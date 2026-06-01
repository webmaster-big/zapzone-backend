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

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('availability_type', $type);
    }

    public function scopeByDayConfiguration($query, string $dayConfig)
    {
        return $query->where('day_configuration', $dayConfig);
    }

    public function getTimeSlotsForDate(string $date, int $durationMinutes = 0): array
    {
        $slots = [];
        $start = strtotime($this->time_slot_start);
        $end = strtotime($this->time_slot_end);

        if ($end <= $start) {
            $end += 86400; // Add 24 hours
        }

        $interval = $this->time_slot_interval * 60; // Convert to seconds
        $durationSeconds = $durationMinutes * 60;

        for ($time = $start; $time < $end; $time += $interval) {
            if ($durationSeconds > 0 && ($time + $durationSeconds) > $end) {
                break;
            }
            $slots[] = date('H:i', $time);
        }

        return $slots;
    }

    public function matchesDate(string $date): bool
    {
        $dateObj = \Carbon\Carbon::parse($date);

        switch ($this->availability_type) {
            case 'daily':
                return true;

            case 'weekly':
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

    private function matchesMonthlyConfiguration(\Carbon\Carbon $date, string $config): bool
    {
        if (!$config) {
            return false;
        }

        $parts = explode('-', strtolower($config));

        [$occurrence, $dayName] = $parts;

        if (strtolower($date->format('l')) !== $dayName) {
            return false;
        }

        if ($occurrence === 'last') {
            $nextWeek = $date->copy()->addWeek();
            return $nextWeek->month !== $date->month;
        } else {
            $occurrenceMap = [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
            ];

            if (!isset($occurrenceMap[$occurrence])) {
                return false;
            }

            $dayOfMonth = $date->day;
            $occurrenceNumber = (int) ceil($dayOfMonth / 7);

            return $occurrenceNumber === $occurrenceMap[$occurrence];
        }
    }
}
