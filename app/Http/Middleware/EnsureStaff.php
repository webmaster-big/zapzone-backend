<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails CLOSED for staff-only endpoints.
 *
 * A customer token is mintable from a public endpoint and passes auth:sanctum just as a
 * staff token does. App\Http\Traits\ScopesByAuthUser then returns null for that
 * principal and every `$user?->company_id` guard downstream skips instead of denying, so
 * a bare auth:sanctum route hands a customer every company's data. This requires a real
 * staff User, in an approved role, attached to a company, before the route runs.
 *
 * Pass roles to narrow further: middleware('staff:company_admin|location_manager').
 */
class EnsureStaff
{
    public const ROLES = ['company_admin', 'admin', 'location_manager', 'attendant'];

    public const ROLE_RANK = [
        'staff' => 1,
        'attendant' => 1,
        'location_manager' => 2,
        'admin' => 3,
        'company_admin' => 3,
    ];

    /**
     * The role this request actually acts with.
     *
     * A PIN-minted token carries a `role:<name>` ability recording the ceiling of the terminal it
     * was issued on. The ability can only ever NARROW the account's own role: a token claiming a
     * higher rank than the user holds is ignored outright, so this can never raise privilege.
     */
    public static function effectiveRole(?User $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        $accountRole = (string) $user->role;
        $claimed = null;

        try {
            $token = $user->currentAccessToken();
            $abilities = is_object($token) ? ($token->abilities ?? null) : null;

            if (is_array($abilities)) {
                foreach ($abilities as $ability) {
                    if (is_string($ability) && str_starts_with($ability, 'role:')) {
                        $claimed = substr($ability, 5);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            return $accountRole;
        }

        if ($claimed === null || !array_key_exists($claimed, self::ROLE_RANK)) {
            return $accountRole;
        }

        $accountRank = self::ROLE_RANK[$accountRole] ?? 0;
        $claimedRank = self::ROLE_RANK[$claimed];

        return $claimedRank < $accountRank ? $claimed : $accountRole;
    }

    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !in_array((string) $user->role, self::ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This area is for staff accounts only.',
            ], 403);
        }

        if ($user->company_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to a company yet. Please ask an administrator to finish setting it up.',
            ], 403);
        }

        $role = self::effectiveRole($user);

        if ($only !== null && !in_array((string) $role, explode('|', $only), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your role does not have access to this setting. Please ask a manager if you need it changed.',
            ], 403);
        }

        return $next($request);
    }
}
