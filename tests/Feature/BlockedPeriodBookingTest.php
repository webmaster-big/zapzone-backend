<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\Booking;
use App\Models\Company;
use App\Models\DayOff;
use App\Models\DayOffChangeLog;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Room;
use App\Models\User;
use App\Services\Schedule\ScheduleDayWindow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedPeriodBookingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Room $room;

    private Package $package;

    private Attraction $attraction;

    private Event $event;

    private User $manager;

    private User $attendant;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = Carbon::today()->addDays(10)->toDateString();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->room = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room 1',
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Basic Birthday',
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
        $this->package->rooms()->sync([$this->room->id]);

        PackageAvailabilitySchedule::create([
            'package_id' => $this->package->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '10:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 120,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $this->event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => $this->date,
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        $this->manager = $this->user('manager', 'location_manager');
        $this->attendant = $this->user('attendant', 'attendant');
    }

    private function user(string $handle, string $role): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'first_name' => ucfirst($handle),
            'last_name' => 'Tester',
            'email' => "{$handle}@zapzone.test",
            'password' => 'Password123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function as(User $user): static
    {
        $token = $user->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json']);
    }

    private function block(array $attributes = []): DayOff
    {
        return DayOff::create(array_merge([
            'location_id' => $this->location->id,
            'date' => $this->date,
            'reason' => 'Private buyout',
            'group_name' => 'Acme Corp Holiday Party',
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ], $attributes));
    }

    private function seen(): array
    {
        return [
            'acknowledge_blocked_period' => true,
            'acknowledged_block_ids' => DayOff::orderBy('id')->pluck('id')->all(),
        ];
    }

    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'guest_phone' => '2485559999',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
        ], $overrides);
    }

    public function test_guests_cannot_book_inside_a_full_day_block(): void
    {
        $this->block();

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed. Please choose another slot.')
            ->assertJsonMissingPath('blocked_periods');

        $this->assertSame(0, Booking::count());
    }

    public function test_guests_book_inside_the_operating_window_but_never_outside_it(): void
    {
        $this->block(['opens_at' => '12:00', 'closes_at' => '18:00']);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '17:00']))
            ->assertStatus(422);
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '10:00']))
            ->assertStatus(422);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '14:00']))
            ->assertSuccessful();

        $this->assertSame(1, Booking::count());
    }

    public function test_staff_are_warned_with_the_block_details(): void
    {
        $this->block(['opens_at' => '12:00', 'closes_at' => '16:00']);

        $response = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '16:00']))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD')
            ->assertJsonPath('requires_acknowledgement', true)
            ->assertJsonPath('blocked_periods.0.group_name', 'Acme Corp Holiday Party')
            ->assertJsonPath('blocked_periods.0.reason', 'Private buyout')
            ->assertJsonPath('blocked_periods.0.scope_label', 'Entire location')
            ->assertJsonPath('blocked_periods.0.window_label', 'Open only 12:00 PM – 4:00 PM')
            ->assertJsonPath('blocked_periods.0.set_by.name', 'Manager Tester');

        $this->assertStringContainsString('Acme Corp Holiday Party', $response->json('message'));
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_staff_who_confirm_the_warning_book_the_group_and_it_is_recorded(): void
    {
        $block = $this->block();

        $response = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload([
            'guest_name' => 'Acme Corp',
        ] + $this->seen()))->assertSuccessful();

        $booking = Booking::sole();
        $this->assertSame($response->json('data.id'), $booking->id);

        $log = DayOffChangeLog::sole();
        $this->assertSame(DayOffChangeLog::ACTION_BOOKED_INSIDE, $log->action);
        $this->assertSame($block->id, (int) $log->day_off_id);
        $this->assertSame($this->attendant->id, (int) $log->changed_by);
        $this->assertSame('booking', $log->context['entity_type']);
        $this->assertSame($booking->id, $log->context['entity_id']);
        $this->assertSame($booking->reference_number, $log->context['reference']);
        $this->assertStringContainsString($booking->reference_number, $log->summary);

        $this->assertSame(1, ActivityLog::where('action', 'Booked Inside Blocked Period')->where('entity_id', $booking->id)->count());
    }

    public function test_a_guest_cannot_skip_the_block_by_claiming_to_have_seen_the_warning(): void
    {
        $this->block();

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload($this->seen()))
            ->assertStatus(422);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_bookings_outside_any_block_are_untouched(): void
    {
        $this->block(['date' => Carbon::parse($this->date)->addDay()->toDateString()]);

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload())->assertSuccessful();
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_room_and_package_blocks_only_catch_their_own_bookings(): void
    {
        $otherRoom = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room 2',
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
        $this->package->rooms()->sync([$this->room->id, $otherRoom->id]);

        $this->block(['room_ids' => [$this->room->id]]);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload())->assertStatus(422);
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['room_id' => $otherRoom->id]))->assertSuccessful();

        DayOff::query()->delete();
        $otherPackage = Package::create([
            'location_id' => $this->location->id,
            'name' => 'VIP Birthday',
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
        $this->block(['package_ids' => [$otherPackage->id]]);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '16:00']))->assertSuccessful();
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload([
            'package_id' => $otherPackage->id,
            'room_id' => null,
            'booking_time' => '18:00',
        ]))->assertStatus(422);
    }

    public function test_moving_a_booking_into_a_block_warns_staff_and_records_the_confirmation(): void
    {
        $booking = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload())->assertSuccessful()->json('data');
        $this->block(['date' => Carbon::parse($this->date)->addDay()->toDateString()]);

        $move = [
            'booking_date' => Carbon::parse($this->date)->addDay()->toDateString(),
            'change_reason' => 'Guest asked to move',
        ];

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", $move)
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');

        $this->assertSame($this->date, Booking::find($booking['id'])->booking_date->toDateString());

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", $move + $this->seen())
            ->assertOk();

        $this->assertSame(Carbon::parse($this->date)->addDay()->toDateString(), Booking::find($booking['id'])->booking_date->toDateString());
        $this->assertSame(1, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
        $this->assertTrue(DayOffChangeLog::sole()->context['moved']);
    }

    public function test_an_added_attraction_that_is_blocked_stops_guests_and_warns_staff(): void
    {
        $this->block(['attraction_ids' => [$this->attraction->id], 'opens_at' => '16:00']);

        $laserTag = ['additional_attractions' => [['attraction_id' => $this->attraction->id, 'quantity' => 2]]];

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload($laserTag))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Laser Tag is not available at that time. Remove it or choose another time.');
        $this->assertSame(0, Booking::count());

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload())->assertSuccessful();
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '16:00'] + $laserTag))->assertSuccessful();

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '10:00'] + $laserTag))
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD')
            ->assertJsonPath('blocked_periods.0.group_name', 'Acme Corp Holiday Party');

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '10:00'] + $laserTag + $this->seen()))
            ->assertSuccessful();

        $this->assertSame(3, Booking::count());
        $this->assertSame(1, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
    }

    public function test_a_blocked_package_keeps_the_general_message_even_with_an_added_attraction(): void
    {
        $this->block();

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload([
            'additional_attractions' => [['attraction_id' => $this->attraction->id, 'quantity' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed. Please choose another slot.');
    }

    public function test_adding_a_blocked_attraction_to_a_booking_warns_staff_but_other_edits_still_save(): void
    {
        $booking = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload())->assertSuccessful()->json('data');
        $this->block(['attraction_ids' => [$this->attraction->id]]);

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", [
            'participants' => 9,
            'change_reason' => 'One more guest',
        ])->assertOk();

        $addLaserTag = [
            'additional_attractions' => [['attraction_id' => $this->attraction->id, 'quantity' => 1]],
            'change_reason' => 'Guest added laser tag',
        ];

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", $addLaserTag)
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertSame(0, Booking::find($booking['id'])->attractions()->count());

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", $addLaserTag + $this->seen())->assertOk();
        $this->assertSame(1, Booking::find($booking['id'])->attractions()->count());

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", [
            'participants' => 10,
            'change_reason' => 'Another guest',
        ])->assertOk();

        $this->as($this->attendant)->putJson("/api/bookings/{$booking['id']}", [
            'booking_time' => '16:00',
            'change_reason' => 'Later start',
        ])->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
    }

    public function test_approving_a_location_change_into_a_block_warns_and_records_it(): void
    {
        $booking = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['room_id' => null]))->assertSuccessful()->json('data');

        $waterford = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Waterford',
            'address' => '2 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->block(['location_id' => $waterford->id, 'group_name' => 'Waterford Buyout']);

        $admin = User::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'first_name' => 'Admin',
            'last_name' => 'Tester',
            'email' => 'admin-approver@zapzone.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);

        $request = \App\Models\LocationChangeRequest::create([
            'booking_id' => $booking['id'],
            'from_location_id' => $this->location->id,
            'to_location_id' => $waterford->id,
            'reason' => 'Guest prefers Waterford',
            'status' => \App\Models\LocationChangeRequest::STATUS_PENDING,
            'requested_by' => $this->attendant->id,
        ]);

        $this->as($admin)->patchJson("/api/location-change-requests/{$request->id}/approve", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD')
            ->assertJsonPath('blocked_periods.0.group_name', 'Waterford Buyout');

        $this->assertSame($this->location->id, (int) Booking::find($booking['id'])->location_id);
        $this->assertSame('pending', $request->fresh()->status);

        $this->as($admin)->patchJson("/api/location-change-requests/{$request->id}/approve", $this->seen())->assertOk();

        $this->assertSame($waterford->id, (int) Booking::find($booking['id'])->location_id);
        $log = DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->sole();
        $this->assertSame($waterford->id, $log->context['moved_to_location_id']);
        $this->assertSame($request->id, $log->context['location_change_request_id']);
    }

    public function test_bringing_a_booking_back_into_a_block_warns_staff_and_is_recorded(): void
    {
        $first = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload())->assertSuccessful()->json('data.id');
        $second = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['booking_time' => '16:00', 'room_id' => null]))->assertSuccessful()->json('data.id');
        Booking::whereKey($first)->update(['status' => 'cancelled']);
        $this->block();

        $reason = ['change_reason' => 'Guest called back'];

        $this->as($this->attendant)->patchJson("/api/bookings/{$first}/status", ['status' => 'confirmed'] + $reason)
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->as($this->attendant)->putJson("/api/bookings/{$first}", ['status' => 'confirmed'] + $reason)
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertSame('cancelled', Booking::find($first)->status);

        $this->as($this->attendant)->patchJson("/api/bookings/{$first}/status", ['status' => 'confirmed'] + $reason + $this->seen())->assertOk();
        $this->assertSame('confirmed', Booking::find($first)->status);
        $this->assertTrue(DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->sole()->context['reactivated']);

        Booking::find($first)->delete();
        $this->as($this->attendant)->postJson("/api/bookings/{$first}/restore", $reason)
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertNull(Booking::find($first));
        $this->as($this->attendant)->postJson("/api/bookings/{$first}/restore", $reason + $this->seen())->assertOk();
        $this->assertNotNull(Booking::find($first));

        Booking::find($first)->delete();
        Booking::find($second)->delete();
        $this->as($this->attendant)->postJson('/api/bookings/bulk-restore', ['ids' => [$first, $second]] + $reason)
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertSame(0, Booking::whereIn('id', [$first, $second])->count());
        $this->as($this->attendant)->postJson('/api/bookings/bulk-restore', ['ids' => [$first, $second]] + $reason + $this->seen())
            ->assertOk()->assertJsonPath('data.restored_count', 2);

        $this->assertSame(4, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
        $this->assertSame(3, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->where('context->restored', true)->count());
    }

    public function test_bringing_tickets_back_into_a_block_warns_staff_and_is_recorded(): void
    {
        $attraction = $this->as($this->attendant)->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '18:00',
            'payment_method' => 'in-store',
        ])->assertCreated()->json('data.id');
        $event = $this->as($this->attendant)->postJson('/api/event-purchases', [
            'event_id' => $this->event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'purchase_date' => $this->date,
            'purchase_time' => '19:00',
            'quantity' => 1,
            'total_amount' => 15,
            'payment_method' => 'in-store',
        ])->assertSuccessful()->json('id');

        \App\Models\AttractionPurchase::whereKey($attraction)->update(['status' => 'cancelled']);
        \App\Models\EventPurchase::whereKey($event)->update(['status' => 'cancelled']);
        $this->block();

        $this->as($this->attendant)->patchJson("/api/attraction-purchases/{$attraction}/update-status", ['status' => 'confirmed'])
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->as($this->attendant)->patchJson("/api/attraction-purchases/{$attraction}/confirm")
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->as($this->attendant)->patchJson("/api/attraction-purchases/{$attraction}/confirm", $this->seen())->assertOk();
        $this->assertSame('confirmed', \App\Models\AttractionPurchase::find($attraction)->status);

        $this->as($this->attendant)->patchJson("/api/event-purchases/{$event}/status", ['status' => 'confirmed'])
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->as($this->attendant)->patchJson("/api/event-purchases/{$event}/status", ['status' => 'confirmed'] + $this->seen())->assertOk();

        \App\Models\AttractionPurchase::find($attraction)->delete();
        \App\Models\EventPurchase::find($event)->delete();

        $this->as($this->attendant)->postJson("/api/attraction-purchases/{$attraction}/restore")->assertStatus(409);
        $this->as($this->attendant)->postJson("/api/event-purchases/{$event}/restore")->assertStatus(409);
        $this->as($this->attendant)->postJson('/api/event-purchases/bulk-restore', ['ids' => [$event]])->assertStatus(409);
        $this->as($this->attendant)->postJson('/api/attraction-purchases/bulk-restore', ['ids' => [$attraction]])->assertStatus(409);

        $this->as($this->attendant)->postJson("/api/attraction-purchases/{$attraction}/restore", $this->seen())->assertOk();
        $this->as($this->attendant)->postJson('/api/event-purchases/bulk-restore', ['ids' => [$event]] + $this->seen())->assertOk();

        $this->assertSame(4, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
    }

    public function test_a_booking_without_a_space_is_blocked_when_every_space_of_its_package_is(): void
    {
        $this->block(['room_ids' => [$this->room->id]]);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['room_id' => null]))
            ->assertStatus(422);

        $otherRoom = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room 2',
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
        $this->package->rooms()->sync([$this->room->id, $otherRoom->id]);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['room_id' => null]))->assertSuccessful();
    }

    public function test_an_event_ticket_without_a_time_is_refused_only_when_every_time_is_blocked(): void
    {
        $cart = ['items' => [[
            'type' => 'event',
            'id' => $this->event->id,
            'quantity' => 1,
            'scheduled_date' => $this->date,
        ]]];

        $closing = $this->block(['closes_at' => '17:00']);

        $this->asGuest()->postJson('/api/ticket-orders/quote', $cart)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Glow Night is closed on the date you picked. Please choose another.');

        $this->as($this->attendant)->postJson('/api/ticket-orders', $cart + [
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'payment_method' => 'in-store',
        ])->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');

        $closing->update(['closes_at' => '20:00']);

        $this->asGuest()->postJson('/api/ticket-orders/quote', $cart)->assertOk();
    }

    public function test_an_untimed_event_ticket_is_not_read_as_a_midnight_visit(): void
    {
        $ticket = \App\Models\EventPurchase::create([
            'reference_number' => 'EVT-UNTIMED-1',
            'event_id' => $this->event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'purchase_date' => $this->date,
            'purchase_time' => '00:00',
            'quantity' => 1,
            'unit_price' => 15,
            'total_amount' => 15,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        $ticket->delete();
        $this->block(['opens_at' => '12:00']);

        $this->as($this->attendant)->postJson("/api/event-purchases/{$ticket->id}/restore")->assertOk();
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_an_account_without_a_company_is_treated_like_a_guest(): void
    {
        $this->block();

        $loose = User::create([
            'company_id' => null,
            'location_id' => null,
            'first_name' => 'Loose',
            'last_name' => 'Account',
            'email' => 'loose@zapzone.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);

        $this->as($loose)->postJson('/api/bookings', $this->bookingPayload($this->seen()))
            ->assertStatus(422);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, DayOffChangeLog::count());

        $this->as($loose)->postJson('/api/ticket-orders/quote', ['items' => [[
            'type' => 'attraction',
            'id' => $this->attraction->id,
            'quantity' => 1,
            'scheduled_date' => $this->date,
            'scheduled_time' => '14:00',
        ]]])->assertStatus(422);
    }

    public function test_a_retried_online_checkout_cannot_reuse_a_purchase_now_inside_a_block(): void
    {
        $payload = [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '14:00',
            'payment_method' => 'authorize.net',
        ];

        $first = $this->asGuest()->postJson('/api/attraction-purchases', $payload)->assertCreated()->json('data.id');
        $this->assertSame('pending', \App\Models\AttractionPurchase::find($first)->status);

        $this->block(['attraction_ids' => [$this->attraction->id]]);

        $this->asGuest()->postJson('/api/attraction-purchases', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed for this attraction. Please choose another.');
    }

    public function test_importing_bookings_into_a_block_warns_staff_and_records_each_one(): void
    {
        $this->block();

        $header = ['ID', 'Appointment date', 'Party Area', 'Customer name', 'Customer phone', 'Customer email', 'Service', 'Duration', 'Status', 'Payment', 'Notes', 'Created', 'Customer address', "Guest of Honor's Name", "Guest of Honor's Age (or General Age of Group)"];
        $when = Carbon::parse($this->date)->format('n/j/Y') . ' 14:00';
        $csv = function () use ($header, $when) {
            $path = tempnam(sys_get_temp_dir(), 'spec11_csv_');
            $rows = [
                ['9001', $when, 'Party Room 1', 'Acme Corp', '2485550101', 'acme@example.com', 'Basic Birthday', '120', 'Approved', '', '', '', '', '', ''],
            ];
            file_put_contents($path, implode(',', array_map(fn ($h) => str_contains($h, ',') || str_contains($h, "'") ? '"' . $h . '"' : $h, $header)) . "\n"
                . implode("\n", array_map(fn ($row) => implode(',', $row), $rows)) . "\n");

            return new \Illuminate\Http\UploadedFile($path, 'import.csv', 'text/csv', null, true);
        };

        $this->as($this->manager)->post('/api/bookings/bulk-import-csv', [
            'file' => $csv(),
            'location_id' => $this->location->id,
        ])->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');

        $this->assertSame(0, Booking::count());

        $this->as($this->manager)->post('/api/bookings/bulk-import-csv', [
            'file' => $csv(),
            'location_id' => $this->location->id,
        ] + $this->seen())->assertOk()->assertJsonPath('data.imported', 1);

        $log = DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->sole();
        $this->assertTrue($log->context['imported']);
        $this->assertSame(Booking::sole()->id, $log->context['entity_id']);
    }

    public function test_importing_bookings_is_limited_to_your_own_company(): void
    {
        $other = Company::create([
            'company_name' => 'Other Co',
            'email' => 'other@zapzone.test',
            'phone' => '5550000000',
            'address' => '9 Elm St',
        ]);
        $foreign = Location::create([
            'company_id' => $other->id,
            'name' => 'Other Venue',
            'address' => '9 Elm St',
            'city' => 'Novi',
            'state' => 'MI',
            'zip_code' => '48375',
            'phone' => '2485550000',
            'email' => 'novi@other.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $admin = User::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'first_name' => 'Admin',
            'last_name' => 'Tester',
            'email' => 'import-admin@zapzone.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'spec11_csv_');
        file_put_contents($path, "ID,Appointment date\n1,1/1/2030 10:00\n");

        $this->as($admin)->post('/api/bookings/bulk-import-csv', [
            'file' => new \Illuminate\Http\UploadedFile($path, 'import.csv', 'text/csv', null, true),
            'location_id' => $foreign->id,
        ])->assertForbidden();
    }

    public function test_the_history_records_the_role_a_pin_terminal_acted_with(): void
    {
        $this->block();

        $token = $this->manager->createToken('pin-terminal', ['role:attendant'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/bookings', $this->bookingPayload($this->seen()))
            ->assertSuccessful();

        $log = DayOffChangeLog::sole();
        $this->assertSame($this->manager->id, (int) $log->changed_by);
        $this->assertSame('attendant', $log->changed_by_role);
    }

    private function otherCompanyVenue(): array
    {
        $company = Company::create([
            'company_name' => 'Rival Fun Co',
            'email' => 'rival@zapzone.test',
            'phone' => '5550000001',
            'address' => '9 Elm St',
        ]);
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Rival Venue',
            'address' => '9 Elm St',
            'city' => 'Novi',
            'state' => 'MI',
            'zip_code' => '48375',
            'phone' => '2485550000',
            'email' => 'rival@rival.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $event = Event::create([
            'location_id' => $location->id,
            'name' => 'Rival Night',
            'date_type' => 'one_time',
            'start_date' => $this->date,
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
        $this->block(['location_id' => $location->id, 'group_name' => 'Rival Secret Party', 'reason' => 'Rival private reason', 'created_by' => null, 'updated_by' => null]);

        return compact('company', 'location', 'event');
    }

    public function test_a_request_touching_another_companys_block_gets_no_details_and_no_warning(): void
    {
        $rival = $this->otherCompanyVenue();
        $this->block(['attraction_ids' => [$this->attraction->id]]);

        $response = $this->as($this->attendant)->postJson('/api/ticket-orders', [
            'items' => [
                ['type' => 'attraction', 'id' => $this->attraction->id, 'quantity' => 1, 'scheduled_date' => $this->date, 'scheduled_time' => '14:00'],
                ['type' => 'event', 'id' => $rival['event']->id, 'quantity' => 1, 'scheduled_date' => $this->date, 'scheduled_time' => '19:00'],
            ],
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'payment_method' => 'in-store',
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Rival Secret Party', $response->getContent());
        $this->assertStringNotContainsString('Rival private reason', $response->getContent());
    }

    public function test_restoring_only_reaches_your_own_locations(): void
    {
        $rival = $this->otherCompanyVenue();
        $theirs = Booking::create([
            'reference_number' => 'BK-RIVAL-1',
            'location_id' => $rival['location']->id,
            'guest_name' => 'Rival Guest',
            'guest_email' => 'rival-guest@example.com',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'status' => 'confirmed',
            'payment_method' => 'paylater',
            'type' => 'package',
        ]);
        $theirs->delete();

        $this->as($this->attendant)->postJson('/api/bookings/bulk-restore', ['ids' => [$theirs->id], 'change_reason' => 'Try'] + $this->seen())
            ->assertOk()
            ->assertJsonPath('data.restored_count', 0);
        $this->as($this->attendant)->postJson("/api/bookings/{$theirs->id}/restore", ['change_reason' => 'Try'])
            ->assertNotFound();

        $this->assertNull(Booking::find($theirs->id));
    }

    public function test_restoring_stays_inside_the_location_you_work_at(): void
    {
        $howell = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Howell',
            'address' => '2 Test Way',
            'city' => 'Howell',
            'state' => 'MI',
            'zip_code' => '48843',
            'phone' => '5175551234',
            'email' => 'howell@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $rival = $this->otherCompanyVenue();
        $owner = $this->user('owner', 'company_admin');

        $deletedBooking = function (Location $location, string $reference): Booking {
            $booking = Booking::create([
                'reference_number' => $reference,
                'location_id' => $location->id,
                'guest_name' => 'Howell Guest',
                'guest_email' => 'howell-guest@example.com',
                'booking_date' => $this->date,
                'booking_time' => '14:00',
                'participants' => 4,
                'duration' => 120,
                'duration_unit' => 'minutes',
                'total_amount' => 100,
                'amount_paid' => 0,
                'status' => 'confirmed',
                'payment_method' => 'paylater',
                'type' => 'package',
            ]);
            $booking->delete();

            return $booking;
        };
        $deletedTicket = function (Event $event, Location $location, string $reference): \App\Models\EventPurchase {
            $ticket = \App\Models\EventPurchase::create([
                'reference_number' => $reference,
                'event_id' => $event->id,
                'location_id' => $location->id,
                'guest_name' => 'Ticket Guest',
                'guest_email' => 'ticket-guest@example.com',
                'purchase_date' => $this->date,
                'purchase_time' => '19:00',
                'quantity' => 1,
                'unit_price' => 15,
                'total_amount' => 15,
                'amount_paid' => 0,
                'payment_method' => 'paylater',
                'payment_status' => 'pending',
                'status' => 'confirmed',
            ]);
            $ticket->delete();

            return $ticket;
        };

        $howellBooking = $deletedBooking($howell, 'BK-HOWELL-1');
        $howellTicket = $deletedTicket($this->event, $howell, 'EVT-HOWELL-1');
        $rivalTicket = $deletedTicket($rival['event'], $rival['location'], 'EVT-RIVAL-1');

        $this->as($this->attendant)->postJson("/api/bookings/{$howellBooking->id}/restore", ['change_reason' => 'Try'])->assertNotFound();
        $this->as($this->attendant)->postJson("/api/event-purchases/{$howellTicket->id}/restore")->assertNotFound();
        $this->as($this->attendant)->postJson("/api/event-purchases/{$rivalTicket->id}/restore")->assertNotFound();
        $this->as($this->attendant)->postJson('/api/event-purchases/bulk-restore', ['ids' => [$howellTicket->id, $rivalTicket->id]]);
        $this->as($owner)->postJson("/api/event-purchases/{$rivalTicket->id}/restore")->assertNotFound();

        $token = $owner->createToken('pin-terminal', ['role:attendant'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson("/api/bookings/{$howellBooking->id}/restore", ['change_reason' => 'Try'])
            ->assertNotFound();

        $this->assertNull(Booking::find($howellBooking->id));
        $this->assertNull(\App\Models\EventPurchase::find($howellTicket->id));
        $this->assertNull(\App\Models\EventPurchase::find($rivalTicket->id));

        $this->as($owner)->postJson("/api/bookings/{$howellBooking->id}/restore", ['change_reason' => 'Guest called back'])->assertOk();
        $this->as($owner)->postJson("/api/event-purchases/{$howellTicket->id}/restore")->assertOk();
        $this->assertNotNull(Booking::find($howellBooking->id));
        $this->assertNotNull(\App\Models\EventPurchase::find($howellTicket->id));
    }

    public function test_a_booking_is_checked_against_blocks_where_its_package_lives(): void
    {
        $howell = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Howell',
            'address' => '2 Test Way',
            'city' => 'Howell',
            'state' => 'MI',
            'zip_code' => '48843',
            'phone' => '5175551234',
            'email' => 'howell@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $howellPackage = Package::create([
            'location_id' => $howell->id,
            'name' => 'Howell Birthday',
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
        $booking = Booking::create([
            'reference_number' => 'BK-MOVED-1',
            'location_id' => $this->location->id,
            'package_id' => $howellPackage->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'status' => 'confirmed',
            'payment_method' => 'paylater',
            'type' => 'package',
        ]);
        $nextDay = Carbon::parse($this->date)->addDay()->toDateString();
        $this->block(['location_id' => $howell->id, 'date' => $nextDay, 'package_ids' => [$howellPackage->id]]);

        $this->as($this->attendant)->putJson("/api/bookings/{$booking->id}", ['booking_date' => $nextDay, 'change_reason' => 'Guest asked to move'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertSame($this->date, $booking->fresh()->booking_date->toDateString());
    }

    public function test_space_time_slots_respect_blocks_and_locations(): void
    {
        $otherRoom = Room::create(['location_id' => $this->location->id, 'name' => 'Party Room 2', 'capacity' => 20, 'is_available' => true, 'booking_interval' => 15]);
        $this->package->rooms()->sync([$this->room->id, $otherRoom->id]);
        $customer = \App\Models\Customer::create(['first_name' => 'Jamie', 'last_name' => 'Guest', 'email' => 'jamie-slot@example.com', 'phone' => '2485550000', 'password' => 'Password123!']);

        $booking = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['room_id' => null, 'customer_id' => $customer->id]))->assertSuccessful()->json('data.id');
        $this->block(['room_ids' => [$this->room->id]]);

        $slot = [
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'booking_id' => $booking,
            'customer_id' => $customer->id,
            'booked_date' => $this->date,
            'time_slot_start' => '14:00',
            'duration' => 120,
            'duration_unit' => 'minutes',
        ];

        $this->asGuest()->postJson('/api/package-time-slots', $slot)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That space is closed at that time. Please choose another time.');

        $this->as($this->attendant)->postJson('/api/package-time-slots', $slot)
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $id = $this->as($this->attendant)->postJson('/api/package-time-slots', $slot + $this->seen())->assertCreated()->json('data.id');
        $this->assertSame('package_time_slot', DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->sole()->context['entity_type']);

        $rival = $this->otherCompanyVenue();
        $outsider = User::create([
            'company_id' => $rival['company']->id,
            'location_id' => $rival['location']->id,
            'first_name' => 'Rival',
            'last_name' => 'Manager',
            'email' => 'rival-manager@zapzone.test',
            'password' => 'Password123!',
            'role' => 'location_manager',
            'status' => 'active',
        ]);
        $this->as($outsider)->putJson("/api/package-time-slots/{$id}", ['time_slot_start' => '16:00', 'change_reason' => 'Move'])->assertForbidden();

        $this->as($this->attendant)->putJson("/api/package-time-slots/{$id}", ['time_slot_start' => '16:00', 'change_reason' => 'Move'])
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
    }

    public function test_bringing_back_a_refunded_ticket_through_an_edit_is_warned(): void
    {
        $attraction = $this->as($this->attendant)->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '14:00',
            'payment_method' => 'in-store',
        ])->assertCreated()->json('data.id');
        \App\Models\AttractionPurchase::whereKey($attraction)->update(['status' => 'refunded']);
        $this->block(['attraction_ids' => [$this->attraction->id]]);

        $this->as($this->attendant)->putJson("/api/attraction-purchases/{$attraction}", ['status' => 'confirmed'])
            ->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
        $this->assertSame('refunded', \App\Models\AttractionPurchase::find($attraction)->status);
    }

    public function test_a_partial_refund_never_brings_a_cancelled_ticket_back(): void
    {
        $attraction = $this->as($this->attendant)->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'amount_paid' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '14:00',
            'payment_method' => 'in-store',
        ])->assertCreated()->json('data.id');
        $payment = \App\Models\Payment::create([
            'payable_id' => $attraction,
            'payable_type' => \App\Models\Payment::TYPE_ATTRACTION_PURCHASE,
            'transaction_id' => 'CASH-TEST-1',
            'amount' => 20,
            'currency' => 'USD',
            'method' => 'cash',
            'status' => 'completed',
            'location_id' => $this->location->id,
        ]);
        \App\Models\AttractionPurchase::whereKey($attraction)->update(['status' => 'cancelled', 'amount_paid' => 20]);

        $this->as($this->manager)->patchJson("/api/payments/{$payment->id}/manual-refund", ['amount' => 5, 'notes' => 'Goodwill'])->assertOk();
        $this->assertSame('cancelled', \App\Models\AttractionPurchase::find($attraction)->status);

        \App\Models\AttractionPurchase::whereKey($attraction)->update(['status' => 'confirmed']);
        $this->as($this->manager)->patchJson("/api/payments/{$payment->id}/manual-refund", ['amount' => 5, 'notes' => 'Cancel too', 'cancel' => true])->assertOk();
        $this->assertSame('cancelled', \App\Models\AttractionPurchase::find($attraction)->status);
    }

    public function test_a_refund_on_an_order_never_brings_a_cancelled_line_back(): void
    {
        $orderId = $this->as($this->attendant)->postJson('/api/ticket-orders', [
            'items' => [
                ['type' => 'attraction', 'id' => $this->attraction->id, 'quantity' => 1, 'scheduled_date' => $this->date, 'scheduled_time' => '14:00'],
                ['type' => 'attraction', 'id' => $this->attraction->id, 'quantity' => 1, 'scheduled_date' => $this->date, 'scheduled_time' => '15:00'],
            ],
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'payment_method' => 'in-store',
        ])->assertCreated()->json('data.id');

        $order = \App\Models\TicketOrder::findOrFail($orderId);
        \App\Models\TicketOrder::whereKey($orderId)->update(['amount_paid' => $order->total_amount, 'status' => 'confirmed']);
        \App\Models\AttractionPurchase::where('ticket_order_id', $orderId)->update(['status' => 'confirmed']);
        $lines = \App\Models\AttractionPurchase::where('ticket_order_id', $orderId)->orderBy('id')->get();
        \App\Models\AttractionPurchase::whereKey($lines[0]->id)->update(['status' => 'cancelled']);

        app(\App\Services\TicketOrderService::class)->applyRefund(\App\Models\TicketOrder::findOrFail($orderId), 5.0);
        $this->assertSame('cancelled', $lines[0]->fresh()->status);
        $this->assertSame('pending', $lines[1]->fresh()->status);

        $order = \App\Models\TicketOrder::findOrFail($orderId);
        app(\App\Services\TicketOrderService::class)->applyRefund($order, (float) $order->amount_paid);
        $this->assertSame('cancelled', $lines[0]->fresh()->status);
        $this->assertSame('refunded', $lines[1]->fresh()->status);
    }

    public function test_an_attraction_ticket_without_a_time_is_refused_when_every_hour_is_blocked(): void
    {
        $this->attraction->update(['availability' => [['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], 'start_time' => '10:00', 'end_time' => '20:00']]]);
        $closing = $this->block(['attraction_ids' => [$this->attraction->id], 'closes_at' => '08:00']);

        $ticket = [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'payment_method' => 'authorize.net',
        ];

        $this->asGuest()->postJson('/api/attraction-purchases', $ticket)->assertStatus(422);

        $closing->update(['closes_at' => '15:00']);
        $this->asGuest()->postJson('/api/attraction-purchases', $ticket)->assertCreated();
    }

    public function test_switched_off_spaces_and_foreign_spaces_do_not_open_a_way_in(): void
    {
        $offRoom = Room::create(['location_id' => $this->location->id, 'name' => 'Closed Room', 'capacity' => 20, 'is_available' => false, 'booking_interval' => 15]);
        $foreign = Room::create(['location_id' => $this->location->id, 'name' => 'Arcade Corner', 'capacity' => 20, 'is_available' => true, 'booking_interval' => 15]);
        $this->package->rooms()->sync([$this->room->id, $offRoom->id]);
        $this->block(['room_ids' => [$this->room->id]]);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['room_id' => null]))->assertStatus(422);
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['room_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That space is not part of this package. Please choose another time.');
    }

    public function test_a_yearly_block_names_the_day_being_reactivated(): void
    {
        $attraction = $this->as($this->attendant)->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '14:00',
            'payment_method' => 'in-store',
        ])->assertCreated()->json('data.id');
        \App\Models\AttractionPurchase::whereKey($attraction)->update(['status' => 'cancelled']);
        $this->block(['date' => Carbon::parse($this->date)->subYears(2)->toDateString(), 'is_recurring' => true, 'attraction_ids' => [$this->attraction->id]]);

        $this->as($this->attendant)->patchJson("/api/attraction-purchases/{$attraction}/update-status", ['status' => 'confirmed'])
            ->assertStatus(409)
            ->assertJsonPath('blocked_periods.0.occurrence_date', $this->date);
    }

    public function test_removing_a_booking_made_inside_a_block_is_recorded_on_the_block(): void
    {
        $this->block();
        $booking = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload($this->seen()))->assertSuccessful()->json('data.id');

        Booking::find($booking)->delete();
        Booking::withTrashed()->find($booking)->forceDelete();

        $removed = DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKING_REMOVED)->orderBy('id')->get();
        $this->assertCount(2, $removed);
        $this->assertFalse($removed[0]->context['permanently']);
        $this->assertTrue($removed[1]->context['permanently']);
        $this->assertSame($booking, $removed[1]->context['entity_id']);

        $unrelated = $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['booking_date' => Carbon::parse($this->date)->addDay()->toDateString()]))->assertSuccessful()->json('data.id');
        Booking::find($unrelated)->delete();
        $this->assertSame(2, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKING_REMOVED)->count());
    }

    public function test_an_item_that_moves_to_another_location_is_taken_off_its_old_blocks(): void
    {
        $waterford = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Waterford',
            'address' => '2 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $other = Attraction::create(['location_id' => $this->location->id, 'name' => 'Arcade', 'description' => 'x', 'category' => 'Activities', 'price' => 10, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']);
        $solo = $this->block(['attraction_ids' => [$this->attraction->id]]);
        $shared = $this->block(['date' => Carbon::parse($this->date)->addDay()->toDateString(), 'attraction_ids' => [$this->attraction->id, $other->id]]);

        $this->attraction->update(['location_id' => $waterford->id]);

        $this->assertNull(DayOff::find($solo->id));
        $this->assertSame([$other->id], DayOff::find($shared->id)->attraction_ids);
        $this->assertSame(1, DayOffChangeLog::where('action', 'deleted')->where('day_off_id', $solo->id)->count());
        $this->assertSame(1, DayOffChangeLog::where('action', 'updated')->where('day_off_id', $shared->id)->count());
        $this->assertSame(0, DayOff::where('location_id', $this->location->id)->whereNull('attraction_ids')->whereNull('package_ids')->whereNull('room_ids')->whereNull('event_ids')->count());
    }

    public function test_an_operating_window_can_close_after_midnight(): void
    {
        $night = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Lock-in',
            'description' => 'Overnight',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $night->rooms()->sync([$this->room->id]);
        PackageAvailabilitySchedule::create([
            'package_id' => $night->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '18:00',
            'time_slot_end' => '02:00',
            'time_slot_interval' => 120,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->block(['opens_at' => '20:00', 'closes_at' => '01:00']);

        $this->assertSame(['20:00', '22:00'], array_column($this->slots(false, $night->id), 'start_time'));
        $this->assertSame([], array_column($this->slots(false, $this->package->id), 'start_time'));
        $this->assertSame('Open only 8:00 PM – 1:00 AM (next morning)', app(\App\Services\DayOffPresenter::class)->windowLabel(DayOff::sole()));
    }

    public function test_attraction_tickets_follow_the_same_rule(): void
    {
        $this->block(['attraction_ids' => [$this->attraction->id], 'closes_at' => '17:00']);

        $payload = [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '18:00',
            'payment_method' => 'in-store',
        ];

        $this->asGuest()->postJson('/api/attraction-purchases', $payload + ['payment_method' => 'authorize.net'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed for this attraction. Please choose another.');

        $this->as($this->attendant)->postJson('/api/attraction-purchases', $payload)
            ->assertStatus(409)
            ->assertJsonPath('blocked_periods.0.scope_label', 'Attraction: Laser Tag');

        $this->as($this->attendant)->postJson('/api/attraction-purchases', $payload + $this->seen())
            ->assertCreated();

        $this->assertSame(1, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
        $this->assertSame('attraction_purchase', DayOffChangeLog::sole()->context['entity_type']);
    }

    public function test_event_tickets_follow_the_same_rule(): void
    {
        $this->block(['closes_at' => '20:00']);

        $payload = [
            'event_id' => $this->event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'purchase_date' => $this->date,
            'purchase_time' => '20:00',
            'quantity' => 1,
            'total_amount' => 15,
            'payment_method' => 'in-store',
        ];

        $this->asGuest()->postJson('/api/event-purchases', ['payment_method' => 'authorize.net'] + $payload)
            ->assertStatus(422);

        $this->as($this->attendant)->postJson('/api/event-purchases', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');

        $this->as($this->attendant)->postJson('/api/event-purchases', $payload + $this->seen())
            ->assertSuccessful();

        $this->assertSame(1, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());

        $this->as($this->attendant)->postJson('/api/event-purchases', ['purchase_time' => '19:00'] + $payload)
            ->assertSuccessful();
        $this->assertSame(1, DayOffChangeLog::count());
    }

    public function test_editing_a_purchase_inside_a_block_only_warns_when_it_moves(): void
    {
        $this->block(['attraction_ids' => [$this->attraction->id], 'closes_at' => '17:00']);

        $attraction = $this->as($this->attendant)->postJson('/api/attraction-purchases', [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Acme Corp',
            'guest_email' => 'acme@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => '18:00',
            'payment_method' => 'in-store',
        ] + $this->seen())->assertCreated()->json('data.id');

        $same = ['scheduled_date' => $this->date, 'scheduled_time' => '18:00', 'change_reason' => 'Guest note'];

        $this->as($this->attendant)->putJson("/api/attraction-purchases/{$attraction}", $same + ['notes' => 'Bring IDs', 'quantity' => 2])
            ->assertOk();

        $this->as($this->attendant)->putJson("/api/attraction-purchases/{$attraction}", ['scheduled_time' => '19:00'] + $same)
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');

        $this->as($this->attendant)->putJson("/api/attraction-purchases/{$attraction}", ['scheduled_time' => '19:00'] + $same + $this->seen())
            ->assertOk();

        $this->assertSame(2, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->where('context->entity_type', 'attraction_purchase')->count());

        $this->block(['date' => Carbon::parse($this->date)->addDay()->toDateString(), 'event_ids' => [$this->event->id]]);
        $this->event->update(['date_type' => 'date_range', 'end_date' => Carbon::parse($this->date)->addDays(5)->toDateString()]);

        $event = $this->as($this->attendant)->postJson('/api/event-purchases', [
            'event_id' => $this->event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Acme Corp',
            'guest_email' => 'acme@example.com',
            'purchase_date' => Carbon::parse($this->date)->addDay()->toDateString(),
            'purchase_time' => '19:00',
            'quantity' => 1,
            'total_amount' => 15,
            'payment_method' => 'in-store',
        ] + $this->seen())->assertSuccessful()->json('id');

        $sameEvent = ['purchase_date' => Carbon::parse($this->date)->addDay()->toDateString(), 'purchase_time' => '19:00', 'change_reason' => 'Guest note'];

        $this->as($this->attendant)->putJson("/api/event-purchases/{$event}", $sameEvent + ['notes' => 'Birthday', 'quantity' => 3])
            ->assertOk();

        $this->as($this->attendant)->putJson("/api/event-purchases/{$event}", ['purchase_time' => '20:00'] + $sameEvent)
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');
    }

    public function test_staff_orders_are_warned_and_guest_carts_are_refused(): void
    {
        $this->block(['attraction_ids' => [$this->attraction->id], 'closes_at' => '17:00']);

        $order = [
            'items' => [[
                'type' => 'attraction',
                'id' => $this->attraction->id,
                'quantity' => 2,
                'scheduled_date' => $this->date,
                'scheduled_time' => '18:00',
            ]],
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
        ];

        $this->asGuest()->postJson('/api/ticket-orders', $order + ['payment_method' => 'authorize.net'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Laser Tag is closed on the date or time you picked. Please choose another.');

        $this->as($this->attendant)->postJson('/api/ticket-orders/quote', ['items' => $order['items']])
            ->assertOk();

        $this->asGuest()->postJson('/api/ticket-orders/quote', ['items' => $order['items']])
            ->assertStatus(422);

        $this->as($this->attendant)->postJson('/api/ticket-orders', $order + ['payment_method' => 'in-store'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD')
            ->assertJsonPath('blocked_periods.0.scope_label', 'Attraction: Laser Tag');

        $created = $this->as($this->attendant)->postJson('/api/ticket-orders', $order + [
            'payment_method' => 'in-store',
        ] + $this->seen())->assertCreated();

        $log = DayOffChangeLog::sole();
        $this->assertSame('ticket_order', $log->context['entity_type']);
        $this->assertSame($created->json('data.id'), $log->context['entity_id']);
        $this->assertSame(DayOffChangeLog::ACTION_BOOKED_INSIDE, $log->action);
    }

    public function test_an_attraction_visit_counts_as_the_whole_hour_it_is_sold_as(): void
    {
        $this->block(['attraction_ids' => [$this->attraction->id], 'closes_at' => '17:30']);

        $ticket = fn (string $time) => [
            'attraction_id' => $this->attraction->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'quantity' => 1,
            'total_amount' => 20,
            'purchase_date' => Carbon::today()->toDateString(),
            'scheduled_date' => $this->date,
            'scheduled_time' => $time,
            'payment_method' => 'authorize.net',
        ];

        $this->asGuest()->postJson('/api/attraction-purchases', $ticket('17:00'))->assertStatus(422);
        $this->asGuest()->postJson('/api/attraction-purchases', $ticket('16:00'))->assertCreated();

        $cart = fn (string $time) => ['items' => [[
            'type' => 'attraction',
            'id' => $this->attraction->id,
            'quantity' => 1,
            'scheduled_date' => $this->date,
            'scheduled_time' => $time,
        ]]];

        $this->asGuest()->postJson('/api/ticket-orders/quote', $cart('17:00'))->assertStatus(422);
        $this->asGuest()->postJson('/api/ticket-orders/quote', $cart('16:00'))->assertOk();

        $this->as($this->attendant)->postJson('/api/ticket-orders', $cart('17:00') + [
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'payment_method' => 'in-store',
        ])->assertStatus(409)->assertJsonPath('code', 'BLOCKED_PERIOD');
    }

    public function test_event_dates_and_times_list_blocked_ones_only_when_asked(): void
    {
        $this->block(['closes_at' => '20:00']);

        $this->asGuest()->getJson("/api/events/{$this->event->id}/available-time-slots/{$this->date}")
            ->assertOk()
            ->assertJsonPath('time_slots', ['18:00', '19:00'])
            ->assertJsonMissingPath('blocked_time_slots');

        $this->asGuest()->getJson("/api/events/{$this->event->id}/available-time-slots/{$this->date}?include_blocked=1")
            ->assertOk()
            ->assertJsonPath('time_slots', ['18:00', '19:00'])
            ->assertJsonPath('blocked_time_slots', ['20:00', '21:00']);

        DayOff::query()->update(['closes_at' => null]);

        $this->asGuest()->getJson("/api/events/{$this->event->id}/available-dates")
            ->assertOk()
            ->assertJsonPath('dates', [])
            ->assertJsonMissingPath('blocked_dates');

        $this->asGuest()->getJson("/api/events/{$this->event->id}/available-dates?include_blocked=1")
            ->assertOk()
            ->assertJsonPath('dates', [])
            ->assertJsonPath('blocked_dates', [$this->date]);
    }

    public function test_an_acknowledgement_only_covers_the_blocks_staff_were_shown(): void
    {
        $first = $this->block();

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload(['acknowledge_blocked_period' => true]))
            ->assertStatus(409);

        $second = $this->block(['room_ids' => [$this->room->id], 'group_name' => 'Late addition']);

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload([
            'acknowledge_blocked_period' => true,
            'acknowledged_block_ids' => [$first->id],
        ]))->assertStatus(409)->assertJsonCount(2, 'blocked_periods');

        $this->assertSame(0, Booking::count());

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload([
            'acknowledge_blocked_period' => true,
            'acknowledged_block_ids' => [$first->id, $second->id],
        ]))->assertSuccessful();

        $this->assertSame(2, DayOffChangeLog::where('action', DayOffChangeLog::ACTION_BOOKED_INSIDE)->count());
    }

    public function test_staff_of_another_company_are_treated_like_guests(): void
    {
        $this->block();

        $other = Company::create([
            'company_name' => 'Other Fun Co',
            'email' => 'owner@other.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);
        $outsider = User::create([
            'company_id' => $other->id,
            'first_name' => 'Outside',
            'last_name' => 'Admin',
            'email' => 'outsider@other.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);

        $this->as($outsider)->postJson('/api/bookings', $this->bookingPayload($this->seen()))
            ->assertStatus(422)
            ->assertJsonMissingPath('blocked_periods');

        $this->assertSame(0, Booking::count());
    }

    public function test_the_warning_names_the_day_being_booked_for_a_yearly_block(): void
    {
        $base = Carbon::parse($this->date)->subYears(2)->toDateString();
        $this->block(['date' => $base, 'is_recurring' => true]);

        $this->as($this->attendant)->postJson('/api/bookings', $this->bookingPayload())
            ->assertStatus(409)
            ->assertJsonPath('blocked_periods.0.date', $base)
            ->assertJsonPath('blocked_periods.0.occurrence_date', $this->date);
    }

    public function test_late_event_times_after_midnight_respect_the_closing_time(): void
    {
        $late = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Midnight Glow',
            'date_type' => 'one_time',
            'start_date' => $this->date,
            'time_start' => '20:00',
            'time_end' => '01:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        $this->asGuest()->getJson("/api/events/{$late->id}/available-time-slots/{$this->date}")
            ->assertJsonPath('time_slots', ['20:00', '21:00', '22:00', '23:00', '00:00']);

        $block = $this->block(['closes_at' => '22:00']);

        $this->asGuest()->getJson("/api/events/{$late->id}/available-time-slots/{$this->date}")
            ->assertJsonPath('time_slots', ['20:00', '21:00']);

        $ticket = [
            'event_id' => $late->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Night Owl',
            'guest_email' => 'owl@example.com',
            'purchase_date' => $this->date,
            'purchase_time' => '00:00',
            'quantity' => 1,
            'total_amount' => 15,
        ];

        $this->asGuest()->postJson('/api/event-purchases', $ticket + ['payment_method' => 'authorize.net'])->assertStatus(422);
        $this->as($this->attendant)->postJson('/api/event-purchases', $ticket + ['payment_method' => 'in-store'])->assertStatus(409);

        $block->update(['closes_at' => null, 'opens_at' => '12:00']);

        $this->asGuest()->getJson("/api/events/{$late->id}/available-time-slots/{$this->date}")
            ->assertJsonPath('time_slots', ['20:00', '21:00', '22:00', '23:00', '00:00']);
    }

    public function test_package_starts_after_midnight_respect_the_closing_time(): void
    {
        $night = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Lock-in',
            'description' => 'Overnight',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $night->rooms()->sync([$this->room->id]);
        PackageAvailabilitySchedule::create([
            'package_id' => $night->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '18:00',
            'time_slot_end' => '02:00',
            'time_slot_interval' => 120,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->assertSame(['18:00', '20:00', '22:00', '00:00'], array_column($this->slots(false, $night->id), 'start_time'));

        $this->block(['closes_at' => '23:00']);

        $this->assertSame(['18:00', '20:00'], array_column($this->slots(false, $night->id), 'start_time'));
        $this->assertSame([false, false, true, true], array_column($this->slots(true, $night->id), 'blocked'));

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['package_id' => $night->id, 'booking_time' => '00:00']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed. Please choose another slot.');

        DayOff::query()->update(['closes_at' => null, 'opens_at' => '12:00']);

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['package_id' => $night->id, 'booking_time' => '09:00']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected date or time is closed. Please choose another slot.');
    }

    public function test_cart_event_lines_without_a_time_respect_full_day_blocks(): void
    {
        $this->block(['event_ids' => [$this->event->id]]);

        $order = [
            'items' => [[
                'type' => 'event',
                'id' => $this->event->id,
                'quantity' => 2,
                'scheduled_date' => $this->date,
            ]],
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
        ];

        $this->asGuest()->postJson('/api/ticket-orders', $order + ['payment_method' => 'authorize.net'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Glow Night is closed on the date you picked. Please choose another.');

        $this->as($this->attendant)->postJson('/api/ticket-orders', $order + ['payment_method' => 'in-store'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BLOCKED_PERIOD');

        $this->as($this->attendant)->postJson('/api/ticket-orders', $order + ['payment_method' => 'in-store'] + $this->seen())
            ->assertCreated();
    }

    public function test_the_public_slot_list_drops_blocked_times_and_the_staff_list_flags_them(): void
    {
        $this->block(['opens_at' => '12:00', 'closes_at' => '16:00']);

        $public = $this->slots();
        $this->assertSame(['12:00', '14:00'], array_column($public, 'start_time'));

        $staff = $this->slots(true);
        $this->assertSame(['10:00', '12:00', '14:00', '16:00', '18:00'], array_column($staff, 'start_time'));
        $this->assertSame([true, false, false, true, true], array_column($staff, 'blocked'));
        $this->assertSame($this->room->id, $staff[0]['room_id']);
    }

    public function test_a_room_block_is_flagged_in_the_staff_slot_list(): void
    {
        $this->block(['room_ids' => [$this->room->id], 'closes_at' => '14:00']);

        $this->assertSame(['10:00', '12:00'], array_column($this->slots(), 'start_time'));

        $staff = $this->slots(true);
        $this->assertSame(['10:00', '12:00', '14:00', '16:00', '18:00'], array_column($staff, 'start_time'));
        $this->assertSame([false, false, true, true, true], array_column($staff, 'blocked'));
    }

    public function test_the_day_schedule_shows_the_operating_window_and_who_blocked_it(): void
    {
        $this->block(['opens_at' => '12:00', 'closes_at' => '16:00']);

        $window = app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date);

        $package = collect($window['packages'])->firstWhere('package_id', $this->package->id);
        $this->assertSame(12 * 60, $package['open_minutes']);
        $this->assertSame(16 * 60, $package['close_minutes']);
        $this->assertSame(['Open only 12:00 PM – 4:00 PM · Acme Corp Holiday Party: Private buyout'], $package['closure_notes']);

        $room = collect($window['rooms'])->firstWhere('room_id', $this->room->id);
        $this->assertSame(12 * 60, $room['open_minutes']);
        $this->assertSame(16 * 60, $room['close_minutes']);
        $this->assertSame(['Open only 12:00 PM – 4:00 PM · Acme Corp Holiday Party: Private buyout'], $room['closure_notes']);

        $this->assertCount(1, $window['blocked_periods']);
        $this->assertSame('Acme Corp Holiday Party', $window['blocked_periods'][0]['group_name']);
        $this->assertFalse($window['location_closed']);
    }

    public function test_the_day_schedule_matches_what_is_enforced_for_a_package_that_runs_past_midnight(): void
    {
        $night = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Lock-in',
            'description' => 'Overnight',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $night->rooms()->sync([$this->room->id]);
        PackageAvailabilitySchedule::create([
            'package_id' => $night->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '18:00',
            'time_slot_end' => '02:00',
            'time_slot_interval' => 120,
            'priority' => 0,
            'is_active' => true,
        ]);

        $lateOpening = $this->block(['opens_at' => '12:00']);

        $package = collect(app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date)['packages'])->firstWhere('package_id', $night->id);
        $this->assertNotNull($package);
        $this->assertSame(18 * 60, $package['open_minutes']);
        $this->assertSame(26 * 60, $package['close_minutes']);
        $this->assertSame(['18:00', '20:00', '22:00', '00:00'], array_column($this->slots(false, $night->id), 'start_time'));

        $lateOpening->update(['opens_at' => null, 'closes_at' => '23:00']);

        $package = collect(app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date)['packages'])->firstWhere('package_id', $night->id);
        $this->assertSame(18 * 60, $package['open_minutes']);
        $this->assertSame(23 * 60, $package['close_minutes']);
        $this->assertSame(['18:00', '20:00'], array_column($this->slots(false, $night->id), 'start_time'));

        $lateOpening->update(['opens_at' => '00:00', 'closes_at' => '01:00']);

        $day = app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date);
        $package = collect($day['packages'])->firstWhere('package_id', $night->id);
        $space = collect($day['rooms'])->firstWhere('room_id', $this->room->id);
        $this->assertSame(18 * 60, $package['open_minutes']);
        $this->assertSame(25 * 60, $package['close_minutes']);
        $this->assertSame(['open' => 18 * 60, 'close' => 25 * 60], ['open' => $space['open_minutes'], 'close' => $space['close_minutes']]);
        $this->assertSame(['18:00', '20:00', '22:00'], array_column($this->slots(false, $night->id), 'start_time'));

        $lateOpening->update(['opens_at' => null, 'closes_at' => '01:00']);

        $package = collect(app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date)['packages'])->firstWhere('package_id', $night->id);
        $this->assertSame(18 * 60, $package['open_minutes']);
        $this->assertSame(25 * 60, $package['close_minutes']);
        $this->assertSame(['18:00', '20:00', '22:00'], array_column($this->slots(false, $night->id), 'start_time'));

        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['package_id' => $night->id, 'booking_time' => '22:00']))->assertSuccessful();
        $this->asGuest()->postJson('/api/bookings', $this->bookingPayload(['package_id' => $night->id, 'booking_time' => '00:00', 'room_id' => null]))->assertStatus(422);
    }

    public function test_a_full_day_location_block_still_closes_the_whole_schedule(): void
    {
        $this->block();

        $window = app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date);

        $this->assertTrue($window['location_closed']);
        $this->assertTrue(DayOff::isDateBlocked($this->location->id, $this->date));

        DayOff::query()->update(['opens_at' => '12:00']);

        $this->assertFalse(DayOff::isDateBlocked($this->location->id, $this->date));
        $this->assertFalse(app(ScheduleDayWindow::class)->forDate($this->location->id, $this->date)['location_closed']);
    }

    private function slots(bool $includeBlocked = false, ?int $packageId = null): array
    {
        $query = $includeBlocked ? '?include_blocked=1' : '';
        $packageId ??= $this->package->id;

        $this->app['auth']->forgetGuards();
        $response = $this->get("/api/package-time-slots/available-slots/{$packageId}/{$this->date}{$query}");
        $body = $response->streamedContent();

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data: ')) {
                return json_decode(substr($line, 6), true)['available_slots'];
            }
        }

        $this->fail('The slot stream sent no data: ' . $body);
    }
}
