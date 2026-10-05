<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
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

    public function test_a_fresh_notification_feed_sends_nothing_old_and_finishes_at_once(): void
    {
        $this->book('11:00');
        $this->book('13:00');
        $latest = Booking::withTrashed()->max('id');

        $response = $this->get("/api/stream/notifications?location_id={$this->location->id}");
        $startedAt = microtime(true);
        $body = $response->streamedContent();
        $elapsed = microtime(true) - $startedAt;

        $response->assertOk();
        $this->assertStringStartsWith("retry: 20000\n\n", $body);
        $this->assertSame([], $this->notificationEvents($body));
        $this->assertSame("b{$latest}.p0", $this->cursorOf($body));
        $this->assertLessThan(3.0, $elapsed);
    }

    public function test_the_notification_feed_sends_only_what_arrived_after_the_cursor_and_only_once(): void
    {
        $this->book('11:00');
        $cursor = $this->cursorOf($this->get("/api/stream/notifications?location_id={$this->location->id}")->streamedContent());

        $new = $this->book('13:00');
        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $this->book('15:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);
        $newTickets = $this->buyTickets($this->attractionHere(), 'new.tickets@example.com');

        $body = $this->get("/api/stream/notifications?location_id={$this->location->id}", ['Last-Event-ID' => $cursor])->streamedContent();
        $events = $this->notificationEvents($body);

        $this->assertSame(
            [['booking', $new->id], ['attraction_purchase', $newTickets->id]],
            array_map(fn (array $event) => [$event['type'], $event['id']], $events)
        );
        $this->assertSame(
            ['id', 'type', 'reference_number', 'customer_name', 'package_name', 'location_name', 'booking_date', 'booking_time', 'status', 'total_amount', 'created_at', 'timestamp', 'user_id'],
            array_keys($events[0])
        );
        $this->assertSame($new->reference_number, $events[0]['reference_number']);
        $this->assertSame('Arcade Party', $events[0]['package_name']);
        $this->assertSame('Laser Tag', $events[1]['attraction_name']);

        $next = $this->cursorOf($body);
        $this->assertSame("b{$new->id}.p{$newTickets->id}", $next);
        $this->assertSame([], $this->notificationEvents(
            $this->get("/api/stream/notifications?location_id={$this->location->id}", ['Last-Event-ID' => $next])->streamedContent()
        ));
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

        $events = $this->notificationEvents(
            $this->get("/api/stream/notifications?location_id={$this->location->id}", ['Last-Event-ID' => 'b0.p0'])->streamedContent()
        );

        $this->assertSame(array_slice($ids, -20), array_column($events, 'id'));
    }

    public function test_an_unrecognised_cursor_is_treated_as_a_fresh_connection(): void
    {
        $this->book('11:00');

        foreach (['booking_1', 'purchase_7', 'b1.p', 'garbage', 'b99999999999999999999999.p1'] as $header) {
            $body = $this->get("/api/stream/notifications?location_id={$this->location->id}", ['Last-Event-ID' => $header])->streamedContent();

            $this->assertSame([], $this->notificationEvents($body), $header);
            $this->assertSame('b' . Booking::withTrashed()->max('id') . '.p0', $this->cursorOf($body), $header);
        }
    }

    public function test_the_notification_feed_leaves_out_the_viewers_own_bookings_when_asked(): void
    {
        $staff = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff, 'sanctum');
        $own = $this->book('11:00', ['payment_method' => 'in-store', 'amount_paid' => 30, 'created_by' => $staff->id]);
        $this->app['auth']->forgetGuards();
        $guest = $this->book('13:00');

        $this->assertSame($staff->id, (int) $own->created_by);

        $events = $this->notificationEvents(
            $this->get("/api/stream/notifications?location_id={$this->location->id}&user_id={$staff->id}", ['Last-Event-ID' => 'b' . ($own->id - 1) . '.p0'])->streamedContent()
        );

        $this->assertSame([$guest->id], array_column($events, 'id'));
    }

    public function test_the_notification_feed_without_a_location_covers_every_location(): void
    {
        $cursor = $this->cursorOf($this->get('/api/stream/notifications')->streamedContent());

        $elsewhere = $this->makePackage($this->otherLocation, 'Canton Party');
        $here = $this->book('11:00');
        $there = $this->book('13:00', ['package_id' => $elsewhere->id, 'location_id' => $this->otherLocation->id, 'room_id' => null]);

        $events = $this->notificationEvents($this->get('/api/stream/notifications', ['Last-Event-ID' => $cursor])->streamedContent());

        $this->assertSame([$here->id, $there->id], array_column($events, 'id'));
    }

    public function test_the_unused_stream_routes_are_gone(): void
    {
        $this->get('/api/stream/bookings')->assertNotFound();
        $this->get('/api/stream/attraction-purchases')->assertNotFound();
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

    private function attractionHere(): Attraction
    {
        return Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function cursorOf(string $body): ?string
    {
        $cursor = null;

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'id: ')) {
                $cursor = substr($line, 4);
            }
        }

        return $cursor;
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

    private function notificationEvents(string $body): array
    {
        return array_values(array_map(
            fn (string $frame) => $this->dataFrames($frame)[0],
            array_filter($this->frames($body), fn (string $frame) => in_array('event: notification', explode("\n", $frame), true))
        ));
    }
}
