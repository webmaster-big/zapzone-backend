<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\PackageTimeSlot;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Services\Schedule\ScheduleDayWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AreaSimultaneousStartsTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $manager;

    private string $date;

    private int $guests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
            'checkout.enforce_charge_within_due' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Grouped Spaces Co',
            'email' => 'owner@grouped.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $company->id,
            'name' => 'Grouped Venue',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'venue@grouped.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->manager = User::create([
            'first_name' => 'Grouped',
            'last_name' => 'Admin',
            'email' => 'admin@grouped.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);
        $this->date = Carbon::now('America/Detroit')->addWeeks(2)->next(Carbon::SUNDAY)->toDateString();
    }

    private function freshRequest(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function asGuest(): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function asManager(): static
    {
        $this->freshRequest();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->manager->createToken('grouped')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function oneStartEach(string $room): array
    {
        $closed = [
            'A' => [['18:00', '18:30'], ['20:15', '23:00']],
            'B' => [['18:00', '18:45'], ['20:30', '23:00']],
            'C' => [['18:00', '19:00'], ['20:45', '23:00']],
        ][$room];

        return array_map(fn ($window) => ['days' => ['sunday'], 'start_time' => $window[0], 'end_time' => $window[1]], $closed);
    }

    private function venue(bool $startsTogether, int|array $turnaround = 30, bool $oneStartEach = true, ?int $cap = null): array
    {
        $rooms = [];

        foreach (['A', 'B', 'C'] as $name) {
            $rooms[$name] = Room::create([
                'location_id' => $this->location->id,
                'name' => 'Room ' . $name,
                'capacity' => 40,
                'is_available' => true,
                'area_group' => 'Party Rooms',
                'booking_interval' => is_array($turnaround) ? $turnaround[$name] : $turnaround,
                'area_allows_simultaneous_starts' => $startsTogether,
                'break_time' => $oneStartEach ? $this->oneStartEach($name) : [],
            ]);
        }

        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Party',
            'description' => 'Party',
            'category' => 'Party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'max_tickets_per_slot' => $cap,
            'duration' => 105,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'weekly',
            'day_configuration' => ['sunday'],
            'time_slot_start' => '18:00',
            'time_slot_end' => '23:00',
            'time_slot_interval' => 15,
            'priority' => 0,
            'is_active' => true,
        ]);

        $package->rooms()->sync(collect($rooms)->pluck('id')->all());

        return [$package, $rooms];
    }

    private function offered(Package $package): array
    {
        $this->freshRequest();
        $stream = $this->get('/api/package-time-slots/available-slots/' . $package->id . '/' . $this->date)->streamedContent();
        $line = collect(explode("\n", $stream))->first(fn ($candidate) => str_starts_with($candidate, 'data: '));
        $this->assertNotNull($line, 'the start-time stream sent no data');
        $payload = json_decode(substr((string) $line, 6), true);
        $this->assertIsArray($payload['available_slots'] ?? null, 'the start-time stream reported an error: ' . $line);

        return collect($payload['available_slots'])->keyBy('start_time')->all();
    }

    private function book(Package $package, Room $room, string $time, int $participants = 1): TestResponse
    {
        $this->guests++;

        return $this->asGuest()->postJson('/api/bookings', [
            'guest_name' => 'Guest ' . $this->guests,
            'guest_email' => 'guest' . $this->guests . '@example.com',
            'guest_phone' => '6165550000',
            'package_id' => $package->id,
            'location_id' => $this->location->id,
            'room_id' => $room->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => $time,
            'participants' => $participants,
            'duration' => 105,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'send_email' => false,
        ]);
    }

    private function bookRoomB(Package $package, Room $roomB, int $participants = 1): int
    {
        return (int) $this->book($package, $roomB, '18:45', $participants)->assertCreated()->json('data.id');
    }

    private function slotRoomOf(TestResponse $response): ?int
    {
        return PackageTimeSlot::where('booking_id', $response->json('data.id'))->value('room_id');
    }

    public function test_the_handoff_starts_stay_offered_when_the_group_lets_spaces_start_together(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);

        $before = $this->offered($package);
        $this->assertSame($rooms['A']->id, $before['18:30']['room_id']);
        $this->assertSame($rooms['B']->id, $before['18:45']['room_id']);
        $this->assertSame($rooms['C']->id, $before['19:00']['room_id']);

        $this->bookRoomB($package, $rooms['B']);

        $after = $this->offered($package);
        $this->assertArrayHasKey('18:30', $after, 'Room A can still host 6:30');
        $this->assertSame($rooms['A']->id, $after['18:30']['room_id']);
        $this->assertArrayNotHasKey('18:45', $after, 'only Room B could host 6:45 and it is booked');
        $this->assertArrayHasKey('19:00', $after, 'Room C can still host 7:00');
        $this->assertSame($rooms['C']->id, $after['19:00']['room_id']);
    }

    public function test_checkout_puts_the_half_past_six_start_in_room_a(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);
        $this->bookRoomB($package, $rooms['B']);

        $slot = $this->offered($package)['18:30'];
        $response = $this->book($package, Room::find($slot['room_id']), $slot['start_time']);

        $response->assertCreated();
        $this->assertSame($rooms['A']->id, $this->slotRoomOf($response));
    }

    public function test_checkout_puts_the_seven_oclock_start_in_room_c(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);
        $this->bookRoomB($package, $rooms['B']);

        $slot = $this->offered($package)['19:00'];
        $response = $this->book($package, Room::find($slot['room_id']), $slot['start_time']);

        $response->assertCreated();
        $this->assertSame($rooms['C']->id, $this->slotRoomOf($response));
    }

    public function test_a_group_that_keeps_starts_apart_still_hides_them(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false);
        $this->bookRoomB($package, $rooms['B']);

        $after = $this->offered($package);

        $this->assertArrayNotHasKey('18:30', $after);
        $this->assertArrayNotHasKey('18:45', $after);
        $this->assertArrayNotHasKey('19:00', $after);
    }

    public function test_spaces_in_the_group_can_start_at_the_same_minute(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true, oneStartEach: false);
        $this->bookRoomB($package, $rooms['B']);

        $slot = $this->offered($package)['18:45'] ?? null;

        $this->assertNotNull($slot, 'Rooms A and C are free at 6:45');
        $this->assertEqualsCanonicalizing([$rooms['A']->id, $rooms['C']->id], $slot['available_room_ids']);
        $this->book($package, $rooms['A'], '18:45')->assertCreated();
    }

    public function test_a_real_clash_in_the_same_space_still_blocks(): void
    {
        config(['booking_rules.slot_conflict' => 'enforce']);
        [$package, $rooms] = $this->venue(startsTogether: true, oneStartEach: false);
        $this->bookRoomB($package, $rooms['B']);

        foreach ($this->offered($package) as $start => $slot) {
            if ($start > '17:00' && $start < '20:30') {
                $this->assertNotContains($rooms['B']->id, $slot['available_room_ids'], "Room B is booked at {$start}");
            }
        }

        $this->book($package, $rooms['B'], '19:30')->assertStatus(409);
        $this->assertSame(1, PackageTimeSlot::where('room_id', $rooms['B']->id)->where('status', 'booked')->count());
    }

    public function test_each_space_still_waits_out_its_own_turnaround(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true, turnaround: 30, oneStartEach: false);
        $this->bookRoomB($package, $rooms['B']);

        $offered = $this->offered($package);

        $this->assertNotContains($rooms['B']->id, $offered['20:45']['available_room_ids'], '8:30 end plus 30 min turnaround');
        $this->assertContains($rooms['B']->id, $offered['21:00']['available_room_ids']);
    }

    public function test_used_up_shared_capacity_still_blocks_with_the_group_active(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true, oneStartEach: false, cap: 10);
        $this->bookRoomB($package, $rooms['B'], 10);

        $after = $this->offered($package);

        $this->assertArrayNotHasKey('18:30', $after);
        $this->assertArrayNotHasKey('19:00', $after);
        $refused = $this->book($package, $rooms['A'], '18:30')->assertStatus(422);
        $this->assertStringContainsString('sold out', (string) $refused->json('message'));
    }

    public function test_cancelling_the_booking_brings_the_hidden_starts_back(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false);
        $booking = $this->bookRoomB($package, $rooms['B']);
        $this->assertArrayNotHasKey('18:30', $this->offered($package));

        $this->asManager()->patchJson('/api/bookings/' . $booking . '/cancel', ['change_reason' => 'Guest called to cancel'])->assertOk();

        $after = $this->offered($package);
        $this->assertArrayHasKey('18:30', $after);
        $this->assertArrayHasKey('18:45', $after);
        $this->assertArrayHasKey('19:00', $after);
    }

    public function test_moving_the_booking_recalculates_the_starts(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false, oneStartEach: false);
        $booking = $this->bookRoomB($package, $rooms['B']);
        $this->assertArrayNotHasKey('18:30', $this->offered($package));

        $this->asManager()->patchJson('/api/bookings/' . $booking, [
            'booking_time' => '20:15',
            'change_reason' => 'Guest asked for a later start',
        ])->assertOk();

        $this->assertSame('20:15', substr((string) PackageTimeSlot::where('booking_id', $booking)->value('time_slot_start'), 0, 5));
        $after = $this->offered($package);
        $this->assertArrayHasKey('18:30', $after);
        $this->assertArrayHasKey('19:00', $after);
        $this->assertArrayNotHasKey('20:00', $after, 'within 30 min of the moved start');
    }

    private function cashPaymentFor(int $bookingId): Payment
    {
        return Payment::create([
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $bookingId,
            'transaction_id' => 'CASH-' . $bookingId,
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'cash',
            'status' => 'completed',
            'location_id' => $this->location->id,
        ]);
    }

    public function test_a_refund_that_cancels_the_booking_brings_the_hidden_starts_back(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false);
        $booking = $this->bookRoomB($package, $rooms['B']);
        $payment = $this->cashPaymentFor($booking);
        $this->assertArrayNotHasKey('18:30', $this->offered($package));

        $this->asManager()->patchJson('/api/payments/' . $payment->id . '/manual-refund', [
            'notes' => 'Guest cancelled by phone',
            'cancel' => true,
        ])->assertOk();

        $this->assertSame('cancelled', Booking::find($booking)->status);
        $this->assertSame('cancelled', PackageTimeSlot::where('booking_id', $booking)->value('status'));
        $after = $this->offered($package);
        $this->assertArrayHasKey('18:30', $after);
        $this->assertArrayHasKey('18:45', $after);
        $this->assertArrayHasKey('19:00', $after);
    }

    public function test_a_partial_refund_that_keeps_the_booking_keeps_its_space(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false);
        $booking = $this->bookRoomB($package, $rooms['B']);
        $payment = $this->cashPaymentFor($booking);

        $this->asManager()->patchJson('/api/payments/' . $payment->id . '/manual-refund', [
            'amount' => 20,
            'notes' => 'Goodwill discount',
            'cancel' => false,
        ])->assertOk();

        $this->assertSame('booked', PackageTimeSlot::where('booking_id', $booking)->value('status'));
        $this->assertArrayNotHasKey('18:45', $this->offered($package));
    }

    public function test_one_space_that_keeps_starts_apart_keeps_the_whole_group_apart(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);
        Room::create([
            'location_id' => $this->location->id,
            'name' => 'Room D',
            'capacity' => 40,
            'is_available' => false,
            'area_group' => 'Party Rooms',
            'booking_interval' => 30,
            'area_allows_simultaneous_starts' => false,
        ]);
        $this->bookRoomB($package, $rooms['B']);

        $this->assertArrayNotHasKey('18:30', $this->offered($package));
    }

    public function test_the_staff_schedule_agrees_with_the_booking_site(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);
        $this->bookRoomB($package, $rooms['B']);

        $window = app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date);
        $entry = collect($window['packages'])->firstWhere('package_id', $package->id);
        $spaces = collect($window['rooms'])->keyBy('room_id');

        $this->assertContains(18 * 60 + 30, $entry['start_minutes']);
        $this->assertContains(19 * 60, $entry['start_minutes']);
        $this->assertNotContains(18 * 60 + 45, $entry['start_minutes']);
        foreach ($rooms as $room) {
            $this->assertSame(0, $spaces[$room->id]['stagger_minutes']);
            $this->assertSame(30, $spaces[$room->id]['interval_minutes']);
        }
    }

    public function test_the_staff_schedule_still_shows_the_spacing_for_a_group_that_keeps_starts_apart(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);

        $spaces = collect(app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date)['rooms'])->keyBy('room_id');

        $this->assertSame(30, $spaces[$rooms['A']->id]['stagger_minutes']);
    }

    public function test_area_settings_turn_starting_together_on_for_every_space_in_the_group(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'booking_interval' => 20,
            'area_allows_simultaneous_starts' => true,
            'location_id' => $this->location->id,
        ])->assertOk()->assertJsonPath('data.updated_count', 3);

        foreach ($rooms as $room) {
            $room->refresh();
            $this->assertTrue($room->area_allows_simultaneous_starts);
            $this->assertSame(20, $room->booking_interval);
        }
    }

    public function test_area_settings_sent_without_the_new_field_leave_it_alone(): void
    {
        [, $rooms] = $this->venue(startsTogether: true);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'booking_interval' => 45,
            'location_id' => $this->location->id,
        ])->assertOk();

        $this->assertTrue($rooms['A']->fresh()->area_allows_simultaneous_starts);
        $this->assertSame(45, $rooms['A']->fresh()->booking_interval);
    }

    public function test_a_new_space_takes_its_groups_setting(): void
    {
        $this->venue(startsTogether: true);

        $joined = $this->asManager()->postJson('/api/rooms', [
            'location_id' => $this->location->id,
            'name' => 'Room E',
            'area_group' => 'Party Rooms',
            'booking_interval' => 30,
        ])->assertCreated()->json('data.id');

        $alone = $this->asManager()->postJson('/api/rooms', [
            'location_id' => $this->location->id,
            'name' => 'Room F',
            'area_group' => 'Brand New Area',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(Room::find($joined)->area_allows_simultaneous_starts);
        $this->assertFalse(Room::find($alone)->area_allows_simultaneous_starts);
    }

    public function test_a_space_moved_into_a_group_takes_that_groups_setting(): void
    {
        $this->venue(startsTogether: false);
        $mover = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Room G',
            'is_available' => true,
            'area_group' => 'Arcade',
            'booking_interval' => 15,
            'area_allows_simultaneous_starts' => true,
        ]);

        $this->asManager()->putJson('/api/rooms/' . $mover->id, ['area_group' => 'Party Rooms'])->assertOk();
        $this->assertFalse($mover->fresh()->area_allows_simultaneous_starts);

        $mover->refresh()->update(['area_allows_simultaneous_starts' => true]);
        $this->asManager()->putJson('/api/rooms/' . $mover->id, ['name' => 'Room G2', 'area_group' => 'Party Rooms'])->assertOk();
        $this->assertTrue($mover->fresh()->area_allows_simultaneous_starts, 'renaming a space inside its own group leaves the setting alone');
    }

    private function secondVenue(): Location
    {
        return Location::create([
            'company_id' => $this->location->company_id,
            'name' => 'Second Venue',
            'address' => '2 Test Way',
            'city' => 'Canton',
            'state' => 'MI',
            'zip_code' => '48187',
            'phone' => '7345551234',
            'email' => 'second@grouped.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    public function test_the_largest_turnaround_in_a_group_still_sets_its_spacing(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false, turnaround: ['A' => 15, 'B' => 30, 'C' => 45], oneStartEach: false);
        $this->bookRoomB($package, $rooms['B']);

        $offered = $this->offered($package);

        $this->assertArrayHasKey('18:00', $offered, '45 min before 6:45 is allowed');
        $this->assertArrayNotHasKey('18:15', $offered, 'inside the group spacing of 45');
        $this->assertArrayNotHasKey('19:15', $offered, 'inside the group spacing of 45');
        $this->assertArrayHasKey('19:30', $offered, '45 min after 6:45 is allowed');
    }

    public function test_each_space_keeps_its_own_turnaround_when_the_group_starts_together(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true, turnaround: ['A' => 15, 'B' => 30, 'C' => 45], oneStartEach: false);
        $this->book($package, $rooms['A'], '18:00')->assertCreated();
        $this->book($package, $rooms['C'], '18:00')->assertCreated();

        $offered = $this->offered($package);

        $this->assertContains($rooms['A']->id, $offered['20:00']['available_room_ids'], '7:45 end plus A\'s 15 min');
        $this->assertNotContains($rooms['C']->id, $offered['20:00']['available_room_ids'], '7:45 end plus C\'s 45 min');
        $this->assertContains($rooms['C']->id, $offered['20:30']['available_room_ids']);
    }

    public function test_turning_on_starting_together_leaves_each_spaces_turnaround_alone(): void
    {
        [, $rooms] = $this->venue(startsTogether: false, turnaround: ['A' => 15, 'B' => 30, 'C' => 45]);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'area_allows_simultaneous_starts' => true,
            'location_id' => $this->location->id,
        ])->assertOk();

        $this->assertSame([15, 30, 45], collect($rooms)->map(fn ($room) => $room->fresh()->booking_interval)->values()->all());
        $this->assertTrue(collect($rooms)->every(fn ($room) => $room->fresh()->area_allows_simultaneous_starts));
    }

    public function test_area_settings_without_a_location_cover_every_venue_in_the_company(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);
        $elsewhere = Room::create([
            'location_id' => $this->secondVenue()->id,
            'name' => 'Room Z',
            'is_available' => true,
            'area_group' => 'Party Rooms',
            'booking_interval' => 15,
        ]);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'area_allows_simultaneous_starts' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 4);

        $this->assertTrue($elsewhere->fresh()->area_allows_simultaneous_starts);
        $this->assertTrue($rooms['A']->fresh()->area_allows_simultaneous_starts);
    }

    public function test_an_empty_turnaround_is_refused_instead_of_becoming_zero(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'booking_interval' => '',
            'area_allows_simultaneous_starts' => true,
            'location_id' => $this->location->id,
        ])->assertStatus(422);

        $this->assertSame(30, $rooms['A']->fresh()->booking_interval);
        $this->assertFalse($rooms['A']->fresh()->area_allows_simultaneous_starts);
    }

    public function test_a_space_moved_into_a_brand_new_area_starts_with_the_setting_off(): void
    {
        [, $rooms] = $this->venue(startsTogether: true);

        $this->asManager()->putJson('/api/rooms/' . $rooms['C']->id, ['area_group' => 'Laser Tag'])->assertOk();
        $joined = $this->asManager()->postJson('/api/rooms', [
            'location_id' => $this->location->id,
            'name' => 'Laser Arena 2',
            'area_group' => 'Laser Tag',
        ])->assertCreated()->json('data.id');

        $this->assertFalse($rooms['C']->fresh()->area_allows_simultaneous_starts);
        $this->assertFalse(Room::find($joined)->area_allows_simultaneous_starts);
        $this->assertTrue($rooms['A']->fresh()->area_allows_simultaneous_starts, 'the spaces left behind keep their setting');
    }

    public function test_an_empty_start_together_setting_is_refused(): void
    {
        [, $rooms] = $this->venue(startsTogether: true);

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'booking_interval' => 30,
            'area_allows_simultaneous_starts' => '',
            'location_id' => $this->location->id,
        ])->assertStatus(422);
        $this->asManager()->putJson('/api/rooms/' . $rooms['A']->id, ['area_allows_simultaneous_starts' => ''])->assertStatus(422);

        $this->assertTrue($rooms['A']->fresh()->area_allows_simultaneous_starts);
    }

    public function test_a_space_moved_to_another_venue_takes_that_venues_setting(): void
    {
        [, $rooms] = $this->venue(startsTogether: true);
        $second = $this->secondVenue();
        Room::create([
            'location_id' => $second->id,
            'name' => 'Room Z',
            'is_available' => true,
            'area_group' => 'Party Rooms',
            'booking_interval' => 15,
            'area_allows_simultaneous_starts' => false,
        ]);

        $this->asManager()->putJson('/api/rooms/' . $rooms['A']->id, ['location_id' => $second->id])->assertOk();

        $this->assertSame($second->id, $rooms['A']->fresh()->location_id);
        $this->assertFalse($rooms['A']->fresh()->area_allows_simultaneous_starts);
    }

    public function test_spaces_still_held_by_cancelled_bookings_are_released_once(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: false);
        $cancelled = $this->bookRoomB($package, $rooms['B']);
        Booking::whereKey($cancelled)->update(['status' => 'cancelled']);
        $live = (int) $this->book($package, $rooms['C'], '19:00')->assertCreated()->json('data.id');
        $this->assertArrayNotHasKey('18:30', $this->offered($package));

        $past = Booking::create([
            'reference_number' => 'PAST-CANCELLED',
            'booking_date' => Carbon::now('America/Detroit')->subDays(10)->toDateString(),
            'booking_time' => '18:45',
            'duration' => 105,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'room_id' => $rooms['B']->id,
            'participants' => 1,
            'total_amount' => 0,
            'status' => 'cancelled',
        ]);
        PackageTimeSlot::create([
            'package_id' => $package->id,
            'room_id' => $rooms['B']->id,
            'booking_id' => $past->id,
            'booked_date' => $past->booking_date,
            'time_slot_start' => '18:45',
            'duration' => 105,
            'duration_unit' => 'minutes',
            'status' => 'booked',
        ]);

        $migration = require database_path('migrations/2026_11_25_000002_release_spaces_held_by_cancelled_bookings.php');
        $migration->up();
        $migration->up();

        $this->assertSame('cancelled', PackageTimeSlot::where('booking_id', $cancelled)->value('status'));
        $this->assertSame('booked', PackageTimeSlot::where('booking_id', $live)->value('status'), 'a live booking keeps its space');
        $this->assertSame('booked', PackageTimeSlot::where('booking_id', $past->id)->value('status'), 'past dates are left alone');
        $this->assertArrayHasKey('18:30', $this->offered($package));
    }

    private function staffAt(Location $location, string $role): User
    {
        $this->guests++;

        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . $this->guests . '@grouped.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
        ]);
    }

    private function as(User $user): static
    {
        $this->freshRequest();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('grouped-role')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function partyRoomAt(Location $location): Room
    {
        return Room::create([
            'location_id' => $location->id,
            'name' => 'Room Z',
            'is_available' => true,
            'area_group' => 'Party Rooms',
            'booking_interval' => 15,
        ]);
    }

    public function test_a_location_manager_turns_it_on_for_their_own_location_only(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);
        $elsewhere = $this->partyRoomAt($this->secondVenue());

        $this->as($this->staffAt($this->location, 'location_manager'))
            ->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
                'area_allows_simultaneous_starts' => true,
            ])->assertOk()->assertJsonPath('data.updated_count', 3);

        $this->assertTrue($rooms['A']->fresh()->area_allows_simultaneous_starts);
        $this->assertFalse($elsewhere->fresh()->area_allows_simultaneous_starts, 'another venue with the same group name is left alone');
    }

    public function test_a_location_manager_cannot_change_another_locations_group(): void
    {
        $this->venue(startsTogether: false);
        $second = $this->secondVenue();
        $elsewhere = $this->partyRoomAt($second);

        $this->as($this->staffAt($this->location, 'location_manager'))
            ->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
                'area_allows_simultaneous_starts' => true,
                'location_id' => $second->id,
            ])->assertForbidden();

        $this->assertFalse($elsewhere->fresh()->area_allows_simultaneous_starts);
    }

    public function test_an_attendant_is_held_to_their_own_location_too(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);
        $elsewhere = $this->partyRoomAt($this->secondVenue());

        $this->as($this->staffAt($this->location, 'attendant'))
            ->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
                'area_allows_simultaneous_starts' => true,
            ])->assertOk()->assertJsonPath('data.updated_count', 3);

        $this->assertTrue($rooms['B']->fresh()->area_allows_simultaneous_starts);
        $this->assertFalse($elsewhere->fresh()->area_allows_simultaneous_starts);
    }

    public function test_a_company_admin_who_picks_a_location_changes_only_that_location(): void
    {
        [, $rooms] = $this->venue(startsTogether: false);
        $elsewhere = $this->partyRoomAt($this->secondVenue());

        $this->asManager()->patchJson('/api/rooms/area-group/' . rawurlencode('Party Rooms') . '/update-booking-interval', [
            'area_allows_simultaneous_starts' => true,
            'location_id' => $this->location->id,
        ])->assertOk()->assertJsonPath('data.updated_count', 3);

        $this->assertTrue($rooms['C']->fresh()->area_allows_simultaneous_starts);
        $this->assertFalse($elsewhere->fresh()->area_allows_simultaneous_starts);
    }

    public function test_a_location_managers_day_schedule_follows_the_setting(): void
    {
        [$package, $rooms] = $this->venue(startsTogether: true);
        $this->bookRoomB($package, $rooms['B']);
        $manager = $this->staffAt($this->location, 'location_manager');

        $window = $this->as($manager)->getJson('/api/schedule/day-window?date=' . $this->date)->assertOk()->json('data');
        $entry = collect($window['packages'])->firstWhere('package_id', $package->id);
        $spaces = collect($window['rooms'])->keyBy('room_id');

        $this->assertSame($this->location->id, $window['location_id']);
        $this->assertContains(18 * 60 + 30, $entry['start_minutes']);
        $this->assertContains(19 * 60, $entry['start_minutes']);
        $this->assertSame(0, $spaces[$rooms['A']->id]['stagger_minutes']);
    }

    private function moveSlotNextToRoomB(bool $startsTogether): array
    {
        [$package, $rooms] = $this->venue(startsTogether: $startsTogether, oneStartEach: false);
        $this->bookRoomB($package, $rooms['B']);
        $partyInA = (int) $this->book($package, $rooms['A'], '19:15')->assertCreated()->json('data.id');
        $slot = PackageTimeSlot::where('booking_id', $partyInA)->firstOrFail();

        $response = $this->asManager()->patchJson('/api/package-time-slots/' . $slot->id, [
            'booked_date' => $this->date,
            'time_slot_start' => '18:45',
            'change_reason' => 'Guests asked to start with the other party',
        ]);

        return [$response, $slot];
    }

    public function test_staff_can_move_a_slot_to_a_neighbours_start_when_the_group_starts_together(): void
    {
        [$response, $slot] = $this->moveSlotNextToRoomB(true);

        $response->assertOk();
        $this->assertSame('18:45', substr((string) $slot->fresh()->time_slot_start, 0, 5));
    }

    public function test_moving_a_slot_next_to_a_neighbour_is_still_refused_when_the_group_keeps_starts_apart(): void
    {
        [$response, $slot] = $this->moveSlotNextToRoomB(false);

        $response->assertStatus(422);
        $this->assertSame('19:15', substr((string) $slot->fresh()->time_slot_start, 0, 5));
    }
}
