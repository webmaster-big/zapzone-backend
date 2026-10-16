<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PackageTimeSlot extends Model
{
    use HasFactory;

    private bool $overlapAllowed = false;

    protected static function booted(): void
    {
        static::creating(function (PackageTimeSlot $slot) {
            $slot->overlap_seq = $slot->freeOverlapSeq();
        });

        static::updating(function (PackageTimeSlot $slot) {
            if ($slot->movesToAnotherStart()) {
                $slot->overlap_seq = $slot->freeOverlapSeq();
            }
        });
    }

    private function movesToAnotherStart(): bool
    {
        if (! $this->isDirty(['room_id', 'booked_date', 'time_slot_start'])) {
            return false;
        }

        return (int) $this->getOriginal('room_id') !== (int) $this->room_id
            || Carbon::parse($this->getOriginal('booked_date'))->toDateString() !== Carbon::parse($this->booked_date)->toDateString()
            || Carbon::parse($this->getOriginal('time_slot_start'))->format('H:i:s') !== Carbon::parse($this->time_slot_start)->format('H:i:s');
    }

    public function allowOverlap(bool $allowed = true): static
    {
        $this->overlapAllowed = $allowed;

        return $this;
    }

    private function freeOverlapSeq(): int
    {
        $sharing = static::query()
            ->where('room_id', $this->room_id)
            ->whereDate('booked_date', Carbon::parse($this->booked_date)->toDateString())
            ->where('time_slot_start', Carbon::parse($this->time_slot_start)->format('H:i:s'))
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->get(['overlap_seq', 'status']);

        if (! $this->overlapAllowed && ($this->status ?? 'booked') === 'booked' && $sharing->contains('status', 'booked')) {
            throw new \App\Exceptions\SlotTakenException();
        }

        $taken = $sharing->pluck('overlap_seq')->map(fn ($seq) => (int) $seq)->all();
        $seq = 0;

        while (in_array($seq, $taken, true)) {
            $seq++;
        }

        return $seq;
    }

    protected $fillable = [
        'package_id',
        'room_id',
        'booking_id',
        'customer_id',
        'user_id',
        'booked_date',
        'time_slot_start',
        'duration',
        'duration_unit',
        'status',
        'notes',
    ];

    protected $casts = [
        'booked_date' => 'date',
        'duration' => 'decimal:2',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getTimeSlotEndAttribute()
    {
        $start = Carbon::parse($this->time_slot_start);
        
        $durationInMinutes = $this->getDurationInMinutes();
        
        return $start->addMinutes($durationInMinutes)->format('H:i:s');
    }

    public function getDurationInMinutes(): int
    {
        $duration = (float) $this->duration;
        
        if ($this->duration_unit === 'hours' || $this->duration_unit === 'hours and minutes') {
            return (int) round($duration * 60);
        }
        
        return (int) round($duration);
    }

    public function scopeByPackage($query, $packageId)
    {
        return $query->where('package_id', $packageId);
    }

    public function scopeByRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    public function scopeByDate($query, $date)
    {
        return $query->whereDate('booked_date', $date);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeBooked($query)
    {
        return $query->where('status', 'booked');
    }

    public function scopeAvailableSlots($query, $roomId, $date)
    {
        return $query->where('room_id', $roomId)
                    ->whereDate('booked_date', $date)
                    ->where('status', 'booked');
    }
}
