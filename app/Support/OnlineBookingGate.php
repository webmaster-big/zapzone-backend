<?php

namespace App\Support;

use App\Models\Attraction;
use App\Models\Event;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OnlineBookingGate
{
    public const CODE = 'LOCATION_NOT_BOOKABLE_ONLINE';

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

    public static function refusal(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => self::CODE,
            'message' => 'This location is not taking online bookings right now. Please choose another location.',
        ], 422);
    }
}
