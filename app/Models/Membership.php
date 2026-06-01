<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Membership extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id', 'membership_plan_id', 'membership_group_id',
        'home_location_id', 'sold_at_location_id',
        'status',
        'started_at', 'current_term_start', 'current_term_end',
        'next_billing_at', 'canceled_at', 'cancellation_effective_at',
        'frozen_until', 'grace_period_ends_at',
        'uses_remaining', 'visits_remaining', 'services_remaining',
        'photo_path', 'photo_taken_at', 'photo_taken_by_user_id',
        'qr_token',
        'billing_amount', 'payment_method_label', 'payment_profile_token',
        'recurring_billing_authorized', 'recurring_billing_authorized_at',
        'terms_accepted', 'terms_accepted_at',
        'is_comped', 'discount_amount',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'current_term_start' => 'datetime',
        'current_term_end' => 'datetime',
        'next_billing_at' => 'datetime',
        'canceled_at' => 'datetime',
        'cancellation_effective_at' => 'datetime',
        'frozen_until' => 'datetime',
        'grace_period_ends_at' => 'datetime',
        'photo_taken_at' => 'datetime',
        'recurring_billing_authorized_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'recurring_billing_authorized' => 'boolean',
        'terms_accepted' => 'boolean',
        'is_comped' => 'boolean',
        'billing_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->qr_token)) {
                $m->qr_token = self::generateUniqueQrToken();
            }
        });
    }

    public static function generateUniqueQrToken(): string
    {
        do {
            $token = 'mbr_' . Str::random(40);
        } while (self::where('qr_token', $token)->exists());
        return $token;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MembershipGroup::class, 'membership_group_id');
    }

    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id');
    }

    public function soldAtLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'sold_at_location_id');
    }

    public function photoTakenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photo_taken_by_user_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(MembershipVisit::class);
    }

    public function membershipPayments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MembershipNote::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(MembershipAuditLog::class);
    }

    public function benefitRedemptions(): HasMany
    {
        return $this->hasMany(MembershipBenefitRedemption::class);
    }

    public function isUsable(): bool
    {
        if ($this->status === 'active') return true;
        if ($this->status === 'past_due' && $this->grace_period_ends_at && $this->grace_period_ends_at->isFuture()) {
            return true;
        }
        return false;
    }

    public function hasPhoto(): bool
    {
        return ! empty($this->photo_path);
    }
}
