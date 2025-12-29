<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PackageTimeSlot extends Model
{
    use HasFactory;

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

    // Relationships
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

    // Accessor to calculate end time
    public function getTimeSlotEndAttribute()
    {
        $start = Carbon::parse($this->time_slot_start);
        
        // Convert duration to minutes for precise calculation
        $durationInMinutes = $this->getDurationInMinutes();
        
        return $start->addMinutes($durationInMinutes)->format('H:i:s');
    }

    /**
     * Get duration in minutes regardless of unit type.
     * Handles decimal values (e.g., 1.75 hours = 105 minutes)
     */
    public function getDurationInMinutes(): int
    {
        $duration = (float) $this->duration;
        
        if ($this->duration_unit === 'hours' || $this->duration_unit === 'hours and minutes') {
            return (int) round($duration * 60);
        }
        
        return (int) round($duration);
    }

    // Scopes
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
