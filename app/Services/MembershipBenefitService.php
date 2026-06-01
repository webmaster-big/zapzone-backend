<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipBenefitRedemption;
use App\Models\MembershipPlanBenefit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MembershipBenefitService
{
    public function activeMembershipFor(Customer $customer): ?Membership
    {
        $membership = Membership::with(['plan.planBenefits', 'plan.inheritsPlan.planBenefits'])
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'past_due'])
            ->latest()
            ->get()
            ->first(fn (Membership $m) => $m->isUsable());

        Log::debug('[MembershipBenefit] activeMembershipFor', [
            'customer_id'   => $customer->id,
            'membership_id' => $membership?->id,
            'found'         => $membership !== null,
        ]);

        return $membership;
    }

    public function quote(Membership $membership, ?int $locationId, array $items): array
    {
        Log::debug('[MembershipBenefit] quote start', [
            'membership_id' => $membership->id,
            'location_id'   => $locationId,
            'item_count'    => count($items),
        ]);

        $membership->loadMissing('plan.planBenefits', 'plan.inheritsPlan.planBenefits', 'plan.approvedLocations');

        $result = [
            'eligible'          => false,
            'reason'            => null,
            'membership_id'     => $membership->id,
            'plan_name'         => $membership->plan?->name,
            'currency_discount' => 0.0,
            'lines'             => [],
            'passes'            => [],
            'applied'           => [],
        ];

        if (! $membership->isUsable()) {
            $result['reason'] = "Membership is {$membership->status}";
            return $result;
        }

        if ($locationId && ! app(MembershipService::class)->locationAllowed($membership, $locationId)) {
            $result['reason'] = 'Membership not valid at this location';
            return $result;
        }

        $benefits = $this->benefitsFor($membership);
        if ($benefits->isEmpty()) {
            $result['eligible'] = true;
            return $result;
        }

        $totalDiscount = 0.0;

        foreach ($items as $idx => $item) {
            $type     = $item['type'] ?? null;
            $id       = isset($item['id']) ? (int) $item['id'] : null;
            $category = $item['category'] ?? null;
            $unit     = (float) ($item['unit_price'] ?? 0);
            $qty      = max(1, (int) ($item['quantity'] ?? 1));
            if (! $type || $unit <= 0) {
                continue;
            }

            $lineTotal = $unit * $qty;

            $candidates = $benefits
                ->filter(fn (MembershipPlanBenefit $b) => $b->isDiscount() && $b->appliesToLine($type, $id, $category))
                ->filter(fn (MembershipPlanBenefit $b) => $this->conditionsMet($b, $lineTotal, $item))
                ->filter(fn (MembershipPlanBenefit $b) => $this->hasRemaining($membership, $b))
                ->sortByDesc('priority')
                ->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $lineDiscount = 0.0;
            $appliedHere  = [];
            $usedNonStackable = false;

            foreach ($candidates as $b) {
                if ($usedNonStackable) {
                    break;
                }
                $remainingLine = $lineTotal - $lineDiscount;
                if ($remainingLine <= 0) {
                    break;
                }
                $off = $this->discountAmount($b, $remainingLine);
                if ($off <= 0) {
                    continue;
                }
                $lineDiscount += $off;
                $appliedHere[] = [
                    'benefit_id'   => $b->id,
                    'benefit_type' => $b->benefit_type,
                    'label'        => $b->label,
                    'value_mode'   => $b->value_mode,
                    'amount'       => round($off, 2),
                ];
                if (! $b->is_stackable) {
                    $usedNonStackable = true;
                }
            }

            $lineDiscount = round(min($lineDiscount, $lineTotal), 2);
            if ($lineDiscount <= 0) {
                continue;
            }

            $totalDiscount += $lineDiscount;
            $result['lines'][] = [
                'index'         => $idx,
                'type'          => $type,
                'id'            => $id,
                'line_total'    => round($lineTotal, 2),
                'discount'      => $lineDiscount,
                'benefits'      => $appliedHere,
            ];

            foreach ($appliedHere as $a) {
                $result['applied'][] = [
                    'membership_plan_benefit_id' => $a['benefit_id'],
                    'benefit_type'               => $a['benefit_type'],
                    'value_mode'                 => $a['value_mode'],
                    'value_applied'              => $a['amount'],
                ];
            }
        }

        foreach ($benefits->filter(fn (MembershipPlanBenefit $b) => $b->isPass()) as $b) {
            $remaining = $this->remainingRedemptions($membership, $b);
            $result['passes'][] = [
                'benefit_id'   => $b->id,
                'benefit_type' => $b->benefit_type,
                'label'        => $b->label,
                'remaining'    => $remaining,
            ];
        }

        $result['currency_discount'] = round($totalDiscount, 2);
        $result['eligible'] = true;

        Log::debug('[MembershipBenefit] quote result', [
            'membership_id'     => $membership->id,
            'eligible'          => $result['eligible'],
            'currency_discount' => $result['currency_discount'],
            'lines_matched'     => count($result['lines']),
            'passes'            => count($result['passes']),
        ]);

        return $result;
    }

    public function quoteForCustomer(Customer $customer, ?int $locationId, array $items): array
    {
        $membership = $this->activeMembershipFor($customer);
        if (! $membership) {
            return [
                'eligible'          => false,
                'reason'            => 'No active membership',
                'membership_id'     => null,
                'plan_name'         => null,
                'currency_discount' => 0.0,
                'lines'             => [],
                'passes'            => [],
                'applied'           => [],
            ];
        }
        return $this->quote($membership, $locationId, $items);
    }

    public function recordPurchaseRedemptions(
        Membership $membership,
        Model $redeemable,
        array $applied,
        ?int $locationId = null,
        ?int $staffUserId = null
    ): void {
        if (empty($applied)) {
            return;
        }

        DB::transaction(function () use ($membership, $redeemable, $applied, $locationId, $staffUserId) {
            foreach ($applied as $row) {
                MembershipBenefitRedemption::create([
                    'membership_id'              => $membership->id,
                    'customer_id'                => $membership->customer_id,
                    'membership_plan_benefit_id' => $row['membership_plan_benefit_id'] ?? null,
                    'location_id'                => $locationId,
                    'benefit_type'               => $row['benefit_type'] ?? 'package_discount',
                    'value_mode'                 => $row['value_mode'] ?? 'fixed',
                    'value_applied'              => $row['value_applied'] ?? 0,
                    'redeemable_type'            => $redeemable->getMorphClass(),
                    'redeemable_id'              => $redeemable->getKey(),
                    'staff_user_id'              => $staffUserId ?? Auth::id(),
                ]);
            }
        });
    }

    public function redeemPass(
        Membership $membership,
        MembershipPlanBenefit $benefit,
        ?int $locationId = null,
        ?Model $redeemable = null
    ): ?MembershipBenefitRedemption {
        Log::debug('[MembershipBenefit] redeemPass attempt', [
            'membership_id' => $membership->id,
            'benefit_id'    => $benefit->id,
            'benefit_type'  => $benefit->benefit_type,
            'is_pass'       => $benefit->isPass(),
            'has_remaining' => $this->hasRemaining($membership, $benefit),
        ]);

        if (! $benefit->isPass() || ! $this->hasRemaining($membership, $benefit)) {
            Log::debug('[MembershipBenefit] redeemPass blocked', ['membership_id' => $membership->id, 'benefit_id' => $benefit->id]);
            return null;
        }

        return MembershipBenefitRedemption::create([
            'membership_id'              => $membership->id,
            'customer_id'                => $membership->customer_id,
            'membership_plan_benefit_id' => $benefit->id,
            'location_id'                => $locationId,
            'benefit_type'               => $benefit->benefit_type,
            'value_mode'                 => 'count',
            'value_applied'              => 1,
            'redeemable_type'            => $redeemable?->getMorphClass(),
            'redeemable_id'              => $redeemable?->getKey(),
            'staff_user_id'              => Auth::id(),
        ]);
    }

    public function reverseForRedeemable(Model $redeemable, string $reason = 'reversed'): int
    {
        return MembershipBenefitRedemption::query()
            ->where('redeemable_type', $redeemable->getMorphClass())
            ->where('redeemable_id', $redeemable->getKey())
            ->whereNull('reversed_at')
            ->update([
                'reversed_at'     => now(),
                'reversal_reason' => $reason,
            ]);
    }

    protected function benefitsFor(Membership $membership): Collection
    {
        $plan = $membership->plan;
        if (! $plan) {
            return new Collection();
        }
        $resolved = $plan->resolvedBenefits();
        return $resolved instanceof Collection ? $resolved : Collection::make($resolved->all());
    }

    protected function discountAmount(MembershipPlanBenefit $b, float $base): float
    {
        return match ($b->value_mode) {
            'percent' => round($base * ((float) $b->value / 100), 2),
            'fixed'   => round(min((float) $b->value, $base), 2),
            'free'    => round($base, 2),
            default   => 0.0,
        };
    }

    protected function conditionsMet(MembershipPlanBenefit $b, float $lineTotal, array $item): bool
    {
        $c = $b->conditions ?? [];
        if (! is_array($c) || empty($c)) {
            return true;
        }

        if (isset($c['min_spend']) && $lineTotal < (float) $c['min_spend']) {
            return false;
        }

        if (! empty($c['day_of_week']) && is_array($c['day_of_week'])) {
            $today = strtolower(Carbon::now()->format('l'));
            $days  = array_map('strtolower', $c['day_of_week']);
            if (! in_array($today, $days, true)) {
                return false;
            }
        }

        if (! empty($c['blackout_dates']) && is_array($c['blackout_dates'])) {
            $today = Carbon::now()->toDateString();
            if (in_array($today, $c['blackout_dates'], true)) {
                return false;
            }
        }

        return true;
    }

    protected function hasRemaining(Membership $membership, MembershipPlanBenefit $b): bool
    {
        $remaining = $this->remainingRedemptions($membership, $b);
        return $remaining === null || $remaining > 0;
    }

    protected function redemptionCap(MembershipPlanBenefit $b): ?int
    {
        if ($b->value_mode === 'count') {
            return max(0, (int) $b->value);
        }
        return empty($b->max_redemptions) ? null : (int) $b->max_redemptions;
    }

    protected function remainingRedemptions(Membership $membership, MembershipPlanBenefit $b): ?int
    {
        $cap = $this->redemptionCap($b);
        if ($cap === null) {
            Log::debug('[MembershipBenefit] remainingRedemptions unlimited', ['membership_id' => $membership->id, 'benefit_id' => $b->id]);
            return null;
        }

        $q = MembershipBenefitRedemption::query()
            ->where('membership_id', $membership->id)
            ->where('membership_plan_benefit_id', $b->id)
            ->whereNull('reversed_at');

        switch ($b->period) {
            case 'per_day':
                $q->whereDate('created_at', Carbon::today());
                break;
            case 'per_term':
                if ($membership->current_term_start) {
                    $q->where('created_at', '>=', $membership->current_term_start);
                }
                break;
            case 'lifetime':
            default:
                break;
        }

        $used  = $q->count();
        $remaining = max(0, $cap - $used);

        Log::debug('[MembershipBenefit] remainingRedemptions', [
            'membership_id' => $membership->id,
            'benefit_id'    => $b->id,
            'period'        => $b->period,
            'cap'           => $cap,
            'used'          => $used,
            'remaining'     => $remaining,
        ]);

        return $remaining;
    }
}
