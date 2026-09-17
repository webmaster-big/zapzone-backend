<?php

namespace App\Support;

/**
 * A booking's length, written the way a guest reads it.
 *
 * duration_unit is a stored enum whose third value is the literal string "hours and minutes", so
 * printing the number and the unit side by side emails a 90 minute party "1.5 hours and minutes".
 * This mirrors the front end's formatDurationDisplay so a guest reads the same words in their
 * confirmation email as on the page they booked it.
 */
class DurationLabel
{
    public static function make($duration, ?string $unit): string
    {
        if ($duration === null || $duration === '') {
            return 'Not specified';
        }

        $value = is_numeric($duration) ? (float) $duration : null;

        if ($value === null) {
            return 'Not specified';
        }

        if ($value <= 0) {
            return 'Not specified';
        }

        if ($unit === 'minutes') {
            return self::fromMinutes((int) round($value));
        }

        // "hours" and "hours and minutes" are both stored as a decimal number of hours
        return self::fromMinutes((int) round($value * 60));
    }

    private static function fromMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($rest === 0) {
            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $hours.' hr '.$rest.' min';
    }
}
