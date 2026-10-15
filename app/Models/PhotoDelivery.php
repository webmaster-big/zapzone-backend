<?php

namespace App\Models;

use App\Services\SmsService;
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
    public const KIND_ESCAPE_ROOM = 'escape_room';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    public const CARRIER_CHECK_HOURS = 72;

    public const RECEIPT_VIA_STAFF = 'staff';
    public const RECEIPT_VIA_LINK = 'link';

    public const OUTCOME_RECEIVED = 'received';
    public const OUTCOME_DELIVERED = 'delivered';
    public const OUTCOME_AWAITING = 'awaiting_carrier';
    public const OUTCOME_UNCONFIRMED = 'unconfirmed';
    public const OUTCOME_NOT_DELIVERED = 'not_delivered';
    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_SCHEDULED = 'scheduled';
    public const OUTCOME_QUEUED = 'queued';
    public const OUTCOME_CANCELED = 'canceled';

    /**
     * How long to wait before the next automatic attempt, keyed by attempts already made.
     */
    public const RETRY_BACKOFF_MINUTES = [
        1 => 15,
        2 => 120,
    ];

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
        'provider_sid',
        'provider_from',
        'provider_status',
        'provider_error_code',
        'provider_checked_at',
        'delivered_at',
        'receipt_confirmed_at',
        'receipt_confirmed_by',
        'receipt_confirmed_via',
        'link_nonce',
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
        'provider_checked_at' => 'datetime',
        'delivered_at' => 'datetime',
        'receipt_confirmed_at' => 'datetime',
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

    public function receiptConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receipt_confirmed_by');
    }

    public function isText(): bool
    {
        return $this->channel === self::CHANNEL_SMS;
    }

    public function carrierSaysDelivered(): bool
    {
        return in_array((string) $this->provider_status, ['delivered', 'read'], true);
    }

    public function carrierSaysNotDelivered(): bool
    {
        return in_array((string) $this->provider_status, SmsService::NOT_DELIVERED_STATUSES, true);
    }

    public function isAwaitingCarrier(): bool
    {
        return $this->isText()
            && $this->status === self::STATUS_SENT
            && $this->provider_sid !== null
            && !SmsService::isFinalStatus($this->provider_status)
            && $this->sent_at !== null
            && $this->sent_at->greaterThanOrEqualTo(now()->startOfSecond()->subHours(self::CARRIER_CHECK_HOURS));
    }

    public function isStillWithProvider(): bool
    {
        return $this->provider_sid !== null
            && in_array((string) $this->provider_status, SmsService::PENDING_STATUSES, true);
    }

    public function canConfirmReceipt(): bool
    {
        return $this->canRecordGuestReceipt()
            && !$this->isStillWithProvider()
            && ($this->isAwaitingCarrier() || $this->carrierSaysDelivered());
    }

    public function canRecordGuestReceipt(): bool
    {
        return $this->isText()
            && !$this->isDuplicate()
            && $this->status === self::STATUS_SENT
            && $this->provider_sid !== null
            && $this->receipt_confirmed_at === null
            && !$this->carrierSaysNotDelivered();
    }

    public function retryIsUseful(): bool
    {
        return !in_array((string) $this->provider_error_code, SmsService::GUEST_PERMANENT_CODES, true);
    }

    public function senderIsCurrent(): bool
    {
        $from = $this->provider_from ? (SmsService::toE164($this->provider_from) ?? $this->provider_from) : null;

        return $from !== null && $from === SmsService::senderNumber();
    }

    public function reachedGuest(): bool
    {
        if ($this->isDuplicate() || $this->status !== self::STATUS_SENT) {
            return false;
        }

        if (!$this->isText()) {
            return true;
        }

        return !$this->carrierSaysNotDelivered()
            && ($this->receipt_confirmed_at !== null || $this->carrierSaysDelivered());
    }

    public function outcome(): string
    {
        if ($this->isDuplicate()) {
            return self::OUTCOME_DUPLICATE;
        }

        if ($this->status !== self::STATUS_SENT) {
            return match ($this->status) {
                self::STATUS_SCHEDULED => self::OUTCOME_SCHEDULED,
                self::STATUS_FAILED => self::OUTCOME_FAILED,
                self::STATUS_SKIPPED => self::OUTCOME_SKIPPED,
                self::STATUS_CANCELED => self::OUTCOME_CANCELED,
                default => self::OUTCOME_QUEUED,
            };
        }

        if (!$this->isText()) {
            return self::OUTCOME_SENT;
        }
        if ($this->carrierSaysNotDelivered()) {
            return self::OUTCOME_NOT_DELIVERED;
        }
        if ($this->receipt_confirmed_at !== null) {
            return self::OUTCOME_RECEIVED;
        }
        if ($this->carrierSaysDelivered()) {
            return self::OUTCOME_DELIVERED;
        }

        return $this->isAwaitingCarrier() ? self::OUTCOME_AWAITING : self::OUTCOME_UNCONFIRMED;
    }

    public function outcomeTone(): string
    {
        return match ($this->outcome()) {
            self::OUTCOME_RECEIVED, self::OUTCOME_DELIVERED, self::OUTCOME_SENT => 'success',
            self::OUTCOME_AWAITING, self::OUTCOME_SCHEDULED, self::OUTCOME_QUEUED => 'info',
            self::OUTCOME_UNCONFIRMED, self::OUTCOME_SKIPPED => 'warning',
            self::OUTCOME_NOT_DELIVERED, self::OUTCOME_FAILED => 'danger',
            default => 'muted',
        };
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome()) {
            self::OUTCOME_RECEIVED => "Received on the guest's phone",
            self::OUTCOME_DELIVERED => 'Delivered to the phone',
            self::OUTCOME_AWAITING => 'Waiting for the carrier to confirm',
            self::OUTCOME_UNCONFIRMED => 'Sent, arrival not confirmed',
            self::OUTCOME_NOT_DELIVERED => 'Not delivered',
            self::OUTCOME_SENT => 'Sent',
            self::OUTCOME_FAILED => 'Failed',
            self::OUTCOME_SKIPPED => 'Not sent',
            self::OUTCOME_DUPLICATE => 'Not sent twice',
            self::OUTCOME_SCHEDULED => 'Scheduled',
            self::OUTCOME_CANCELED => 'Canceled',
            default => 'Waiting to send',
        };
    }

    public function outcomeDetail(): ?string
    {
        return match ($this->outcome()) {
            self::OUTCOME_RECEIVED => $this->receipt_confirmed_via === self::RECEIPT_VIA_LINK
                ? 'The guest opened the photo link from this text, so it reached their phone.'
                : 'A staff member saw this text on the guest\'s phone.',
            self::OUTCOME_DELIVERED => 'The phone carrier confirmed the text reached the phone.',
            self::OUTCOME_AWAITING => 'The text provider accepted it. The phone carrier has not confirmed delivery yet.',
            self::OUTCOME_UNCONFIRMED => $this->provider_sid === null
                ? 'This text was sent before delivery tracking was added, so there is no way to confirm it arrived.'
                : 'The phone carrier never confirmed whether this text arrived.',
            self::OUTCOME_NOT_DELIVERED => SmsService::meaningOf($this->provider_error_code)
                ?? ('The phone carrier did not deliver it' . ($this->provider_error_code ? ' (code ' . $this->provider_error_code . ').' : '.')),
            self::OUTCOME_SENT => 'Handed to the email service.',
            self::OUTCOME_FAILED, self::OUTCOME_SKIPPED => $this->error,
            self::OUTCOME_DUPLICATE => $this->isText()
                ? 'Another guest in this send has the same number, so only one text went to it. That row shows how it went.'
                : 'Another guest in this send has the same email address, so only one email went to it. That row shows how it went.',
            self::OUTCOME_QUEUED => 'This has not been sent yet.',
            default => null,
        };
    }

    public function linkRef(): string
    {
        return base_convert((string) $this->id, 10, 36) . '-' . self::linkSignature((int) $this->id, (string) $this->link_nonce);
    }

    public static function fromLinkRef(int $sessionId, ?string $ref): ?self
    {
        $ref = trim((string) $ref);

        if ($ref === '' || strlen($ref) > 40 || !preg_match('/^([0-9a-z]{1,12})-([0-9a-f]{8})$/', $ref, $parts)) {
            return null;
        }

        $id = (int) base_convert($parts[1], 36, 10);

        if ($id <= 0) {
            return null;
        }

        $delivery = self::where('id', $id)->where('photo_session_id', $sessionId)->first();

        if (!$delivery || !hash_equals(self::linkSignature($id, (string) $delivery->link_nonce), $parts[2])) {
            return null;
        }

        return $delivery;
    }

    protected static function linkSignature(int $id, string $nonce): string
    {
        return substr(hash_hmac('sha256', 'photo-delivery-link|' . $id . '|' . $nonce, (string) config('app.key')), 0, 8);
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

    /**
     * Deliveries that failed on a first or second attempt and are worth another try.
     *
     * A momentary provider or network problem used to strand a visitor's photo link for
     * good, because nothing drove attempts two and three except a staff member noticing
     * the failure and pressing Retry. The wait grows between attempts so a genuinely bad
     * address is not hammered.
     */
    public function scopeRetryable($query)
    {
        return $query->where('status', self::STATUS_FAILED)
            ->where('attempts', '>=', 1)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($q) {
                $q->whereNull('provider_error_code')
                    ->orWhereNotIn('provider_error_code', SmsService::GUEST_PERMANENT_CODES);
            })
            ->where(function ($q) {
                foreach (self::RETRY_BACKOFF_MINUTES as $attempts => $minutes) {
                    $q->orWhere(function ($inner) use ($attempts, $minutes) {
                        $inner->where('attempts', $attempts)
                            ->where('updated_at', '<=', now()->subMinutes($minutes));
                    });
                }
            });
    }

    public function scopeAwaitingCarrier($query)
    {
        return $query->where('channel', self::CHANNEL_SMS)
            ->where('status', self::STATUS_SENT)
            ->whereNotNull('provider_sid')
            ->where(function ($q) {
                $q->whereNull('provider_status')
                    ->orWhereNotIn('provider_status', SmsService::FINAL_STATUSES);
            })
            ->where('sent_at', '>=', now()->subHours(self::CARRIER_CHECK_HOURS));
    }
}
