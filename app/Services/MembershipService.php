<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\MembershipAuditLog;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\MembershipVisit;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\User;
use App\Mail\MembershipActivated;
use App\Mail\MembershipPaymentReceipt;
use App\Mail\MembershipPaymentFailed;
use App\Mail\MembershipCanceled;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MembershipService
{
    public function activate(Membership $membership, ?array $context = []): Membership
    {
        Log::debug('[Membership] activate', ['membership_id' => $membership->id, 'customer_id' => $membership->customer_id]);

        $activated = DB::transaction(function () use ($membership, $context) {
            $plan = $membership->plan()->firstOrFail();

            $now = now();
            $membership->status              = 'active';
            $membership->started_at          = $membership->started_at ?? $now;
            $membership->current_term_start  = $now;
            $membership->current_term_end    = $this->calcTermEnd($now, $plan);
            // Fixed-season plans don't auto-renew, so there is no next billing date.
            $membership->next_billing_at     = $plan->price > 0 && ! $membership->is_comped
                && $plan->billing_cycle !== 'one_time' && ! $plan->season_end_date
                ? $membership->current_term_end
                : null;
            $membership->grace_period_ends_at = null;

            $membership->uses_remaining     = $plan->unlimited_uses_per_term ? null : ($plan->uses_per_term ?? null);
            $membership->visits_remaining   = $plan->unlimited_visits_per_term ? null : ($plan->visits_per_term !== null ? (int) $plan->visits_per_term : null);
            $membership->services_remaining = $plan->services_per_term;

            $membership->save();

            $this->log($membership, 'activated', null, $membership->fresh()->toArray(), $context['note'] ?? null);

            return $membership->fresh();
        });

        // Side effects run AFTER the transaction commits so a slow or failing email
        // can never roll back activation, hold DB locks open, or surface a 500 to the
        // caller. (Fixes duplicate-member creation on retry + missing activation email.)
        $plan = $activated->plan;
        $this->notify($activated->customer_id, 'Membership activated',
            "Your {$plan->name} membership is now active.");
        $this->sendMail($activated, fn ($m) => new MembershipActivated($m), 'MembershipActivated');

        return $activated;
    }

    public function calcTermEnd(Carbon $from, MembershipPlan $plan): Carbon
    {
        // Fixed-season plans (e.g. a season pass) expire for everyone on the plan's
        // season end date, regardless of when the member joined.
        if ($plan->season_end_date) {
            return Carbon::parse($plan->season_end_date)->endOfDay();
        }

        return match ($plan->billing_cycle) {
            'monthly'   => $from->copy()->addMonth(),
            'quarterly' => $from->copy()->addMonths(3),
            'annual'    => $from->copy()->addYear(),
            'one_time'  => $from->copy()->addYears(100), // non-renewing; far-future sentinel
            'custom'    => $from->copy()->addDays(max(1, (int) $plan->custom_billing_days)),
            default     => $from->copy()->addMonth(),
        };
    }

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
     * Manually extend a membership's term end date. Staff may extend beyond the
     * plan's fixed season end date or past an expired / past-due term, which
     * reactivates a lapsed membership.
     */
    public function extend(Membership $membership, Carbon $newTermEnd, ?int $staffUserId = null, ?string $note = null): Membership
    {
        Log::debug('[Membership] extend', [
            'membership_id' => $membership->id,
            'new_term_end'  => $newTermEnd->toIso8601String(),
        ]);

        $before = [
            'status'           => $membership->status,
            'current_term_end' => optional($membership->current_term_end)->toIso8601String(),
        ];

        $membership->current_term_end              = $newTermEnd;
        $membership->manually_extended_at          = now();
        $membership->manually_extended_by_user_id  = $staffUserId;

        // Reactivate a lapsed membership when extended to a future date.
        if (in_array($membership->status, ['expired', 'past_due', 'suspended'], true) && $newTermEnd->isFuture()) {
            $membership->status               = 'active';
            $membership->grace_period_ends_at = null;
        }

        $membership->save();

        $this->log($membership, 'manual_extension', $before, [
            'current_term_end' => $newTermEnd->toIso8601String(),
            'status'           => $membership->status,
        ], $note);

        $this->notify($membership->customer_id, 'Membership extended',
            'Your membership has been extended to ' . $newTermEnd->toFormattedDateString() . '.');

        return $membership->fresh();
    }

    public function eligibility(Membership $membership, ?int $locationId = null): array
    {
        Log::debug('[Membership] eligibility check', ['membership_id' => $membership->id, 'location_id' => $locationId]);

        $membership->loadMissing('plan.approvedLocations');

        if (! $membership->isUsable()) {
            Log::debug('[Membership] eligibility denied — not usable', ['membership_id' => $membership->id, 'status' => $membership->status]);
            return ['eligible' => false, 'reason' => "Status: {$membership->status}"];
        }

        if ($locationId) {
            $allowed = $this->locationAllowed($membership, $locationId);
            if (! $allowed) {
                return ['eligible' => false, 'reason' => 'Location not authorized for this membership'];
            }
        }

        if (! $membership->plan->unlimited_visits_per_term) {
            $effectiveRemaining = $membership->visits_remaining
                ?? (int) ($membership->plan->visits_per_term ?? 0);
            if ($effectiveRemaining <= 0) {
                return ['eligible' => false, 'reason' => 'No visits remaining for this term'];
            }
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

        // Hard gate: a plan that requires a member photo cannot be used until the
        // photo has been captured. Staff must take the photo (or explicitly override).
        if ($membership->photoRequiredAndMissing()) {
            Log::debug('[Membership] eligibility — blocked, required photo missing', ['membership_id' => $membership->id]);
            return ['eligible' => false, 'reason' => 'Member photo required before first use', 'photo_required' => true];
        }

        Log::debug('[Membership] eligibility passed', ['membership_id' => $membership->id]);
        return ['eligible' => true, 'reason' => null, 'photo_required' => ! $membership->hasPhoto()];
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

    public function recordVisit(Membership $membership, array $data): MembershipVisit
    {
        Log::debug('[Membership] recordVisit', [
            'membership_id' => $membership->id,
            'result'        => $data['result'] ?? null,
            'location_id'   => $data['location_id'] ?? null,
        ]);

        return DB::transaction(function () use ($membership, $data) {
            $allowed = $data['result'] === 'allowed' || $data['result'] === 'override';
            $counted = $allowed
                && ! $membership->plan->unlimited_visits_per_term
                && ! empty($data['counted_against_usage']);

            // Initialize visits_remaining for legacy memberships where activate() stored null
            if ($counted && $membership->visits_remaining === null && ! $membership->plan->unlimited_visits_per_term) {
                $perTerm = (int) ($membership->plan->visits_per_term ?? 0);
                $usedThisTerm = $membership->visits()
                    ->where('counted_against_usage', true)
                    ->where('visited_at', '>=', ($membership->current_term_start ?? now())->toDateTimeString())
                    ->count();
                $membership->visits_remaining = max(0, $perTerm - $usedThisTerm - 1);
                $membership->save();
                Log::debug('[Membership] recordVisit — initialized visits_remaining from plan', [
                    'membership_id'    => $membership->id,
                    'visits_per_term'  => $perTerm,
                    'used_this_term'   => $usedThisTerm,
                    'visits_remaining' => $membership->visits_remaining,
                ]);
            } elseif ($counted && $membership->visits_remaining !== null && $membership->visits_remaining > 0) {
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

            if (empty($data['skip_audit_log'])) {
                $this->log($membership, 'check_in', null, [
                    'result' => $data['result'],
                    'location_id' => $data['location_id'] ?? null,
                ]);
            }

            return $visit;
        });
    }

    public function changeStatus(Membership $membership, string $newStatus, ?string $note = null): Membership
    {
        Log::debug('[Membership] changeStatus', [
            'membership_id' => $membership->id,
            'from'          => $membership->status,
            'to'            => $newStatus,
        ]);

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

    public function recordPayment(Membership $membership, array $data): MembershipPayment
    {
        Log::debug('[Membership] recordPayment', [
            'membership_id' => $membership->id,
            'status'        => $data['status'] ?? null,
            'amount'        => $data['amount'] ?? null,
        ]);

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
            $plan = $membership->plan;
            $membership->current_term_start = now();
            $membership->current_term_end   = $this->calcTermEnd(now(), $plan);
            $membership->next_billing_at    = $plan->billing_cycle !== 'one_time' ? $membership->current_term_end : null;
            $membership->grace_period_ends_at = null;
            if ($membership->status === 'past_due' || $membership->status === 'suspended') {
                $membership->status = 'active';
            }
            $membership->save();
            $this->notify($membership->customer_id, 'Payment receipt',
                "Receipt: \${$payment->amount} charged for your {$plan->name} membership.");
            $this->sendMail($membership, fn ($m) => new MembershipPaymentReceipt($m, $payment), 'MembershipPaymentReceipt');
        } elseif ($data['status'] === 'failed') {
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

    public function log(Membership $membership, string $action, ?array $before = null, ?array $after = null, ?string $note = null): void
    {
        $actor      = Auth::guard('sanctum')->user();
        $isCustomer = $actor instanceof Customer;

        MembershipAuditLog::create([
            'membership_id' => $membership->id,
            'user_id'       => $isCustomer ? null : ($actor instanceof User ? $actor->id : null),
            'customer_id'   => $membership->customer_id,
            'action'        => $action,
            'actor_type'    => $isCustomer ? 'customer' : ($actor ? 'staff' : 'system'),
            'before'        => $before,
            'after'         => $after,
            'note'          => $note,
        ]);
    }

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
            Log::warning('Membership notify failed: ' . $e->getMessage());
        }
    }

    protected function sendMail(Membership $membership, \Closure $factory, string $label): void
    {
        try {
            $membership->loadMissing('customer');
            $email = $membership->customer?->email;
            if (! $email) {
                Log::info("[{$label}] Skipped — customer has no email", ['membership_id' => $membership->id]);
                return;
            }

            Log::info("[{$label}] Preparing email", ['membership_id' => $membership->id, 'to' => $email]);

            $mailable = $factory($membership);
            $html     = $mailable->render();
            $subject  = $mailable->subject ?? $label;
            $fromName = $membership->homeLocation?->company?->company_name
                      ?? config('gmail.sender_name', 'Zap Zone');

            $attachments = [];
            if ($mailable instanceof \App\Mail\MembershipActivated && $mailable->qrCodeBase64) {
                $attachments[] = [
                    'data'      => $mailable->qrCodeBase64,
                    'filename'  => 'membership-qr.png',
                    'mime_type' => 'image/png',
                ];
            }

            // Try Gmail API unconditionally (mirrors EmailNotificationService pattern),
            // fall back to SMTP only if Gmail is unavailable or fails.
            // Resolve from the container (not `new`) so it can be mocked in tests —
            // this is what the activation-email regression test asserts against.
            try {
                app(GmailApiService::class)->sendEmail($email, $subject, $html, $fromName, $attachments);
                Log::info("[{$label}] Sent via Gmail API", ['membership_id' => $membership->id, 'to' => $email]);
            } catch (\Throwable $gmailEx) {
                Log::warning("[{$label}] Gmail failed, falling back to SMTP: " . $gmailEx->getMessage(), [
                    'membership_id' => $membership->id,
                ]);
                Mail::html($html, function ($message) use ($email, $subject, $fromName) {
                    $message->to($email)
                        ->subject($subject)
                        ->from(config('mail.from.address'), $fromName);
                });
                Log::info("[{$label}] Sent via SMTP fallback", ['membership_id' => $membership->id, 'to' => $email]);
            }
        } catch (\Throwable $e) {
            Log::error("[{$label}] Send failed: " . $e->getMessage(), [
                'membership_id' => $membership->id,
                'trace'         => $e->getTraceAsString(),
            ]);
        }
    }
}
