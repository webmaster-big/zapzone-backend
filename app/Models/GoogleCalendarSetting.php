<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoogleCalendarSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_secret',
        'frontend_redirect_url',
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
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the single settings record (singleton pattern).
     */
    public static function getSettings(): ?self
    {
        return static::first();
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
