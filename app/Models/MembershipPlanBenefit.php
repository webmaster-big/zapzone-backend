<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPlanBenefit extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_plan_id',
        'benefit_type',
        'label',
        'scope_type',
        'scope_id',
        'scope_category',
        'value_mode',
        'value',
        'period',
        'max_redemptions',
        'priority',
        'is_stackable',
        'conditions',
        'is_active',
    ];

    protected $casts = [
        'value'         => 'decimal:2',
        'scope_id'      => 'integer',
        'max_redemptions' => 'integer',
        'priority'      => 'integer',
        'is_stackable'  => 'boolean',
        'is_active'     => 'boolean',
        'conditions'    => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(MembershipBenefitRedemption::class);
    }

    public function isDiscount(): bool
    {
        return in_array($this->benefit_type, [
            'package_discount', 'attraction_discount', 'event_discount', 'addon_discount',
        ], true);
    }

    public function isPass(): bool
    {
        return in_array($this->benefit_type, ['free_entry_pass', 'guest_pass'], true);
    }

    public function appliesToLine(string $type, ?int $id = null, ?string $category = null): bool
    {
        $typeMap = [
            'package_discount'    => 'package',
            'attraction_discount' => 'attraction',
            'event_discount'      => 'event',
            'addon_discount'      => 'addon',
            'free_entry_pass'     => 'attraction',
        ];
        if (isset($typeMap[$this->benefit_type]) && $typeMap[$this->benefit_type] !== $type) {
            return false;
        }

        return match ($this->scope_type) {
            'any'      => true,
            'package'  => $type === 'package' && (int) $this->scope_id === (int) $id,
            'attraction' => $type === 'attraction' && (int) $this->scope_id === (int) $id,
            'event'    => $type === 'event' && (int) $this->scope_id === (int) $id,
            'category' => $category !== null && $this->scope_category !== null
                          && strcasecmp($category, $this->scope_category) === 0,
            default    => true,
        };
    }
}
