<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'location_id', 'name', 'slug', 'description', 'benefits',
        'tier', 'inherits_plan_id', 'price', 'billing_cycle', 'custom_billing_days', 'term_length_months',
        'trial_days',
        'usage_type', 'uses_per_term', 'visits_per_term', 'services_per_term',
        'punch_card_total',
        'unlimited_uses_per_term', 'unlimited_visits_per_term', 'max_visits_per_day',
        'member_only_booking', 'advance_booking_days',
        'late_cancel_counts_as_visit', 'no_show_counts_as_visit',
        'location_access_mode',
        'grace_period_days', 'failed_payment_retry_days', 'failed_payment_max_retries',
        'cancellation_mode', 'renewable',
        'discount_percent',
        'requires_photo', 'is_family_or_group', 'max_family_size',
        'is_active',
    ];

    protected $casts = [
        'benefits' => 'array',
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'unlimited_uses_per_term' => 'boolean',
        'unlimited_visits_per_term' => 'boolean',
        'member_only_booking' => 'boolean',
        'late_cancel_counts_as_visit' => 'boolean',
        'no_show_counts_as_visit' => 'boolean',
        'renewable' => 'boolean',
        'is_active' => 'boolean',
        'requires_photo' => 'boolean',
        'is_family_or_group' => 'boolean',
    ];

    protected $appends = [
        'billing_interval',
        'unlimited_uses',
        'unlimited_visits',
        'included_visits_per_term',
    ];


    public function getBillingIntervalAttribute(): string
    {
        return $this->billing_cycle ?? 'monthly';
    }

    public function getUnlimitedUsesAttribute(): bool
    {
        return (bool) $this->unlimited_uses_per_term;
    }

    public function getUnlimitedVisitsAttribute(): bool
    {
        return (bool) $this->unlimited_visits_per_term;
    }

    public function getIncludedVisitsPerTermAttribute(): ?int
    {
        return $this->visits_per_term;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function approvedLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'membership_plan_locations');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function planBenefits(): HasMany
    {
        return $this->hasMany(MembershipPlanBenefit::class);
    }

    public function inheritsPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'inherits_plan_id');
    }

    public function resolvedBenefits()
    {
        $own = $this->planBenefits()->where('is_active', true)->get();
        if ($this->inherits_plan_id && $this->relationLoaded('inheritsPlan') === false) {
            $this->load('inheritsPlan.planBenefits');
        }
        $inherited = $this->inheritsPlan?->planBenefits?->where('is_active', true) ?? collect();
        return $inherited->concat($own);
    }
}
