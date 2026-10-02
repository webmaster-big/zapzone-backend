<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureStaffTerminal;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\StaffTerminal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffTerminalController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        $query = StaffTerminal::query()->with(['location:id,name', 'enrolledBy:id,first_name,last_name']);
        $this->applyAuthScope($query, $request);

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('last_seen_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($this->isPinSession($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Setting up a shared terminal needs a full sign-in with your email and password, not a PIN.',
            ], 403);
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:120'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'idle_seconds' => ['nullable', 'integer'],
            'idle_disabled' => ['nullable', 'boolean'],
        ]);

        $location = $this->scopedLocation($request, (int) $validated['location_id']);

        if (! $location instanceof \App\Models\Location) {
            return $location;
        }

        $secret = Str::random(64);

        $terminal = StaffTerminal::firstOrNew(['device_id' => $validated['device_id']]);

        $terminal->forceFill([
            'company_id' => $user->company_id,
            'location_id' => $location->id,
            'label' => $validated['label'],
            'token_hash' => hash('sha256', $secret),
            'enrolled_by_user_id' => $user->getKey(),
            'ceiling_role' => (string) EnsureStaff::effectiveRole($user),
            'idle_seconds' => $this->clampIdle($validated['idle_seconds'] ?? null),
            'idle_disabled' => (bool) ($validated['idle_disabled'] ?? false),
            'revoked_at' => null,
        ])->save();

        ActivityLog::log(
            'staff_terminal_enrolled',
            'security',
            ActivityLog::describeActor($user) . ' set up "' . $terminal->label . '" as a shared terminal',
            $user->getKey(),
            $terminal->location_id,
            'staff_terminal',
            $terminal->getKey(),
            ['ceiling_role' => $terminal->ceiling_role, 'device_id' => $terminal->device_id]
        );

        return response()->json([
            'success' => true,
            'message' => 'This device is now a shared terminal.',
            'data' => [
                'terminal' => $terminal->fresh(['location:id,name']),
                'token' => $secret,
            ],
        ], 201);
    }

    public function context(Request $request): JsonResponse
    {
        $terminal = EnsureStaffTerminal::fromRequest($request);

        if (! $terminal) {
            return response()->json([
                'success' => true,
                'data' => ['registered' => false],
            ]);
        }

        $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();

        $elevated = $this->callerIsElevated($request);

        return response()->json([
            'success' => true,
            'data' => [
                'registered' => true,
                'label' => $terminal->label,
                'location_id' => $terminal->location_id,
                'location_name' => $terminal->location?->name,
                'ceiling_role' => $terminal->ceiling_role,
                'elevated' => $elevated,
                'idle_seconds' => $elevated
                    ? $terminal->effectiveElevatedIdleSeconds()
                    : $terminal->effectiveIdleSeconds(),
                'idle_disabled' => $elevated ? false : (bool) $terminal->idle_disabled,
                'elevated_idle_seconds' => $terminal->effectiveElevatedIdleSeconds(),
                'pin_length' => app(\App\Services\StaffPinService::class)->pinLength(),
            ],
        ]);
    }

    public function update(Request $request, StaffTerminal $staffTerminal): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($denied = $this->denyForeignRecord($staffTerminal, 'terminal')) {
            return $denied;
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'idle_seconds' => ['sometimes', 'nullable', 'integer'],
            'idle_disabled' => ['sometimes', 'boolean'],
            'elevated_idle_seconds' => ['sometimes', 'nullable', 'integer'],
        ]);

        $changes = [];

        if (array_key_exists('label', $validated)) {
            $changes['label'] = $validated['label'];
        }

        if (array_key_exists('idle_seconds', $validated)) {
            $changes['idle_seconds'] = $this->clampIdle($validated['idle_seconds']);
        }

        if (array_key_exists('idle_disabled', $validated)) {
            $changes['idle_disabled'] = (bool) $validated['idle_disabled'];
        }

        if (array_key_exists('elevated_idle_seconds', $validated)) {
            $changes['elevated_idle_seconds'] = $this->clampElevatedIdle($validated['elevated_idle_seconds']);
        }

        $before = $staffTerminal->only(array_keys($changes));
        $staffTerminal->forceFill($changes)->save();

        ActivityLog::log(
            'staff_terminal_updated',
            'security',
            ActivityLog::describeActor($request->user()) . ' changed the automatic logout settings for "' . $staffTerminal->label . '"',
            $request->user()?->getKey(),
            $staffTerminal->location_id,
            'staff_terminal',
            $staffTerminal->getKey(),
            ['before' => $before, 'after' => $changes]
        );

        return response()->json([
            'success' => true,
            'message' => 'Terminal settings saved.',
            'data' => $staffTerminal->fresh(),
        ]);
    }

    public function destroy(Request $request, StaffTerminal $staffTerminal): JsonResponse
    {
        if ($denied = $this->guardManager($request)) {
            return $denied;
        }

        if ($denied = $this->denyForeignRecord($staffTerminal, 'terminal')) {
            return $denied;
        }

        $staffTerminal->forceFill(['revoked_at' => now()])->save();

        ActivityLog::log(
            'staff_terminal_revoked',
            'security',
            ActivityLog::describeActor($request->user()) . ' removed "' . $staffTerminal->label . '" as a shared terminal',
            $request->user()?->getKey(),
            $staffTerminal->location_id,
            'staff_terminal',
            $staffTerminal->getKey()
        );

        return response()->json(['success' => true, 'message' => 'That terminal will ask for a full sign-in from now on.']);
    }

    private function clampIdle($seconds): ?int
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }

        $min = (int) config('staff_pins.idle.min_seconds', 15);
        $max = (int) config('staff_pins.idle.max_seconds', 3600);

        return max($min, min((int) $seconds, $max));
    }

    private function clampElevatedIdle($seconds): ?int
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }

        $min = (int) config('staff_pins.idle.min_seconds', 15);
        $max = (int) config('staff_pins.idle.elevated_max_seconds', 300);

        return max($min, min((int) $seconds, $max));
    }

    private function callerIsElevated(Request $request): bool
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        } catch (\Throwable $e) {
            return false;
        }

        if (! $user instanceof User || ! $this->isPinSession($user)) {
            return false;
        }

        return in_array(
            (string) EnsureStaff::effectiveRole($user),
            (array) config('staff_pins.elevated_roles', []),
            true
        );
    }

    private function isPinSession(?User $user): bool
    {
        try {
            $token = $user?->currentAccessToken();
            $abilities = is_object($token) ? ($token->abilities ?? []) : [];

            return is_array($abilities) && in_array('pin', $abilities, true);
        } catch (\Throwable $e) {
            return false;
        }
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
                'message' => 'Only a manager can change shared terminal settings.',
            ], 403);
        }

        return null;
    }
}
