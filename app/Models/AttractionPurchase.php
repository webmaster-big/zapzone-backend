<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttractionPurchase extends Model
{
    use SoftDeletes;
    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked-in';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    protected $fillable = [
        'attraction_id',
        'customer_id',
        'created_by',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_address',
        'guest_city',
        'guest_state',
        'guest_zip',
        'guest_country',
        'quantity',
        'total_amount',
        'amount_paid',
        'payment_method',
        'status',
        'transaction_id',
        'purchase_date',
        'scheduled_date',
        'scheduled_time',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'scheduled_date' => 'date',
        'scheduled_time' => 'datetime:H:i',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Get customer name (from customer or guest)
     */
    public function getCustomerNameAttribute(): string
    {
        if ($this->customer) {
            return $this->customer->first_name . ' ' . $this->customer->last_name;
        }
        return $this->guest_name ?? 'Guest';
    }

    /**
     * Get customer email (from customer or guest)
     */
    public function getCustomerEmailAttribute(): ?string
    {
        if ($this->customer) {
            return $this->customer->email;
        }
        return $this->guest_email;
    }

    /**
     * Get customer phone (from customer or guest)
     */
    public function getCustomerPhoneAttribute(): ?string
    {
        if ($this->customer) {
            return $this->customer->phone;
        }
        return $this->guest_phone;
    }

    public function attraction()
    {
        return $this->belongsTo(Attraction::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all payments for this attraction purchase.
     * Uses polymorphic relationship with payable_type = 'attraction_purchase'
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // by location
    public function scopeByLocation($query, $locationId)
    {
        return $query->whereHas('attraction', function ($q) use ($locationId) {
            $q->where('location_id', $locationId);
        });
    }
}
