<?php

namespace App\Services\Checkout;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\SpecialPricing;
use Carbon\Carbon;

class PurchaseSpecialRepricer
{
    private const CENT = 0.005;

    public function forAttractionPurchase(AttractionPurchase $purchase, array $changes = []): ?array
    {
        $original = Attraction::find($purchase->attraction_id);
        $targetId = (int) ($changes['attraction_id'] ?? $purchase->attraction_id);
        $target = $targetId === (int) $purchase->attraction_id ? $original : Attraction::find($targetId);

        if (! $original || ! $target) {
            return null;
        }

        $originalUnit = $this->ticketPrice($purchase, (float) $original->price);

        $from = [
            'id' => (int) $original->id,
            'location_id' => $original->location_id ? (int) $original->location_id : null,
            'unit_price' => $originalUnit,
            'quantity' => max(1, (int) $purchase->quantity),
        ] + CheckoutPricer::attractionVisit([
            'scheduled_date' => $purchase->scheduled_date?->toDateString(),
            'scheduled_time' => $purchase->scheduled_time?->format('H:i'),
            'purchase_date' => $purchase->purchase_date?->toDateString(),
        ]);

        $to = [
            'id' => (int) $target->id,
            'location_id' => $target->location_id ? (int) $target->location_id : null,
            'unit_price' => (int) $target->id === (int) $original->id ? $originalUnit : (float) $target->price,
            'quantity' => max(1, (int) ($changes['quantity'] ?? $purchase->quantity)),
        ] + CheckoutPricer::attractionVisit([
            'scheduled_date' => array_key_exists('scheduled_date', $changes) ? $changes['scheduled_date'] : $purchase->scheduled_date?->toDateString(),
            'scheduled_time' => array_key_exists('scheduled_time', $changes) ? $changes['scheduled_time'] : $purchase->scheduled_time?->format('H:i'),
            'purchase_date' => $changes['purchase_date'] ?? $purchase->purchase_date?->toDateString(),
        ]);

        return $this->quote($purchase, SpecialPricing::ENTITY_ATTRACTION, $from, $to);
    }

    public function forEventPurchase(EventPurchase $purchase, array $changes = []): ?array
    {
        $event = Event::withTrashed()->find($purchase->event_id);
        $originalDate = $purchase->purchase_date?->toDateString();

        if (! $event || ! $originalDate) {
            return null;
        }

        $base = [
            'id' => (int) $event->id,
            'location_id' => $event->location_id ? (int) $event->location_id : null,
            'unit_price' => $this->ticketPrice($purchase, (float) $event->price),
        ];

        $from = $base + [
            'quantity' => max(1, (int) $purchase->quantity),
            'date' => $originalDate,
            'time' => $purchase->purchase_time?->format('H:i'),
        ];

        $to = $base + [
            'quantity' => max(1, (int) ($changes['quantity'] ?? $purchase->quantity)),
            'date' => ! empty($changes['purchase_date']) ? Carbon::parse($changes['purchase_date'])->toDateString() : $originalDate,
            'time' => array_key_exists('purchase_time', $changes) ? $changes['purchase_time'] : $from['time'],
        ];

        return $this->quote($purchase, SpecialPricing::ENTITY_EVENT, $from, $to);
    }

    private function quote(AttractionPurchase|EventPurchase $purchase, string $entityType, array $from, array $to): array
    {
        $given = SpecialPricingSwap::specialEntries($purchase->applied_discounts);
        $givenTotal = SpecialPricingSwap::total($given);
        $proposed = $this->entries($entityType, $to);

        $special = SpecialPricingSwap::choose(
            $given,
            $this->entries($entityType, $from),
            $proposed,
            $from['quantity'],
            $to['quantity'],
            $from['id'] === $to['id'],
            round($to['unit_price'] * $to['quantity'], 2)
        );

        $specialTotal = SpecialPricingSwap::total($special);
        $delta = round($specialTotal - $givenTotal, 2);

        return [
            'changed' => abs($delta) > self::CENT || SpecialPricingSwap::ids($special) !== SpecialPricingSwap::ids($given),
            'special_discounts' => $special,
            'special_pricing_discount' => $specialTotal,
            'given_special' => $givenTotal,
            'proposed_special' => SpecialPricingSwap::total($proposed),
            'proposed_discounts' => $proposed,
            'delta' => $delta,
            'applied_discounts' => array_merge($special, SpecialPricingSwap::otherEntries($purchase->applied_discounts)),
            'discount_amount' => round(max(0, (float) $purchase->discount_amount + $delta), 2),
            'total_amount' => round(max(0, (float) $purchase->total_amount - $delta), 2),
        ];
    }

    private function ticketPrice(AttractionPurchase|EventPurchase $purchase, float $catalogPrice): float
    {
        if ($purchase->unit_price !== null) {
            return (float) $purchase->unit_price;
        }

        $addOns = $purchase->addOns->sum(fn ($addOn) => (float) ($addOn->pivot->price_at_purchase ?? 0) * (int) ($addOn->pivot->quantity ?? 0));
        $fees = collect((array) ($purchase->applied_fees ?? []))
            ->filter(fn ($fee) => is_array($fee) && ($fee['fee_application_type'] ?? null) === 'additive')
            ->sum(fn (array $fee) => (float) ($fee['fee_amount'] ?? 0));
        $unit = ((float) $purchase->total_amount + (float) $purchase->discount_amount - $addOns - $fees) / max(1, (int) $purchase->quantity);

        return $unit > 0 ? $unit : $catalogPrice;
    }

    private function entries(string $entityType, array $visit): array
    {
        return SpecialPricing::toAppliedDiscounts(SpecialPricing::getFullPriceBreakdown(
            $entityType,
            $visit['id'],
            $visit['unit_price'],
            Carbon::parse(Carbon::parse($visit['date'])->toDateString()),
            $visit['location_id'],
            SpecialPricing::normalizeTime($visit['time'] ?? null),
            $visit['quantity']
        ));
    }
}
