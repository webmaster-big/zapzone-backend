<?php

namespace App\Models;

use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VisitFollowUp extends Model
{
    public const VISIT_BOOKING = 'booking';
    public const VISIT_ESCAPE_ROOM_GAME = 'escape_room_session';
    public const VISIT_EVENT_PURCHASE = 'event_purchase';

    public const VISIT_TYPES = [self::VISIT_BOOKING, self::VISIT_ESCAPE_ROOM_GAME, self::VISIT_EVENT_PURCHASE];

    public const KIND_THANKS = 'thanks';
    public const KIND_REVIEW = 'review';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELED = 'canceled';

    public const MAX_ATTEMPTS = 3;

    public const REASON_REOPENED = 'reopened';
    public const REASON_SWITCHED_OFF = 'switched_off';
    public const REASON_VISIT_DATE = 'visit_date';
    public const REASON_OPTED_OUT = 'opted_out';
    public const REASON_ASKED_RECENTLY = 'asked_recently';
    public const REASON_REDIRECTED = 'redirected';
    public const REASON_LEFT_GAME = 'left_game';
    public const REASON_RECIPIENT_CHANGED = 'recipient_changed';
    public const REASON_VISIT_GONE = 'visit_gone';
    public const REASON_STAFF = 'staff';

    protected $fillable = [
        'company_id',
        'location_id',
        'visit_type',
        'visit_id',
        'kind',
        'email_notification_id',
        'waiver_id',
        'customer_id',
        'recipient_email',
        'recipient_name',
        'status',
        'due_at',
        'attempts',
        'sent_at',
        'error',
        'reason',
        'rating',
        'comment',
        'rated_at',
        'alert_notification_id',
        'created_by',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'due_at' => 'datetime',
        'sent_at' => 'datetime',
        'rated_at' => 'datetime',
        'attempts' => 'integer',
        'rating' => 'integer',
    ];

    public static function isAvailable(): bool
    {
        return SchemaSupport::hasTable('visit_follow_ups');
    }

    protected static function booted(): void
    {
        static::creating(function (VisitFollowUp $followUp) {
            if ($followUp->kind === self::KIND_REVIEW && empty($followUp->token)) {
                $followUp->token = Str::random(48);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(EmailNotification::class, 'email_notification_id');
    }

    public function scopeForVisit($query, string $visitType, int $visitId)
    {
        return $query->where('visit_type', $visitType)->where('visit_id', $visitId);
    }

    public function scopeDue($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)->where('due_at', '<=', now());
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', self::STATUS_FAILED)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(10));
    }

    public function gaveUp(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function canRevive(): bool
    {
        if ($this->gaveUp()) {
            return true;
        }

        return in_array($this->status, [self::STATUS_SKIPPED, self::STATUS_CANCELED], true)
            && $this->reason !== self::REASON_STAFF;
    }

    public function maskedEmail(): string
    {
        return \App\Services\WaiverProfileService::maskEmail($this->recipient_email) ?? '';
    }

    public function feedbackUrl(?int $rating = null): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/') . '/feedback/' . $this->token;

        return $rating === null ? $base : $base . '?rating=' . $rating;
    }

    public function toStaffArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'visit_type' => $this->visit_type,
            'visit_id' => $this->visit_id,
            'recipient_name' => $this->recipient_name,
            'recipient_email_masked' => $this->maskedEmail(),
            'waiver_id' => $this->waiver_id,
            'status' => $this->status,
            'gave_up' => $this->gaveUp(),
            'due_at' => $this->due_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'error' => $this->error,
            'reason' => $this->reason,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'rated_at' => $this->rated_at?->toIso8601String(),
            'email_notification_id' => $this->email_notification_id,
        ];
    }
}
