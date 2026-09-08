<?php

namespace App\Http\Traits;

use App\Http\Middleware\EnsureStaff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Booking changes carry a typed reason so the audit trail says WHY, not just what.
 *
 * Whether a reason is mandatory is policy, not code: config('booking_rules.change_reason') is
 * 'guest_visible' (default), 'all' or 'off'. Reasons supplied when not required are still stored.
 */
trait CapturesChangeReason
{
    /** A change a guest would notice - reschedule, room/package swap, price, cancellation. */
    public const CHANGE_GUEST_VISIBLE = 'guest_visible';

    /** Back-office bookkeeping - check-in, internal notes, recording a payment. */
    public const CHANGE_INTERNAL = 'internal';

    public const REASON_PRESETS = [
        'Customer requested a change',
        'Customer requested cancellation',
        'Scheduling conflict',
        'Room or equipment unavailable',
        'Staff booking error corrected',
        'Weather or closure',
        'Customer no-show',
        'Price or discount adjustment',
        'Duplicate booking removed',
    ];

    private const MIN_REASON_LENGTH = 3;

    protected function reasonPolicy(): string
    {
        $policy = (string) config('booking_rules.change_reason', 'guest_visible');

        return in_array($policy, ['off', self::CHANGE_GUEST_VISIBLE, 'all'], true) ? $policy : self::CHANGE_GUEST_VISIBLE;
    }

    protected function reasonIsRequired(string $sensitivity): bool
    {
        $policy = $this->reasonPolicy();

        if ($policy === 'off') {
            return false;
        }

        // The requirement covers EMPLOYEE-made changes only, so the prompt must never reach the
        // customer side. Two kinds of caller are deliberately not employees:
        //   - unauthenticated ones (the checkout-rollback DELETE routes),
        //   - authenticated CUSTOMERS. A customer token is mintable from customer-login and
        //     passes auth:sanctum exactly like a staff token (see EnsureStaff), and several
        //     booking routes - cancel, update - sit on bare auth:sanctum. Gating on
        //     auth()->check() alone would 422 a guest cancelling their own booking.
        if (! $this->actorIsStaff()) {
            return false;
        }

        if ($policy === 'all') {
            return true;
        }

        return $sensitivity === self::CHANGE_GUEST_VISIBLE;
    }

    protected function actorIsStaff(): bool
    {
        $user = auth()->user();

        return $user instanceof User && in_array((string) $user->role, EnsureStaff::ROLES, true);
    }

    /**
     * Pull the reason off the request, enforcing it when policy says it is mandatory.
     *
     * @throws ValidationException
     */
    protected function resolveChangeReason(Request $request, string $sensitivity = self::CHANGE_GUEST_VISIBLE): ?string
    {
        $reason = $request->input('change_reason', $request->input('reason'));
        $reason = is_string($reason) ? trim($reason) : null;

        if (! $this->reasonIsRequired($sensitivity)) {
            return $reason !== '' ? $reason : null;
        }

        if ($reason === null || $reason === '') {
            throw ValidationException::withMessages([
                'change_reason' => ['A reason is required for this change so it can be recorded in the booking history.'],
            ]);
        }

        // Guards against a bare "x" being typed to get past the prompt.
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'change_reason' => ['Please give a slightly more specific reason for this change.'],
            ]);
        }

        return $reason;
    }
}
