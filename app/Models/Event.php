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
        'max_tickets_per_slot',
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
        'max_tickets_per_slot' => 'integer',
        'features' => 'array',
        'add_ons_order' => 'array',
    ];

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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function getAvailableDates(): array
    {
        if ($this->date_type === 'one_time') {
            $date = $this->start_date->format('Y-m-d');
            return DayOff::isDateBlockedForEvent($this->location_id, $this->id, $date) ? [] : [$date];
        }

        $dates = [];
        $current = $this->start_date->copy();
        $end = $this->end_date;

        while ($current->lte($end)) {
            $date = $current->format('Y-m-d');
            if (!DayOff::isDateBlockedForEvent($this->location_id, $this->id, $date)) {
                $dates[] = $date;
            }
            $current->addDay();
        }

        return $dates;
    }

    public function getTimeSlots(): array
    {
        if (!$this->time_start || !$this->time_end) {
            return [];
        }

        $slots = [];
        $start = Carbon::parse($this->time_start);
        $end = Carbon::parse($this->time_end);

        // A late event runs past midnight — 8:00 PM to 1:00 AM ends on the next
        // calendar day, so the end has to move forward a day or the loop never runs
        // and the event offers no times at all.
        if ($end->lte($start)) {
            $end->addDay();
        }

        $interval = (int) ($this->interval_minutes ?: 60);

        while ($start->lt($end)) {
            $slotEnd = $start->copy()->addMinutes($interval);
            if ($slotEnd->gt($end)) {
                break;
            }
            $slots[] = $start->format('H:i');
            $start->addMinutes($interval);
        }

        return $slots;
    }

    public function getAvailableTimeSlotsForDate(string $date): array
    {
        $interval = (int) ($this->interval_minutes ?: 60);

        $allSlots = array_values(array_filter($this->getTimeSlots(), function ($slot) use ($date, $interval) {
            $slotEnd = Carbon::parse($slot)->addMinutes($interval)->format('H:i');
            return !DayOff::isTimeSlotBlockedForEvent($this->location_id, $this->id, $date, $slot, $slotEnd);
        }));

        if ($this->max_tickets_per_slot !== null) {
            $seatCounts = $this->getBookedTicketsBySlot($date);
            $allSlots = array_values(array_filter($allSlots, function ($slot) use ($seatCounts) {
                return ($seatCounts[$slot] ?? 0) < $this->max_tickets_per_slot;
            }));
        }

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

    public function isDateValid(string $date): bool
    {
        $date = Carbon::parse($date);

        if ($this->date_type === 'one_time') {
            return $date->isSameDay($this->start_date);
        }

        return $date->between($this->start_date, $this->end_date);
    }

    public function getBookedTicketsBySlot(string $date): array
    {
        $counts = $this->eventPurchases()
            ->where('purchase_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw("TIME_FORMAT(purchase_time, '%H:%i') as slot_time, SUM(quantity) as seats")
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

        $taken = $this->getBookedTicketsBySlot($date)[substr($time, 0, 5)] ?? 0;

        return max(0, $this->max_tickets_per_slot - $taken);
    }
}
