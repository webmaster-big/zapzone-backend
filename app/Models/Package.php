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
        'display_order',
        'has_guest_of_honor',
        'add_ons_order',
        'customer_notes',
        'invitation_download_link',
        'invitation_file',
        'booking_window_days',
        'min_booking_notice_hours',
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
        'display_order' => 'integer',
        'has_guest_of_honor' => 'boolean',
        'booking_window_days' => 'integer',
        'min_booking_notice_hours' => 'integer',
    ];

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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(PackageAvailabilitySchedule::class);
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

    public function getTimeSlotsForDate(string $date): array
    {
        $matchingSchedules = $this->availabilitySchedules()
            ->active()
            ->get()
            ->filter(function ($schedule) use ($date) {
                return $schedule->matchesDate($date);
            })
            ->sortByDesc('priority');

        if ($matchingSchedules->isNotEmpty()) {
            $schedule = $matchingSchedules->first();

            $durationMinutes = $this->getDurationInMinutes();

            return $schedule->getTimeSlotsForDate($date, $durationMinutes);
        }

        return [];
    }

    public function getDurationInMinutes(): int
    {
        $duration = (float) $this->duration;
        $unit = $this->duration_unit;

        if ($unit === 'hours' || $unit === 'hours and minutes') {
            return (int) round($duration * 60);
        }

        return (int) round($duration);
    }
}
