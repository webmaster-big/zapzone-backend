<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAttraction extends Model
{
    protected $fillable = [
        'booking_id',
        'attraction_id',
        'quantity',
        'price',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function attraction()
    {
        return $this->belongsTo(Attraction::class);
    }
}
