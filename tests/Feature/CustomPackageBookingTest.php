<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomPackageBookingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $canton;

    private Room $brightonRoom;

    private Room $brightonHall;

    private Room $cantonRoom;

    private User $admin;

    private User $manager;

    private User $attendant;

    private AddOn $pizza;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
            'booking_rules.slot_conflict' => 'enforce',
            'booking_rules.package_required' => 'log',
            'checkout.enforce_charge_within_due' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->setTime(12, 0));

        $this->company = Company::create([
            'company_name' => 'Custom Package Co',
            'email' => 'owner@custompackage.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->brighton = $this->makeLocation('Brighton');
        $this->canton = $this->makeLocation('Canton');

        $this->brightonRoom = Room::create(['location_id' => $this->brighton->id, 'name' => 'Party Room 1', 'capacity' => 30, 'is_available' => true, 'booking_interval' => 15]);
        $this->brightonHall = Room::create(['location_id' => $this->brighton->id, 'name' => 'Event Hall', 'capacity' => 60, 'is_available' => true, 'booking_interval' => 15]);
        $this->cantonRoom = Room::create(['location_id' => $this->canton->id, 'name' => 'Canton Room', 'capacity' => 30, 'is_available' => true, 'booking_interval' => 15]);

        Category::create(['name' => 'Corporate Event']);
        Category::create(['name' => 'Birthday']);

        $this->pizza = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Pizza', 'price' => 7.50, 'is_active' => true]);

        $this->admin = $this->staff('company_admin', $this->brighton);
        $this->manager = $this->staff('location_manager', $this->brighton);
        $this->attendant = $this->staff('attendant', $this->brighton);

        $this->date = now()->addDays(5)->toDateString();
    }

    private function makeLocation(string $city): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $city,
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($city) . '@custompackage.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function staff(string $role, Location $location): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@custompackage.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'status' => 'active',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat.' . uniqid() . '@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
    }

    private function freshRequest(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function as(User|Customer $account): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $account->createToken('test')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function asGuest(): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Team Outing',
            'category' => 'Custom: Work',
            'duration_minutes' => 90,
            'min_guests' => 8,
            'max_guests' => 16,
            'pricing_type' => 'per_guest',
            'price' => 20,
            'fees' => [
                ['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 4],
                ['name' => 'Setup Fee', 'type' => 'flat', 'amount' => 10],
            ],
            'one_time_add_ons' => [
                ['name' => 'Glow Bracelets', 'quantity' => 3, 'unit_price' => 5],
            ],
        ], $overrides);
    }

    private function payload(array $overrides = [], array $terms = []): array
    {
        return array_merge([
            'guest_name' => 'Jordan Planner',
            'guest_email' => 'jordan@example.com',
            'guest_phone' => '6165550000',
            'package_id' => null,
            'location_id' => $this->brighton->id,
            'room_id' => $this->brightonRoom->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 12,
            'duration' => 90,
            'duration_unit' => 'minutes',
            'total_amount' => 275.20,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'send_email' => false,
            'is_manual_entry' => true,
            'custom_package' => $this->terms($terms),
        ], $overrides);
    }

    private function createCustom(array $overrides = [], array $terms = [], ?User $as = null): Booking
    {
        $id = (int) $this->as($as ?? $this->admin)
            ->postJson('/api/bookings', $this->payload($overrides, $terms))
            ->assertCreated()
            ->json('data.id');

        return Booking::findOrFail($id);
    }

    private function rawColumn(Booking $booking, string $column): string
    {
        return (string) DB::table('bookings')->where('id', $booking->id)->value($column);
    }

    public function test_staff_create_stores_the_owners_example_with_server_fees_and_a_package_less_slot(): void
    {
        $response = $this->as($this->admin)->postJson('/api/bookings', $this->payload(['created_by' => $this->admin->id]))->assertCreated();

        $booking = Booking::findOrFail((int) $response->json('data.id'));

        $this->assertTrue($booking->is_custom_package);
        $this->assertNull($booking->package_id);
        $this->assertSame('Team Outing', $booking->custom_package_name);
        $this->assertSame('Custom: Work', $booking->custom_package_category);
        $this->assertSame('275.20', $booking->total_amount);
        $this->assertSame('0.00', $booking->discount_amount);
        $this->assertNull($booking->applied_discounts);
        $this->assertSame('20.00', $booking->package_price_at_booking);
        $this->assertSame('per_person', $booking->package_pricing_type_at_booking);
        $this->assertSame('0.00', $booking->price_per_additional_at_booking);
        $this->assertSame('90.00', $booking->duration);
        $this->assertSame('minutes', $booking->duration_unit);

        $this->assertEquals([
            [
                'fee_name' => 'Venue Fee',
                'fee_amount' => 10.2,
                'fee_application_type' => 'additive',
                'fee_calculation_type' => 'percentage',
                'fee_label' => '4%',
                'fee_support_id' => null,
            ],
            [
                'fee_name' => 'Setup Fee',
                'fee_amount' => 10.0,
                'fee_application_type' => 'additive',
                'fee_calculation_type' => 'fixed',
                'fee_label' => '$10.00',
                'fee_support_id' => null,
            ],
        ], $booking->applied_fees);

        $rawFees = json_decode($this->rawColumn($booking, 'applied_fees'), true);
        foreach ($rawFees as $fee) {
            $this->assertTrue(is_float($fee['fee_amount']) || is_int($fee['fee_amount']), 'fee_amount is stored as a JSON number');
        }
        $this->assertSame(10.2, $rawFees[0]['fee_amount']);
        $this->assertSame(
            ['fee_name', 'fee_amount', 'fee_application_type', 'fee_calculation_type', 'fee_label', 'fee_support_id'],
            array_keys($response->json('data.applied_fees.0'))
        );

        $rawTerms = json_decode($this->rawColumn($booking, 'custom_package_terms'), true);
        $this->assertSame(8, $rawTerms['min_guests']);
        $this->assertSame(16, $rawTerms['max_guests']);
        $this->assertEquals(['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 4], $rawTerms['fees'][0]);
        $this->assertEquals(['name' => 'Setup Fee', 'type' => 'flat', 'amount' => 10], $rawTerms['fees'][1]);
        $this->assertEquals(['name' => 'Glow Bracelets', 'quantity' => 3, 'unit_price' => 5], $rawTerms['one_time_add_ons'][0]);
        $this->assertIsInt($rawTerms['one_time_add_ons'][0]['quantity']);
        $this->assertTrue(is_int($rawTerms['fees'][0]['amount']) || is_float($rawTerms['fees'][0]['amount']));
        $this->assertDoesNotMatchRegularExpression('/"\d+(\.\d+)?"/', $this->rawColumn($booking, 'custom_package_terms'), 'no number is stored as a string');

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNull($slot->package_id);
        $this->assertSame($this->brightonRoom->id, (int) $slot->room_id);
        $this->assertSame('booked', $slot->status);
        $this->assertSame(90, $slot->getDurationInMinutes());

        $response->assertJsonPath('data.is_custom_package', true)
            ->assertJsonPath('data.custom_package_name', 'Team Outing')
            ->assertJsonPath('data.custom_package_category', 'Custom: Work')
            ->assertJsonPath('data.custom_package_terms.max_guests', 16)
            ->assertJsonPath('data.applied_fees.0.fee_amount', 10.2);

        $this->assertSame(
            [['name' => 'Glow Bracelets', 'quantity' => 3, 'unit_price' => 5.0, 'total' => 15.0]],
            $booking->oneTimeAddOns()
        );
        $this->assertSame('Team Outing', $booking->packageLabel());
        $this->assertSame('Custom: Work', $booking->packageCategoryLabel());
        $this->assertSame(['min' => 8, 'max' => 16], $booking->customGuestRange());
        $this->assertSame([
            ['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 4.0],
            ['name' => 'Setup Fee', 'type' => 'flat', 'amount' => 10.0],
        ], $booking->customFees());

        $log = ActivityLog::where('action', 'Booking Created')->where('entity_id', $booking->id)->firstOrFail();
        $this->assertEquals(['id' => null, 'name' => 'Team Outing', 'custom' => true], $log->metadata['package']);
        $this->assertSame('Custom: Work', $log->metadata['custom_package']['category']);
        $this->assertSame('per_guest', $log->metadata['custom_package']['pricing']);
        $this->assertEquals(20, $log->metadata['custom_package']['price']);
        $this->assertSame('Venue Fee', $log->metadata['custom_package']['fees'][0]['name']);
        $this->assertSame('Glow Bracelets', $log->metadata['custom_package']['one_time_add_ons'][0]['name']);
    }

    public function test_package_id_may_be_left_out_entirely(): void
    {
        $payload = $this->payload();
        unset($payload['package_id']);

        $id = (int) $this->as($this->admin)->postJson('/api/bookings', $payload)->assertCreated()->json('data.id');

        $this->assertNull(PackageTimeSlot::where('booking_id', $id)->value('package_id'));
        $this->assertSame(1, PackageTimeSlot::where('booking_id', $id)->count());
    }

    public function test_percentage_fees_round_half_up_on_the_package_and_add_ons_subtotal(): void
    {
        $booking = $this->createCustom(['total_amount' => 277.42], [
            'fees' => [
                ['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 4.87],
                ['name' => 'Setup Fee', 'type' => 'flat', 'amount' => 10],
            ],
        ]);

        $this->assertSame('277.42', $booking->total_amount);
        $this->assertSame(12.42, $booking->applied_fees[0]['fee_amount']);
        $this->assertSame('4.87%', $booking->applied_fees[0]['fee_label']);
    }

    public function test_per_booking_pricing_uses_the_price_once(): void
    {
        $booking = $this->createCustom(['total_amount' => 300], [
            'pricing_type' => 'per_booking',
            'price' => 300,
            'fees' => [],
            'one_time_add_ons' => [],
        ]);

        $this->assertSame('300.00', $booking->total_amount);
        $this->assertSame('base', $booking->package_pricing_type_at_booking);
        $this->assertNull($booking->applied_fees);
    }

    public function test_a_wrong_total_is_refused_with_the_expected_total_and_nothing_is_saved(): void
    {
        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['total_amount' => 275.00]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PRICE_MISMATCH')
            ->assertJsonPath('message', 'The total for this custom package changed. Please check the price and try again.')
            ->assertJsonPath('expected_total', 275.2);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, PackageTimeSlot::count());
    }

    public function test_a_one_cent_difference_is_accepted_and_the_server_total_is_stored(): void
    {
        $booking = $this->createCustom(['total_amount' => 275.21]);

        $this->assertSame('275.20', $booking->total_amount);
    }

    public function test_a_guest_or_a_customer_cannot_book_a_custom_package(): void
    {
        $this->asGuest()->postJson('/api/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PACKAGE_STAFF_ONLY')
            ->assertJsonPath('message', 'Custom packages can only be booked by staff. Please sign in again and try once more.');

        $this->as($this->customer())->postJson('/api/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PACKAGE_STAFF_ONLY');

        $this->freshRequest();
        $this->withHeaders(['Authorization' => 'Bearer 999|expired-token', 'Accept' => 'application/json'])
            ->postJson('/api/bookings', $this->payload(['guest_email' => null]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PACKAGE_STAFF_ONLY');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, PackageTimeSlot::count());
    }

    public function test_a_manager_or_attendant_cannot_book_another_location(): void
    {
        $elsewhere = $this->payload(['location_id' => $this->canton->id, 'room_id' => $this->cantonRoom->id]);

        $this->as($this->manager)->postJson('/api/bookings', $elsewhere)
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only book a custom package at your own location.');
        $this->as($this->attendant)->postJson('/api/bookings', $elsewhere)->assertForbidden();
        $this->assertSame(0, Booking::count());

        $this->createCustom([], [], $this->manager);
        $this->createCustom(['booking_time' => '17:00'], [], $this->attendant);
        $this->assertSame(2, Booking::where('location_id', $this->brighton->id)->count());

        $this->as($this->admin)->postJson('/api/bookings', array_merge($elsewhere, ['booking_time' => '10:00']))->assertCreated();
    }

    public function test_the_space_must_exist_at_the_booking_location(): void
    {
        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['room_id' => $this->cantonRoom->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Choose a space at this location.');

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['room_id' => null]))
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Choose a space at this location.');

        $this->assertSame(0, Booking::count());
    }

    public function test_participants_must_fit_the_custom_guest_range(): void
    {
        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['participants' => 17, 'total_amount' => 379.20]))
            ->assertStatus(422)
            ->assertJsonPath('errors.participants.0', 'Participants must be between 8 and 16.');

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['participants' => 7]))
            ->assertStatus(422)
            ->assertJsonPath('errors.participants.0', 'Participants must be between 8 and 16.');

        $this->as($this->admin)->postJson('/api/bookings', $this->payload([], ['min_guests' => 20, 'max_guests' => 16]))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['custom_package.max_guests' => ['Max. guests must be at least 20.']]);

        $this->assertSame(0, Booking::count());

        $booking = $this->createCustom(['participants' => 1, 'total_amount' => 46.40], ['min_guests' => '', 'max_guests' => '16']);
        $this->assertSame(['min' => 1, 'max' => 16], $booking->customGuestRange());
        $this->assertSame(1, json_decode($this->rawColumn($booking, 'custom_package_terms'), true)['min_guests']);
    }

    public function test_custom_terms_are_trimmed_validated_and_need_a_known_category(): void
    {
        $booking = $this->createCustom([], ['name' => '  Team Outing  ', 'category' => ' Custom: Work ', 'price' => '20.00']);
        $this->assertSame('Team Outing', $booking->custom_package_name);
        $this->assertSame('Custom: Work', $booking->custom_package_category);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], ['category' => 'Not A Category']))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['custom_package.category' => ['Choose a category from the list.']]);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], ['name' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_package.name']);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], ['price' => 20.005]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_package.price']);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], ['duration_minutes' => 1441]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_package.duration_minutes']);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], [
            'fees' => [['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 100.5]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors', ['custom_package.fees.0.amount' => ['A percent fee must be between 0 and 100.']]);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], [
            'one_time_add_ons' => [['name' => 'Cake', 'quantity' => 0, 'unit_price' => 5]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_package.one_time_add_ons.0.quantity']);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '17:00'], ['pricing_type' => 'per_person']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['custom_package.pricing_type']);

        $this->assertSame(1, Booking::count());
    }

    public function test_the_server_overrides_the_duration_with_the_custom_minutes(): void
    {
        $booking = $this->createCustom(['duration' => 2, 'duration_unit' => 'hours'], ['duration_minutes' => 45]);

        $this->assertSame('45.00', $booking->duration);
        $this->assertSame('minutes', $booking->duration_unit);
        $this->assertSame(45, PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail()->getDurationInMinutes());
    }

    public function test_catalog_add_ons_must_be_offered_at_the_location_and_use_the_catalog_price(): void
    {
        $cantonCake = AddOn::create(['location_id' => $this->canton->id, 'name' => 'Canton Cake', 'price' => 12, 'is_active' => true]);
        $retired = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Retired Balloons', 'price' => 3, 'is_active' => false]);
        $unpriced = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Ask At Desk', 'price' => null, 'is_active' => true]);
        $limited = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Goodie Bag', 'price' => 4, 'is_active' => true, 'min_quantity' => 2, 'max_quantity' => 4]);
        $companyWide = AddOn::create(['location_id' => null, 'name' => 'Gift Card Sleeve', 'price' => 1, 'is_active' => true]);

        foreach ([$cantonCake, $retired, $unpriced, $companyWide] as $addOn) {
            $this->as($this->admin)->postJson('/api/bookings', $this->payload([
                'additional_addons' => [['addon_id' => $addOn->id, 'quantity' => 1, 'price_at_booking' => 1]],
            ]))
                ->assertStatus(422)
                ->assertJsonPath('errors.additional_addons.0', "One of the add-ons isn't available at this location.");
        }

        foreach ([1, 5] as $quantity) {
            $this->as($this->admin)->postJson('/api/bookings', $this->payload([
                'additional_addons' => [['addon_id' => $limited->id, 'quantity' => $quantity]],
            ]))
                ->assertStatus(422)
                ->assertJsonPath('errors.additional_addons.0', 'Goodie Bag needs a quantity between 2 and 4.');
        }

        $this->as($this->admin)->postJson('/api/bookings', $this->payload([
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 100]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.additional_addons.0', 'Pizza needs a quantity between 1 and 99.');

        $this->assertSame(0, Booking::count());

        $booking = $this->createCustom([
            'total_amount' => 290.80,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2, 'price_at_booking' => 0.01]],
        ]);

        $this->assertSame('290.80', $booking->total_amount);
        $this->assertSame(10.8, $booking->applied_fees[0]['fee_amount']);
        $line = BookingAddOn::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(2, (int) $line->quantity);
        $this->assertEquals(7.50, (float) $line->price_at_booking, 'the catalog price is kept, not the client price');
    }

    public function test_amount_paid_cannot_be_more_than_the_total(): void
    {
        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['payment_method' => 'in-store', 'amount_paid' => 275.21]))
            ->assertStatus(422)
            ->assertJsonPath('errors.amount_paid.0', 'Amount paid cannot be more than the total.');

        $this->assertSame(0, Booking::count());

        $booking = $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_package_attraction_and_discount_keys_are_refused_on_a_custom_booking(): void
    {
        $package = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $laser = Attraction::create([
            'location_id' => $this->brighton->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['package_id' => $package->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.package_id.0', "A custom package booking can't also have a package.");

        $this->as($this->admin)->postJson('/api/bookings', $this->payload([
            'additional_attractions' => [['attraction_id' => $laser->id, 'quantity' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['additional_attractions']);

        foreach (['promo_code' => 'SAVE10', 'gift_card_code' => 'GC-1234', 'membership_id' => 1] as $field => $value) {
            $this->as($this->admin)->postJson('/api/bookings', $this->payload([$field => $value]))
                ->assertStatus(422)
                ->assertJsonPath("errors.{$field}.0", "Discounts can't be used with a custom package.");
        }

        $this->assertSame(0, Booking::count());

        $this->createCustom(['additional_attractions' => [], 'promo_code' => '', 'gift_card_code' => null]);
        $this->assertSame(1, Booking::count());
    }

    public function test_enforcing_package_required_still_lets_custom_bookings_through(): void
    {
        config(['booking_rules.package_required' => 'enforce']);

        $this->createCustom();

        $plain = $this->payload(['room_id' => null, 'booking_time' => '17:00']);
        unset($plain['custom_package']);

        $this->as($this->admin)->postJson('/api/bookings', $plain)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Please choose a package for this booking.');

        $this->assertSame(1, Booking::count());
    }

    public function test_a_regular_package_less_booking_still_cannot_take_a_space(): void
    {
        $plain = $this->payload();
        unset($plain['custom_package']);

        $this->as($this->admin)->postJson('/api/bookings', $plain)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a package for this booking before giving it a space.');

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, PackageTimeSlot::count());
    }

    public function test_changing_participants_on_a_per_guest_booking_recomputes_the_total(): void
    {
        $booking = $this->createCustom();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 10,
            'change_reason' => 'Two guests dropped out',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame(10, (int) $booking->participants);
        $this->assertSame('233.60', $booking->total_amount);
        $this->assertSame(8.6, $booking->applied_fees[0]['fee_amount']);
        $this->assertEquals(10, $booking->applied_fees[1]['fee_amount']);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 17,
            'change_reason' => 'More guests',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.participants.0', 'Participants must be between 8 and 16.');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 12,
            'total_amount' => 233.60,
            'change_reason' => 'Guests are back',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PRICE_MISMATCH')
            ->assertJsonPath('expected_total', 275.2);

        $this->assertSame('233.60', $booking->fresh()->total_amount);
    }

    public function test_client_fees_and_discounts_are_ignored_on_a_custom_update(): void
    {
        $booking = $this->createCustom();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 10,
            'total_amount' => 233.60,
            'discount_amount' => 50,
            'applied_fees' => [['fee_name' => 'Sneaky Fee', 'fee_amount' => 99, 'fee_application_type' => 'additive']],
            'applied_discounts' => [['discount_name' => 'Free', 'discount_amount' => 50, 'discount_type' => 'fixed', 'original_price' => 50]],
            'change_reason' => 'Inline edit',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('233.60', $booking->total_amount);
        $this->assertSame('0.00', $booking->discount_amount);
        $this->assertNull($booking->applied_discounts);
        $this->assertSame(['Venue Fee', 'Setup Fee'], array_column($booking->applied_fees, 'fee_name'));
    }

    public function test_replacing_the_custom_terms_recomputes_and_logs_the_change(): void
    {
        $booking = $this->createCustom();
        $slotId = PackageTimeSlot::where('booking_id', $booking->id)->value('id');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'custom_package' => [
                'name' => 'Board Retreat',
                'category' => 'Custom: Kid',
                'duration_minutes' => 120,
                'min_guests' => null,
                'max_guests' => 20,
                'pricing_type' => 'per_booking',
                'price' => 300,
                'fees' => [],
                'one_time_add_ons' => [],
            ],
            'total_amount' => 300,
            'change_reason' => 'Guest changed the plan',
        ])->assertOk()
            ->assertJsonPath('data.custom_package_name', 'Board Retreat')
            ->assertJsonPath('data.total_amount', '300.00');

        $booking->refresh();
        $this->assertTrue($booking->is_custom_package);
        $this->assertSame('Board Retreat', $booking->custom_package_name);
        $this->assertSame('Custom: Kid', $booking->custom_package_category);
        $this->assertSame('300.00', $booking->package_price_at_booking);
        $this->assertSame('base', $booking->package_pricing_type_at_booking);
        $this->assertEquals(['min_guests' => 1, 'max_guests' => 20, 'fees' => [], 'one_time_add_ons' => []], $booking->custom_package_terms);
        $this->assertSame('120.00', $booking->duration);
        $this->assertNull($booking->applied_fees);
        $this->assertSame(120, PackageTimeSlot::findOrFail($slotId)->getDurationInMinutes());

        $log = ActivityLog::where('action', 'Booking Edited')->where('entity_id', $booking->id)->latest('id')->firstOrFail();
        $changes = $log->metadata['changes'];
        $this->assertEquals(['from' => 'Team Outing', 'to' => 'Board Retreat'], $changes['custom_package_name']);
        $this->assertEquals(['from' => 'Custom: Work', 'to' => 'Custom: Kid'], $changes['custom_package_category']);
        $this->assertEquals(['from' => '20.00', 'to' => '300.00'], $changes['package_price_at_booking']);
        $this->assertEquals(['from' => 'per_person', 'to' => 'base'], $changes['package_pricing_type_at_booking']);
        $this->assertSame(16, $changes['custom_package_terms']['from']['max_guests']);
        $this->assertSame(20, $changes['custom_package_terms']['to']['max_guests']);
        $this->assertArrayNotHasKey('is_custom_package', $changes);
    }

    public function test_an_unchanged_legacy_category_is_kept_but_a_new_one_must_be_a_custom_category(): void
    {
        $booking = $this->createCustom();
        DB::table('bookings')->where('id', $booking->id)->update(['custom_package_category' => 'Corporate Event']);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'custom_package' => $this->terms(['name' => 'Team Outing Plus', 'category' => 'Corporate Event']),
            'change_reason' => 'Renamed',
        ])->assertOk();

        $this->assertSame('Corporate Event', $booking->fresh()->custom_package_category);

        $changes = ActivityLog::where('action', 'Booking Edited')->where('entity_id', $booking->id)->latest('id')->firstOrFail()->metadata['changes'];
        $this->assertEquals(['from' => 'Team Outing', 'to' => 'Team Outing Plus'], $changes['custom_package_name']);
        $this->assertSame(['custom_package_name'], array_keys($changes), 'unchanged terms, fees and totals are not logged as changes');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'custom_package' => $this->terms(['category' => 'Gone Category']),
            'change_reason' => 'Renamed',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['custom_package.category' => ['Choose a category from the list.']]);
    }

    public function test_a_custom_booking_cannot_switch_to_a_package_and_a_regular_one_cannot_take_custom_terms(): void
    {
        $booking = $this->createCustom();
        $package = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'package_id' => $package->id,
            'change_reason' => 'Switch',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.package_id.0', "A custom package booking can't be switched to a regular package.");

        $this->assertNull($booking->fresh()->package_id);

        $regular = Booking::create([
            'reference_number' => 'BKREGULAR1',
            'location_id' => $this->brighton->id,
            'package_id' => $package->id,
            'guest_name' => 'Regular Guest',
            'guest_email' => 'regular@example.com',
            'booking_date' => $this->date,
            'booking_time' => '10:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->as($this->admin)->putJson("/api/bookings/{$regular->id}", [
            'custom_package' => $this->terms(),
            'change_reason' => 'Make it custom',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.custom_package.0', 'Only custom package bookings can have custom package details.');

        $this->assertFalse($regular->fresh()->is_custom_package);
    }

    public function test_a_space_change_on_a_custom_booking_without_a_slot_creates_one(): void
    {
        $booking = $this->createCustom();
        PackageTimeSlot::where('booking_id', $booking->id)->delete();
        $booking->forceFill(['room_id' => null])->save();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'room_id' => $this->brightonHall->id,
            'change_reason' => 'Bigger room',
        ])->assertOk();

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNull($slot->package_id);
        $this->assertSame($this->brightonHall->id, (int) $slot->room_id);
        $this->assertSame('booked', $slot->status);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'room_id' => $this->cantonRoom->id,
            'change_reason' => 'Wrong venue',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Choose a space at this location.');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'room_id' => null,
            'change_reason' => 'No room',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Choose a space at this location.');
    }

    public function test_a_regular_package_less_booking_still_cannot_be_given_a_space_on_edit(): void
    {
        $legacy = Booking::create([
            'reference_number' => 'BKLEGACY1',
            'location_id' => $this->brighton->id,
            'package_id' => null,
            'guest_name' => 'Imported Guest',
            'guest_email' => 'imported@example.com',
            'booking_date' => $this->date,
            'booking_time' => '10:00',
            'participants' => 2,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'total_amount' => 50,
            'amount_paid' => 50,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->as($this->admin)->putJson("/api/bookings/{$legacy->id}", [
            'room_id' => $this->brightonRoom->id,
            'change_reason' => 'Fix import',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a package for this booking before giving it a space.');

        $this->assertNull($legacy->fresh()->room_id);
        $this->assertSame(0, PackageTimeSlot::where('booking_id', $legacy->id)->count());
    }

    public function test_existing_add_on_lines_keep_their_price_and_new_lines_use_the_catalog_price(): void
    {
        $booking = $this->createCustom([
            'total_amount' => 290.80,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2]],
        ]);
        $this->pizza->update(['price' => 9.00, 'is_active' => false]);
        $cake = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Cake', 'price' => 25, 'is_active' => true]);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 11,
            'additional_addons' => [
                ['addon_id' => $this->pizza->id, 'quantity' => 2, 'price_at_booking' => 9],
                ['addon_id' => $cake->id, 'quantity' => 1, 'price_at_booking' => 1],
            ],
            'change_reason' => 'Cake added',
        ])->assertOk();

        $booking->refresh();
        $prices = BookingAddOn::where('booking_id', $booking->id)->pluck('price_at_booking', 'add_on_id')->map(fn ($p) => (float) $p)->all();
        $this->assertSame([$this->pizza->id => 7.5, $cake->id => 25.0], $prices);
        $this->assertSame('296.00', $booking->total_amount);

        $retired = AddOn::create(['location_id' => $this->brighton->id, 'name' => 'Old Banner', 'price' => 5, 'is_active' => false]);
        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'additional_addons' => [
                ['addon_id' => $this->pizza->id, 'quantity' => 2],
                ['addon_id' => $retired->id, 'quantity' => 1],
            ],
            'change_reason' => 'Banner',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.additional_addons.0', "One of the add-ons isn't available at this location.");
    }

    public function test_a_paid_custom_booking_cannot_drop_below_what_was_paid(): void
    {
        $booking = $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 10,
            'change_reason' => 'Fewer guests',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.amount_paid.0', 'Amount paid cannot be more than the total.');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 300,
            'change_reason' => 'Typo',
        ])->assertOk();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'notes' => 'Bringing a cake',
            'change_reason' => 'Note',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('275.20', $booking->total_amount);
        $this->assertSame('275.20', $booking->amount_paid);
    }

    public function test_recording_a_part_payment_keeps_the_server_total(): void
    {
        $booking = $this->createCustom();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 100,
            'total_amount' => 999,
            'change_reason' => 'Deposit',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOM_PRICE_MISMATCH');

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 100,
            'change_reason' => 'Deposit',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('100.00', $booking->amount_paid);
        $this->assertSame('275.20', $booking->total_amount);
        $this->assertSame('partial', $booking->payment_status);
    }

    public function test_edits_that_do_not_touch_the_price_keep_the_saved_total_after_an_add_on_is_deleted(): void
    {
        $booking = $this->createCustom([
            'total_amount' => 290.80,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2]],
        ]);
        $this->assertSame('290.80', $booking->total_amount);
        $fees = $booking->applied_fees;

        $this->pizza->delete();
        $this->assertSame(0, BookingAddOn::where('booking_id', $booking->id)->count());

        $newDate = now()->addDays(6)->toDateString();
        $this->as($this->admin)->postJson("/api/bookings/{$booking->id}/reprice", ['booking_date' => $newDate])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 290.8)
            ->assertJsonPath('data.additive_fees', 20.8)
            ->assertJsonPath('data.remaining_balance', 290.8);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 100,
            'status' => 'confirmed',
            'change_reason' => 'Deposit',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('290.80', $booking->total_amount);
        $this->assertSame('100.00', $booking->amount_paid);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertEquals($fees, $booking->applied_fees);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'booking_date' => $newDate,
            'total_amount' => 290.80,
            'change_reason' => 'New date',
        ])->assertOk();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'room_id' => $this->brightonHall->id,
            'change_reason' => 'Bigger room',
        ])->assertOk();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'participants' => 12,
            'total_amount' => 290.80,
            'change_reason' => 'Same guests',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('290.80', $booking->total_amount);
        $this->assertSame($newDate, $booking->booking_date->toDateString());
        $this->assertSame($this->brightonHall->id, (int) $booking->room_id);
        $this->assertEquals($fees, $booking->applied_fees);
    }

    public function test_a_payment_over_the_balance_is_capped_at_the_total(): void
    {
        $booking = $this->createCustom();

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 275.21,
            'status' => 'confirmed',
            'change_reason' => 'Paid in full',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('275.20', $booking->amount_paid);
        $this->assertSame('275.20', $booking->total_amount);
        $this->assertSame('paid', $booking->payment_status);

        $this->as($this->admin)->putJson("/api/bookings/{$booking->id}", [
            'amount_paid' => 300,
            'status' => 'confirmed',
            'change_reason' => 'Stale balance',
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('275.20', $booking->amount_paid);
        $this->assertSame('275.20', $booking->total_amount);
    }

    public function test_the_reprice_quote_prices_a_custom_booking_with_the_documented_keys(): void
    {
        $booking = $this->createCustom();

        $response = $this->as($this->admin)->postJson("/api/bookings/{$booking->id}/reprice", ['participants' => 10])->assertOk();

        $quote = $response->json('data');
        $this->assertSame([
            'subtotal', 'lines', 'fees', 'persist_fees', 'additive_fees', 'special_pricing_discount', 'membership_discount',
            'redeemed_credit', 'discount_amount', 'applied_discounts', 'total_amount', 'amount_paid', 'remaining_balance',
            'payment_status', 'delta', 'pricing_consistent',
        ], array_keys($quote));
        $this->assertEquals(215, $quote['subtotal']);
        $this->assertSame(233.6, $quote['total_amount']);
        $this->assertSame(18.6, $quote['additive_fees']);
        $this->assertSame(-41.6, $quote['delta']);
        $this->assertSame(233.6, $quote['remaining_balance']);
        $this->assertSame('pending', $quote['payment_status']);
        $this->assertTrue($quote['pricing_consistent']);
        $this->assertNull($quote['applied_discounts']);
        $this->assertEquals(0, $quote['discount_amount']);
        $this->assertEquals(0, $quote['special_pricing_discount']);
        $this->assertEquals(0, $quote['membership_discount']);
        $this->assertEquals(0, $quote['redeemed_credit']);
        $this->assertSame($quote['fees'], $quote['persist_fees']);
        $this->assertSame(8.6, $quote['fees'][0]['fee_amount']);
        $this->assertSame('4%', $quote['fees'][0]['fee_label']);
        $this->assertEquals(['type' => 'package', 'id' => null, 'category' => 'Custom: Work', 'unit_price' => 200, 'quantity' => 1, 'line_total' => 200, 'client_unit' => null], $quote['lines'][0]);
        $this->assertSame(['type', 'id', 'category', 'unit_price', 'quantity', 'line_total', 'client_unit'], array_keys($quote['lines'][0]));
        $this->assertEquals(['type' => 'one_time_addon', 'id' => null, 'category' => null, 'unit_price' => 5, 'quantity' => 3, 'line_total' => 15, 'client_unit' => null], $quote['lines'][1]);

        $this->as($this->admin)->postJson("/api/bookings/{$booking->id}/reprice", [
            'participants' => 12,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2]],
            'booking_date' => now()->addDays(9)->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 290.8)
            ->assertJsonPath('data.lines.1.type', 'addon')
            ->assertJsonPath('data.lines.1.unit_price', 7.5);

        $this->as($this->admin)->postJson("/api/bookings/{$booking->id}/reprice", ['participants' => 17])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 379.2)
            ->assertJsonPath('data.participants_error', 'Participants must be between 8 and 16.');
    }

    public function test_lists_exports_and_search_carry_the_custom_fields(): void
    {
        $customer = $this->customer();
        $booking = $this->createCustom(['customer_id' => $customer->id, 'guest_name' => null, 'guest_email' => null]);

        $expect = function (array $row) use ($booking) {
            $this->assertSame($booking->id, (int) $row['id']);
            $this->assertTrue($row['is_custom_package']);
            $this->assertSame('Team Outing', $row['custom_package_name']);
            $this->assertSame('Custom: Work', $row['custom_package_category']);
            $this->assertSame(16, $row['custom_package_terms']['max_guests']);
            $this->assertSame('20.00', $row['package_price_at_booking']);
            $this->assertSame('0.00', $row['price_per_additional_at_booking']);
            $this->assertSame('per_person', $row['package_pricing_type_at_booking']);
        };

        $expect($this->as($this->admin)->getJson('/api/bookings?per_page=50')->assertOk()->json('data.bookings.0'));
        $expect($this->as($this->admin)->getJson('/api/bookings/export')->assertOk()->json('data.bookings.0'));
        $expect($this->as($customer)->getJson('/api/customers/bookings')->assertOk()->json('data.bookings.0'));

        $this->assertSame([$booking->id], array_column($this->as($this->admin)->getJson('/api/bookings?search=outing')->assertOk()->json('data.bookings'), 'id'));
        $this->assertSame([$booking->id], array_column($this->as($this->admin)->getJson('/api/bookings/search?query=Team%20Outing')->assertOk()->json('data'), 'id'));
        $this->assertSame([], $this->as($this->admin)->getJson('/api/bookings?search=retreat')->assertOk()->json('data.bookings'));
    }

    public function test_a_changed_custom_term_with_the_same_checkout_key_is_a_new_request(): void
    {
        $first = $this->as($this->admin)->postJson('/api/bookings', $this->payload(['checkout_key' => 'cpkg-key-1']))->assertCreated();

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['checkout_key' => 'cpkg-key-1']))
            ->assertOk()
            ->assertJsonPath('message', 'Booking already exists')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $changed = $this->as($this->admin)->postJson('/api/bookings', $this->payload(['checkout_key' => 'cpkg-key-1'], ['name' => 'Team Outing (VIP)']))
            ->assertCreated()
            ->assertJsonPath('message', 'Booking created successfully');

        $this->assertNotSame($first->json('data.id'), $changed->json('data.id'));
        $this->assertSame('Team Outing (VIP)', Booking::findOrFail((int) $changed->json('data.id'))->custom_package_name);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['checkout_key' => 'cpkg-key-1'], ['name' => 'Team Outing (VIP)', 'category' => 'Custom: Kid']))
            ->assertCreated();

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['checkout_key' => 'cpkg-key-1', 'total_amount' => 301.20], [
            'name' => 'Team Outing (VIP)',
            'category' => 'Custom: Kid',
            'one_time_add_ons' => [['name' => 'Glow Bracelets', 'quantity' => 8, 'unit_price' => 5]],
        ]))->assertCreated();

        $this->assertSame(4, Booking::count());
    }

    public function test_a_saved_paid_booking_is_only_refused_again_when_its_custom_terms_are_unchanged(): void
    {
        $paid = ['checkout_key' => 'cpkg-key-2', 'payment_method' => 'in-store', 'amount_paid' => 275.20];
        $first = (int) $this->as($this->admin)->postJson('/api/bookings', $this->payload($paid))->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson('/api/bookings', $this->payload($paid))
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED')
            ->assertJsonPath('booking_id', $first);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload($paid, ['name' => 'Team Outing (Second Group)']))
            ->assertCreated()
            ->assertJsonPath('data.custom_package_name', 'Team Outing (Second Group)');

        $this->assertSame(2, Booking::count());
    }

    public function test_a_keyless_retry_with_a_changed_custom_term_is_not_replayed(): void
    {
        $first = (int) $this->as($this->admin)->postJson('/api/bookings', $this->payload())->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson('/api/bookings', $this->payload())
            ->assertOk()
            ->assertJsonPath('message', 'Booking already exists')
            ->assertJsonPath('data.id', $first);

        $this->as($this->admin)->postJson('/api/bookings', $this->payload([], ['category' => 'Custom: Kid']))
            ->assertCreated();

        $this->assertSame(2, Booking::count());
    }

    public function test_a_same_space_overlap_still_needs_a_manager_override(): void
    {
        $this->manager->forceFill(['pin_hash' => Hash::make('4321')])->save();

        $this->createCustom();

        $this->as($this->admin)->postJson('/api/bookings', $this->payload(['booking_time' => '14:30', 'guest_email' => 'other@example.com']))
            ->assertStatus(409)
            ->assertJsonPath('requires_override', true);

        $package = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $plain = $this->payload([
            'package_id' => $package->id,
            'booking_time' => '15:00',
            'participants' => 4,
            'duration' => 60,
            'total_amount' => 100,
            'guest_email' => 'party@example.com',
        ]);
        unset($plain['custom_package']);

        $this->as($this->admin)->postJson('/api/bookings', $plain)
            ->assertStatus(409)
            ->assertJsonPath('requires_override', true);

        $this->assertSame(1, Booking::count());
    }

    public function test_moving_a_custom_booking_to_another_location_keeps_its_space_slot(): void
    {
        $booking = $this->createCustom();

        $roomless = $this->createCustom(['booking_time' => '18:00', 'guest_email' => 'roomless@example.com']);
        PackageTimeSlot::where('booking_id', $roomless->id)->delete();
        $roomless->forceFill(['room_id' => null])->save();

        $this->as($this->admin)->patchJson("/api/bookings/{$roomless->id}/location", [
            'location_id' => $this->canton->id,
            'change_reason' => 'Guest prefers Canton',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Choose a space at this location.');

        $this->as($this->admin)->patchJson("/api/bookings/{$booking->id}/location", [
            'location_id' => $this->canton->id,
            'room_id' => $this->cantonRoom->id,
            'change_reason' => 'Guest prefers Canton',
        ])->assertOk();

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($this->cantonRoom->id, (int) $slot->room_id);
        $this->assertNull($slot->package_id);

        PackageTimeSlot::where('booking_id', $booking->id)->delete();

        $this->as($this->admin)->patchJson("/api/bookings/{$booking->id}/location", [
            'location_id' => $this->brighton->id,
            'room_id' => $this->brightonRoom->id,
            'change_reason' => 'Back to Brighton',
        ])->assertOk();

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($this->brightonRoom->id, (int) $slot->room_id);
        $this->assertNull($slot->package_id);
        $this->assertSame($this->brighton->id, (int) $booking->fresh()->location_id);
    }

    public function test_a_custom_booking_with_this_locations_add_ons_cannot_be_moved_away(): void
    {
        $booking = $this->createCustom([
            'total_amount' => 290.80,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2]],
        ]);

        $this->as($this->admin)->patchJson("/api/bookings/{$booking->id}/location", [
            'location_id' => $this->canton->id,
            'room_id' => $this->cantonRoom->id,
            'change_reason' => 'Guest prefers Canton',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.additional_addons.0', "One of the add-ons isn't available at this location.");

        $this->assertSame($this->brighton->id, (int) $booking->fresh()->location_id);
    }

    public function test_a_regular_package_less_booking_still_cannot_be_moved_into_a_space(): void
    {
        $legacy = Booking::create([
            'reference_number' => 'BKLEGACY2',
            'location_id' => $this->brighton->id,
            'package_id' => null,
            'guest_name' => 'Imported Guest',
            'guest_email' => 'imported@example.com',
            'booking_date' => $this->date,
            'booking_time' => '10:00',
            'participants' => 2,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'total_amount' => 50,
            'amount_paid' => 50,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->as($this->admin)->patchJson("/api/bookings/{$legacy->id}/location", [
            'location_id' => $this->canton->id,
            'room_id' => $this->cantonRoom->id,
            'change_reason' => 'Fix import',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a package for this booking before giving it a space.');

        $this->assertSame($this->brighton->id, (int) $legacy->fresh()->location_id);
        $this->assertSame(0, PackageTimeSlot::where('booking_id', $legacy->id)->count());
    }

    public function test_an_approved_location_change_gives_a_custom_booking_a_package_less_slot(): void
    {
        $booking = $this->createCustom();
        PackageTimeSlot::where('booking_id', $booking->id)->delete();

        $requestId = (int) $this->as($this->admin)->postJson("/api/bookings/{$booking->id}/location-change-requests", [
            'to_location_id' => $this->canton->id,
            'room_id' => $this->cantonRoom->id,
            'reason' => 'Guest prefers Canton',
        ])->assertCreated()->json('data.id');

        $this->as($this->admin)->patchJson("/api/location-change-requests/{$requestId}/approve", [])->assertOk();

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNull($slot->package_id);
        $this->assertSame($this->cantonRoom->id, (int) $slot->room_id);
        $this->assertSame($this->canton->id, (int) $booking->fresh()->location_id);
    }

    public function test_staff_can_still_see_a_custom_slot_through_its_booking_location(): void
    {
        $booking = $this->createCustom();
        $slotId = (int) PackageTimeSlot::where('booking_id', $booking->id)->value('id');
        $cantonManager = $this->staff('location_manager', $this->canton);

        $this->as($this->manager)->getJson("/api/package-time-slots/{$slotId}")->assertOk()->assertJsonPath('data.id', $slotId);
        $this->assertContains($slotId, array_column($this->as($this->manager)->getJson('/api/package-time-slots')->assertOk()->json('data.time_slots'), 'id'));
        $this->assertContains($slotId, array_column($this->as($this->admin)->getJson('/api/package-time-slots')->assertOk()->json('data.time_slots'), 'id'));

        $this->as($cantonManager)->getJson("/api/package-time-slots/{$slotId}")->assertNotFound();
        $this->assertNotContains($slotId, array_column($this->as($cantonManager)->getJson('/api/package-time-slots')->assertOk()->json('data.time_slots'), 'id'));
    }
}
