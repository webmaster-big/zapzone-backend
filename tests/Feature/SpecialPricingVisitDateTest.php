<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Package;
use App\Models\SpecialPricing;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\Checkout\BookingRepricer;
use App\Services\Checkout\CheckoutPricer;
use App\Services\Checkout\PurchaseSpecialRepricer;
use App\Services\EmailNotificationService;
use App\Services\TicketOrderPricer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpecialPricingVisitDateTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-09';
    private const TUESDAY = '2026-11-10';
    private const WEDNESDAY = '2026-11-11';

    private Company $company;
    private Location $location;
    private Location $otherLocation;
    private Attraction $laserTag;
    private Attraction $wristband;
    private SpecialPricing $halfOffTuesdays;
    private SpecialPricing $wristbandTuesdays;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setMichiganNow(self::MONDAY . ' 10:00:00');

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = $this->makeLocation('Brighton');
        $this->otherLocation = $this->makeLocation('Ypsilanti');

        $this->laserTag = $this->makeAttraction($this->location, 'Laser Tag', 10.00);
        $this->wristband = $this->makeAttraction($this->location, 'Unlimited Wristband', 32.99);

        $this->halfOffTuesdays = $this->makeRule([
            'name' => 'Tuesday Special',
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 50,
            'entity_ids' => [$this->laserTag->id],
        ]);
        $this->wristbandTuesdays = $this->makeRule([
            'name' => 'Wristband Tuesday',
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 13,
            'entity_ids' => [$this->wristband->id],
        ]);

        $this->manager = User::create([
            'first_name' => 'Desk',
            'last_name' => 'Manager',
            'email' => 'manager@special.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function setMichiganNow(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'America/Detroit'));
    }

    private function makeLocation(string $name): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => $name,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($name) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeAttraction(Location $location, string $name, float $price): Attraction
    {
        return Attraction::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => "{$name} arena",
            'category' => 'Attractions',
            'price' => $price,
            'pricing_type' => 'per_person',
            'duration' => 30,
            'max_capacity' => 50,
            'is_active' => true,
        ]);
    }

    private function makeRule(array $overrides): SpecialPricing
    {
        return SpecialPricing::create(array_merge([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'recurrence_type' => SpecialPricing::RECURRENCE_WEEKLY,
            'recurrence_value' => SpecialPricing::TUESDAY,
            'entity_type' => SpecialPricing::ENTITY_ATTRACTION,
            'is_active' => true,
            'is_stackable' => false,
            'priority' => 0,
        ], $overrides));
    }

    private function attractionQuote(Attraction $attraction, array $v): array
    {
        return app(CheckoutPricer::class)->forAttractionPurchase(array_merge([
            'quantity' => 3,
            'purchase_date' => Carbon::now('America/Detroit')->toDateString(),
        ], $v), $attraction, null);
    }

    private function asManager(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->manager->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    public function test_buying_on_monday_for_a_tuesday_visit_gets_the_tuesday_special(): void
    {
        $quote = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00']);

        $this->assertSame(15.0, $quote['special_pricing_discount']);
        $this->assertSame([self::TUESDAY], $quote['special_pricing_dates']);
        $this->assertSame(15.0, $quote['expected_total']);
    }

    public function test_buying_on_tuesday_for_a_wednesday_visit_pays_full_price(): void
    {
        $this->setMichiganNow(self::TUESDAY . ' 11:00:00');

        $quote = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::WEDNESDAY, 'scheduled_time' => '14:00']);

        $this->assertSame(0.0, $quote['special_pricing_discount']);
        $this->assertSame(30.0, $quote['expected_total']);
    }

    public function test_buying_days_ahead_for_a_later_tuesday_still_gets_the_special(): void
    {
        $this->setMichiganNow('2026-11-05 09:00:00');

        $quote = $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-17', 'scheduled_time' => '12:00']);

        $this->assertSame(15.0, $quote['special_pricing_discount']);
    }

    public function test_a_walk_in_with_no_visit_scheduled_is_priced_for_the_day_it_is_bought(): void
    {
        $this->setMichiganNow(self::TUESDAY . ' 11:00:00');
        $tuesday = $this->attractionQuote($this->laserTag, []);

        $this->setMichiganNow(self::WEDNESDAY . ' 11:00:00');
        $wednesday = $this->attractionQuote($this->laserTag, []);

        $this->assertSame(15.0, $tuesday['special_pricing_discount']);
        $this->assertSame(0.0, $wednesday['special_pricing_discount']);
    }

    public function test_the_tuesday_special_follows_michigan_time_late_on_monday_evening(): void
    {
        $this->setMichiganNow(self::MONDAY . ' 22:30:00');

        $quote = $this->attractionQuote($this->laserTag, []);

        $this->assertSame(0.0, $quote['special_pricing_discount']);
        $this->assertSame([self::MONDAY], $quote['special_pricing_dates']);
    }

    public function test_a_fixed_special_comes_off_every_ticket(): void
    {
        $quote = $this->attractionQuote($this->wristband, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00']);

        $this->assertSame(39.0, $quote['special_pricing_discount']);
        $this->assertSame(round(32.99 * 3 - 39, 2), $quote['expected_total']);
    }

    public function test_add_ons_are_not_discounted_by_the_special(): void
    {
        $socks = AddOn::create(['location_id' => $this->location->id, 'name' => 'Grip Socks', 'price' => 4.00, 'is_active' => true]);

        $quote = $this->attractionQuote($this->laserTag, [
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'additional_addons' => [['addon_id' => $socks->id, 'quantity' => 2]],
        ]);

        $this->assertSame(38.0, $quote['subtotal']);
        $this->assertSame(15.0, $quote['special_pricing_discount']);
        $this->assertSame(23.0, $quote['expected_total']);
    }

    public function test_a_time_window_is_checked_against_the_visit_time(): void
    {
        $this->halfOffTuesdays->update(['time_start' => '16:00', 'time_end' => '20:00']);

        $early = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00']);
        $evening = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '17:30']);

        $this->assertSame(0.0, $early['special_pricing_discount']);
        $this->assertSame(15.0, $evening['special_pricing_discount']);
    }

    public function test_a_special_at_another_location_does_not_apply(): void
    {
        $ypsiTag = $this->makeAttraction($this->otherLocation, 'Laser Tag', 10.00);

        $quote = $this->attractionQuote($ypsiTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00']);

        $this->assertSame(0.0, $quote['special_pricing_discount']);
    }

    public function test_the_cart_prices_every_line_by_its_own_visit_date(): void
    {
        $this->setMichiganNow(self::TUESDAY . ' 11:00:00');

        $cart = app(TicketOrderPricer::class)->priceCart([
            ['type' => 'attraction', 'id' => $this->laserTag->id, 'quantity' => 3, 'scheduled_date' => self::WEDNESDAY, 'scheduled_time' => '14:00'],
            ['type' => 'attraction', 'id' => $this->wristband->id, 'quantity' => 2, 'scheduled_date' => '2026-11-17', 'scheduled_time' => '15:00'],
        ]);

        [$wednesdayLine, $tuesdayLine] = $cart['lines'];

        $this->assertSame(0.0, $wednesdayLine['discount_amount']);
        $this->assertSame([], $wednesdayLine['applied_discounts']);
        $this->assertSame(30.0, $wednesdayLine['total_amount']);

        $this->assertSame(26.0, $tuesdayLine['discount_amount']);
        $this->assertSame(26.0, $tuesdayLine['applied_discounts'][0]['discount_amount']);
        $this->assertSame(13.0, $tuesdayLine['applied_discounts'][0]['unit_discount_amount']);
        $this->assertSame('special_pricing', $tuesdayLine['applied_discounts'][0]['source']);
        $this->assertSame(19.99, $tuesdayLine['unit_price_after_discount']);
        $this->assertSame(round(32.99 * 2 - 26, 2), $tuesdayLine['total_amount']);
        $this->assertSame(26.0, $cart['discount_amount']);
    }

    public function test_the_cart_and_a_single_purchase_charge_the_same_for_the_same_visit(): void
    {
        \App\Models\FeeSupport::create([
            'company_id' => $this->company->id,
            'fee_name' => 'Service Fee',
            'fee_amount' => 4.87,
            'fee_calculation_type' => 'percentage',
            'fee_application_type' => 'additive',
            'entity_type' => 'attraction',
            'entity_ids' => [$this->laserTag->id],
            'applies_to_all' => true,
            'is_active' => true,
        ]);

        $single = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00']);
        $cart = app(TicketOrderPricer::class)->priceCart([
            ['type' => 'attraction', 'id' => $this->laserTag->id, 'quantity' => 3, 'scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00'],
        ]);

        $this->assertGreaterThan(0, $single['additive_fees']);
        $this->assertSame($single['expected_total'], $cart['total_amount']);
    }

    public function test_a_per_person_package_special_applies_to_every_participant(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Escape Room',
            'description' => 'Per-person room',
            'category' => 'escape',
            'price' => 25.00,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->makeRule([
            'name' => 'Tuesday Rooms',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 20,
            'entity_ids' => [$package->id],
        ]);

        $tuesday = app(CheckoutPricer::class)->forBooking(['participants' => 4, 'booking_date' => self::TUESDAY, 'booking_time' => '18:00'], $package, null);
        $wednesday = app(CheckoutPricer::class)->forBooking(['participants' => 4, 'booking_date' => self::WEDNESDAY, 'booking_time' => '18:00'], $package, null);

        $this->assertSame(20.0, $tuesday['special_pricing_discount']);
        $this->assertSame(80.0, $tuesday['expected_total']);
        $this->assertSame(0.0, $wednesday['special_pricing_discount']);
    }

    private function makeFlatParty(): Package
    {
        return Package::create([
            'location_id' => $this->location->id,
            'name' => 'Party',
            'description' => 'Flat party',
            'category' => 'party',
            'price' => 200.00,
            'price_per_additional' => 20,
            'pricing_type' => 'base',
            'min_participants' => 8,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    private function makeBookingOn(Package $package, array $overrides): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => self::TUESDAY,
            'booking_time' => '12:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'package_price_at_booking' => $package->price,
            'price_per_additional_at_booking' => $package->price_per_additional,
            'package_pricing_type_at_booking' => $package->pricing_type,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ], $overrides));
    }

    public function test_a_legacy_booking_moved_off_tuesday_is_charged_full_price_not_more(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Escape Room',
            'description' => 'Per-person room',
            'category' => 'escape',
            'price' => 25.00,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 12,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $rule = $this->makeRule([
            'name' => 'Tuesday Rooms',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 20,
            'entity_ids' => [$package->id],
        ]);
        $booking = $this->makeBookingOn($package, [
            'participants' => 10,
            'total_amount' => 245,
            'discount_amount' => 5,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Rooms', 'discount_amount' => 5, 'discount_type' => 'percentage', 'original_price' => 25, 'special_pricing_id' => $rule->id],
            ],
        ]);

        $repricer = app(BookingRepricer::class);

        $noOp = $repricer->quote($booking);
        $this->assertSame(245.0, $noOp['total_amount']);
        $this->assertNull($noOp['applied_discounts']);

        $sameDayLater = $repricer->quote($booking, ['booking_time' => '15:00']);
        $this->assertSame(245.0, $sameDayLater['total_amount']);

        $wednesday = $repricer->quote($booking, ['booking_date' => self::WEDNESDAY]);
        $this->assertSame(250.0, $wednesday['total_amount']);
        $this->assertSame(0.0, (float) $wednesday['discount_amount']);
        $this->assertSame([], $wednesday['applied_discounts']);
    }

    public function test_a_booking_that_never_had_the_special_is_not_charged_extra_when_moved(): void
    {
        $package = $this->makeFlatParty();
        $booking = $this->makeBookingOn($package, ['total_amount' => 200]);
        $this->makeRule([
            'name' => 'New Tuesday Deal',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);

        $repricer = app(BookingRepricer::class);

        $this->assertSame(200.0, $repricer->quote($booking)['total_amount']);
        $this->assertSame(200.0, $repricer->quote($booking, ['booking_date' => self::WEDNESDAY])['total_amount']);
        $this->assertSame(200.0, $repricer->quote($booking, ['participants' => 8])['total_amount']);
    }

    public function test_moving_into_a_time_window_never_applies_the_special_twice(): void
    {
        $package = $this->makeFlatParty();
        $rule = $this->makeRule([
            'name' => 'Tuesday Evenings',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
            'time_start' => '16:00',
            'time_end' => '21:00',
        ]);
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Evenings', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => $rule->id],
            ],
        ]);

        $evening = app(BookingRepricer::class)->quote($booking, ['booking_time' => '17:00']);

        $this->assertSame(150.0, $evening['total_amount']);
        $this->assertSame(50.0, (float) $evening['discount_amount']);
        $this->assertSame([$rule->id], array_column($evening['applied_discounts'], 'special_pricing_id'));
    }

    public function test_saving_a_booking_keeps_gift_card_and_promo_details_on_its_discount_lines(): void
    {
        $package = $this->makeFlatParty();
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 175,
            'discount_amount' => 25,
            'applied_discounts' => [
                ['discount_name' => 'Gift Card ABC', 'discount_amount' => 25, 'discount_type' => 'fixed', 'original_price' => 200, 'source' => 'gift_card', 'gift_card_id' => 7, 'code' => 'ABC'],
            ],
        ]);

        $this->asManager()->putJson("/api/bookings/{$booking->id}", [
            'notes' => 'Bring cake',
            'change_reason' => 'Guest asked',
            'total_amount' => 175,
            'discount_amount' => 25,
            'applied_discounts' => $booking->applied_discounts,
        ])->assertOk();

        $entry = $booking->fresh()->applied_discounts[0];
        $this->assertSame('gift_card', $entry['source']);
        $this->assertSame(7, (int) $entry['gift_card_id']);
        $this->assertSame('ABC', $entry['code']);
    }

    public function test_moving_a_booking_with_only_a_date_rewrites_its_special_lines_on_the_server(): void
    {
        $package = $this->makeFlatParty();
        $rule = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => $rule->id],
            ],
        ]);

        $this->asManager()->putJson("/api/bookings/{$booking->id}", [
            'booking_date' => self::WEDNESDAY,
            'change_reason' => 'Guest asked',
        ])->assertOk();

        $moved = $booking->fresh();
        $this->assertSame(200.0, (float) $moved->total_amount);
        $this->assertSame([], $moved->applied_discounts);
    }

    public function test_a_single_purchase_keeps_its_ticket_price_for_later_reschedules(): void
    {
        $response = $this->asManager()->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Walk In',
            'guest_email' => 'walkin@example.com',
            'quantity' => 3,
            'total_amount' => 15,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'discount_amount' => 15,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
        ])->assertCreated();

        $purchase = AttractionPurchase::findOrFail($response->json('data.id'));
        $this->assertSame(10.0, (float) $purchase->unit_price);

        $this->laserTag->update(['price' => 12]);

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => '2026-11-17',
            'scheduled_time' => '14:00',
        ])->assertOk();

        $this->assertSame(15.0, (float) $purchase->fresh()->total_amount);
    }

    public function test_a_visit_date_sent_with_a_time_still_counts_as_that_day(): void
    {
        $this->halfOffTuesdays->update(['start_date' => self::TUESDAY]);

        $cart = app(TicketOrderPricer::class)->priceCart([
            ['type' => 'attraction', 'id' => $this->laserTag->id, 'quantity' => 1, 'scheduled_date' => self::TUESDAY . 'T00:00:00Z', 'scheduled_time' => '14:00'],
        ]);

        $this->assertSame(5.0, $cart['lines'][0]['discount_amount']);
    }

    public function test_moving_a_booking_off_tuesday_drops_the_special_line_and_charges_the_difference(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Party',
            'description' => 'Flat party',
            'category' => 'party',
            'price' => 200.00,
            'price_per_additional' => 20,
            'pricing_type' => 'base',
            'min_participants' => 8,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);

        $booking = Booking::create([
            'reference_number' => 'BKSPECIAL1',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => self::TUESDAY,
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'package_price_at_booking' => 200,
            'price_per_additional_at_booking' => 20,
            'package_pricing_type_at_booking' => 'base',
            'total_amount' => 150,
            'amount_paid' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => SpecialPricing::where('name', 'Tuesday Parties')->value('id')],
                ['discount_name' => 'Staff courtesy', 'discount_amount' => 0, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => null],
            ],
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ]);

        $quote = app(BookingRepricer::class)->quote($booking, ['booking_date' => self::WEDNESDAY]);

        $this->assertSame(200.0, $quote['total_amount']);
        $this->assertSame(0.0, (float) $quote['discount_amount']);
        $this->assertSame(50.0, $quote['remaining_balance']);
        $this->assertSame('partial', $quote['payment_status']);
        $this->assertSame(['Staff courtesy'], array_column($quote['applied_discounts'], 'discount_name'));

        $back = app(BookingRepricer::class)->quote($booking, ['booking_date' => self::TUESDAY]);
        $this->assertSame(150.0, $back['total_amount']);
        $this->assertNull($back['applied_discounts']);

        $nextTuesday = app(BookingRepricer::class)->quote($booking, ['booking_date' => '2026-11-17']);
        $this->assertSame(150.0, $nextTuesday['total_amount']);
        $this->assertNull($nextTuesday['applied_discounts']);
    }

    public function test_a_bare_reschedule_of_a_booking_onto_tuesday_reprices_on_the_server(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Party',
            'description' => 'Flat party',
            'category' => 'party',
            'price' => 200.00,
            'price_per_additional' => 20,
            'pricing_type' => 'base',
            'min_participants' => 8,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $rule = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
            'time_start' => '16:00',
            'time_end' => '21:00',
        ]);
        $booking = Booking::create([
            'reference_number' => 'BKSPECIAL2',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => self::TUESDAY,
            'booking_time' => '12:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'package_price_at_booking' => 200,
            'price_per_additional_at_booking' => 20,
            'package_pricing_type_at_booking' => 'base',
            'total_amount' => 200,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ]);

        $quote = app(BookingRepricer::class)->quote($booking, ['booking_time' => '17:00']);

        $this->assertSame(150.0, $quote['total_amount']);
        $this->assertSame([$rule->id], array_column($quote['applied_discounts'], 'special_pricing_id'));
    }

    public function test_rescheduling_a_single_attraction_purchase_through_the_api_moves_the_special(): void
    {
        $purchase = AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Walk Up',
            'guest_email' => 'walkup@example.com',
            'quantity' => 3,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 15,
            'amount_paid' => 15,
            'discount_amount' => 15,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => self::WEDNESDAY,
            'scheduled_time' => '14:00',
        ])->assertOk();

        $moved = $purchase->fresh();
        $this->assertSame(30.0, (float) $moved->total_amount);
        $this->assertSame(0.0, (float) $moved->discount_amount);
        $this->assertSame([], $moved->applied_discounts);

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => '2026-11-17',
            'scheduled_time' => '15:00',
        ])->assertOk();

        $back = $purchase->fresh();
        $this->assertSame(15.0, (float) $back->total_amount);
        $this->assertSame(15.0, (float) $back->discount_amount);
        $this->assertSame([$this->halfOffTuesdays->id], array_column($back->applied_discounts, 'special_pricing_id'));
    }

    public function test_a_staff_edit_that_sends_its_own_totals_is_left_alone(): void
    {
        $purchase = AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Walk Up',
            'quantity' => 3,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 15,
            'discount_amount' => 15,
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => self::WEDNESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 12,
            'discount_amount' => 18,
            'applied_discounts' => null,
        ])->assertOk();

        $this->assertSame(12.0, (float) $purchase->fresh()->total_amount);
    }

    public function test_a_cart_order_line_keeps_its_price_when_its_visit_moves(): void
    {
        $order = TicketOrder::create([
            'reference_number' => 'ORD20261109ABCDEF',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => self::MONDAY,
            'item_count' => 1,
            'ticket_count' => 3,
            'subtotal' => 30,
            'discount_amount' => 15,
            'total_amount' => 15,
            'amount_paid' => 15,
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);
        $line = AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'ticket_order_id' => $order->id,
            'line_position' => 1,
            'guest_name' => 'Cart Shopper',
            'quantity' => 3,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 15,
            'amount_paid' => 15,
            'discount_amount' => 15,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id, 'source' => 'special_pricing', 'unit_discount_amount' => 5, 'quantity' => 3],
            ],
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);

        $this->asManager()->putJson("/api/attraction-purchases/{$line->id}", [
            'scheduled_date' => self::WEDNESDAY,
            'scheduled_time' => '14:00',
        ])->assertOk();

        $moved = $line->fresh();
        $this->assertSame(15.0, (float) $moved->total_amount);
        $this->assertSame(15.0, (float) $moved->discount_amount);
        $this->assertSame([$this->halfOffTuesdays->id], array_column($moved->applied_discounts, 'special_pricing_id'));
        $this->assertSame(self::WEDNESDAY, $moved->scheduled_date->toDateString());
    }

    public function test_a_cart_event_line_keeps_its_price_when_its_visit_moves(): void
    {
        $event = $this->makeGlowNight();
        $rule = $this->makeTuesdayGlow($event);
        $order = TicketOrder::create([
            'reference_number' => 'ORD20261109EVLINE',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => self::MONDAY,
            'item_count' => 1,
            'ticket_count' => 2,
            'subtotal' => 30,
            'discount_amount' => 10,
            'total_amount' => 20,
            'amount_paid' => 20,
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);
        $line = $this->makeEventPurchase($event, $rule, [
            'ticket_order_id' => $order->id,
            'line_position' => 1,
        ]);

        $this->asManager()->putJson("/api/event-purchases/{$line->id}", [
            'purchase_date' => self::WEDNESDAY,
            'purchase_time' => '19:00',
        ])->assertOk();

        $moved = $line->fresh();
        $this->assertSame(20.0, (float) $moved->total_amount);
        $this->assertSame([$rule->id], array_column($moved->applied_discounts, 'special_pricing_id'));
        $this->assertSame(self::WEDNESDAY, $moved->purchase_date->toDateString());
    }

    public function test_accounting_counts_the_whole_special_on_a_multi_ticket_cart_line(): void
    {
        $this->setMichiganNow(self::TUESDAY . ' 11:00:00');

        $cart = app(TicketOrderPricer::class)->priceCart([
            ['type' => 'attraction', 'id' => $this->laserTag->id, 'quantity' => 3, 'scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00'],
        ]);
        $line = $cart['lines'][0];

        AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Cart Shopper',
            'quantity' => 3,
            'purchase_date' => self::TUESDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => $line['total_amount'],
            'discount_amount' => $line['discount_amount'],
            'applied_discounts' => $line['applied_discounts'],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);

        $report = app(AccountingReportService::class)->buildReportData(
            [$this->location->id],
            Carbon::parse(self::TUESDAY),
            Carbon::parse(self::TUESDAY),
            'visit_date'
        );

        $this->assertSame(15.0, (float) $report['summary']['discount_by_source']['special_pricing']);
    }

    public function test_discounts_by_source_follow_the_report_filters(): void
    {
        $this->tuesdayLaserTagPurchase();
        $service = app(AccountingReportService::class);

        $all = $service->buildReportData([$this->location->id], Carbon::parse(self::TUESDAY), Carbon::parse(self::TUESDAY), 'visit_date');
        $parties = $service->buildReportData([$this->location->id], Carbon::parse(self::TUESDAY), Carbon::parse(self::TUESDAY), 'visit_date', ['category_filter' => 'party']);

        $this->assertSame(15.0, (float) $all['summary']['discount_by_source']['special_pricing']);
        $this->assertSame(0.0, (float) $parties['summary']['discount_by_source']['special_pricing']);
    }

    public function test_the_backfill_turns_old_per_ticket_cart_entries_into_line_totals(): void
    {
        $order = TicketOrder::create([
            'reference_number' => 'ORD20261109BACKFL',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => self::MONDAY,
            'item_count' => 1,
            'ticket_count' => 3,
            'subtotal' => 30,
            'discount_amount' => 15,
            'total_amount' => 15.73,
            'amount_paid' => 15.73,
            'payment_method' => 'in-store',
            'status' => 'confirmed',
            'applied_discounts' => [
                ['name' => 'Tuesday Special', 'discount_type' => 'percentage', 'discount_label' => '50.00%', 'discount_amount' => 5, 'special_pricing_id' => $this->halfOffTuesdays->id, 'line_position' => 1],
            ],
        ]);
        $line = AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'ticket_order_id' => $order->id,
            'line_position' => 1,
            'guest_name' => 'Cart Shopper',
            'quantity' => 3,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 15.73,
            'amount_paid' => 15.73,
            'discount_amount' => 15,
            'applied_discounts' => [
                ['name' => 'Tuesday Special', 'discount_type' => 'percentage', 'discount_label' => '50.00%', 'discount_amount' => 5, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);
        $mismatched = AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'ticket_order_id' => $order->id,
            'line_position' => 2,
            'guest_name' => 'Cart Shopper',
            'quantity' => 2,
            'purchase_date' => self::MONDAY,
            'total_amount' => 10,
            'discount_amount' => 7,
            'applied_discounts' => [
                ['name' => 'Tuesday Special', 'discount_type' => 'percentage', 'discount_amount' => 5, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);

        $migration = require database_path('migrations/2026_11_03_120000_backfill_order_line_special_pricing_amounts.php');
        $migration->up();
        $migration->up();

        $entry = $line->fresh()->applied_discounts[0];
        $this->assertSame(15, (int) round($entry['discount_amount']));
        $this->assertSame(5, (int) $entry['unit_discount_amount']);
        $this->assertSame(3, $entry['quantity']);
        $this->assertSame('special_pricing', $entry['source']);

        $this->assertSame(15, (int) round($order->fresh()->applied_discounts[0]['discount_amount']));
        $this->assertSame(5, (int) $mismatched->fresh()->applied_discounts[0]['discount_amount']);
        $this->assertArrayNotHasKey('unit_discount_amount', $mismatched->fresh()->applied_discounts[0]);
    }

    public function test_the_deals_endpoint_lists_the_special_and_only_its_days(): void
    {
        $response = $this->getJson('/api/special-pricings/deals?' . http_build_query([
            'entity_type' => 'attraction',
            'entity_id' => $this->laserTag->id,
            'location_id' => $this->location->id,
            'days' => 21,
        ]))->assertOk();

        $deals = $response->json('data.deals');
        $this->assertCount(1, $deals);
        $this->assertSame('Tuesday Special', $deals[0]['name']);
        $this->assertSame('50%', $deals[0]['discount_label']);
        $this->assertSame('Every Tuesday', $deals[0]['recurrence_display']);
        $this->assertSame(self::TUESDAY, $deals[0]['next_dates'][0]);
        $this->assertSame([self::TUESDAY, '2026-11-17', '2026-11-24'], array_keys($response->json('data.dates')));
    }

    public function test_the_price_breakdown_endpoint_scales_by_ticket_count(): void
    {
        $breakdown = $this->getJson('/api/special-pricings/for-entity?' . http_build_query([
            'entity_type' => 'attraction',
            'entity_id' => $this->wristband->id,
            'base_price' => 32.99,
            'quantity' => 3,
            'date' => self::TUESDAY,
            'time' => '14:00:00',
            'location_id' => $this->location->id,
        ]))->assertOk()->json('data');

        $this->assertSame(98.97, $breakdown['original_price']);
        $this->assertEquals(39, $breakdown['total_discount']);
        $this->assertSame(59.97, $breakdown['discounted_price']);
        $this->assertEquals(39, $breakdown['discounts_applied'][0]['discount_amount']);
        $this->assertEquals(13, $breakdown['discounts_applied'][0]['unit_discount_amount']);
    }

    public function test_the_storefront_shows_the_deal_every_day_and_names_where_it_applies(): void
    {
        $this->makeAttraction($this->otherLocation, 'Laser Tag', 10.00);

        $grouped = collect($this->getJson('/api/attractions/grouped')->assertOk()->json('data'))->firstWhere('name', 'Laser Tag');

        $this->assertSame(['Tuesday Special'], array_column($grouped['special_deals'], 'name'));
        $this->assertSame(['Brighton'], $grouped['special_deals'][0]['location_names']);
        $this->assertFalse($grouped['special_deals'][0]['applies_at_every_location']);

        $byLocation = collect($grouped['locations'])->keyBy('location_name');
        $this->assertCount(1, $byLocation['Brighton']['special_deals']);
        $this->assertCount(0, $byLocation['Ypsilanti']['special_deals']);
        $this->assertFalse($byLocation['Brighton']['special_pricing']['has_special_pricing']);
    }

    public function test_the_audit_lists_purchases_priced_for_the_wrong_day(): void
    {
        AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Wrong Day',
            'quantity' => 3,
            'purchase_date' => self::TUESDAY,
            'scheduled_date' => '2026-11-12',
            'scheduled_time' => '14:00',
            'total_amount' => 15,
            'discount_amount' => 15,
            'applied_discounts' => [['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id]],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);
        AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Missed',
            'quantity' => 2,
            'purchase_date' => self::WEDNESDAY,
            'scheduled_date' => '2026-11-17',
            'scheduled_time' => '14:00',
            'total_amount' => 20,
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);
        AttractionPurchase::create([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Correct',
            'quantity' => 1,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 5,
            'discount_amount' => 5,
            'applied_discounts' => [['discount_name' => 'Tuesday Special', 'discount_amount' => 5, 'discount_type' => 'percentage', 'original_price' => 10, 'special_pricing_id' => $this->halfOffTuesdays->id]],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);

        $before = $this->purchaseSnapshot();

        $this->artisan('special-pricing:audit')
            ->expectsOutputToContain('special given, visit is not a special day: 1')
            ->expectsOutputToContain('special missed on a special visit day: 1')
            ->doesntExpectOutputToContain('special amount differs')
            ->assertSuccessful();

        $this->assertSame($before, $this->purchaseSnapshot());
    }

    private function purchaseSnapshot(): array
    {
        return AttractionPurchase::query()
            ->orderBy('id')
            ->get(['id', 'total_amount', 'discount_amount', 'applied_discounts', 'updated_at'])
            ->map(fn (AttractionPurchase $purchase) => $purchase->getAttributes())
            ->all();
    }

    public function test_a_one_time_special_follows_the_visit_day_not_the_purchase_day(): void
    {
        $this->halfOffTuesdays->update(['is_active' => false]);
        $this->makeRule([
            'name' => 'Grand Opening',
            'recurrence_type' => SpecialPricing::RECURRENCE_ONE_TIME,
            'recurrence_value' => null,
            'specific_date' => '2026-11-14',
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 2,
            'entity_ids' => [$this->laserTag->id],
        ]);

        $onTheDay = $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-14', 'scheduled_time' => '12:00']);
        $this->setMichiganNow('2026-11-14 12:00:00');
        $boughtOnTheDayForLater = $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-15', 'scheduled_time' => '12:00']);

        $this->assertSame(6.0, $onTheDay['special_pricing_discount']);
        $this->assertSame(0.0, $boughtOnTheDayForLater['special_pricing_discount']);
    }

    public function test_a_monthly_special_follows_the_visit_day(): void
    {
        $this->halfOffTuesdays->update(['is_active' => false]);
        $this->makeRule([
            'name' => 'Fifteenth',
            'recurrence_type' => SpecialPricing::RECURRENCE_MONTHLY,
            'recurrence_value' => 15,
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 10,
            'entity_ids' => [$this->laserTag->id],
        ]);

        $this->assertSame(3.0, $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-15', 'scheduled_time' => '12:00'])['special_pricing_discount']);
        $this->assertSame(0.0, $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-16', 'scheduled_time' => '12:00'])['special_pricing_discount']);
    }

    public function test_the_last_visit_date_counts_visits_not_purchases(): void
    {
        $this->halfOffTuesdays->update(['end_date' => self::TUESDAY]);

        $lastTuesday = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '12:00']);
        $nextTuesday = $this->attractionQuote($this->laserTag, ['scheduled_date' => '2026-11-17', 'scheduled_time' => '12:00']);

        $this->assertSame(15.0, $lastTuesday['special_pricing_discount']);
        $this->assertSame(0.0, $nextTuesday['special_pricing_discount']);

        $deals = $this->getJson('/api/special-pricings/deals?' . http_build_query([
            'entity_type' => 'attraction', 'entity_id' => $this->laserTag->id, 'location_id' => $this->location->id, 'days' => 30,
        ]))->json('data');
        $this->assertSame([self::TUESDAY], array_keys($deals['dates']));
        $this->assertSame([self::TUESDAY], $deals['deals'][0]['next_dates']);
    }

    public function test_stacked_specials_apply_per_ticket_in_priority_order(): void
    {
        $this->halfOffTuesdays->update(['is_stackable' => true, 'priority' => 10]);
        $this->makeRule([
            'name' => 'Extra Dollar',
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 1,
            'is_stackable' => true,
            'priority' => 1,
            'entity_ids' => [$this->laserTag->id],
        ]);

        $quote = $this->attractionQuote($this->laserTag, ['scheduled_date' => self::TUESDAY, 'scheduled_time' => '12:00']);

        $this->assertSame(18.0, $quote['special_pricing_discount']);
        $this->assertSame(['Tuesday Special', 'Extra Dollar'], array_column($quote['special_pricing_applied'], 'name'));
        $this->assertSame(18.0, array_sum(array_column($quote['special_pricing_applied'], 'discount_amount')));
    }

    public function test_an_event_ticket_uses_the_event_day_and_counts_every_ticket(): void
    {
        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'date_range',
            'start_date' => self::MONDAY,
            'end_date' => '2026-11-30',
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
        $this->makeRule([
            'name' => 'Tuesday Glow',
            'entity_type' => SpecialPricing::ENTITY_EVENT,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 5,
            'entity_ids' => [$event->id],
        ]);

        $tuesday = app(CheckoutPricer::class)->forEventPurchase(['quantity' => 4, 'purchase_date' => self::TUESDAY, 'purchase_time' => '19:00'], $event, null);
        $wednesday = app(CheckoutPricer::class)->forEventPurchase(['quantity' => 4, 'purchase_date' => self::WEDNESDAY, 'purchase_time' => '19:00'], $event, null);

        $this->assertSame(20.0, $tuesday['special_pricing_discount']);
        $this->assertSame(0.0, $wednesday['special_pricing_discount']);
    }

    private function makeGlowNight(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'date_range',
            'start_date' => self::MONDAY,
            'end_date' => '2026-11-30',
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ], $overrides));
    }

    private function makeTuesdayGlow(Event $event): SpecialPricing
    {
        return $this->makeRule([
            'name' => 'Tuesday Glow',
            'entity_type' => SpecialPricing::ENTITY_EVENT,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 5,
            'entity_ids' => [$event->id],
        ]);
    }

    private function makeEventPurchase(Event $event, SpecialPricing $rule, array $overrides = []): EventPurchase
    {
        return EventPurchase::create(array_merge([
            'reference_number' => 'EV' . strtoupper(uniqid()),
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Glow Guest',
            'guest_email' => 'glow@example.com',
            'purchase_date' => self::TUESDAY,
            'purchase_time' => '19:00',
            'quantity' => 2,
            'unit_price' => 15,
            'total_amount' => 20,
            'amount_paid' => 20,
            'discount_amount' => 10,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Glow', 'discount_amount' => 10, 'discount_type' => 'fixed', 'original_price' => 30, 'special_pricing_id' => $rule->id, 'source' => 'special_pricing', 'unit_discount_amount' => 5, 'quantity' => 2],
            ],
            'payment_method' => 'in-store',
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ], $overrides));
    }

    private function tuesdayLaserTagPurchase(array $overrides = []): AttractionPurchase
    {
        return AttractionPurchase::create(array_merge([
            'attraction_id' => $this->laserTag->id,
            'guest_name' => 'Walk Up',
            'guest_email' => 'walkup@example.com',
            'quantity' => 3,
            'unit_price' => 10,
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'total_amount' => 15,
            'amount_paid' => 15,
            'discount_amount' => 15,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id, 'source' => 'special_pricing', 'unit_discount_amount' => 5, 'quantity' => 3],
            ],
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ], $overrides));
    }

    private function deals(string $type, int $id, int $days = 60): array
    {
        return $this->getJson('/api/special-pricings/deals?' . http_build_query([
            'entity_type' => $type,
            'entity_id' => $id,
            'days' => $days,
        ]))->assertOk()->json('data');
    }

    public function test_a_same_day_time_change_keeps_the_special_a_purchase_got_after_the_rule_is_switched_off(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase();
        $this->halfOffTuesdays->update(['is_active' => false]);

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '15:00',
        ])->assertOk();

        $moved = $purchase->fresh();
        $this->assertSame(15.0, (float) $moved->total_amount);
        $this->assertSame([$this->halfOffTuesdays->id], array_column($moved->applied_discounts, 'special_pricing_id'));
    }

    public function test_dropping_a_ticket_after_the_rule_is_switched_off_keeps_the_special_per_ticket(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase();
        $legacy = $this->tuesdayLaserTagPurchase(['applied_discounts' => [
            ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id],
        ]]);
        $this->halfOffTuesdays->update(['is_active' => false]);

        $quote = app(PurchaseSpecialRepricer::class)->forAttractionPurchase($purchase, ['quantity' => 2]);

        $this->assertTrue($quote['changed']);
        $this->assertSame(10.0, $quote['special_pricing_discount']);
        $this->assertSame(2, $quote['special_discounts'][0]['quantity']);
        $this->assertSame(10.0, app(PurchaseSpecialRepricer::class)->forAttractionPurchase($legacy, ['quantity' => 2])['special_pricing_discount']);
    }

    public function test_moving_to_a_day_with_a_different_special_of_the_same_amount_names_the_new_special(): void
    {
        $thursdays = $this->makeRule([
            'name' => 'Thursday Special',
            'recurrence_value' => SpecialPricing::THURSDAY,
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 50,
            'entity_ids' => [$this->laserTag->id],
        ]);
        $purchase = $this->tuesdayLaserTagPurchase();

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'scheduled_date' => '2026-11-12',
            'scheduled_time' => '14:00',
        ])->assertOk();

        $moved = $purchase->fresh();
        $this->assertSame(15.0, (float) $moved->total_amount);
        $this->assertSame([$thursdays->id], array_column($moved->applied_discounts, 'special_pricing_id'));
        $this->assertSame('Thursday Special', $moved->applied_discounts[0]['discount_name']);
    }

    public function test_a_booking_moved_to_a_day_with_a_different_special_of_the_same_amount_names_the_new_special(): void
    {
        $package = $this->makeFlatParty();
        $tuesdays = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $thursdays = $this->makeRule([
            'name' => 'Thursday Parties',
            'recurrence_value' => SpecialPricing::THURSDAY,
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => $tuesdays->id],
            ],
        ]);

        $quote = app(BookingRepricer::class)->quote($booking, ['booking_date' => '2026-11-12']);

        $this->assertSame(150.0, $quote['total_amount']);
        $this->assertSame([$thursdays->id], array_column($quote['applied_discounts'], 'special_pricing_id'));
    }

    public function test_the_edit_screens_ask_the_server_what_the_special_becomes(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase();

        $wednesday = $this->asManager()->postJson("/api/attraction-purchases/{$purchase->id}/special-quote", [
            'scheduled_date' => self::WEDNESDAY,
            'scheduled_time' => '14:00',
        ])->assertOk()->json('data');
        $this->assertTrue($wednesday['changed']);
        $this->assertEquals(0, $wednesday['special_pricing_discount']);
        $this->assertEquals(15, $wednesday['given_special']);
        $this->assertSame([], $wednesday['special_discounts']);

        $swapped = $this->asManager()->postJson("/api/attraction-purchases/{$purchase->id}/special-quote", [
            'attraction_id' => $this->wristband->id,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
        ])->assertOk()->json('data');
        $this->assertEquals(39, $swapped['special_pricing_discount']);
        $this->assertSame([$this->wristbandTuesdays->id], array_column($swapped['special_discounts'], 'special_pricing_id'));

        $event = $this->makeGlowNight();
        $ticket = $this->makeEventPurchase($event, $this->makeTuesdayGlow($event));
        $eventQuote = $this->asManager()->postJson("/api/event-purchases/{$ticket->id}/special-quote", [
            'quantity' => 3,
        ])->assertOk()->json('data');
        $this->assertEquals(15, $eventQuote['special_pricing_discount']);
        $this->assertSame(3, $eventQuote['special_discounts'][0]['quantity']);
    }

    public function test_switching_the_attraction_stores_the_new_ticket_price(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase();

        $this->asManager()->putJson("/api/attraction-purchases/{$purchase->id}", [
            'attraction_id' => $this->wristband->id,
            'quantity' => 3,
            'total_amount' => 59.97,
            'discount_amount' => 39,
            'applied_discounts' => [
                ['discount_name' => 'Wristband Tuesday', 'discount_amount' => 39, 'discount_type' => 'fixed', 'original_price' => 98.97, 'special_pricing_id' => $this->wristbandTuesdays->id, 'source' => 'special_pricing', 'unit_discount_amount' => 13, 'quantity' => 3],
            ],
        ])->assertOk();

        $this->assertSame(32.99, (float) $purchase->fresh()->unit_price);
    }

    public function test_checkout_keeps_the_per_ticket_details_of_the_special(): void
    {
        $response = $this->asManager()->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->wristband->id,
            'guest_name' => 'Walk In',
            'guest_email' => 'walkin@example.com',
            'quantity' => 3,
            'total_amount' => 59.97,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'purchase_date' => self::MONDAY,
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
            'discount_amount' => 39,
            'applied_discounts' => [
                ['discount_name' => 'Wristband Tuesday', 'discount_amount' => 39, 'discount_type' => 'fixed', 'original_price' => 98.97, 'special_pricing_id' => $this->wristbandTuesdays->id, 'source' => 'special_pricing', 'discount_label' => '$13.00', 'unit_discount_amount' => 13, 'quantity' => 3],
            ],
        ])->assertCreated();

        $entry = AttractionPurchase::findOrFail($response->json('data.id'))->applied_discounts[0];
        $this->assertEquals(13, $entry['unit_discount_amount']);
        $this->assertSame(3, (int) $entry['quantity']);
        $this->assertSame('$13.00', $entry['discount_label']);
    }

    public function test_moving_an_event_ticket_off_the_special_day_marks_the_balance_owed(): void
    {
        $event = $this->makeGlowNight();
        $rule = $this->makeTuesdayGlow($event);
        $edited = $this->makeEventPurchase($event, $rule);
        $bare = $this->makeEventPurchase($event, $rule);

        $this->asManager()->putJson("/api/event-purchases/{$edited->id}", [
            'purchase_date' => self::WEDNESDAY,
            'purchase_time' => '19:00',
            'payment_status' => 'paid',
            'amount_paid' => 20,
            'total_amount' => 30,
            'discount_amount' => 0,
            'applied_discounts' => null,
        ])->assertOk();

        $this->asManager()->putJson("/api/event-purchases/{$bare->id}", [
            'purchase_date' => self::WEDNESDAY,
            'purchase_time' => '19:00',
        ])->assertOk();

        $this->assertSame('partial', $edited->fresh()->payment_status);
        $this->assertSame(30.0, (float) $bare->fresh()->total_amount);
        $this->assertSame('partial', $bare->fresh()->payment_status);
    }

    public function test_the_price_check_without_a_location_uses_the_items_own_venue(): void
    {
        $this->makeRule([
            'name' => 'Ypsilanti Everything',
            'location_id' => $this->otherLocation->id,
            'entity_type' => SpecialPricing::ENTITY_ALL,
            'entity_ids' => [],
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 90,
            'priority' => 5,
        ]);

        $breakdown = $this->getJson('/api/special-pricings/for-entity?' . http_build_query([
            'entity_type' => 'attraction',
            'entity_id' => $this->laserTag->id,
            'base_price' => 10,
            'quantity' => 3,
            'date' => self::TUESDAY,
            'time' => '14:00',
        ]))->assertOk()->json('data');

        $this->assertSame(['Tuesday Special'], array_column($breakdown['discounts_applied'], 'name'));
        $this->assertEquals(15, $breakdown['total_discount']);
    }

    public function test_a_special_that_starts_later_is_advertised_from_its_first_visit_date(): void
    {
        $this->halfOffTuesdays->update(['start_date' => '2026-12-01']);

        $deals = $this->deals('attraction', $this->laserTag->id);
        $this->assertSame('2026-12-01', $deals['deals'][0]['next_dates'][0]);
        $this->assertSame('2026-12-01', array_key_first($deals['dates']));

        $upcoming = $this->getJson('/api/special-pricings/upcoming-dates?days=60')->assertOk()->json('data.dates');
        $this->assertContains($this->halfOffTuesdays->id, array_column($upcoming['2026-12-01'], 'id'));
        $this->assertNotContains($this->halfOffTuesdays->id, array_column($upcoming[self::TUESDAY], 'id'));
    }

    public function test_a_one_time_special_outside_its_own_date_window_is_not_advertised(): void
    {
        $rule = $this->makeRule([
            'name' => 'Christmas Eve',
            'recurrence_type' => SpecialPricing::RECURRENCE_ONE_TIME,
            'recurrence_value' => null,
            'specific_date' => '2026-12-24',
            'end_date' => '2026-11-30',
            'entity_ids' => [$this->laserTag->id],
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 2,
            'is_stackable' => true,
        ]);

        $this->assertNotContains($rule->id, array_column($this->deals('attraction', $this->laserTag->id)['deals'], 'special_pricing_id'));
        $this->assertSame([], $rule->fresh()->getUpcomingDates(3));
    }

    public function test_an_event_only_shows_a_deal_it_can_actually_get(): void
    {
        $show = $this->makeGlowNight([
            'name' => 'Saturday Show',
            'date_type' => 'one_time',
            'start_date' => '2026-11-14',
            'end_date' => '2026-11-14',
        ]);
        $this->makeRule([
            'name' => 'Tuesday Everything',
            'entity_type' => SpecialPricing::ENTITY_ALL,
            'entity_ids' => [],
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 3,
        ]);

        $deals = $this->deals('event', $show->id);
        $this->assertSame([], $deals['deals']);
        $this->assertSame([], $deals['dates']);

        $grouped = collect($this->getJson('/api/events/grouped-by-name')->assertOk()->json('data'))->firstWhere('name', 'Saturday Show');
        $this->assertSame([], $grouped['special_deals']);
    }

    public function test_two_specials_that_cannot_combine_only_advertise_the_one_that_applies(): void
    {
        $this->halfOffTuesdays->update(['priority' => 10]);
        $this->makeRule([
            'name' => 'Tuesday Dollar',
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 1,
            'entity_ids' => [$this->laserTag->id],
        ]);

        $deals = $this->deals('attraction', $this->laserTag->id, 21);

        $this->assertSame(['Tuesday Special'], array_column($deals['deals'], 'name'));
        $this->assertSame(['Tuesday Special'], array_column($deals['dates'][self::TUESDAY], 'name'));
    }

    public function test_a_rule_cannot_be_saved_with_its_visit_times_reversed_and_one_time_rules_drop_their_window(): void
    {
        $this->asManager()->putJson("/api/special-pricings/{$this->halfOffTuesdays->id}", [
            'time_start' => '21:00',
            'time_end' => '01:00',
        ])->assertStatus(422);

        $this->asManager()->putJson("/api/special-pricings/{$this->halfOffTuesdays->id}", [
            'recurrence_type' => 'one_time',
            'specific_date' => '2026-12-24',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
        ])->assertOk();

        $rule = $this->halfOffTuesdays->fresh();
        $this->assertNull($rule->start_date);
        $this->assertNull($rule->end_date);
        $this->assertSame(['2026-12-24'], $rule->getUpcomingDates(3));
    }

    public function test_cart_lines_name_their_special(): void
    {
        $cart = app(TicketOrderPricer::class)->priceCart([
            ['type' => 'attraction', 'id' => $this->laserTag->id, 'quantity' => 3, 'scheduled_date' => self::TUESDAY, 'scheduled_time' => '14:00'],
        ]);

        $this->assertSame('Tuesday Special', $cart['lines'][0]['applied_discounts'][0]['discount_name']);
    }

    public function test_the_purchase_email_shows_the_visit_day_and_the_special(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase(['unit_price' => null]);
        $method = new \ReflectionMethod(EmailNotificationService::class, 'buildPurchaseVariables');
        $variables = $method->invoke(app(EmailNotificationService::class), $purchase->fresh(['attraction']), false);

        $this->assertSame('Tuesday, November 10, 2026', $variables['purchase_visit_date']);
        $this->assertSame('2:00 PM', $variables['purchase_visit_time']);
        $this->assertSame('-$15.00 (Tuesday Special)', $variables['purchase_discount']);
        $this->assertSame('$10.00', $variables['purchase_unit_price']);
        $this->assertSame('November 9, 2026', $variables['purchase_date']);
    }

    public function test_the_attraction_email_templates_switch_to_the_visit_date_only_where_untouched(): void
    {
        $dateRow = "<strong style=\"color: #6b7280;\">Date:</strong>\n                    <span style=\"color: #111827; float: right;\">{{purchase_date}}</span>";
        $pristine = "<table>{$dateRow}</table>";
        $edited = "<p>Our own words</p>{$dateRow}";

        $make = function (string $key, string $body): int {
            DB::table('email_notifications')->updateOrInsert(
                ['company_id' => $this->company->id, 'default_key' => $key],
                [
                    'name' => $key,
                    'trigger_type' => 'purchase_reminder',
                    'entity_type' => 'attraction',
                    'recipient_types' => json_encode(['customer']),
                    'body' => $body,
                    'default_body' => "<table>" . "<strong style=\"color: #6b7280;\">Date:</strong>\n                    <span style=\"color: #111827; float: right;\">{{purchase_date}}</span>" . "</table>",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return (int) DB::table('email_notifications')->where('company_id', $this->company->id)->where('default_key', $key)->value('id');
        };
        $untouched = $make('purchase_reminder_customer', $pristine);
        $customised = $make('purchase_reschedule_customer', $edited);

        (require database_path('migrations/2026_11_03_120100_show_visit_date_and_discount_in_attraction_emails.php'))->up();

        $this->assertStringContainsString('{{purchase_visit_date}}', DB::table('email_notifications')->where('id', $untouched)->value('body'));
        $this->assertSame($edited, DB::table('email_notifications')->where('id', $customised)->value('body'));
        $this->assertStringContainsString('{{purchase_visit_date}}', DB::table('email_notifications')->where('id', $customised)->value('default_body'));
    }

    public function test_the_audit_does_not_blame_a_purchase_for_a_rule_edited_after_it(): void
    {
        $this->tuesdayLaserTagPurchase();
        $this->setMichiganNow(self::TUESDAY . ' 09:00:00');
        $this->halfOffTuesdays->update(['discount_amount' => 40]);

        $this->artisan('special-pricing:audit')
            ->expectsOutputToContain('rule changed since purchase, check by hand: 1')
            ->doesntExpectOutputToContain('special amount differs')
            ->assertSuccessful();
    }

    public function test_a_rule_added_after_a_purchase_does_not_hide_the_special_it_got(): void
    {
        $this->tuesdayLaserTagPurchase();
        $this->setMichiganNow(self::WEDNESDAY . ' 09:00:00');
        $this->makeRule([
            'name' => 'Tuesday Dollar',
            'priority' => 5,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 1,
            'entity_ids' => [$this->laserTag->id],
        ]);

        $this->artisan('special-pricing:audit')
            ->expectsOutputToContain('Every purchase matches the special for its visit date.')
            ->assertSuccessful();
    }

    public function test_the_audit_counts_every_ticket_and_checks_events_and_parties_too(): void
    {
        $this->tuesdayLaserTagPurchase([
            'total_amount' => 25,
            'discount_amount' => 5,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 5, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
        ]);
        $event = $this->makeGlowNight();
        $this->makeEventPurchase($event, $this->makeTuesdayGlow($event), ['purchase_date' => self::WEDNESDAY]);
        $package = $this->makeFlatParty();
        $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $this->makeBookingOn($package, ['total_amount' => 200]);
        $before = $this->purchaseSnapshot();

        $this->artisan('special-pricing:audit')
            ->expectsOutputToContain('special amount differs: 1')
            ->expectsOutputToContain('special given, visit is not a special day: 1')
            ->expectsOutputToContain('special missed on a special visit day: 1')
            ->assertSuccessful();

        $this->assertSame($before, $this->purchaseSnapshot());
    }

    public function test_the_audit_reads_unconverted_cart_entries_per_ticket(): void
    {
        $order = TicketOrder::create([
            'reference_number' => 'ORD20261109AUDITC',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => self::MONDAY,
            'item_count' => 1,
            'ticket_count' => 3,
            'subtotal' => 30,
            'discount_amount' => 15,
            'total_amount' => 15,
            'amount_paid' => 15,
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ]);
        $this->tuesdayLaserTagPurchase([
            'ticket_order_id' => $order->id,
            'line_position' => 1,
            'applied_discounts' => [
                ['name' => 'Tuesday Special', 'discount_type' => 'percentage', 'discount_amount' => 5, 'special_pricing_id' => $this->halfOffTuesdays->id],
            ],
        ]);

        $this->artisan('special-pricing:audit')
            ->expectsOutputToContain('Every purchase matches the special for its visit date.')
            ->assertSuccessful();
    }

    public function test_the_backfill_finishes_an_order_whose_lines_were_converted_in_an_earlier_run(): void
    {
        $event = $this->makeGlowNight();
        $glow = $this->makeTuesdayGlow($event);
        $order = TicketOrder::create([
            'reference_number' => 'ORD20261109RERUNS',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => self::MONDAY,
            'item_count' => 2,
            'ticket_count' => 5,
            'subtotal' => 60,
            'discount_amount' => 25,
            'total_amount' => 35,
            'amount_paid' => 35,
            'payment_method' => 'in-store',
            'status' => 'confirmed',
            'applied_discounts' => [
                ['name' => 'Tuesday Special', 'discount_type' => 'percentage', 'discount_amount' => 5, 'special_pricing_id' => $this->halfOffTuesdays->id, 'line_position' => 1],
                ['name' => 'Tuesday Glow', 'discount_type' => 'fixed', 'discount_amount' => 5, 'special_pricing_id' => $glow->id, 'line_position' => 2],
            ],
        ]);
        $this->tuesdayLaserTagPurchase(['ticket_order_id' => $order->id, 'line_position' => 1]);
        $eventLine = $this->makeEventPurchase($event, $glow, [
            'ticket_order_id' => $order->id,
            'line_position' => 2,
            'applied_discounts' => [
                ['name' => 'Tuesday Glow', 'discount_type' => 'fixed', 'discount_amount' => 5, 'special_pricing_id' => $glow->id],
            ],
        ]);

        $this->artisan('special-pricing:backfill-cart-lines')
            ->expectsOutputToContain('Cart lines converted: 1. Orders converted: 1.')
            ->assertSuccessful();

        $this->assertEquals([15, 10], array_column($order->fresh()->applied_discounts, 'discount_amount'));
        $this->assertEquals(10, $eventLine->fresh()->applied_discounts[0]['discount_amount']);

        $this->artisan('special-pricing:backfill-cart-lines')
            ->expectsOutputToContain('Cart lines converted: 0. Orders converted: 0.')
            ->assertSuccessful();
    }

    public function test_a_booking_save_with_its_own_total_keeps_its_discount_lines(): void
    {
        $package = $this->makeFlatParty();
        $rule = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => $rule->id],
            ],
        ]);

        $this->asManager()->putJson("/api/bookings/{$booking->id}", [
            'booking_date' => self::WEDNESDAY,
            'change_reason' => 'Guest asked',
            'total_amount' => 180,
        ])->assertOk();

        $saved = $booking->fresh();
        $this->assertSame(180.0, (float) $saved->total_amount);
        $this->assertSame([$rule->id], array_column($saved->applied_discounts, 'special_pricing_id'));
    }

    public function test_moving_a_party_into_the_evening_window_through_the_api_applies_the_special(): void
    {
        $package = $this->makeFlatParty();
        $rule = $this->makeRule([
            'name' => 'Tuesday Evenings',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
            'time_start' => '16:00',
            'time_end' => '21:00',
        ]);
        $booking = $this->makeBookingOn($package, ['total_amount' => 200]);

        $quote = $this->asManager()->postJson("/api/bookings/{$booking->id}/reprice", ['booking_time' => '17:00'])
            ->assertOk()->json('data');
        $this->assertEquals(150, $quote['total_amount']);
        $this->assertSame([$rule->id], array_column($quote['applied_discounts'], 'special_pricing_id'));

        $this->asManager()->putJson("/api/bookings/{$booking->id}", [
            'booking_time' => '17:00',
            'change_reason' => 'Guest asked',
        ])->assertOk();

        $this->assertSame(150.0, (float) $booking->fresh()->total_amount);
    }

    public function test_switching_a_party_to_a_per_person_package_never_multiplies_the_special(): void
    {
        $flat = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Flat Party',
            'description' => 'Flat',
            'category' => 'party',
            'price' => 250.00,
            'pricing_type' => 'base',
            'min_participants' => 10,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $perPerson = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Per Person Party',
            'description' => 'Per person',
            'category' => 'party',
            'price' => 25.00,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $rule = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'entity_ids' => [],
            'discount_type' => SpecialPricing::DISCOUNT_PERCENTAGE,
            'discount_amount' => 20,
        ]);
        $booking = $this->makeBookingOn($flat, [
            'participants' => 10,
            'total_amount' => 200,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'percentage', 'original_price' => 250, 'special_pricing_id' => $rule->id, 'source' => 'special_pricing', 'unit_discount_amount' => 50, 'quantity' => 1],
            ],
        ]);

        $swap = app(BookingRepricer::class)->quote($booking, ['package_id' => $perPerson->id]);
        $this->assertEquals(50, $swap['special_pricing_discount']);
        $this->assertSame(200.0, $swap['total_amount']);

        $rule->update(['is_active' => false]);
        $paused = app(BookingRepricer::class)->quote($booking, ['package_id' => $perPerson->id]);
        $this->assertEquals(0, $paused['special_pricing_discount']);
        $this->assertSame(250.0, $paused['total_amount']);
    }

    public function test_switching_attraction_and_ticket_count_uses_the_new_attractions_special(): void
    {
        $arcade = $this->makeAttraction($this->location, 'Arcade Pass', 15.00);
        $this->halfOffTuesdays->update(['entity_ids' => [$this->laserTag->id, $arcade->id]]);
        $purchase = $this->tuesdayLaserTagPurchase();

        $quote = app(PurchaseSpecialRepricer::class)->forAttractionPurchase($purchase, ['attraction_id' => $arcade->id, 'quantity' => 2]);

        $this->assertSame(15.0, $quote['special_pricing_discount']);
        $this->assertEquals(7.5, $quote['special_discounts'][0]['unit_discount_amount']);
        $this->assertSame(2, $quote['special_discounts'][0]['quantity']);

        $this->halfOffTuesdays->update(['is_active' => false]);
        $kept = app(PurchaseSpecialRepricer::class)->forAttractionPurchase(
            $this->tuesdayLaserTagPurchase(['applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 40, 'discount_type' => 'fixed', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id, 'source' => 'special_pricing', 'unit_discount_amount' => 40, 'quantity' => 1],
            ]]),
            ['quantity' => 1]
        );
        $this->assertSame(10.0, $kept['special_pricing_discount']);
    }

    public function test_an_older_purchase_gets_its_special_from_the_price_the_guest_paid(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase([
            'unit_price' => null,
            'scheduled_date' => self::WEDNESDAY,
            'total_amount' => 30,
            'amount_paid' => 30,
            'discount_amount' => 0,
            'applied_discounts' => [],
        ]);
        $this->laserTag->update(['price' => 12]);

        $quote = $this->asManager()->postJson("/api/attraction-purchases/{$purchase->id}/special-quote", [
            'scheduled_date' => self::TUESDAY,
            'scheduled_time' => '14:00',
        ])->assertOk()->json('data');

        $this->assertEquals(15, $quote['special_pricing_discount']);
        $this->assertEquals(15, $quote['total_amount']);
    }

    public function test_a_refunded_event_ticket_stays_refunded_when_its_total_changes(): void
    {
        $event = $this->makeGlowNight();
        $ticket = $this->makeEventPurchase($event, $this->makeTuesdayGlow($event), ['payment_status' => 'refunded']);

        $this->asManager()->putJson("/api/event-purchases/{$ticket->id}", [
            'purchase_date' => self::WEDNESDAY,
            'purchase_time' => '19:00',
            'payment_status' => 'paid',
            'total_amount' => 30,
            'discount_amount' => 0,
            'applied_discounts' => null,
        ])->assertOk();

        $this->assertSame('refunded', $ticket->fresh()->payment_status);
    }

    public function test_a_ticket_for_a_deleted_event_can_still_be_rescheduled(): void
    {
        $event = $this->makeGlowNight();
        $ticket = $this->makeEventPurchase($event, $this->makeTuesdayGlow($event));
        $event->delete();

        $this->asManager()->postJson("/api/event-purchases/{$ticket->id}/special-quote", ['purchase_date' => self::WEDNESDAY])
            ->assertOk()
            ->assertJsonPath('data.special_pricing_discount', 0);
    }

    public function test_purchase_emails_never_print_a_gift_card_code(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase([
            'total_amount' => 0,
            'discount_amount' => 30,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Special', 'discount_amount' => 15, 'discount_type' => 'percentage', 'original_price' => 30, 'special_pricing_id' => $this->halfOffTuesdays->id, 'source' => 'special_pricing'],
                ['discount_name' => 'Gift Card GCABCD2345EFGH', 'discount_amount' => 15, 'discount_type' => 'fixed', 'original_price' => 30, 'special_pricing_id' => null, 'source' => 'gift_card', 'gift_card_id' => 3],
            ],
        ]);

        $text = app(EmailNotificationService::class)->purchaseDiscountText($purchase);

        $this->assertSame('-$30.00 (Tuesday Special, Gift card)', $text);
        $this->assertStringNotContainsString('GCABCD', $text);
    }

    public function test_the_check_in_lookup_shows_what_was_paid_and_the_visit_day(): void
    {
        $purchase = $this->tuesdayLaserTagPurchase(['amount_paid' => 10]);

        $this->asManager()->getJson("/api/attraction-purchases/{$purchase->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.amount_paid', '10.00')
            ->assertJsonPath('data.scheduled_time', '14:00');
    }

    public function test_an_api_save_whose_total_matches_the_server_also_saves_the_discount(): void
    {
        $package = $this->makeFlatParty();
        $rule = $this->makeRule([
            'name' => 'Tuesday Parties',
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'discount_amount' => 50,
            'entity_ids' => [$package->id],
        ]);
        $booking = $this->makeBookingOn($package, [
            'total_amount' => 150,
            'discount_amount' => 50,
            'applied_discounts' => [
                ['discount_name' => 'Tuesday Parties', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 200, 'special_pricing_id' => $rule->id],
            ],
        ]);

        $this->asManager()->putJson("/api/bookings/{$booking->id}", [
            'booking_date' => self::WEDNESDAY,
            'change_reason' => 'Guest asked',
            'total_amount' => 200,
        ])->assertOk();

        $saved = $booking->fresh();
        $this->assertSame([], $saved->applied_discounts);
        $this->assertSame(0.0, (float) $saved->discount_amount);
    }

    public function test_a_server_running_in_utc_still_prices_by_the_michigan_day(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        $this->setMichiganNow(self::MONDAY . ' 22:30:00');
        $walkIn = app(CheckoutPricer::class)->forAttractionPurchase(['quantity' => 3], $this->laserTag, null);
        $this->assertSame(0.0, $walkIn['special_pricing_discount']);

        $this->setMichiganNow(self::TUESDAY . ' 21:30:00');
        $this->assertSame(self::TUESDAY, array_key_first($this->deals('attraction', $this->laserTag->id, 14)['dates']));
    }
}
