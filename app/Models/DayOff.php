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
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    // Relationships
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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
     * Helper method to check if a date is blocked (full day only - legacy support).
     */
    public static function isDateBlocked($locationId, $date)
    {
        return self::where('location_id', $locationId)
            ->where('date', $date)
            ->whereNull('time_start')
            ->whereNull('time_end')
            ->exists();
    }

    /**
     * Check if a specific time slot is blocked on a given date.
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
            ->get();

        foreach ($dayOffs as $dayOff) {
            if ($dayOff->isTimeBlocked($slotStart, $slotEnd)) {
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
