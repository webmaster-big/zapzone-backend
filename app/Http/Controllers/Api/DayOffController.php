<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DayOff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DayOffController extends Controller
{
    /**
     * Display a listing of day offs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DayOff::with('location');

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            if ($authUser && $authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by date
        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->byDateRange($request->start_date, $request->end_date);
        }

        // Filter by recurring
        if ($request->has('is_recurring')) {
            $query->where('is_recurring', $request->boolean('is_recurring'));
        }

        // Filter upcoming only
        if ($request->boolean('upcoming_only')) {
            $query->upcoming();
        }

        // Sort
        $sortBy = $request->get('sort_by', 'date');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['date', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $dayOffs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'day_offs' => $dayOffs->items(),
                'pagination' => [
                    'current_page' => $dayOffs->currentPage(),
                    'last_page' => $dayOffs->lastPage(),
                    'per_page' => $dayOffs->perPage(),
                    'total' => $dayOffs->total(),
                    'from' => $dayOffs->firstItem(),
                    'to' => $dayOffs->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created day off.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'reason' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
        ]);

        // Check if day off already exists
        $exists = DayOff::where('location_id', $validated['location_id'])
            ->where('date', $validated['date'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Day off already exists for this date and location',
            ], 422);
        }

        $dayOff = DayOff::create($validated);
        $dayOff->load('location');

        // Log activity
        ActivityLog::log(
            action: 'Day Off Created',
            category: 'create',
            description: "Day off created for {$dayOff->date->format('Y-m-d')}: {$dayOff->reason}",
            userId: auth()->id(),
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off created successfully',
            'data' => $dayOff,
        ], 201);
    }

    /**
     * Display the specified day off.
     */
    public function show(DayOff $dayOff): JsonResponse
    {
        $dayOff->load('location');

        return response()->json([
            'success' => true,
            'data' => $dayOff,
        ]);
    }

    /**
     * Update the specified day off.
     */
    public function update(Request $request, DayOff $dayOff): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'date' => 'sometimes|date',
            'reason' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
        ]);

        // Check for duplicate if date or location changed
        if (isset($validated['date']) || isset($validated['location_id'])) {
            $locationId = $validated['location_id'] ?? $dayOff->location_id;
            $date = $validated['date'] ?? $dayOff->date;

            $exists = DayOff::where('location_id', $locationId)
                ->where('date', $date)
                ->where('id', '!=', $dayOff->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Day off already exists for this date and location',
                ], 422);
            }
        }

        $dayOff->update($validated);
        $dayOff->load('location');

        // Log activity
        ActivityLog::log(
            action: 'Day Off Updated',
            category: 'update',
            description: "Day off updated for {$dayOff->date->format('Y-m-d')}",
            userId: auth()->id(),
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off updated successfully',
            'data' => $dayOff,
        ]);
    }

    /**
     * Remove the specified day off.
     */
    public function destroy(DayOff $dayOff): JsonResponse
    {
        $dayOffDate = $dayOff->date->format('Y-m-d');
        $dayOffId = $dayOff->id;
        $locationId = $dayOff->location_id;

        $dayOff->delete();

        // Log activity
        ActivityLog::log(
            action: 'Day Off Deleted',
            category: 'delete',
            description: "Day off for '{$dayOffDate}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'day_off',
            entityId: $dayOffId
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off deleted successfully',
        ]);
    }

    /**
     * Get day offs by location.
     */
    public function getByLocation(int $locationId): JsonResponse
    {
        $dayOffs = DayOff::byLocation($locationId)
            ->upcoming()
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dayOffs,
        ]);
    }

    /**
     * Check if a specific date is blocked.
     */
    public function checkDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
        ]);

        $isBlocked = DayOff::isDateBlocked($validated['location_id'], $validated['date']);

        $dayOff = null;
        if ($isBlocked) {
            $dayOff = DayOff::where('location_id', $validated['location_id'])
                ->where('date', $validated['date'])
                ->first();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'is_blocked' => $isBlocked,
                'day_off' => $dayOff,
            ],
        ]);
    }

    /**
     * Bulk delete day offs.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:day_offs,id',
        ]);

        $ids = $validated['ids'];
        $deletedCount = 0;

        foreach ($ids as $id) {
            $dayOff = DayOff::find($id);
            if ($dayOff) {
                $dayOffDate = $dayOff->date->format('Y-m-d');
                $locationId = $dayOff->location_id;

                $dayOff->delete();
                $deletedCount++;

                // Log each deletion
                ActivityLog::log(
                    action: 'Day Off Bulk Deleted',
                    category: 'delete',
                    description: "Day off for '{$dayOffDate}' was deleted via bulk operation",
                    userId: auth()->id(),
                    locationId: $locationId,
                    entityType: 'day_off',
                    entityId: $id
                );
            }
        }

        // Log bulk operation summary
        ActivityLog::log(
            action: 'Day Offs Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} day offs",
            userId: auth()->id(),
            metadata: ['count' => $deletedCount, 'ids' => $ids]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} day off(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
