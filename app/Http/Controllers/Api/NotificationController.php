<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::with('location');

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->byPriority($request->priority);
        }

        // Filter unread/read
        if ($request->has('unread')) {
            $query->unread();
        } elseif ($request->has('read')) {
            $query->read();
        }

        $perPage = $request->get('per_page', 15);
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications->items(),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'type' => ['required', Rule::in(['system', 'booking', 'payment', 'staff', 'customer', 'promotion', 'gift_card', 'reminder'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'action_url' => 'nullable|string',
            'action_text' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['status'] = 'unread';

        $notification = Notification::create($validated);
        $notification->load('location');

        return response()->json([
            'success' => true,
            'message' => 'Notification created successfully',
            'data' => $notification,
        ], 201);
    }

    public function show(Notification $notification): JsonResponse
    {
        $notification->load('location');
        return response()->json(['success' => true, 'data' => $notification]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $notification->update([
            'status' => 'read',
            'read_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $locationId = $request->get('location_id');

        if (!$locationId) {
            return response()->json([
                'success' => false,
                'message' => 'location_id is required',
            ], 422);
        }

        Notification::where('location_id', $locationId)
            ->where('status', 'unread')
            ->update([
                'status' => 'read',
                'read_at' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    public function destroy(Notification $notification): JsonResponse
    {
        $notificationId = $notification->id;
        $title = $notification->title;
        $locationId = $notification->location_id;

        $notification->delete();

        // Log notification deletion
        ActivityLog::log(
            action: 'Notification Deleted',
            category: 'delete',
            description: "Notification '{$title}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'notification',
            entityId: $notificationId
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully',
        ]);
    }

    public function update(Request $request, Notification $notification): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['unread', 'read', 'archived'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'read' && $notification->status !== 'read') {
            $validated['read_at'] = now();
        }

        $notification->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Notification updated successfully',
            'data' => $notification,
        ]);
    }
}
