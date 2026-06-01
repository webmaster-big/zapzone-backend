<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MembershipBenefitRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        'customer_id',
        'membership_plan_benefit_id',
        'location_id',
        'benefit_type',
        'value_mode',
        'value_applied',
        'redeemable_type',
        'redeemable_id',
        'staff_user_id',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'value_applied' => 'decimal:2',
        'reversed_at'   => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(MembershipPlanBenefit::class, 'membership_plan_benefit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function redeemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive($query)
    {
        return $query->whereNull('reversed_at');
    }
}
