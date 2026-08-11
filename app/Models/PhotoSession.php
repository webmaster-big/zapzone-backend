<?php

namespace App\Models;

use App\Support\OperatingDay;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PhotoSession extends Model
{
    use HasFactory;

    public const SOURCE_STAFF = 'staff';
    public const SOURCE_KIOSK = 'kiosk';

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_PREVIEW = 'awaiting_preview';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';

    public const DELIVERY_WAIVER_MESSAGE = 'waiver_message';
    public const DELIVERY_STAFF_QR = 'staff_qr';
    public const DELIVERY_KIOSK_QR = 'kiosk_qr';

    public const SCHEDULE_IMMEDIATE = 'immediate';
    public const SCHEDULE_NEXT_DAY = 'next_day_9am';

    public const STAFF_MAX_PHOTOS = 3;
    public const KIOSK_MAX_PHOTOS = 1;
    public const QR_VALID_HOURS = 12;
    public const ACCESS_VALID_DAYS = 30;
    public const KIOSK_COUNTDOWN_SECONDS = 10;
    public const KIOSK_IDLE_SECONDS = 60;

    protected $fillable = [
        'company_id',
        'location_id',
        'source',
        'status',
        'created_by',
        'verbal_consent_at',
        'delivery_method',
        'delivery_schedule',
        'slideshow_opt_in',
        'access_token',
        'qr_token',
        'qr_expires_at',
        'access_expires_at',
        'captured_at',
        'capture_date',
        'operating_day',
        'kiosk_contact_name',
        'kiosk_contact_email',
        'kiosk_contact_phone',
        'kiosk_marketing_consent',
        'kiosk_contact_at',
        'qr_scan_count',
        'first_scanned_at',
        'accepted_at',
        'delivered_at',
        'purged_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_PROGRESS,
        'slideshow_opt_in' => false,
        'qr_scan_count' => 0,
    ];

    protected $casts = [
        'verbal_consent_at' => 'datetime',
        'slideshow_opt_in' => 'boolean',
        'qr_expires_at' => 'datetime',
        'access_expires_at' => 'datetime',
        'captured_at' => 'datetime',
        'capture_date' => 'date',
        'operating_day' => 'date',
        'kiosk_marketing_consent' => 'boolean',
        'kiosk_contact_at' => 'datetime',
        'qr_scan_count' => 'integer',
        'first_scanned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'purged_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (PhotoSession $session) {
            if (empty($session->access_token)) {
                $session->access_token = self::generateToken('access_token');
            }
            if (empty($session->qr_token)) {
                $session->qr_token = self::generateToken('qr_token');
            }
        });
    }

    public static function generateToken(string $column): string
    {
        do {
            $token = Str::random(48);
        } while (self::where($column, $token)->exists());

        return $token;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('position');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PhotoDelivery::class);
    }

    public function waivers(): BelongsToMany
    {
        return $this->belongsToMany(Waiver::class, 'photo_session_waivers')->withTimestamps();
    }

    public function maxPhotos(): int
    {
        return $this->source === self::SOURCE_KIOSK ? self::KIOSK_MAX_PHOTOS : self::STAFF_MAX_PHOTOS;
    }

    public function startQrWindow(): void
    {
        $now = now();
        $this->qr_expires_at = $now->copy()->addHours(self::QR_VALID_HOURS);
        $this->access_expires_at = $now->copy()->addDays(self::ACCESS_VALID_DAYS);
    }

    public function qrIsActive(): bool
    {
        return $this->qr_expires_at !== null && $this->qr_expires_at->isFuture();
    }

    public function accessIsActive(): bool
    {
        return $this->purged_at === null
            && $this->access_expires_at !== null
            && $this->access_expires_at->isFuture();
    }

    public function qrStatus(): string
    {
        return $this->qrIsActive() ? 'active' : 'expired';
    }

    public function accessStatus(): string
    {
        return $this->accessIsActive() ? 'active' : 'expired';
    }

    public function requiresContactBeforeAccess(): bool
    {
        return $this->delivery_method === self::DELIVERY_KIOSK_QR
            && $this->kiosk_contact_at === null;
    }

    public function deliveryStatus(): string
    {
        $deliveries = $this->relationLoaded('deliveries') ? $this->deliveries : $this->deliveries()->get();

        // Duplicate destinations and channels this site cannot send are both recorded for
        // the audit trail, but neither is a delivery in flight, so neither decides the status.
        $real = $deliveries
            ->whereNull('duplicate_of_id')
            ->where('status', '!=', PhotoDelivery::STATUS_SKIPPED);

        if ($real->isEmpty()) {
            return 'none';
        }
        if ($real->every(fn ($d) => $d->status === PhotoDelivery::STATUS_SCHEDULED)) {
            return PhotoDelivery::STATUS_SCHEDULED;
        }

        $attempted = $real->whereIn('status', [PhotoDelivery::STATUS_SENT, PhotoDelivery::STATUS_FAILED]);
        if ($attempted->isEmpty()) {
            return 'pending';
        }

        $sent = $attempted->where('status', PhotoDelivery::STATUS_SENT)->count();
        if ($sent === 0) {
            return 'failed';
        }
        if ($sent < $attempted->count()) {
            return 'partially_delivered';
        }

        return 'delivered';
    }

    public function timezone(): string
    {
        return OperatingDay::timezoneFor($this->location);
    }

    public function scopeForOperatingDay($query, string $day)
    {
        return $query->whereDate('operating_day', $day);
    }

    public function scopeLive($query)
    {
        return $query->whereNull('purged_at');
    }
}
