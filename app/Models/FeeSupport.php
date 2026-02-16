<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeSupport extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'fee_name',
        'fee_amount',
        'fee_calculation_type',
        'fee_application_type',
        'entity_ids',
        'entity_type',
        'is_active',
    ];

    protected $casts = [
        'fee_amount' => 'decimal:2',
        'entity_ids' => 'array',
        'is_active' => 'boolean',
    ];

    // Constants
    const CALCULATION_FIXED = 'fixed';
    const CALCULATION_PERCENTAGE = 'percentage';

    const APPLICATION_ADDITIVE = 'additive';
    const APPLICATION_INCLUSIVE = 'inclusive';

    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';

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
        return $query->where('location_id', $locationId);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForPackages($query)
    {
        return $query->where('entity_type', self::ENTITY_PACKAGE);
    }

    public function scopeForAttractions($query)
    {
        return $query->where('entity_type', self::ENTITY_ATTRACTION);
    }

    /**
     * Calculate the fee amount for a given base price.
     *
     * @param float $basePrice
     * @return float The calculated fee amount in dollars
     */
    public function calculateFee(float $basePrice): float
    {
        if ($this->fee_calculation_type === self::CALCULATION_FIXED) {
            return (float) $this->fee_amount;
        }

        // Percentage calculation
        if ($this->fee_application_type === self::APPLICATION_INCLUSIVE) {
            // For inclusive: fee is extracted FROM the base price
            // base price $200, fee 10% → fee = $200 * 10 / (100 + 10) ≈ $18.18...
            // Actually the user wants: base shows $180, fee $20, total $200
            // So fee = basePrice * (percentage / 100)
            // And displayed base = basePrice - fee
            return round($basePrice * ((float) $this->fee_amount / 100), 2);
        }

        // Additive: fee is added ON TOP of base price
        // base price $200, fee 10% → fee = $20, total = $220
        return round($basePrice * ((float) $this->fee_amount / 100), 2);
    }

    /**
     * Get the display breakdown for a given base price.
     *
     * @param float $originalPrice The original/actual price
     * @return array{displayed_base_price: float, fee_amount: float, total: float, fee_name: string, fee_label: string}
     */
    public function getPriceBreakdown(float $originalPrice): array
    {
        $feeAmount = $this->calculateFee($originalPrice);
        $feeLabel = $this->fee_calculation_type === self::CALCULATION_PERCENTAGE
            ? "{$this->fee_amount}%"
            : '$' . number_format((float) $this->fee_amount, 2);

        if ($this->fee_application_type === self::APPLICATION_INCLUSIVE) {
            // Inclusive: total stays the same as original price, base is reduced
            return [
                'fee_support_id' => $this->id,
                'fee_name' => $this->fee_name,
                'fee_label' => $feeLabel,
                'fee_calculation_type' => $this->fee_calculation_type,
                'fee_application_type' => $this->fee_application_type,
                'fee_amount' => $feeAmount,
                'displayed_base_price' => round($originalPrice - $feeAmount, 2),
                'total' => $originalPrice,
            ];
        }

        // Additive: total is base + fee
        return [
            'fee_support_id' => $this->id,
            'fee_name' => $this->fee_name,
            'fee_label' => $feeLabel,
            'fee_calculation_type' => $this->fee_calculation_type,
            'fee_application_type' => $this->fee_application_type,
            'fee_amount' => $feeAmount,
            'displayed_base_price' => $originalPrice,
            'total' => round($originalPrice + $feeAmount, 2),
        ];
    }

    /**
     * Check if this fee applies to a specific entity ID.
     *
     * @param int $entityId
     * @return bool
     */
    public function appliesToEntity(int $entityId): bool
    {
        $ids = $this->entity_ids ?? [];
        return in_array($entityId, $ids);
    }

    /**
     * Get all active fee supports for a specific entity (package or attraction).
     *
     * @param string $entityType 'package' or 'attraction'
     * @param int $entityId
     * @param int|null $locationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getFeesForEntity(string $entityType, int $entityId, ?int $locationId = null)
    {
        $query = static::active()
            ->where('entity_type', $entityType);

        if ($locationId) {
            $query->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)
                    ->orWhereNull('location_id');
            });
        }

        return $query->get()->filter(function ($fee) use ($entityId) {
            return $fee->appliesToEntity($entityId);
        })->values();
    }

    /**
     * Get full price breakdown for an entity with all applicable fees.
     *
     * @param string $entityType
     * @param int $entityId
     * @param float $basePrice
     * @param int|null $locationId
     * @return array{base_price: float, displayed_base_price: float, fees: array, total: float}
     */
    public static function getFullPriceBreakdown(string $entityType, int $entityId, float $basePrice, ?int $locationId = null): array
    {
        $fees = static::getFeesForEntity($entityType, $entityId, $locationId);

        $totalAdditiveFees = 0;
        $totalInclusiveFees = 0;
        $feeBreakdowns = [];

        foreach ($fees as $fee) {
            $breakdown = $fee->getPriceBreakdown($basePrice);
            $feeBreakdowns[] = $breakdown;

            if ($fee->fee_application_type === self::APPLICATION_ADDITIVE) {
                $totalAdditiveFees += $breakdown['fee_amount'];
            } else {
                $totalInclusiveFees += $breakdown['fee_amount'];
            }
        }

        return [
            'original_base_price' => $basePrice,
            'displayed_base_price' => round($basePrice - $totalInclusiveFees, 2),
            'fees' => $feeBreakdowns,
            'total' => round($basePrice + $totalAdditiveFees, 2),
        ];
    }
}
