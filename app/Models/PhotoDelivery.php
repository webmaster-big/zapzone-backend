<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoDelivery extends Model
{
    use HasFactory;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const KIND_IMMEDIATE = 'immediate';
    public const KIND_NEXT_DAY = 'next_day';
    public const KIND_KIOSK = 'kiosk';
    public const KIND_BACKEND = 'backend';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'photo_session_id',
        'company_id',
        'location_id',
        'waiver_id',
        'duplicate_of_id',
        'kind',
        'channel',
        'destination',
        'recipient_name',
        'status',
        'scheduled_for',
        'sent_at',
        'opened_at',
        'attempts',
        'error',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'attempts' => 0,
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoSession::class, 'photo_session_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(PhotoDelivery::class, 'duplicate_of_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate_of_id !== null;
    }

    public function canRetry(): bool
    {
        return !$this->isDuplicate()
            && in_array($this->status, [self::STATUS_FAILED, self::STATUS_SENT], true);
    }

    public function canCancel(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function maskedDestination(): string
    {
        if ($this->channel === self::CHANNEL_EMAIL) {
            $parts = explode('@', $this->destination);
            if (count($parts) !== 2) {
                return $this->destination;
            }
            $name = $parts[0];
            $visible = mb_substr($name, 0, 2);

            return $visible . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $parts[1];
        }

        $digits = preg_replace('/[^0-9]/', '', $this->destination);

        return strlen($digits) > 4
            ? '(***) ***-' . substr($digits, -4)
            : $this->destination;
    }

    public function scopeDue($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now());
    }

    public function scopeReal($query)
    {
        return $query->whereNull('duplicate_of_id');
    }
}
