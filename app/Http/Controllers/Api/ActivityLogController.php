<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query();

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('activity_type')) {
            $query->where('activity_type', $request->activity_type);
        }

        $perPage = $request->get('per_page', 15);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'activity_logs' => $logs->items(),
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'activity_type' => 'required|string',
            'description' => 'required|string',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
        ]);

        $log = ActivityLog::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Activity logged successfully',
            'data' => $log,
        ], 201);
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $activityLog]);
    }
}
