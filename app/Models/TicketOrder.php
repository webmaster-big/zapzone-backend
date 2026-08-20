<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TicketOrder extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked-in';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    public const REFERENCE_PREFIX = 'ORD';

    protected $fillable = [
        'reference_number',
        'company_id',
        'location_id',
        'customer_id',
        'membership_id',
        'created_by',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_address',
        'guest_city',
        'guest_state',
        'guest_zip',
        'guest_country',
        'purchase_date',
        'item_count',
        'ticket_count',
        'subtotal',
        'discount_amount',
        'membership_discount',
        'fee_total',
        'total_amount',
        'amount_paid',
        'applied_fees',
        'applied_discounts',
        'promo_id',
        'gift_card_id',
        'payment_method',
        'status',
        'transaction_id',
        'notes',
        'expires_at',
        'confirmed_at',
        'cancelled_at',
        'qr_code',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'item_count' => 'integer',
        'ticket_count' => 'integer',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'membership_discount' => 'decimal:2',
        'fee_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'applied_fees' => 'array',
        'applied_discounts' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function generateReference(): string
    {
        do {
            $candidate = self::REFERENCE_PREFIX . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (self::withTrashed()->where('reference_number', $candidate)->exists());

        return $candidate;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function attractionPurchases(): HasMany
    {
        return $this->hasMany(AttractionPurchase::class)->orderBy('line_position');
    }

    public function eventPurchases(): HasMany
    {
        return $this->hasMany(EventPurchase::class)->orderBy('line_position');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function getCustomerNameAttribute(): string
    {
        if ($this->customer) {
            return trim($this->customer->first_name . ' ' . $this->customer->last_name);
        }

        return $this->guest_name ?? 'Guest';
    }

    public function getCustomerEmailAttribute(): ?string
    {
        return $this->customer->email ?? $this->guest_email;
    }

    public function getCustomerPhoneAttribute(): ?string
    {
        return $this->customer->phone ?? $this->guest_phone;
    }

    public function getRemainingBalanceAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->amount_paid, 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->remaining_balance <= 0.0;
    }

    public function lines()
    {
        return $this->attractionPurchases
            ->map(fn (AttractionPurchase $line) => [
                'type' => 'attraction',
                'model' => $line,
                'position' => $line->line_position,
            ])
            ->concat($this->eventPurchases->map(fn (EventPurchase $line) => [
                'type' => 'event',
                'model' => $line,
                'position' => $line->line_position,
            ]))
            ->sortBy('position')
            ->values();
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_DRAFT, self::STATUS_CANCELLED]);
    }
}
