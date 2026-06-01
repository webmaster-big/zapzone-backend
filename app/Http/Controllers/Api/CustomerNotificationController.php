<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CustomerNotificationController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = CustomerNotification::with(['customer', 'location']);

        $this->applyAuthScope($query, $request);

        if ($request->has('customer_id')) {
            $query->byCustomer($request->customer_id);
        }

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('priority')) {
            $query->byPriority($request->priority);
        }

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
            'customer_id' => 'required|exists:customers,id',
            'location_id' => 'nullable|exists:locations,id',
            'type' => ['required', Rule::in(['booking', 'payment', 'gift_card', 'reminder', 'general', 'attraction'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'action_url' => 'nullable|string',
            'action_text' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['status'] = 'unread';
        $validated['priority'] = $validated['priority'] ?? 'medium';

        $notification = CustomerNotification::create($validated);
        $notification->load(['customer', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Customer notification created successfully',
            'data' => $notification,
        ], 201);
    }

    public function show(CustomerNotification $customerNotification): JsonResponse
    {
        $customerNotification->load(['customer', 'location']);
        return response()->json(['success' => true, 'data' => $customerNotification]);
    }

    public function update(Request $request, CustomerNotification $customerNotification): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['unread', 'read', 'archived'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'read' && $customerNotification->status !== 'read') {
            $validated['read_at'] = now();
        }

        $customerNotification->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Customer notification updated successfully',
            'data' => $customerNotification,
        ]);
    }

    public function destroy(CustomerNotification $customerNotification): JsonResponse
    {
        $notificationId = $customerNotification->id;
        $customerId = $customerNotification->customer_id;
        $title = $customerNotification->title;
        $locationId = $customerNotification->location_id;

        $customerNotification->delete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Customer Notification Deleted',
            category: 'delete',
            description: "Customer notification '{$title}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'customer_notification',
            entityId: $notificationId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'notification_details' => [
                    'notification_id' => $notificationId,
                    'title' => $title,
                    'customer_id' => $customerId,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer notification deleted successfully',
        ]);
    }

    public function markAsRead(CustomerNotification $customerNotification): JsonResponse
    {
        $customerNotification->update([
            'status' => 'read',
            'read_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $customerNotification,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $customerId = $request->get('customer_id');

        if (!$customerId) {
            return response()->json([
                'success' => false,
                'message' => 'customer_id is required',
            ], 422);
        }

        CustomerNotification::where('customer_id', $customerId)
            ->where('status', 'unread')
            ->update([
                'status' => 'read',
                'read_at' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All customer notifications marked as read',
        ]);
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        $customerId = $request->route('customerId') ?? $request->get('customer_id');

        if (!$customerId) {
            return response()->json([
                'success' => false,
                'message' => 'customer_id is required',
            ], 422);
        }

        $count = CustomerNotification::where('customer_id', $customerId)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }
}
