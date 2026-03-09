<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'location_id',
        'name',
        'description',
        'image',
        'date_type',
        'start_date',
        'end_date',
        'time_start',
        'time_end',
        'interval_minutes',
        'max_bookings_per_slot',
        'price',
        'features',
        'add_ons_order',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'interval_minutes' => 'integer',
        'max_bookings_per_slot' => 'integer',
        'features' => 'array',
        'add_ons_order' => 'array',
    ];

    // Relationships
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function eventPurchases(): HasMany
    {
        return $this->hasMany(EventPurchase::class);
    }

    public function addOns(): BelongsToMany
    {
        return $this->belongsToMany(AddOn::class, 'event_add_ons')
            ->withTimestamps();
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

    /**
     * Get available dates for this event.
     */
    public function getAvailableDates(): array
    {
        if ($this->date_type === 'one_time') {
            return [$this->start_date->format('Y-m-d')];
        }

        $dates = [];
        $current = $this->start_date->copy();
        $end = $this->end_date;

        while ($current->lte($end)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $dates;
    }

    /**
     * Generate time slots based on time_start, time_end, and interval_minutes.
     */
    public function getTimeSlots(): array
    {
        $slots = [];
        $start = Carbon::parse($this->time_start);
        $end = Carbon::parse($this->time_end);

        while ($start->lt($end)) {
            $slotEnd = $start->copy()->addMinutes($this->interval_minutes);
            if ($slotEnd->gt($end)) {
                break;
            }
            $slots[] = $start->format('H:i');
            $start->addMinutes($this->interval_minutes);
        }

        return $slots;
    }

    /**
     * Get available time slots for a specific date, accounting for existing bookings.
     */
    public function getAvailableTimeSlotsForDate(string $date): array
    {
        $allSlots = $this->getTimeSlots();

        if ($this->max_bookings_per_slot === null) {
            return $allSlots;
        }

        $purchaseCounts = $this->eventPurchases()
            ->where('purchase_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('purchase_time, COUNT(*) as count')
            ->groupBy('purchase_time')
            ->pluck('count', 'purchase_time')
            ->toArray();

        return array_values(array_filter($allSlots, function ($slot) use ($purchaseCounts) {
            $count = $purchaseCounts[$slot . ':00'] ?? $purchaseCounts[$slot] ?? 0;
            return $count < $this->max_bookings_per_slot;
        }));
    }

    /**
     * Check if a specific date is valid for this event.
     */
    public function isDateValid(string $date): bool
    {
        $date = Carbon::parse($date);

        if ($this->date_type === 'one_time') {
            return $date->isSameDay($this->start_date);
        }

        return $date->between($this->start_date, $this->end_date);
    }
}
