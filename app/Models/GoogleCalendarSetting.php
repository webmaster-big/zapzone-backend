<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleCalendarSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'google_account_email',
        'calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_connected',
        'last_synced_at',
        'sync_from_date',
        'metadata',
    ];

    protected $casts = [
        'is_connected' => 'boolean',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sync_from_date' => 'date',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the location this Google Calendar setting belongs to.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get settings for a specific location.
     */
    public static function getSettings(?int $locationId = null): ?self
    {
        if ($locationId) {
            return static::where('location_id', $locationId)->first();
        }

        // Fallback: return first record (legacy / no location specified)
        return static::first();
    }

    /**
     * Get or create settings for a specific location.
     */
    public static function getOrCreateForLocation(int $locationId): self
    {
        return static::firstOrCreate(
            ['location_id' => $locationId],
            ['calendar_id' => 'primary', 'is_connected' => false]
        );
    }

    /**
     * Check if the token is expired.
     */
    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }

        return $this->token_expires_at->isPast();
    }
}
