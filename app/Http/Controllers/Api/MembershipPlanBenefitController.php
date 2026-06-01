<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanBenefit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MembershipPlanBenefitController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $membershipPlan->planBenefits()->orderByDesc('priority')->get(),
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
}
