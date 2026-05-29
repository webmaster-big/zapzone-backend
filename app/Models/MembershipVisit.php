<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id', 'customer_id', 'location_id', 'staff_user_id',
        'visited_at', 'result', 'denial_reason',
        'counted_against_usage', 'visits_remaining_after', 'notes',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'counted_against_usage' => 'boolean',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
