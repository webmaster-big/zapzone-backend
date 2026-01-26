<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'location_id',
        'name',
        'description',
        'category',
        'package_type',
        'features',
        'price',
        'price_per_additional',
        'min_participants',
        'max_participants',
        'duration',
        'duration_unit',
        'price_per_additional_30min',
        'price_per_additional_1hr',
        'image',
        'is_active',
        'has_guest_of_honor',
        'add_ons_order',
        'partial_payment_percentage',
        'partial_payment_fixed',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_per_additional' => 'decimal:2',
        'price_per_additional_30min' => 'decimal:2',
        'price_per_additional_1hr' => 'decimal:2',
        'partial_payment_fixed' => 'decimal:2',
        'duration' => 'decimal:2',
        'features' => 'array',
        'image' => 'array',
        'add_ons_order' => 'array',
        'is_active' => 'boolean',
        'has_guest_of_honor' => 'boolean',
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

    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(PackageAvailabilitySchedule::class);
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

    public function scopeByPackageType($query, $packageType)
    {
        return $query->where('package_type', $packageType);
    }

    public function scopeRegular($query)
    {
        return $query->where('package_type', 'regular');
    }

    public function scopeCustom($query)
    {
        return $query->where('package_type', '!=', 'regular');
    }

    /**
     * Get available time slots for a specific date.
     * Returns time slots from matching availability schedules.
     *
     * @param string $date Date in Y-m-d format
     * @return array Array of time slots in H:i format
     */
    public function getTimeSlotsForDate(string $date): array
    {
        // Find matching schedules for the given date
        $matchingSchedules = $this->availabilitySchedules()
            ->active()
            ->get()
            ->filter(function ($schedule) use ($date) {
                return $schedule->matchesDate($date);
            })
            ->sortByDesc('priority');

        // If we have matching schedules, use the highest priority one
        if ($matchingSchedules->isNotEmpty()) {
            $schedule = $matchingSchedules->first();
            return $schedule->getTimeSlotsForDate($date);
        }

        // No schedules found for this date
        return [];
    }
}
