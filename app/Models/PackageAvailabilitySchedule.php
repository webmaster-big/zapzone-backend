<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageAvailabilitySchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'availability_type',
        'day_configuration',
        'time_slot_start',
        'time_slot_end',
        'time_slot_interval',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'day_configuration' => 'array',
        'time_slot_start' => 'string',
        'time_slot_end' => 'string',
        'time_slot_interval' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the package that owns the schedule.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Scope: Filter active schedules
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Filter by availability type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('availability_type', $type);
    }

    /**
     * Scope: Filter by day configuration
     */
    public function scopeByDayConfiguration($query, string $dayConfig)
    {
        return $query->where('day_configuration', $dayConfig);
    }

    /**
     * Get time slots for this schedule based on a given date.
     *
     * @param string $date Date in Y-m-d format
     * @return array Array of time slots in H:i format
     */
    public function getTimeSlotsForDate(string $date): array
    {
        $slots = [];
        $start = strtotime($this->time_slot_start);
        $end = strtotime($this->time_slot_end);

        // Handle overnight slots (e.g., 3pm to 12am next day)
        if ($end <= $start) {
            $end += 86400; // Add 24 hours
        }

        $interval = $this->time_slot_interval * 60; // Convert to seconds

        for ($time = $start; $time < $end; $time += $interval) {
            $slots[] = date('H:i', $time);
        }

        return $slots;
    }

    /**
     * Check if this schedule matches a given date.
     *
     * @param string $date Date in Y-m-d format
     * @return bool
     */
    public function matchesDate(string $date): bool
    {
        $dateObj = \Carbon\Carbon::parse($date);

        switch ($this->availability_type) {
            case 'daily':
                // Daily schedules match every day
                return true;

            case 'weekly':
                // Check if day of week matches any in the array
                if (empty($this->day_configuration)) {
                    return false;
                }
                $currentDay = strtolower($dateObj->format('l'));
                foreach ($this->day_configuration as $day) {
                    if (strtolower($day) === $currentDay) {
                        return true;
                    }
                }
                return false;
                
            case 'monthly':
                // Check if date matches any monthly configuration in the array
                if (empty($this->day_configuration)) {
                    return false;
                }
                foreach ($this->day_configuration as $config) {
                    if ($this->matchesMonthlyConfiguration($dateObj, $config)) {
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    /**
     * Check if date matches a monthly configuration pattern.
     *
     * @param \Carbon\Carbon $date
     * @param string $config
     * @return bool
     */
    private function matchesMonthlyConfiguration(\Carbon\Carbon $date, string $config): bool
    {
        if (!$config) {
            return false;
        }

        // Parse configuration like 'first-monday', 'last-sunday', 'second-friday'
        $parts = explode('-', strtolower($config));

        [$occurrence, $dayName] = $parts;

        // Check if the day name matches
        if (strtolower($date->format('l')) !== $dayName) {
            return false;
        }

        // Check if it's the correct occurrence in the month
        if ($occurrence === 'last') {
            // Check if it's the last occurrence of this day in the month
            $nextWeek = $date->copy()->addWeek();
            return $nextWeek->month !== $date->month;
        } else {
            // Check for first, second, third, fourth
            $occurrenceMap = [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
            ];

            if (!isset($occurrenceMap[$occurrence])) {
                return false;
            }

            // Calculate which occurrence of this day in the month
            $dayOfMonth = $date->day;
            $occurrenceNumber = (int) ceil($dayOfMonth / 7);

            return $occurrenceNumber === $occurrenceMap[$occurrence];
        }
    }
}
