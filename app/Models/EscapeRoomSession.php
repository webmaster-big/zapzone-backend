<?php

namespace App\Models;

use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscapeRoomSession extends Model
{
    protected $fillable = [
        'company_id',
        'location_id',
        'package_id',
        'session_date',
        'session_time',
        'photo_session_id',
        'escaped',
        'completion_seconds',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected $casts = [
        'session_date' => 'date',
        'escaped' => 'boolean',
        'completion_seconds' => 'integer',
        'completed_at' => 'datetime',
    ];

    public static function isAvailable(): bool
    {
        return SchemaSupport::hasTable('escape_room_sessions');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function photoSession(): BelongsTo
    {
        return $this->belongsTo(PhotoSession::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
    }

    public function timeKey(): string
    {
        return substr((string) $this->session_time, 0, 5);
    }

    public function dateKey(): string
    {
        return $this->session_date?->toDateString() ?? '';
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public static function formatSeconds(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $minutes = intdiv($seconds, 60);

        return sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    public function completionLabel(): string
    {
        if ($this->completed_at === null) {
            return '';
        }

        if ($this->escaped === false) {
            return 'Did not escape';
        }

        return self::formatSeconds($this->completion_seconds);
    }

    public function resultLabel(): string
    {
        if ($this->completed_at === null) {
            return '';
        }

        if ($this->escaped === false) {
            return "Your group didn't escape this time. Come back and try again!";
        }

        return 'Your group escaped in ' . self::formatSeconds($this->completion_seconds) . '!';
    }
}
