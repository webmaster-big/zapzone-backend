<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    /**
     * Display a listing of rooms.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Room::with(['location', 'packages']);

            // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            // log the auth user info
            if ($authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by availability
        if ($request->has('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        } else {
            $query->available();
        }

        // Filter by capacity
        if ($request->has('min_capacity')) {
            $query->byCapacity($request->min_capacity);
        }

        // Price range filter
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Search by name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        // Sort
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['name', 'capacity', 'price', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $rooms = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'rooms' => $rooms->items(),
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
    }

    /**
     * Store a newly created room.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'price' => 'nullable|numeric|min:0',
            'is_available' => 'boolean',
        ]);

        $room = Room::create($validated);
        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully',
            'data' => $room,
        ], 201);
    }

    /**
     * Display the specified room.
     */
    public function show(Room $room): JsonResponse
    {
        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'data' => $room,
        ]);
    }

    /**
     * Update the specified room.
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'capacity' => 'sometimes|nullable|integer|min:1',
            'price' => 'sometimes|nullable|numeric|min:0',
            'is_available' => 'boolean',
        ]);

        $room->update($validated);
        $room->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Room updated successfully',
            'data' => $room,
        ]);
    }

    /**
     * Remove the specified room.
     */
    public function destroy(Room $room): JsonResponse
    {
        $roomName = $room->name;
        $roomId = $room->id;
        $locationId = $room->location_id;

        $room->delete();

        // Log room deletion
        ActivityLog::log(
            action: 'Room Deleted',
            category: 'delete',
            description: "Room '{$roomName}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'room',
            entityId: $roomId
        );

        return response()->json([
            'success' => true,
            'message' => 'Room deleted successfully',
        ]);
    }

    /**
     * Get rooms by location.
     */
    public function getByLocation(int $locationId): JsonResponse
    {
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

    /**
     * Toggle room availability.
     */
    public function toggleAvailability(Room $room): JsonResponse
    {
        $room->update(['is_available' => !$room->is_available]);

        return response()->json([
            'success' => true,
            'message' => 'Room availability updated successfully',
            'data' => $room,
        ]);
    }

    /**
     * Get available rooms with capacity.
     */
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

    /**
     * Bulk delete rooms.
     */
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
            if ($room) {
                $roomName = $room->name;
                $locationId = $room->location_id;
                
                $room->delete();
                $deletedCount++;

                // Log each room deletion
                ActivityLog::log(
                    action: 'Room Bulk Deleted',
                    category: 'delete',
                    description: "Room '{$roomName}' was deleted via bulk operation",
                    userId: auth()->id(),
                    locationId: $locationId,
                    entityType: 'room',
                    entityId: $id
                );
            }
        }

        // Log the bulk operation summary
        ActivityLog::log(
            action: 'Rooms Bulk Delete',
            category: 'delete',
            description: "Bulk deleted {$deletedCount} rooms",
            userId: auth()->id(),
            metadata: ['count' => $deletedCount, 'ids' => $ids]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} room(s) deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
