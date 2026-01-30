<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DayOff;
use App\Models\PackageTimeSlot;
use App\Models\Package;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PackageTimeSlotController extends Controller
{
    /**
     * Cleanup/buffer time in minutes between bookings.
     * This allows time for cleaning the room after a booking ends.
     */
    private const CLEANUP_BUFFER_MINUTES = 15;

    /**
     * Convert duration to minutes based on unit type.
     * Handles decimal values (e.g., 1.75 hours = 105 minutes)
     */
    private function getDurationInMinutes($duration, $durationUnit): int
    {
        $duration = (float) $duration;

        if ($durationUnit === 'hours' || $durationUnit === 'hours and minutes') {
            return (int) round($duration * 60);
        }

        return (int) round($duration);
    }

    /**
     * Display a listing of time slots with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PackageTimeSlot::with(['package', 'room', 'booking', 'customer', 'user']);

        // Filter by package
        if ($request->has('package_id')) {
            $query->byPackage($request->package_id);
        }

        // Filter by room
        if ($request->has('room_id')) {
            $query->byRoom($request->room_id);
        }

        // Filter by date
        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->byCustomer($request->customer_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'booked_date');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $timeSlots = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'time_slots' => $timeSlots->items(),
                'pagination' => [
                    'current_page' => $timeSlots->currentPage(),
                    'last_page' => $timeSlots->lastPage(),
                    'per_page' => $timeSlots->perPage(),
                    'total' => $timeSlots->total(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created time slot.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'room_id' => 'required|exists:rooms,id',
            'booking_id' => 'required|exists:bookings,id',
            'customer_id' => 'required|exists:customers,id',
            'user_id' => 'nullable|exists:users,id',
            'booked_date' => 'required|date',
            'time_slot_start' => 'required|date_format:H:i',
            'duration' => 'required|numeric|min:0.25',
            'duration_unit' => 'required|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // Check minimum booking notice requirement
        $minNoticeCheck = $this->checkMinBookingNotice(
            $validated['package_id'],
            $validated['booked_date'],
            $validated['time_slot_start']
        );

        if (!$minNoticeCheck['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $minNoticeCheck['message'],
                'data' => [
                    'min_booking_notice_hours' => $minNoticeCheck['min_hours'],
                    'hours_until_slot' => $minNoticeCheck['hours_until_slot'],
                ],
            ], 422);
        }

        // Check for booking conflicts
        $conflict = $this->checkTimeSlotConflict(
            $validated['room_id'],
            $validated['booked_date'],
            $validated['time_slot_start'],
            $validated['duration'],
            $validated['duration_unit']
        );

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked for the selected room and date.',
            ], 422);
        }

        // Check for area group stagger conflicts
        $staggerConflict = $this->checkAreaGroupStaggerConflict(
            $validated['room_id'],
            $validated['booked_date'],
            $validated['time_slot_start']
        );

        if ($staggerConflict) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot conflicts with another booking in the same area. Please choose a different time.',
            ], 422);
        }

        $timeSlot = PackageTimeSlot::create($validated);
        $timeSlot->load(['package', 'room', 'booking', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Time slot booked successfully',
            'data' => $timeSlot,
        ], 201);
    }

    /**
     * Display the specified time slot.
     */
    public function show(PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $packageTimeSlot->load(['package', 'room', 'booking', 'customer', 'user']);

        return response()->json([
            'success' => true,
            'data' => $packageTimeSlot,
        ]);
    }

    /**
     * Update the specified time slot.
     */
    public function update(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $validated = $request->validate([
            'booked_date' => 'sometimes|date',
            'time_slot_start' => 'sometimes|date_format:H:i',
            'duration' => 'sometimes|numeric|min:0.25',
            'duration_unit' => 'sometimes|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // If time/date is being updated, check for conflicts
        if (isset($validated['booked_date']) || isset($validated['time_slot_start']) ||
            isset($validated['duration']) || isset($validated['duration_unit'])) {

            $newDate = $validated['booked_date'] ?? $packageTimeSlot->booked_date;
            $newStartTime = $validated['time_slot_start'] ?? $packageTimeSlot->time_slot_start;

            $conflict = $this->checkTimeSlotConflict(
                $packageTimeSlot->room_id,
                $newDate,
                $newStartTime,
                $validated['duration'] ?? $packageTimeSlot->duration,
                $validated['duration_unit'] ?? $packageTimeSlot->duration_unit,
                $packageTimeSlot->id // Exclude current record
            );

            if ($conflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot is already booked for the selected room and date.',
                ], 422);
            }

            // Check for area group stagger conflicts
            $staggerConflict = $this->checkAreaGroupStaggerConflict(
                $packageTimeSlot->room_id,
                $newDate,
                $newStartTime,
                $packageTimeSlot->id // Exclude current record
            );

            if ($staggerConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot conflicts with another booking in the same area. Please choose a different time.',
                ], 422);
            }
        }

        $packageTimeSlot->update($validated);
        $packageTimeSlot->load(['package', 'room', 'booking', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Time slot updated successfully',
            'data' => $packageTimeSlot,
        ]);
    }

    /**
     * Remove the specified time slot.
     */
    public function destroy(PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $timeSlotId = $packageTimeSlot->id;
        $packageId = $packageTimeSlot->package_id;
        $roomId = $packageTimeSlot->room_id;

        $packageTimeSlot->delete();

        // Log time slot deletion
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Package Time Slot Deleted',
            category: 'delete',
            description: "Package time slot was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'package_time_slot',
            entityId: $timeSlotId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'time_slot_details' => [
                    'time_slot_id' => $timeSlotId,
                    'package_id' => $packageId,
                    'room_id' => $roomId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Time slot deleted successfully',
        ]);
    }

    /**
     * Get available time slots for a package and date (auto-finds available rooms) - SSE.
     */
    public function getAvailableSlotsAuto(int $packageId, string $date)
    {
        return response()->stream(function () use ($packageId, $date) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            try {
                $package = Package::with('rooms')->findOrFail($packageId);

                if ($package->rooms->isEmpty()) {
                    echo "event: error\n";
                    echo "data: " . json_encode([
                        'error' => 'No rooms available',
                        'message' => 'No rooms available for this package'
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                    return;
                }

                $lastHash = '';

                // Keep sending updates every 3 seconds
                while (true) {
                    $availableSlots = $this->generateAvailableSlotsWithRooms(
                        $package,
                        $date
                    );

                    $data = [
                        'available_slots' => $availableSlots,
                        'package' => [
                            'id' => $package->id,
                            'name' => $package->name,
                            'duration' => $package->duration,
                            'duration_unit' => $package->duration_unit,
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ];

                    // Check if data has changed
                    $currentHash = md5(json_encode($data));

                    if ($currentHash !== $lastHash) {
                        // Send data only if changed
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();
                        $lastHash = $currentHash;
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        break;
                    }

                    // Wait 3 seconds before next update
                    sleep(3);
                }
            } catch (\Exception $e) {
                Log::error('Error in SSE available slots stream', [
                    'package_id' => $packageId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);

                // Send error to client
                echo "event: error\n";
                echo "data: " . json_encode([
                    'error' => $e->getMessage(),
                    'message' => 'Failed to load available time slots'
                ]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Check if a time slot conflicts with room break times.
     */
    private function checkBreakTimeConflict($roomId, $date, $startTime, $duration, $durationUnit)
    {
        $room = Room::find($roomId);

        if (!$room || !$room->break_time || empty($room->break_time)) {
            return false;
        }

        // Get the day of the week for the booking date
        $bookingDate = Carbon::parse($date);
        $dayOfWeek = strtolower($bookingDate->format('l')); // 'monday', 'tuesday', etc.

        // Calculate booking time range using minutes for precision with decimal durations
        $bookingStart = Carbon::parse($date . ' ' . $startTime);
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $bookingEnd = (clone $bookingStart)->addMinutes($durationInMinutes);

        // Check each break time period
        foreach ($room->break_time as $breakPeriod) {
            // Check if this break period applies to the booking day
            if (!isset($breakPeriod['days']) || !is_array($breakPeriod['days'])) {
                continue;
            }

            $breakDays = array_map('strtolower', $breakPeriod['days']);

            if (!in_array($dayOfWeek, $breakDays)) {
                continue;
            }

            // Parse break time range
            $breakStart = Carbon::parse($date . ' ' . $breakPeriod['start_time']);
            $breakEnd = Carbon::parse($date . ' ' . $breakPeriod['end_time']);

            // Check for overlap
            // Booking conflicts if it starts before break ends AND ends after break starts
            if ($bookingStart->lt($breakEnd) && $bookingEnd->gt($breakStart)) {
                Log::info('Break time conflict detected', [
                    'room_id' => $roomId,
                    'date' => $date,
                    'day' => $dayOfWeek,
                    'booking_start' => $bookingStart->format('H:i'),
                    'booking_end' => $bookingEnd->format('H:i'),
                    'break_start' => $breakStart->format('H:i'),
                    'break_end' => $breakEnd->format('H:i'),
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a time slot conflicts with area group stagger interval.
     * Rooms in the same area_group (or same location if no area_group) cannot have bookings starting within the booking_interval of each other.
     * This creates a staggered booking system across rooms in the same area.
     */
    private function checkAreaGroupStaggerConflict($roomId, $date, $startTime, $excludeId = null)
    {
        $room = Room::find($roomId);

        if (!$room) {
            return false;
        }

        $bookingInterval = $room->booking_interval ?? 15; // Default 15 minutes

        if ($bookingInterval <= 0) {
            return false; // No stagger interval configured
        }

        $bookingStart = Carbon::parse($date . ' ' . $startTime);

        // Get room IDs to check:
        // - If room has area_group: check rooms in same area_group + rooms with NO area_group (global blockers)
        // - If room has NO area_group: check ALL rooms in the same location (global stagger)
        if ($room->area_group) {
            // Get all rooms in the same area_group AND rooms with no area_group (global blockers)
            $roomIdsToCheck = Room::where('location_id', $room->location_id)
                ->where(function ($query) use ($room) {
                    $query->where('area_group', $room->area_group)
                          ->orWhereNull('area_group');
                })
                ->pluck('id')
                ->toArray();
        } else {
            // No area_group set - apply global stagger across ALL rooms in the same location
            $roomIdsToCheck = Room::where('location_id', $room->location_id)
                ->pluck('id')
                ->toArray();
        }

        // Check for existing bookings in ANY of these rooms that start at the same time or within interval
        $query = PackageTimeSlot::whereIn('room_id', $roomIdsToCheck)
            ->whereDate('booked_date', $date)
            ->where('status', 'booked');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlots = $query->get();

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);

            // Check if booking starts at EXACTLY the same time (primary conflict)
            if ($bookingStart->eq($existingStart)) {
                Log::info('Area group stagger conflict - same start time', [
                    'room_id' => $roomId,
                    'area_group' => $room->area_group,
                    'date' => $date,
                    'new_booking_start' => $bookingStart->format('H:i'),
                    'existing_booking_start' => $existingStart->format('H:i'),
                    'existing_room_id' => $slot->room_id,
                ]);
                return true;
            }

            // Check if new booking is within the interval window of existing booking
            $intervalEnd = (clone $existingStart)->addMinutes($bookingInterval);

            if ($bookingStart->gt($existingStart) && $bookingStart->lt($intervalEnd)) {
                Log::info('Area group stagger conflict - within interval after existing', [
                    'room_id' => $roomId,
                    'area_group' => $room->area_group,
                    'date' => $date,
                    'new_booking_start' => $bookingStart->format('H:i'),
                    'existing_booking_start' => $existingStart->format('H:i'),
                    'existing_room_id' => $slot->room_id,
                    'booking_interval' => $bookingInterval,
                ]);
                return true;
            }

            // Check if existing booking is within the interval window of new booking
            $newIntervalEnd = (clone $bookingStart)->addMinutes($bookingInterval);

            if ($existingStart->gt($bookingStart) && $existingStart->lt($newIntervalEnd)) {
                Log::info('Area group stagger conflict - existing within interval of new', [
                    'room_id' => $roomId,
                    'area_group' => $room->area_group,
                    'date' => $date,
                    'new_booking_start' => $bookingStart->format('H:i'),
                    'existing_booking_start' => $existingStart->format('H:i'),
                    'existing_room_id' => $slot->room_id,
                    'booking_interval' => $bookingInterval,
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a time slot conflicts with existing bookings.
     * Includes cleanup buffer time after each booking.
     */
    private function checkTimeSlotConflict($roomId, $date, $startTime, $duration, $durationUnit, $excludeId = null)
    {
        $start = Carbon::parse($date . ' ' . $startTime);
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $end = (clone $start)->addMinutes($durationInMinutes);

        $query = PackageTimeSlot::where('room_id', $roomId)
            ->whereDate('booked_date', $date)
            ->where('status', 'booked');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlots = $query->get();

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);
            $existingDurationInMinutes = $this->getDurationInMinutes($slot->duration, $slot->duration_unit);
            $existingEnd = (clone $existingStart)->addMinutes($existingDurationInMinutes);

            // Add cleanup buffer time after the booking ends
            $existingEndWithBuffer = (clone $existingEnd)->addMinutes(self::CLEANUP_BUFFER_MINUTES);

            // Check for overlap (considering the buffer time)
            // New booking cannot start until after the buffer period
            if ($start->lt($existingEndWithBuffer) && $end->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the booking meets the minimum notice requirement for the package.
     * Returns an array with 'allowed' boolean and details.
     */
    private function checkMinBookingNotice($packageId, $date, $startTime): array
    {
        $package = Package::find($packageId);

        if (!$package) {
            return [
                'allowed' => false,
                'message' => 'Package not found',
                'min_hours' => null,
                'hours_until_slot' => null,
            ];
        }

        // If no minimum notice is set, allow the booking
        if ($package->min_booking_notice_hours === null || $package->min_booking_notice_hours === 0) {
            return [
                'allowed' => true,
                'message' => 'No minimum booking notice required',
                'min_hours' => 0,
                'hours_until_slot' => null,
            ];
        }

        // Calculate time until the booking slot
        $slotDateTime = Carbon::parse($date . ' ' . $startTime);
        $now = Carbon::now();
        $hoursUntilSlot = $now->diffInHours($slotDateTime, false); // false = can be negative if in past

        // If the slot is in the past, don't allow
        if ($hoursUntilSlot < 0) {
            return [
                'allowed' => false,
                'message' => 'Cannot book a time slot in the past',
                'min_hours' => $package->min_booking_notice_hours,
                'hours_until_slot' => $hoursUntilSlot,
            ];
        }

        // Check if there's enough notice
        if ($hoursUntilSlot < $package->min_booking_notice_hours) {
            $minHours = $package->min_booking_notice_hours;
            $formattedNotice = $minHours >= 24 
                ? round($minHours / 24, 1) . ' day(s)' 
                : $minHours . ' hour(s)';

            return [
                'allowed' => false,
                'message' => "This package requires at least {$formattedNotice} advance notice for bookings. The selected time slot is only {$hoursUntilSlot} hour(s) away.",
                'min_hours' => $minHours,
                'hours_until_slot' => $hoursUntilSlot,
            ];
        }

        return [
            'allowed' => true,
            'message' => 'Booking notice requirement met',
            'min_hours' => $package->min_booking_notice_hours,
            'hours_until_slot' => $hoursUntilSlot,
        ];
    }

    /**
     * Find an available room for a specific date and time.
     */
    private function findAvailableRoom($packageId, $date, $startTime, $duration, $durationUnit)
    {
        $package = Package::with('rooms')->find($packageId);

        if (!$package || $package->rooms->isEmpty()) {
            return null;
        }

        // Calculate slot end time for day off check
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $slotStart = Carbon::parse($date . ' ' . $startTime);
        $slotEnd = (clone $slotStart)->addMinutes($durationInMinutes);

        // Check each room for availability
        foreach ($package->rooms as $room) {
            // Check for room-specific day off blocks
            $isRoomBlocked = DayOff::isTimeSlotBlockedForRoom(
                $package->location_id,
                $room->id,
                $date,
                $startTime,
                $slotEnd->format('H:i')
            );

            if ($isRoomBlocked) {
                continue; // Skip this room
            }

            // Check for booking conflicts
            $hasBookingConflict = $this->checkTimeSlotConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            // Check for break time conflicts
            $hasBreakTimeConflict = $this->checkBreakTimeConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            // Check for area group stagger conflicts
            $hasStaggerConflict = $this->checkAreaGroupStaggerConflict(
                $room->id,
                $date,
                $startTime
            );

            if (!$hasBookingConflict && !$hasBreakTimeConflict && !$hasStaggerConflict) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Generate available time slots considering all rooms for the package.
     * Uses the new availability schedules system.
     * Automatically excludes slots within the cleanup buffer period after existing bookings.
     * Also checks for day off (partial or full day) conflicts.
     */
    private function generateAvailableSlotsWithRooms($package, $date)
    {
        $availableSlots = [];

        // Get the package's location_id for day off checking
        $locationId = $package->location_id;

        // Get time slots from availability schedules
        $timeSlots = $package->getTimeSlotsForDate($date);

        if (empty($timeSlots)) {
            Log::info('No time slots found for package', [
                'package_id' => $package->id,
                'date' => $date,
            ]);
            return [];
        }

        $duration = $package->duration;
        $durationUnit = $package->duration_unit;

        // Calculate actual duration in minutes using helper method
        $slotDurationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);

        // Iterate through each time slot from the schedule
        foreach ($timeSlots as $timeSlot) {
            $currentTime = Carbon::parse($date . ' ' . $timeSlot);
            $slotEndTime = (clone $currentTime)->addMinutes($slotDurationInMinutes);

            // Check minimum booking notice requirement
            if ($package->min_booking_notice_hours !== null && $package->min_booking_notice_hours > 0) {
                $hoursUntilSlot = Carbon::now()->diffInHours($currentTime, false);
                if ($hoursUntilSlot < $package->min_booking_notice_hours) {
                    Log::debug('Time slot filtered by minimum booking notice', [
                        'package_id' => $package->id,
                        'date' => $date,
                        'time_slot' => $currentTime->format('H:i'),
                        'min_booking_notice_hours' => $package->min_booking_notice_hours,
                        'hours_until_slot' => $hoursUntilSlot,
                    ]);
                    continue; // Skip this slot - doesn't meet minimum notice requirement
                }
            }

            // Check if this time slot is blocked by a day off for this specific package
            $isPackageBlocked = DayOff::isTimeSlotBlockedForPackage(
                $locationId,
                $package->id,
                $date,
                $currentTime->format('H:i'),
                $slotEndTime->format('H:i')
            );

            if ($isPackageBlocked) {
                Log::debug('Time slot blocked by day off for package', [
                    'package_id' => $package->id,
                    'date' => $date,
                    'time_slot' => $currentTime->format('H:i'),
                    'location_id' => $locationId,
                ]);
                continue; // Skip this slot
            }

            // Check if ANY room is available for this slot
            $availableRoom = $this->findAvailableRoom(
                $package->id,
                $date,
                $currentTime->format('H:i'),
                $duration,
                $durationUnit
            );

            if ($availableRoom) {
                $availableSlots[] = [
                    'start_time' => $currentTime->format('H:i'),
                    'end_time' => $slotEndTime->format('H:i'),
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'room_id' => $availableRoom->id,
                    'room_name' => $availableRoom->name,
                    'available_rooms_count' => $this->countAvailableRooms(
                        $package->id,
                        $date,
                        $currentTime->format('H:i'),
                        $duration,
                        $durationUnit
                    ),
                ];
            }
        }

        return $availableSlots;
    }

    /**
     * Count how many rooms are available for a specific time slot.
     */
    private function countAvailableRooms($packageId, $date, $startTime, $duration, $durationUnit)
    {
        $package = Package::with('rooms')->find($packageId);

        if (!$package || $package->rooms->isEmpty()) {
            return 0;
        }

        // Calculate slot end time for day off check
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $slotStart = Carbon::parse($date . ' ' . $startTime);
        $slotEnd = (clone $slotStart)->addMinutes($durationInMinutes);

        $count = 0;
        foreach ($package->rooms as $room) {
            // Check for room-specific day off blocks
            $isRoomBlocked = DayOff::isTimeSlotBlockedForRoom(
                $package->location_id,
                $room->id,
                $date,
                $startTime,
                $slotEnd->format('H:i')
            );

            if ($isRoomBlocked) {
                continue; // Skip this room
            }

            // Check for booking conflicts
            $hasBookingConflict = $this->checkTimeSlotConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            // Check for break time conflicts
            $hasBreakTimeConflict = $this->checkBreakTimeConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            // Check for area group stagger conflicts
            $hasStaggerConflict = $this->checkAreaGroupStaggerConflict(
                $room->id,
                $date,
                $startTime
            );

            if (!$hasBookingConflict && !$hasBreakTimeConflict && !$hasStaggerConflict) {
                $count++;
            }
        }

        return $count;
    }

}
