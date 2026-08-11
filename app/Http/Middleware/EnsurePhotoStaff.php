<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails CLOSED for the photo module.
 *
 * App\Http\Traits\ScopesByAuthUser silently applies NO tenant scoping when the
 * authenticated principal is not an App\Models\User, and Sanctum has no configured
 * provider — so a customer token (mintable from a public endpoint) would otherwise
 * read every company's photo data. This middleware requires a real staff User with
 * one of the three approved roles before any photo endpoint runs.
 */
class EnsurePhotoStaff
{
    public const ROLES = ['company_admin', 'admin', 'location_manager', 'attendant'];

    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'This area is for staff accounts only.',
            ], 403);
        }

        if (!in_array((string) $user->role, self::ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'This area is for staff accounts only.',
            ], 403);
        }

        if ($user->company_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to a company yet, so we cannot show photo data. Please ask an administrator to finish setting it up.',
            ], 403);
        }

        if ($only !== null) {
            $allowed = explode('|', $only);

            if (!in_array((string) $user->role, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your role does not have access to this photo setting. Please ask a manager if you need it changed.',
                ], 403);
            }
        }

        return $next($request);
    }
}
