<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutConcern extends Model
{
    use HasFactory;

    public const KIND_SCHEDULE_HELP = 'schedule_help';
    public const KIND_ABANDONED_CHECKOUT = 'abandoned_checkout';
    public const KIND_CALL_TO_BOOK = 'call_to_book';

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'company_id',
        'location_id',
        'contact_id',
        'kind',
        'name',
        'phone',
        'email',
        'message',
        'entity_type',
        'entity_id',
        'entity_name',
        'preferred_date',
        'preferred_time',
        'context',
        'status',
        'handled_by',
        'handled_at',
        'resolution_note',
        'alerted',
        'alert_after',
        'alerted_at',
        'fingerprint',
    ];

    protected $casts = [
        'context' => 'array',
        'alerted' => 'array',
        'preferred_date' => 'date',
        'handled_at' => 'datetime',
        'alert_after' => 'datetime',
        'alerted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByKind($query, $kind)
    {
        return $query->where('kind', $kind);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function scopeDueForAlert($query)
    {
        return $query->whereNull('alerted_at')
            ->whereNotNull('alert_after')
            ->where('alert_after', '<=', now());
    }

    public function isScheduleHelp(): bool
    {
        return $this->kind === self::KIND_SCHEDULE_HELP;
    }

    public function isCallToBook(): bool
    {
        return $this->kind === self::KIND_CALL_TO_BOOK;
    }

    public function getHeadlineAttribute(): string
    {
        return match ($this->kind) {
            self::KIND_CALL_TO_BOOK => 'Call to book request',
            self::KIND_SCHEDULE_HELP => 'Schedule help requested',
            default => 'Checkout left unfinished',
        };
    }

    public function getPreferredTimeLabelAttribute(): ?string
    {
        if (!$this->preferred_time) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($this->preferred_time)->format('g:i A');
        } catch (\Throwable $e) {
            return $this->preferred_time;
        }
    }

    public function getWhatTheyWantedAttribute(): string
    {
        $parts = array_filter([
            $this->entity_name,
            $this->preferred_date ? $this->preferred_date->format('D, M j, Y') : null,
            $this->preferred_time_label,
        ]);

        return $parts ? implode(' • ', $parts) : 'No date or item chosen yet';
    }
}
