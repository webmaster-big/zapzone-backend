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
        'scope_ids',
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
        'scope_ids'     => 'array',
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
            'any'        => true,
            'package'    => $type === 'package' && $this->scopeIdMatches($id),
            'attraction' => $type === 'attraction' && $this->scopeIdMatches($id),
            'event'      => $type === 'event' && $this->scopeIdMatches($id),
            'addon'      => $type === 'addon' && $this->scopeIdMatches($id),
            'category'   => $category !== null && $this->scope_category !== null
                          && strcasecmp($category, $this->scope_category) === 0,
            default      => true,
        };
    }

    /**
     * Match a line id against the benefit's targets.
     * Supports multiple targets (scope_ids) with single-target (scope_id) fallback.
     * When no specific targets are set, the benefit applies to every item of the scope type.
     */
    protected function scopeIdMatches(?int $id): bool
    {
        $ids = is_array($this->scope_ids) ? array_map('intval', $this->scope_ids) : [];
        if (count($ids) > 0) {
            return in_array((int) $id, $ids, true);
        }
        if ($this->scope_id !== null) {
            return (int) $this->scope_id === (int) $id;
        }
        return true; // no specific targets -> all items of this scope type
    }
}
