<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    /**
     * Display a listing of activity logs with comprehensive filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with(['user', 'location']);

        // Filter by user
        if ($request->has('user_id')) {
            $query->byUser($request->user_id);
        }

        // Filter by location, multiple locations support but also accept 1 location
        if ($request->has('location_id')) {
            $locationIds = is_array($request->location_id) ? $request->location_id : explode(',', $request->location_id);
            $query->byLocation($locationIds);
        }

        // user entity type and optional entity id, multiple ids support
        if ($request->has('entity_type')) {
            $entityIds = $request->has('entity_id') ? (is_array($request->entity_id) ? $request->entity_id : explode(',', $request->entity_id)) : null;
            $query->byEntity($request->entity_type, $entityIds);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Filter by entity type
        if ($request->has('entity_type')) {
            $query->byEntity($request->entity_type, $request->entity_id ?? null);
        }

        // Filter by action (partial match)
        if ($request->has('action')) {
            $query->byAction($request->action);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $dateFrom = $request->date_from;
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($request->has('date_to')) {
            $dateTo = $request->date_to;
            $query->whereDate('created_at', '<=', $dateTo);
        }

        // start date and end date range
        if ($request->has('start_date')) {
            $startDate = $request->start_date;
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($request->has('end_date')) {
            $endDate = $request->end_date;
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Filter recent logs (last N days)
        if ($request->has('recent_days')) {
            $query->recent($request->recent_days);
        }

        // Search in description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
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

    /**
     * Store a new activity log
     */
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

        // Auto-fill IP and user agent if not provided
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

    /**
     * Display the specified activity log
     */
    public function show(ActivityLog $activityLog): JsonResponse
    {
        $activityLog->load(['user', 'location']);

        return response()->json([
            'success' => true,
            'data' => $activityLog,
        ]);
    }
}
