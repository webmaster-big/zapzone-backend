<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Location;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use App\Support\OnlineBookingGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnlineBookingGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private array $venues = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Gate Co',
            'email' => 'gate@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Gate St',
        ]);

        $this->venues['open'] = $this->makeVenue('Brighton', true);
        $this->venues['closed'] = $this->makeVenue('Waterford', false);
    }

    private function makeVenue(string $city, bool $shown): array
    {
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
            'show_on_main_page' => $shown,
        ]);

        $room = Room::create([
            'location_id' => $location->id,
            'name' => "{$city} Room",
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        $package = Package::create([
            'location_id' => $location->id,
            'name' => "{$city} Party",
            'description' => 'Test package',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $attraction = Attraction::create([
            'location_id' => $location->id,
            'name' => "{$city} Laser Tag",
            'description' => 'Arena laser tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $event = Event::create([
            'location_id' => $location->id,
            'name' => "{$city} Glow Night",
            'date_type' => 'one_time',
            'start_date' => now()->addDays(7)->toDateString(),
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        return compact('location', 'room', 'package', 'attraction', 'event');
    }

    private function staff(string $role = 'company_admin', ?Location $location = null): User
    {
        return User::create([
            'first_name' => 'Front',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => ($location ?? $this->venues['open']['location'])->id,
        ]);
    }

    private function customerToken(): string
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat.' . uniqid() . '@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        $this->app['auth']->forgetGuards();

        return $customer->createToken('customer')->plainTextToken;
    }

    private function bookingPayload(string $venue): array
    {
        $v = $this->venues[$venue];

        return [
            'guest_name' => 'Online Guest',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '2485559999',
            'package_id' => $v['package']->id,
            'location_id' => $v['location']->id,
            'room_id' => $v['room']->id,
            'type' => 'package',
            'booking_date' => now()->addDays(10)->toDateString(),
            'booking_time' => '18:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
        ];
    }

    private function attractionPayload(string $venue): array
    {
        return [
            'attraction_id' => $this->venues[$venue]['attraction']->id,
            'guest_name' => 'Online Guest',
            'guest_email' => 'guest@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => now()->toDateString(),
            'payment_method' => 'authorize.net',
        ];
    }

    private function eventPayload(string $venue): array
    {
        $v = $this->venues[$venue];

        return [
            'event_id' => $v['event']->id,
            'location_id' => $v['location']->id,
            'guest_name' => 'Online Guest',
            'guest_email' => 'guest@example.com',
            'purchase_date' => now()->addDays(7)->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 1,
            'total_amount' => 15,
            'payment_method' => 'authorize.net',
        ];
    }

    private function orderPayload(string $venue): array
    {
        return [
            'items' => [[
                'type' => 'attraction',
                'id' => $this->venues[$venue]['attraction']->id,
                'quantity' => 1,
                'scheduled_date' => now()->addDays(3)->toDateString(),
                'scheduled_time' => '18:00',
            ]],
            'guest_name' => 'Online Guest',
            'guest_email' => 'guest@example.com',
            'payment_method' => 'authorize.net',
        ];
    }

    private function counts(): array
    {
        return [
            DB::table('bookings')->count(),
            DB::table('attraction_purchases')->count(),
            DB::table('event_purchases')->count(),
            DB::table('ticket_orders')->count(),
        ];
    }

    private function assertRefused($response): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('code', OnlineBookingGate::CODE)
            ->assertJsonPath('message', 'This location is not taking online bookings right now. Please choose another location.');
    }

    private function assertNotRefusedByGate($response): void
    {
        $this->assertNotSame(OnlineBookingGate::CODE, $response->json('code'), $response->getContent());
    }

    public function test_a_guest_cannot_book_or_buy_anything_at_a_turned_off_location(): void
    {
        $before = $this->counts();

        $this->assertRefused($this->postJson('/api/bookings', $this->bookingPayload('closed')));
        $this->assertRefused($this->postJson('/api/attraction-purchases', $this->attractionPayload('closed')));
        $this->assertRefused($this->postJson('/api/event-purchases', $this->eventPayload('closed')));
        $this->assertRefused($this->postJson('/api/ticket-orders', $this->orderPayload('closed')));

        $this->assertSame($before, $this->counts(), 'nothing may be created for a refused request');
    }

    public function test_a_logged_in_customer_is_treated_as_a_guest(): void
    {
        $before = $this->counts();
        $token = $this->customerToken();

        $this->assertRefused($this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/bookings', $this->bookingPayload('closed')));
        $this->assertRefused($this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/attraction-purchases', $this->attractionPayload('closed')));
        $this->assertRefused($this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/event-purchases', $this->eventPayload('closed')));
        $this->assertRefused($this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/ticket-orders', $this->orderPayload('closed')));

        $this->assertSame($before, $this->counts());
    }

    public function test_staff_can_still_book_at_a_turned_off_location(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload('closed'))
            ->assertSuccessful();

        $this->assertDatabaseHas('bookings', ['location_id' => $this->venues['closed']['location']->id, 'guest_name' => 'Online Guest']);

        $this->assertNotRefusedByGate($this->actingAs($staff, 'sanctum')->postJson('/api/attraction-purchases', $this->attractionPayload('closed')));
        $this->assertNotRefusedByGate($this->actingAs($staff, 'sanctum')->postJson('/api/event-purchases', $this->eventPayload('closed')));
        $this->assertNotRefusedByGate($this->actingAs($staff, 'sanctum')->postJson('/api/ticket-orders', $this->orderPayload('closed')));
    }

    public function test_an_attendant_from_another_location_is_still_staff(): void
    {
        $attendant = $this->staff('attendant', $this->venues['open']['location']);

        $this->actingAs($attendant, 'sanctum')
            ->postJson('/api/bookings', $this->bookingPayload('closed'))
            ->assertSuccessful();
    }

    public function test_guests_book_normally_at_a_location_that_is_on(): void
    {
        $this->postJson('/api/bookings', $this->bookingPayload('open'))->assertSuccessful();

        $this->assertNotRefusedByGate($this->postJson('/api/attraction-purchases', $this->attractionPayload('open')));
        $this->assertNotRefusedByGate($this->postJson('/api/event-purchases', $this->eventPayload('open')));
        $this->assertNotRefusedByGate($this->postJson('/api/ticket-orders', $this->orderPayload('open')));
    }

    public function test_turning_a_location_back_on_lets_guests_book_again(): void
    {
        $this->assertRefused($this->postJson('/api/bookings', $this->bookingPayload('closed')));

        $this->venues['closed']['location']->update(['show_on_main_page' => true]);

        $this->postJson('/api/bookings', $this->bookingPayload('closed'))->assertSuccessful();
    }

    private function giftCardPayload(string $venue, array $overrides = []): array
    {
        return array_merge([
            'location_id' => $this->venues[$venue]['location']->id,
            'amount' => 50,
            'payment_method' => 'authorize.net',
            'purchaser_name' => 'Gift Buyer',
            'purchaser_email' => 'buyer.' . uniqid() . '@example.com',
            'opaque_data' => ['dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'token'],
        ], $overrides);
    }

    private function membershipPlan(): MembershipPlan
    {
        return MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->venues['open']['location']->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer-' . uniqid(),
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    public function test_gift_cards_for_a_turned_off_location_are_not_sold_online(): void
    {
        $before = DB::table('gift_cards')->count();

        $this->postJson('/api/gift-cards/purchase', $this->giftCardPayload('closed'))
            ->assertStatus(422)
            ->assertJsonPath('code', OnlineBookingGate::CODE)
            ->assertJsonPath('message', 'Gift cards for this location are not sold online right now. Please choose another location.');

        $this->assertSame($before, DB::table('gift_cards')->count());

        $this->assertNotRefusedByGate($this->postJson('/api/gift-cards/purchase', $this->giftCardPayload('open')));
    }

    public function test_staff_can_still_sell_a_gift_card_for_a_turned_off_location(): void
    {
        $response = $this->actingAs($this->staff(), 'sanctum')
            ->postJson('/api/gift-cards/purchase', $this->giftCardPayload('closed', ['payment_method' => 'in-store', 'opaque_data' => null]));

        $this->assertNotRefusedByGate($response);
        $response->assertSuccessful();
    }

    public function test_a_membership_cannot_be_bought_online_with_a_turned_off_home_location(): void
    {
        $plan = $this->membershipPlan();
        $token = $this->customerToken();
        $before = DB::table('memberships')->count();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/memberships/purchase', [
                'membership_plan_id' => $plan->id,
                'home_location_id' => $this->venues['closed']['location']->id,
                'terms_accepted' => true,
                'recurring_billing_authorized' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', OnlineBookingGate::CODE)
            ->assertJsonPath('message', 'Memberships for this home location are not sold online right now. Please choose another location.');

        $this->assertSame($before, DB::table('memberships')->count());

        $this->assertNotRefusedByGate($this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/memberships/purchase', [
                'membership_plan_id' => $plan->id,
                'home_location_id' => $this->venues['open']['location']->id,
                'terms_accepted' => true,
                'recurring_billing_authorized' => true,
            ]));
    }

    public function test_a_cart_mixing_in_a_turned_off_location_is_refused(): void
    {
        $payload = $this->orderPayload('open');
        $payload['items'][] = [
            'type' => 'event',
            'id' => $this->venues['closed']['event']->id,
            'quantity' => 1,
            'scheduled_date' => now()->addDays(7)->toDateString(),
            'scheduled_time' => '18:00',
        ];

        $before = $this->counts();
        $this->assertRefused($this->postJson('/api/ticket-orders', $payload));
        $this->assertSame($before, $this->counts());
    }
}
