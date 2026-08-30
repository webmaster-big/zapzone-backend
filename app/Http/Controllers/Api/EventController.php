<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Event;
use App\Support\DataUriImage;
use App\Support\LocationSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Event::with(['location:id,name', 'addOns']);

            $this->applyAuthScope($query, $request);

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
                'time_start' => 'nullable|required_with:time_end|date_format:H:i',
                'time_end' => 'nullable|required_with:time_start|date_format:H:i|different:time_start',
                'interval_minutes' => 'nullable|required_with:time_start|integer|min:5',
                'max_bookings_per_slot' => 'nullable|integer|min:1',
                'max_tickets_per_slot' => 'nullable|integer|min:1|max:10000',
                'price' => 'nullable|numeric|min:0',
                'features' => 'nullable|array',
                'features.*' => 'string',
                'add_ons_order' => 'nullable|array',
                'add_ons_order.*' => 'integer',
                'add_on_ids' => 'nullable|array',
                'add_on_ids.*' => 'integer|exists:add_ons,id',
                'is_active' => 'boolean',
            ], [
                'time_end.different' => 'Start and end time cannot be the same. For an event that runs past midnight, enter the next-day end time instead.',
            ]);

            $window = \App\Support\CatalogRules::windowMinutes($validated['time_start'] ?? null, $validated['time_end'] ?? null);
            $interval = (int) ($validated['interval_minutes'] ?? 0);

            if ($window !== null && $interval > $window) {
                $denied = \App\Support\CatalogRules::reject('events', 'interval_minutes', "Interval ({$interval} min) is longer than the event's time window ({$window} min), so no start times could be generated.", ['location_id' => $validated['location_id'], 'user_id' => auth()->id()]);

                if ($denied) {
                    return $denied;
                }
            }

            if ($validated['date_type'] === 'one_time') {
                $validated['end_date'] = null;
            }

            if (isset($validated['image']) && !empty($validated['image'])) {
                try {
                    $validated['image'] = $this->handleImageUpload($validated['image']);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    Log::error('Event image upload failed', ['error' => $e->getMessage()]);

                    return response()->json(['message' => 'Image upload failed: ' . $e->getMessage()], 422);
                }
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

    public function show(Event $event): JsonResponse
    {
        return response()->json($event->load(['location:id,name', 'eventPurchases', 'addOns']));
    }

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
                'time_start' => 'sometimes|nullable|date_format:H:i',
                'time_end' => 'sometimes|nullable|date_format:H:i|different:time_start',
                'interval_minutes' => 'sometimes|nullable|integer|min:5',
                'max_bookings_per_slot' => 'nullable|integer|min:1',
                'max_tickets_per_slot' => 'nullable|integer|min:1|max:10000',
                'price' => 'nullable|numeric|min:0',
                'features' => 'nullable|array',
                'features.*' => 'string',
                'add_ons_order' => 'nullable|array',
                'add_ons_order.*' => 'integer',
                'add_on_ids' => 'nullable|array',
                'add_on_ids.*' => 'integer|exists:add_ons,id',
                'is_active' => 'boolean',
            ], [
                'time_end.different' => 'Start and end time cannot be the same. For an event that runs past midnight, enter the next-day end time instead.',
            ]);

            $timeStart = array_key_exists('time_start', $validated) ? $validated['time_start'] : $event->time_start;
            $timeEnd = array_key_exists('time_end', $validated) ? $validated['time_end'] : $event->time_end;
            $interval = (int) (array_key_exists('interval_minutes', $validated) ? $validated['interval_minutes'] : $event->interval_minutes);
            $window = \App\Support\CatalogRules::windowMinutes($timeStart ? substr((string) $timeStart, 0, 5) : null, $timeEnd ? substr((string) $timeEnd, 0, 5) : null);

            if ($window !== null && $interval > $window) {
                $denied = \App\Support\CatalogRules::reject('events', 'interval_minutes', "Interval ({$interval} min) is longer than the event's time window ({$window} min), so no start times could be generated.", ['event_id' => $event->id, 'user_id' => auth()->id()]);

                if ($denied) {
                    return $denied;
                }
            }

            $dateType = $validated['date_type'] ?? $event->date_type;
            if ($dateType === 'one_time') {
                $validated['end_date'] = null;
            }

            if (isset($validated['image']) && !empty($validated['image'])) {
                try {
                    $newImage = $this->handleImageUpload($validated['image']);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    Log::error('Event image upload failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);

                    return response()->json(['message' => 'Image upload failed: ' . $e->getMessage()], 422);
                }

                if ($event->image && $event->image !== $newImage) {
                    $oldImagePath = storage_path('app/public/' . $event->image);
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                $validated['image'] = $newImage;
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

    public function destroy(Event $event): JsonResponse
    {
        try {
            $event->delete();
            return response()->json(['message' => 'Event deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete event', 'error' => $e->getMessage()], 500);
        }
    }

    public function toggleStatus(Event $event): JsonResponse
    {
        $event->update(['is_active' => !$event->is_active]);
        return response()->json($event);
    }

    public function getAvailableDates(Event $event): JsonResponse
    {
        return response()->json(['dates' => $event->getAvailableDates()]);
    }

    public function getAvailableTimeSlots(Event $event, string $date): JsonResponse
    {
        if (!$event->isDateValid($date)) {
            return response()->json(['message' => 'Date is not valid for this event'], 422);
        }

        $slots = $event->getAvailableTimeSlotsForDate($date);

        $remaining = null;

        if ($event->max_tickets_per_slot !== null) {
            $taken = $event->getBookedTicketsBySlot($date);
            $remaining = [];
            foreach ($slots as $slot) {
                $remaining[$slot] = max(0, $event->max_tickets_per_slot - ($taken[$slot] ?? 0));
            }
        }

        return response()->json(['date' => $date, 'time_slots' => $slots, 'remaining_tickets' => $remaining]);
    }

    public function eventsGroupedByName(Request $request): JsonResponse
    {
        $search = $request->get('search', null);

        $cacheKey = 'events:grouped:' . md5($search ?? '');

        $result = \App\Support\CacheGroups::remember(
            [\App\Support\CacheGroups::EVENTS],
            $cacheKey,
            \App\Support\CacheGroups::TTL_CATALOG,
            function () use ($search) {
                return $this->buildGroupedEvents($search);
            }
        );

        return response()->json([
            'success' => true,
            'data' => $result,
            'total' => count($result),
        ]);
    }

    private function buildGroupedEvents(?string $search): array
    {
        $groupedEvents = [];

        $query = Event::with(['location', 'addOns'])
            ->select(['id', 'name', 'description', 'image', 'date_type', 'start_date', 'end_date',
                'time_start', 'time_end', 'interval_minutes', 'max_bookings_per_slot', 'max_tickets_per_slot',
                'price', 'features', 'location_id', 'is_active'])
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderBy('id')->chunk(100, function ($events) use (&$groupedEvents) {
            foreach ($events as $event) {
                $eventName = $event->name;

                if (!isset($groupedEvents[$eventName])) {
                    $groupedEvents[$eventName] = [
                        'name' => $event->name,
                        'description' => $event->description,
                        'image' => $event->image,
                        'date_type' => $event->date_type,
                        'start_date' => $event->start_date,
                        'end_date' => $event->end_date,
                        'time_start' => $event->time_start,
                        'time_end' => $event->time_end,
                        'interval_minutes' => $event->interval_minutes,
                        'max_bookings_per_slot' => $event->max_bookings_per_slot,
                        'max_tickets_per_slot' => $event->max_tickets_per_slot,
                        'price' => $event->price,
                        'features' => $event->features,
                        'locations' => [],
                        'purchase_links' => [],
                    ];
                }

                if (empty($groupedEvents[$eventName]['image']) && !empty($event->image)) {
                    $groupedEvents[$eventName]['image'] = $event->image;
                }

                $locationSlug = $event->location->slug ?: LocationSlug::make($event->location->name);

                $groupedEvents[$eventName]['locations'][] = [
                    'image' => \App\Support\CatalogImage::forCatalog($event->image),
                    'location_id' => $event->location->id,
                    'location_name' => $event->location->name,
                    'location_slug' => $locationSlug,
                    'event_id' => $event->id,
                    'address' => $event->location->address,
                    'city' => $event->location->city,
                    'state' => $event->location->state,
                    'phone' => $event->location->phone,
                    'time_start' => $event->time_start,
                    'time_end' => $event->time_end,
                    'add_ons' => $event->addOns,
                ];

                $groupedEvents[$eventName]['purchase_links'][] = [
                    'location' => $event->location->name,
                    'url' => "/book/event/{$locationSlug}/{$event->id}",
                    'event_id' => $event->id,
                    'location_id' => $event->location->id,
                ];
            }
        });

        return array_values($groupedEvents);
    }

    public function getByLocation(int $locationId): JsonResponse
    {
        $events = Event::active()
            ->byLocation($locationId)
            ->orderBy('start_date')
            ->get();

        return response()->json($events);
    }

    private function handleImageUpload(string $image): string
    {
        if (DataUriImage::isDataUri($image)) {
            return DataUriImage::store($image, 'images/events');
        }

        if (strlen($image) > (int) config('media.max_path_length', 2048)) {
            throw new \InvalidArgumentException('Image must be a base64 image data URI or a stored file path');
        }

        return $image;
    }
}
