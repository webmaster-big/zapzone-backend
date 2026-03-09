<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * List all events.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Event::with(['location:id,name', 'addOns']);

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            $events = $query->orderBy('start_date', 'desc')->get();

            return response()->json($events);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch events', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a new event.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'location_id' => 'required|exists:locations,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'date_type' => 'required|in:one_time,date_range',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after_or_equal:start_date|required_if:date_type,date_range',
                'time_start' => 'required|date_format:H:i',
                'time_end' => 'required|date_format:H:i|after:time_start',
                'interval_minutes' => 'required|integer|min:5',
                'max_bookings_per_slot' => 'nullable|integer|min:1',
                'price' => 'nullable|numeric|min:0',
                'features' => 'nullable|array',
                'features.*' => 'string',
                'add_ons_order' => 'nullable|array',
                'add_ons_order.*' => 'integer',
                'add_on_ids' => 'nullable|array',
                'add_on_ids.*' => 'integer|exists:add_ons,id',
                'is_active' => 'boolean',
            ]);

            if ($validated['date_type'] === 'one_time') {
                $validated['end_date'] = null;
            }

            $addOnIds = $validated['add_on_ids'] ?? [];
            unset($validated['add_on_ids']);

            $event = Event::create($validated);

            if (!empty($addOnIds)) {
                $event->addOns()->sync($addOnIds);
            }

            return response()->json($event->load(['location:id,name', 'addOns']), 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create event', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single event.
     */
    public function show(Event $event): JsonResponse
    {
        return response()->json($event->load(['location:id,name', 'eventPurchases', 'addOns']));
    }

    /**
     * Update an event.
     */
    public function update(Request $request, Event $event): JsonResponse
    {
        try {
            $validated = $request->validate([
                'location_id' => 'sometimes|exists:locations,id',
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'date_type' => 'sometimes|in:one_time,date_range',
                'start_date' => 'sometimes|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'time_start' => 'sometimes|date_format:H:i',
                'time_end' => 'sometimes|date_format:H:i',
                'interval_minutes' => 'sometimes|integer|min:5',
                'max_bookings_per_slot' => 'nullable|integer|min:1',
                'price' => 'nullable|numeric|min:0',
                'features' => 'nullable|array',
                'features.*' => 'string',
                'add_ons_order' => 'nullable|array',
                'add_ons_order.*' => 'integer',
                'add_on_ids' => 'nullable|array',
                'add_on_ids.*' => 'integer|exists:add_ons,id',
                'is_active' => 'boolean',
            ]);

            $dateType = $validated['date_type'] ?? $event->date_type;
            if ($dateType === 'one_time') {
                $validated['end_date'] = null;
            }

            $addOnIds = $validated['add_on_ids'] ?? null;
            unset($validated['add_on_ids']);

            $event->update($validated);

            if ($addOnIds !== null) {
                $event->addOns()->sync($addOnIds);
            }

            return response()->json($event->fresh()->load(['location:id,name', 'addOns']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update event', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an event (soft delete).
     */
    public function destroy(Event $event): JsonResponse
    {
        try {
            $event->delete();
            return response()->json(['message' => 'Event deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete event', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle event active status.
     */
    public function toggleStatus(Event $event): JsonResponse
    {
        $event->update(['is_active' => !$event->is_active]);
        return response()->json($event);
    }

    /**
     * Get available dates for an event.
     */
    public function getAvailableDates(Event $event): JsonResponse
    {
        return response()->json(['dates' => $event->getAvailableDates()]);
    }

    /**
     * Get available time slots for a specific event date.
     */
    public function getAvailableTimeSlots(Event $event, string $date): JsonResponse
    {
        if (!$event->isDateValid($date)) {
            return response()->json(['message' => 'Date is not valid for this event'], 422);
        }

        $slots = $event->getAvailableTimeSlotsForDate($date);

        return response()->json(['date' => $date, 'time_slots' => $slots]);
    }

    /**
     * Get events by location (public).
     */
    public function getByLocation(int $locationId): JsonResponse
    {
        $events = Event::active()
            ->byLocation($locationId)
            ->orderBy('start_date')
            ->get();

        return response()->json($events);
    }
}
