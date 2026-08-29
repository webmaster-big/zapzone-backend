<?php

namespace App\Models;

use App\Support\DataUriImage;
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
        'pricing_type',
        'price_per_additional',
        'min_participants',
        'max_participants',
        'max_tickets_per_slot',
        'participant_label',
        'display_label',
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
        'max_tickets_per_slot' => 'integer',
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

    protected static function booted(): void
    {
        static::saving(function (Package $package) {
            if (DataUriImage::contains($package->image)) {
                $package->image = DataUriImage::externalize($package->image, 'images/packages');
            }
        });
    }

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

    private array $resolvedScheduleForDate = [];

    public function scheduleForDate(string $date): ?PackageAvailabilitySchedule
    {
        if (!array_key_exists($date, $this->resolvedScheduleForDate)) {
            $this->resolvedScheduleForDate[$date] = $this->availabilitySchedules()
                ->active()
                ->get()
                ->filter(fn ($schedule) => $schedule->matchesDate($date))
                ->sortByDesc('priority')
                ->first();
        }

        return $this->resolvedScheduleForDate[$date];
    }

    public function forgetResolvedSchedules(): void
    {
        $this->resolvedScheduleForDate = [];
    }

    public function getTimeSlotsForDate(string $date): array
    {
        $schedule = $this->scheduleForDate($date);

        if ($schedule) {
            return $schedule->getTimeSlotsForDate($date, $this->getDurationInMinutes());
        }

        return [];
    }

    public function effectiveMinParticipants(?string $date = null): int
    {
        $override = $date !== null ? $this->scheduleForDate($date)?->min_participants : null;

        return max(1, (int) ($override ?? $this->min_participants ?? 1));
    }

    public function usesRooms(): bool
    {
        return $this->relationLoaded('rooms') ? $this->rooms->isNotEmpty() : $this->rooms()->exists();
    }

    public function isExclusiveOn(string $date): bool
    {
        if ((string) config('booking_rules.exclusive_slots', 'enforce') !== 'enforce') {
            return false;
        }

        return $this->effectiveTicketCap() !== null
            && !$this->usesRooms()
            && $this->effectiveMinParticipants($date) > 1;
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

    public function getBookedSeatsBySlot(string $date): array
    {
        $counts = $this->bookings()
            ->where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw("TIME_FORMAT(booking_time, '%H:%i') as slot_time, SUM(participants) as seats")
            ->groupBy('slot_time')
            ->pluck('seats', 'slot_time')
            ->toArray();

        return array_map('intval', $counts);
    }

    /**
     * Every booked window on a date, as minutes-from-midnight plus the seats it holds.
     * Start times alone are not enough: a schedule can start a new slot every 30 minutes
     * for an experience that runs an hour, so 12:00 and 12:30 occupy the same room and
     * must share one capacity. Loaded once and reused across a day's slots.
     *
     * @return array<int, array{start:int,end:int,seats:int,id:int}>
     */
    public function bookedWindowsForDate(string $date): array
    {
        return $this->bookings()
            ->where('booking_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->get(['id', 'booking_time', 'duration', 'duration_unit', 'participants'])
            ->map(function ($booking) {
                $time = $booking->booking_time instanceof \DateTimeInterface
                    ? $booking->booking_time->format('H:i')
                    : substr((string) $booking->booking_time, 0, 5);

                [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);
                $start = ($hours * 60) + $minutes;

                $length = (float) $booking->duration;
                $lengthMinutes = in_array($booking->duration_unit, ['hours', 'hours and minutes'], true)
                    ? (int) round($length * 60)
                    : (int) round($length);

                return [
                    'start' => $start,
                    'end' => $start + max(1, $lengthMinutes),
                    'seats' => (int) $booking->participants,
                    'id' => (int) $booking->id,
                ];
            })
            ->all();
    }

    /** Seats already held during the window this slot occupies, overlaps included. */
    public function seatsHeldDuring(array $windows, string $time, ?int $excludeBookingId = null): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', substr($time, 0, 5))), 2, 0);
        $slotStart = ($hours * 60) + $minutes;
        $slotEnd = $slotStart + max(1, $this->getDurationInMinutes());

        $held = 0;

        foreach ($windows as $window) {
            if ($excludeBookingId !== null && $window['id'] === $excludeBookingId) {
                continue;
            }

            if ($window['start'] < $slotEnd && $slotStart < $window['end']) {
                $held += $window['seats'];
            }
        }

        return $held;
    }

    /**
     * Seats sellable per slot. A per-player experience IS the room — an escape room that
     * takes 3-6 players holds 6 people in a slot, so its party size caps the slot even when
     * no explicit ticket limit was typed. Room-based packages stay uncapped here and are
     * limited by room availability instead.
     */
    public function effectiveTicketCap(): ?int
    {
        if ($this->max_tickets_per_slot !== null) {
            return (int) $this->max_tickets_per_slot;
        }

        if ($this->pricing_type === 'per_person' && $this->max_participants !== null) {
            return (int) $this->max_participants;
        }

        return null;
    }

    public function remainingTicketsForSlotGivenWindows(array $windows, string $date, string $time, ?int $excludeBookingId = null, ?bool $exclusive = null): ?int
    {
        $cap = $this->effectiveTicketCap();

        if ($cap === null) {
            return null;
        }

        $taken = $this->seatsHeldDuring($windows, $time, $excludeBookingId);

        if ($taken > 0 && ($exclusive ?? $this->isExclusiveOn($date))) {
            return 0;
        }

        return max(0, $cap - $taken);
    }

    public function remainingTicketsForSlot(string $date, string $time, ?int $excludeBookingId = null): ?int
    {
        return $this->remainingTicketsForSlotGivenWindows($this->bookedWindowsForDate($date), $date, $time, $excludeBookingId);
    }
}
