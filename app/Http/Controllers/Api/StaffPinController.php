<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureStaffTerminal;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\StaffTerminal;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class StaffPinController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(private readonly StaffPinService $pins)
    {
    }

    public function unlock(Request $request): JsonResponse
    {
        $terminal = EnsureStaffTerminal::fromRequest($request);

        if (! $terminal) {
            return response()->json([
                'success' => false,
                'message' => 'This device is not set up as a shared terminal.',
            ], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:' . $this->pins->pinRule()],
        ], [
            'pin.regex' => 'Enter your ' . $this->pins->pinLength() . '-digit PIN.',
        ]);

        [$result, $user] = $this->pins->resolve(
            (int) $terminal->company_id,
            $validated['pin'],
            (int) $terminal->location_id
        );

        if ($result !== StaffPinService::RESULT_OK || ! $user) {
            ActivityLog::log(
                'staff_pin_rejected',
                'security',
                'A PIN was entered at "' . $terminal->label . '" and was not accepted',
                null,
                $terminal->location_id,
                'staff_terminal',
                $terminal->getKey(),
                ['result' => $result]
            );

            return response()->json([
                'success' => false,
                'message' => match ($result) {
                    StaffPinService::RESULT_LOCKED => 'That PIN is locked for now. Ask a manager to unlock it.',
                    StaffPinService::RESULT_BREAKER => 'PIN sign-in is paused. Please sign in with your email and password.',
                    StaffPinService::RESULT_UNAVAILABLE => 'PIN sign-in is not set up on this server yet. Please sign in with your email and password.',
                    default => 'That PIN was not recognised.',
                },
            ], in_array($result, [StaffPinService::RESULT_BREAKER, StaffPinService::RESULT_UNAVAILABLE], true) ? 503 : 422);
        }

        $role = $this->ceilingRole((string) $user->role, (string) $terminal->ceiling_role);
        $elevated = in_array($role, (array) config('staff_pins.elevated_roles', []), true);

        $expiresAt = $elevated
            ? now()->addMinutes(max(1, (int) config('staff_pins.elevated_session_minutes', 60)))
            : now()->addHours(max(1, (int) config('staff_pins.session_hours', 12)));

        $token = $user->createToken(
            'pin:' . $terminal->device_id,
            ['pin', 'role:' . $role, 'terminal:' . $terminal->getKey()],
            $expiresAt
        );

        $user->forceFill(['last_login' => now()])->save();
        $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();

        ActivityLog::log(
            'staff_pin_signed_in',
            'login',
            ActivityLog::describeActor($user) . ' signed in with their PIN at "' . $terminal->label . '"',
            $user->getKey(),
            $terminal->location_id,
            'user',
            $user->getKey(),
            [
                'terminal_id' => $terminal->getKey(),
                'terminal_label' => $terminal->label,
                'account_role' => $user->role,
                'effective_role' => $role,
                'ceiling_role' => $terminal->ceiling_role,
                'expires_at' => $expiresAt->toIso8601String(),
            ]
        );

        $user->load('location');

        return response()->json([
            'success' => true,
            'user' => $user,
            'role' => $role,
            'token' => $token->plainTextToken,
            'data' => [
                'effective_role' => $role,
                'account_role' => $user->role,
                'elevated' => $elevated,
                'expires_at' => $expiresAt->toIso8601String(),
                'idle_seconds' => $elevated
                    ? $terminal->effectiveElevatedIdleSeconds()
                    : $terminal->effectiveIdleSeconds(),
                'idle_disabled' => $elevated ? false : (bool) $terminal->idle_disabled,
                'terminal_label' => $terminal->label,
                'location_id' => $terminal->location_id,
            ],
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user instanceof User) {
            ActivityLog::log(
                'staff_pin_locked_screen',
                'logout',
                ActivityLog::describeActor($user) . ' returned the terminal to the PIN screen',
                $user->getKey(),
                $user->location_id,
                'user',
                $user->getKey(),
                ['reason' => $request->input('reason', 'idle')]
            );
        }

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['success' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'has_pin' => (bool) $user?->pin_hash,
                'set_at' => $user?->pin_set_at,
                'locked' => $user instanceof User ? $user->pinIsLocked() : false,
                'pin_length' => $this->pins->pinLength(),
                'can_manage' => in_array(
                    (string) EnsureStaff::effectiveRole($user),
                    (array) config('staff_pins.manager_roles', []),
                    true
                ),
            ],
        ]);
    }

    public function setOwn(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'This area is for staff accounts only.'], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:' . $this->pins->pinRule()],
            'current_password' => ['required', 'string'],
        ], [
            'pin.regex' => 'The PIN must be ' . $this->pins->pinLength() . ' digits.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'That password is not right.'], 422);
        }

        try {
            $this->pins->setPin($user, $validated['pin'], $user);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'PIN saved.']);
    }

    public function roster(Request $request): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        $query = User::query()
            ->whereIn('role', $this->pins->allowedRoles())
            ->select([
                'id', 'first_name', 'last_name', 'email', 'role', 'status',
                'location_id', 'pin_set_at', 'pin_locked_until', 'pin_hash',
            ]);

        $this->applyAuthScope($query, $request);

        $staff = $query->orderBy('first_name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => ActivityLog::describeActor($u),
            'email' => $u->email,
            'role' => $u->role,
            'status' => $u->status,
            'location_id' => $u->location_id,
            'has_pin' => $u->pin_hash !== null,
            'pin_set_at' => $u->pin_set_at,
            'locked' => $u->pinIsLocked(),
            'locked_until' => $u->pin_locked_until,
        ]);

        return response()->json(['success' => true, 'data' => $staff]);
    }

    public function issue(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($denied = $this->guardTarget($request, $user)) {
            return $denied;
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:' . $this->pins->pinRule()],
        ], [
            'pin.regex' => 'The PIN must be ' . $this->pins->pinLength() . ' digits.',
        ]);

        try {
            $this->pins->setPin($user, $validated['pin'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'PIN issued.']);
    }

    public function clear(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($denied = $this->guardTarget($request, $user)) {
            return $denied;
        }

        $this->pins->clearPin($user, $request->user());

        return response()->json(['success' => true, 'message' => 'PIN removed.']);
    }

    public function unlockAccount(Request $request, User $user): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($denied = $this->guardTarget($request, $user)) {
            return $denied;
        }

        $this->pins->unlock($user, $request->user());

        return response()->json(['success' => true, 'message' => 'PIN unlocked.']);
    }

    private function ceilingRole(string $accountRole, string $ceiling): string
    {
        $rank = EnsureStaff::ROLE_RANK;
        $accountRank = $rank[$accountRole] ?? 0;
        $ceilingRank = $rank[$ceiling] ?? 0;

        return $ceilingRank > 0 && $ceilingRank < $accountRank ? $ceiling : $accountRole;
    }

    private function guardManager(Request $request)
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'This area is for staff accounts only.'], 403);
        }

        $role = (string) EnsureStaff::effectiveRole($user);

        if (! in_array($role, (array) config('staff_pins.manager_roles', []), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a location manager or administrator can manage employee PINs.',
            ], 403);
        }

        return null;
    }

    private function guardTarget(Request $request, User $target)
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['success' => false, 'message' => 'This area is for staff accounts only.'], 403);
        }

        if ((int) $target->company_id !== (int) $actor->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: that employee belongs to another company',
            ], 403);
        }

        $actorRole = (string) EnsureStaff::effectiveRole($actor);
        $rank = EnsureStaff::ROLE_RANK;

        if ($actorRole === 'location_manager') {
            if ((int) $target->location_id !== (int) $actor->location_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: that employee works at another location',
                ], 403);
            }

            if (($rank[(string) $target->role] ?? 0) > ($rank[$actorRole] ?? 0)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A location manager cannot change an administrator\'s PIN.',
                ], 403);
            }
        }

        if (! in_array((string) $target->role, $this->pins->allowedRoles(), true)) {
            return response()->json([
                'success' => false,
                'message' => 'That account cannot hold a PIN.',
            ], 422);
        }

        return null;
    }
}
