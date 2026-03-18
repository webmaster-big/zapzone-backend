<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DayOff;
use App\Models\Location;
use App\Models\Package;
use App\Traits\GeneratesAvailableTimeSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class MobileAvailabilityController extends Controller
{
    use GeneratesAvailableTimeSlots;

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

        // Get active packages for this location with their schedules and rooms
        $packages = Package::with([
                'availabilitySchedules' => function ($q) {
                    $q->where('is_active', true);
                },
                'rooms',
            ])
            ->byLocation($locationId)
            ->active()
            ->get([
                'id', 'name', 'description', 'category',
                'price', 'min_participants', 'max_participants',
                'duration', 'duration_unit', 'image', 'display_order',
                'location_id',
            ]);

        // Filter packages that have a matching schedule for the requested date
        $filteredPackages = $packages->filter(function ($package) use ($date) {
            return $package->availabilitySchedules->contains(fn($s) => $s->matchesDate($date));
        })->sortBy([['display_order', 'asc'], ['name', 'asc']]);

        $result = $filteredPackages->values()->map(function ($package) use ($date) {
            // Use the same availability logic as PackageTimeSlotController
            $isBlocked = $this->isPackageFullyBlocked($package->location_id, $package->id, $date);
            $availableSlots = [];
            if (!$isBlocked && $package->rooms->isNotEmpty()) {
                $availableSlots = $this->generateAvailableSlotsWithRooms($package, $date);
            }

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
                'total_slots' => count($availableSlots),
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
