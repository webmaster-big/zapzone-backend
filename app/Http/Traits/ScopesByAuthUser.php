<?php

namespace App\Http\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait ScopesByAuthUser
 *
 * Centralizes multi-tenant data scoping for API controllers.
 *
 * Roles:
 *  - company_admin     : can see all data within their company (all locations)
 *  - location_manager  : limited to their own location
 *  - attendant         : limited to their own location
 *  - customer          : limited to their own resources (handled per-controller)
 *
 * IMPORTANT: This replaces the legacy pattern that read `user_id` from the
 * request body / query string (which was insecure because the FE could omit
 * it or pass a different id to escape the filter). The auth user is now
 * resolved from the Sanctum bearer token.
 */
trait ScopesByAuthUser
{
    /**
     * Resolve the authenticated user. Falls back to the legacy
     * `user_id` request parameter ONLY if no Sanctum user is present
     * (kept for backwards-compat with old FE callers in test envs).
     */
    protected function resolveAuthUser(?Request $request = null): ?User
    {
        $user = auth()->user();
        if ($user) {
            return $user;
        }

        if ($request && $request->filled('user_id')) {
            return User::find($request->input('user_id'));
        }

        return null;
    }

    /**
     * Apply company + location scope to a query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array  $columns  ['company' => 'company_id', 'location' => 'location_id']
     */
    protected function applyAuthScope($query, ?Request $request = null, array $columns = []): void
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser) {
            return;
        }

        $companyCol  = $columns['company']  ?? 'company_id';
        $locationCol = $columns['location'] ?? 'location_id';

        // Always restrict to the user's company when the column exists.
        if ($companyCol && $authUser->company_id && $this->columnExists($query, $companyCol)) {
            $query->where($companyCol, $authUser->company_id);
        }

        // Non-admins are restricted to their own location.
        if (in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && $locationCol
            && $this->columnExists($query, $locationCol)) {
            $query->where($locationCol, $authUser->location_id);
        }
    }

    /**
     * Apply scope through a relationship (e.g. when the model itself does
     * not have location_id but its relation does).
     *
     * Example:
     *  $this->applyAuthScopeThrough($query, 'package', 'location_id');
     */
    protected function applyAuthScopeThrough($query, string $relation, string $locationCol = 'location_id', string $companyCol = 'company_id'): void
    {
        $authUser = $this->resolveAuthUser();
        if (!$authUser) {
            return;
        }

        $query->whereHas($relation, function ($q) use ($authUser, $locationCol, $companyCol) {
            if ($companyCol && $authUser->company_id) {
                $q->where($companyCol, $authUser->company_id);
            }
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $q->where($locationCol, $authUser->location_id);
            }
        });
    }

    /**
     * Authorize that an arbitrary record belongs to the auth user's scope.
     * Returns true on success, false otherwise. Caller is responsible for
     * returning the 403 response.
     */
    protected function authorizeRecordScope($record, string $locationCol = 'location_id', string $companyCol = 'company_id'): bool
    {
        $authUser = $this->resolveAuthUser();
        if (!$authUser || !$record) {
            return false;
        }

        if ($companyCol && isset($record->{$companyCol}) && $authUser->company_id
            && (int) $record->{$companyCol} !== (int) $authUser->company_id) {
            return false;
        }

        if (in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && $locationCol && isset($record->{$locationCol})
            && (int) $record->{$locationCol} !== (int) $authUser->location_id) {
            return false;
        }

        return true;
    }

    /**
     * Guard that the auth user is allowed to access the given location.
     * Returns a JsonResponse 403 if not allowed; null otherwise.
     *
     * Use in analytics/report endpoints where a location_id is supplied
     * by the caller and we must enforce that location_managers can only
     * pass their own.
     */
    protected function guardLocationAccess(?Request $request, $locationId)
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser) {
            return null;
        }
        if (in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && (int) $authUser->location_id !== (int) $locationId) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: cannot access another location\'s data',
            ], 403);
        }
        return null;
    }

    /**
     * Guard that the auth user is allowed to access the given company.
     * Returns a JsonResponse 403 if not allowed; null otherwise.
     */
    protected function guardCompanyAccess(?Request $request, $companyId)
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser) {
            return null;
        }
        if ($authUser->company_id && (int) $authUser->company_id !== (int) $companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: cannot access another company\'s data',
            ], 403);
        }
        return null;
    }

    /**
     * Best-effort check that the given column exists on the query's model
     * table. Wrapped in try/catch so it is safe to call against query
     * builders that may not have schema introspection support.
     */
    private function columnExists($query, string $column): bool
    {
        try {
            if (method_exists($query, 'getModel')) {
                $model = $query->getModel();
                $table = $model->getTable();
                static $cache = [];
                $key = $table . ':' . $column;
                if (!array_key_exists($key, $cache)) {
                    $cache[$key] = \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
                }
                return $cache[$key];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return true;
    }
}
