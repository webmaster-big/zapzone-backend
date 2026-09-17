<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * A manager's PIN, used to approve a booking that overlaps something already in the space.
 *
 * The approval is proved to the booking write with a short-lived encrypted token rather than a flag
 * in the payload, so an attendant cannot approve their own overlap by editing the request.
 */
class OverridePinController extends Controller
{
    /** Who is allowed to hold an override PIN. */
    private const APPROVER_ROLES = ['company_admin', 'admin', 'location_manager'];

    private const TOKEN_TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    /** Set or rotate the signed-in manager's own PIN. Their password is required to do it. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, self::APPROVER_ROLES, true)) {
            return response()->json(['success' => false, 'message' => 'Only managers can hold an override PIN.'], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'current_password' => ['required', 'string'],
        ], [
            'pin.regex' => 'The PIN must be 4 to 6 digits.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'That password is not right.'], 422);
        }

        $user->forceFill([
            'override_pin' => Hash::make($validated['pin']),
            'override_pin_set_at' => now(),
        ])->save();

        ActivityLog::log(
            'override_pin_set',
            'security',
            ActivityLog::describeActor($user) . ' set their overlap override PIN',
            $user->getKey(),
            $user->location_id,
            'user',
            $user->getKey()
        );

        return response()->json(['success' => true, 'message' => 'Override PIN saved.']);
    }

    /** Whether the signed-in user holds a PIN, so the UI can prompt them to set one. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'can_hold_pin' => $user && in_array($user->role, self::APPROVER_ROLES, true),
                'has_pin' => (bool) ($user?->override_pin),
                'set_at' => $user?->override_pin_set_at,
            ],
        ]);
    }

    /**
     * Check a PIN against every manager who covers this location, and mint a token the booking
     * write will accept once.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $throttleKey = 'override-pin:' . $request->ip() . ':' . $validated['location_id'];

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Try again in ' . ceil(RateLimiter::availableIn($throttleKey) / 60) . ' minutes.',
            ], 429);
        }

        $approver = $this->findApprover($validated['pin'], (int) $validated['location_id']);

        if (! $approver) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            ActivityLog::log(
                'override_pin_rejected',
                'security',
                'An overlap override PIN was entered and did not match any manager for this location',
                $request->user()?->getKey(),
                (int) $validated['location_id'],
                'location',
                (int) $validated['location_id'],
                ['attempts_left' => max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($throttleKey))]
            );

            return response()->json(['success' => false, 'message' => 'That PIN was not recognised.'], 422);
        }

        RateLimiter::clear($throttleKey);

        $token = Crypt::encryptString(json_encode([
            'purpose' => 'overlap_override',
            'approver_id' => $approver->getKey(),
            'location_id' => (int) $validated['location_id'],
            'requested_by' => $request->user()?->getKey(),
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->timestamp,
        ]));

        ActivityLog::log(
            'override_pin_accepted',
            'security',
            ActivityLog::describeActor($approver) . ' approved an overlapping booking',
            $request->user()?->getKey(),
            (int) $validated['location_id'],
            'user',
            $approver->getKey(),
            ['reason' => $validated['reason'] ?? null]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'approved_by' => ActivityLog::describeActor($approver),
                'expires_in' => self::TOKEN_TTL_SECONDS,
            ],
        ]);
    }

    /**
     * Read a token back. Returns the approver id, or null when it is missing, tampered with,
     * expired, or minted for a different location.
     */
    public static function approverFromToken(?string $token, int $locationId): ?int
    {
        if (! $token) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'overlap_override') {
            return null;
        }

        if ((int) ($payload['location_id'] ?? 0) !== $locationId) {
            return null;
        }

        if ((int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return (int) ($payload['approver_id'] ?? 0) ?: null;
    }

    private function findApprover(string $pin, int $locationId): ?User
    {
        $candidates = User::query()
            ->whereIn('role', self::APPROVER_ROLES)
            ->whereNotNull('override_pin')
            // a company admin covers every venue; a location manager only their own
            ->where(function ($query) use ($locationId) {
                $query->whereIn('role', ['company_admin', 'admin'])
                    ->orWhere('location_id', $locationId);
            })
            ->get(['id', 'first_name', 'last_name', 'name', 'email', 'role', 'location_id', 'override_pin']);

        foreach ($candidates as $candidate) {
            if (Hash::check($pin, $candidate->override_pin)) {
                return $candidate;
            }
        }

        return null;
    }
}
