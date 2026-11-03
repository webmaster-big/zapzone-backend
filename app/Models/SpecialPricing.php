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

    const DISCOUNT_FIXED = 'fixed';
    const DISCOUNT_PERCENTAGE = 'percentage';

    const RECURRENCE_ONE_TIME = 'one_time';
    const RECURRENCE_WEEKLY = 'weekly';
    const RECURRENCE_MONTHLY = 'monthly';

    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';
    const ENTITY_EVENT = 'event';
    const ENTITY_ALL = 'all';

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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

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

    public function scopeForEvents($query)
    {
        return $query->whereIn('entity_type', [self::ENTITY_EVENT, self::ENTITY_ALL]);
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

    public function scopeNotEnded($query, ?Carbon $date = null)
    {
        $date = $date ?? self::businessToday();

        return $query->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString()));
    }

    public function scopeWithinDateRange($query, ?Carbon $date = null)
    {
        $date = $date ?? self::businessToday();

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

    public function appliesToEntity(int $entityId, string $entityType): bool
    {
        if ($this->entity_type !== self::ENTITY_ALL && $this->entity_type !== $entityType) {
            return false;
        }

        if (empty($this->entity_ids)) {
            return true;
        }

        return in_array($entityId, $this->entity_ids);
    }

    public function isActiveOnDate(Carbon $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->start_date && $date->lt($this->start_date)) {
            return false;
        }
        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }

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

    public function isActiveAtTime(?string $time = null): bool
    {
        if (is_null($this->time_start) && is_null($this->time_end)) {
            return true;
        }

        $checkTime = self::normalizeTime($time) ?? Carbon::now(config('app.business_timezone', 'America/Detroit'))->format('H:i:s');
        $startTime = self::normalizeTime($this->time_start);
        $endTime = self::normalizeTime($this->time_end);

        if ($startTime && $checkTime < $startTime) {
            return false;
        }

        if ($endTime && $checkTime > $endTime) {
            return false;
        }

        return true;
    }

    public static function businessToday(): Carbon
    {
        return Carbon::parse(Carbon::now(config('app.business_timezone', 'America/Detroit'))->toDateString());
    }

    public static function normalizeTime(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i:s');
        }

        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', (string) $time, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        return null;
    }

    public static function isSpecialPricingEntry(mixed $entry): bool
    {
        return is_array($entry)
            && (($entry['source'] ?? null) === 'special_pricing' || ! empty($entry['special_pricing_id']));
    }

    public static function toAppliedDiscounts(array $breakdown): array
    {
        return array_map(fn (array $d) => [
            'discount_name' => $d['name'],
            'discount_amount' => (float) $d['discount_amount'],
            'discount_type' => $d['discount_type'],
            'original_price' => (float) $breakdown['original_price'],
            'special_pricing_id' => $d['special_pricing_id'],
            'source' => 'special_pricing',
            'discount_label' => $d['discount_label'],
            'unit_discount_amount' => (float) $d['unit_discount_amount'],
            'quantity' => (int) $d['quantity'],
        ], $breakdown['discounts_applied'] ?? []);
    }

    public function discountLabel(): string
    {
        return $this->discount_type === self::DISCOUNT_PERCENTAGE
            ? rtrim(rtrim(number_format((float) $this->discount_amount, 2, '.', ''), '0'), '.') . '%'
            : '$' . number_format((float) $this->discount_amount, 2);
    }

    public function toDeal(array $nextDates): array
    {
        return [
            'special_pricing_id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_amount' => (float) $this->discount_amount,
            'discount_label' => $this->discountLabel(),
            'recurrence_type' => $this->recurrence_type,
            'recurrence_value' => $this->recurrence_value,
            'recurrence_display' => $this->getRecurrenceDisplay(),
            'specific_date' => $this->specific_date?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'time_start' => self::normalizeTime($this->time_start) ? substr(self::normalizeTime($this->time_start), 0, 5) : null,
            'time_end' => self::normalizeTime($this->time_end) ? substr(self::normalizeTime($this->time_end), 0, 5) : null,
            'next_dates' => $nextDates,
        ];
    }

    public static function dealRules(): \Illuminate\Support\Collection
    {
        return static::active()
            ->notEnded()
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public static function applicableDealRules(\Illuminate\Support\Collection $rules, string $entityType, int $entityId, ?int $locationId): \Illuminate\Support\Collection
    {
        return $rules
            ->filter(fn (self $rule) => ($rule->location_id === null || $locationId === null || (int) $rule->location_id === (int) $locationId)
                && $rule->appliesToEntity($entityId, $entityType))
            ->values();
    }

    public static function dealsFor(\Illuminate\Support\Collection $rules, string $entityType, int $entityId, ?int $locationId, int $dateCount = 3, ?array $window = null): array
    {
        $applicable = static::applicableDealRules($rules, $entityType, $entityId, $locationId);

        return $applicable
            ->map(function (self $rule) use ($applicable, $dateCount, $window) {
                $dates = $rule->pricedDates($applicable, $dateCount, $window);

                return $dates === [] ? null : $rule->toDeal($dates);
            })
            ->filter()
            ->values()
            ->all();
    }

    public static function pricedRulesOn(\Illuminate\Support\Collection $rules, Carbon $date): \Illuminate\Support\Collection
    {
        $appliedNonStackable = false;

        return $rules->filter(function (self $rule) use ($date, &$appliedNonStackable) {
            if (! $rule->isActiveOnDate($date)) {
                return false;
            }

            if (! $rule->is_stackable && $appliedNonStackable) {
                return false;
            }

            if (! $rule->is_stackable && $rule->time_start === null && $rule->time_end === null) {
                $appliedNonStackable = true;
            }

            return true;
        })->values();
    }

    public function pricedDates(\Illuminate\Support\Collection $applicable, int $count, ?array $window = null): array
    {
        $from = self::businessToday();
        $to = self::businessToday()->addYear();

        foreach ([$this->start_date, isset($window['from']) ? Carbon::parse($window['from']) : null] as $bound) {
            if ($bound && $bound->gt($from)) {
                $from = Carbon::parse($bound->toDateString());
            }
        }

        foreach ([$this->end_date, isset($window['to']) ? Carbon::parse($window['to']) : null] as $bound) {
            if ($bound && $bound->lt($to)) {
                $to = Carbon::parse($bound->toDateString());
            }
        }

        if ($this->recurrence_type === self::RECURRENCE_ONE_TIME) {
            if (! $this->specific_date) {
                return [];
            }

            $specific = Carbon::parse($this->specific_date->toDateString());
            $from = $specific->gt($from) ? $specific : $from;
            $to = $specific->lt($to) ? $specific : $to;
        }

        $dates = [];
        for ($day = $from->copy(); $day->lte($to) && count($dates) < $count; $day->addDay()) {
            if ($this->isActiveOnDate($day) && static::pricedRulesOn($applicable, $day)->contains(fn (self $rule) => $rule->is($this))) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    public static function eventWindow(?Event $event): ?array
    {
        if (! $event || ! $event->start_date) {
            return null;
        }

        $end = $event->date_type === 'one_time' ? $event->start_date : ($event->end_date ?? $event->start_date);

        return [
            'from' => $event->start_date->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    public static function combineDeals(array $locations): array
    {
        $combined = [];
        $locationCount = count(array_unique(array_map(
            fn (array $location) => $location['location_id'] ?? $location['location_name'] ?? null,
            $locations
        )));

        foreach ($locations as $location) {
            foreach ($location['special_deals'] ?? [] as $deal) {
                $id = $deal['special_pricing_id'];
                if (! isset($combined[$id])) {
                    $combined[$id] = $deal + ['location_names' => []];
                }
                if (! empty($location['location_name'])) {
                    $combined[$id]['location_names'][] = $location['location_name'];
                }
            }
        }

        return array_values(array_map(function (array $deal) use ($locationCount) {
            $names = array_values(array_unique($deal['location_names']));
            $deal['location_names'] = $names;
            $deal['applies_at_every_location'] = count($names) >= $locationCount;

            return $deal;
        }, $combined));
    }

    public function calculateDiscount(float $basePrice): float
    {
        if ($this->discount_type === self::DISCOUNT_FIXED) {
            return min((float) $this->discount_amount, $basePrice);
        }

        return round($basePrice * ((float) $this->discount_amount / 100), 2);
    }

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

    public static function getActiveForEntity(
        string $entityType,
        int $entityId,
        ?Carbon $date = null,
        ?int $locationId = null,
        ?string $time = null,
        ?Carbon $createdBefore = null
    ) {
        $date = Carbon::parse(($date ?? self::businessToday())->toDateString());

        $query = static::active()
            ->withinDateRange($date)
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc');

        if ($createdBefore) {
            $query->where('created_at', '<=', $createdBefore);
        }

        if ($entityType === self::ENTITY_PACKAGE) {
            $query->forPackages();
        } elseif ($entityType === self::ENTITY_EVENT) {
            $query->forEvents();
        } else {
            $query->forAttractions();
        }

        if ($locationId) {
            $query->byLocation($locationId);
        }

        return $query->get()->filter(function ($pricing) use ($entityId, $entityType, $date, $time) {
            if (!$pricing->appliesToEntity($entityId, $entityType)) {
                return false;
            }

            if (!$pricing->isActiveOnDate($date)) {
                return false;
            }

            if ($time && !$pricing->isActiveAtTime($time)) {
                return false;
            }

            return true;
        })->values();
    }

    public static function getFullPriceBreakdown(
        string $entityType,
        int $entityId,
        float $basePrice,
        ?Carbon $date = null,
        ?int $locationId = null,
        ?string $time = null,
        int $quantity = 1,
        ?Carbon $createdBefore = null
    ): array {
        $quantity = max(1, $quantity);
        $specialPricings = static::getActiveForEntity($entityType, $entityId, $date, $locationId, $time, $createdBefore);

        $currentPrice = $basePrice;
        $appliedDiscounts = [];
        $unitDiscount = 0.0;
        $totalDiscount = 0.0;
        $appliedNonStackable = false;

        foreach ($specialPricings as $pricing) {
            if ($appliedNonStackable && !$pricing->is_stackable) {
                continue;
            }

            $unitAmount = $pricing->calculateDiscount($currentPrice);
            $lineAmount = round($unitAmount * $quantity, 2);

            $appliedDiscounts[] = [
                'special_pricing_id' => $pricing->id,
                'source' => 'special_pricing',
                'name' => $pricing->name,
                'description' => $pricing->description,
                'discount_label' => $pricing->discountLabel(),
                'discount_type' => $pricing->discount_type,
                'discount_amount' => $lineAmount,
                'unit_discount_amount' => $unitAmount,
                'quantity' => $quantity,
                'is_stackable' => $pricing->is_stackable,
                'recurrence_display' => $pricing->getRecurrenceDisplay(),
            ];

            $currentPrice -= $unitAmount;
            $unitDiscount += $unitAmount;
            $totalDiscount += $lineAmount;

            if (!$pricing->is_stackable) {
                $appliedNonStackable = true;
            }

            if ($currentPrice <= 0) {
                $currentPrice = 0;
                break;
            }
        }

        $originalPrice = round($basePrice * $quantity, 2);
        $totalDiscount = round($totalDiscount, 2);

        return [
            'original_price' => $originalPrice,
            'discounted_price' => round(max(0, $originalPrice - $totalDiscount), 2),
            'total_discount' => $totalDiscount,
            'unit_price' => round($basePrice, 2),
            'unit_discount' => round($unitDiscount, 2),
            'unit_discounted_price' => round(max(0, $currentPrice), 2),
            'quantity' => $quantity,
            'discounts_applied' => $appliedDiscounts,
            'has_special_pricing' => count($appliedDiscounts) > 0,
        ];
    }

    public function getUpcomingDates(int $count = 5): array
    {
        $dates = [];
        $currentDate = self::businessToday();

        if ($this->start_date && $this->start_date->gt($currentDate)) {
            $currentDate = $this->start_date->copy();
        }

        $maxDate = $this->end_date ?? self::businessToday()->addYear();

        switch ($this->recurrence_type) {
            case self::RECURRENCE_ONE_TIME:
                if ($this->specific_date && $this->specific_date->gte(self::businessToday())
                    && (! $this->start_date || $this->specific_date->gte($this->start_date))
                    && (! $this->end_date || $this->specific_date->lte($this->end_date))) {
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
