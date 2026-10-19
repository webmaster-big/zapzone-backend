<?php

namespace App\Support;

use App\Models\Attraction;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OnlineBookingGate
{
    public const CODE = 'LOCATION_NOT_BOOKABLE_ONLINE';

    public const PACKAGE_CODE = 'PACKAGE_NOT_BOOKABLE_ONLINE';

    public const REMOVED_PACKAGE_CODE = 'PACKAGE_NOT_AVAILABLE';

    public static function closedFor(mixed $actor, array $locationIds): bool
    {
        if ($actor instanceof User) {
            return false;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $locationIds))));

        if ($ids === [] || !SchemaSupport::hasColumn('locations', 'show_on_main_page')) {
            return false;
        }

        return Location::whereIn('id', $ids)->where('show_on_main_page', false)->exists();
    }

    public static function packageClosedOnline(mixed $packageId): bool
    {
        $id = (int) $packageId;

        if ($id <= 0) {
            return false;
        }

        $package = Package::withTrashed()->find($id);

        return !$package || $package->trashed() || !$package->isAvailableOnline();
    }

    public static function packageLocationId(mixed $packageId): ?int
    {
        $id = (int) $packageId;
        $locationId = $id > 0 ? Package::withTrashed()->whereKey($id)->value('location_id') : null;

        return $locationId !== null ? (int) $locationId : null;
    }

    public static function packageRemoved(mixed $packageId): bool
    {
        $id = (int) $packageId;

        return $id > 0 && !Package::whereKey($id)->exists();
    }

    public static function packageRefusal(mixed $packageId = null, bool $previousAttempt = false, bool $staff = false): JsonResponse
    {
        $removed = self::packageRemoved($packageId);

        return response()->json([
            'success' => false,
            'code' => $removed ? self::REMOVED_PACKAGE_CODE : self::PACKAGE_CODE,
            'message' => match (true) {
                $removed && $staff => 'This package was deleted, so it can no longer be booked. Nothing new was booked or charged. Please choose another package.',
                $removed => 'This package is no longer available. Please choose another package.',
                default => 'This package is not available for online booking. Please call the venue to book it.',
            },
        ] + ($previousAttempt ? ['previous_attempt' => true] : []), 422);
    }

    public static function cartLocationIds(array $items): array
    {
        $idsOf = fn (string $type) => collect($items)
            ->where('type', $type)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return Attraction::whereIn('id', $idsOf('attraction'))->pluck('location_id')
            ->merge(Event::whereIn('id', $idsOf('event'))->pluck('location_id'))
            ->all();
    }

    public static function refusal(?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => self::CODE,
            'message' => $message ?? 'This location is not taking online bookings right now. Please choose another location.',
        ], 422);
    }
}
