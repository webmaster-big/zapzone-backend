<?php

namespace App\Services;

use App\Http\Middleware\EnsureStaff;
use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\DayOff;
use App\Models\DayOffChangeLog;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AvailabilityBlockGuard
{
    public const ACKNOWLEDGE_FIELD = 'acknowledge_blocked_period';

    public const ACKNOWLEDGED_IDS_FIELD = 'acknowledged_block_ids';

    public const CODE = 'BLOCKED_PERIOD';

    public const ATTRACTION_SLOT_MINUTES = 60;

    private array $occurrenceDates = [];

    public function __construct(private DayOffPresenter $presenter)
    {
    }

    public function forPackageBooking(int $locationId, ?int $packageId, ?int $roomId, string $date, string $start, ?string $end, array $attractionIds = []): Collection
    {
        $package = $packageId ? Package::query()->select(['id', 'location_id'])->find($packageId) : null;
        $overnightUntil = $package?->overnightUntilMinute(Carbon::parse($date)->toDateString());
        $attractionIds = $this->distinctIds($attractionIds);
        $blocks = $this->blocksOn($locationId, $date);
        $inside = fn (DayOff $dayOff) => $dayOff->isTimeBlocked($start, $end, $overnightUntil);

        $direct = $blocks->filter(function (DayOff $dayOff) use ($packageId, $roomId, $attractionIds, $inside) {
            $applies = $this->appliesToPackageBooking($dayOff, $packageId, $roomId)
                || collect($attractionIds)->contains(fn (int $attractionId) => $dayOff->appliesToAttraction($attractionId));

            return $applies && $inside($dayOff);
        });

        $everyRoom = collect();

        if ($roomId === null && $package) {
            $roomIds = $package->rooms()->where('rooms.is_available', true)->pluck('rooms.id')->map(fn ($id) => (int) $id)->all();

            foreach ($roomIds as $packageRoomId) {
                $roomBlocks = $blocks->filter(fn (DayOff $dayOff) => $dayOff->appliesToRoom($packageRoomId) && $inside($dayOff));

                if ($roomBlocks->isEmpty()) {
                    $everyRoom = collect();
                    break;
                }

                $everyRoom = $everyRoom->merge($roomBlocks->values());
            }
        }

        return $direct->values()->merge($everyRoom)->unique('id')->sortBy('id')->values();
    }

    public function forExistingBooking(Booking $booking): Collection
    {
        $date = $booking->booking_date ? Carbon::parse($booking->booking_date)->toDateString() : null;
        $start = $booking->booking_time instanceof \DateTimeInterface
            ? $booking->booking_time->format('H:i')
            : DayOff::shortClock((string) $booking->booking_time);

        if (! $date || ! $start || ! $booking->location_id || Carbon::parse($date)->lt(Carbon::today())) {
            return collect();
        }

        $end = Carbon::parse($start)
            ->addMinutes(self::durationMinutes($booking->duration ?? 2, $booking->duration_unit ?? 'hours'))
            ->format('H:i');

        return $this->forPackageBooking(
            (int) $booking->location_id,
            $booking->package_id ? (int) $booking->package_id : null,
            $booking->room_id ? (int) $booking->room_id : null,
            $date,
            $start,
            $end,
            $booking->attractions()->pluck('attractions.id')->all()
        );
    }

    public function forExistingAttractionPurchase(AttractionPurchase $purchase): Collection
    {
        $date = $purchase->scheduled_date ? Carbon::parse($purchase->scheduled_date)->toDateString() : null;
        $attraction = Attraction::query()->withoutGlobalScopes()->select(['id', 'location_id'])->find($purchase->attraction_id);

        if (! $date || ! $attraction?->location_id || Carbon::parse($date)->lt(Carbon::today())) {
            return collect();
        }

        $time = $purchase->scheduled_time instanceof \DateTimeInterface
            ? $purchase->scheduled_time->format('H:i')
            : DayOff::shortClock((string) $purchase->scheduled_time);

        return $this->forAttraction((int) $attraction->location_id, (int) $attraction->id, $date, $time);
    }

    public function forExistingEventPurchase(EventPurchase $purchase): Collection
    {
        $date = $purchase->purchase_date ? Carbon::parse($purchase->purchase_date)->toDateString() : null;
        $event = Event::query()->select(['id', 'location_id', 'time_start', 'time_end', 'interval_minutes'])->find($purchase->event_id);

        if (! $date || ! $event?->location_id || Carbon::parse($date)->lt(Carbon::today())) {
            return collect();
        }

        $time = self::eventSlotTime($event, $purchase->purchase_time instanceof \DateTimeInterface
            ? $purchase->purchase_time->format('H:i')
            : DayOff::shortClock((string) $purchase->purchase_time));

        return $this->forEvent(
            (int) $event->location_id,
            (int) $event->id,
            $date,
            $time,
            $time ? Carbon::parse($time)->addMinutes((int) ($event->interval_minutes ?: 60))->format('H:i') : null
        );
    }

    public static function eventSlotTime(Event $event, ?string $time): ?string
    {
        if ($time === '00:00' && ! in_array('00:00', $event->getTimeSlots(), true)) {
            return null;
        }

        return $time;
    }

    public static function durationMinutes($duration, ?string $unit): int
    {
        $value = (float) $duration;

        return in_array($unit, ['hours', 'hours and minutes'], true) ? (int) round($value * 60) : (int) round($value);
    }

    public function addedAttractionsMessage(Collection $blocks, ?int $packageId, ?int $roomId, array $attractionIds): ?string
    {
        if ($blocks->isEmpty()) {
            return null;
        }

        $attractionOnly = fn (DayOff $dayOff) => ! empty($dayOff->attraction_ids)
            && empty($dayOff->package_ids)
            && empty($dayOff->room_ids)
            && empty($dayOff->event_ids);

        if (! $blocks->every($attractionOnly)) {
            return null;
        }

        $blockedIds = collect($this->distinctIds($attractionIds))
            ->filter(fn (int $attractionId) => $blocks->contains(fn (DayOff $dayOff) => $dayOff->appliesToAttraction($attractionId)))
            ->values();

        if ($blockedIds->isEmpty()) {
            return null;
        }

        $names = Attraction::query()->withoutGlobalScopes()->whereIn('id', $blockedIds)->orderBy('name')->pluck('name')->filter()->values();

        if ($names->isEmpty()) {
            return 'An attraction added to this booking is not available at that time. Remove it or choose another time.';
        }

        return $names->implode(', ') . ($names->count() === 1 ? ' is' : ' are')
            . ' not available at that time. Remove ' . ($names->count() === 1 ? 'it' : 'them') . ' or choose another time.';
    }

    public function forAttraction(int $locationId, int $attractionId, string $date, ?string $time, ?string $end = null): Collection
    {
        $end ??= self::attractionSlotEnd($time);
        $blocks = $this->blocksOn($locationId, $date)
            ->filter(fn (DayOff $dayOff) => $dayOff->appliesToAttraction($attractionId))
            ->values();

        if ($time) {
            return $blocks->filter(fn (DayOff $dayOff) => $dayOff->isTimeBlocked($time, $end))->values();
        }

        $fullDay = $blocks->filter(fn (DayOff $dayOff) => $dayOff->isFullDay())->values();
        $attraction = $fullDay->isEmpty()
            ? Attraction::query()->withoutGlobalScopes()->select(['id', 'availability'])->find($attractionId)
            : null;
        $slots = $attraction ? self::attractionSlots($attraction->availability, $date) : [];

        if ($slots === []) {
            return $fullDay;
        }

        $hits = collect();

        foreach ($slots as $slot) {
            $slotBlocks = $blocks->filter(fn (DayOff $dayOff) => $dayOff->isTimeBlocked($slot, self::attractionSlotEnd($slot)));

            if ($slotBlocks->isEmpty()) {
                return collect();
            }

            $hits = $hits->merge($slotBlocks->values());
        }

        return $hits->unique('id')->sortBy('id')->values();
    }

    public static function attractionSlots($availability, string $date): array
    {
        if (! is_array($availability) || $availability === []) {
            return [];
        }

        $weekday = strtolower(Carbon::parse($date)->format('l'));
        $window = null;

        if (array_is_list($availability)) {
            foreach ($availability as $block) {
                $days = is_array($block) ? array_map(fn ($day) => strtolower((string) $day), (array) ($block['days'] ?? [])) : [];

                if (in_array($weekday, $days, true)) {
                    $window = [$block['start_time'] ?? null, $block['end_time'] ?? null];
                    break;
                }
            }
        } elseif (! empty($availability[$weekday])) {
            $window = ['09:00', '17:00'];
        }

        $start = DayOff::clockToMinutes($window[0] ?? null);
        $end = DayOff::clockToMinutes($window[1] ?? null);

        if ($start === null || $end === null) {
            return [];
        }

        if ($end <= $start) {
            $end += DayOff::MINUTES_PER_DAY;
        }

        $slots = [];

        for ($minute = $start; $minute < $end; $minute += self::ATTRACTION_SLOT_MINUTES) {
            $wrapped = $minute % DayOff::MINUTES_PER_DAY;
            $slots[] = sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
        }

        return $slots;
    }

    public function forEvent(int $locationId, int $eventId, string $date, ?string $time, ?string $end = null): Collection
    {
        $event = Event::query()->select(['id', 'location_id', 'time_start', 'time_end', 'interval_minutes'])->find($eventId);
        $overnightUntil = $event?->overnightUntilMinute();
        $blocks = $this->blocksOn($locationId, $date)
            ->filter(fn (DayOff $dayOff) => $dayOff->appliesToEvent($eventId))
            ->values();

        if ($time) {
            return $blocks->filter(fn (DayOff $dayOff) => $dayOff->isTimeBlocked($time, $end, $overnightUntil))->values();
        }

        $fullDay = $blocks->filter(fn (DayOff $dayOff) => $dayOff->isFullDay())->values();
        $slots = $event ? $event->getTimeSlots() : [];

        if ($fullDay->isNotEmpty() || $slots === []) {
            return $fullDay;
        }

        $interval = (int) ($event->interval_minutes ?: 60);
        $hits = collect();

        foreach ($slots as $slot) {
            $slotEnd = Carbon::parse($slot)->addMinutes($interval)->format('H:i');
            $slotBlocks = $blocks->filter(fn (DayOff $dayOff) => $dayOff->isTimeBlocked($slot, $slotEnd, $overnightUntil));

            if ($slotBlocks->isEmpty()) {
                return collect();
            }

            $hits = $hits->merge($slotBlocks->values());
        }

        return $hits->unique('id')->sortBy('id')->values();
    }

    public static function attractionSlotEnd(?string $time): ?string
    {
        $start = DayOff::clockToMinutes($time);

        if ($start === null) {
            return null;
        }

        $end = ($start + self::ATTRACTION_SLOT_MINUTES) % DayOff::MINUTES_PER_DAY;

        return sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
    }

    public function isStaff(Request $request, ?int $locationId = null): bool
    {
        $user = $request->user('sanctum');

        if (! $user instanceof User || ! in_array((string) $user->role, EnsureStaff::ROLES, true) || $user->company_id === null) {
            return false;
        }

        if ($locationId === null) {
            return true;
        }

        $companyId = Location::query()->whereKey($locationId)->value('company_id');

        return $companyId !== null && (int) $companyId === (int) $user->company_id;
    }

    public function isStaffForAll(Request $request, Collection $blocks): bool
    {
        $locationIds = $blocks->map(fn (DayOff $dayOff) => (int) $dayOff->location_id)->unique()->values();

        if ($locationIds->isEmpty()) {
            return $this->isStaff($request);
        }

        return $locationIds->every(fn (int $locationId) => $this->isStaff($request, $locationId));
    }

    public function acknowledged(Request $request, Collection $blocks): bool
    {
        if ($blocks->isEmpty() || ! $request->boolean(self::ACKNOWLEDGE_FIELD)) {
            return false;
        }

        if (! $this->isStaffForAll($request, $blocks)) {
            return false;
        }

        $seen = collect((array) $request->input(self::ACKNOWLEDGED_IDS_FIELD, []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id);

        return $blocks->every(fn (DayOff $dayOff) => $seen->contains((int) $dayOff->id));
    }

    public function refusal(Request $request, Collection $blocks, string $guestMessage, string $staffAction = 'This booking'): ?JsonResponse
    {
        if ($blocks->isEmpty()) {
            return null;
        }

        if (! $this->isStaffForAll($request, $blocks)) {
            return response()->json([
                'success' => false,
                'message' => $guestMessage,
            ], 422);
        }

        if ($this->acknowledged($request, $blocks)) {
            return null;
        }

        $periods = array_map(
            fn (array $period) => ['occurrence_date' => $this->occurrenceDates[$period['id']] ?? $period['date']] + $period,
            $this->presenter->presentMany($blocks)
        );

        return response()->json([
            'success' => false,
            'code' => self::CODE,
            'requires_acknowledgement' => true,
            'message' => $staffAction . ' falls inside a blocked period: ' . $this->describe($periods)
                . '. Confirm to book it anyway.',
            'blocked_periods' => $periods,
        ], 409);
    }

    public function recordBookedInside(Request $request, Collection $blocks, string $entityType, int $entityId, array $details): void
    {
        if ($blocks->isEmpty() || ! $this->acknowledged($request, $blocks)) {
            return;
        }

        $actor = $request->user('sanctum');
        $reference = $details['reference'] ?? "#{$entityId}";

        foreach ($blocks as $dayOff) {
            try {
                if (DayOff::tracksAvailabilityDetails()) {
                    DayOffChangeLog::record(
                        DayOffChangeLog::ACTION_BOOKED_INSIDE,
                        $dayOff,
                        $actor,
                        "{$details['label']} {$reference} was booked inside this block after the warning was acknowledged",
                        null,
                        $dayOff->snapshot(),
                        ['entity_type' => $entityType, 'entity_id' => $entityId] + $details,
                        $request->ip(),
                    );
                }

                ActivityLog::log(
                    action: 'Booked Inside Blocked Period',
                    category: 'other',
                    description: "{$details['label']} {$reference} was booked inside the blocked period"
                        . ($dayOff->group_name ? " for {$dayOff->group_name}" : '')
                        . ' after the warning was acknowledged',
                    userId: $actor?->id,
                    locationId: $dayOff->location_id,
                    entityType: $entityType,
                    entityId: $entityId,
                    metadata: [
                        'day_off_id' => $dayOff->id,
                        'day_off' => $dayOff->snapshot(),
                        'booking' => $details,
                    ],
                );
            } catch (\Throwable $e) {
                Log::error('Could not record a booking made inside a blocked period', [
                    'day_off_id' => $dayOff->id,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function releaseMovedItem(string $column, int $itemId, int $oldLocationId, string $itemName): void
    {
        $rows = DayOff::where('location_id', $oldLocationId)
            ->whereNotNull($column)
            ->orderBy('id')
            ->get()
            ->filter(fn (DayOff $dayOff) => in_array($itemId, DayOff::normalizedIds($dayOff->{$column}) ?? [], true));

        if ($rows->isEmpty()) {
            return;
        }

        $actor = request()->user('sanctum');
        $actor = $actor instanceof User ? $actor : null;
        $logs = DayOff::tracksAvailabilityDetails();

        foreach ($rows as $dayOff) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($dayOff, $column, $itemId, $itemName, $actor, $logs) {
                $before = $dayOff->snapshot();
                $remaining = array_values(array_diff(DayOff::normalizedIds($dayOff->{$column}) ?? [], [$itemId]));
                $dayOff->{$column} = $remaining === [] ? null : $remaining;
                $context = ['item_moved' => ['column' => $column, 'id' => $itemId, 'name' => $itemName]];

                if ($dayOff->isLocationWide()) {
                    if ($logs) {
                        DayOffChangeLog::record(DayOffChangeLog::ACTION_DELETED, $dayOff, $actor, "Removed this block because {$itemName} moved to another location", $before, null, $context, request()->ip());
                    }

                    DayOff::whereKey($dayOff->id)->delete();

                    return;
                }

                $dayOff->save();

                if ($logs) {
                    DayOffChangeLog::record(DayOffChangeLog::ACTION_UPDATED, $dayOff, $actor, "Took {$itemName} off this block because it moved to another location", $before, $dayOff->snapshot(), $context, request()->ip());
                }
            });
        }
    }

    public function recordRemoved(string $entityType, int $entityId, bool $permanently): void
    {
        if (! DayOff::tracksAvailabilityDetails()) {
            return;
        }

        try {
            $logs = DayOffChangeLog::query()
                ->where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)
                ->where('context->entity_type', $entityType)
                ->where('context->entity_id', $entityId)
                ->orderByDesc('id')
                ->get()
                ->unique('day_off_id');

            if ($logs->isEmpty()) {
                return;
            }

            $actor = request()->user('sanctum');

            foreach ($logs as $log) {
                $dayOff = $log->day_off_id ? DayOff::find($log->day_off_id) : null;
                $label = $log->context['label'] ?? 'Booking';
                $reference = $log->context['reference'] ?? "#{$entityId}";

                DayOffChangeLog::record(
                    DayOffChangeLog::ACTION_BOOKING_REMOVED,
                    $dayOff,
                    $actor instanceof User ? $actor : null,
                    "{$label} {$reference}, booked inside this block, was " . ($permanently ? 'permanently deleted' : 'deleted'),
                    null,
                    $dayOff ? null : ($log->after ?? ['id' => $log->day_off_id, 'location_id' => $log->location_id]),
                    ['entity_type' => $entityType, 'entity_id' => $entityId, 'reference' => $reference, 'permanently' => $permanently],
                    request()->ip(),
                );
            }
        } catch (\Throwable $e) {
            Log::error('Could not record that a booking inside a blocked period was removed', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function appliesToPackageBooking(DayOff $dayOff, ?int $packageId, ?int $roomId): bool
    {
        $applies = $packageId ? $dayOff->appliesToPackage($packageId) : $dayOff->isLocationWide();

        return $applies || ($roomId && $dayOff->appliesToRoom($roomId));
    }

    private function distinctIds(array $ids): array
    {
        return collect($ids)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function blocksOn(int $locationId, string $date): Collection
    {
        $day = Carbon::parse($date)->toDateString();

        $blocks = DayOff::where('location_id', $locationId)
            ->forDate($day)
            ->orderBy('id')
            ->get();

        foreach ($blocks as $dayOff) {
            $this->occurrenceDates[(int) $dayOff->id] = $day;
        }

        return $blocks;
    }

    private function describe(array $periods): string
    {
        return collect($periods)
            ->map(function (array $period) {
                $parts = [$period['scope_label'] . ' — ' . $period['window_label']];

                if (! empty($period['group_name'])) {
                    $parts[] = 'for ' . $period['group_name'];
                }

                if (! empty($period['reason'])) {
                    $parts[] = '(' . $period['reason'] . ')';
                }

                return implode(' ', $parts);
            })
            ->implode('; ');
    }
}
