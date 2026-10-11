<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BookingTombstone extends Model
{
    public const KEEP_HOURS = 3;

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'location_id',
        'removed_at',
    ];

    protected $casts = [
        'removed_at' => 'datetime',
    ];

    private static ?bool $tableExists = null;

    public static function tableExists(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable((new static())->getTable());
            } catch (\Throwable $e) {
                return false;
            }
        }

        return self::$tableExists;
    }

    public static function forgetTableCheck(?bool $known = null): void
    {
        self::$tableExists = $known;
    }

    public static function recordMany(iterable $bookings): void
    {
        if (!self::tableExists()) {
            return;
        }

        try {
            $now = now();
            $rows = [];

            foreach ($bookings as $booking) {
                $rows[] = ['booking_id' => $booking->id, 'location_id' => $booking->location_id, 'removed_at' => $now];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                static::insert($chunk);
            }

            static::where('removed_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
        } catch (\Throwable $e) {
            Log::warning('Removed bookings could not be recorded for open admin lists', [
                'count' => isset($rows) ? count($rows) : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function recordMovedFrom(Booking $booking, ?int $fromLocationId): void
    {
        if ($fromLocationId === null || (int) $fromLocationId === (int) $booking->location_id || !self::tableExists()) {
            return;
        }

        try {
            static::create([
                'booking_id' => $booking->id,
                'location_id' => $fromLocationId,
                'removed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('A moved booking could not be recorded for the old location\'s admin lists', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function record(Booking $booking): void
    {
        try {
            static::create([
                'booking_id' => $booking->id,
                'location_id' => $booking->location_id,
                'removed_at' => now(),
            ]);

            static::where('removed_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
        } catch (\Throwable $e) {
            Log::warning('A removed booking could not be recorded for open admin lists', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
