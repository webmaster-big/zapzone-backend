<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class DayOff extends Model
{
    use HasFactory;

    public const MINUTES_PER_DAY = 1440;

    public const BLOCK_FULL_DAY = 'full_day';
    public const BLOCK_OPERATING_WINDOW = 'operating_window';
    public const BLOCK_LEGACY_CLOSURE = 'legacy_closure';

    private const OPEN_ENDED = 2 * self::MINUTES_PER_DAY;

    protected $fillable = [
        'location_id',
        'date',
        'time_start',
        'time_end',
        'opens_at',
        'closes_at',
        'reason',
        'group_name',
        'is_recurring',
        'package_ids',
        'room_ids',
        'attraction_ids',
        'event_ids',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
        'package_ids' => 'array',
        'room_ids' => 'array',
        'attraction_ids' => 'array',
        'event_ids' => 'array',
    ];

    protected $appends = ['block_type'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function packages()
    {
        if (empty($this->package_ids)) {
            return collect();
        }
        return Package::whereIn('id', $this->package_ids)->get();
    }

    public function rooms()
    {
        if (empty($this->room_ids)) {
            return collect();
        }
        return Room::whereIn('id', $this->room_ids)->get();
    }

    public function attractions()
    {
        if (empty($this->attraction_ids)) {
            return collect();
        }
        return Attraction::whereIn('id', $this->attraction_ids)->get();
    }

    public function events()
    {
        if (empty($this->event_ids)) {
            return collect();
        }
        return Event::whereIn('id', $this->event_ids)->get();
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForDate($query, $date)
    {
        $carbon = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        return $query->where(function ($q) use ($carbon) {
            $q->whereDate('date', $carbon->toDateString())
              ->orWhere(function ($recurring) use ($carbon) {
                  $recurring->where('is_recurring', true)
                      ->whereMonth('date', $carbon->month)
                      ->whereDay('date', $carbon->day);
              });
        });
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeUpcoming($query)
    {
        return $query->where(function ($q) {
            $q->where('date', '>=', now()->toDateString())
              ->orWhere('is_recurring', true);
        });
    }

    public function scopeAllDay($query)
    {
        $query->whereNull('time_start')->whereNull('time_end');

        if (self::hasOperatingWindowColumns()) {
            $query->whereNull('opens_at')->whereNull('closes_at');
        }

        return $query;
    }

    public static function hasOperatingWindowColumns(): bool
    {
        static $known = null;

        if ($known === null) {
            try {
                $known = \Illuminate\Support\Facades\Schema::hasColumn('day_offs', 'opens_at');
            } catch (\Throwable $e) {
                $known = false;
            }
        }

        return $known;
    }

    public static function tracksAvailabilityDetails(): bool
    {
        static $known = null;

        if ($known === null) {
            try {
                $known = self::hasOperatingWindowColumns()
                    && \Illuminate\Support\Facades\Schema::hasTable('day_off_change_logs');
            } catch (\Throwable $e) {
                $known = false;
            }
        }

        return $known;
    }

    public function scopeForPackage($query, $packageId)
    {
        return $query->where(function ($q) use ($packageId) {
            $q->whereNull('package_ids')
              ->orWhereJsonContains('package_ids', (int) $packageId)
              ->orWhereJsonContains('package_ids', (string) $packageId);
        });
    }

    public function scopeForRoom($query, $roomId)
    {
        return $query->where(function ($q) use ($roomId) {
            $q->whereNull('room_ids')
              ->orWhereJsonContains('room_ids', (int) $roomId)
              ->orWhereJsonContains('room_ids', (string) $roomId);
        });
    }

    public function scopeForAttraction($query, $attractionId)
    {
        return $query->where(function ($q) use ($attractionId) {
            $q->whereNull('attraction_ids')
              ->orWhereJsonContains('attraction_ids', (int) $attractionId)
              ->orWhereJsonContains('attraction_ids', (string) $attractionId);
        });
    }

    public function scopeForEvent($query, $eventId)
    {
        return $query->where(function ($q) use ($eventId) {
            $q->whereNull('event_ids')
              ->orWhereJsonContains('event_ids', (int) $eventId)
              ->orWhereJsonContains('event_ids', (string) $eventId);
        });
    }

    public function scopeLocationWide($query)
    {
        return $query->whereNull('package_ids')
            ->whereNull('room_ids')
            ->whereNull('attraction_ids')
            ->whereNull('event_ids');
    }

    public static function clockToMinutes(?string $clock): ?int
    {
        if ($clock === null || trim($clock) === '') {
            return null;
        }

        $parts = explode(':', trim($clock));

        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit(substr($parts[1], 0, 2))) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) substr($parts[1], 0, 2);

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }

    public static function closingClockToMinutes(?string $clock): ?int
    {
        $minutes = self::clockToMinutes($clock);

        return $minutes === 0 ? self::MINUTES_PER_DAY : $minutes;
    }

    public static function shortClock(?string $clock): ?string
    {
        $minutes = self::clockToMinutes($clock);

        return $minutes === null ? null : sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public function getBlockTypeAttribute(): string
    {
        if ($this->isOperatingWindow()) {
            return self::BLOCK_OPERATING_WINDOW;
        }

        if ($this->isFullDay()) {
            return self::BLOCK_FULL_DAY;
        }

        return self::BLOCK_LEGACY_CLOSURE;
    }

    public function isOperatingWindow(): bool
    {
        return ! empty($this->opens_at) || ! empty($this->closes_at);
    }

    public function isFullDay(): bool
    {
        return empty($this->time_start) && empty($this->time_end) && ! $this->isOperatingWindow();
    }

    public function isCloseEarly(): bool
    {
        return ! $this->isOperatingWindow() && ! empty($this->time_start) && empty($this->time_end);
    }

    public function isDelayedOpening(): bool
    {
        return ! $this->isOperatingWindow() && empty($this->time_start) && ! empty($this->time_end);
    }

    public function isTimeRange(): bool
    {
        return ! $this->isOperatingWindow() && ! empty($this->time_start) && ! empty($this->time_end);
    }

    public function opensAtMinutes(): ?int
    {
        if ($this->isOperatingWindow()) {
            return self::clockToMinutes($this->opens_at);
        }

        return $this->isDelayedOpening() ? self::clockToMinutes($this->time_end) : null;
    }

    public const OVERNIGHT_CLOSE_LIMIT = 6 * 60;

    public static function overnightClose(?int $opensAt, ?int $closesAt): ?int
    {
        if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt && $closesAt <= self::OVERNIGHT_CLOSE_LIMIT) {
            return $closesAt + self::MINUTES_PER_DAY;
        }

        return $closesAt;
    }

    public function closesAtMinutes(): ?int
    {
        if ($this->isOperatingWindow()) {
            return self::overnightClose(self::clockToMinutes($this->opens_at), self::closingClockToMinutes($this->closes_at));
        }

        return $this->isCloseEarly() ? self::clockToMinutes($this->time_start) : null;
    }

    public function closedRangeMinutes(): ?array
    {
        if (! $this->isTimeRange()) {
            return null;
        }

        $start = self::clockToMinutes($this->time_start);
        $end = self::clockToMinutes($this->time_end);

        if ($start === null || $end === null || $end <= $start) {
            return null;
        }

        return [$start, $end];
    }

    public function blockedRanges(?int $overnightUntil = null): array
    {
        if ($this->isFullDay()) {
            return [[0, self::OPEN_ENDED]];
        }

        $tonight = fn (int $minutes) => $overnightUntil !== null && $minutes <= $overnightUntil
            ? $minutes + self::MINUTES_PER_DAY
            : $minutes;

        $ranges = [];

        $opensAt = $this->opensAtMinutes();
        if ($opensAt !== null && $opensAt > 0) {
            $ranges[] = [0, $tonight($opensAt)];
        }

        $closesAt = $this->closesAtMinutes();
        if ($closesAt !== null) {
            $ranges[] = [$tonight($closesAt), self::OPEN_ENDED];
        }

        $closedRange = $this->closedRangeMinutes();
        if ($closedRange !== null) {
            $ranges[] = $overnightUntil !== null && $closedRange[1] <= $overnightUntil
                ? [$closedRange[0] + self::MINUTES_PER_DAY, $closedRange[1] + self::MINUTES_PER_DAY]
                : $closedRange;
        }

        return $ranges;
    }

    public function isTimeBlocked(string $slotStart, ?string $slotEnd = null, ?int $overnightUntil = null): bool
    {
        if ($this->isFullDay()) {
            return true;
        }

        $start = self::clockToMinutes($slotStart);

        if ($start === null) {
            return true;
        }

        $hasEnd = $slotEnd !== null && trim($slotEnd) !== '';
        $end = $hasEnd ? self::clockToMinutes($slotEnd) : null;

        if ($hasEnd && $end === null) {
            return true;
        }

        if ($end !== null && $end <= $start) {
            $end += self::MINUTES_PER_DAY;
        }

        if ($overnightUntil !== null && $start < $overnightUntil) {
            $start += self::MINUTES_PER_DAY;
            $end = $end === null ? null : $end + self::MINUTES_PER_DAY;
        }

        foreach ($this->blockedRanges($overnightUntil) as [$rangeStart, $rangeEnd]) {
            if ($end === null) {
                if ($start >= $rangeStart && $start < $rangeEnd) {
                    return true;
                }

                continue;
            }

            if ($start < $rangeEnd && $end > $rangeStart) {
                return true;
            }
        }

        return false;
    }

    public function asLegacyClosures(): array
    {
        if (! $this->isOperatingWindow()) {
            return [[
                'time_start' => self::shortClock($this->time_start),
                'time_end' => self::shortClock($this->time_end),
            ]];
        }

        $closures = [];

        $opensAt = $this->opensAtMinutes();
        if ($opensAt !== null && $opensAt > 0) {
            $closures[] = ['time_start' => null, 'time_end' => self::shortClock($this->opens_at)];
        }

        $closesAt = $this->closesAtMinutes();
        if ($closesAt !== null && $closesAt < self::MINUTES_PER_DAY) {
            $closures[] = ['time_start' => self::shortClock($this->closes_at), 'time_end' => null];
        }

        return $closures;
    }

    public function isLocationWide(): bool
    {
        return empty($this->package_ids)
            && empty($this->room_ids)
            && empty($this->attraction_ids)
            && empty($this->event_ids);
    }

    public function appliesToPackage(int $packageId): bool
    {
        if ($this->isLocationWide()) {
            return true;
        }

        if (!empty($this->package_ids)) {
            return in_array($packageId, $this->package_ids) || in_array((string) $packageId, $this->package_ids);
        }

        return false;
    }

    public function appliesToRoom(int $roomId): bool
    {
        if ($this->isLocationWide()) {
            return true;
        }

        if (!empty($this->room_ids)) {
            return in_array($roomId, $this->room_ids) || in_array((string) $roomId, $this->room_ids);
        }

        return false;
    }

    public function appliesToAttraction(int $attractionId): bool
    {
        if ($this->isLocationWide()) {
            return true;
        }

        if (!empty($this->attraction_ids)) {
            return in_array($attractionId, $this->attraction_ids) || in_array((string) $attractionId, $this->attraction_ids);
        }

        return false;
    }

    public function appliesToEvent(int $eventId): bool
    {
        if ($this->isLocationWide()) {
            return true;
        }

        if (!empty($this->event_ids)) {
            return in_array($eventId, $this->event_ids) || in_array((string) $eventId, $this->event_ids);
        }

        return false;
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id !== null ? (int) $this->location_id : null,
            'date' => $this->date ? Carbon::parse($this->date)->toDateString() : null,
            'is_recurring' => (bool) $this->is_recurring,
            'block_type' => $this->block_type,
            'opens_at' => self::shortClock($this->opens_at),
            'closes_at' => self::shortClock($this->closes_at),
            'time_start' => self::shortClock($this->time_start),
            'time_end' => self::shortClock($this->time_end),
            'reason' => $this->reason,
            'group_name' => $this->group_name,
            'package_ids' => self::normalizedIds($this->package_ids),
            'room_ids' => self::normalizedIds($this->room_ids),
            'attraction_ids' => self::normalizedIds($this->attraction_ids),
            'event_ids' => self::normalizedIds($this->event_ids),
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'updated_by' => $this->updated_by !== null ? (int) $this->updated_by : null,
        ];
    }

    public static function overnightUntil(?string $windowStart, ?string $windowEnd): ?int
    {
        $start = self::clockToMinutes($windowStart);
        $end = self::clockToMinutes($windowEnd);

        if ($start === null || $end === null || $end >= $start || $end === 0) {
            return null;
        }

        return $end;
    }

    public static function normalizedIds($ids): ?array
    {
        if (empty($ids)) {
            return null;
        }

        $normalized = array_values(array_unique(array_map('intval', (array) $ids)));
        sort($normalized);

        return $normalized;
    }

    public static function isDateBlocked($locationId, $date)
    {
        return self::where('location_id', $locationId)
            ->forDate($date)
            ->allDay()
            ->whereNull('package_ids')
            ->whereNull('room_ids')
            ->whereNull('attraction_ids')
            ->whereNull('event_ids')
            ->exists();
    }

    public static function isTimeSlotBlocked($locationId, $date, string $slotStart, ?string $slotEnd = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->locationWide()
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd)) {
                return true;
            }
        }

        return false;
    }

    public static function isTimeSlotBlockedForPackage($locationId, int $packageId, $date, string $slotStart, ?string $slotEnd = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->forPackage($packageId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd) && $dayOff->appliesToPackage($packageId)) {
                return true;
            }
        }

        return false;
    }

    public static function isTimeSlotBlockedForRoom($locationId, int $roomId, $date, string $slotStart, ?string $slotEnd = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->forRoom($roomId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd) && $dayOff->appliesToRoom($roomId)) {
                return true;
            }
        }

        return false;
    }

    public static function isTimeSlotBlockedForAttraction($locationId, int $attractionId, $date, string $slotStart, ?string $slotEnd = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->forAttraction($attractionId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd) && $dayOff->appliesToAttraction($attractionId)) {
                return true;
            }
        }

        return false;
    }

    public static function isTimeSlotBlockedForEvent($locationId, int $eventId, $date, string $slotStart, ?string $slotEnd = null, ?int $overnightUntil = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->forEvent($eventId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd, $overnightUntil) && $dayOff->appliesToEvent($eventId)) {
                return true;
            }
        }

        return false;
    }

    public static function isDateBlockedForAttraction($locationId, int $attractionId, $date): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->allDay()
            ->forAttraction($attractionId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->appliesToAttraction($attractionId)) {
                return true;
            }
        }

        return false;
    }

    public static function isDateBlockedForEvent($locationId, int $eventId, $date): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->allDay()
            ->forEvent($eventId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->appliesToEvent($eventId)) {
                return true;
            }
        }

        return false;
    }

    public static function getDayOffForTimeSlot($locationId, $date, string $slotStart, ?string $slotEnd = null): ?self
    {
        $dayOffs = self::where('location_id', $locationId)
            ->forDate($date)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd)) {
                return $dayOff;
            }
        }

        return null;
    }
}
