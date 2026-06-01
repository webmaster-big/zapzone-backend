<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class DayOff extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'date',
        'time_start',
        'time_end',
        'reason',
        'is_recurring',
        'package_ids',
        'room_ids',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
        'package_ids' => 'array',
        'room_ids' => 'array',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('date', $date);
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
        return $query->where('date', '>=', now()->toDateString());
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

    public function scopeLocationWide($query)
    {
        return $query->whereNull('package_ids')->whereNull('room_ids');
    }

    public function isFullDay(): bool
    {
        return is_null($this->time_start) && is_null($this->time_end);
    }

    public function isCloseEarly(): bool
    {
        return !is_null($this->time_start) && is_null($this->time_end);
    }

    public function isDelayedOpening(): bool
    {
        return is_null($this->time_start) && !is_null($this->time_end);
    }

    public function isTimeRange(): bool
    {
        return !is_null($this->time_start) && !is_null($this->time_end);
    }

    public function isTimeBlocked(string $slotStart, ?string $slotEnd = null): bool
    {
        if ($this->isFullDay()) {
            return true;
        }

        $slotStartTime = Carbon::parse($slotStart);
        $slotEndTime = $slotEnd ? Carbon::parse($slotEnd) : null;

        if ($this->isCloseEarly()) {
            $closeTime = Carbon::parse($this->time_start);
            if ($slotStartTime->gte($closeTime)) {
                return true;
            }
            if ($slotEndTime && $slotEndTime->gt($closeTime)) {
                return true;
            }
            return false;
        }

        if ($this->isDelayedOpening()) {
            $openTime = Carbon::parse($this->time_end);
            if ($slotStartTime->lt($openTime)) {
                return true;
            }
            return false;
        }

        if ($this->isTimeRange()) {
            $rangeStart = Carbon::parse($this->time_start);
            $rangeEnd = Carbon::parse($this->time_end);

            if ($slotEndTime) {
                if ($slotStartTime->lt($rangeEnd) && $slotEndTime->gt($rangeStart)) {
                    return true;
                }
            } else {
                if ($slotStartTime->gte($rangeStart) && $slotStartTime->lt($rangeEnd)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    public function isLocationWide(): bool
    {
        return empty($this->package_ids) && empty($this->room_ids);
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

    public static function isDateBlocked($locationId, $date)
    {
        return self::where('location_id', $locationId)
            ->where('date', $date)
            ->whereNull('time_start')
            ->whereNull('time_end')
            ->whereNull('package_ids')
            ->whereNull('room_ids')
            ->exists();
    }

    public static function isTimeSlotBlocked($locationId, $date, string $slotStart, ?string $slotEnd = null): bool
    {
        $dayOffs = self::where('location_id', $locationId)
            ->where('date', $date)
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
            ->where('date', $date)
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
            ->where('date', $date)
            ->forRoom($roomId)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd) && $dayOff->appliesToRoom($roomId)) {
                return true;
            }
        }

        return false;
    }

    public static function getDayOffForTimeSlot($locationId, $date, string $slotStart, ?string $slotEnd = null): ?self
    {
        $dayOffs = self::where('location_id', $locationId)
            ->where('date', $date)
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd)) {
                return $dayOff;
            }
        }

        return null;
    }
}
