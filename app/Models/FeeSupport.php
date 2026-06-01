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

    const CALCULATION_FIXED = 'fixed';
    const CALCULATION_PERCENTAGE = 'percentage';

    const APPLICATION_ADDITIVE = 'additive';
    const APPLICATION_INCLUSIVE = 'inclusive';

    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';
    const ENTITY_EVENT = 'event';

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

    public function scopeForEvents($query)
    {
        return $query->where('entity_type', self::ENTITY_EVENT);
    }

    public function calculateFee(float $basePrice): float
    {
        if ($this->fee_calculation_type === self::CALCULATION_FIXED) {
            return (float) $this->fee_amount;
        }

        if ($this->fee_application_type === self::APPLICATION_INCLUSIVE) {
            return round($basePrice * ((float) $this->fee_amount / 100), 2);
        }

        return round($basePrice * ((float) $this->fee_amount / 100), 2);
    }

    public function getPriceBreakdown(float $originalPrice): array
    {
        $feeAmount = $this->calculateFee($originalPrice);
        $feeLabel = $this->fee_calculation_type === self::CALCULATION_PERCENTAGE
            ? "{$this->fee_amount}%"
            : '$' . number_format((float) $this->fee_amount, 2);

        if ($this->fee_application_type === self::APPLICATION_INCLUSIVE) {
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

    public function appliesToEntity(int $entityId): bool
    {
        $ids = $this->entity_ids ?? [];
        return in_array($entityId, $ids);
    }

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
