<?php

namespace App\Services;

use App\Http\Traits\PresentsPhotos;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\DayOff;
use App\Models\EscapeRoomSession;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Package;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Support\EscapeRoomException;
use App\Support\OperatingDay;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EscapeRoomSessionService
{
    use PresentsPhotos;

    public const MAX_COMPLETION_SECONDS = 36000;

    public const SUBMIT_GRACE_MINUTES = 60;

    public const SIGN_AHEAD_DAYS = 366;

    public const UNSENT_LOOKBACK_DAYS = 7;

    public const STUCK_AFTER_MINUTES = 5;

    public const ROOM_COLUMNS = [
        'id',
        'location_id',
        'name',
        'duration',
        'duration_unit',
        'is_active',
        'is_escape_room',
        'participant_label',
        'min_participants',
        'max_participants',
        'display_order',
    ];

    public const EXCLUDED_BOOKING_REMOVED = 'booking_removed';
    public const EXCLUDED_BOOKING_CANCELLED = 'booking_cancelled';
    public const EXCLUDED_BOOKING_MOVED = 'booking_moved';
    public const EXCLUDED_OTHER_LOCATION = 'other_location';

    public function __construct(protected PhotoDeliveryService $deliveries)
    {
    }

    public function isEnabled(): bool
    {
        return Package::supportsEscapeRoomFlag()
            && WaiverTemplate::supportsKind()
            && EscapeRoomSession::isAvailable()
            && Waiver::supportsEscapeRoomSessionId();
    }

    public function today(Location $location): string
    {
        return OperatingDay::calendarDateFor($location);
    }

    public function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($value), $matches)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    public function parseCompletionTime(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{1,3})$/', $value, $matches)) {
            $seconds = (int) $matches[1] * 60;
        } elseif (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $matches)) {
            $seconds = ((int) $matches[1] * 60) + (int) $matches[2];
        } elseif (preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $value, $matches)) {
            $seconds = ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
        } else {
            return null;
        }

        return $seconds > 0 && $seconds <= self::MAX_COMPLETION_SECONDS ? $seconds : null;
    }

    public function timeLabel(string $time): string
    {
        try {
            return Carbon::createFromFormat('H:i', $time)->format('g:i A');
        } catch (\Throwable) {
            return $time;
        }
    }

    public function roomsAt(Location $location, bool $activeOnly = true): Collection
    {
        return Package::escapeRooms()
            ->where('location_id', $location->id)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(self::ROOM_COLUMNS);
    }

    public function findRoom(Location $location, int $packageId, bool $activeOnly = true): ?Package
    {
        return Package::escapeRooms()
            ->where('location_id', $location->id)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->find($packageId, self::ROOM_COLUMNS);
    }

    public function templateForRoom(Location $location, Package $room): ?WaiverTemplate
    {
        return WaiverTemplate::resolveForEscapeRoom((int) $location->company_id, (int) $location->id, (int) $room->id);
    }

    public function scheduledTimes(Package $room, string $date): array
    {
        $room->forgetResolvedSchedules();
        $duration = max(1, $room->getDurationInMinutes());
        $slots = $room->getTimeSlotsForDate($date);

        if ($slots === []) {
            return [];
        }

        $closures = DayOff::where('location_id', $room->location_id)
            ->forDate($date)
            ->forPackage((int) $room->id)
            ->get();

        return array_values(array_filter(
            $slots,
            fn (string $time) => !$closures->contains(
                fn (DayOff $closure) => $closure->isTimeBlocked($time, $this->endTime($time, $duration))
                    && $closure->appliesToPackage((int) $room->id)
            )
        ));
    }

    public function bookedTimes(Package $room, string $date): array
    {
        return Booking::where('package_id', $room->id)
            ->where('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->selectRaw("DISTINCT TIME_FORMAT(booking_time, '%H:%i') as slot_time")
            ->pluck('slot_time')
            ->filter()
            ->values()
            ->all();
    }

    public function sessionTimes(Package $room, string $date): array
    {
        if (!EscapeRoomSession::isAvailable()) {
            return [];
        }

        return EscapeRoomSession::where('package_id', $room->id)
            ->whereDate('session_date', $date)
            ->where(function ($query) {
                $query->whereNotNull('photo_session_id')
                    ->orWhereNotNull('completed_at')
                    ->orWhereExists(fn ($waivers) => $waivers->selectRaw('1')
                        ->from('waivers')
                        ->whereColumn('waivers.escape_room_session_id', 'escape_room_sessions.id')
                        ->whereNull('waivers.deleted_at'));
            })
            ->pluck('session_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();
    }

    public function timesForDay(Package $room, string $date, bool $includeSchedule = true): array
    {
        $times = array_merge(
            $includeSchedule ? $this->scheduledTimes($room, $date) : [],
            $this->bookedTimes($room, $date),
            $this->sessionTimes($room, $date)
        );

        $times = array_values(array_unique(array_filter(array_map(fn ($time) => $this->normalizeTime($time), $times))));
        sort($times);

        return $times;
    }

    public function guestTimes(Location $location, Package $room, int $graceMinutes = 0, ?string $date = null): array
    {
        $date ??= $this->today($location);
        $now = OperatingDay::localNow($location);
        $duration = max(1, $room->getDurationInMinutes());
        $tz = OperatingDay::timezoneFor($location);

        $times = [];
        $resultOnly = $this->resultOnlyTimes($room, $date);

        foreach ($this->timesForDay($room, $date) as $time) {
            if (in_array($time, $resultOnly, true)) {
                continue;
            }

            $start = Carbon::parse($date . ' ' . $time, $tz);
            $end = $start->copy()->addMinutes($duration);

            if ($end->copy()->addMinutes($graceMinutes)->lessThanOrEqualTo($now)) {
                continue;
            }

            $times[] = [
                'time' => $time,
                'date' => $date,
                'label' => $this->timeLabel($time),
                'in_progress' => $start->lessThanOrEqualTo($now),
                'just_finished' => $end->lessThanOrEqualTo($now),
            ];
        }

        return $times;
    }

    public function resultOnlyTimes(Package $room, string $date): array
    {
        if (!EscapeRoomSession::isAvailable()) {
            return [];
        }

        return EscapeRoomSession::where('package_id', $room->id)
            ->whereDate('session_date', $date)
            ->whereNotNull('completed_at')
            ->where(function ($query) {
                $query->whereNull('photo_session_id')
                    ->orWhereNotExists(fn ($deliveries) => $deliveries->selectRaw('1')
                        ->from('photo_deliveries')
                        ->whereColumn('photo_deliveries.photo_session_id', 'escape_room_sessions.photo_session_id')
                        ->where('photo_deliveries.kind', PhotoDelivery::KIND_ESCAPE_ROOM));
            })
            ->pluck('session_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->all();
    }

    public function gameLinkSignature(int $locationId, int $roomId, string $date, string $time): string
    {
        return substr(hash_hmac('sha256', "escape-room-game|{$locationId}|{$roomId}|{$date}|{$time}", (string) config('app.key')), 0, 24);
    }

    public function validGameSignature(int $locationId, int $roomId, string $date, string $time, mixed $signature): bool
    {
        return is_string($signature) && $signature !== ''
            && hash_equals($this->gameLinkSignature($locationId, $roomId, $date, $time), $signature);
    }

    public function linkedGame(Location $location, mixed $roomId, mixed $time, mixed $date, mixed $signature): ?array
    {
        $normalized = $this->normalizeTime($time);
        $room = is_numeric($roomId) ? $this->findRoom($location, (int) $roomId) : null;

        if (!$room || $normalized === null || !$this->templateForRoom($location, $room)) {
            return null;
        }

        if ($ahead = $this->aheadDate($location, $date)) {
            if (!$this->validGameSignature((int) $location->id, (int) $room->id, $ahead, $normalized, $signature)) {
                return null;
            }

            $times = array_values(array_filter($this->bookedGuestTimes($room, $ahead), fn (array $slot) => $slot['time'] === $normalized));

            return ['room' => $room, 'date' => $ahead, 'times' => $times, 'ahead' => true];
        }

        $yesterday = Carbon::parse($this->today($location))->subDay()->toDateString();

        if ($date === $yesterday) {
            $times = array_values(array_filter(
                $this->guestTimes($location, $room, self::SUBMIT_GRACE_MINUTES, $yesterday),
                fn (array $slot) => $slot['time'] === $normalized
            ));

            return $times === [] ? null : ['room' => $room, 'date' => $yesterday, 'times' => $times, 'ahead' => false];
        }

        return null;
    }

    public function aheadDate(Location $location, mixed $date): ?string
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date))) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', trim($date));
        } catch (\Throwable) {
            return null;
        }

        if (!$parsed || $parsed->toDateString() !== trim($date)) {
            return null;
        }

        $today = Carbon::parse($this->today($location));

        if ($parsed->lessThanOrEqualTo($today) || $parsed->greaterThan($today->copy()->addDays(self::SIGN_AHEAD_DAYS))) {
            return null;
        }

        return $parsed->toDateString();
    }

    public function bookedGuestTimes(Package $room, string $date): array
    {
        $times = array_values(array_unique(array_filter(array_map(fn ($time) => $this->normalizeTime($time), $this->bookedTimes($room, $date)))));
        sort($times);

        return array_map(fn (string $time) => [
            'time' => $time,
            'date' => $date,
            'label' => $this->timeLabel($time),
            'in_progress' => false,
            'just_finished' => false,
        ], $times);
    }

    public function resolveGuestChoice(Location $location, mixed $packageId, mixed $time, mixed $date = null, mixed $signature = null): array
    {
        $room = is_numeric($packageId) ? $this->findRoom($location, (int) $packageId) : null;

        if (!$room) {
            throw new EscapeRoomException('That room is not available. Please choose your room again.', 422, 'package_id');
        }

        $template = $this->templateForRoom($location, $room);

        if (!$template) {
            throw new EscapeRoomException('This room is not set up for check-in yet. Please see the front desk.', 422, 'package_id');
        }

        $ahead = $this->aheadDate($location, $date);

        if ($ahead !== null) {
            $normalized = $this->normalizeTime($time);
            $allowed = array_column($this->bookedGuestTimes($room, $ahead), 'time');

            if ($normalized === null || !in_array($normalized, $allowed, true)
                || !$this->validGameSignature((int) $location->id, (int) $room->id, $ahead, $normalized, $signature)) {
                throw new EscapeRoomException('We could not find a booking for that room and time. Please use the link in your booking email, or see the front desk.', 422, 'session_time');
            }

            return [
                'room' => $room,
                'template' => $template,
                'date' => $ahead,
                'time' => $normalized,
            ];
        }

        $today = $this->today($location);
        $gameDate = is_string($date) && trim($date) !== '' ? trim($date) : $today;
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        if ($gameDate !== $today && $gameDate !== $yesterday) {
            throw new EscapeRoomException('That time is not available for this room today. Please choose your time again.', 422, 'session_time');
        }

        $normalized = $this->normalizeTime($time);
        $allowed = array_column($this->guestTimes($location, $room, self::SUBMIT_GRACE_MINUTES, $gameDate), 'time');

        if ($normalized === null || !in_array($normalized, $allowed, true)) {
            throw new EscapeRoomException('That time is not available for this room today. Please choose your time again.', 422, 'session_time');
        }

        return [
            'room' => $room,
            'template' => $template,
            'date' => $gameDate,
            'time' => $normalized,
        ];
    }

    public function sessionFor(Package $room, string $date, string $time, ?int $userId = null): EscapeRoomSession
    {
        $room->loadMissing('location:id,company_id');
        $storedTime = $time . ':00';

        $find = fn () => EscapeRoomSession::where('package_id', $room->id)
            ->whereDate('session_date', $date)
            ->where('session_time', $storedTime)
            ->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return EscapeRoomSession::create([
                'company_id' => $room->location->company_id,
                'location_id' => $room->location_id,
                'package_id' => $room->id,
                'session_date' => $date,
                'session_time' => $storedTime,
                'created_by' => $userId,
            ]);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000' && ($existing = $find())) {
                return $existing;
            }

            throw $e;
        }
    }

    public function liveBookingsQuery(int $packageId, string $date, string $time)
    {
        return Booking::where('package_id', $packageId)
            ->where('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->whereRaw("TIME_FORMAT(booking_time, '%H:%i') = ?", [$time]);
    }

    public function soleBookingId(int $packageId, string $date, string $time): ?int
    {
        $ids = $this->liveBookingsQuery($packageId, $date, $time)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public function bookingMatches(?Booking $booking, EscapeRoomSession $session): bool
    {
        if (!$booking || $booking->status === 'cancelled') {
            return false;
        }

        return (int) $booking->package_id === (int) $session->package_id
            && $booking->booking_date?->toDateString() === $session->dateKey()
            && $this->bookingTime($booking) === $session->timeKey();
    }

    public function bookingTime(Booking $booking): ?string
    {
        $time = $booking->booking_time instanceof \DateTimeInterface
            ? $booking->booking_time->format('H:i')
            : substr((string) $booking->getRawOriginal('booking_time'), 0, 5);

        return $this->normalizeTime($time);
    }

    public function attachToSession(Waiver $waiver, EscapeRoomSession $session, bool $linkSoleBooking = true): Waiver
    {
        $session->loadMissing('package:id,name');

        $waiver->forceFill([
            'escape_room_session_id' => $session->id,
            'package_id' => $session->package_id,
            'selected_date' => $session->dateKey(),
            'manual_activity_name' => $waiver->manual_activity_name ?: $session->package?->name,
        ]);

        if ($linkSoleBooking && !$waiver->booking_id) {
            $waiver->booking_id = $this->soleBookingId((int) $session->package_id, $session->dateKey(), $session->timeKey());
        }

        $waiver->save();

        return $waiver;
    }

    public function attachSignedBookingWaiver(Waiver $waiver): void
    {
        if (!$this->isEnabled() || $waiver->escape_room_session_id || !$waiver->booking_id) {
            return;
        }

        $waiver->loadMissing('template', 'booking.package');

        if (!$waiver->template?->isEscapeRoom()) {
            return;
        }

        $booking = $waiver->booking;
        $room = $booking?->package;

        if (!$booking || $booking->status === 'cancelled' || !$room?->isEscapeRoom()) {
            return;
        }

        $time = $this->bookingTime($booking);

        if (!$time || !$booking->booking_date) {
            return;
        }

        $session = $this->sessionFor($room, $booking->booking_date->toDateString(), $time);
        $this->attachToSession($waiver, $session, false);
    }

    public function followBooking(Booking $booking): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $waivers = Waiver::withoutHeavyColumns()
            ->where('booking_id', $booking->id)
            ->whereNotNull('escape_room_session_id')
            ->get();

        if ($waivers->isEmpty()) {
            return;
        }

        $booking->loadMissing('package');
        $room = $booking->package;
        $time = $this->bookingTime($booking);

        if ($booking->status === 'cancelled' || !$room?->isEscapeRoom() || !$time || !$booking->booking_date) {
            return;
        }

        $target = $this->sessionFor($room, $booking->booking_date->toDateString(), $time);

        foreach ($waivers as $waiver) {
            $from = DB::transaction(function () use ($waiver, $target) {
                $current = $waiver->escape_room_session_id;

                if ($current) {
                    EscapeRoomSession::whereKey($current)->lockForUpdate()->first();
                }

                $waiver->refresh();

                if ((int) $waiver->escape_room_session_id !== (int) $current
                    || (int) $waiver->escape_room_session_id === (int) $target->id
                    || $this->wasSent($waiver)) {
                    return false;
                }

                $this->attachToSession($waiver, $target, false);

                return $current;
            });

            if ($from === false) {
                continue;
            }

            ActivityLog::log(
                'escape_room_waiver_followed_booking',
                'photos',
                sprintf('Moved waiver %s to the new time of booking %s', $waiver->reference_number ?? ('#' . $waiver->id), $booking->reference_number),
                null,
                $booking->location_id,
                'waiver',
                $waiver->id,
                ['from_escape_room_session_id' => $from, 'to_escape_room_session_id' => $target->id, 'booking_id' => $booking->id]
            );
        }
    }

    public function releaseDeletedBooking(int $bookingId): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $released = 0;
        $waivers = Waiver::withoutHeavyColumns()
            ->where('booking_id', $bookingId)
            ->whereNotNull('escape_room_session_id')
            ->get()
            ->reject(fn (Waiver $waiver) => $waiver->isEscapeRoomSignIn());

        foreach ($waivers as $waiver) {
            $from = DB::transaction(function () use ($waiver) {
                $current = $waiver->escape_room_session_id;
                EscapeRoomSession::whereKey($current)->lockForUpdate()->first();
                $waiver->refresh();

                if ((int) $waiver->escape_room_session_id !== (int) $current || $this->wasSent($waiver)) {
                    return null;
                }

                $waiver->forceFill(['escape_room_session_id' => null])->save();

                return $current;
            });

            if ($from === null) {
                continue;
            }

            $released++;

            ActivityLog::log(
                'escape_room_waiver_released_deleted_booking',
                'photos',
                sprintf('Took waiver %s out of escape-room game #%d because its booking was permanently deleted', $waiver->reference_number ?? ('#' . $waiver->id), $from),
                auth()->id(),
                $waiver->location_id,
                'waiver',
                $waiver->id,
                ['escape_room_session_id' => $from, 'booking_id' => $bookingId]
            );
        }

        return $released;
    }

    public function wasSent(Waiver $waiver): bool
    {
        return PhotoDelivery::where('waiver_id', $waiver->id)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
            ->exists();
    }

    protected function membersQuery()
    {
        return Waiver::withoutHeavyColumns()
            ->with([
                'minors:id,waiver_id',
                'booking' => fn ($query) => $query->select(['id', 'reference_number', 'package_id', 'location_id', 'booking_date', 'booking_time', 'status', 'guest_name', 'customer_id']),
            ])
            ->where('status', Waiver::STATUS_COMPLETED);
    }

    public function bookingWaiversWithoutGame(array $bookingIds): Collection
    {
        if ($bookingIds === []) {
            return collect();
        }

        return $this->membersQuery()
            ->whereIn('booking_id', $bookingIds)
            ->whereNull('escape_room_session_id')
            ->get();
    }

    public function membership(EscapeRoomSession $session): array
    {
        $attached = $this->membersQuery()
            ->where('escape_room_session_id', $session->id)
            ->get();

        $bookingIds = $this->liveBookingsQuery((int) $session->package_id, $session->dateKey(), $session->timeKey())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $waivers = $attached
            ->concat($this->bookingWaiversWithoutGame($bookingIds))
            ->unique('id')
            ->sortBy(fn (Waiver $waiver) => sprintf('%020d|%020d', $waiver->submitted_at?->getTimestamp() ?? 0, $waiver->id))
            ->values();

        $included = collect();
        $excluded = collect();

        foreach ($waivers as $waiver) {
            $reason = $this->exclusionReason($waiver, $session);

            if ($reason === null) {
                $included->push($waiver);
            } else {
                $waiver->setAttribute('escape_room_excluded_reason', $reason);
                $excluded->push($waiver);
            }
        }

        return ['included' => $included, 'excluded' => $excluded];
    }

    protected function exclusionReason(Waiver $waiver, EscapeRoomSession $session): ?string
    {
        if ((int) $waiver->company_id !== (int) $session->company_id || (int) $waiver->location_id !== (int) $session->location_id) {
            return self::EXCLUDED_OTHER_LOCATION;
        }

        if (!$waiver->booking_id) {
            return null;
        }

        $booking = $waiver->booking;

        if (!$booking) {
            return self::EXCLUDED_BOOKING_REMOVED;
        }
        if ($booking->status === 'cancelled') {
            return self::EXCLUDED_BOOKING_CANCELLED;
        }

        return $this->bookingMatches($booking, $session) ? null : self::EXCLUDED_BOOKING_MOVED;
    }

    public function sentWaiverIds(?PhotoSession $photoSession): array
    {
        if (!$photoSession) {
            return [];
        }

        return PhotoDelivery::where('photo_session_id', $photoSession->id)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
            ->whereNotNull('waiver_id')
            ->pluck('waiver_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function pinToGame(Collection $waivers, EscapeRoomSession $session): void
    {
        $ids = $waivers
            ->filter(fn (Waiver $waiver) => $waiver->escape_room_session_id === null)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        Waiver::whereIn('id', $ids)
            ->whereNull('escape_room_session_id')
            ->update(['escape_room_session_id' => $session->id]);
    }

    public function unsentRecipients(EscapeRoomSession $session, ?PhotoSession $photoSession): Collection
    {
        $sent = $this->sentWaiverIds($photoSession);

        return $this->membership($session)['included']
            ->reject(fn (Waiver $waiver) => in_array((int) $waiver->id, $sent, true))
            ->values();
    }

    public function ensurePhotoSession(EscapeRoomSession $session, User $user, bool $verbalConsent): EscapeRoomSession
    {
        if (!$verbalConsent) {
            throw new EscapeRoomException('Confirm that the group agreed to have their photo taken.');
        }

        return DB::transaction(function () use ($session, $user) {
            $locked = EscapeRoomSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->photo_session_id && PhotoSession::whereKey($locked->photo_session_id)->whereNull('purged_at')->exists()) {
                return $locked;
            }

            if ($locked->completed_at !== null) {
                throw new EscapeRoomException('This game is already complete, so a photo can no longer be added.', 409);
            }

            $locked->loadMissing('location', 'package:id,name');
            $now = now();

            $photoSession = PhotoSession::create([
                'company_id' => $locked->company_id,
                'location_id' => $locked->location_id,
                'source' => PhotoSession::SOURCE_STAFF,
                'status' => PhotoSession::STATUS_IN_PROGRESS,
                'created_by' => $user->id,
                'verbal_consent_at' => $now,
                'captured_at' => null,
                'capture_date' => OperatingDay::calendarDateFor($locked->location, $now),
                'operating_day' => OperatingDay::forLocation($locked->location, $now),
            ]);

            $locked->forceFill(['photo_session_id' => $photoSession->id])->save();

            ActivityLog::log(
                'photo_session_started',
                'photos',
                sprintf(
                    'Started the group photo for %s at %s after confirming verbal consent',
                    $locked->package?->name ?? 'an escape room',
                    $this->timeLabel($locked->timeKey())
                ),
                $user->id,
                $locked->location_id,
                'photo_session',
                $photoSession->id,
                ['escape_room_session_id' => $locked->id]
            );

            return $locked;
        });
    }

    public function complete(EscapeRoomSession $session, bool $escaped, ?int $seconds, User $user, bool $withoutPhoto = false): EscapeRoomSession
    {
        if ($session->isCompleted()) {
            throw new EscapeRoomException('This game is already complete and its photo has been sent.', 409);
        }

        if ($escaped && ($seconds === null || $seconds < 1 || $seconds > self::MAX_COMPLETION_SECONDS)) {
            throw new EscapeRoomException('Enter the time the group finished in minutes and seconds, for example 47:12.');
        }

        if ($withoutPhoto) {
            return $this->completeWithoutPhoto($session, $escaped, $seconds, $user);
        }

        $photoSession = $this->readyPhotoSession($session);

        if (!$this->deliveries->emailAvailable()) {
            throw new EscapeRoomException('Email is not switched on for this site yet, so the photo cannot be sent. Ask your administrator to enable it.');
        }

        try {
            $result = DB::transaction(function () use ($session, $photoSession, $escaped, $seconds, $user) {
                $locked = EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();

                if (!$locked || $locked->completed_at !== null) {
                    throw new EscapeRoomException('This game is already complete and its photo has been sent.', 409);
                }

                $recipients = $this->unsentRecipients($locked, $photoSession);

                if ($recipients->isEmpty()) {
                    throw new EscapeRoomException('Nobody has signed a waiver for this game yet, so there is no one to send the photo to.');
                }

                if ($recipients->every(fn (Waiver $waiver) => !$this->deliveries->validEmail($waiver->adult_email))) {
                    throw new EscapeRoomException('None of the players in this game has an email address on their waiver, so the photo cannot be emailed.');
                }

                $this->pinToGame($recipients, $locked);
                $this->checkInPlayers($this->membership($locked)['included'], $user);

                $locked->forceFill([
                    'escaped' => $escaped,
                    'completion_seconds' => $escaped ? $seconds : null,
                    'completed_at' => now(),
                    'completed_by' => $user->id,
                ])->save();

                if (!$photoSession->accessIsActive()) {
                    $photoSession->startQrWindow();
                    $photoSession->save();
                }

                return $this->deliveries->createEscapeRoomDeliveries($photoSession, $recipients, $user->id)
                    + ['waiver_ids' => $recipients->pluck('id')->all()];
            });
        } catch (EscapeRoomException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Escape-room photo send could not be prepared', [
                'escape_room_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            throw new EscapeRoomException('The photo could not be sent. Please try again.', 500);
        }

        $this->allowLongSend();
        $sent = $this->deliveries->sendEscapeRoomDeliveries($photoSession->fresh(), $result['deliveries']);
        $session = $session->fresh(['package:id,name']);

        ActivityLog::log(
            'escape_room_session_completed',
            'photos',
            sprintf(
                'Completed %s at %s (%s) and sent the group photo to %d player(s)',
                $session->package?->name ?? 'an escape room',
                $this->timeLabel($session->timeKey()),
                $session->completionLabel(),
                count($result['deliveries'])
            ),
            $user->id,
            $session->location_id,
            'escape_room_session',
            $session->id,
            [
                'photo_session_id' => $photoSession->id,
                'waiver_ids' => $result['waiver_ids'],
                'deliveries_created' => count($result['deliveries']),
                'deliveries_sent' => $sent,
                'duplicates' => $result['duplicates'],
                'no_email_waiver_ids' => $result['skipped_waiver_ids'],
                'escaped' => $escaped,
                'completion_seconds' => $escaped ? $seconds : null,
            ]
        );

        return $session;
    }

    protected function completeWithoutPhoto(EscapeRoomSession $session, bool $escaped, ?int $seconds, User $user): EscapeRoomSession
    {
        $waiverIds = DB::transaction(function () use ($session, $escaped, $seconds, $user) {
            $locked = EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();

            if (!$locked || $locked->completed_at !== null) {
                throw new EscapeRoomException('This game is already complete.', 409);
            }

            $locked->loadMissing('location');

            if ($locked->location && $locked->dateKey() > $this->today($locked->location)) {
                throw new EscapeRoomException('This game is on a later day. Record its result after it has been played.');
            }

            if ($locked->photo_session_id && \App\Models\Photo::where('photo_session_id', $locked->photo_session_id)->exists()) {
                throw new EscapeRoomException('This game has a group photo. Send it with Complete & Send, or remove the photo first.');
            }

            $players = $this->membership($locked)['included'];

            if ($players->isEmpty() && !$this->liveBookingsQuery((int) $locked->package_id, $locked->dateKey(), $locked->timeKey())->exists()) {
                throw new EscapeRoomException('Nobody booked or signed for this game, so there is no result to record.');
            }

            $this->pinToGame($players, $locked);
            $this->checkInPlayers($players, $user);

            $locked->forceFill([
                'escaped' => $escaped,
                'completion_seconds' => $escaped ? $seconds : null,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();

            return $players->pluck('id')->all();
        });

        $session = $session->fresh(['package:id,name']);

        ActivityLog::log(
            'escape_room_session_completed_without_photo',
            'photos',
            sprintf(
                'Recorded the result of %s at %s (%s) without a group photo. No email was sent.',
                $session->package?->name ?? 'an escape room',
                $this->timeLabel($session->timeKey()),
                $session->completionLabel()
            ),
            $user->id,
            $session->location_id,
            'escape_room_session',
            $session->id,
            [
                'waiver_ids' => $waiverIds,
                'escaped' => $escaped,
                'completion_seconds' => $escaped ? $seconds : null,
            ]
        );

        return $session;
    }

    public function sendToNewPlayers(EscapeRoomSession $session, User $user): EscapeRoomSession
    {
        if (!$session->isCompleted()) {
            throw new EscapeRoomException('Complete the game first. That sends the photo to everyone who has signed.');
        }

        $photoSession = $this->readyPhotoSession($session);

        if (!$photoSession->accessIsActive()) {
            throw new EscapeRoomException('The photo link for this game has expired, so it cannot be sent again.');
        }

        if (!$this->deliveries->emailAvailable()) {
            throw new EscapeRoomException('Email is not switched on for this site yet, so the photo cannot be sent. Ask your administrator to enable it.');
        }

        $stuck = $this->claimStuckDeliveries($photoSession);

        $result = DB::transaction(function () use ($session, $photoSession, $user, $stuck) {
            EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();

            $unsent = $this->unsentRecipients($session, $photoSession);
            $recipients = $unsent
                ->filter(fn (Waiver $waiver) => $this->deliveries->validEmail($waiver->adult_email))
                ->values();
            $this->checkInPlayers($unsent, $user);

            if ($recipients->isEmpty() && $stuck === []) {
                throw new EscapeRoomException('Everyone in this game with an email address has already been sent the photo.', 409);
            }

            $this->pinToGame($recipients, $session);

            $created = $recipients->isEmpty()
                ? ['deliveries' => [], 'duplicates' => 0, 'skipped_waiver_ids' => []]
                : $this->deliveries->createEscapeRoomDeliveries($photoSession, $recipients, $user->id);

            return $created + ['waiver_ids' => $recipients->pluck('id')->all()];
        });

        $this->allowLongSend();
        $sent = $this->deliveries->sendEscapeRoomDeliveries($photoSession->fresh(), array_merge($stuck, $result['deliveries']));

        ActivityLog::log(
            'escape_room_session_sent_to_new',
            'photos',
            sprintf('Sent the escape-room group photo from game #%d to %d more player(s)', $session->id, count($result['deliveries']) + count($stuck)),
            $user->id,
            $session->location_id,
            'escape_room_session',
            $session->id,
            [
                'waiver_ids' => $result['waiver_ids'],
                'deliveries_created' => count($result['deliveries']),
                'deliveries_resumed' => array_map(fn (PhotoDelivery $delivery) => $delivery->id, $stuck),
                'deliveries_sent' => $sent,
                'duplicates' => $result['duplicates'],
            ]
        );

        return $session->fresh();
    }

    public function slideshowRelease(EscapeRoomSession $session): array
    {
        $players = $this->membership($session)['included'];

        return [
            'players' => $players->count(),
            'declined' => $players->filter(fn (Waiver $waiver) => $waiver->photo_video_consent === false)->count(),
            'not_asked' => $players->filter(fn (Waiver $waiver) => $waiver->photo_video_consent === null)->count(),
        ];
    }

    public function withdrawSlideshowForDecliner(Waiver $waiver): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $games = collect();

            if ($waiver->escape_room_session_id) {
                $games->push(EscapeRoomSession::find($waiver->escape_room_session_id));
            }

            $booking = $waiver->booking_id ? Booking::find($waiver->booking_id) : null;
            $time = $booking ? $this->bookingTime($booking) : null;

            if ($booking && $time && $booking->booking_date) {
                $games->push(EscapeRoomSession::where('package_id', $booking->package_id)
                    ->whereDate('session_date', $booking->booking_date->toDateString())
                    ->where('session_time', $time . ':00')
                    ->first());
            }

            foreach ($games->filter()->unique('id') as $game) {
                if (!$game->photo_session_id) {
                    continue;
                }

                $onScreen = Photo::where('photo_session_id', $game->photo_session_id)
                    ->where('slideshow_eligible', true)
                    ->pluck('id')
                    ->all();

                if ($onScreen === []) {
                    continue;
                }

                $isPlayer = $this->membership($game)['included']->contains(fn (Waiver $member) => (int) $member->id === (int) $waiver->id);

                if (!$isPlayer) {
                    continue;
                }

                Photo::whereIn('id', $onScreen)->update([
                    'slideshow_eligible' => false,
                    'slideshow_state' => Photo::SLIDESHOW_REMOVED,
                    'slideshow_queue_id' => null,
                ]);

                ActivityLog::log(
                    'slideshow_photo_withdrawn',
                    'photos',
                    sprintf('Took %d escape-room photo(s) off the venue slideshow because a player in that game declined the photo release', count($onScreen)),
                    null,
                    $game->location_id,
                    'escape_room_session',
                    $game->id,
                    ['photo_ids' => $onScreen, 'waiver_id' => $waiver->id]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Escape-room slideshow photos could not be checked after a declined photo release', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function checkInPlayers(Collection $waivers, User $user): void
    {
        $checkIns = app(WaiverCheckInService::class);

        foreach ($waivers as $waiver) {
            if ($waiver->checked_in_at) {
                continue;
            }

            try {
                $fresh = Waiver::withoutHeavyColumns()->find($waiver->id);

                if ($fresh) {
                    $checkIns->stamp($fresh, $user);
                }
            } catch (\Throwable $e) {
                Log::warning('Escape-room player could not be checked in', ['waiver_id' => $waiver->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function resendToPlayer(EscapeRoomSession $session, Waiver $waiver, ?string $email, User $user): EscapeRoomSession
    {
        if (!$session->isCompleted()) {
            throw new EscapeRoomException('Complete the game first. That sends the photo to everyone who has signed.');
        }

        $photoSession = $this->readyPhotoSession($session);

        if (!$photoSession->accessIsActive()) {
            throw new EscapeRoomException('The photo link for this game has expired, so it cannot be sent again.');
        }

        if (!$this->deliveries->emailAvailable()) {
            throw new EscapeRoomException('Email is not switched on for this site yet, so the photo cannot be sent. Ask your administrator to enable it.');
        }

        $lastSent = PhotoDelivery::where('photo_session_id', $photoSession->id)
            ->where('waiver_id', $waiver->id)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->orderByDesc('id')
            ->value('destination');
        $destination = strtolower(trim((string) ($email !== null && trim($email) !== '' ? $email : ($lastSent ?: $waiver->adult_email))));

        if (!$this->deliveries->validEmail($destination)) {
            throw new EscapeRoomException('Enter a valid email address to send the photo to.', 422, 'email');
        }

        $delivery = DB::transaction(function () use ($session, $photoSession, $waiver, $destination, $user) {
            EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();

            $isPlayer = $this->membership($session)['included']->contains(fn (Waiver $member) => (int) $member->id === (int) $waiver->id);

            if (!$isPlayer) {
                throw new EscapeRoomException('That player is not part of this game.', 404);
            }

            $this->pinToGame(collect([$waiver]), $session);
            $this->checkInPlayers(collect([$waiver]), $user);

            return PhotoDelivery::create([
                'photo_session_id' => $photoSession->id,
                'company_id' => $photoSession->company_id,
                'location_id' => $photoSession->location_id,
                'waiver_id' => $waiver->id,
                'kind' => PhotoDelivery::KIND_ESCAPE_ROOM,
                'channel' => PhotoDelivery::CHANNEL_EMAIL,
                'destination' => $destination,
                'recipient_name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
                'status' => PhotoDelivery::STATUS_QUEUED,
                'created_by' => $user->id,
            ]);
        });

        $this->allowLongSend();
        $this->deliveries->sendEscapeRoomDeliveries($photoSession->fresh(), [$delivery]);

        ActivityLog::log(
            'escape_room_photo_resent',
            'photos',
            sprintf(
                'Resent the escape-room group photo from game #%d to %s%s',
                $session->id,
                $delivery->maskedDestination(),
                strtolower(trim((string) $waiver->adult_email)) === $destination ? '' : ' (a different address from the waiver)'
            ),
            $user->id,
            $session->location_id,
            'waiver',
            $waiver->id,
            ['escape_room_session_id' => $session->id, 'photo_delivery_id' => $delivery->id, 'changed_address' => strtolower(trim((string) $waiver->adult_email)) !== $destination]
        );

        return $session->fresh();
    }

    public function correctResult(EscapeRoomSession $session, bool $escaped, ?int $seconds, User $user): EscapeRoomSession
    {
        if (!$session->isCompleted()) {
            throw new EscapeRoomException('This game has not been completed yet. Enter the time when you press Complete & Send.');
        }

        if ($escaped && ($seconds === null || $seconds < 1 || $seconds > self::MAX_COMPLETION_SECONDS)) {
            throw new EscapeRoomException('Enter the time the group finished in minutes and seconds, for example 47:12.');
        }

        $before = $session->completionLabel();

        $session->forceFill([
            'escaped' => $escaped,
            'completion_seconds' => $escaped ? $seconds : null,
        ])->save();

        ActivityLog::log(
            'escape_room_result_corrected',
            'photos',
            sprintf('Corrected the recorded result of escape-room game #%d from %s to %s', $session->id, $before, $session->completionLabel()),
            $user->id,
            $session->location_id,
            'escape_room_session',
            $session->id,
            ['before' => $before, 'after' => $session->completionLabel()]
        );

        return $session->fresh();
    }

    public function checkInLinkForBooking($booking): string
    {
        try {
            $booking->loadMissing(['location', 'package']);
            $room = $booking->package;

            if (!$this->isEnabled() || !$room?->isEscapeRoom() || !$booking->location || !$this->templateForRoom($booking->location, $room)) {
                return '';
            }

            $date = $booking->booking_date?->toDateString();
            $time = $this->bookingTime($booking);

            return $date && $time
                ? $this->gameCheckInUrl($booking->location, (int) $room->id, $date, $time)
                : $this->kioskUrl($booking->location);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function bookingGameSummary(Booking $booking): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $booking->loadMissing('package', 'location');
        $room = $booking->package;
        $time = $this->bookingTime($booking);

        if (!$room?->isEscapeRoom() || !$booking->location || !$booking->booking_date || !$time) {
            return null;
        }

        $date = $booking->booking_date->toDateString();
        $session = EscapeRoomSession::where('package_id', $room->id)
            ->whereDate('session_date', $date)
            ->where('session_time', $time . ':00')
            ->first();
        $photoSession = $session?->photo_session_id ? PhotoSession::whereKey($session->photo_session_id)->first() : null;
        $members = $session
            ? $this->membership($session)['included']
            : $this->bookingWaiversWithoutGame([(int) $booking->id]);
        $players = $members->count();
        $people = $members->sum(fn (Waiver $waiver) => 1 + ($waiver->relationLoaded('minors') ? $waiver->minors->count() : $waiver->minors()->count()));
        $withoutPhoto = $session?->isCompleted()
            && !($photoSession && PhotoDelivery::where('photo_session_id', $photoSession->id)->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)->exists());

        return [
            'session_id' => $session?->id,
            'room_id' => $room->id,
            'room_name' => $room->name,
            'date' => $date,
            'time' => $time,
            'time_label' => $this->timeLabel($time),
            'has_waiver' => $this->templateForRoom($booking->location, $room) !== null,
            'players_signed' => $players,
            'people_covered' => (int) $people,
            'players_booked' => (int) $booking->participants,
            'shared_time' => $this->liveBookingsQuery((int) $room->id, $date, $time)->count() > 1,
            'photo_taken' => $photoSession !== null && $photoSession->photos()->ready()->exists(),
            'completed' => $session?->isCompleted() ?? false,
            'completed_without_photo' => (bool) $withoutPhoto,
            'completion_label' => $session?->completionLabel() ?? '',
            'sent' => count($this->sentWaiverIds($photoSession)),
            'booking_cancelled' => $booking->status === 'cancelled',
            'kiosk_url' => $this->gameCheckInUrl($booking->location, (int) $room->id, $date, $time),
        ];
    }

    public function describeWaiverGame(?EscapeRoomSession $session): ?array
    {
        if (!$session) {
            return null;
        }

        $session->loadMissing('package:id,name');

        return [
            'session_id' => $session->id,
            'room_name' => $session->package?->name,
            'date' => $session->dateKey(),
            'time' => $session->timeKey(),
            'time_label' => $this->timeLabel($session->timeKey()),
            'completed' => $session->isCompleted(),
            'completion_label' => $session->completionLabel(),
        ];
    }

    public function stuckDeliveriesQuery(PhotoSession $photoSession)
    {
        return PhotoDelivery::where('photo_session_id', $photoSession->id)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('status', PhotoDelivery::STATUS_QUEUED)
            ->whereNull('duplicate_of_id')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES));
    }

    protected function claimStuckDeliveries(PhotoSession $photoSession): array
    {
        $claimed = [];

        foreach ($this->stuckDeliveriesQuery($photoSession)->get() as $delivery) {
            $taken = $this->deliveries->claimForSending($delivery);

            if ($taken) {
                $claimed[] = $taken;
            }
        }

        return $claimed;
    }

    protected function allowLongSend(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
    }

    public function removeWaiver(Waiver $waiver, EscapeRoomSession $session, User $user): void
    {
        $previousBookingId = DB::transaction(function () use ($waiver, $session) {
            EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();
            $waiver->refresh();

            if ((int) $waiver->escape_room_session_id !== (int) $session->id) {
                if ($waiver->escape_room_session_id === null
                    && $waiver->booking_id
                    && !$waiver->isEscapeRoomSignIn()
                    && $this->liveBookingsQuery((int) $session->package_id, $session->dateKey(), $session->timeKey())->whereKey($waiver->booking_id)->exists()) {
                    throw new EscapeRoomException("This is the booking's own waiver, so it stays with its booking's game. To leave it out, change or cancel the booking.");
                }

                throw new EscapeRoomException('That player is not part of this game.', 404);
            }

            if ($this->wasSent($waiver)) {
                throw new EscapeRoomException('This player has already been sent the photo for this game, so they cannot be removed.');
            }

            if (!$waiver->isEscapeRoomSignIn() && $waiver->booking_id) {
                throw new EscapeRoomException("This is the booking's own waiver, so it stays with its booking's game. To leave it out, change or cancel the booking.");
            }

            $previous = $waiver->booking_id;
            $changes = ['escape_room_session_id' => null];

            if ($waiver->booking_id) {
                $changes['booking_id'] = null;
            }

            $waiver->forceFill($changes)->save();

            return $previous;
        });

        ActivityLog::log(
            'escape_room_waiver_removed',
            'photos',
            sprintf('Removed waiver %s from escape-room game #%d', $waiver->reference_number ?? ('#' . $waiver->id), $session->id),
            $user->id,
            $session->location_id,
            'waiver',
            $waiver->id,
            ['escape_room_session_id' => $session->id, 'previous_booking_id' => $previousBookingId]
        );
    }

    public function moveWaiver(Waiver $waiver, EscapeRoomSession $from, int $packageId, mixed $time, User $user): EscapeRoomSession
    {
        if ((int) $waiver->escape_room_session_id !== (int) $from->id) {
            throw new EscapeRoomException('That player is not part of this game.', 404);
        }

        if (!$waiver->isEscapeRoomSignIn() && $waiver->booking_id) {
            throw new EscapeRoomException("This is the booking's own waiver. Change the booking's time instead, and its players move with it.");
        }

        $from->loadMissing('location');
        $room = $this->findRoom($from->location, $packageId, false);

        if (!$room) {
            throw new EscapeRoomException('Choose an escape room at this location.');
        }

        $normalized = $this->normalizeTime($time);

        if ($normalized === null || !in_array($normalized, $this->timesForDay($room, $from->dateKey()), true)) {
            throw new EscapeRoomException('That time is not on the schedule for this room on this day.');
        }

        $target = $this->sessionFor($room, $from->dateKey(), $normalized, $user->id);

        if ((int) $target->id === (int) $from->id) {
            throw new EscapeRoomException('That player is already in this game.');
        }

        $previousBookingId = DB::transaction(function () use ($waiver, $from, $target) {
            EscapeRoomSession::whereKey($from->id)->lockForUpdate()->first();
            $waiver->refresh();

            if ((int) $waiver->escape_room_session_id !== (int) $from->id) {
                throw new EscapeRoomException('That player is not part of this game.', 404);
            }

            if ($this->wasSent($waiver)) {
                throw new EscapeRoomException('This player has already been sent the photo for this game, so they cannot be moved.');
            }

            $previous = $waiver->booking_id;

            if ($waiver->booking_id) {
                $booking = Booking::find($waiver->booking_id);

                if (!$this->bookingMatches($booking, $target)) {
                    $waiver->booking_id = null;
                }
            }

            $this->attachToSession($waiver, $target);

            return $previous;
        });

        ActivityLog::log(
            'escape_room_waiver_moved',
            'photos',
            sprintf(
                'Moved waiver %s from game #%d to %s at %s',
                $waiver->reference_number ?? ('#' . $waiver->id),
                $from->id,
                $room->name,
                $this->timeLabel($normalized)
            ),
            $user->id,
            $from->location_id,
            'waiver',
            $waiver->id,
            [
                'from_escape_room_session_id' => $from->id,
                'to_escape_room_session_id' => $target->id,
                'previous_booking_id' => $previousBookingId,
                'booking_id' => $waiver->booking_id,
            ]
        );

        return $target;
    }

    public function linkWaiverToBooking(Waiver $waiver, EscapeRoomSession $session, ?int $bookingId, User $user): void
    {
        if ((int) $waiver->escape_room_session_id !== (int) $session->id) {
            throw new EscapeRoomException('That player is not part of this game.', 404);
        }

        if (!$waiver->isEscapeRoomSignIn()) {
            throw new EscapeRoomException("This is the booking's own waiver, so it stays with its booking.");
        }

        if ($bookingId !== null) {
            $inGame = $this->liveBookingsQuery((int) $session->package_id, $session->dateKey(), $session->timeKey())
                ->whereKey($bookingId)
                ->exists();

            if (!$inGame) {
                throw new EscapeRoomException('That booking is not part of this game.');
            }
        }

        $previous = DB::transaction(function () use ($waiver, $session, $bookingId) {
            EscapeRoomSession::whereKey($session->id)->lockForUpdate()->first();
            $waiver->refresh();

            if ((int) $waiver->escape_room_session_id !== (int) $session->id) {
                throw new EscapeRoomException('That player is not part of this game.', 404);
            }

            if ($this->wasSent($waiver)) {
                throw new EscapeRoomException('This player has already been sent the photo for this game.');
            }

            $previous = $waiver->booking_id;
            $waiver->forceFill(['booking_id' => $bookingId])->save();

            return $previous;
        });

        ActivityLog::log(
            'escape_room_waiver_booking_linked',
            'photos',
            sprintf('Linked waiver %s to %s', $waiver->reference_number ?? ('#' . $waiver->id), $bookingId ? 'booking #' . $bookingId : 'no booking'),
            $user->id,
            $session->location_id,
            'waiver',
            $waiver->id,
            ['escape_room_session_id' => $session->id, 'previous_booking_id' => $previous, 'booking_id' => $bookingId]
        );
    }

    protected function readyPhotoSession(EscapeRoomSession $session): PhotoSession
    {
        $photoSession = $session->photo_session_id
            ? PhotoSession::whereKey($session->photo_session_id)->whereNull('purged_at')->first()
            : null;

        if (!$photoSession || $photoSession->photos()->ready()->count() === 0) {
            throw new EscapeRoomException('Take or upload the group photo first.');
        }

        if ($photoSession->verbal_consent_at === null) {
            throw new EscapeRoomException('Confirm that the group agreed to have their photo taken.');
        }

        return $photoSession;
    }

    protected function endTime(string $time, int $minutes): string
    {
        [$hours, $mins] = array_map('intval', explode(':', $time));
        $end = min(($hours * 60) + $mins + $minutes, (23 * 60) + 59);

        return sprintf('%02d:%02d', intdiv($end, 60), $end % 60);
    }

    public function kioskUrl(Location $location): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/waiver/escape-room/' . $location->id;
    }

    public function gameCheckInUrl(Location $location, int $roomId, string $date, string $time): string
    {
        return $this->kioskUrl($location) . '?room=' . $roomId . '&time=' . $time . '&date=' . $date
            . '&sig=' . $this->gameLinkSignature((int) $location->id, $roomId, $date, $time);
    }

    protected function unsentEarlier(Location $location, array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $today = $this->today($location);
        $from = Carbon::parse($today)->subDays(self::UNSENT_LOOKBACK_DAYS)->toDateString();

        return EscapeRoomSession::with('package:id,name')
            ->whereIn('package_id', $roomIds)
            ->whereNull('completed_at')
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<', $today)
            ->where(function ($query) {
                $query->whereNotNull('photo_session_id')
                    ->orWhereExists(fn ($waivers) => $waivers->selectRaw('1')
                        ->from('waivers')
                        ->whereColumn('waivers.escape_room_session_id', 'escape_room_sessions.id')
                        ->where('waivers.status', Waiver::STATUS_COMPLETED)
                        ->whereNull('waivers.deleted_at'));
            })
            ->orderBy('session_date')
            ->orderBy('session_time')
            ->limit(20)
            ->get()
            ->map(fn (EscapeRoomSession $session) => [
                'session_id' => $session->id,
                'date' => $session->dateKey(),
                'time' => $session->timeKey(),
                'time_label' => $this->timeLabel($session->timeKey()),
                'room_name' => $session->package?->name,
                'players' => $this->membership($session)['included']->count(),
                'has_photo' => $session->photo_session_id !== null,
            ])
            ->filter(fn (array $game) => $game['players'] > 0 || $game['has_photo'])
            ->values()
            ->all();
    }

    public function daySummary(Location $location, string $date): array
    {
        $rooms = $this->roomsAt($location, false);
        $roomIds = $rooms->pluck('id')->all();
        $isToday = $date === $this->today($location);
        $now = OperatingDay::localNow($location);
        $tz = OperatingDay::timezoneFor($location);

        $bookings = $roomIds === [] ? collect() : Booking::with('customer:id,first_name,last_name')
            ->whereIn('package_id', $roomIds)
            ->where('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'reference_number', 'package_id', 'location_id', 'customer_id', 'guest_name', 'booking_time', 'participants', 'status']);

        $bookingsBySlot = $bookings->groupBy(fn (Booking $booking) => $booking->package_id . '|' . $this->bookingTime($booking));

        $sessions = $roomIds === [] ? collect() : EscapeRoomSession::whereIn('package_id', $roomIds)
            ->whereDate('session_date', $date)
            ->get()
            ->keyBy(fn (EscapeRoomSession $session) => $session->package_id . '|' . $session->timeKey());

        $sessionIds = $sessions->pluck('id')->all();

        $signedCounts = $this->signedCountsBySlot($sessions, $bookings);

        $pendingCounts = $bookings->isEmpty() ? collect() : Waiver::whereIn('booking_id', $bookings->pluck('id'))
            ->where('status', Waiver::STATUS_PENDING)
            ->selectRaw('booking_id, COUNT(*) as total')
            ->groupBy('booking_id')
            ->pluck('total', 'booking_id');

        $photoSessionIds = $sessions->pluck('photo_session_id')->filter()->all();

        $readyPhotos = $photoSessionIds === [] ? collect() : \App\Models\Photo::whereIn('photo_session_id', $photoSessionIds)
            ->ready()
            ->selectRaw('photo_session_id, COUNT(*) as total')
            ->groupBy('photo_session_id')
            ->pluck('total', 'photo_session_id');

        $sentCounts = $photoSessionIds === [] ? collect() : PhotoDelivery::whereIn('photo_session_id', $photoSessionIds)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
            ->whereNotNull('waiver_id')
            ->selectRaw('photo_session_id, COUNT(DISTINCT waiver_id) as total')
            ->groupBy('photo_session_id')
            ->pluck('total', 'photo_session_id');

        $problemCounts = [];

        if ($photoSessionIds !== []) {
            $latest = PhotoDelivery::whereIn('photo_session_id', $photoSessionIds)
                ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
                ->whereNull('duplicate_of_id')
                ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
                ->orderBy('id')
                ->get(['id', 'photo_session_id', 'waiver_id', 'status', 'updated_at'])
                ->groupBy(fn (PhotoDelivery $delivery) => $delivery->photo_session_id . '|' . ($delivery->waiver_id ?? ('row' . $delivery->id)))
                ->map(fn ($rows) => $rows->last());
            $stuckBefore = now()->subMinutes(self::STUCK_AFTER_MINUTES);

            foreach ($latest as $delivery) {
                $problem = $delivery->status === PhotoDelivery::STATUS_FAILED
                    || ($delivery->status === PhotoDelivery::STATUS_QUEUED && $delivery->updated_at && $delivery->updated_at->lessThan($stuckBefore));

                if ($problem) {
                    $problemCounts[$delivery->photo_session_id] = ($problemCounts[$delivery->photo_session_id] ?? 0) + 1;
                }
            }
        }

        $payload = [];

        foreach ($rooms as $room) {
            $hasWaiver = $this->templateForRoom($location, $room) !== null;
            $times = $this->timesForDay($room, $date, (bool) $room->is_active);
            $duration = max(1, $room->getDurationInMinutes());

            if (!$room->is_active && $times === []) {
                continue;
            }

            $slots = [];

            foreach ($times as $time) {
                $key = $room->id . '|' . $time;
                $slotBookings = $bookingsBySlot->get($key, collect());
                $session = $sessions->get($key);
                $signed = (int) ($signedCounts[$key] ?? 0);
                $photos = $session && $session->photo_session_id ? (int) ($readyPhotos[$session->photo_session_id] ?? 0) : 0;
                $sent = $session && $session->photo_session_id ? (int) ($sentCounts[$session->photo_session_id] ?? 0) : 0;
                $problems = $session && $session->photo_session_id ? (int) ($problemCounts[$session->photo_session_id] ?? 0) : 0;
                $start = Carbon::parse($date . ' ' . $time, $tz);
                $end = $start->copy()->addMinutes($duration);

                $slots[] = [
                    'key' => $key,
                    'session_id' => $session?->id,
                    'time' => $time,
                    'time_label' => $this->timeLabel($time),
                    'is_past' => $isToday ? $end->lessThanOrEqualTo($now) : $date < $this->today($location),
                    'in_progress' => $isToday && $start->lessThanOrEqualTo($now) && $end->greaterThan($now),
                    'check_in_open' => $isToday && $end->copy()->addMinutes(self::SUBMIT_GRACE_MINUTES)->greaterThan($now),
                    'bookings' => $slotBookings->map(fn (Booking $booking) => [
                        'id' => $booking->id,
                        'reference_number' => $booking->reference_number,
                        'name' => $this->bookingName($booking),
                        'participants' => (int) $booking->participants,
                        'unsigned' => (int) ($pendingCounts[$booking->id] ?? 0),
                    ])->values()->all(),
                    'players_booked' => (int) $slotBookings->sum('participants'),
                    'signed' => $signed,
                    'unsigned' => (int) $slotBookings->sum(fn (Booking $booking) => (int) ($pendingCounts[$booking->id] ?? 0)),
                    'photos' => $photos,
                    'sent' => $sent,
                    'not_delivered' => $problems,
                    'completed' => $session?->isCompleted() ?? false,
                    'completion_label' => $session?->completionLabel() ?? '',
                    'status' => $this->slotStatus($session, $signed, $photos, $problems, $sent),
                ];
            }

            $payload[] = [
                'id' => $room->id,
                'name' => $room->name,
                'is_active' => (bool) $room->is_active,
                'duration_minutes' => $duration,
                'has_waiver' => $hasWaiver,
                'slots' => $slots,
            ];
        }

        return [
            'date' => $date,
            'is_today' => $isToday,
            'today' => $this->today($location),
            'location' => ['id' => $location->id, 'name' => $location->name],
            'kiosk_url' => $this->kioskUrl($location),
            'email_available' => $this->deliveries->emailAvailable(),
            'email_note' => $this->deliveries->channelDiagnostics()['email_note'],
            'rooms' => $payload,
            'unsent_earlier' => $this->unsentEarlier($location, $roomIds),
        ];
    }

    protected function signedCountsBySlot(Collection $sessions, Collection $bookings): array
    {
        $counts = [];
        $sessionsById = $sessions->keyBy('id');

        $attached = $sessionsById->isEmpty() ? collect() : $this->membersQuery()
            ->whereIn('escape_room_session_id', $sessionsById->keys()->all())
            ->get();

        foreach ($attached as $waiver) {
            $session = $sessionsById->get($waiver->escape_room_session_id);

            if ($session && $this->exclusionReason($waiver, $session) === null) {
                $key = $session->package_id . '|' . $session->timeKey();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $bookingsById = $bookings->keyBy('id');

        foreach ($this->bookingWaiversWithoutGame($bookingsById->keys()->map(fn ($id) => (int) $id)->all()) as $waiver) {
            $booking = $bookingsById->get($waiver->booking_id);
            $time = $booking ? $this->bookingTime($booking) : null;

            if ($time && (int) $waiver->location_id === (int) $booking->location_id) {
                $key = $booking->package_id . '|' . $time;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    protected function slotStatus(?EscapeRoomSession $session, int $signed, int $photos, int $problems = 0, int $sent = 0): string
    {
        if ($session?->isCompleted()) {
            if ($sent === 0 && $problems === 0) {
                return 'finished';
            }

            return $problems > 0 ? 'send_problem' : 'sent';
        }
        if ($photos > 0) {
            return 'photo_ready';
        }
        if ($signed > 0) {
            return 'signing';
        }

        return 'waiting';
    }

    public function bookingName(Booking $booking): string
    {
        if ($booking->relationLoaded('customer') && $booking->customer) {
            $name = trim(($booking->customer->first_name ?? '') . ' ' . ($booking->customer->last_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return trim((string) $booking->guest_name) ?: 'Guest';
    }

    public function detail(EscapeRoomSession $session): array
    {
        $session->loadMissing('location', 'package', 'completer:id,first_name,last_name');
        $location = $session->location;
        $room = $session->package;

        $photoSession = $session->photo_session_id
            ? PhotoSession::whereKey($session->photo_session_id)->first()
            : null;

        $membership = $this->membership($session);
        $sentIds = $this->sentWaiverIds($photoSession);

        $deliveriesByWaiver = $photoSession
            ? PhotoDelivery::where('photo_session_id', $photoSession->id)
                ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
                ->orderBy('id')
                ->get()
                ->groupBy('waiver_id')
            : collect();

        $bookings = Booking::with('customer:id,first_name,last_name')
            ->where('package_id', $session->package_id)
            ->where('booking_date', $session->dateKey())
            ->where('status', '!=', 'cancelled')
            ->whereRaw("TIME_FORMAT(booking_time, '%H:%i') = ?", [$session->timeKey()])
            ->get(['id', 'reference_number', 'package_id', 'customer_id', 'guest_name', 'booking_time', 'participants', 'status']);

        $pending = $bookings->isEmpty() ? collect() : Waiver::withoutHeavyColumns()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->where('status', Waiver::STATUS_PENDING)
            ->get();

        $bookingRefs = $bookings->pluck('reference_number', 'id');

        $present = function (Waiver $waiver) use ($sentIds, $deliveriesByWaiver, $bookingRefs) {
            $delivery = $deliveriesByWaiver->get($waiver->id)?->last();
            $hasEmail = $this->deliveries->validEmail($waiver->adult_email);

            return [
                'waiver_id' => $waiver->id,
                'reference_number' => $waiver->reference_number,
                'name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
                'minors' => $waiver->relationLoaded('minors') ? $waiver->minors->count() : 0,
                'email_masked' => $hasEmail ? WaiverProfileService::maskEmail($waiver->adult_email) : null,
                'has_email' => $hasEmail,
                'photo_release' => $waiver->photo_video_consent,
                'booking_id' => $waiver->booking_id,
                'booking_reference' => $waiver->booking_id
                    ? ($bookingRefs[$waiver->booking_id] ?? $waiver->booking?->reference_number)
                    : null,
                'is_sign_in' => $waiver->isEscapeRoomSignIn(),
                'signed_at' => $waiver->submitted_at?->toIso8601String(),
                'sent' => in_array((int) $waiver->id, $sentIds, true),
                'delivery' => $delivery ? [
                    'id' => $delivery->id,
                    'status' => $delivery->status,
                    'gave_up' => $delivery->status === PhotoDelivery::STATUS_FAILED && $delivery->attempts >= PhotoDelivery::MAX_ATTEMPTS,
                    'is_duplicate' => $delivery->isDuplicate(),
                    'sent_at' => $delivery->sent_at?->toIso8601String(),
                    'error' => $delivery->error,
                ] : null,
                'excluded_reason' => $waiver->getAttribute('escape_room_excluded_reason'),
            ];
        };

        $included = $membership['included']->map($present)->values();
        $excluded = $membership['excluded']->map($present)->values();
        $newPlayers = $included->filter(fn ($row) => !$row['sent'] && $row['has_email'])->count();
        $primaryDeliveries = $deliveriesByWaiver->flatten()
            ->whereNull('duplicate_of_id')
            ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
            ->sortBy('id')
            ->groupBy(fn (PhotoDelivery $delivery) => $delivery->waiver_id ?? ('row' . $delivery->id))
            ->map(fn ($rows) => $rows->last())
            ->values();
        $stuckCount = $photoSession ? $this->stuckDeliveriesQuery($photoSession)->count() : 0;
        $photoAvailable = $photoSession
            && $photoSession->purged_at === null
            && $photoSession->accessIsActive()
            && $photoSession->photos()->ready()->exists();
        $sendBlocker = null;
        $completedWithoutPhoto = $session->isCompleted() && $deliveriesByWaiver->isEmpty();

        if ($completedWithoutPhoto) {
            $sendBlocker = 'The result was recorded without a group photo, so there is no photo to send.';
        } elseif ($session->isCompleted() && !$photoAvailable) {
            $sendBlocker = $photoSession && $photoSession->purged_at === null && $photoSession->photos()->ready()->exists()
                ? 'The photo link for this game has expired, so the photo cannot be sent to more players.'
                : "This game's photo has been removed, so it cannot be sent to more players.";
        }

        $blockers = [];
        if (!$photoSession || $photoSession->photos()->ready()->count() === 0) {
            $blockers[] = 'Take or upload the group photo.';
        }
        if ($included->isEmpty()) {
            $blockers[] = 'Nobody has signed a waiver for this game yet.';
        } elseif ($included->every(fn ($row) => !$row['has_email'])) {
            $blockers[] = 'None of the players has an email address on their waiver.';
        }
        if (!$this->deliveries->emailAvailable()) {
            $blockers[] = 'Email is not switched on for this site yet.';
        }

        return [
            'id' => $session->id,
            'location_id' => $session->location_id,
            'location_name' => $location?->name,
            'room' => [
                'id' => $room?->id,
                'name' => $room?->name,
                'duration_minutes' => $room ? max(1, $room->getDurationInMinutes()) : null,
                'is_active' => (bool) ($room?->is_active),
                'has_waiver' => $room && $location ? $this->templateForRoom($location, $room) !== null : false,
            ],
            'session_date' => $session->dateKey(),
            'session_time' => $session->timeKey(),
            'session_time_label' => $this->timeLabel($session->timeKey()),
            'completed' => $session->isCompleted(),
            'escaped' => $session->escaped,
            'completion_seconds' => $session->completion_seconds,
            'completion_label' => $session->completionLabel(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'completed_without_photo' => $completedWithoutPhoto,
            'completed_by_name' => $session->completer
                ? trim(($session->completer->first_name ?? '') . ' ' . ($session->completer->last_name ?? ''))
                : null,
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'name' => $this->bookingName($booking),
                'participants' => (int) $booking->participants,
                'status' => $booking->status,
            ])->values(),
            'players' => $included,
            'excluded_players' => $excluded,
            'unsigned' => $pending->map(fn (Waiver $waiver) => [
                'waiver_id' => $waiver->id,
                'reference_number' => $waiver->reference_number,
                'booking_id' => $waiver->booking_id,
                'booking_reference' => $bookingRefs[$waiver->booking_id] ?? null,
            ])->values(),
            'counts' => [
                'players' => $included->count(),
                'people' => (int) $included->sum(fn ($row) => 1 + (int) $row['minors']),
                'with_email' => $included->where('has_email', true)->count(),
                'sent' => $included->where('sent', true)->count(),
                'emailed' => $primaryDeliveries->where('status', PhotoDelivery::STATUS_SENT)->count(),
                'failed' => $primaryDeliveries->where('status', PhotoDelivery::STATUS_FAILED)->filter(fn ($d) => $d->attempts >= PhotoDelivery::MAX_ATTEMPTS)->count(),
                'retrying' => $primaryDeliveries->where('status', PhotoDelivery::STATUS_FAILED)->filter(fn ($d) => $d->attempts < PhotoDelivery::MAX_ATTEMPTS)->count(),
                'sending' => $primaryDeliveries->where('status', PhotoDelivery::STATUS_QUEUED)->count(),
                'stuck' => $stuckCount,
                'new_players' => $newPlayers,
                'excluded' => $excluded->count(),
                'unsigned' => $pending->count(),
            ],
            'photo_session' => $photoSession ? $this->presentSession($photoSession) : null,
            'can_complete' => !$session->isCompleted() && $blockers === [],
            'can_send_new' => $session->isCompleted() && !$completedWithoutPhoto && $photoAvailable && ($newPlayers > 0 || $stuckCount > 0) && $this->deliveries->emailAvailable(),
            'can_resend' => $session->isCompleted() && !$completedWithoutPhoto && $photoAvailable && $this->deliveries->emailAvailable(),
            'can_complete_without_photo' => !$session->isCompleted()
                && (!$location || $session->dateKey() <= $this->today($location))
                && (!$photoSession || !$photoSession->photos()->exists())
                && ($included->isNotEmpty() || $bookings->isNotEmpty()),
            'send_blocker' => $sendBlocker,
            'photo_link' => $photoSession && $photoAvailable ? $this->deliveries->photoLink($photoSession) : null,
            'blockers' => $blockers,
            'email_available' => $this->deliveries->emailAvailable(),
            'kiosk_url' => $location ? $this->kioskUrl($location) : null,
            'players_booked' => (int) $bookings->sum('participants'),
            'slideshow' => [
                'enabled' => $location ? (bool) LocationPhotoSetting::forLocation($location)->slideshow_enabled : false,
                'declined' => $membership['included']->filter(fn (Waiver $waiver) => $waiver->photo_video_consent === false)->count(),
                'not_asked' => $membership['included']->filter(fn (Waiver $waiver) => $waiver->photo_video_consent === null)->count(),
            ],
        ];
    }
}
