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

    private const CLEANUP_BUFFER_MINUTES = 15;

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

    public function getPackagesByLocationAndDate(Request $request, int $locationId): JsonResponse
    {
        $location = Location::find($locationId);

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found',
            ], 404);
        }

        $timezone = $this->normalizeTimezone($location->timezone ?? 'America/Chicago');
        $date = $request->get('date', Carbon::now($timezone)->format('Y-m-d'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format. Use Y-m-d.',
            ], 422);
        }

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

        $filteredPackages = $packages->filter(function ($package) use ($date) {
            return $package->availabilitySchedules->contains(fn($s) => $s->matchesDate($date));
        })->sortBy([['display_order', 'asc'], ['name', 'asc']]);

        $result = $filteredPackages->values()->map(function ($package) use ($date) {
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


    private function isPackageFullyBlocked(int $locationId, int $packageId, string $date): bool
    {
        if (DayOff::isDateBlocked($locationId, $date)) {
            return true;
        }

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

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception $e) {
            return 'America/Chicago';
        }
    }
}
