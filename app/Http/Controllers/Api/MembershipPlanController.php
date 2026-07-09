<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\MembershipPlan;
use App\Support\CompanyLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MembershipPlanController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = MembershipPlan::with(['approvedLocations:id,name', 'location:id,name', 'billingAccount:id,label,location_id,environment,is_active'])
            ->withCount('memberships');

        $this->applyPlanScope($request, $query);
        $this->applyPlanFilters($request, $query);
        $this->applyPlanSort($request, $query);

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->get('per_page', 25)),
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $query = MembershipPlan::with(['location:id,name', 'billingAccount:id,label,location_id,environment,is_active'])
            ->withCount('memberships');

        $this->applyPlanScope($request, $query);
        $this->applyPlanFilters($request, $query);
        $this->applyPlanSort($request, $query);

        $limit = min(max((int) $request->get('limit', 10000), 1), 50000);
        $records = $query->limit($limit + 1)->get();
        $limited = $records->count() > $limit;

        $rows = $records->take($limit)->map(fn (MembershipPlan $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'tier' => $p->tier,
            'price' => $p->price,
            'billing_cycle' => $p->billing_cycle,
            'usage_type' => $p->usage_type,
            'visits_per_term' => $p->visits_per_term,
            'unlimited_visits_per_term' => $p->unlimited_visits_per_term,
            'location_access_mode' => $p->location_access_mode,
            'location' => $p->location?->name,
            'billing_account' => $p->billingAccount?->label,
            'members_count' => $p->memberships_count,
            'is_active' => $p->is_active,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => ['membership_plans' => $rows, 'count' => $rows->count(), 'limited' => $limited, 'limit' => $limit],
        ]);
    }

    private function applyPlanScope(Request $request, $query): void
    {
        $authUser = $this->resolveAuthUser($request);
        if (! $authUser) {
            return;
        }

        $query->where('company_id', $authUser->company_id);
        // Location managers and attendants see plans valid at their own location,
        // including multi-location and all-location plans.
        if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $locId = (int) $authUser->location_id;
            $query->where(function ($q) use ($locId) {
                $q->where('location_id', $locId)
                  ->orWhere('location_access_mode', 'all')
                  ->orWhereHas('approvedLocations', fn ($x) => $x->where('locations.id', $locId));
            });
        }
    }

    private function applyPlanFilters(Request $request, $query): void
    {
        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }
        if ($request->filled('location_id')) {
            $locId = (int) $request->location_id;
            $query->where(function ($q) use ($locId) {
                $q->where('location_id', $locId)
                  ->orWhere('location_access_mode', 'all')
                  ->orWhereHas('approvedLocations', fn($x) => $x->where('locations.id', $locId));
            });
        }
        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('name', 'like', $like)
                      ->orWhere('description', 'like', $like)
                      ->orWhere('slug', 'like', $like)
                      ->orWhere('tier', 'like', $like);
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }
    }

    private function applyPlanSort(Request $request, $query): void
    {
        $sortable = ['name', 'price', 'tier', 'created_at', 'is_active'];
        $sortBy = (string) $request->get('sort_by', '');
        $sortOrder = strtolower((string) $request->get('sort_order', ''));
        $sortOrder = in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'asc';
        if (in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortOrder)->orderBy('id');
        } else {
            $query->orderBy('price');
        }
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $locationId = $request->filled('location_id') ? (int) $request->location_id : null;
        $cacheKey = 'membership-plans:public:' . ($locationId ?? 'all');

        $data = \App\Support\CacheGroups::remember(
            [\App\Support\CacheGroups::MEMBERSHIP_PLANS],
            $cacheKey,
            \App\Support\CacheGroups::TTL_CATALOG,
            fn () => $this->buildPublicPlans($locationId)
        );

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    private function buildPublicPlans(?int $locationId): array
    {
        $query = MembershipPlan::with([
                'approvedLocations:id,name',
                'location:id,name',
                'billingAccount:id,label,location_id,environment,is_active',
                'planBenefits' => fn($q) => $q->where('is_active', true)->orderByDesc('priority'),
            ])
            ->where('is_active', true);

        if ($locationId !== null) {
            $query->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)
                  ->orWhere('location_access_mode', 'all')
                  ->orWhereHas('approvedLocations', fn($x) => $x->where('locations.id', $locationId));
            });
        }

        $plans = $query->orderBy('price')->get();

        $data = $plans->map(function (MembershipPlan $plan) {
            $arr = $plan->toArray();

            $arr['valid_locations'] = $this->resolveValidLocations($plan);
            $arr['location_access_label'] = match ($plan->location_access_mode) {
                'all'    => 'Valid at all locations',
                'multi'  => 'Valid at selected locations',
                'single' => 'Valid at your selected home location',
                default  => null,
            };

            if (isset($arr['plan_benefits'])) {
                $enriched = MembershipPlanBenefitController::resolveTargets($plan->planBenefits);
                $arr['plan_benefits'] = $enriched->map(fn ($b) => $b->toArray())->values()->toArray();
            }

            return $arr;
        });

        return $data->values()->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $this->validateData($request);
        $data['company_id'] = $authUser->company_id;
        if (! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . Str::random(5);
        }

        $approved = $data['approved_location_ids'] ?? [];
        unset($data['approved_location_ids']);
        $approvedNames = $data['approved_location_names'] ?? [];
        unset($data['approved_location_names']);
        if (empty($approved) && !empty($approvedNames)) {
            $approved = \App\Models\Location::whereIn('name', $approvedNames)->pluck('id')->all();
        }

        if (empty($data['location_id']) && !empty($data['location_name'])) {
            $data['location_id'] = \App\Models\Location::where('name', $data['location_name'])->value('id');
        }
        unset($data['location_name']);

        // Ensure location managers' plans are always tied to their own location
        // so the plan appears in their own index view.
        if (empty($data['location_id']) && $authUser->role === 'location_manager' && $authUser->location_id) {
            $data['location_id'] = $authUser->location_id;
        }

        $plan = MembershipPlan::create($data);
        if (! empty($approved)) {
            $plan->approvedLocations()->sync($approved);
        }

        return response()->json(['success' => true, 'data' => $plan->load(['approvedLocations', 'location:id,name'])], 201);
    }

    public function show(MembershipPlan $membershipPlan): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $membershipPlan->load([
                'approvedLocations',
                'location:id,name',
                'planBenefits' => fn($q) => $q->where('is_active', true)->orderByDesc('priority'),
            ]),
        ]);
    }

    public function update(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $this->validateData($request, $membershipPlan->id);
        $approved = $data['approved_location_ids'] ?? null;
        unset($data['approved_location_ids']);
        $approvedNames = $data['approved_location_names'] ?? null;
        unset($data['approved_location_names']);
        if ($approved === null && $approvedNames !== null) {
            $approved = \App\Models\Location::whereIn('name', $approvedNames)->pluck('id')->all();
        }

        if (empty($data['location_id']) && !empty($data['location_name'])) {
            $data['location_id'] = \App\Models\Location::where('name', $data['location_name'])->value('id');
        }
        unset($data['location_name']);

        $membershipPlan->update($data);
        if ($approved !== null) {
            $membershipPlan->approvedLocations()->sync($approved);
        }

        return response()->json(['success' => true, 'data' => $membershipPlan->fresh()->load(['approvedLocations', 'location:id,name'])]);
    }

    public function destroy(Request $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && $authUser->role === 'company_admin', 403);
        $membershipPlan->delete();
        return response()->json(['success' => true]);
    }

    public function toggleStatus(MembershipPlan $membershipPlan): JsonResponse
    {
        $membershipPlan->is_active = ! $membershipPlan->is_active;
        $membershipPlan->save();
        return response()->json(['success' => true, 'data' => $membershipPlan]);
    }

    private function validateData(Request $request, ?int $id = null): array
    {
        $merge = [];
        if ($request->has('billing_interval') && !$request->has('billing_cycle')) {
            $merge['billing_cycle'] = $request->billing_interval;
        }
        if ($request->has('usage_type') && $request->usage_type === 'limited_visits') {
            $merge['usage_type'] = 'limited_visits';
        }
        if ($request->has('unlimited_uses') && !$request->has('unlimited_uses_per_term')) {
            $merge['unlimited_uses_per_term'] = $request->unlimited_uses;
        }
        if ($request->has('unlimited_visits') && !$request->has('unlimited_visits_per_term')) {
            $merge['unlimited_visits_per_term'] = $request->unlimited_visits;
        }
        if ($request->has('included_visits_per_term') && !$request->has('visits_per_term')) {
            $merge['visits_per_term'] = $request->included_visits_per_term;
        }
        if (!empty($merge)) {
            $request->merge($merge);
        }

        return $request->validate([
            'name'                          => 'required|string|max:150',
            'slug'                          => 'nullable|string|max:160',
            'description'                   => 'nullable|string',
            'benefits'                      => 'nullable|array',
            'tier'                          => ['nullable', Rule::in(['basic','premium','unlimited','family','discounted','comped','custom'])],
            'inherits_plan_id'              => 'nullable|exists:membership_plans,id',
            'price'                         => 'required|numeric|min:0',
            'billing_cycle'                 => ['required', Rule::in(['monthly','quarterly','annual','one_time','custom'])],
            'custom_billing_days'           => 'nullable|integer|min:1',
            'term_length_months'            => 'nullable|integer|min:1',
            'season_start_date'             => 'nullable|date',
            'season_end_date'               => 'nullable|date|after_or_equal:season_start_date',
            'trial_days'                    => 'nullable|integer|min:0',
            'usage_type'                    => ['required', Rule::in(['limited','unlimited','limited_visits','punch_card'])],
            'uses_per_term'                 => 'nullable|integer|min:0',
            'visits_per_term'               => 'nullable|integer|min:0',
            'services_per_term'             => 'nullable|integer|min:0',
            'punch_card_total'              => 'nullable|integer|min:1',
            'unlimited_uses_per_term'       => 'boolean',
            'unlimited_visits_per_term'     => 'boolean',
            'max_visits_per_day'            => 'nullable|integer|min:1',
            'member_only_booking'           => 'boolean',
            'advance_booking_days'          => 'nullable|integer|min:0',
            'late_cancel_counts_as_visit'   => 'boolean',
            'no_show_counts_as_visit'       => 'boolean',
            'location_id'                   => 'nullable|exists:locations,id',
            'location_name'                 => 'nullable|string|max:150',
            'billing_account_id'             => 'nullable|exists:authorize_net_accounts,id',
            'location_access_mode'          => ['required', Rule::in(['single','multi','all'])],
            'approved_location_ids'         => 'nullable|array',
            'approved_location_ids.*'       => 'exists:locations,id',
            'approved_location_names'       => 'nullable|array',
            'approved_location_names.*'     => 'string|max:150',
            'grace_period_days'             => 'nullable|integer|min:0',
            'failed_payment_retry_days'     => 'nullable|integer|min:0',
            'failed_payment_max_retries'    => 'nullable|integer|min:0',
            'cancellation_mode'             => ['required', Rule::in(['immediate','end_of_term','staff_only'])],
            'renewable'                     => 'boolean',
            'discount_percent'              => 'nullable|numeric|min:0|max:100',
            'requires_photo'                => 'boolean',
            'is_family_or_group'            => 'boolean',
            'max_family_size'               => 'nullable|integer|min:2',
            'is_active'                     => 'boolean',
        ]);
    }

    private function resolveValidLocations(MembershipPlan $plan): array
    {
        return match ($plan->location_access_mode) {
            'all'   => \App\Models\Location::where('company_id', $plan->company_id)
                            ->orderBy('name')
                            ->pluck('name')
                            ->all(),
            'multi' => $plan->approvedLocations->pluck('name')->filter()->sort()->values()->all(),
            default => array_filter([$plan->location?->name ?? null]),
        };
    }
}
