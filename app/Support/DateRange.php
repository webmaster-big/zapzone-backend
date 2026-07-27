<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class DateRange
{
    /**
     * The business/display timezone. The client operates in Michigan, and the UI
     * renders timestamps in this zone, so user-selected filter dates are interpreted here.
     */
    public static function businessTimezone(): string
    {
        return config('app.business_timezone', 'America/Detroit');
    }

    /**
     * The timezone timestamp columns are stored/compared in (the app runtime timezone).
     */
    public static function storageTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    /**
     * Start-of-day for a business-local date, expressed in the storage timezone.
     */
    public static function startOfDay(string $date): Carbon
    {
        return Carbon::parse($date, self::businessTimezone())->startOfDay()->setTimezone(self::storageTimezone());
    }

    /**
     * End-of-day for a business-local date, expressed in the storage timezone.
     */
    public static function endOfDay(string $date): Carbon
    {
        return Carbon::parse($date, self::businessTimezone())->endOfDay()->setTimezone(self::storageTimezone());
    }

    /**
     * Apply a timezone-aware date-range filter on a timestamp column.
     * Only use this on datetime/timestamp columns (created_at, paid_at, ...),
     * never on plain DATE columns (booking_date, purchase_date), which carry no timezone.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function apply($query, string $column, ?string $start, ?string $end)
    {
        if (!empty($start)) {
            $query->where($column, '>=', self::startOfDay($start));
        }
        if (!empty($end)) {
            $query->where($column, '<=', self::endOfDay($end));
        }

        return $query;
    }
}
