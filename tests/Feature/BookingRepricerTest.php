<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\FeeSupport;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Services\Checkout\BookingRepricer;
use App\Services\Payments\PayableLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingRepricerTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected Package $flatPackage;
    protected Package $perPersonPackage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Waterford',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->flatPackage = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Friday Unlimited',
            'description' => 'Flat-rate party package',
            'category' => 'party',
            'price' => 199.00,
            'price_per_additional' => 29.99,
            'pricing_type' => 'base',
            'min_participants' => 8,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->perPersonPackage = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Escape Room',
            'description' => 'Per-person escape room',
            'category' => 'escape',
            'price' => 25.00,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    private function makeBooking(Package $package, int $participants, float $total, float $paid, array $extra = []): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '14:00',
            'participants' => $participants,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'package_price_at_booking' => $package->price,
            'price_per_additional_at_booking' => $package->price_per_additional,
            'package_pricing_type_at_booking' => $package->pricing_type,
            'total_amount' => $total,
            'amount_paid' => $paid,
            'payment_status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'pending'),
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ], $extra));
    }

    public function test_a_no_op_reprice_does_not_move_the_total(): void
    {
        $booking = $this->makeBooking($this->flatPackage, 8, 199.00, 50.00);

        $quote = app(BookingRepricer::class)->quote($booking);

        $this->assertSame(199.00, $quote['total_amount']);
        $this->assertSame(149.00, $quote['remaining_balance']);
        $this->assertSame('partial', $quote['payment_status']);
    }

    public function test_increasing_participants_adds_the_per_additional_price(): void
    {
        $booking = $this->makeBooking($this->flatPackage, 8, 199.00, 50.00);

        $quote = app(BookingRepricer::class)->quote($booking, ['participants' => 12]);

        $this->assertSame(round(199.00 + 4 * 29.99, 2), $quote['total_amount']);
    }

    public function test_decreasing_participants_reduces_a_per_person_total(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 200.00, 0.00);

        $this->assertSame(250.00, app(BookingRepricer::class)->quote($booking, ['participants' => 10])['total_amount']);
        $this->assertSame(150.00, app(BookingRepricer::class)->quote($booking, ['participants' => 6])['total_amount']);
    }

    public function test_returning_to_the_original_count_restores_paid_in_full(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 200.00, 200.00);
        $repricer = app(BookingRepricer::class);

        foreach ([11, 14, 6] as $n) {
            $moved = $repricer->quote($booking, ['participants' => $n]);
            $booking->update(['participants' => $n, 'total_amount' => $moved['total_amount']]);
        }

        $restored = $repricer->quote($booking->fresh(), ['participants' => 8]);

        $this->assertSame(200.00, $restored['total_amount']);
        $this->assertSame(0.0, $restored['remaining_balance']);
        $this->assertSame('paid', $restored['payment_status']);
    }

    public function test_a_redeemed_promo_survives_a_participant_change(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 150.00, 0.00, [
            'discount_amount' => 50.00,
            'applied_discounts' => [[
                'discount_name' => 'Fair50',
                'discount_amount' => 50,
                'discount_type' => 'fixed',
                'original_price' => 200,
                'source' => 'promo',
                'promo_id' => 1,
                'code' => 'Fair50',
            ]],
        ]);

        $quote = app(BookingRepricer::class)->quote($booking, ['participants' => 10]);

        $this->assertSame(200.00, $quote['total_amount']);
        $this->assertSame(50.0, $quote['redeemed_credit']);
    }

    public function test_a_percentage_fee_rescales_with_the_participant_count(): void
    {
        FeeSupport::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'fee_name' => 'Venue Fee',
            'fee_amount' => 4.87,
            'fee_calculation_type' => 'percentage',
            'fee_application_type' => 'additive',
            'entity_ids' => [],
            'entity_type' => 'package',
            'applies_to_all' => true,
            'is_active' => true,
        ]);

        $booking = $this->makeBooking($this->perPersonPackage, 8, 209.74, 0.00);
        $repricer = app(BookingRepricer::class);

        $at8 = $repricer->quote($booking);
        $at16 = $repricer->quote($booking, ['participants' => 16]);

        $this->assertSame(round(200 * 0.0487, 2), $at8['additive_fees']);
        $this->assertSame(round(400 * 0.0487, 2), $at16['additive_fees']);
        $this->assertGreaterThan($at8['additive_fees'], $at16['additive_fees']);
    }

    public function test_applies_to_all_covers_a_package_missing_from_entity_ids(): void
    {
        $fee = FeeSupport::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'fee_name' => 'Venue Fee',
            'fee_amount' => 4.87,
            'fee_calculation_type' => 'percentage',
            'fee_application_type' => 'additive',
            'entity_ids' => [999999],
            'entity_type' => 'package',
            'applies_to_all' => false,
            'is_active' => true,
        ]);

        $this->assertFalse($fee->appliesToEntity($this->perPersonPackage->id));

        $fee->update(['applies_to_all' => true]);

        $this->assertTrue($fee->fresh()->appliesToEntity($this->perPersonPackage->id));
    }

    public function test_a_drifted_booking_moves_only_by_the_delta(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 120.00, 120.00);

        $quote = app(BookingRepricer::class)->quote($booking, ['participants' => 10]);

        $this->assertFalse($quote['pricing_consistent']);
        $this->assertSame(50.0, $quote['delta']);
        $this->assertSame(170.00, $quote['total_amount']);
    }

    public function test_derive_preserves_terminal_states_and_uses_a_cent_epsilon(): void
    {
        $repricer = app(BookingRepricer::class);

        $this->assertSame('paid', $repricer->derive(100.00, 100.00));
        $this->assertSame('partial', $repricer->derive(99.98, 100.00));
        $this->assertSame('paid', $repricer->derive(99.999, 100.00));
        $this->assertSame('pending', $repricer->derive(0.0, 100.00));
        $this->assertSame('refunded', $repricer->derive(100.00, 100.00, 'refunded'));
        $this->assertSame('voided', $repricer->derive(50.00, 100.00, 'voided'));
    }

    public function test_the_ledger_nets_refunds_and_survives_a_later_payment(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 200.00, 0.00);
        $ledger = app(PayableLedger::class);

        Payment::create([
            'payable_id' => $booking->id, 'payable_type' => Payment::TYPE_BOOKING,
            'amount' => 200.00, 'currency' => 'USD', 'method' => 'cash', 'status' => 'completed',
            'transaction_id' => 'T1', 'location_id' => $this->location->id, 'paid_at' => now(),
        ]);
        $ledger->sync(Payment::TYPE_BOOKING, $booking->id);
        $this->assertSame('200.00', (string) $booking->fresh()->amount_paid);

        Payment::create([
            'payable_id' => $booking->id, 'payable_type' => Payment::TYPE_BOOKING,
            'amount' => 75.00, 'currency' => 'USD', 'method' => 'cash', 'status' => 'refunded',
            'transaction_id' => 'T2', 'location_id' => $this->location->id, 'refunded_at' => now(),
        ]);
        $ledger->sync(Payment::TYPE_BOOKING, $booking->id);
        $this->assertSame('125.00', (string) $booking->fresh()->amount_paid);

        Payment::create([
            'payable_id' => $booking->id, 'payable_type' => Payment::TYPE_BOOKING,
            'amount' => 10.00, 'currency' => 'USD', 'method' => 'cash', 'status' => 'completed',
            'transaction_id' => 'T3', 'location_id' => $this->location->id, 'paid_at' => now(),
        ]);
        $ledger->sync(Payment::TYPE_BOOKING, $booking->id);

        $this->assertSame('135.00', (string) $booking->fresh()->amount_paid);
    }

    public function test_the_ledger_never_zeroes_a_booking_that_has_no_payment_rows(): void
    {
        $booking = $this->makeBooking($this->perPersonPackage, 8, 200.00, 50.00);

        app(PayableLedger::class)->sync(Payment::TYPE_BOOKING, $booking->id);

        $this->assertSame('50.00', (string) $booking->fresh()->amount_paid);
    }
}
