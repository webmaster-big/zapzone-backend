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

        $cacheKey = 'dashboards:membership-report:' . ($companyId ?? 'all') . ':' . ($locationFilter ?? 'all')
            . ':' . $from->toDateString() . ':' . $to->toDateString();
        if (($cached = \App\Support\CacheGroups::get([\App\Support\CacheGroups::DASHBOARDS], $cacheKey)) !== null) {
            return response()->json(['success' => true, 'data' => $cached]);
        }

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
            ->get()
            ->map(fn ($row) => [
                'location_id'   => $row->location_id,
                'location_name' => $row->location?->name ?? ($row->location_id ? "Location #{$row->location_id}" : 'Unknown'),
                'visits'        => (int) $row->visits,
            ])
            ->values();

        $topPlans = MembershipPlan::withCount(['memberships as active_count' => fn($q) => $q->where('status', 'active')])
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderByDesc('active_count')
            ->limit(5)
            ->get(['id', 'name', 'price', 'billing_cycle']);

        // Underused: active limited-visit members who have used 0 or very few visits this term.
        // "visits_remaining" is the unused-visit counter. A high remaining count = underused.
        // We join plans to get the total visits_per_term, then surface those using < 30% of their allowance.
        $underused = (clone $base)
            ->where('status', 'active')
            ->whereHas('plan', fn($q) => $q->where('unlimited_visits_per_term', false)->where('visits_per_term', '>', 0))
            ->whereNotNull('visits_remaining')
            ->orderByDesc('visits_remaining')  // most remaining = least used
            ->limit(10)
            ->with('customer:id,first_name,last_name,email', 'plan:id,name,visits_per_term')
            ->get(['id', 'customer_id', 'membership_plan_id', 'visits_remaining', 'current_term_end']);

        $underused = $underused->map(function ($m) {
            $perTerm  = (int) ($m->plan?->visits_per_term ?? 0);
            $remaining = (int) ($m->visits_remaining ?? $perTerm);
            $used     = max(0, $perTerm - $remaining);
            return [
                'id'                   => $m->id,
                'customer_id'          => $m->customer_id,
                'customer_name'        => $m->customer
                    ? trim(($m->customer->first_name ?? '') . ' ' . ($m->customer->last_name ?? ''))
                    : null,
                'customer_email'       => $m->customer?->email,
                'plan_name'            => $m->plan?->name,
                'visits_per_term'      => $perTerm,
                'visits_used_this_term'=> $used,
                'visits_remaining'     => $remaining,
                'term_ends'            => $m->current_term_end,
            ];
        });

        $payload = [
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
        ];

        \App\Support\CacheGroups::put([\App\Support\CacheGroups::DASHBOARDS], $cacheKey, $payload, \App\Support\CacheGroups::TTL_DASHBOARD);

        return response()->json(['success' => true, 'data' => $payload]);
    }
}
