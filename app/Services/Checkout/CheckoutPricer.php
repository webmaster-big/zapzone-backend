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
        $packageLine = $this->packageLine($package, $participants);

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
            $unit = $this->packageAddOnUnit($addOn, (int) $package->id);
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
            $unit = (float) $attraction->price;
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

        return $this->assemble('booking', 'package', (int) $package->id, (int) $package->location_id, $lines,
            [Carbon::parse($v['booking_date'])->toDateString()], $v, $membership);
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

        $dates = array_values(array_unique(array_filter([
            ! empty($v['scheduled_date']) ? Carbon::parse($v['scheduled_date'])->toDateString() : null,
            ! empty($v['purchase_date']) ? Carbon::parse($v['purchase_date'])->toDateString() : null,
            Carbon::now(config('app.business_timezone', 'America/Detroit'))->toDateString(),
        ])));

        return $this->assemble('attraction_purchase', 'attraction', (int) $attraction->id, (int) $attraction->location_id, $lines, $dates, $v, $membership);
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

        return $this->assemble('event_purchase', 'event', (int) $event->id, (int) $event->location_id, $lines,
            [Carbon::parse($v['purchase_date'])->toDateString()], $v, $membership);
    }

    private function assemble(string $purchaseType, string $entityType, int $entityId, int $locationId, array $lines, array $dates, array $v, ?Membership $membership): array
    {
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);

        $fees = FeeSupport::getFullPriceBreakdown($entityType, $entityId, $subtotal, $locationId);
        $additiveFees = round(((float) $fees['total']) - $subtotal, 2);

        $claimedBase = (float) collect($v['applied_discounts'] ?? [])
            ->filter(fn ($d) => ! empty($d['special_pricing_id']))
            ->max(fn ($d) => (float) ($d['original_price'] ?? 0));
        $specialBase = $claimedBase > 0 ? min($claimedBase, $subtotal) : $subtotal;

        $special = 0.0;
        $specialApplied = [];
        foreach ($dates as $date) {
            $b = SpecialPricing::getFullPriceBreakdown($entityType, $entityId, $specialBase, Carbon::parse($date), $locationId, null);
            if ((float) $b['total_discount'] > $special) {
                $special = round((float) $b['total_discount'], 2);
                $specialApplied = $b['discounts_applied'];
            }
        }

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
            'special_pricing_base' => $specialBase,
            'special_pricing_dates' => $dates,
            'special_pricing_discount' => $special,
            'special_pricing_applied' => $specialApplied,
            'membership_id' => $membership?->id,
            'membership_discount' => $membershipDiscount,
            'membership_quote_applied' => $quoteApplied,
            'expected_total' => round(max(0, $subtotal + $additiveFees - $special - $membershipDiscount), 2),
        ];
    }

    private function packageLine(Package $package, int $participants): float
    {
        $price = (float) $package->price;
        if ($package->pricing_type === 'per_person') {
            return round($price * $participants, 2);
        }
        $included = max(1, (int) ($package->min_participants ?: 1));
        $extra = max(0, $participants - $included);

        return round($price + $extra * (float) ($package->price_per_additional ?? 0), 2);
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
