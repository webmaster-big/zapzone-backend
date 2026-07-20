<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\DayOff;
use App\Models\Event;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DayOffController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = DayOff::with('location');

        $this->applyAuthScope($query, $request);

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->byDateRange($request->start_date, $request->end_date);
        }

        if ($request->has('is_recurring')) {
            $query->where('is_recurring', $request->boolean('is_recurring'));
        }

        if ($request->has('package_id')) {
            $query->forPackage($request->package_id);
        }

        if ($request->has('room_id')) {
            $query->forRoom($request->room_id);
        }

        if ($request->has('attraction_id')) {
            $query->forAttraction($request->attraction_id);
        }

        if ($request->has('event_id')) {
            $query->forEvent($request->event_id);
        }

        if ($request->boolean('location_wide_only')) {
            $query->locationWide();
        }

        if ($request->boolean('upcoming_only')) {
            $query->upcoming();
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where('reason', 'like', $like);
            }
        }

        $sortBy = $request->get('sort_by', 'date');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        if (in_array($sortBy, ['date', 'created_at', 'updated_at'])) {
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
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        $validated['package_ids'] = !empty($validated['package_ids']) ? array_map('intval', $validated['package_ids']) : null;
        $validated['room_ids'] = !empty($validated['room_ids']) ? array_map('intval', $validated['room_ids']) : null;
        $validated['attraction_ids'] = !empty($validated['attraction_ids']) ? array_map('intval', $validated['attraction_ids']) : null;
        $validated['event_ids'] = !empty($validated['event_ids']) ? array_map('intval', $validated['event_ids']) : null;

        $existingDayOffs = DayOff::where('location_id', $validated['location_id'])
            ->where('date', $validated['date'])
            ->get();

        $newIsLocationWide = empty($validated['package_ids']) && empty($validated['room_ids']) &&
            empty($validated['attraction_ids']) && empty($validated['event_ids']);

        foreach ($existingDayOffs as $existing) {
            $existingIsLocationWide = empty($existing->package_ids) && empty($existing->room_ids) &&
                empty($existing->attraction_ids) && empty($existing->event_ids);

            if ($newIsLocationWide && $existingIsLocationWide) {
                if ($this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A location-wide day off already exists for this date and time range',
                    ], 422);
                }
            }

            if (!empty($validated['package_ids']) && !empty($existing->package_ids)) {
                $overlappingPackages = array_intersect($validated['package_ids'], $existing->package_ids);
                if (!empty($overlappingPackages) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these packages already exists for this date and time range',
                    ], 422);
                }
            }

            if (!empty($validated['room_ids']) && !empty($existing->room_ids)) {
                $overlappingRooms = array_intersect($validated['room_ids'], $existing->room_ids);
                if (!empty($overlappingRooms) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these rooms already exists for this date and time range',
                    ], 422);
                }
            }

            if (!empty($validated['attraction_ids']) && !empty($existing->attraction_ids)) {
                $overlappingAttractions = array_intersect($validated['attraction_ids'], $existing->attraction_ids);
                if (!empty($overlappingAttractions) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these attractions already exists for this date and time range',
                    ], 422);
                }
            }

            if (!empty($validated['event_ids']) && !empty($existing->event_ids)) {
                $overlappingEvents = array_intersect($validated['event_ids'], $existing->event_ids);
                if (!empty($overlappingEvents) && $this->hasTimeOverlap($existing, $validated)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A day off for some of these events already exists for this date and time range',
                    ], 422);
                }
            }
        }

        $dayOff = DayOff::create($validated);
        $dayOff->load('location');

        $scope = $dayOff->isLocationWide() ? 'location-wide' : '';
        if (!empty($dayOff->package_ids)) {
            $packageNames = Package::whereIn('id', $dayOff->package_ids)->pluck('name')->implode(', ');
            $scope .= "packages: {$packageNames}";
        }
        if (!empty($dayOff->room_ids)) {
            $roomNames = Room::whereIn('id', $dayOff->room_ids)->pluck('name')->implode(', ');
            $scope .= (!empty($scope) ? ', ' : '') . "rooms: {$roomNames}";
        }
        if (!empty($dayOff->attraction_ids)) {
            $attractionNames = Attraction::whereIn('id', $dayOff->attraction_ids)->pluck('name')->implode(', ');
            $scope .= (!empty($scope) ? ', ' : '') . "attractions: {$attractionNames}";
        }
        if (!empty($dayOff->event_ids)) {
            $eventNames = Event::whereIn('id', $dayOff->event_ids)->pluck('name')->implode(', ');
            $scope .= (!empty($scope) ? ', ' : '') . "events: {$eventNames}";
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Day Off Created',
            category: 'create',
            description: "Day off created for {$dayOff->date->format('Y-m-d')} ({$scope}): {$dayOff->reason}",
            userId: auth()->id(),
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id,
            metadata: [
                'created_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'created_at' => now()->toIso8601String(),
                'day_off_details' => [
                    'day_off_id' => $dayOff->id,
                    'date' => $dayOff->date->format('Y-m-d'),
                    'time_start' => $dayOff->time_start,
                    'time_end' => $dayOff->time_end,
                    'reason' => $dayOff->reason,
                    'is_recurring' => $dayOff->is_recurring,
                    'location_id' => $dayOff->location_id,
                    'scope' => $scope,
                ],
                'affected_resources' => [
                    'package_ids' => $dayOff->package_ids,
                    'room_ids' => $dayOff->room_ids,
                    'attraction_ids' => $dayOff->attraction_ids,
                    'event_ids' => $dayOff->event_ids,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off created successfully',
            'data' => $dayOff,
        ], 201);
    }

    public function show(DayOff $dayOff): JsonResponse
    {
        $dayOff->load('location');

        return response()->json([
            'success' => true,
            'data' => $dayOff,
        ]);
    }

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
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        if (array_key_exists('package_ids', $validated)) {
            $validated['package_ids'] = !empty($validated['package_ids']) ? array_map('intval', $validated['package_ids']) : null;
        }
        if (array_key_exists('room_ids', $validated)) {
            $validated['room_ids'] = !empty($validated['room_ids']) ? array_map('intval', $validated['room_ids']) : null;
        }
        if (array_key_exists('attraction_ids', $validated)) {
            $validated['attraction_ids'] = !empty($validated['attraction_ids']) ? array_map('intval', $validated['attraction_ids']) : null;
        }
        if (array_key_exists('event_ids', $validated)) {
            $validated['event_ids'] = !empty($validated['event_ids']) ? array_map('intval', $validated['event_ids']) : null;
        }

        $dayOff->update($validated);
        $dayOff->load('location');

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Day Off Updated',
            category: 'update',
            description: "Day off updated for {$dayOff->date->format('Y-m-d')}",
            userId: auth()->id(),
            locationId: $dayOff->location_id,
            entityType: 'day_off',
            entityId: $dayOff->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($validated),
                'day_off_details' => [
                    'day_off_id' => $dayOff->id,
                    'date' => $dayOff->date->format('Y-m-d'),
                    'time_start' => $dayOff->time_start,
                    'time_end' => $dayOff->time_end,
                    'reason' => $dayOff->reason,
                    'location_id' => $dayOff->location_id,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off updated successfully',
            'data' => $dayOff,
        ]);
    }

    public function destroy(DayOff $dayOff): JsonResponse
    {
        $dayOffDate = $dayOff->date->format('Y-m-d');
        $dayOffId = $dayOff->id;
        $locationId = $dayOff->location_id;

        $dayOff->delete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Day Off Deleted',
            category: 'delete',
            description: "Day off for '{$dayOffDate}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'day_off',
            entityId: $dayOffId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'day_off_details' => [
                    'day_off_id' => $dayOffId,
                    'date' => $dayOffDate,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Day off deleted successfully',
        ]);
    }

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

    public function checkDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i',
        ]);

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

                $currentUser = auth()->user();
                ActivityLog::log(
                    action: 'Day Off Bulk Deleted',
                    category: 'delete',
                    description: "Day off for '{$dayOffDate}' was deleted via bulk operation",
                    userId: auth()->id(),
                    locationId: $locationId,
                    entityType: 'day_off',
                    entityId: $id,
                    metadata: [
                        'deleted_by' => [
                            'user_id' => auth()->id(),
                            'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                            'email' => $currentUser?->email,
                        ],
                        'deleted_at' => now()->toIso8601String(),
                        'day_off_details' => [
                            'day_off_id' => $id,
                            'date' => $dayOffDate,
                            'location_id' => $locationId,
                        ],
                        'bulk_operation' => true,
                    ]
                );
            }
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Day Offs Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} day offs",
            userId: auth()->id(),
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'day_off_ids' => $ids,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} day off(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    private function hasTimeOverlap(DayOff $existing, array $new): bool
    {
        $existingStart = $existing->time_start;
        $existingEnd = $existing->time_end;
        $newStart = $new['time_start'] ?? null;
        $newEnd = $new['time_end'] ?? null;

        if (is_null($existingStart) && is_null($existingEnd) && is_null($newStart) && is_null($newEnd)) {
            return true;
        }

        if ((is_null($existingStart) && is_null($existingEnd)) || (is_null($newStart) && is_null($newEnd))) {
            return true;
        }

        if (!is_null($existingStart) && is_null($existingEnd) && !is_null($newStart) && is_null($newEnd)) {
            return true; // Both close early, overlap from whichever is earlier
        }

        if (is_null($existingStart) && !is_null($existingEnd) && is_null($newStart) && !is_null($newEnd)) {
            return true; // Both delayed opening, overlap from start until whichever is later
        }

        if (!is_null($existingStart) && !is_null($existingEnd) && !is_null($newStart) && !is_null($newEnd)) {
            return $newStart < $existingEnd && $newEnd > $existingStart;
        }

        return true;
    }
}
