<?php

namespace App\Models;

use App\Models\Concerns\ExcludesHeavyColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Waiver extends Model
{
    use ExcludesHeavyColumns, HasFactory, SoftDeletes;

    public const HEAVY_COLUMNS = ['signature_image'];

    protected $fillable = [
        'company_id',
        'location_id',
        'waiver_template_id',
        'waiver_template_version_id',
        'customer_id',
        'waiver_profile_id',
        'booking_id',
        'package_id',
        'attraction_id',
        'event_id',
        'attraction_purchase_id',
        'bulk_invite_id',
        'bulk_invite_recipient_id',
        'status',
        'selected_date',
        'manual_activity_name',
        'adult_first_name',
        'adult_last_name',
        'adult_email',
        'adult_phone',
        'adult_dob',
        'relationship',
        'typed_legal_name',
        'signature_image',
        'agreement_accepted',
        'electronic_consent_accepted',
        'photo_video_consent',
        'marketing_consent_status',
        'marketing_consent_at',
        'marketing_consent_source',
        'source',
        'ip_address',
        'device',
        'device_id',
        'browser',
        'operating_system',
        'user_agent',
        'read_seconds',
        'gps_latitude',
        'gps_longitude',
        'gps_accuracy',
        'pdf_path',
        'pdf_hash',
        'pdf_generated_at',
        'submitted_at',
        'checked_in_at',
        'checked_in_by',
        'expires_at',
        'reminder_sent',
        'reminder_sent_at',
        'replaced_by_waiver_id',
        'access_token',
        'created_by',
        'assigned_by',
        'is_manager_assigned',
        'deleted_by',
    ];

    protected $casts = [
        'selected_date' => 'date',
        'adult_dob' => 'date',
        'agreement_accepted' => 'boolean',
        'electronic_consent_accepted' => 'boolean',
        'photo_video_consent' => 'boolean',
        'marketing_consent_at' => 'datetime',
        'read_seconds' => 'integer',
        'gps_latitude' => 'float',
        'gps_longitude' => 'float',
        'gps_accuracy' => 'float',
        'pdf_generated_at' => 'datetime',
        'submitted_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'expires_at' => 'date',
        'reminder_sent' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'is_manager_assigned' => 'boolean',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_DELETED = 'deleted';

    public const MARKETING_NOT_OPTED_IN = 'not_opted_in';
    public const MARKETING_OPTED_IN = 'opted_in';
    public const MARKETING_WITHDRAWN = 'withdrawn';

    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_CONFIRMATION_EMAIL = 'confirmation_email';
    public const SOURCE_SMS_LINK = 'sms_link';
    public const SOURCE_KIOSK = 'kiosk';
    public const SOURCE_STAFF_SENT = 'staff_sent';
    public const SOURCE_BULK_INVITE = 'bulk_invite';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Waiver $waiver) {
            if (empty($waiver->access_token)) {
                $waiver->access_token = self::generateUniqueToken();
            }
        });
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('access_token', $token)->exists());

        return $token;
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WaiverProfile::class, 'waiver_profile_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplate::class, 'waiver_template_id')->withTrashed();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplateVersion::class, 'waiver_template_version_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function attraction(): BelongsTo
    {
        return $this->belongsTo(Attraction::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attractionPurchase(): BelongsTo
    {
        return $this->belongsTo(AttractionPurchase::class);
    }

    public function bulkInvite(): BelongsTo
    {
        return $this->belongsTo(WaiverBulkInvite::class, 'bulk_invite_id');
    }

    public function inviteRecipient(): BelongsTo
    {
        return $this->belongsTo(WaiverInviteRecipient::class, 'bulk_invite_recipient_id');
    }

    public function minors(): HasMany
    {
        return $this->hasMany(WaiverMinor::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(WaiverAuditEvent::class)->orderBy('occurred_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForDate(Builder $query, $date): Builder
    {
        return $query->whereDate('selected_date', $date);
    }

    public function getAdultFullNameAttribute(): string
    {
        return trim(($this->adult_first_name ?? '') . ' ' . ($this->adult_last_name ?? ''));
    }

    /** Public customer-facing URL for completing this waiver. */
    public function getSigningUrlAttribute(): string
    {
        $base = rtrim(config('app.frontend_url', config('app.url', '')), '/');
        return $base . '/waiver/' . $this->access_token;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
