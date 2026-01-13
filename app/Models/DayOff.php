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

    // Relationships
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get packages associated with this day off.
     */
    public function packages()
    {
        if (empty($this->package_ids)) {
            return collect();
        }
        return Package::whereIn('id', $this->package_ids)->get();
    }

    /**
     * Get rooms associated with this day off.
     */
    public function rooms()
    {
        if (empty($this->room_ids)) {
            return collect();
        }
        return Room::whereIn('id', $this->room_ids)->get();
    }

    // Scopes
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

    /**
     * Scope to filter day offs that apply to a specific package.
     * Includes day offs with no package_ids (applies to all packages)
     * and those that specifically include the package.
     */
    public function scopeForPackage($query, $packageId)
    {
        return $query->where(function ($q) use ($packageId) {
            $q->whereNull('package_ids')
              ->orWhereJsonContains('package_ids', (int) $packageId)
              ->orWhereJsonContains('package_ids', (string) $packageId);
        });
    }

    /**
     * Scope to filter day offs that apply to a specific room.
     * Includes day offs with no room_ids (applies to all rooms)
     * and those that specifically include the room.
     */
    public function scopeForRoom($query, $roomId)
    {
        return $query->where(function ($q) use ($roomId) {
            $q->whereNull('room_ids')
              ->orWhereJsonContains('room_ids', (int) $roomId)
              ->orWhereJsonContains('room_ids', (string) $roomId);
        });
    }

    /**
     * Scope to filter day offs that block the entire location
     * (both package_ids and room_ids are null).
     */
    public function scopeLocationWide($query)
    {
        return $query->whereNull('package_ids')->whereNull('room_ids');
    }

    /**
     * Check if this day off is a full day closure.
     */
    public function isFullDay(): bool
    {
        return is_null($this->time_start) && is_null($this->time_end);
    }

    /**
     * Check if this is a partial day closure (close early).
     * Blocked from time_start until end of day.
     */
    public function isCloseEarly(): bool
    {
        return !is_null($this->time_start) && is_null($this->time_end);
    }

    /**
     * Check if this is a delayed opening.
     * Blocked from start of day until time_end.
     */
    public function isDelayedOpening(): bool
    {
        return is_null($this->time_start) && !is_null($this->time_end);
    }

    /**
     * Check if this is a specific time range closure.
     */
    public function isTimeRange(): bool
    {
        return !is_null($this->time_start) && !is_null($this->time_end);
    }

    /**
     * Check if a specific time slot is blocked by this day off.
     *
     * @param string $slotStart The start time of the slot (H:i format)
     * @param string|null $slotEnd The end time of the slot (H:i format), optional
     * @return bool
     */
    public function isTimeBlocked(string $slotStart, ?string $slotEnd = null): bool
    {
        // Full day closure blocks everything
        if ($this->isFullDay()) {
            return true;
        }

        $slotStartTime = Carbon::parse($slotStart);
        $slotEndTime = $slotEnd ? Carbon::parse($slotEnd) : null;

        // Close early: blocked from time_start onwards
        if ($this->isCloseEarly()) {
            $closeTime = Carbon::parse($this->time_start);
            // Block if slot starts at or after close time
            // Or if slot ends after close time
            if ($slotStartTime->gte($closeTime)) {
                return true;
            }
            if ($slotEndTime && $slotEndTime->gt($closeTime)) {
                return true;
            }
            return false;
        }

        // Delayed opening: blocked until time_end
        if ($this->isDelayedOpening()) {
            $openTime = Carbon::parse($this->time_end);
            // Block if slot starts before open time
            if ($slotStartTime->lt($openTime)) {
                return true;
            }
            return false;
        }

        // Specific time range: blocked during the range
        if ($this->isTimeRange()) {
            $rangeStart = Carbon::parse($this->time_start);
            $rangeEnd = Carbon::parse($this->time_end);

            // Check if slot overlaps with blocked range
            // Slot is blocked if it starts before range ends AND ends after range starts
            if ($slotEndTime) {
                if ($slotStartTime->lt($rangeEnd) && $slotEndTime->gt($rangeStart)) {
                    return true;
                }
            } else {
                // No end time provided, just check if start is within range
                if ($slotStartTime->gte($rangeStart) && $slotStartTime->lt($rangeEnd)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * Check if this day off applies to the entire location.
     * (No specific packages or rooms specified)
     */
    public function isLocationWide(): bool
    {
        return empty($this->package_ids) && empty($this->room_ids);
    }

    /**
     * Check if this day off applies to a specific package.
     */
    public function appliesToPackage(int $packageId): bool
    {
        // If location-wide, it applies to all packages
        if ($this->isLocationWide()) {
            return true;
        }

        // If specific packages are set, check if this package is in the list
        if (!empty($this->package_ids)) {
            return in_array($packageId, $this->package_ids) || in_array((string) $packageId, $this->package_ids);
        }

        // If only rooms are specified (no packages), this doesn't directly apply to the package
        // The package might still be affected if its rooms are blocked
        return false;
    }

    /**
     * Check if this day off applies to a specific room.
     */
    public function appliesToRoom(int $roomId): bool
    {
        // If location-wide, it applies to all rooms
        if ($this->isLocationWide()) {
            return true;
        }

        // If specific rooms are set, check if this room is in the list
        if (!empty($this->room_ids)) {
            return in_array($roomId, $this->room_ids) || in_array((string) $roomId, $this->room_ids);
        }

        // If only packages are specified, rooms are not directly blocked
        return false;
    }

    /**
     * Helper method to check if a date is blocked (full day only - legacy support).
     */
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

    /**
     * Check if a specific time slot is blocked on a given date (location-wide only).
     *
     * @param int $locationId
     * @param string $date
     * @param string $slotStart The start time of the slot (H:i format)
     * @param string|null $slotEnd The end time of the slot (H:i format), optional
     * @return bool
     */
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

    /**
     * Check if a specific time slot is blocked for a package.
     * Checks both location-wide blocks and package-specific blocks.
     *
     * @param int $locationId
     * @param int $packageId
     * @param string $date
     * @param string $slotStart The start time of the slot (H:i format)
     * @param string|null $slotEnd The end time of the slot (H:i format), optional
     * @return bool
     */
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

    /**
     * Check if a specific time slot is blocked for a room.
     * Checks both location-wide blocks and room-specific blocks.
     *
     * @param int $locationId
     * @param int $roomId
     * @param string $date
     * @param string $slotStart The start time of the slot (H:i format)
     * @param string|null $slotEnd The end time of the slot (H:i format), optional
     * @return bool
     */
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

    /**
     * Get day off info for a specific time slot (returns the day off record if blocked).
     */
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
