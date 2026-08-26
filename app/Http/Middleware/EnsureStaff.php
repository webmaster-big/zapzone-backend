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

        if ($only !== null && !in_array((string) $user->role, explode('|', $only), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Your role does not have access to this setting. Please ask a manager if you need it changed.',
            ], 403);
        }

        return $next($request);
    }
}
