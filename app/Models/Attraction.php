<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'description',
        'price',
        'pricing_type',
        'max_capacity',
        'max_tickets_per_slot',
        'display_capacity_to_customers',
        'category',
        'unit',
        'duration',
        'duration_unit',
        'availability',
        'image',
        'rating',
        'min_age',
        'is_active',
        'add_ons_order',
        'display_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rating' => 'decimal:2',
        'duration' => 'decimal:2',
        'availability' => 'array',
        'image' => 'array',
        'is_active' => 'boolean',
        'display_capacity_to_customers' => 'boolean',
        'add_ons_order' => 'array',
        'display_order' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_attractions');
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_attractions');
    }

    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'attraction_add_ons');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(AttractionPurchase::class);
    }

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
        $values = \App\Support\VenueCategory::matchValues($category);

        if (empty($values)) {
            return $query;
        }

        return $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(category)'), $values);
    }

    public function scopeByPricingType($query, $pricingType)
    {
        return $query->where('pricing_type', $pricingType);
    }

    public function getBookedSeatsBySlot(string $date): array
    {
        $counts = $this->purchases()
            ->where('scheduled_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('scheduled_time')
            ->selectRaw("TIME_FORMAT(scheduled_time, '%H:%i') as slot_time, SUM(quantity) as seats")
            ->groupBy('slot_time')
            ->pluck('seats', 'slot_time')
            ->toArray();

        return array_map('intval', $counts);
    }

    public function remainingTicketsForSlot(string $date, string $time): ?int
    {
        if ($this->max_tickets_per_slot === null) {
            return null;
        }

        $taken = $this->getBookedSeatsBySlot($date)[substr($time, 0, 5)] ?? 0;

        return max(0, $this->max_tickets_per_slot - $taken);
    }
}
