<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventPurchase extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number',
        'event_id',
        'customer_id',
        'location_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'purchase_date',
        'purchase_time',
        'quantity',
        'total_amount',
        'applied_fees',
        'amount_paid',
        'discount_amount',
        'payment_method',
        'payment_status',
        'status',
        'transaction_id',
        'notes',
        'special_requests',
        'checked_in_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_time' => 'datetime:H:i',
        'total_amount' => 'decimal:2',
        'applied_fees' => 'array',
        'amount_paid' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'checked_in_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Relationships
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'event_purchase_add_ons', 'event_purchase_id', 'add_on_id')
            ->withPivot('quantity', 'price_at_purchase')
            ->withTimestamps();
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByEvent($query, $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('purchase_date', $date);
    }

    // Helpers
    public function getRemainingBalance(): float
    {
        return $this->total_amount - $this->amount_paid;
    }

    public function isFullyPaid(): bool
    {
        return $this->amount_paid >= $this->total_amount;
    }
}
