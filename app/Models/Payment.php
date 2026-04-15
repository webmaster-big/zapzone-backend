<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Payment types for payable_type column
     */
    public const TYPE_BOOKING = 'booking';
    public const TYPE_ATTRACTION_PURCHASE = 'attraction_purchase';
    public const TYPE_EVENT_PURCHASE = 'event_purchase';

    protected $fillable = [
        'payable_id',
        'payable_type',
        'customer_id',
        'transaction_id',
        'amount',
        'currency',
        'method',
        'status',
        'notes',
        'signature_image',
        'terms_accepted',
        'paid_at',
        'refunded_at',
        'payment_id',
        'card_last_four',
        'avs_result_code',
        'cvv_result_code',
        'location_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'terms_accepted' => 'boolean',
    ];

    // Relationships

    /**
     * Get the parent payable model (Booking, AttractionPurchase, or EventPurchase).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the booking if payable_type is 'booking'.
     * This is a convenience method for backward compatibility.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'payable_id')
            ->where(fn($q) => $this->payable_type === self::TYPE_BOOKING);
    }

    /**
     * Get the attraction purchase if payable_type is 'attraction_purchase'.
     */
    public function attractionPurchase(): BelongsTo
    {
        return $this->belongsTo(AttractionPurchase::class, 'payable_id')
            ->where(fn($q) => $this->payable_type === self::TYPE_ATTRACTION_PURCHASE);
    }

    /**
     * Get the event purchase if payable_type is 'event_purchase'.
     */
    public function eventPurchase(): BelongsTo
    {
        return $this->belongsTo(EventPurchase::class, 'payable_id')
            ->where(fn($q) => $this->payable_type === self::TYPE_EVENT_PURCHASE);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // Scopes
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByMethod($query, $method)
    {
        return $query->where('method', $method);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForBookings($query)
    {
        return $query->where('payable_type', self::TYPE_BOOKING);
    }

    public function scopeForAttractionPurchases($query)
    {
        return $query->where('payable_type', self::TYPE_ATTRACTION_PURCHASE);
    }

    public function scopeForEventPurchases($query)
    {
        return $query->where('payable_type', self::TYPE_EVENT_PURCHASE);
    }

    public function scopeByPayableType($query, $type)
    {
        return $query->where('payable_type', $type);
    }

    // Helper methods

    /**
     * Check if this payment is for a booking (package).
     */
    public function isForBooking(): bool
    {
        return $this->payable_type === self::TYPE_BOOKING;
    }

    /**
     * Check if this payment is for an attraction purchase.
     */
    public function isForAttractionPurchase(): bool
    {
        return $this->payable_type === self::TYPE_ATTRACTION_PURCHASE;
    }

    /**
     * Check if this payment is for an event purchase.
     */
    public function isForEventPurchase(): bool
    {
        return $this->payable_type === self::TYPE_EVENT_PURCHASE;
    }

    /**
     * Get the payable entity details.
     */
    public function getPayableDetails()
    {
        if ($this->isForBooking()) {
            return Booking::withTrashed()->find($this->payable_id);
        } elseif ($this->isForAttractionPurchase()) {
            return AttractionPurchase::withTrashed()->find($this->payable_id);
        } elseif ($this->isForEventPurchase()) {
            return EventPurchase::withTrashed()->find($this->payable_id);
        }
        return null;
    }
}
