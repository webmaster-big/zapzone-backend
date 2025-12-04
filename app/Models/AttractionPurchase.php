<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttractionPurchase extends Model
{
    protected $fillable = [
        'attraction_id',
        'customer_id',
        'created_by',
        'guest_name',
        'guest_email',
        'guest_phone',
        'quantity',
        'total_amount',
        'payment_method',
        'status',
        'purchase_date',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'total_amount' => 'decimal:2',
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

    // by location
    public function scopeByLocation($query, $locationId)
    {
        return $query->whereHas('attraction', function ($q) use ($locationId) {
            $q->where('location_id', $locationId);
        });
    }
}
