<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'qr_code_path',
        'customer_id',
        'package_id',
        'location_id',
        'room_id',
        'created_by',
        'guest_name',
        'guest_email',
        'guest_phone',
        'guest_address',
        'guest_city',
        'guest_state',
        'guest_zip',
        'guest_country',
        'gift_card_id',
        'promo_id',
        'type',
        'booking_date',
        'booking_time',
        'participants',
        'duration',
        'duration_unit',
        'total_amount',
        'amount_paid',
        'discount_amount',
        'payment_method',
        'payment_status',
        'transaction_id',
        'status',
        'notes',
        'internal_notes',
        'special_requests',
        'guest_of_honor_name',
        'guest_of_honor_age',
        'guest_of_honor_gender',
        'checked_in_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'booking_time' => 'datetime:H:i',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'duration' => 'decimal:2',
        'guest_of_honor_age' => 'integer',
        'checked_in_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function attractions(): BelongsToMany
    {
        return $this->belongsToMany(Attraction::class, 'booking_attractions', 'booking_id', 'attraction_id')
            ->withPivot('quantity', 'price_at_booking')
            ->withTimestamps();
    }

    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'booking_add_ons', 'booking_id', 'add_on_id')
            ->withPivot('quantity', 'price_at_booking')
            ->withTimestamps();
    }

    /**
     * Get all payments for this booking.
     * Uses polymorphic relationship with payable_type = 'booking'
     */
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

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByDate($query, $date)
    {
        return $query->where('booking_date', $date);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('booking_date', '>=', now()->toDateString());
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

    public function getCustomerNameAttribute(): ?string
    {
        if ($this->customer) {
            return $this->customer->first_name . ' ' . $this->customer->last_name;
        }
        return $this->guest_name;
    }

    public function getCustomerEmailAttribute(): ?string
    {
        if ($this->customer) {
            return $this->customer->email;
        }
        return $this->guest_email;
    }

    public function getCustomerPhoneAttribute(): ?string
    {
        if ($this->customer) {
            return $this->customer->phone;
        }
        return $this->guest_phone;
    }
}
