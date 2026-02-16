<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class SpecialPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'description',
        'discount_amount',
        'discount_type',
        'recurrence_type',
        'recurrence_value',
        'specific_date',
        'start_date',
        'end_date',
        'time_start',
        'time_end',
        'entity_type',
        'entity_ids',
        'priority',
        'is_stackable',
        'is_active',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'recurrence_value' => 'integer',
        'specific_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'entity_ids' => 'array',
        'priority' => 'integer',
        'is_stackable' => 'boolean',
        'is_active' => 'boolean',
    ];

    // Constants
    const DISCOUNT_FIXED = 'fixed';
    const DISCOUNT_PERCENTAGE = 'percentage';

    const RECURRENCE_ONE_TIME = 'one_time';
    const RECURRENCE_WEEKLY = 'weekly';
    const RECURRENCE_MONTHLY = 'monthly';

    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';
    const ENTITY_ALL = 'all';

    // Day of week constants (for recurrence_value when recurrence_type is 'weekly')
    const SUNDAY = 0;
    const MONDAY = 1;
    const TUESDAY = 2;
    const WEDNESDAY = 3;
    const THURSDAY = 4;
    const FRIDAY = 5;
    const SATURDAY = 6;

    const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where(function ($q) use ($locationId) {
            $q->where('location_id', $locationId)
              ->orWhereNull('location_id'); // Include company-wide
        });
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForPackages($query)
    {
        return $query->whereIn('entity_type', [self::ENTITY_PACKAGE, self::ENTITY_ALL]);
    }

    public function scopeForAttractions($query)
    {
        return $query->whereIn('entity_type', [self::ENTITY_ATTRACTION, self::ENTITY_ALL]);
    }

    public function scopeOneTime($query)
    {
        return $query->where('recurrence_type', self::RECURRENCE_ONE_TIME);
    }

    public function scopeRecurring($query)
    {
        return $query->whereIn('recurrence_type', [self::RECURRENCE_WEEKLY, self::RECURRENCE_MONTHLY]);
    }

    public function scopeWeekly($query)
    {
        return $query->where('recurrence_type', self::RECURRENCE_WEEKLY);
    }

    public function scopeMonthly($query)
    {
        return $query->where('recurrence_type', self::RECURRENCE_MONTHLY);
    }

    /**
     * Scope to filter special pricings that are within their effective date range.
     */
    public function scopeWithinDateRange($query, ?Carbon $date = null)
    {
        $date = $date ?? Carbon::today();

        return $query->where(function ($q) use ($date) {
            $q->where(function ($q2) use ($date) {
                $q2->whereNull('start_date')
                   ->orWhere('start_date', '<=', $date);
            })->where(function ($q2) use ($date) {
                $q2->whereNull('end_date')
                   ->orWhere('end_date', '>=', $date);
            });
        });
    }

    /**
     * Check if this special pricing applies to a specific entity ID.
     */
    public function appliesToEntity(int $entityId, string $entityType): bool
    {
        // Check if entity type matches
        if ($this->entity_type !== self::ENTITY_ALL && $this->entity_type !== $entityType) {
            return false;
        }

        // If no specific IDs, applies to all of that entity type
        if (empty($this->entity_ids)) {
            return true;
        }

        return in_array($entityId, $this->entity_ids);
    }

    /**
     * Check if this special pricing is active on a given date.
     */
    public function isActiveOnDate(Carbon $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Check date range
        if ($this->start_date && $date->lt($this->start_date)) {
            return false;
        }
        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }

        // Check recurrence
        switch ($this->recurrence_type) {
            case self::RECURRENCE_ONE_TIME:
                return $this->specific_date && $date->isSameDay($this->specific_date);

            case self::RECURRENCE_WEEKLY:
                return $date->dayOfWeek === $this->recurrence_value;

            case self::RECURRENCE_MONTHLY:
                return $date->day === $this->recurrence_value;

            default:
                return false;
        }
    }

    /**
     * Check if this special pricing is active at a given time.
     */
    public function isActiveAtTime(?string $time = null): bool
    {
        // If no time restrictions, always active
        if (is_null($this->time_start) && is_null($this->time_end)) {
            return true;
        }

        $time = $time ?? Carbon::now()->format('H:i:s');
        $checkTime = Carbon::createFromFormat('H:i:s', $time);

        if ($this->time_start) {
            $startTime = Carbon::createFromFormat('H:i:s', $this->time_start);
            if ($checkTime->lt($startTime)) {
                return false;
            }
        }

        if ($this->time_end) {
            $endTime = Carbon::createFromFormat('H:i:s', $this->time_end);
            if ($checkTime->gt($endTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate the discount amount for a given base price.
     */
    public function calculateDiscount(float $basePrice): float
    {
        if ($this->discount_type === self::DISCOUNT_FIXED) {
            // Fixed discount, cap at base price
            return min((float) $this->discount_amount, $basePrice);
        }

        // Percentage discount
        return round($basePrice * ((float) $this->discount_amount / 100), 2);
    }

    /**
     * Get the discount breakdown for a given base price.
     */
    public function getDiscountBreakdown(float $basePrice): array
    {
        $discountAmount = $this->calculateDiscount($basePrice);
        $discountLabel = $this->discount_type === self::DISCOUNT_PERCENTAGE
            ? "{$this->discount_amount}%"
            : '$' . number_format((float) $this->discount_amount, 2);

        return [
            'special_pricing_id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discount_label' => $discountLabel,
            'discount_type' => $this->discount_type,
            'discount_amount' => $discountAmount,
            'original_price' => $basePrice,
            'discounted_price' => round($basePrice - $discountAmount, 2),
            'recurrence_type' => $this->recurrence_type,
            'recurrence_display' => $this->getRecurrenceDisplay(),
        ];
    }

    /**
     * Get human-readable recurrence display text.
     */
    public function getRecurrenceDisplay(): string
    {
        switch ($this->recurrence_type) {
            case self::RECURRENCE_ONE_TIME:
                return $this->specific_date
                    ? 'One-time: ' . $this->specific_date->format('M j, Y')
                    : 'One-time';

            case self::RECURRENCE_WEEKLY:
                $dayName = self::DAY_NAMES[$this->recurrence_value] ?? 'Unknown';
                return "Every {$dayName}";

            case self::RECURRENCE_MONTHLY:
                $suffix = $this->getOrdinalSuffix($this->recurrence_value);
                return "Monthly on the {$this->recurrence_value}{$suffix}";

            default:
                return 'Unknown';
        }
    }

    /**
     * Get ordinal suffix for a number (1st, 2nd, 3rd, etc.)
     */
    private function getOrdinalSuffix(int $number): string
    {
        if (in_array(($number % 100), [11, 12, 13])) {
            return 'th';
        }
        switch ($number % 10) {
            case 1: return 'st';
            case 2: return 'nd';
            case 3: return 'rd';
            default: return 'th';
        }
    }

    /**
     * Get all active special pricings for a specific entity on a given date.
     */
    public static function getActiveForEntity(
        string $entityType,
        int $entityId,
        ?Carbon $date = null,
        ?int $locationId = null,
        ?string $time = null
    ) {
        $date = $date ?? Carbon::today();

        $query = static::active()
            ->withinDateRange($date)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc');

        // Filter by entity type
        if ($entityType === self::ENTITY_PACKAGE) {
            $query->forPackages();
        } else {
            $query->forAttractions();
        }

        if ($locationId) {
            $query->byLocation($locationId);
        }

        return $query->get()->filter(function ($pricing) use ($entityId, $entityType, $date, $time) {
            // Check if applies to entity
            if (!$pricing->appliesToEntity($entityId, $entityType)) {
                return false;
            }

            // Check if active on date
            if (!$pricing->isActiveOnDate($date)) {
                return false;
            }

            // Check time restriction
            if ($time && !$pricing->isActiveAtTime($time)) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Get full price breakdown with all applicable special pricings.
     * Applies discounts based on priority and stacking rules.
     */
    public static function getFullPriceBreakdown(
        string $entityType,
        int $entityId,
        float $basePrice,
        ?Carbon $date = null,
        ?int $locationId = null,
        ?string $time = null
    ): array {
        $specialPricings = static::getActiveForEntity($entityType, $entityId, $date, $locationId, $time);

        $currentPrice = $basePrice;
        $appliedDiscounts = [];
        $totalDiscount = 0;
        $appliedNonStackable = false;

        foreach ($specialPricings as $pricing) {
            // If we've already applied a non-stackable discount, skip unless this one is stackable
            if ($appliedNonStackable && !$pricing->is_stackable) {
                continue;
            }

            $discountAmount = $pricing->calculateDiscount($currentPrice);

            $appliedDiscounts[] = [
                'special_pricing_id' => $pricing->id,
                'name' => $pricing->name,
                'description' => $pricing->description,
                'discount_label' => $pricing->discount_type === self::DISCOUNT_PERCENTAGE
                    ? "{$pricing->discount_amount}%"
                    : '$' . number_format((float) $pricing->discount_amount, 2),
                'discount_type' => $pricing->discount_type,
                'discount_amount' => $discountAmount,
                'is_stackable' => $pricing->is_stackable,
                'recurrence_display' => $pricing->getRecurrenceDisplay(),
            ];

            $currentPrice -= $discountAmount;
            $totalDiscount += $discountAmount;

            // If this is non-stackable and we haven't applied a non-stackable yet
            if (!$pricing->is_stackable) {
                $appliedNonStackable = true;
            }

            // Don't go below zero
            if ($currentPrice <= 0) {
                $currentPrice = 0;
                break;
            }
        }

        return [
            'original_price' => $basePrice,
            'discounted_price' => round($currentPrice, 2),
            'total_discount' => round($totalDiscount, 2),
            'discounts_applied' => $appliedDiscounts,
            'has_special_pricing' => count($appliedDiscounts) > 0,
        ];
    }

    /**
     * Get upcoming dates when this special pricing will be active.
     */
    public function getUpcomingDates(int $count = 5): array
    {
        $dates = [];
        $currentDate = Carbon::today();

        // Respect start_date
        if ($this->start_date && $this->start_date->gt($currentDate)) {
            $currentDate = $this->start_date->copy();
        }

        $maxDate = $this->end_date ?? Carbon::today()->addYear();

        switch ($this->recurrence_type) {
            case self::RECURRENCE_ONE_TIME:
                if ($this->specific_date && $this->specific_date->gte(Carbon::today())) {
                    $dates[] = $this->specific_date->format('Y-m-d');
                }
                break;

            case self::RECURRENCE_WEEKLY:
                while (count($dates) < $count && $currentDate->lte($maxDate)) {
                    if ($currentDate->dayOfWeek === $this->recurrence_value) {
                        $dates[] = $currentDate->format('Y-m-d');
                    }
                    $currentDate->addDay();
                }
                break;

            case self::RECURRENCE_MONTHLY:
                while (count($dates) < $count && $currentDate->lte($maxDate)) {
                    if ($currentDate->day === $this->recurrence_value) {
                        $dates[] = $currentDate->format('Y-m-d');
                    }
                    $currentDate->addDay();
                }
                break;
        }

        return $dates;
    }
}
