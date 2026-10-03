<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorIdentity extends Model
{
    protected $fillable = [
        'visitor_id',
        'name',
        'phone',
        'email',
        'customer_id',
        'location_id',
        'sms_consent',
        'sms_consent_at',
        'last_seen_at',
    ];

    protected $casts = [
        'sms_consent' => 'boolean',
        'sms_consent_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
