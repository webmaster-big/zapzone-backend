<?php

namespace App\Services\Checkout;

use App\Models\Booking;
use App\Models\Package;
use App\Services\MembershipBenefitService;
use Illuminate\Support\Facades\Log;

class BookingRepricer
{
    public const REDEEMED_SOURCES = ['promo', 'gift_card'];

    private const CENT = 0.005;

    public function __construct(
        private CheckoutPricer $pricer,
        private MembershipBenefitService $benefits
    ) {
    }

    public function reprice(Booking $booking, array $intent = []): ?array
    {
        $proposed = $this->expectationFor($booking, $intent);
        if (! $proposed) {
            return null;
        }

        $baseline = $this->expectationFor($booking, []);
        if (! $baseline) {
            return null;
        }

        $redeemed = $this->redeemedCredit($booking);
        $proposedNet = $this->netTotal($proposed, $redeemed);
        $baselineNet = $this->netTotal($baseline, $redeemed);

        $stored = round((float) $booking->total_amount, 2);
        $delta = round($proposedNet - $baselineNet, 2);
        $total = round(max(0, $stored + $delta), 2);
        $consistent = abs($baselineNet - $stored) <= self::CENT;

        if (! $consistent) {
            Log::channel(config('checkout.log_channel') ?: config('logging.default'))
                ->warning('booking.reprice.baseline_mismatch', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'stored_total' => $stored,
                    'baseline_total' => $baselineNet,
                    'drift' => round($baselineNet - $stored, 2),
                    'delta_applied' => $delta,
                    'resulting_total' => $total,
                ]);
        }

        if ($redeemed > (float) $proposed['expected_total'] + self::CENT) {
            Log::channel(config('checkout.log_channel') ?: config('logging.default'))
                ->warning('booking.reprice.redemption_exceeds_total', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'expected_total' => (float) $proposed['expected_total'],
                    'redeemed_credit' => $redeemed,
                ]);
        }

        $special = (float) $proposed['special_pricing_discount'];
        $membershipDiscount = (float) $proposed['membership_discount'];

        return [
            'total_amount' => $total,
            'applied_fees' => $consistent ? $proposed['fees'] : ($booking->applied_fees ?? []),
            'quoted_fees' => $proposed['fees'],
            'discount_amount' => $consistent
                ? round($special + $membershipDiscount + $redeemed, 2)
                : round((float) $booking->discount_amount, 2),
            'membership_discount' => $consistent ? $membershipDiscount : round((float) $booking->membership_discount, 2),
            'subtotal' => (float) $proposed['subtotal'],
            'additive_fees' => (float) $proposed['additive_fees'],
            'special_pricing_discount' => $special,
            'redeemed_credit' => $redeemed,
            'lines' => $proposed['lines'],
            'stored_total' => $stored,
            'baseline_total' => $baselineNet,
            'delta' => $delta,
            'pricing_consistent' => $consistent,
            'expectation' => $proposed,
        ];
    }

    public function quote(Booking $booking, array $intent = []): ?array
    {
        $priced = $this->reprice($booking, $intent);
        if (! $priced) {
            return null;
        }

        $amountPaid = round((float) $booking->amount_paid, 2);
        $total = (float) $priced['total_amount'];

        return [
            'subtotal' => $priced['subtotal'],
            'lines' => $priced['lines'],
            'fees' => $priced['quoted_fees'],
            'persist_fees' => $priced['applied_fees'],
            'additive_fees' => $priced['additive_fees'],
            'special_pricing_discount' => $priced['special_pricing_discount'],
            'membership_discount' => $priced['membership_discount'],
            'redeemed_credit' => $priced['redeemed_credit'],
            'discount_amount' => $priced['discount_amount'],
            'total_amount' => $total,
            'amount_paid' => $amountPaid,
            'remaining_balance' => round($total - $amountPaid, 2),
            'payment_status' => $this->derive($amountPaid, $total, $booking->payment_status),
            'delta' => $priced['delta'],
            'pricing_consistent' => $priced['pricing_consistent'],
        ];
    }

    public function derive(float $amountPaid, float $total, ?string $current = null): string
    {
        if (in_array($current, ['refunded', 'voided'], true)) {
            return $current;
        }

        if (round($total - $amountPaid, 2) <= self::CENT) {
            return 'paid';
        }

        return $amountPaid > 0 ? 'partial' : 'pending';
    }

    private function netTotal(array $expectation, float $redeemed): float
    {
        return round(max(0, (float) $expectation['expected_total'] - $redeemed), 2);
    }

    private function expectationFor(Booking $booking, array $intent): ?array
    {
        $packageId = (int) ($intent['package_id'] ?? $booking->package_id);
        if (! $packageId) {
            return null;
        }

        $package = Package::find($packageId);
        if (! $package) {
            return null;
        }

        $packageChanged = $packageId !== (int) $booking->package_id;

        $storedAddOns = $this->storedAddOns($booking);
        $storedAttractions = $packageChanged ? [] : $this->storedAttractions($booking);

        $v = [
            'participants' => max(1, (int) ($intent['participants'] ?? $booking->participants)),
            'booking_date' => $intent['booking_date'] ?? $this->bookingDate($booking),
            'applied_discounts' => $booking->applied_discounts ?? [],
            'additional_addons' => $this->carryFrozenPrices(
                $intent['additional_addons'] ?? $storedAddOns,
                $storedAddOns,
                'addon_id'
            ),
            'additional_attractions' => $this->carryFrozenPrices(
                $intent['additional_attractions'] ?? $storedAttractions,
                $storedAttractions,
                'attraction_id'
            ),
            'price_snapshot' => $packageChanged ? null : $this->priceSnapshot($booking),
            'location_id' => (int) ($intent['location_id'] ?? $booking->location_id ?? $package->location_id),
        ];

        $membership = $booking->membership_id
            ? $this->benefits->membershipForCheckout(
                $booking->membership_id,
                null,
                $booking->customer_id ? (int) $booking->customer_id : null,
                (int) ($intent['location_id'] ?? $booking->location_id),
                'booking'
            )
            : null;

        return $this->pricer->forBooking($v, $package, $membership);
    }

    private function carryFrozenPrices(array $lines, array $stored, string $key): array
    {
        $frozen = [];
        foreach ($stored as $row) {
            if (isset($row[$key], $row['frozen_unit_price'])) {
                $frozen[(int) $row[$key]] = (float) $row['frozen_unit_price'];
            }
        }

        return array_map(function ($row) use ($frozen, $key) {
            $id = (int) ($row[$key] ?? 0);
            if (! array_key_exists('frozen_unit_price', $row) && array_key_exists($id, $frozen)) {
                $row['frozen_unit_price'] = $frozen[$id];
            }

            return $row;
        }, $lines);
    }

    private function redeemedCredit(Booking $booking): float
    {
        return round(collect($booking->applied_discounts ?? [])
            ->filter(fn ($d) => in_array($d['source'] ?? null, self::REDEEMED_SOURCES, true))
            ->sum(fn ($d) => (float) ($d['discount_amount'] ?? 0)), 2);
    }

    private function priceSnapshot(Booking $booking): ?array
    {
        if ($booking->package_price_at_booking === null) {
            return null;
        }

        return [
            'price' => (float) $booking->package_price_at_booking,
            'price_per_additional' => $booking->price_per_additional_at_booking !== null
                ? (float) $booking->price_per_additional_at_booking
                : null,
            'pricing_type' => $booking->package_pricing_type_at_booking,
        ];
    }

    private function bookingDate(Booking $booking): string
    {
        return $booking->booking_date instanceof \DateTimeInterface
            ? $booking->booking_date->format('Y-m-d')
            : (string) $booking->booking_date;
    }

    private function storedAddOns(Booking $booking): array
    {
        return $booking->addOns()->get()->map(fn ($a) => [
            'addon_id' => $a->id,
            'quantity' => (int) $a->pivot->quantity,
            'frozen_unit_price' => (float) $a->pivot->price_at_booking,
        ])->values()->all();
    }

    private function storedAttractions(Booking $booking): array
    {
        return $booking->attractions()->get()->map(fn ($a) => [
            'attraction_id' => $a->id,
            'quantity' => (int) $a->pivot->quantity,
            'frozen_unit_price' => (float) $a->pivot->price_at_booking,
        ])->values()->all();
    }
}
