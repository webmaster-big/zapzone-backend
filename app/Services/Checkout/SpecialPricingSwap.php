<?php

namespace App\Services\Checkout;

use App\Models\SpecialPricing;

class SpecialPricingSwap
{
    private const CENT = 0.005;

    public static function choose(array $given, array $baseline, array $proposed, int $fromQuantity, int $toQuantity, bool $sameItem = true, ?float $cap = null): array
    {
        if (! $sameItem
            || abs(self::total($proposed) - self::total($baseline)) > self::CENT
            || self::ids($proposed) !== self::ids($baseline)) {
            return $proposed;
        }

        if ($given === [] || $fromQuantity === $toQuantity) {
            return self::capped($given, $cap);
        }

        return self::capped(array_map(function (array $entry) use ($fromQuantity, $toQuantity) {
            $unit = array_key_exists('unit_discount_amount', $entry)
                ? (float) $entry['unit_discount_amount']
                : (float) ($entry['discount_amount'] ?? 0) / max(1, (int) ($entry['quantity'] ?? $fromQuantity));

            return array_merge($entry, [
                'source' => 'special_pricing',
                'unit_discount_amount' => round($unit, 2),
                'quantity' => $toQuantity,
                'discount_amount' => round($unit * $toQuantity, 2),
            ]);
        }, $given), $cap);
    }

    private static function capped(array $entries, ?float $cap): array
    {
        $total = self::total($entries);
        if ($cap === null || $total <= $cap + self::CENT || $total <= 0) {
            return $entries;
        }

        $factor = max(0, $cap) / $total;

        return array_map(fn (array $entry) => array_merge($entry, [
            'discount_amount' => round((float) ($entry['discount_amount'] ?? 0) * $factor, 2),
        ]), $entries);
    }

    public static function total(array $entries): float
    {
        return round(array_sum(array_map(fn (array $entry) => (float) ($entry['discount_amount'] ?? 0), $entries)), 2);
    }

    public static function ids(array $entries): array
    {
        $ids = array_map(fn (array $entry) => (int) ($entry['special_pricing_id'] ?? 0), $entries);
        sort($ids);

        return $ids;
    }

    public static function specialEntries(mixed $appliedDiscounts): array
    {
        return array_values(array_filter((array) ($appliedDiscounts ?? []), fn ($entry) => SpecialPricing::isSpecialPricingEntry($entry)));
    }

    public static function otherEntries(mixed $appliedDiscounts): array
    {
        return array_values(array_filter((array) ($appliedDiscounts ?? []), fn ($entry) => is_array($entry) && ! SpecialPricing::isSpecialPricingEntry($entry)));
    }
}
