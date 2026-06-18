<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Membership;
use App\Services\MembershipService;
use App\Services\MembershipBenefitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MembershipCheckInController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(
        protected MembershipService $service,
        protected MembershipBenefitService $benefits,
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            'qr_token'    => 'required|string',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        Log::debug('[CheckIn] scan', ['qr_token' => $data['qr_token'], 'location_id' => $data['location_id'] ?? null]);

        $membership = Membership::with([
            'customer:id,first_name,last_name,email,phone',
            'plan.approvedLocations:id,name',
            'homeLocation:id,name',
            'notes' => fn($q) => $q->where('pinned', true)->latest()->limit(5),
        ])->where('qr_token', $data['qr_token'])->first();

        if (! $membership) {
            Log::debug('[CheckIn] scan — membership not found', ['qr_token' => $data['qr_token']]);
            return response()->json(['success' => false, 'message' => 'Membership not found'], 404);
        }

        $locationId  = $data['location_id'] ?? $authUser->location_id;
        $eligibility = $this->service->eligibility($membership, $locationId);

        Log::debug('[CheckIn] scan eligibility', [
            'membership_id' => $membership->id,
            'eligible'      => $eligibility['eligible'],
            'reason'        => $eligibility['reason'] ?? null,
        ]);

        $benefitQuote = $this->benefits->quote($membership, $locationId, []);

        $plan = $membership->plan;
        $visitsRemaining = $membership->visits_remaining
            ?? ($plan->unlimited_visits_per_term ? null : (isset($plan->visits_per_term) ? (int) $plan->visits_per_term : null));
        $membership->visits_remaining = $visitsRemaining;

        return response()->json([
            'success' => true,
            'data' => [
                'membership'    => $membership,
                'eligibility'   => $eligibility,
                'photo_required'=> ! $membership->hasPhoto(),
                'visits_today'  => $membership->visits()->whereDate('visited_at', today())->count(),
                'passes'        => $benefitQuote['passes'] ?? [],
            ],
        ]);
    }

    public function checkIn(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        Log::debug('[CheckIn] checkIn request', [
            'membership_id' => $membership->id,
            'body'          => $request->only('result','location_id','denial_reason','counted_against_usage','override_note'),
        ]);

        $data = $request->validate([
            'result'                => ['required', Rule::in(['allowed','denied','override'])],
            'location_id'           => 'nullable|exists:locations,id',
            'denial_reason'         => 'nullable|string|max:255',
            'counted_against_usage' => 'boolean',
            'notes'                 => 'nullable|string',
            'override_note'         => 'required_if:result,override|string',
        ]);

        // Task 5: a membership that requires a member photo cannot be used until the
        // photo is on file. Staff can still record a 'denied' result or deliberately
        // 'override' (which is audit-logged), but a plain 'allowed' entry is blocked.
        if ($data['result'] === 'allowed' && $membership->photoRequiredAndMissing()) {
            return response()->json([
                'success'        => false,
                'photo_required' => true,
                'message'        => 'A member photo is required before this membership can be used. Capture the photo first.',
            ], 422);
        }

        if ($data['result'] === 'override') {
            $this->service->log($membership, 'manual_override', null, [
                'location_id' => $data['location_id'] ?? null,
            ], $data['override_note']);
        }

        Log::debug('[CheckIn] checkIn', [
            'membership_id' => $membership->id,
            'result'        => $data['result'],
            'location_id'   => $data['location_id'] ?? $authUser->location_id,
        ]);

        $visit = $this->service->recordVisit($membership, [
            'result'                => $data['result'],
            'location_id'           => $data['location_id'] ?? $authUser->location_id,
            'denial_reason'         => $data['denial_reason'] ?? null,
            'counted_against_usage' => $data['counted_against_usage'] ?? true,
            'notes'                 => $data['notes'] ?? null,
        ]);

        Log::debug('[CheckIn] checkIn recorded', ['visit_id' => $visit->id, 'result' => $visit->result]);

        return response()->json(['success' => true, 'data' => $visit->load('staff:id,first_name,last_name', 'location:id,name')]);
    }

    public function redeemPassCheckIn(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            'benefit_id'  => 'required|integer',
            'location_id' => 'nullable|exists:locations,id',
            'note'        => 'nullable|string|max:255',
        ]);

        $locationId = $data['location_id'] ?? $authUser->location_id;

        $membership->loadMissing('plan.planBenefits', 'plan.inheritsPlan.planBenefits');

        // Use resolvedBenefits() so inherited plan benefits are also searchable
        $allBenefits = collect($membership->plan?->resolvedBenefits() ?? []);
        $benefit = $allBenefits->firstWhere('id', (int) $data['benefit_id']);

        if (! $benefit || ! $benefit->isPass()) {
            return response()->json(['success' => false, 'message' => 'Benefit not found or is not a redeemable pass'], 422);
        }

        $redemption = $this->benefits->redeemPass($membership, $benefit, $locationId);

        if (! $redemption) {
            return response()->json(['success' => false, 'message' => 'No passes remaining for this period'], 422);
        }

        $noteText = $data['note'] ?? ('Pass redeemed: ' . ($benefit->label ?: $benefit->benefit_type));

        $visit = $this->service->recordVisit($membership, [
            'result'                => 'allowed',
            'location_id'           => $locationId,
            'counted_against_usage' => false,
            'notes'                 => $noteText,
            'skip_audit_log'        => true,
        ]);

        $this->service->log($membership, 'pass_redeemed', null, [
            'benefit_id'   => $benefit->id,
            'benefit_type' => $benefit->benefit_type,
            'label'        => $benefit->label ?: $benefit->benefit_type,
            'location_id'  => $locationId,
        ], $noteText);

        Log::debug('[CheckIn] redeemPassCheckIn', [
            'membership_id' => $membership->id,
            'benefit_id'    => $benefit->id,
            'visit_id'      => $visit->id,
        ]);

        // Refresh pass counts so caller can update UI without a re-scan
        $updatedPasses = $this->benefits->quote($membership, $locationId, [])['passes'] ?? [];

        return response()->json([
            'success' => true,
            'data'    => [
                'redemption' => $redemption,
                'visit'      => $visit->load('staff:id,first_name,last_name', 'location:id,name'),
                'passes'     => $updatedPasses,
            ],
        ]);
    }
}
