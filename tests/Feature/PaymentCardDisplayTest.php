<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Package;
use App\Models\Payment;
use App\Support\CardBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCardDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $company->id,
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

        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Friday Unlimited',
            'description' => 'Flat-rate party package',
            'category' => 'party',
            'price' => 199.00,
            'pricing_type' => 'base',
            'min_participants' => 8,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    private function makeBooking(): Booking
    {
        return Booking::create([
            'reference_number' => 'BK'.strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 199.00,
            'amount_paid' => 199.00,
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'payment_method' => 'authorize.net',
        ]);
    }

    private function pay(Booking $booking, array $attributes): Payment
    {
        return Payment::create(array_merge([
            'payable_id' => $booking->id,
            'payable_type' => Payment::TYPE_BOOKING,
            'amount' => 199.00,
            'currency' => 'USD',
            'method' => 'authorize.net',
            'status' => 'completed',
            'transaction_id' => 'T'.uniqid(),
            'location_id' => $this->location->id,
            'paid_at' => now(),
        ], $attributes));
    }

    public function test_the_card_brand_and_last_four_are_stored_and_serialized(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->pay($booking, ['card_type' => 'Visa', 'card_last_four' => '3798']);

        $fresh = $payment->fresh();

        $this->assertSame('Visa', $fresh->card_type);
        $this->assertSame('3798', $fresh->card_last_four);
        $this->assertSame('Visa ending in 3798', $fresh->card_label);
        $this->assertSame('Visa ending in 3798', $fresh->toArray()['card_label']);
    }

    public function test_the_booking_details_payload_carries_the_card(): void
    {
        $booking = $this->makeBooking();
        $this->pay($booking, ['card_type' => 'Visa', 'card_last_four' => '3798']);

        $loaded = Booking::with('payments')->find($booking->id);

        $this->assertSame('Visa ending in 3798', CardBrand::fromPayments($loaded->payments));
        $this->assertSame('Visa ending in 3798', $loaded->toArray()['payments'][0]['card_label']);
    }

    public function test_the_full_card_number_is_never_stored_or_serialized(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->pay($booking, ['card_type' => 'Visa', 'card_last_four' => '3798']);

        $encoded = json_encode($payment->fresh()->toArray());

        $this->assertStringNotContainsString('4111111111111111', (string) $encoded);
        $this->assertDoesNotMatchRegularExpression('/\d(?:[ -]?\d){11,18}/', (string) $encoded);
        $this->assertSame(4, strlen((string) $payment->fresh()->card_last_four));
    }

    public function test_a_cash_booking_shows_no_card_at_all(): void
    {
        $booking = $this->makeBooking();
        $this->pay($booking, ['method' => 'cash', 'card_type' => null, 'card_last_four' => null]);

        $loaded = Booking::with('payments')->find($booking->id);

        $this->assertNull(CardBrand::fromPayments($loaded->payments));
        $this->assertNull($loaded->payments->first()->card_label);
    }

    public function test_a_legacy_payment_without_a_brand_still_shows_the_last_four(): void
    {
        $booking = $this->makeBooking();
        $this->pay($booking, ['card_type' => null, 'card_last_four' => '3424']);

        $loaded = Booking::with('payments')->find($booking->id);

        $this->assertSame('Card ending in 3424', CardBrand::fromPayments($loaded->payments));
    }

    public function test_a_membership_label_can_never_hold_a_full_card_number(): void
    {
        $membership = new Membership;
        $membership->payment_method_label = 'Jane Doe 4111111111111111';

        $this->assertSame('Jane Doe ending in 1111', $membership->payment_method_label);
        $this->assertDoesNotMatchRegularExpression('/\d(?:[ -]?\d){11,18}/', (string) $membership->payment_method_label);
    }
}
