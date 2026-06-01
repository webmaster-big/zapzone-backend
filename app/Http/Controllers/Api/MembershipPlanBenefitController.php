<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class MembershipPlanBenefitController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $benefits = $membershipPlan->planBenefits()->orderByDesc('priority')->get();
        return response()->json([
            'success' => true,
            'data'    => static::resolveTargets($benefits),
        ]);
    }

    public function store(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $this->validateData($request);
        $data['membership_plan_id'] = $membershipPlan->id;

        $benefit = MembershipPlanBenefit::create($data);

        return response()->json(['success' => true, 'data' => $benefit], 201);
    }

    public function update(Request $request, MembershipPlan $membershipPlan, MembershipPlanBenefit $benefit): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);
        abort_unless((int) $benefit->membership_plan_id === (int) $membershipPlan->id, 404);

        $benefit->update($this->validateData($request, true, $benefit));

        return response()->json(['success' => true, 'data' => $benefit->fresh()]);
    }

    public function destroy(Request $request, MembershipPlan $membershipPlan, MembershipPlanBenefit $benefit): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);
        abort_unless((int) $benefit->membership_plan_id === (int) $membershipPlan->id, 404);

        $benefit->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Which scope types are valid for each benefit type.
     * Prevents nonsensical combinations like a package_discount scoped to an attraction.
     */
    private const SCOPE_MATRIX = [
        'package_discount'    => ['any', 'package', 'category'],
        'attraction_discount' => ['any', 'attraction', 'category'],
        'event_discount'      => ['any', 'event', 'category'],
        'addon_discount'      => ['any', 'addon'],
        'free_entry_pass'     => ['any', 'attraction'],
        'guest_pass'          => ['any', 'attraction'],
        'priority_booking'    => ['any'],
        'member_only_access'  => ['any', 'location'],
        'birthday_reward'     => ['any'],
    ];

    private const VALUE_MODE_MATRIX = [
        'package_discount'    => ['percent', 'fixed', 'free'],
        'attraction_discount' => ['percent', 'fixed', 'free'],
        'event_discount'      => ['percent', 'fixed', 'free'],
        'addon_discount'      => ['percent', 'fixed', 'free'],
        'free_entry_pass'     => ['count'],
        'guest_pass'          => ['count'],
        'priority_booking'    => ['flag'],
        'member_only_access'  => ['flag'],
        'birthday_reward'     => ['free', 'percent', 'fixed', 'count'],
    ];

    private function validateData(Request $request, bool $partial = false, ?MembershipPlanBenefit $existing = null): array
    {
        $req = fn (string $rule) => $partial ? 'sometimes|' . $rule : $rule;

        $data = $request->validate([
            'benefit_type'    => [$partial ? 'sometimes' : 'required', Rule::in([
                'package_discount', 'attraction_discount', 'event_discount', 'addon_discount',
                'free_entry_pass', 'guest_pass', 'priority_booking', 'member_only_access', 'birthday_reward',
            ])],
            'label'           => 'nullable|string|max:255',
            'scope_type'      => [$partial ? 'sometimes' : 'required', Rule::in(['any', 'package', 'attraction', 'event', 'addon', 'category', 'location'])],
            'scope_id'        => 'nullable|integer',
            'scope_ids'       => 'nullable|array',
            'scope_ids.*'     => 'integer',
            'scope_category'  => 'nullable|string|max:150',
            'value_mode'      => [$partial ? 'sometimes' : 'required', Rule::in(['percent', 'fixed', 'free', 'count', 'flag'])],
            'value'           => $req('numeric|min:0'),
            'period'          => ['nullable', Rule::in(['per_term', 'per_day', 'lifetime'])],
            'max_redemptions' => 'nullable|integer|min:1',
            'priority'        => 'nullable|integer|min:0',
            'is_stackable'    => 'boolean',
            'conditions'      => 'nullable|array',
            'is_active'       => 'boolean',
            'requires_manual_redemption' => 'boolean',
        ]);

        // Enforce benefit_type <-> scope_type compatibility.
        $benefitType = $data['benefit_type'] ?? $existing?->benefit_type;
        $scopeType   = array_key_exists('scope_type', $data) ? $data['scope_type'] : $existing?->scope_type;
        if ($benefitType && $scopeType) {
            $allowed = self::SCOPE_MATRIX[$benefitType] ?? ['any'];
            if (! in_array($scopeType, $allowed, true)) {
                abort(response()->json([
                    'success' => false,
                    'message' => "Scope \"{$scopeType}\" is not valid for benefit \"{$benefitType}\". Allowed: " . implode(', ', $allowed) . '.',
                ], 422));
            }
        }

        $valueMode = array_key_exists('value_mode', $data) ? $data['value_mode'] : $existing?->value_mode;
        if ($benefitType && $valueMode) {
            $allowedModes = self::VALUE_MODE_MATRIX[$benefitType] ?? ['percent'];
            if (! in_array($valueMode, $allowedModes, true)) {
                abort(response()->json([
                    'success' => false,
                    'message' => "Value mode \"{$valueMode}\" is not valid for benefit \"{$benefitType}\". Allowed: " . implode(', ', $allowedModes) . '.',
                ], 422));
            }
        }

        if (in_array($valueMode, ['count', 'flag'], true) && array_key_exists('value_mode', $data)) {
            $data['max_redemptions'] = null;
        }

        // Normalise targets: dedupe scope_ids, clear single scope_id when a list is provided.
        if (array_key_exists('scope_ids', $data)) {
            $ids = array_values(array_unique(array_map('intval', $data['scope_ids'] ?? [])));
            $data['scope_ids'] = count($ids) > 0 ? $ids : null;
            if (! empty($data['scope_ids'])) {
                $data['scope_id'] = null;
            }
        }

        return $data;
    }

    /**
     * Enrich a collection of MembershipPlanBenefit models with resolved scope_targets,
     * including each target item's name, location_id, and location_name.
     */
    public static function resolveTargets(Collection $benefits): Collection
    {
        $allIds = ['package' => [], 'attraction' => [], 'event' => [], 'addon' => []];

        foreach ($benefits as $b) {
            if (! isset($allIds[$b->scope_type])) continue;
            $bIds = is_array($b->scope_ids) && count($b->scope_ids) > 0
                ? $b->scope_ids
                : ($b->scope_id ? [$b->scope_id] : []);
            $allIds[$b->scope_type] = array_unique(array_merge($allIds[$b->scope_type], $bIds));
        }

        $fetchers = [
            'package'    => fn ($ids) => Package::with('location:id,name')->whereIn('id', $ids)->get(['id', 'name', 'location_id'])->keyBy('id'),
            'attraction' => fn ($ids) => Attraction::with('location:id,name')->whereIn('id', $ids)->get(['id', 'name', 'location_id'])->keyBy('id'),
            'event'      => fn ($ids) => Event::whereIn('id', $ids)->get(['id', 'name'])->keyBy('id'),
            'addon'      => fn ($ids) => AddOn::with('location:id,name')->whereIn('id', $ids)->get(['id', 'name', 'location_id'])->keyBy('id'),
        ];

        $lookup = [];
        foreach ($allIds as $type => $ids) {
            if (! empty($ids)) {
                $lookup[$type] = $fetchers[$type]($ids);
            }
        }

        return $benefits->map(function (MembershipPlanBenefit $b) use ($lookup) {
            $bIds = is_array($b->scope_ids) && count($b->scope_ids) > 0
                ? $b->scope_ids
                : ($b->scope_id ? [$b->scope_id] : []);

            if (isset($lookup[$b->scope_type]) && ! empty($bIds)) {
                $b->scope_targets = collect($bIds)->map(function (int $id) use ($b, $lookup) {
                    $item = $lookup[$b->scope_type][$id] ?? null;
                    if (! $item) return null;
                    return [
                        'id'            => $item->id,
                        'name'          => $item->name,
                        'location_id'   => $item->location_id ?? null,
                        'location_name' => $item->location?->name ?? null,
                    ];
                })->filter()->values()->toArray();
            } else {
                $b->scope_targets = [];
            }

            return $b;
        });
    }
}
