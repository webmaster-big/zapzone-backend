<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with(['user', 'location']);

        $this->applyAuthScope($query, $request);

        if ($request->has('user_id')) {
            $query->byUser($request->user_id);
        }

        if ($request->has('location_id')) {
            $locationIds = is_array($request->location_id) ? $request->location_id : explode(',', $request->location_id);
            $query->byLocation($locationIds);
        }

        if ($request->has('entity_type')) {
            $entityIds = $request->has('entity_id') ? (is_array($request->entity_id) ? $request->entity_id : explode(',', $request->entity_id)) : null;
            $query->byEntity($request->entity_type, $entityIds);
        }

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('entity_type')) {
            $query->byEntity($request->entity_type, $request->entity_id ?? null);
        }

        if ($request->has('action')) {
            $query->byAction($request->action);
        }

        if ($request->has('date_from')) {
            $dateFrom = $request->date_from;
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($request->has('date_to')) {
            $dateTo = $request->date_to;
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($request->has('start_date')) {
            $startDate = $request->start_date;
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($request->has('end_date')) {
            $endDate = $request->end_date;
            $query->whereDate('created_at', '<=', $endDate);
        }

        if ($request->has('recent_days')) {
            $query->recent($request->recent_days);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['created_at', 'action', 'category', 'user_id', 'location_id'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'activity_logs' => $logs->items(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'action' => 'required|string|max:255',
            'category' => ['required', Rule::in(['create', 'update', 'delete', 'view', 'login', 'logout', 'export', 'import', 'other'])],
            'entity_type' => 'nullable|string|max:255',
            'entity_id' => 'nullable|integer',
            'description' => 'required|string',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if (!isset($validated['ip_address'])) {
            $validated['ip_address'] = $request->ip();
        }
        if (!isset($validated['user_agent'])) {
            $validated['user_agent'] = $request->userAgent();
        }

        $log = ActivityLog::create($validated);
        $log->load(['user', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Activity logged successfully',
            'data' => $log,
        ], 201);
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        $activityLog->load(['user', 'location']);

        return response()->json([
            'success' => true,
            'data' => $activityLog,
        ]);
    }
}
