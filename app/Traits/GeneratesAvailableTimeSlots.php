<?php

namespace App\Traits;

use App\Models\DayOff;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

trait GeneratesAvailableTimeSlots
{
    /** availableRoomIds runs per slot; without this it would re-query the same rows per slot. */
    private array $slotLookupCache = [];

    private function cachedPackageWithRooms($packageId): ?Package
    {
        $key = 'package:' . $packageId;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = Package::with('rooms')->find($packageId);
        }

        return $this->slotLookupCache[$key];
    }

    private function cachedRoom($roomId): ?Room
    {
        $key = 'room:' . $roomId;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = Room::find($roomId);
        }

        return $this->slotLookupCache[$key];
    }

    /** Every booked slot for a date, grouped by room — one query per generation pass. */
    private function bookedSlotsForRoom($roomId, $date)
    {
        $key = 'booked:' . $date;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = PackageTimeSlot::whereDate('booked_date', $date)
                ->where('status', 'booked')
                ->get(['id', 'room_id', 'time_slot_start', 'duration', 'duration_unit'])
                ->groupBy('room_id');
        }

        return $this->slotLookupCache[$key]->get($roomId, collect());
    }

    /**
     * The staggering time for an AREA. area_group and booking_interval both live on the room, and
     * a group's stagger is kept by writing the same value to every room in it — but nothing
     * enforces that, so take the strictest. Reading one room's value instead would make the same
     * pair of bookings legal or illegal depending only on which room you booked into.
     */
    private function areaStaggerMinutes($room): int
    {
        if (! $room->area_group) {
            return $this->turnaroundMinutes($room->id);
        }

        $key = 'areastagger:' . $room->location_id . ':' . $room->area_group;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = (int) Room::where('location_id', $room->location_id)
                ->where('area_group', $room->area_group)
                ->max('booking_interval');
        }

        return max(0, $this->slotLookupCache[$key]);
    }

    private function roomIdsSharingStagger($room)
    {
        if (! $room->area_group) {
            return [(int) $room->id];
        }

        $key = 'stagger:' . $room->location_id . ':' . $room->area_group;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = Room::where('location_id', $room->location_id)
                ->where('area_group', $room->area_group)
                ->pluck('id')
                ->all();
        }

        return $this->slotLookupCache[$key];
    }

    /** Every day-off for a location and date — one query instead of one per room per slot. */
    private function dayOffsFor($locationId, $date)
    {
        $key = 'dayoffs:' . $locationId . ':' . $date;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $this->slotLookupCache[$key] = DayOff::where('location_id', $locationId)->forDate($date)->get();
        }

        return $this->slotLookupCache[$key];
    }

    private function roomBlockedByDayOff($locationId, $roomId, $date, string $slotStart, string $slotEnd): bool
    {
        foreach ($this->dayOffsFor($locationId, $date) as $dayOff) {
            if ($dayOff->appliesToRoom((int) $roomId) && $dayOff->isTimeBlocked($slotStart, $slotEnd)) {
                return true;
            }
        }

        return false;
    }

    private function packageBlockedByDayOff($locationId, $packageId, $date, string $slotStart, string $slotEnd): bool
    {
        foreach ($this->dayOffsFor($locationId, $date) as $dayOff) {
            if ($dayOff->appliesToPackage((int) $packageId) && $dayOff->isTimeBlocked($slotStart, $slotEnd)) {
                return true;
            }
        }

        return false;
    }

    private function forgetSlotLookups(): void
    {
        $this->slotLookupCache = [];
    }

    private function getDurationInMinutes($duration, $durationUnit): int
    {
        $duration = (float) $duration;

        if ($durationUnit === 'hours' || $durationUnit === 'hours and minutes') {
            return (int) round($duration * 60);
        }

        return (int) round($duration);
    }

    /**
     * "Now" where the venue actually is. Some locations store a Windows-style zone name such as
     * "Eastern Standard Time", which is not a valid identifier, so fall back rather than throw.
     */
    private function locationNow($locationId): Carbon
    {
        $key = 'now:' . $locationId;

        if (! array_key_exists($key, $this->slotLookupCache)) {
            $zone = \App\Models\Location::find($locationId)?->timezone;

            try {
                $this->slotLookupCache[$key] = Carbon::now($zone ?: config('app.timezone'));
            } catch (\Throwable $e) {
                $this->slotLookupCache[$key] = Carbon::now(config('app.timezone'));
            }
        }

        return $this->slotLookupCache[$key]->copy();
    }

    private function cleanupBufferMinutes(): int
    {
        return max(0, (int) config('booking_rules.room_cleanup_minutes', 15));
    }

    private function generateAvailableSlotsWithRooms($package, $date)
    {
        $availableSlots = [];

        // the caller owns the cache lifetime: a request generating several packages for one date
        // shares these lookups, while the long-lived SSE loop clears them on every tick
        if ($package->relationLoaded('rooms')) {
            $this->slotLookupCache['package:' . $package->id] ??= $package;
        }

        $locationId = $package->location_id;

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

        $slotDurationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);

        $totalRooms = $package->rooms->count();

        $package->forgetResolvedSchedules();

        $ticketCap = $package->effectiveTicketCap();
        $bookedWindows = $ticketCap !== null ? $package->bookedWindowsForDate($date) : [];
        $minForDate = $package->effectiveMinParticipants($date);
        $exclusive = $package->isExclusiveOn($date);

        $now = $this->locationNow($locationId);
        $isToday = $now->toDateString() === Carbon::parse($date)->toDateString();

        foreach ($timeSlots as $timeSlot) {
            $currentTime = Carbon::parse($date . ' ' . $timeSlot);
            $slotEndTime = (clone $currentTime)->addMinutes($slotDurationInMinutes);

            // a start that has already gone by is not bookable
            if ($isToday && $currentTime->format('H:i') < $now->format('H:i')) {
                continue;
            }

            $isPackageBlocked = $this->packageBlockedByDayOff(
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

            $remainingTickets = null;

            if ($ticketCap !== null) {
                $remainingTickets = $package->remainingTicketsForSlotGivenWindows($bookedWindows, $date, $currentTime->format('H:i'), null, $exclusive);

                if ($remainingTickets < $minForDate) {
                    continue;
                }
            }

            $freeRoomIds = $this->availableRoomIds(
                $package->id,
                $date,
                $currentTime->format('H:i'),
                $duration,
                $durationUnit
            );

            $availableRoom = $freeRoomIds === []
                ? null
                : $package->rooms->firstWhere('id', $freeRoomIds[0]);

            if ($availableRoom) {
                $availableSlots[] = [
                    'start_time' => $currentTime->format('H:i'),
                    'end_time' => $slotEndTime->format('H:i'),
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'room_id' => $availableRoom->id,
                    'room_name' => $availableRoom->name,
                    'available_rooms_count' => count($freeRoomIds),
                    'available_room_ids' => $freeRoomIds,
                    'total_rooms' => $totalRooms,
                    'remaining_tickets' => $remainingTickets,
                    'min_participants' => $minForDate,
                    'exclusive' => $exclusive,
                ];
            } elseif ($totalRooms === 0) {
                $availableSlots[] = [
                    'start_time' => $currentTime->format('H:i'),
                    'end_time' => $slotEndTime->format('H:i'),
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'room_id' => null,
                    'room_name' => null,
                    'available_rooms_count' => 0,
                    'available_room_ids' => [],
                    'total_rooms' => 0,
                    'remaining_tickets' => $remainingTickets,
                    'min_participants' => $minForDate,
                    'exclusive' => $exclusive,
                ];
            }
        }

        return $availableSlots;
    }

    /** Every space that could take this slot, so the caller can honour the one a user picked. */
    private function availableRoomIds($packageId, $date, $startTime, $duration, $durationUnit): array
    {
        $package = $this->cachedPackageWithRooms($packageId);

        if (!$package || $package->rooms->isEmpty()) {
            return [];
        }

        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $slotStart = Carbon::parse($date . ' ' . $startTime);
        $slotEnd = (clone $slotStart)->addMinutes($durationInMinutes);

        $ids = [];
        foreach ($package->rooms as $room) {
            if (!$room->is_available) {
                continue;
            }

            $isRoomBlocked = $this->roomBlockedByDayOff(
                $package->location_id,
                $room->id,
                $date,
                $startTime,
                $slotEnd->format('H:i')
            );

            if ($isRoomBlocked) {
                continue; // Skip this room
            }

            $hasBookingConflict = $this->checkTimeSlotConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            $hasBreakTimeConflict = $this->checkBreakTimeConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            $hasStaggerConflict = $this->checkAreaGroupStaggerConflict(
                $room->id,
                $date,
                $startTime
            );

            if (!$hasBookingConflict && !$hasBreakTimeConflict && !$hasStaggerConflict) {
                $ids[] = (int) $room->id;
            }
        }

        return $ids;
    }

    /**
     * How long this space stays shut after a booking ends. The Spaces form calls it
     * "minutes between bookings"; it only ever applies once a booking exists, never to the
     * list of start times on offer.
     */
    private function turnaroundMinutes($roomId): int
    {
        $room = $this->cachedRoom($roomId);

        // the column is NOT NULL with a default, so a zero is a deliberate "no gap"
        return $room === null ? $this->cleanupBufferMinutes() : max(0, (int) $room->booking_interval);
    }

    private function checkTimeSlotConflict($roomId, $date, $startTime, $duration, $durationUnit, $excludeId = null)
    {
        $start = Carbon::parse($date . ' ' . $startTime);
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $end = (clone $start)->addMinutes($durationInMinutes);

        $existingSlots = $this->bookedSlotsForRoom($roomId, $date)
            ->when($excludeId, fn ($rows) => $rows->where('id', '!=', $excludeId));

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);
            $existingDurationInMinutes = $this->getDurationInMinutes($slot->duration, $slot->duration_unit);
            $existingEnd = (clone $existingStart)->addMinutes($existingDurationInMinutes);

            $turnaround = $this->turnaroundMinutes($roomId);
            $existingEndWithBuffer = (clone $existingEnd)->addMinutes($turnaround);
            $endWithBuffer = (clone $end)->addMinutes($turnaround);

            if ($start->lt($existingEndWithBuffer) && $endWithBuffer->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    private function checkBreakTimeConflict($roomId, $date, $startTime, $duration, $durationUnit)
    {
        $room = $this->cachedRoom($roomId);

        if (!$room || !$room->break_time || empty($room->break_time)) {
            return false;
        }

        $bookingDate = Carbon::parse($date);
        $dayOfWeek = strtolower($bookingDate->format('l')); // 'monday', 'tuesday', etc.

        $bookingStart = Carbon::parse($date . ' ' . $startTime);
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $bookingEnd = (clone $bookingStart)->addMinutes($durationInMinutes);

        foreach ($room->break_time as $breakPeriod) {
            if (!isset($breakPeriod['days']) || !is_array($breakPeriod['days'])) {
                continue;
            }

            $breakDays = array_map('strtolower', $breakPeriod['days']);

            if (!in_array($dayOfWeek, $breakDays)) {
                continue;
            }

            $breakStart = Carbon::parse($date . ' ' . $breakPeriod['start_time']);
            $breakEnd = Carbon::parse($date . ' ' . $breakPeriod['end_time']);

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

    private function checkAreaGroupStaggerConflict($roomId, $date, $startTime, $excludeId = null)
    {
        $room = $this->cachedRoom($roomId);

        if (!$room) {
            return false;
        }

        $bookingInterval = $this->areaStaggerMinutes($room);

        if ($bookingInterval <= 0) {
            return false; // No stagger interval configured
        }

        $bookingStart = Carbon::parse($date . ' ' . $startTime);

        $roomIdsToCheck = $this->roomIdsSharingStagger($room);

        $existingSlots = collect($roomIdsToCheck)
            ->flatMap(fn ($id) => $this->bookedSlotsForRoom($id, $date))
            ->when($excludeId, fn ($rows) => $rows->where('id', '!=', $excludeId));

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);

            if ($bookingStart->eq($existingStart)) {
                return true;
            }

            $intervalEnd = (clone $existingStart)->addMinutes($bookingInterval);

            if ($bookingStart->gt($existingStart) && $bookingStart->lt($intervalEnd)) {
                return true;
            }

            $newIntervalEnd = (clone $bookingStart)->addMinutes($bookingInterval);

            if ($existingStart->gt($bookingStart) && $existingStart->lt($newIntervalEnd)) {
                return true;
            }
        }

        return false;
    }
}
