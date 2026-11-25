<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Room;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 500);

            $query = Room::with(['location:id,name', 'packages:id,name']);

            $this->applyAuthScope($query, $request);

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('is_available')) {
                $query->where('is_available', $request->boolean('is_available'));
            } elseif (! $request->boolean('include_unavailable')) {
                $query->available();
            }

            if ($request->has('min_capacity')) {
                $query->byCapacity($request->min_capacity);
            }

            if ($request->has('min_price')) {
                $query->where('price', '>=', $request->min_price);
            }
            if ($request->has('max_price')) {
                $query->where('price', '<=', $request->max_price);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%");
            }

            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');

            if (in_array($sortBy, ['name', 'capacity', 'price', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

            $query->orderBy('rooms.id');

            $rooms = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'rooms' => $rooms->items(),
                    // the package form previews start times client-side and needs the
                    // same cleanup gap the availability check applies
                    'slot_cleanup_minutes' => max(0, (int) config('booking_rules.room_cleanup_minutes', 15)),
                    'pagination' => [
                        'current_page' => $rooms->currentPage(),
                        'last_page' => $rooms->lastPage(),
                        'per_page' => $rooms->perPage(),
                        'total' => $rooms->total(),
                        'from' => $rooms->firstItem(),
                        'to' => $rooms->lastItem(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error fetching rooms', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rooms',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'is_available' => 'boolean',
            'break_time' => 'nullable|array',
            'area_group' => 'nullable|string|max:255',
            'booking_interval' => 'nullable|integer|min:0',
            'area_allows_simultaneous_starts' => 'sometimes|filled|boolean',
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);
        if ($location instanceof JsonResponse) {
            return $location;
        }

        $room = Room::create($this->withAreaStartSetting($validated));
        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully',
            'data' => $room,
        ], 201);
    }

    public function show(Room $room): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($room, 'space')) {
            return $denied;
        }

        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'data' => $room,
        ]);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($room, 'space')) {
            return $denied;
        }

        $validated = $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'price' => 'sometimes|nullable|numeric|min:0',
            'is_available' => 'boolean',
            'break_time' => 'nullable|array',
            'area_group' => 'nullable|string|max:255',
            'booking_interval' => 'nullable|integer|min:0',
            'area_allows_simultaneous_starts' => 'sometimes|filled|boolean',
        ]);

        if (array_key_exists('location_id', $validated) && (int) $validated['location_id'] !== (int) $room->location_id) {
            $location = $this->scopedLocation($request, $validated['location_id']);
            if ($location instanceof JsonResponse) {
                return $location;
            }
        }

        $room->update($this->withAreaStartSetting($validated, $room));
        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Room updated successfully',
            'data' => $room,
        ]);
    }

    public function updateBookingIntervalByAreaGroup(Request $request, string $areaGroup): JsonResponse
    {
        $validated = $request->validate([
            'booking_interval' => 'required_without:area_allows_simultaneous_starts|filled|integer|min:0',
            'area_allows_simultaneous_starts' => 'sometimes|filled|boolean',
        ]);

        $query = Room::where('area_group', $areaGroup);

        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $location = $this->scopedLocation($request, $request->input('location_id'));
            if ($location instanceof JsonResponse) {
                return $location;
            }
            $query->where('location_id', $location->id);
        } else {
            $authUser = $this->resolveAuthUser($request);
            if ($authUser && $authUser->company_id) {
                $query->whereIn(
                    'location_id',
                    \App\Models\Location::where('company_id', $authUser->company_id)->pluck('id')
                );
            }
        }

        $changes = [];

        if (array_key_exists('booking_interval', $validated)) {
            $changes['booking_interval'] = (int) $validated['booking_interval'];
        }

        if (array_key_exists('area_allows_simultaneous_starts', $validated)) {
            $changes['area_allows_simultaneous_starts'] = (bool) $validated['area_allows_simultaneous_starts'];
        }

        $updatedCount = $query->update($changes);
        $spaces = "{$updatedCount} space" . ($updatedCount === 1 ? '' : 's');

        return response()->json([
            'success' => true,
            'message' => array_key_exists('area_allows_simultaneous_starts', $changes)
                ? "Area settings updated for {$spaces} in the {$areaGroup} area"
                : "Turnaround updated for {$spaces} in the {$areaGroup} area",
            'data' => ['updated_count' => $updatedCount],
        ]);
    }

    private function withAreaStartSetting(array $validated, ?Room $room = null): array
    {
        if (array_key_exists('area_allows_simultaneous_starts', $validated)) {
            return $validated;
        }

        $areaGroup = array_key_exists('area_group', $validated) ? $validated['area_group'] : $room?->area_group;
        $locationId = (int) ($validated['location_id'] ?? $room?->location_id);

        if ($areaGroup === null || $areaGroup === '') {
            return $validated;
        }

        if ($room && $areaGroup === $room->area_group && $locationId === (int) $room->location_id) {
            return $validated;
        }

        $areaRooms = Room::where('location_id', $locationId)
            ->where('area_group', $areaGroup)
            ->when($room, fn ($query) => $query->whereKeyNot($room->id))
            ->get();

        $validated['area_allows_simultaneous_starts'] = Room::areaAllowsSimultaneousStarts($areaRooms);

        return $validated;
    }

    public function destroy(Room $room): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($room, 'space')) {
            return $denied;
        }

        $roomName = $room->name;
        $roomId = $room->id;
        $locationId = $room->location_id;

        $room->delete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Room Deleted',
            category: 'delete',
            description: "Room '{$roomName}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'room',
            entityId: $roomId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'room_details' => [
                    'room_id' => $roomId,
                    'name' => $roomName,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Room deleted successfully',
        ]);
    }

    public function getByLocation(int $locationId): JsonResponse
    {
        if ($denied = $this->guardLocationAccess(request(), $locationId)) {
            return $denied;
        }

        $rooms = Room::with(['packages'])
            ->byLocation($locationId)
            ->available()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rooms,
        ]);
    }

    public function toggleAvailability(Room $room): JsonResponse
    {
        if ($denied = $this->denyForeignRecord($room, 'space')) {
            return $denied;
        }

        $room->update(['is_available' => !$room->is_available]);

        return response()->json([
            'success' => true,
            'message' => 'Room availability updated successfully',
            'data' => $room,
        ]);
    }

    public function getAvailableRooms(Request $request): JsonResponse
    {
        $query = Room::with(['location'])
            ->available();

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('min_capacity')) {
            $query->byCapacity($request->min_capacity);
        }

        $rooms = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $rooms,
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:rooms,id',
        ]);

        $ids = $validated['ids'];
        $deletedCount = 0;

        foreach ($ids as $id) {
            $room = Room::find($id);
            if ($room && $this->authorizeRecordScope($room)) {
                $roomName = $room->name;
                $locationId = $room->location_id;

                $room->delete();
                $deletedCount++;

                $currentUser = auth()->user();
                ActivityLog::log(
                    action: 'Room Bulk Deleted',
                    category: 'delete',
                    description: "Room '{$roomName}' was deleted via bulk operation",
                    userId: auth()->id(),
                    locationId: $locationId,
                    entityType: 'room',
                    entityId: $id,
                    metadata: [
                        'deleted_by' => [
                            'user_id' => auth()->id(),
                            'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                            'email' => $currentUser?->email,
                        ],
                        'deleted_at' => now()->toIso8601String(),
                        'room_details' => [
                            'room_id' => $id,
                            'name' => $roomName,
                            'location_id' => $locationId,
                        ],
                        'bulk_operation' => true,
                    ]
                );
            }
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Rooms Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} rooms",
            userId: auth()->id(),
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'room_ids' => $ids,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} room(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
