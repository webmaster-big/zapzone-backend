<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Command;

/**
 * Run nightly to:
 *  - Reset usage counters for memberships whose current_term_end has passed
 *  - Flip past-due grace-expired memberships to suspended
 *
 *  schedule: php artisan memberships:reset-usage
 */
class ResetMembershipUsage extends Command
{
    protected $signature = 'memberships:reset-usage';
    protected $description = 'Reset usage counters on term roll-over and enforce grace-period expiry';

    public function handle(MembershipService $service): int
    {
        $now = now();

        // Term roll-over: active memberships whose term has ended (renewable)
        $rolled = 0;
        Membership::with('plan')
            ->where('status', 'active')
            ->whereNotNull('current_term_end')
            ->where('current_term_end', '<=', $now)
            ->chunkById(100, function ($chunk) use ($service, &$rolled) {
                foreach ($chunk as $m) {
                    if (! $m->plan?->renewable) continue;
                    $service->resetTermUsage($m);
                    $rolled++;
                }
            });

        // Grace expired: past_due -> suspended
        $suspended = 0;
        Membership::where('status', 'past_due')
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<', $now)
            ->chunkById(100, function ($chunk) use ($service, &$suspended) {
                foreach ($chunk as $m) {
                    $service->changeStatus($m, 'suspended', 'Auto: grace period expired');
                    $suspended++;
                }
            });

        // End-of-term cancellations
        $canceled = 0;
        Membership::whereNotNull('cancellation_effective_at')
            ->where('cancellation_effective_at', '<=', $now)
            ->whereNotIn('status', ['canceled', 'expired'])
            ->chunkById(100, function ($chunk) use ($service, &$canceled) {
                foreach ($chunk as $m) {
                    $service->changeStatus($m, 'canceled', 'Auto: scheduled cancellation effective');
                    $canceled++;
                }
            });

        $this->info("Term roll-over: {$rolled}, Suspended: {$suspended}, Canceled: {$canceled}");
        return self::SUCCESS;
    }
}
