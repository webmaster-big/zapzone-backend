<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Membership;
use App\Services\MembershipService;
use App\Services\MembershipBenefitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $membership = Membership::with([
            'customer:id,first_name,last_name,email,phone',
            'plan.approvedLocations:id,name',
            'homeLocation:id,name',
            'notes' => fn($q) => $q->where('pinned', true)->latest()->limit(5),
        ])->where('qr_token', $data['qr_token'])->first();

        if (! $membership) {
            return response()->json(['success' => false, 'message' => 'Membership not found'], 404);
        }

        $locationId = $data['location_id'] ?? $authUser->location_id;
        $eligibility = $this->service->eligibility($membership, $locationId);

        $benefitQuote = $this->benefits->quote($membership, $locationId, []);

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

        $data = $request->validate([
            'result'                => ['required', Rule::in(['allowed','denied','override'])],
            'location_id'           => 'nullable|exists:locations,id',
            'denial_reason'         => 'nullable|string|max:255',
            'counted_against_usage' => 'boolean',
            'notes'                 => 'nullable|string',
            'override_note'         => 'required_if:result,override|string',
        ]);

        if ($data['result'] === 'override') {
            $this->service->log($membership, 'manual_override', null, [
                'location_id' => $data['location_id'] ?? null,
            ], $data['override_note']);
        }

        $visit = $this->service->recordVisit($membership, [
            'result'                => $data['result'],
            'location_id'           => $data['location_id'] ?? $authUser->location_id,
            'denial_reason'         => $data['denial_reason'] ?? null,
            'counted_against_usage' => $data['counted_against_usage'] ?? true,
            'notes'                 => $data['notes'] ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $visit->load('staff:id,name', 'location:id,name')]);
    }
}
