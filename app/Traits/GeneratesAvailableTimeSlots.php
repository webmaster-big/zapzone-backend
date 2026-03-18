<?php

namespace App\Traits;

use App\Models\DayOff;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Shared time-slot availability logic used by both the admin
 * PackageTimeSlotController and the MobileAvailabilityController.
 *
 * The using class MUST define:
 *   private const CLEANUP_BUFFER_MINUTES = 15;
 */
trait GeneratesAvailableTimeSlots
{
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
     * Generate available time slots considering all rooms for the package.
     * Uses the availability schedules system.
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

        $totalRooms = $package->rooms->count();

        // Iterate through each time slot from the schedule
        foreach ($timeSlots as $timeSlot) {
            $currentTime = Carbon::parse($date . ' ' . $timeSlot);
            $slotEndTime = (clone $currentTime)->addMinutes($slotDurationInMinutes);

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
                    'total_rooms' => $totalRooms,
                ];
            }
        }

        return $availableSlots;
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
}
