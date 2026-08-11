<?php

namespace App\Support;

use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class OperatingDay
{
    public const CUTOFF_HOUR = 6;

    public const DEFAULT_TIMEZONE = 'America/Detroit';

    public static function timezoneFor(?Location $location): string
    {
        $tz = trim((string) ($location->timezone ?? ''));

        if ($tz === '' || strtoupper($tz) === 'UTC') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            return self::DEFAULT_TIMEZONE;
        }

        return $tz;
    }

    public static function localNow(?Location $location): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezoneFor($location));
    }

    public static function forLocation(?Location $location, ?Carbon $at = null): string
    {
        $tz = self::timezoneFor($location);
        $local = $at ? $at->copy()->setTimezone($tz) : Carbon::now($tz);

        if ($local->hour < self::CUTOFF_HOUR) {
            $local = $local->copy()->subDay();
        }

        return $local->toDateString();
    }

    public static function calendarDateFor(?Location $location, ?Carbon $at = null): string
    {
        $tz = self::timezoneFor($location);
        $local = $at ? $at->copy()->setTimezone($tz) : Carbon::now($tz);

        return $local->toDateString();
    }

    public static function cutoffAfter(?Location $location, string $operatingDay): Carbon
    {
        $tz = self::timezoneFor($location);

        return Carbon::parse($operatingDay, $tz)
            ->addDay()
            ->setTime(self::CUTOFF_HOUR, 0)
            ->setTimezone(config('app.timezone'));
    }

    public static function nextNineAm(?Location $location, ?Carbon $from = null): Carbon
    {
        $tz = self::timezoneFor($location);
        $local = $from ? $from->copy()->setTimezone($tz) : Carbon::now($tz);
        $target = $local->copy()->addDay()->setTime(9, 0);

        return $target->setTimezone(config('app.timezone'));
    }

    public static function label(?Location $location, string $operatingDay): string
    {
        return Carbon::parse($operatingDay)->format('D, M j, Y');
    }
}
