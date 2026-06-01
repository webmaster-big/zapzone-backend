<?php

namespace App\Http\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

trait ScopesByAuthUser
{
    protected function resolveAuthUser(?Request $request = null): ?User
    {
        $user = auth()->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($request && $request->filled('user_id')) {
            return User::find($request->input('user_id'));
        }

        return null;
    }

    protected function applyAuthScope($query, ?Request $request = null, array $columns = []): void
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser) {
            return;
        }

        $companyCol  = $columns['company']  ?? 'company_id';
        $locationCol = $columns['location'] ?? 'location_id';

        if ($companyCol && $authUser->company_id && $this->columnExists($query, $companyCol)) {
            $query->where($companyCol, $authUser->company_id);
        }

        if (in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && $locationCol
            && $this->columnExists($query, $locationCol)) {
            $query->where($locationCol, $authUser->location_id);
        }
    }

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
        }
        return true;
    }
}
