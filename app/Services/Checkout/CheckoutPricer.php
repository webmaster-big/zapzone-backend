<?php

namespace App\Services\Checkout;

use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\FeeSupport;
use App\Models\Membership;
use App\Models\Package;
use App\Models\SpecialPricing;
use App\Services\MembershipBenefitService;
use Carbon\Carbon;

class CheckoutPricer
{
    public function __construct(private MembershipBenefitService $benefits)
    {
    }

    public function forBooking(array $v, Package $package, ?Membership $membership): array
    {
        $participants = max(1, (int) ($v['participants'] ?? 1));
        $packageLine = $this->packageLine($package, $participants, $v['price_snapshot'] ?? null);

        $lines = [[
            'type' => 'package',
            'id' => (int) $package->id,
            'category' => $package->category,
            'unit_price' => $packageLine,
            'quantity' => 1,
            'line_total' => $packageLine,
            'client_unit' => null,
        ]];

        foreach (($v['additional_addons'] ?? []) as $row) {
            $addOn = AddOn::find($row['addon_id'] ?? null);
            if (! $addOn) {
                continue;
            }
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $unit = isset($row['frozen_unit_price'])
                ? (float) $row['frozen_unit_price']
                : $this->packageAddOnUnit($addOn, (int) $package->id);
            $lines[] = [
                'type' => 'addon',
                'id' => (int) $addOn->id,
                'category' => null,
                'unit_price' => $unit,
                'quantity' => $qty,
                'line_total' => round($unit * $qty, 2),
                'client_unit' => isset($row['price_at_booking']) ? (float) $row['price_at_booking'] : null,
            ];
        }

        foreach (($v['additional_attractions'] ?? []) as $row) {
            $attraction = Attraction::find($row['attraction_id'] ?? null);
            if (! $attraction) {
                continue;
            }
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $multiplier = $attraction->pricing_type === 'per_person' ? $participants : 1;
            $unit = isset($row['frozen_unit_price']) ? (float) $row['frozen_unit_price'] : (float) $attraction->price;
            $lines[] = [
                'type' => 'attraction',
                'id' => (int) $attraction->id,
                'category' => $attraction->category,
                'unit_price' => $unit,
                'quantity' => $qty * $multiplier,
                'line_total' => round($unit * $qty * $multiplier, 2),
                'client_unit' => isset($row['price_at_booking']) ? (float) $row['price_at_booking'] : null,
            ];
        }

        $locationId = (int) ($v['location_id'] ?? $package->location_id);
        $snapshot = $v['price_snapshot'] ?? null;
        $perPerson = ($snapshot['pricing_type'] ?? $package->pricing_type) === 'per_person';

        return $this->assemble('booking', 'package', (int) $package->id, $locationId, $lines, [
            'unit_price' => (float) ($snapshot['price'] ?? $package->price),
            'quantity' => $perPerson ? $participants : 1,
            'date' => Carbon::parse($v['booking_date'])->toDateString(),
            'time' => $v['booking_time'] ?? null,
        ], $membership);
    }

    public function forAttractionPurchase(array $v, Attraction $attraction, ?Membership $membership): array
    {
        $qty = max(1, (int) ($v['quantity'] ?? 1));
        $unit = (float) $attraction->price;
        $lines = [[
            'type' => 'attraction',
            'id' => (int) $attraction->id,
            'category' => $attraction->category,
            'unit_price' => $unit,
            'quantity' => $qty,
            'line_total' => round($unit * $qty, 2),
            'client_unit' => null,
        ]];

        foreach (($v['additional_addons'] ?? []) as $row) {
            $addOn = AddOn::find($row['addon_id'] ?? null);
            if (! $addOn) {
                continue;
            }
            $aq = max(1, (int) ($row['quantity'] ?? 1));
            $lines[] = [
                'type' => 'addon',
                'id' => (int) $addOn->id,
                'category' => null,
                'unit_price' => (float) $addOn->price,
                'quantity' => $aq,
                'line_total' => round((float) $addOn->price * $aq, 2),
                'client_unit' => isset($row['price_at_purchase']) ? (float) $row['price_at_purchase'] : null,
            ];
        }

        return $this->assemble('attraction_purchase', 'attraction', (int) $attraction->id, (int) $attraction->location_id, $lines,
            ['unit_price' => $unit, 'quantity' => $qty] + self::attractionVisit($v), $membership);
    }

    public static function attractionVisit(array $v): array
    {
        if (! empty($v['scheduled_date'])) {
            return [
                'date' => Carbon::parse($v['scheduled_date'])->toDateString(),
                'time' => $v['scheduled_time'] ?? null,
            ];
        }

        $now = Carbon::now(config('app.business_timezone', 'America/Detroit'));
        $date = ! empty($v['purchase_date']) ? Carbon::parse($v['purchase_date'])->toDateString() : $now->toDateString();

        return [
            'date' => $date,
            'time' => $date === $now->toDateString() ? $now->format('H:i:s') : null,
        ];
    }

    public function forEventPurchase(array $v, Event $event, ?Membership $membership): array
    {
        $qty = max(1, (int) ($v['quantity'] ?? 1));
        $unit = (float) $event->price;
        $lines = [[
            'type' => 'event',
            'id' => (int) $event->id,
            'category' => null,
            'unit_price' => $unit,
            'quantity' => $qty,
            'line_total' => round($unit * $qty, 2),
            'client_unit' => null,
        ]];

        foreach (($v['add_ons'] ?? []) as $row) {
            $addOn = AddOn::find($row['add_on_id'] ?? null);
            if (! $addOn) {
                continue;
            }
            $aq = max(1, (int) ($row['quantity'] ?? 1));
            $lines[] = [
                'type' => 'addon',
                'id' => (int) $addOn->id,
                'category' => null,
                'unit_price' => (float) $addOn->price,
                'quantity' => $aq,
                'line_total' => round((float) $addOn->price * $aq, 2),
                'client_unit' => isset($row['price_at_purchase']) ? (float) $row['price_at_purchase'] : null,
            ];
        }

        return $this->assemble('event_purchase', 'event', (int) $event->id, (int) $event->location_id, $lines, [
            'unit_price' => $unit,
            'quantity' => $qty,
            'date' => Carbon::parse($v['purchase_date'])->toDateString(),
            'time' => $v['purchase_time'] ?? null,
        ], $membership);
    }

    private function assemble(string $purchaseType, string $entityType, int $entityId, int $locationId, array $lines, array $visit, ?Membership $membership): array
    {
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

        $fees = FeeSupport::getFullPriceBreakdown($entityType, $entityId, $subtotal, $locationId);
        $additiveFees = round(((float) $fees['total']) - $subtotal, 2);

        $specialBreakdown = SpecialPricing::getFullPriceBreakdown(
            $entityType,
            $entityId,
            (float) $visit['unit_price'],
            Carbon::parse($visit['date']),
            $locationId,
            SpecialPricing::normalizeTime($visit['time'] ?? null),
            (int) $visit['quantity']
        );
        $special = round(min((float) $specialBreakdown['total_discount'], $subtotal), 2);
        $specialApplied = $specialBreakdown['discounts_applied'];

        $membershipDiscount = 0.0;
        $quoteApplied = [];
        if ($membership) {
            $items = array_map(fn ($l) => [
                'type' => $l['type'],
                'id' => $l['id'],
                'category' => $l['category'] ?? null,
                'unit_price' => $l['unit_price'],
                'quantity' => $l['quantity'],
            ], $lines);
            $quote = $this->benefits->quote($membership, $locationId, $items);
            $membershipDiscount = round((float) ($quote['currency_discount'] ?? 0), 2);
            $quoteApplied = $quote['applied'] ?? [];
        }

        return [
            'purchase_type' => $purchaseType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'location_id' => $locationId,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'additive_fees' => $additiveFees,
            'fees' => $fees['fees'],
            'special_pricing_base' => (float) $specialBreakdown['original_price'],
            'special_pricing_quantity' => (int) $specialBreakdown['quantity'],
            'special_pricing_dates' => [$visit['date']],
            'special_pricing_time' => SpecialPricing::normalizeTime($visit['time'] ?? null),
            'special_pricing_discount' => $special,
            'special_pricing_applied' => $specialApplied,
            'membership_id' => $membership?->id,
            'membership_discount' => $membershipDiscount,
            'membership_quote_applied' => $quoteApplied,
            'expected_total' => round(max(0, $subtotal + $additiveFees - $special - $membershipDiscount), 2),
        ];
    }

    private function packageLine(Package $package, int $participants, ?array $snapshot = null): float
    {
        $pricingType = $snapshot['pricing_type'] ?? $package->pricing_type;
        $price = (float) ($snapshot['price'] ?? $package->price);
        $perAdditional = (float) ($snapshot['price_per_additional'] ?? $package->price_per_additional ?? 0);

        if ($pricingType === 'per_person') {
            return round($price * $participants, 2);
        }
        $included = max(1, (int) ($package->min_participants ?: 1));
        $extra = max(0, $participants - $included);

        return round($price + $extra * $perAdditional, 2);
    }

    private function packageAddOnUnit(AddOn $addOn, int $packageId): float
    {
        foreach ((array) ($addOn->price_each_packages ?? []) as $row) {
            if ((int) ($row['package_id'] ?? 0) === $packageId && isset($row['price'])) {
                return (float) $row['price'];
            }
        }

        return (float) $addOn->price;
    }
}
