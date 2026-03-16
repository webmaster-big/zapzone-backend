<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DayOff;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\SpecialPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MobileAvailabilityController extends Controller
{
    /**
     * Cleanup/buffer time in minutes between bookings.
     */
    private const CLEANUP_BUFFER_MINUTES = 15;

    /**
     * GET /api/mobile/locations
     *
     * Returns all active locations for the mobile app landing screen.
     */
    public function getLocations(): JsonResponse
    {
        $locations = Location::active()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'address',
                'city',
                'state',
                'zip_code',
                'phone',
                'email',
                'timezone',
            ]);

        return response()->json([
            'success' => true,
            'data' => $locations,
        ]);
    }

    /**
     * GET /api/mobile/locations/{locationId}/packages?date=2026-03-17
     *
     * Returns active packages for a location filtered by date.
     * Only packages that have an availability schedule matching the given date are returned.
     * Defaults to today's date in the location's timezone.
     */
    public function getPackagesByLocationAndDate(Request $request, int $locationId): JsonResponse
    {
        $location = Location::find($locationId);

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found',
            ], 404);
        }

        // Use location timezone for default date
        $timezone = $location->timezone ?? 'America/Chicago';
        $date = $request->get('date', Carbon::now($timezone)->format('Y-m-d'));

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format. Use Y-m-d.',
            ], 422);
        }

        // Check if the entire location has a full day-off on this date
        $locationClosed = DayOff::isDateBlocked($locationId, $date);

        // Get day offs for this location and date (for frontend display)
        $dayOffs = DayOff::where('location_id', $locationId)
            ->whereDate('date', $date)
            ->get()
            ->map(function ($dayOff) {
                return [
                    'id' => $dayOff->id,
                    'date' => $dayOff->date->format('Y-m-d'),
                    'time_start' => $dayOff->time_start,
                    'time_end' => $dayOff->time_end,
                    'reason' => $dayOff->reason,
                    'is_full_day' => $dayOff->isFullDay(),
                    'is_location_wide' => $dayOff->isLocationWide(),
                    'package_ids' => $dayOff->package_ids,
                    'room_ids' => $dayOff->room_ids,
                ];
            });

        // Get special pricings active on this date for this location
        $specialPricings = SpecialPricing::active()
            ->byLocation($locationId)
            ->forPackages()
            ->withinDateRange(Carbon::parse($date))
            ->get()
            ->filter(fn($sp) => $sp->isActiveOnDate(Carbon::parse($date)));

        // Get active packages for this location with their schedules
        $packages = Package::with(['availabilitySchedules' => function ($q) {
                $q->where('is_active', true);
            }])
            ->byLocation($locationId)
            ->active()
            ->orderBy('display_order', 'asc')
            ->orderBy('name')
            ->get();

        // Filter packages that have a matching schedule for the requested date
        $filteredPackages = $packages->filter(function ($package) use ($date) {
            $matchingSchedules = $package->availabilitySchedules->filter(function ($schedule) use ($date) {
                return $schedule->matchesDate($date);
            });
            return $matchingSchedules->isNotEmpty();
        });

        $result = $filteredPackages->values()->map(function ($package) use ($date, $specialPricings) {
            // Get time slots count for quick preview
            $timeSlots = $package->getTimeSlotsForDate($date);

            // Find applicable special pricings for this package
            $applicablePricings = $specialPricings
                ->filter(fn($sp) => $sp->appliesToEntity($package->id, SpecialPricing::ENTITY_PACKAGE))
                ->values()
                ->map(fn($sp) => [
                    'id' => $sp->id,
                    'name' => $sp->name,
                    'description' => $sp->description,
                    'discount_amount' => $sp->discount_amount,
                    'discount_type' => $sp->discount_type,
                    'discounted_price' => number_format((float) $package->price - $sp->calculateDiscount((float) $package->price), 2, '.', ''),
                    'time_start' => $sp->time_start,
                    'time_end' => $sp->time_end,
                ]);

            return [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'category' => $package->category,
                'package_type' => $package->package_type,
                'price' => $package->price,
                'price_per_additional' => $package->price_per_additional,
                'min_participants' => $package->min_participants,
                'max_participants' => $package->max_participants,
                'duration' => $package->duration,
                'duration_unit' => $package->duration_unit,
                'image' => $package->image,
                'features' => $package->features,
                'has_guest_of_honor' => $package->has_guest_of_honor,
                'customer_notes' => $package->customer_notes,
                'total_slots' => count($timeSlots),
                'schedule_info' => $this->getScheduleSummary($package, $date),
                'special_pricings' => $applicablePricings,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'timezone' => $timezone,
                    'is_closed' => $locationClosed,
                ],
                'date' => $date,
                'day_offs' => $dayOffs->values(),
                'packages' => $result,
            ],
        ]);
    }

    /**
     * GET /api/mobile/packages/{packageId}/availability?date=2026-03-17
     *
     * Returns detailed slot availability for a package on a given date.
     * This is the data for the modal view with full slot details.
     */
    public function getPackageAvailability(Request $request, int $packageId): JsonResponse
    {
        $package = Package::with(['rooms', 'location', 'availabilitySchedules' => function ($q) {
            $q->where('is_active', true);
        }])->find($packageId);


        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Package not found',
            ], 404);
        }

        $location = $package->location;
        $timezone = $location->timezone ?? 'America/Chicago';
        $date = $request->get('date', Carbon::now($timezone)->format('Y-m-d'));

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format. Use Y-m-d.',
            ], 422);
        }

        // Check if this package has a day-off block
        $locationId = $package->location_id;
        $isPackageBlocked = $this->isPackageFullyBlocked($locationId, $packageId, $date);

        // Get the available time slots with room info
        $availableSlots = [];
        if (!$isPackageBlocked && $package->rooms->isNotEmpty()) {
            $availableSlots = $this->generateAvailableSlotsWithRooms($package, $date);
        }

        // Get the schedule that matches this date for display
        $matchingSchedule = $package->availabilitySchedules
            ->filter(fn($s) => $s->matchesDate($date))
            ->sortByDesc('priority')
            ->first();

        // Get available dates for the next N days (for date picker in modal)
        $availableDates = $this->getAvailableDatesForPackage($package, $date, 30);

        // Get day offs for this date that affect this package
        $dayOffs = DayOff::where('location_id', $locationId)
            ->whereDate('date', $date)
            ->forPackage($packageId)
            ->get()
            ->filter(fn($d) => $d->appliesToPackage($packageId))
            ->values()
            ->map(function ($dayOff) {
                return [
                    'id' => $dayOff->id,
                    'date' => $dayOff->date->format('Y-m-d'),
                    'time_start' => $dayOff->time_start,
                    'time_end' => $dayOff->time_end,
                    'reason' => $dayOff->reason,
                    'is_full_day' => $dayOff->isFullDay(),
                    'is_location_wide' => $dayOff->isLocationWide(),
                ];
            });

        // Get special pricings for this package on this date
        $specialPricings = SpecialPricing::active()
            ->byLocation($locationId)
            ->forPackages()
            ->withinDateRange(Carbon::parse($date))
            ->get()
            ->filter(function ($sp) use ($package, $date) {
                return $sp->isActiveOnDate(Carbon::parse($date))
                    && $sp->appliesToEntity($package->id, SpecialPricing::ENTITY_PACKAGE);
            })
            ->values()
            ->map(function ($sp) use ($package) {
                return [
                    'id' => $sp->id,
                    'name' => $sp->name,
                    'description' => $sp->description,
                    'discount_amount' => $sp->discount_amount,
                    'discount_type' => $sp->discount_type,
                    'discounted_price' => number_format((float) $package->price - $sp->calculateDiscount((float) $package->price), 2, '.', ''),
                    'time_start' => $sp->time_start,
                    'time_end' => $sp->time_end,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'package' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'category' => $package->category,
                    'package_type' => $package->package_type,
                    'price' => $package->price,
                    'price_per_additional' => $package->price_per_additional,
                    'min_participants' => $package->min_participants,
                    'max_participants' => $package->max_participants,
                    'duration' => $package->duration,
                    'duration_unit' => $package->duration_unit,
                    'image' => $package->image,
                    'features' => $package->features,
                    'has_guest_of_honor' => $package->has_guest_of_honor,
                    'customer_notes' => $package->customer_notes,
                    'total_rooms' => $package->rooms->count(),
                ],
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'timezone' => $timezone,
                ],
                'date' => $date,
                'is_blocked' => $isPackageBlocked,
                'day_offs' => $dayOffs,
                'special_pricings' => $specialPricings,
                'schedule' => $matchingSchedule ? [
                    'availability_type' => $matchingSchedule->availability_type,
                    'day_configuration' => $matchingSchedule->day_configuration,
                    'time_slot_start' => $matchingSchedule->time_slot_start,
                    'time_slot_end' => $matchingSchedule->time_slot_end,
                    'time_slot_interval' => $matchingSchedule->time_slot_interval,
                ] : null,
                'available_slots' => $availableSlots,
                'total_available_slots' => count($availableSlots),
                'available_dates' => $availableDates,
            ],
        ]);
    }

    /**
     * GET /api/mobile/packages/{packageId}/available-dates?from=2026-03-17&days=60
     *
     * Returns dates that have availability for a package within a range.
     * Useful for the date picker to highlight/enable only available dates.
     */
    public function getPackageAvailableDates(Request $request, int $packageId): JsonResponse
    {
        $package = Package::with(['availabilitySchedules' => function ($q) {
            $q->where('is_active', true);
        }, 'location'])->find($packageId);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Package not found',
            ], 404);
        }

        $timezone = $package->location->timezone ?? 'America/Chicago';
        $from = $request->get('from', Carbon::now($timezone)->format('Y-m-d'));
        $days = min((int) $request->get('days', 30), 90); // Cap at 90 days

        $availableDates = $this->getAvailableDatesForPackage($package, $from, $days);

        return response()->json([
            'success' => true,
            'data' => [
                'package_id' => $package->id,
                'from' => $from,
                'days' => $days,
                'available_dates' => $availableDates,
            ],
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────

    /**
     * Check if a package is fully blocked on a date (location-wide or package-specific full day off).
     */
    private function isPackageFullyBlocked(int $locationId, int $packageId, string $date): bool
    {
        // Check location-wide full day block
        if (DayOff::isDateBlocked($locationId, $date)) {
            return true;
        }

        // Check package-specific full day block
        $dayOffs = DayOff::where('location_id', $locationId)
            ->whereDate('date', $date)
            ->forPackage($packageId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isFullDay() && $dayOff->appliesToPackage($packageId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a human-readable summary of the schedule for a package on a date.
     */
    private function getScheduleSummary(Package $package, string $date): ?array
    {
        $matchingSchedule = $package->availabilitySchedules
            ->filter(fn($s) => $s->matchesDate($date))
            ->sortByDesc('priority')
            ->first();

        if (!$matchingSchedule) {
            return null;
        }

        return [
            'availability_type' => $matchingSchedule->availability_type,
            'day_configuration' => $matchingSchedule->day_configuration,
            'time_slot_start' => $matchingSchedule->time_slot_start,
            'time_slot_end' => $matchingSchedule->time_slot_end,
            'time_slot_interval' => $matchingSchedule->time_slot_interval,
        ];
    }

    /**
     * Get available dates for a package within a date range.
     * Returns an array of dates (Y-m-d) that have at least one matching schedule
     * and are not fully blocked by day offs.
     */
    private function getAvailableDatesForPackage(Package $package, string $fromDate, int $days): array
    {
        $dates = [];
        $startDate = Carbon::parse($fromDate);

        for ($i = 0; $i < $days; $i++) {
            $checkDate = $startDate->copy()->addDays($i);
            $dateStr = $checkDate->format('Y-m-d');

            // Check if any schedule matches this date
            $hasSchedule = $package->availabilitySchedules
                ->contains(fn($s) => $s->matchesDate($dateStr));

            if (!$hasSchedule) {
                continue;
            }

            // Check if the package is not fully blocked
            if (!$this->isPackageFullyBlocked($package->location_id, $package->id, $dateStr)) {
                $dates[] = $dateStr;
            }
        }

        return $dates;
    }

    /**
     * Generate available time slots considering all rooms for the package.
     * Replicates the logic from PackageTimeSlotController for consistency.
     */
    private function generateAvailableSlotsWithRooms(Package $package, string $date): array
    {
        $availableSlots = [];
        $locationId = $package->location_id;

        $timeSlots = $package->getTimeSlotsForDate($date);

        if (empty($timeSlots)) {
            return [];
        }

        $duration = $package->duration;
        $durationUnit = $package->duration_unit;
        $slotDurationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);

        foreach ($timeSlots as $timeSlot) {
            $currentTime = Carbon::parse($date . ' ' . $timeSlot);
            $slotEndTime = (clone $currentTime)->addMinutes($slotDurationInMinutes);

            // Check if blocked by day off for this package
            $isPackageBlocked = DayOff::isTimeSlotBlockedForPackage(
                $locationId,
                $package->id,
                $date,
                $currentTime->format('H:i'),
                $slotEndTime->format('H:i')
            );

            if ($isPackageBlocked) {
                continue;
            }

            // Count available rooms for this slot
            $availableRoomsCount = $this->countAvailableRooms(
                $package,
                $date,
                $currentTime->format('H:i'),
                $duration,
                $durationUnit
            );

            if ($availableRoomsCount > 0) {
                $availableSlots[] = [
                    'start_time' => $currentTime->format('H:i'),
                    'end_time' => $slotEndTime->format('H:i'),
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'available_rooms_count' => $availableRoomsCount,
                    'total_rooms' => $package->rooms->count(),
                ];
            }
        }

        return $availableSlots;
    }

    /**
     * Count how many rooms are available for a specific time slot.
     */
    private function countAvailableRooms(Package $package, string $date, string $startTime, $duration, $durationUnit): int
    {
        if ($package->rooms->isEmpty()) {
            return 0;
        }

        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $slotStart = Carbon::parse($date . ' ' . $startTime);
        $slotEnd = (clone $slotStart)->addMinutes($durationInMinutes);

        $count = 0;

        foreach ($package->rooms as $room) {
            // Room-specific day off check
            $isRoomBlocked = DayOff::isTimeSlotBlockedForRoom(
                $package->location_id,
                $room->id,
                $date,
                $startTime,
                $slotEnd->format('H:i')
            );

            if ($isRoomBlocked) {
                continue;
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
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a time slot conflicts with existing bookings (includes cleanup buffer).
     */
    private function checkTimeSlotConflict($roomId, $date, $startTime, $duration, $durationUnit): bool
    {
        $start = Carbon::parse($date . ' ' . $startTime);
        $durationInMinutes = $this->getDurationInMinutes($duration, $durationUnit);
        $end = (clone $start)->addMinutes($durationInMinutes);

        $existingSlots = PackageTimeSlot::where('room_id', $roomId)
            ->whereDate('booked_date', $date)
            ->where('status', 'booked')
            ->get();

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);
            $existingDurationInMinutes = $this->getDurationInMinutes($slot->duration, $slot->duration_unit);
            $existingEnd = (clone $existingStart)->addMinutes($existingDurationInMinutes);
            $existingEndWithBuffer = (clone $existingEnd)->addMinutes(self::CLEANUP_BUFFER_MINUTES);

            if ($start->lt($existingEndWithBuffer) && $end->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a time slot conflicts with room break times.
     */
    private function checkBreakTimeConflict($roomId, $date, $startTime, $duration, $durationUnit): bool
    {
        $room = Room::find($roomId);

        if (!$room || !$room->break_time || empty($room->break_time)) {
            return false;
        }

        $bookingDate = Carbon::parse($date);
        $dayOfWeek = strtolower($bookingDate->format('l'));

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
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a time slot conflicts with area group stagger interval.
     */
    private function checkAreaGroupStaggerConflict($roomId, $date, $startTime): bool
    {
        $room = Room::find($roomId);

        if (!$room) {
            return false;
        }

        $bookingInterval = $room->booking_interval ?? 15;

        if ($bookingInterval <= 0) {
            return false;
        }

        $bookingStart = Carbon::parse($date . ' ' . $startTime);

        if ($room->area_group) {
            $roomIdsToCheck = Room::where('location_id', $room->location_id)
                ->where(function ($query) use ($room) {
                    $query->where('area_group', $room->area_group)
                          ->orWhereNull('area_group');
                })
                ->pluck('id')
                ->toArray();
        } else {
            $roomIdsToCheck = Room::where('location_id', $room->location_id)
                ->pluck('id')
                ->toArray();
        }

        $existingSlots = PackageTimeSlot::whereIn('room_id', $roomIdsToCheck)
            ->whereDate('booked_date', $date)
            ->where('status', 'booked')
            ->get();

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

    /**
     * Convert duration to minutes based on unit type.
     */
    private function getDurationInMinutes($duration, $durationUnit): int
    {
        $duration = (float) $duration;

        if ($durationUnit === 'hours' || $durationUnit === 'hours and minutes') {
            return (int) round($duration * 60);
        }

        return (int) round($duration);
    }
}
