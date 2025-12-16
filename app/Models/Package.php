<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'description',
        'category',
        'features',
        'price',
        'price_per_additional',
        'min_participants',
        'max_participants',
        'duration',
        'duration_unit',
        'price_per_additional_30min',
        'price_per_additional_1hr',
        'availability_type',
        'available_days',
        'available_week_days',
        'available_month_days',
        'image',
        'time_slot_start',
        'time_slot_end',
        'time_slot_interval',
        'is_active',
        'partial_payment_percentage',
        'partial_payment_fixed',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_per_additional' => 'decimal:2',
        'price_per_additional_30min' => 'decimal:2',
        'price_per_additional_1hr' => 'decimal:2',
        'partial_payment_fixed' => 'decimal:2',
        'available_days' => 'array',
        'available_week_days' => 'array',
        'available_month_days' => 'array',
        'features' => 'array',
        'image' => 'array',
        'is_active' => 'boolean',
        // NEW: Time slot fields
        'time_slot_start' => 'string',
        'time_slot_end' => 'string',
        'time_slot_interval' => 'integer',
    ];

    // Relationships
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function attractions(): BelongsToMany
    {
        return $this->belongsToMany(Attraction::class, 'package_attractions');
    }

    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'package_add_ons');
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'package_rooms');
    }

    public function giftCards(): BelongsToMany
    {
        return $this->belongsToMany(GiftCard::class, 'package_gift_cards');
    }

    public function promos(): BelongsToMany
    {
        return $this->belongsToMany(Promo::class, 'package_promos');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
