<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DayOff;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $timezone = $this->normalizeTimezone($location->timezone ?? 'America/Chicago');
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

        if ($locationClosed) {
            return response()->json([
                'success' => true,
                'data' => [
                    'location' => [
                        'id' => $location->id,
                        'name' => $location->name,
                        'timezone' => $timezone,
                        'is_closed' => true,
                    ],
                    'date' => $date,
                    'packages' => [],
                ],
            ]);
        }

        // Get active packages for this location with their schedules
        $packages = Package::with(['availabilitySchedules' => function ($q) {
                $q->where('is_active', true);
            }])
            ->byLocation($locationId)
            ->active()
            ->get([
                'id', 'name', 'description', 'category',
                'price', 'min_participants', 'max_participants',
                'duration', 'duration_unit', 'image', 'display_order',
            ]);

        // Filter packages that have a matching schedule for the requested date
        $filteredPackages = $packages->filter(function ($package) use ($date) {
            return $package->availabilitySchedules->contains(fn($s) => $s->matchesDate($date));
        })->sortBy([['display_order', 'asc'], ['name', 'asc']]);

        $result = $filteredPackages->values()->map(function ($package) use ($date) {
            $timeSlots = $package->getTimeSlotsForDate($date);

            return [
                'id' => $package->id,
                'name' => $package->name,
                'description' => $package->description,
                'category' => $package->category,
                'price' => $package->price,
                'min_participants' => $package->min_participants,
                'max_participants' => $package->max_participants,
                'duration' => $package->duration,
                'duration_unit' => $package->duration_unit,
                'image' => $package->image,
                'total_slots' => count($timeSlots),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'timezone' => $timezone,
                    'is_closed' => false,
                ],
                'date' => $date,
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
        $package = Package::with(['rooms', 'location'])->find($packageId);

        if (!$package) {
            return response()->json([
                'success' => false,
                'message' => 'Package not found',
            ], 404);
        }

        $location = $package->location;
        $timezone = $this->normalizeTimezone($location->timezone ?? 'America/Chicago');
        $date = $request->get('date', Carbon::now($timezone)->format('Y-m-d'));

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format. Use Y-m-d.',
            ], 422);
        }

        $locationId = $package->location_id;
        $isBlocked = $this->isPackageFullyBlocked($locationId, $packageId, $date);

        $availableSlots = [];
        if (!$isBlocked && $package->rooms->isNotEmpty()) {
            $availableSlots = $this->generateAvailableSlotsWithRooms($package, $date);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'package_name' => $package->name,
                'location_name' => $location->name,
                'date' => $date,
                'is_blocked' => $isBlocked,
                'available_slots' => $availableSlots,
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

    /**
     * Normalize a timezone string to IANA format.
     * Handles Windows-style timezone names (e.g. "Eastern Standard Time" → "America/New_York").
     * Falls back to America/Chicago if the timezone is unrecognized.
     */
    private function normalizeTimezone(string $timezone): string
    {
        static $windowsToIana = [
            'Eastern Standard Time'  => 'America/New_York',
            'Eastern Daylight Time'  => 'America/New_York',
            'Central Standard Time'  => 'America/Chicago',
            'Central Daylight Time'  => 'America/Chicago',
            'Mountain Standard Time' => 'America/Denver',
            'Mountain Daylight Time' => 'America/Denver',
            'Pacific Standard Time'  => 'America/Los_Angeles',
            'Pacific Daylight Time'  => 'America/Los_Angeles',
            'Alaska Standard Time'   => 'America/Anchorage',
            'Hawaii Standard Time'   => 'Pacific/Honolulu',
            'Atlantic Standard Time' => 'America/Halifax',
            'Arizona Standard Time'  => 'America/Phoenix',
            'Indiana Standard Time'  => 'America/Indiana/Indianapolis',
        ];

        if (isset($windowsToIana[$timezone])) {
            return $windowsToIana[$timezone];
        }

        // Validate it's a real IANA timezone; fall back to America/Chicago if not
        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception $e) {
            return 'America/Chicago';
        }
    }
}
