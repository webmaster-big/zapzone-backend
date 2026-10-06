<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LiveFeedsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Location $otherLocation;

    private Room $room;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Zap Zone',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Arcade St',
        ]);

        $this->location = $this->makeLocation('Brighton | Zap Zone', 'brighton@zapzone.test');
        $this->otherLocation = $this->makeLocation('Canton | Zap Zone', 'canton@zapzone.test');

        $this->room = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room 1',
            'capacity' => 20,
            'is_available' => true,
        ]);

        $this->package = $this->makePackage($this->location, 'Arcade Party');
        $this->package->rooms()->attach($this->room->id);
    }

    public function get($uri, array $headers = [])
    {
        $this->freshControllers();

        return parent::get($uri, $headers);
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::postJson($uri, $data, $headers, $options);
    }

    public function test_the_time_slot_feed_answers_once_and_finishes(): void
    {
        $date = now()->addDays(3)->toDateString();

        $response = $this->get("/api/package-time-slots/available-slots/{$this->package->id}/{$date}");
        $startedAt = microtime(true);
        $body = $response->streamedContent();
        $elapsed = microtime(true) - $startedAt;

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("retry: 30000\n\n", $body);

        $frames = $this->dataFrames($body);
        $this->assertCount(1, $frames);
        $this->assertSame($this->package->id, $frames[0]['package']['id']);
        $this->assertContains('11:00', array_column($frames[0]['available_slots'], 'start_time'));
        $this->assertLessThan(3.0, $elapsed);
    }

    public function test_the_time_slot_feed_leaves_out_a_start_whose_space_is_already_booked(): void
    {
        $date = now()->addDays(3)->toDateString();

        $this->book('11:00', ['booking_date' => $date]);

        $frames = $this->dataFrames(
            $this->get("/api/package-time-slots/available-slots/{$this->package->id}/{$date}")->streamedContent()
        );

        $starts = array_column($frames[0]['available_slots'], 'start_time');
        $this->assertNotContains('11:00', $starts);
        $this->assertContains('14:00', $starts);
    }

    public function test_the_time_slot_feed_reports_a_missing_package_once(): void
    {
        $body = $this->get('/api/package-time-slots/available-slots/999999/' . now()->addDay()->toDateString())->streamedContent();

        $this->assertStringContainsString("event: error\n", $body);
        $this->assertCount(1, $this->dataFrames($body));
    }

    public function test_the_live_feed_needs_a_staff_login(): void
    {
        $this->getJson('/api/notifications/live')->assertUnauthorized();

        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        $token = $customer->createToken('customer')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications/live')->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/stream/notifications')->assertNotFound();
        $this->getJson('/api/stream/bookings')->assertNotFound();
        $this->getJson('/api/stream/attraction-purchases')->assertNotFound();
    }

    public function test_a_fresh_live_feed_sends_nothing_old(): void
    {
        $this->book('11:00');
        $this->book('13:00');

        $this->actingAs($this->staff('company_admin'), 'sanctum');
        $feed = $this->live(['location_id' => $this->location->id]);

        $this->assertSame([], $feed['items']);
        $this->assertSame('b' . Booking::withTrashed()->max('id') . '.p0', $feed['cursor']);
    }

    public function test_the_live_feed_sends_only_what_arrived_after_the_cursor_and_only_once(): void
    {
        $this->book('11:00');
        $this->actingAs($this->staff('company_admin'), 'sanctum');
        $cursor = $this->live(['location_id' => $this->location->id])['cursor'];

        $new = $this->book('13:00');
        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $this->book('15:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);
        $newTickets = $this->buyTickets($this->attractionHere(), 'new.tickets@example.com');

        $feed = $this->live(['location_id' => $this->location->id, 'after' => $cursor]);

        $this->assertSame(
            [['booking', $new->id], ['attraction_purchase', $newTickets->id]],
            array_map(fn (array $item) => [$item['type'], $item['id']], $feed['items'])
        );
        $this->assertSame(
            ['id', 'type', 'reference_number', 'customer_name', 'package_name', 'location_name', 'booking_date', 'booking_time', 'status', 'total_amount', 'created_at', 'timestamp', 'user_id', 'location_id'],
            array_keys($feed['items'][0])
        );
        $this->assertSame($new->reference_number, $feed['items'][0]['reference_number']);
        $this->assertSame('Arcade Party', $feed['items'][0]['package_name']);
        $this->assertSame($this->location->id, $feed['items'][0]['location_id']);
        $this->assertSame('Laser Tag', $feed['items'][1]['attraction_name']);
        $this->assertSame($this->location->id, $feed['items'][1]['location_id']);

        $this->assertSame("b{$new->id}.p{$newTickets->id}", $feed['cursor']);
        $this->assertSame([], $this->live(['location_id' => $this->location->id, 'after' => $feed['cursor']])['items']);
    }

    public function test_a_far_behind_cursor_only_catches_up_on_the_newest_twenty(): void
    {
        $ids = [];
        foreach (range(1, 23) as $day) {
            $ids[] = $this->book('11:00', [
                'booking_date' => now()->addDays($day)->toDateString(),
                'guest_email' => "far{$day}@example.com",
            ])->id;
        }

        $this->actingAs($this->staff('company_admin'), 'sanctum');
        $feed = $this->live(['location_id' => $this->location->id, 'after' => 'b0.p0']);

        $this->assertSame(array_slice($ids, -20), array_column($feed['items'], 'id'));
    }

    public function test_an_unrecognised_cursor_is_treated_as_a_fresh_start(): void
    {
        $this->book('11:00');
        $this->actingAs($this->staff('company_admin'), 'sanctum');

        foreach (['booking_1', 'purchase_7', 'b1.p', 'garbage', 'b99999999999999999999999.p1', ['b0.p0']] as $after) {
            $feed = $this->live(['location_id' => $this->location->id, 'after' => $after]);

            $this->assertSame([], $feed['items'], json_encode($after));
            $this->assertSame('b' . Booking::withTrashed()->max('id') . '.p0', $feed['cursor'], json_encode($after));
        }
    }

    public function test_a_location_manager_only_ever_sees_their_own_location(): void
    {
        $cursor = 'b' . (int) Booking::withTrashed()->max('id') . '.p0';
        $here = $this->book('11:00');
        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $this->book('13:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);
        $ticketsHere = $this->buyTickets($this->attractionHere(), 'here.tickets@example.com');
        $this->buyTickets($this->attractionAt($this->otherLocation), 'canton.tickets@example.com');

        $this->actingAs($this->staff('location_manager'), 'sanctum');

        $this->assertSame(
            [['booking', $here->id], ['attraction_purchase', $ticketsHere->id]],
            array_map(fn (array $item) => [$item['type'], $item['id']], $this->live(['after' => $cursor])['items'])
        );
        $this->assertSame([], $this->live(['location_id' => $this->otherLocation->id, 'after' => $cursor])['items']);
    }

    public function test_a_manager_covering_two_locations_sees_only_the_one_they_are_working_in(): void
    {
        $manager = $this->staff('location_manager');
        $manager->locations()->sync([$this->otherLocation->id]);
        $token = $manager->createToken('test')->plainTextToken;

        $cursor = 'b' . (int) Booking::withTrashed()->max('id') . '.p0';
        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $here = $this->book('11:00');
        $there = $this->book('13:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);

        $poll = function (int $locationId) use ($token, $cursor): array {
            $this->app['auth']->forgetGuards();

            return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
                ->getJson('/api/notifications/live?' . http_build_query(['location_id' => $locationId, 'after' => $cursor]))
                ->assertOk()
                ->json('data.items');
        };

        $this->assertSame([$here->id], array_column($poll($this->location->id), 'id'));
        $this->assertSame([], $poll($this->otherLocation->id));

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson('/api/staff-locations/active', ['location_id' => $this->otherLocation->id])
            ->assertOk();

        $this->assertSame([$there->id], array_column($poll($this->otherLocation->id), 'id'));
        $this->assertSame([], $poll($this->location->id));
    }

    public function test_a_company_admin_sees_every_company_location_but_never_another_company(): void
    {
        $cursor = 'b' . (int) Booking::withTrashed()->max('id') . '.p0';
        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $here = $this->book('11:00');
        $there = $this->book('13:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);

        $rivalCompany = Company::create([
            'company_name' => 'Other Arcade',
            'email' => 'owner@other.test',
            'phone' => '5559990000',
            'address' => '9 Elsewhere Rd',
        ]);
        $rivalLocation = Location::create([
            'company_id' => $rivalCompany->id,
            'name' => 'Rival | Arcade',
            'address' => '9 Elsewhere Rd',
            'city' => 'Lansing',
            'state' => 'MI',
            'zip_code' => '48901',
            'phone' => '5175550000',
            'email' => 'rival@other.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $rivalPackage = $this->makePackage($rivalLocation, 'Rival Party');
        $this->book('15:00', ['package_id' => $rivalPackage->id, 'location_id' => $rivalLocation->id, 'room_id' => null]);
        $ticketsThere = $this->buyTickets($this->attractionAt($this->otherLocation), 'canton.tickets@example.com');
        $this->buyTickets($this->attractionAt($rivalLocation), 'rival.tickets@example.com');

        $this->actingAs($this->staff('company_admin'), 'sanctum');

        $this->assertSame(
            [['booking', $here->id], ['booking', $there->id], ['attraction_purchase', $ticketsThere->id]],
            array_map(fn (array $item) => [$item['type'], $item['id']], $this->live(['after' => $cursor])['items'])
        );
        $this->assertSame([], $this->live(['location_id' => $rivalLocation->id, 'after' => $cursor])['items']);
    }

    private function makeLocation(string $name, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => $email,
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makePackage(Location $location, string $name): Package
    {
        $package = Package::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => 'Test package',
            'category' => 'Party',
            'price' => 30,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'daily',
            'day_configuration' => [],
            'time_slot_start' => '11:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'priority' => 0,
            'is_active' => true,
        ]);

        return $package;
    }

    private function book(string $time, array $overrides = []): Booking
    {
        $id = (int) $this->postJson('/api/bookings', array_merge([
            'guest_name' => 'Guest ' . $time,
            'guest_email' => 'guest' . str_replace(':', '', $time) . '@example.com',
            'guest_phone' => '6165550000',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => now()->addDays(3)->toDateString(),
            'booking_time' => $time,
            'participants' => 2,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 30,
            'amount_paid' => 0,
            'payment_method' => 'authorize.net',
            'send_email' => false,
        ], $overrides))->assertStatus(201)->json('data.id');

        return Booking::findOrFail($id);
    }

    private function buyTickets(Attraction $attraction, string $email): AttractionPurchase
    {
        $id = (int) $this->postJson('/api/attraction-purchases', [
            'attraction_id' => $attraction->id,
            'guest_name' => 'Ticket Guest',
            'guest_email' => $email,
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 20,
            'purchase_date' => now()->toDateString(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '18:00',
            'payment_method' => 'authorize.net',
        ])->assertStatus(201)->json('data.id');

        return AttractionPurchase::findOrFail($id);
    }

    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => $role . '.' . uniqid() . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function live(array $query): array
    {
        $this->freshControllers();

        return $this->getJson('/api/notifications/live?' . http_build_query($query))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');
    }

    private function attractionHere(): Attraction
    {
        return $this->attractionAt($this->location);
    }

    private function attractionAt(Location $location): Attraction
    {
        return Attraction::create([
            'location_id' => $location->id,
            'name' => 'Laser Tag',
            'description' => 'Tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function freshControllers(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    private function frames(string $body): array
    {
        return array_values(array_filter(array_map('trim', explode("\n\n", $body))));
    }

    private function dataFrames(string $body): array
    {
        $frames = [];

        foreach ($this->frames($body) as $frame) {
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $frames[] = json_decode(substr($line, 6), true);
                }
            }
        }

        return $frames;
    }
}
