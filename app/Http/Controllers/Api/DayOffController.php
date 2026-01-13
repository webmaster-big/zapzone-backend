<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DayOff;
use App\Models\Package;
use App\Models\Room;
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

        // Filter by package
        if ($request->has('package_id')) {
            $query->forPackage($request->package_id);
        }

        // Filter by room
        if ($request->has('room_id')) {
            $query->forRoom($request->room_id);
        }

        // Filter location-wide only
        if ($request->boolean('location_wide_only')) {
            $query->locationWide();
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
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i',
            'reason' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'integer|exists:rooms,id',
        ]);

        // Ensure package_ids and room_ids are arrays or null
        $validated['package_ids'] = !empty($validated['package_ids']) ? array_map('intval', $validated['package_ids']) : null;
        $validated['room_ids'] = !empty($validated['room_ids']) ? array_map('intval', $validated['room_ids']) : null;

        // Check for overlapping day offs with similar scope
        // We allow multiple day offs on same date for different resources
        $existingDayOffs = DayOff::where('location_id', $validated['location_id'])
            ->where('date', $validated['date'])
            ->get();

        foreach ($existingDayOffs as $existing) {
            // Check for duplicate location-wide blocks
            if (empty($validated['package_ids']) && empty($validated['room_ids']) &&
                empty($existing->package_ids) && empty($existing->room_ids)) {
                // Both are location-wide, check time overlap
                if ($this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A location-wide day off already exists for this date and time range',
                    ], 422);
                }
            }

            // Check for duplicate package-specific blocks
            if (!empty($validated['package_ids']) && !empty($existing->package_ids)) {
                $overlappingPackages = array_intersect($validated['package_ids'], $existing->package_ids);
                if (!empty($overlappingPackages) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these packages already exists for this date and time range',
                    ], 422);
                }
            }

            // Check for duplicate room-specific blocks
            if (!empty($validated['room_ids']) && !empty($existing->room_ids)) {
                $overlappingRooms = array_intersect($validated['room_ids'], $existing->room_ids);
                if (!empty($overlappingRooms) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these rooms already exists for this date and time range',
                    ], 422);
                }
            }
        }

        $dayOff = DayOff::create($validated);
        $dayOff->load('location');

        // Build description for activity log
        $scope = $dayOff->isLocationWide() ? 'location-wide' : '';
        if (!empty($dayOff->package_ids)) {
            $packageNames = Package::whereIn('id', $dayOff->package_ids)->pluck('name')->implode(', ');
            $scope .= "packages: {$packageNames}";
        }
        if (!empty($dayOff->room_ids)) {
            $roomNames = Room::whereIn('id', $dayOff->room_ids)->pluck('name')->implode(', ');
            $scope .= (!empty($scope) ? ', ' : '') . "rooms: {$roomNames}";
        }

        // Log activity
        ActivityLog::log(
            action: 'Day Off Created',
            category: 'create',
            description: "Day off created for {$dayOff->date->format('Y-m-d')} ({$scope}): {$dayOff->reason}",
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
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i',
            'reason' => 'nullable|string|max:255',
            'is_recurring' => 'boolean',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'integer|exists:rooms,id',
        ]);

        // Process package_ids and room_ids
        if (array_key_exists('package_ids', $validated)) {
            $validated['package_ids'] = !empty($validated['package_ids']) ? array_map('intval', $validated['package_ids']) : null;
        }
        if (array_key_exists('room_ids', $validated)) {
            $validated['room_ids'] = !empty($validated['room_ids']) ? array_map('intval', $validated['room_ids']) : null;
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
     * Check if a specific date/time is blocked.
     */
    public function checkDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i',
        ]);

        // If time is provided, check specific time slot
        if (isset($validated['time_start'])) {
            $isBlocked = DayOff::isTimeSlotBlocked(
                $validated['location_id'],
                $validated['date'],
                $validated['time_start'],
                $validated['time_end'] ?? null
            );

            $dayOff = $isBlocked ? DayOff::getDayOffForTimeSlot(
                $validated['location_id'],
                $validated['date'],
                $validated['time_start'],
                $validated['time_end'] ?? null
            ) : null;
        } else {
            // Check for full day block only (legacy behavior)
            $isBlocked = DayOff::isDateBlocked($validated['location_id'], $validated['date']);

            $dayOff = null;
            if ($isBlocked) {
                $dayOff = DayOff::where('location_id', $validated['location_id'])
                    ->where('date', $validated['date'])
                    ->whereNull('time_start')
                    ->whereNull('time_end')
                    ->first();
            }
        }

        // Also get all day offs for this date (for full visibility)
        $allDayOffs = DayOff::where('location_id', $validated['location_id'])
            ->where('date', $validated['date'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'is_blocked' => $isBlocked,
                'day_off' => $dayOff,
                'all_day_offs' => $allDayOffs,
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

    /**
     * Helper method to check if two day offs have overlapping time ranges.
     */
    private function hasTimeOverlap(DayOff $existing, array $new): bool
    {
        $existingStart = $existing->time_start;
        $existingEnd = $existing->time_end;
        $newStart = $new['time_start'] ?? null;
        $newEnd = $new['time_end'] ?? null;

        // Both are full day blocks
        if (is_null($existingStart) && is_null($existingEnd) && is_null($newStart) && is_null($newEnd)) {
            return true;
        }

        // If either is a full day block, they overlap
        if ((is_null($existingStart) && is_null($existingEnd)) || (is_null($newStart) && is_null($newEnd))) {
            return true;
        }

        // Both have close early (from time_start until end of day)
        if (!is_null($existingStart) && is_null($existingEnd) && !is_null($newStart) && is_null($newEnd)) {
            return true; // Both close early, overlap from whichever is earlier
        }

        // Both have delayed opening (from start of day until time_end)
        if (is_null($existingStart) && !is_null($existingEnd) && is_null($newStart) && !is_null($newEnd)) {
            return true; // Both delayed opening, overlap from start until whichever is later
        }

        // For specific time ranges, check actual overlap
        if (!is_null($existingStart) && !is_null($existingEnd) && !is_null($newStart) && !is_null($newEnd)) {
            // Time ranges overlap if new starts before existing ends AND new ends after existing starts
            return $newStart < $existingEnd && $newEnd > $existingStart;
        }

        // Mixed cases (one is a range, other is partial) - assume overlap for safety
        return true;
    }
}
