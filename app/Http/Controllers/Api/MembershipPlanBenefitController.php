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

        $benefit->update($this->validateData($request, true));

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

    private function validateData(Request $request, bool $partial = false): array
    {
        $req = fn (string $rule) => $partial ? 'sometimes|' . $rule : $rule;

        return $request->validate([
            'benefit_type'    => [$partial ? 'sometimes' : 'required', Rule::in([
                'package_discount', 'attraction_discount', 'event_discount', 'addon_discount',
                'free_entry_pass', 'guest_pass', 'priority_booking', 'member_only_access', 'birthday_reward',
            ])],
            'label'           => 'nullable|string|max:255',
            'scope_type'      => [$partial ? 'sometimes' : 'required', Rule::in(['any', 'package', 'attraction', 'event', 'category', 'location'])],
            'scope_id'        => 'nullable|integer',
            'scope_category'  => 'nullable|string|max:150',
            'value_mode'      => [$partial ? 'sometimes' : 'required', Rule::in(['percent', 'fixed', 'free', 'count', 'flag'])],
            'value'           => $req('numeric|min:0'),
            'period'          => ['nullable', Rule::in(['per_term', 'per_day', 'per_visit', 'lifetime', 'once'])],
            'max_redemptions' => 'nullable|integer|min:1',
            'priority'        => 'nullable|integer|min:0',
            'is_stackable'    => 'boolean',
            'conditions'      => 'nullable|array',
            'is_active'       => 'boolean',
        ]);
    }
}
