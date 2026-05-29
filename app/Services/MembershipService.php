<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\MembershipAuditLog;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\MembershipVisit;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Mail\MembershipActivated;
use App\Mail\MembershipPaymentReceipt;
use App\Mail\MembershipPaymentFailed;
use App\Mail\MembershipCanceled;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * MembershipService
 *
 * Centralizes membership lifecycle logic so controllers stay thin.
 * Handles: activation, term math, usage counters, status transitions,
 * billing record creation, audit logging, customer notifications.
 */
class MembershipService
{
    /**
     * Activate a brand-new membership after a successful purchase / staff create.
     */
    public function activate(Membership $membership, ?array $context = []): Membership
    {
        return DB::transaction(function () use ($membership, $context) {
            $plan = $membership->plan()->firstOrFail();

            $now = now();
            $membership->status              = 'active';
            $membership->started_at          = $membership->started_at ?? $now;
            $membership->current_term_start  = $now;
            $membership->current_term_end    = $this->calcTermEnd($now, $plan);
            $membership->next_billing_at     = $plan->price > 0 && ! $membership->is_comped
                ? $membership->current_term_end
                : null;
            $membership->grace_period_ends_at = null;

            // Initialise usage counters from plan
            $membership->uses_remaining     = $plan->unlimited_uses_per_term ? null : $plan->uses_per_term;
            $membership->visits_remaining   = $plan->unlimited_visits_per_term ? null : $plan->visits_per_term;
            $membership->services_remaining = $plan->services_per_term;

            $membership->save();

            $this->log($membership, 'activated', null, $membership->fresh()->toArray(), $context['note'] ?? null);
            $this->notify($membership->customer_id, 'Membership activated',
                "Your {$plan->name} membership is now active.");
            $this->sendMail($membership, fn ($m) => new MembershipActivated($m), 'MembershipActivated');

            return $membership->fresh();
        });
    }

    /**
     * Calculate next term end based on billing cycle.
     */
    public function calcTermEnd(Carbon $from, MembershipPlan $plan): Carbon
    {
        return match ($plan->billing_cycle) {
            'monthly' => $from->copy()->addMonth(),
            'annual'  => $from->copy()->addYear(),
            'custom'  => $from->copy()->addDays(max(1, (int) $plan->custom_billing_days)),
            default   => $from->copy()->addMonth(),
        };
    }

    /**
     * Reset usage counters at start of a new term (called by cron command).
     */
    public function resetTermUsage(Membership $membership): void
    {
        $plan = $membership->plan;
        $now  = now();
        $membership->current_term_start = $now;
        $membership->current_term_end   = $this->calcTermEnd($now, $plan);
        $membership->uses_remaining     = $plan->unlimited_uses_per_term ? null : $plan->uses_per_term;
        $membership->visits_remaining   = $plan->unlimited_visits_per_term ? null : $plan->visits_per_term;
        $membership->services_remaining = $plan->services_per_term;
        $membership->save();

        $this->log($membership, 'term_reset');
    }

    /**
     * Determine if the membership can be used to check-in / book right now.
     * Returns ['eligible' => bool, 'reason' => ?string].
     */
    public function eligibility(Membership $membership, ?int $locationId = null): array
    {
        $membership->loadMissing('plan.approvedLocations');

        if (! $membership->isUsable()) {
            return ['eligible' => false, 'reason' => "Status: {$membership->status}"];
        }

        if ($locationId) {
            $allowed = $this->locationAllowed($membership, $locationId);
            if (! $allowed) {
                return ['eligible' => false, 'reason' => 'Location not authorized for this membership'];
            }
        }

        if (! $membership->plan->unlimited_visits_per_term && ($membership->visits_remaining ?? 0) <= 0) {
            return ['eligible' => false, 'reason' => 'No visits remaining for this term'];
        }

        if ($membership->plan->max_visits_per_day) {
            $today = $membership->visits()
                ->whereDate('visited_at', today())
                ->where('result', 'allowed')
                ->count();
            if ($today >= $membership->plan->max_visits_per_day) {
                return ['eligible' => false, 'reason' => 'Daily visit limit reached'];
            }
        }

        if (! $membership->hasPhoto()) {
            return ['eligible' => true, 'reason' => null, 'photo_required' => true];
        }

        return ['eligible' => true, 'reason' => null, 'photo_required' => false];
    }

    public function locationAllowed(Membership $membership, int $locationId): bool
    {
        $plan = $membership->plan;
        if ($plan->location_access_mode === 'all') return true;
        if ((int) $membership->home_location_id === $locationId) return true;
        if ($plan->location_access_mode === 'multi') {
            return $plan->approvedLocations->contains('id', $locationId);
        }
        return false;
    }

    /**
     * Record a check-in (visit) and decrement usage if counted.
     */
    public function recordVisit(Membership $membership, array $data): MembershipVisit
    {
        return DB::transaction(function () use ($membership, $data) {
            $allowed = $data['result'] === 'allowed' || $data['result'] === 'override';
            $counted = $allowed
                && ! $membership->plan->unlimited_visits_per_term
                && ! empty($data['counted_against_usage']);

            if ($counted && $membership->visits_remaining !== null && $membership->visits_remaining > 0) {
                $membership->decrement('visits_remaining');
            }

            $visit = MembershipVisit::create([
                'membership_id'           => $membership->id,
                'customer_id'             => $membership->customer_id,
                'location_id'             => $data['location_id'] ?? null,
                'staff_user_id'           => Auth::id(),
                'visited_at'              => now(),
                'result'                  => $data['result'],
                'denial_reason'           => $data['denial_reason'] ?? null,
                'counted_against_usage'   => $counted,
                'visits_remaining_after'  => $membership->fresh()->visits_remaining,
                'notes'                   => $data['notes'] ?? null,
            ]);

            $this->log($membership, 'check_in', null, [
                'result' => $data['result'],
                'location_id' => $data['location_id'] ?? null,
            ]);

            return $visit;
        });
    }

    /**
     * Change status and audit it.
     */
    public function changeStatus(Membership $membership, string $newStatus, ?string $note = null): Membership
    {
        $before = ['status' => $membership->status];
        $membership->status = $newStatus;

        if ($newStatus === 'canceled' && ! $membership->canceled_at) {
            $membership->canceled_at = now();
        }
        $membership->save();

        $this->log($membership, 'status_change', $before, ['status' => $newStatus], $note);

        $msgMap = [
            'past_due'  => 'Your membership payment failed. Please update your payment method.',
            'suspended' => 'Your membership has been suspended.',
            'frozen'    => 'Your membership has been paused.',
            'canceled'  => 'Your membership has been canceled.',
            'active'    => 'Your membership is active.',
        ];
        if (isset($msgMap[$newStatus])) {
            $this->notify($membership->customer_id, "Membership status: {$newStatus}", $msgMap[$newStatus]);
        }

        if ($newStatus === 'canceled') {
            $mode = $note && stripos($note, 'immediate') !== false ? 'immediate' : 'end_of_term';
            $this->sendMail($membership, fn ($m) => new MembershipCanceled($m, $mode), 'MembershipCanceled');
        }

        return $membership;
    }

    /**
     * Record a payment attempt result.
     */
    public function recordPayment(Membership $membership, array $data): MembershipPayment
    {
        $payment = MembershipPayment::create([
            'membership_id'   => $membership->id,
            'customer_id'     => $membership->customer_id,
            'payment_id'      => $data['payment_id'] ?? null,
            'amount'          => $data['amount'] ?? $membership->billing_amount,
            'status'          => $data['status'],
            'transaction_id'  => $data['transaction_id'] ?? null,
            'description'     => $data['description'] ?? null,
            'retry_attempt'   => $data['retry_attempt'] ?? 0,
            'charged_at'      => $data['status'] === 'succeeded' ? now() : null,
            'failed_at'       => $data['status'] === 'failed' ? now() : null,
            'failure_reason'  => $data['failure_reason'] ?? null,
        ]);

        if ($data['status'] === 'succeeded') {
            // advance term + clear past-due
            $plan = $membership->plan;
            $membership->current_term_start = now();
            $membership->current_term_end   = $this->calcTermEnd(now(), $plan);
            $membership->next_billing_at    = $membership->current_term_end;
            $membership->grace_period_ends_at = null;
            if ($membership->status === 'past_due' || $membership->status === 'suspended') {
                $membership->status = 'active';
            }
            $membership->save();
            $this->notify($membership->customer_id, 'Payment receipt',
                "Receipt: \${$payment->amount} charged for your {$plan->name} membership.");
            $this->sendMail($membership, fn ($m) => new MembershipPaymentReceipt($m, $payment), 'MembershipPaymentReceipt');
        } elseif ($data['status'] === 'failed') {
            // mark past-due with grace period
            $membership->status = 'past_due';
            $membership->grace_period_ends_at = now()->addDays((int) $membership->plan->grace_period_days);
            $membership->save();
            $this->notify($membership->customer_id, 'Payment failed',
                "Your membership payment failed. Please update your payment method by " .
                $membership->grace_period_ends_at->toFormattedDateString() . '.');
            $this->sendMail($membership, fn ($m) => new MembershipPaymentFailed($m, $data['failure_reason'] ?? null), 'MembershipPaymentFailed');
        }

        return $payment;
    }

    /**
     * Audit log helper.
     */
    public function log(Membership $membership, string $action, ?array $before = null, ?array $after = null, ?string $note = null): void
    {
        MembershipAuditLog::create([
            'membership_id' => $membership->id,
            'user_id'       => Auth::id(),
            'customer_id'   => $membership->customer_id,
            'action'        => $action,
            'actor_type'    => Auth::guard('sanctum')->user() instanceof Customer ? 'customer' : (Auth::id() ? 'staff' : 'system'),
            'before'        => $before,
            'after'         => $after,
            'note'          => $note,
        ]);
    }

    /**
     * Send a customer notification (re-uses existing customer_notifications table).
     */
    public function notify(int $customerId, string $title, string $message): void
    {
        try {
            CustomerNotification::create([
                'customer_id' => $customerId,
                'title'       => $title,
                'message'     => $message,
                'type'        => 'general',
                'priority'    => 'medium',
                'status'      => 'unread',
            ]);
        } catch (\Throwable $e) {
            // Don't fail the parent transaction if the notifications table schema differs.
            Log::warning('Membership notify failed: ' . $e->getMessage());
        }
    }

    /**
     * Safely dispatch a membership-related email. Failures are logged but never bubble up
     * so they cannot break the lifecycle action that triggered them.
     */
    protected function sendMail(Membership $membership, \Closure $factory, string $label): void
    {
        try {
            $membership->loadMissing('customer');
            $email = $membership->customer?->email;
            if (! $email) {
                Log::info("[{$label}] Skipped — customer has no email", ['membership_id' => $membership->id]);
                return;
            }
            Mail::to($email)->send($factory($membership));
        } catch (\Throwable $e) {
            Log::warning("[{$label}] Send failed: " . $e->getMessage(), [
                'membership_id' => $membership->id,
            ]);
        }
    }
}
