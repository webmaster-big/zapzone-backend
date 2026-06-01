<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Membership;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\MembershipVisit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipReportController extends Controller
{
    use ScopesByAuthUser;

    public function summary(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $companyId = $authUser->company_id;
        $locationFilter = $request->integer('location_id') ?: null;
        $from = $request->date('from') ?: Carbon::now()->startOfMonth();
        $to   = $request->date('to')   ?: Carbon::now()->endOfMonth();

        $base = Membership::query()->whereHas('plan', function ($q) use ($companyId) {
            if ($companyId) $q->where('company_id', $companyId);
        });
        if ($locationFilter) $base->where('home_location_id', $locationFilter);

        $active     = (clone $base)->where('status', 'active')->count();
        $pastDue    = (clone $base)->where('status', 'past_due')->count();
        $suspended  = (clone $base)->where('status', 'suspended')->count();
        $frozen     = (clone $base)->where('status', 'frozen')->count();
        $canceled   = (clone $base)->where('status', 'canceled')->whereBetween('canceled_at', [$from, $to])->count();
        $newMembers = (clone $base)->whereBetween('started_at', [$from, $to])->count();

        $mrr = (clone $base)
            ->where('status', 'active')
            ->whereHas('plan', fn($q) => $q->where('billing_cycle', 'monthly'))
            ->sum('billing_amount');

        $arr = (clone $base)
            ->where('status', 'active')
            ->whereHas('plan', fn($q) => $q->where('billing_cycle', 'annual'))
            ->sum('billing_amount');

        $failedPayments = MembershipPayment::where('status', 'failed')
            ->whereBetween('failed_at', [$from, $to])
            ->count();

        $revenue = MembershipPayment::where('status', 'succeeded')
            ->whereBetween('charged_at', [$from, $to])
            ->sum('amount');

        $visitsByLocation = MembershipVisit::selectRaw('location_id, count(*) as visits')
            ->whereBetween('visited_at', [$from, $to])
            ->groupBy('location_id')
            ->with('location:id,name')
            ->get();

        $topPlans = MembershipPlan::withCount(['memberships as active_count' => fn($q) => $q->where('status', 'active')])
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderByDesc('active_count')
            ->limit(5)
            ->get(['id', 'name', 'price', 'billing_cycle']);

        $underused = (clone $base)
            ->where('status', 'active')
            ->whereHas('plan', fn($q) => $q->where('unlimited_visits_per_term', false))
            ->whereRaw('visits_remaining >= visits_remaining')
            ->orderByDesc('visits_remaining')
            ->limit(10)
            ->with('customer:id,first_name,last_name', 'plan:id,name')
            ->get(['id', 'customer_id', 'membership_plan_id', 'visits_remaining']);

        return response()->json(['success' => true, 'data' => [
            'counts' => [
                'active'             => $active,
                'past_due'           => $pastDue,
                'suspended'          => $suspended,
                'frozen'             => $frozen,
                'canceled_in_range'  => $canceled,
                'new_in_range'       => $newMembers,
            ],
            'mrr'                  => round((float) $mrr, 2),
            'arr'                  => round((float) $arr, 2),
            'failed_payments'      => $failedPayments,
            'revenue_in_range'     => round((float) $revenue, 2),
            'visits_by_location'   => $visitsByLocation,
            'top_plans'            => $topPlans,
            'underused_sample'     => $underused,
            'date_range'           => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
        ]]);
    }
}
