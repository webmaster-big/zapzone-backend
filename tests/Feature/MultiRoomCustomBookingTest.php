<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MultiRoomCustomBookingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private User $admin;

    private array $rooms = [];

    private Package $party;

    private string $date;

    private int $guests = 0;

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

        $this->company = Company::create(['company_name' => 'Multi Room Co', 'email' => 'owner@multiroom.test', 'phone' => '5551230000', 'address' => '1 Main St']);
        $this->brighton = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@multiroom.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        foreach ([1, 2, 3, 4] as $number) {
            $this->rooms[$number] = Room::create(['location_id' => $this->brighton->id, 'name' => "Room {$number}", 'capacity' => 30, 'is_available' => true, 'booking_interval' => 0]);
        }
        $this->admin = User::create([
            'first_name' => 'Multi',
            'last_name' => 'Admin',
            'email' => 'admin@multiroom.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'status' => 'active',
        ]);
        $this->party = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Party',
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->date = Carbon::now('America/Detroit')->addWeeks(2)->next(Carbon::SUNDAY)->toDateString();
        PackageAvailabilitySchedule::create([
            'package_id' => $this->party->id,
            'availability_type' => 'weekly',
            'day_configuration' => ['sunday'],
            'time_slot_start' => '10:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 30,
            'priority' => 0,
            'is_active' => true,
        ]);
        $this->party->rooms()->sync(collect($this->rooms)->pluck('id')->all());
    }

    private function freshRequest(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function asAdmin(): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->admin->createToken('multi')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function asGuest(): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function roomIds(int ...$numbers): array
    {
        return array_map(fn (int $number) => $this->rooms[$number]->id, $numbers);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'name' => 'School Field Trip',
            'category' => 'Custom: School',
            'duration_minutes' => 60,
            'min_guests' => 10,
            'max_guests' => 90,
            'pricing_type' => 'per_booking',
            'price' => 300,
            'fees' => [],
            'one_time_add_ons' => [],
        ], $overrides);
    }

    private function saveCustom(array $roomIds, array $overrides = [], array $terms = []): TestResponse
    {
        return $this->asAdmin()->postJson('/api/bookings', array_merge([
            'guest_name' => 'Ms Teacher',
            'guest_email' => 'teacher@example.com',
            'guest_phone' => '6165550000',
            'package_id' => null,
            'location_id' => $this->brighton->id,
            'room_ids' => $roomIds,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => '13:00',
            'participants' => 60,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 300,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'send_email' => false,
            'custom_package' => $this->terms($terms),
        ], $overrides));
    }

    private function publicBooking(Room $room, string $time): TestResponse
    {
        $this->guests++;

        return $this->asGuest()->postJson('/api/bookings', [
            'guest_name' => 'Guest ' . $this->guests,
            'guest_email' => 'guest' . $this->guests . '@example.com',
            'guest_phone' => '6165550000',
            'package_id' => $this->party->id,
            'location_id' => $this->brighton->id,
            'room_id' => $room->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => $time,
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'send_email' => false,
        ]);
    }

    private function offered(): array
    {
        $this->freshRequest();
        $stream = $this->get('/api/package-time-slots/available-slots/' . $this->party->id . '/' . $this->date)->streamedContent();
        $line = collect(explode("\n", $stream))->first(fn ($candidate) => str_starts_with($candidate, 'data: '));
        $payload = json_decode(substr((string) $line, 6), true);
        $this->assertIsArray($payload['available_slots'] ?? null, 'the start-time stream reported an error: ' . $line);

        return collect($payload['available_slots'])->keyBy('start_time')->all();
    }

    private function slotRooms(int $bookingId, ?string $status = null): array
    {
        return PackageTimeSlot::where('booking_id', $bookingId)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('room_id')->pluck('room_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_staff_save_one_custom_booking_across_three_rooms(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->assertSame(1, Booking::count(), 'one booking, not three');
        $booking = Booking::findOrFail($id);
        $this->assertSame($this->rooms[1]->id, (int) $booking->room_id);
        $this->assertSame('Custom: School', $booking->custom_package_category);
        $this->assertSame('300.00', $booking->total_amount);
        $this->assertSame($this->roomIds(1, 2, 3), $this->slotRooms($id, 'booked'));
        $slots = PackageTimeSlot::where('booking_id', $id)->get();
        $this->assertSame(['13:00:00'], $slots->pluck('time_slot_start')->unique()->values()->all());
        $this->assertSame([$this->date], $slots->map(fn ($slot) => $slot->booked_date->toDateString())->unique()->values()->all());

        $shown = $this->asAdmin()->getJson("/api/bookings/{$id}")->assertOk()->json('data');
        $this->assertSame($this->roomIds(1, 2, 3), $shown['room_ids']);
        $this->assertSame('Custom: School', $shown['custom_package_category']);
        $this->assertEqualsCanonicalizing(['Room 1', 'Room 2', 'Room 3'], collect($shown['time_slots'])->pluck('room.name')->all());
    }

    public function test_each_room_rejects_an_overlapping_public_start_and_another_room_stays_open(): void
    {
        $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated();

        $offered = $this->offered();
        $this->assertSame([$this->rooms[4]->id], $offered['13:00']['available_room_ids']);
        $this->assertSame([$this->rooms[4]->id], $offered['13:30']['available_room_ids']);
        $this->assertContains($this->rooms[2]->id, $offered['14:00']['available_room_ids'], 'free again once the hour ends');

        foreach ([1, 2, 3] as $number) {
            $this->publicBooking($this->rooms[$number], '13:30')->assertStatus(409);
        }
        $this->publicBooking($this->rooms[4], '13:30')->assertCreated();
    }

    public function test_a_conflict_in_one_room_saves_nothing_and_names_the_room(): void
    {
        $this->publicBooking($this->rooms[2], '13:30')->assertCreated();
        $before = [Booking::count(), PackageTimeSlot::count()];

        $refused = $this->saveCustom($this->roomIds(1, 2, 3))->assertStatus(409);

        $this->assertStringContainsString('Room 2: another booking already occupies this space', (string) $refused->json('message'));
        $this->assertStringNotContainsString('Room 1:', (string) $refused->json('message'));
        $this->assertSame($before, [Booking::count(), PackageTimeSlot::count()], 'no booking and no room reservation was written');
    }

    public function test_rescheduling_moves_every_room_and_frees_the_old_time(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['booking_time' => '16:00', 'change_reason' => 'School moved the trip'])->assertOk();

        $this->assertSame(['16:00:00'], PackageTimeSlot::where('booking_id', $id)->pluck('time_slot_start')->unique()->values()->all());
        $this->assertSame($this->roomIds(1, 2, 3), $this->slotRooms($id, 'booked'));
        $offered = $this->offered();
        $this->assertEqualsCanonicalizing($this->roomIds(1, 2, 3, 4), $offered['13:00']['available_room_ids']);
        $this->assertSame([$this->rooms[4]->id], $offered['16:00']['available_room_ids']);
    }

    public function test_editing_the_room_set_adds_and_removes_reservations(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 4), 'change_reason' => 'Smaller group'])->assertOk();

        $this->assertSame($this->roomIds(1, 4), $this->slotRooms($id));
        $this->assertSame($this->rooms[1]->id, (int) Booking::find($id)->room_id);
        $this->assertEqualsCanonicalizing($this->roomIds(2, 3), $this->offered()['13:00']['available_room_ids']);
    }

    public function test_an_edit_onto_a_busy_room_changes_nothing(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2))->assertCreated()->json('data.id');
        $this->publicBooking($this->rooms[3], '13:00')->assertCreated();

        $refused = $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 2, 3), 'change_reason' => 'Bigger group'])->assertStatus(409);

        $this->assertStringContainsString('Room 3: another booking already occupies this space', (string) $refused->json('message'));
        $this->assertSame($this->roomIds(1, 2), $this->slotRooms($id));
    }

    public function test_cancelling_releases_every_room(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->asAdmin()->patchJson("/api/bookings/{$id}/cancel", ['change_reason' => 'Trip called off'])->assertOk();

        $this->assertSame([], $this->slotRooms($id, 'booked'));
        $this->assertEqualsCanonicalizing($this->roomIds(1, 2, 3, 4), $this->offered()['13:00']['available_room_ids']);
    }

    public function test_deleting_and_restoring_releases_and_retakes_every_room(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->asAdmin()->deleteJson("/api/bookings/{$id}", ['change_reason' => 'Entered twice'])->assertOk();
        $this->assertSame([], $this->slotRooms($id, 'booked'));

        $this->asAdmin()->postJson("/api/bookings/{$id}/restore", ['change_reason' => 'Deleted by mistake'])->assertOk()->assertJsonPath('space_warning', null);
        $this->assertSame($this->roomIds(1, 2, 3), $this->slotRooms($id, 'booked'));
    }

    public function test_custom_bookings_use_the_five_custom_categories(): void
    {
        $this->asAdmin()->getJson('/api/bookings/custom-package/categories')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['Custom: School', 'Custom: Work', 'Custom: Adult', 'Custom: Kid', 'Custom: Group']]);

        $this->saveCustom($this->roomIds(1), [], ['category' => 'Birthday'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['custom_package.category' => ['Choose a category from the list.']]);

        foreach (['Custom: Work', 'Custom: Adult', 'Custom: Kid', 'Custom: Group'] as $index => $category) {
            $this->saveCustom($this->roomIds(4), ['booking_time' => sprintf('%02d:00', 10 + $index * 2)], ['category' => $category])->assertCreated();
        }
    }

    public function test_only_a_custom_booking_may_hold_several_rooms(): void
    {
        $this->asAdmin()->postJson('/api/bookings', [
            'guest_name' => 'Regular Guest',
            'guest_email' => 'regular@example.com',
            'package_id' => $this->party->id,
            'location_id' => $this->brighton->id,
            'room_ids' => $this->roomIds(1, 2),
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => '13:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'payment_method' => 'paylater',
            'send_email' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['room_ids']);
    }

    public function test_an_older_single_room_custom_booking_still_reopens_and_edits(): void
    {
        $id = (int) $this->saveCustom([], ['room_id' => $this->rooms[2]->id])->assertCreated()->json('data.id');

        $this->assertSame([$this->rooms[2]->id], $this->asAdmin()->getJson("/api/bookings/{$id}")->assertOk()->json('data.room_ids'));
        $this->asAdmin()->putJson("/api/bookings/{$id}", ['booking_time' => '15:00', 'change_reason' => 'Later start'])->assertOk();

        $this->assertSame([$this->rooms[2]->id], $this->slotRooms($id, 'booked'));
        $this->assertSame('15:00:00', PackageTimeSlot::where('booking_id', $id)->value('time_slot_start'));
    }

    public function test_a_save_that_loses_a_room_to_a_simultaneous_booking_writes_nothing(): void
    {
        $rival = Booking::create(['reference_number' => 'RIVAL-1', 'booking_date' => $this->date, 'booking_time' => '13:00', 'duration' => 60, 'duration_unit' => 'minutes', 'location_id' => $this->brighton->id, 'package_id' => $this->party->id, 'participants' => 2, 'total_amount' => 100, 'status' => 'confirmed']);
        $raced = false;
        Event::listen('eloquent.created: ' . Booking::class, function (Booking $created) use (&$raced, $rival) {
            if ($raced || $created->id === $rival->id) {
                return;
            }
            $raced = true;
            DB::table('package_time_slots')->insert([
                'package_id' => $this->party->id,
                'booking_id' => $rival->id,
                'room_id' => $this->rooms[3]->id,
                'booked_date' => $this->date,
                'time_slot_start' => '13:00:00',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'status' => 'booked',
                'overlap_seq' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->saveCustom($this->roomIds(1, 2, 3))->assertStatus(409);

        $this->assertTrue($raced);
        $this->assertSame(1, Booking::count(), 'only the rival booking exists');
        $this->assertSame(0, PackageTimeSlot::where('booking_id', '!=', $rival->id)->count(), 'none of the new reservations were kept');
    }

    public function test_the_bookings_own_rooms_count_as_one_start_for_area_spacing(): void
    {
        foreach ([1, 2, 3] as $number) {
            $this->rooms[$number]->update(['area_group' => 'Classrooms', 'booking_interval' => 30, 'area_allows_simultaneous_starts' => false]);
        }
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logged) {
            $logged[] = $event->message;
        });
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');
        $slot = PackageTimeSlot::where('booking_id', $id)->where('room_id', $this->rooms[3]->id)->firstOrFail();

        $this->asAdmin()->patchJson("/api/package-time-slots/{$slot->id}", [
            'booked_date' => $this->date,
            'time_slot_start' => '13:15',
            'change_reason' => 'Room 3 group arrives a little later',
        ])->assertStatus(422)->assertJsonPath('message', 'This booking uses several spaces. Move the booking itself so every space moves together.');
        $this->assertSame('13:00:00', $slot->fresh()->time_slot_start);

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['booking_time' => '13:15', 'change_reason' => 'Whole group later'])->assertOk();
        $this->assertSame(['13:15:00', '13:15:00', '13:15:00'], PackageTimeSlot::where('booking_id', $id)->orderBy('id')->pluck('time_slot_start')->all());

        $this->assertSame([], array_values(array_filter($logged, fn ($message) => str_contains((string) $message, 'close to another space in the same area'))));
    }

    public function test_a_rival_that_lands_at_an_overlapping_start_while_the_rooms_are_locked_is_refused(): void
    {
        $rival = Booking::create(['reference_number' => 'RIVAL-2', 'booking_date' => $this->date, 'booking_time' => '13:30', 'duration' => 60, 'duration_unit' => 'minutes', 'location_id' => $this->brighton->id, 'package_id' => $this->party->id, 'participants' => 2, 'total_amount' => 100, 'status' => 'confirmed']);
        $landed = false;
        DB::listen(function ($query) use (&$landed, $rival) {
            if ($landed || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, '`rooms`')) {
                return;
            }
            $landed = true;
            DB::table('package_time_slots')->insert([
                'package_id' => $this->party->id,
                'booking_id' => $rival->id,
                'room_id' => $this->rooms[2]->id,
                'booked_date' => $this->date,
                'time_slot_start' => '13:30:00',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'status' => 'booked',
                'overlap_seq' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $refused = $this->saveCustom($this->roomIds(1, 2, 3))->assertStatus(409);

        $this->assertTrue($landed);
        $this->assertStringContainsString('Room 2', (string) $refused->json('message'));
        $this->assertStringContainsString('Nothing was saved', (string) $refused->json('message'));
        $this->assertSame(1, Booking::count(), 'only the rival booking exists');
    }

    public function test_an_edit_that_meets_a_rival_landing_while_the_rooms_are_locked_changes_nothing(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2))->assertCreated()->json('data.id');
        $rival = Booking::create(['reference_number' => 'RIVAL-3', 'booking_date' => $this->date, 'booking_time' => '15:30', 'duration' => 60, 'duration_unit' => 'minutes', 'location_id' => $this->brighton->id, 'package_id' => $this->party->id, 'participants' => 2, 'total_amount' => 100, 'status' => 'confirmed']);
        $landed = false;
        DB::listen(function ($query) use (&$landed, $rival) {
            if ($landed || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, '`rooms`')) {
                return;
            }
            $landed = true;
            DB::table('package_time_slots')->insert([
                'package_id' => $this->party->id, 'booking_id' => $rival->id, 'room_id' => $this->rooms[2]->id,
                'booked_date' => $this->date, 'time_slot_start' => '15:30:00', 'duration' => 60, 'duration_unit' => 'minutes',
                'status' => 'booked', 'overlap_seq' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['booking_time' => '15:00', 'change_reason' => 'Later start'])->assertStatus(409);

        $this->assertTrue($landed);
        $this->assertSame(['13:00:00', '13:00:00'], PackageTimeSlot::where('booking_id', $id)->orderBy('id')->pluck('time_slot_start')->all());
        $this->assertSame('13:00', substr((string) Booking::find($id)->getRawOriginal('booking_time'), 0, 5));
    }

    public function test_a_quick_room_change_on_a_multi_room_booking_points_to_edit_booking(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_id' => $this->rooms[2]->id, 'change_reason' => 'Swap room'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This booking uses several spaces. Change its spaces in Edit Booking.');
        $this->assertSame($this->roomIds(1, 2, 3), $this->slotRooms($id, 'booked'));

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_id' => $this->rooms[1]->id, 'notes' => 'Same main room', 'change_reason' => 'Note'])->assertOk();
        $this->assertSame($this->roomIds(1, 2, 3), $this->slotRooms($id, 'booked'));
    }

    public function test_a_switched_off_room_cannot_be_added_but_one_already_held_stays(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2))->assertCreated()->json('data.id');
        $this->rooms[2]->update(['is_available' => false]);
        $this->rooms[3]->update(['is_available' => false]);

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 2, 3), 'change_reason' => 'Add room 3'])
            ->assertStatus(422)
            ->assertJsonPath('message', \App\Services\Checkout\CustomPackagePricer::ROOM_SWITCHED_OFF_MESSAGE);
        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 2), 'notes' => 'Keep both', 'change_reason' => 'Note'])->assertOk();
        $this->assertSame($this->roomIds(1, 2), $this->slotRooms($id, 'booked'));

        $this->saveCustom($this->roomIds(3, 4), ['booking_time' => '15:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', \App\Services\Checkout\CustomPackagePricer::ROOM_SWITCHED_OFF_MESSAGE);
    }

    public function test_moving_a_multi_room_booking_to_another_location_points_to_edit_booking(): void
    {
        $canton = Location::create(['company_id' => $this->company->id, 'name' => 'Canton', 'address' => '2 Test Way', 'city' => 'Canton', 'state' => 'MI', 'zip_code' => '48187', 'phone' => '7345551234', 'email' => 'canton@multiroom.test', 'timezone' => 'America/Detroit', 'is_active' => true]);
        $cantonRoom = Room::create(['location_id' => $canton->id, 'name' => 'Canton Room', 'capacity' => 30, 'is_available' => true, 'booking_interval' => 0]);
        $message = \App\Services\Checkout\CustomPackagePricer::MULTI_ROOM_MOVE_MESSAGE;

        $single = (int) $this->saveCustom($this->roomIds(4), ['booking_time' => '16:00'])->assertCreated()->json('data.id');
        $pending = $this->asAdmin()->postJson("/api/bookings/{$single}/location-change-requests", ['to_location_id' => $canton->id, 'room_id' => $cantonRoom->id])->assertSuccessful()->json('data.id');
        $this->asAdmin()->putJson("/api/bookings/{$single}", ['room_ids' => $this->roomIds(4, 3), 'change_reason' => 'Bigger group'])->assertOk();

        $this->asAdmin()->patchJson("/api/location-change-requests/{$pending}/approve", [])->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame($this->roomIds(3, 4), $this->slotRooms($single, 'booked'));

        $id = (int) $this->saveCustom($this->roomIds(1, 2))->assertCreated()->json('data.id');
        $this->asAdmin()->patchJson("/api/bookings/{$id}/location", ['location_id' => $canton->id, 'room_id' => $cantonRoom->id, 'change_reason' => 'Move venue'])
            ->assertStatus(422)->assertJsonPath('message', $message);
        $this->asAdmin()->postJson("/api/bookings/{$id}/location-change-requests", ['to_location_id' => $canton->id, 'room_id' => $cantonRoom->id])
            ->assertStatus(422)->assertJsonPath('message', $message);

        $this->assertSame((int) $this->brighton->id, (int) Booking::find($id)->location_id);
        $this->assertSame($this->roomIds(1, 2), $this->slotRooms($id, 'booked'));
    }

    public function test_the_save_and_edit_answers_list_every_room_and_a_room_edit_marks_the_booking_changed(): void
    {
        $created = $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated();
        $this->assertEqualsCanonicalizing($this->roomIds(1, 2, 3), collect($created->json('data.time_slots'))->pluck('room_id')->all());

        $id = (int) $created->json('data.id');
        DB::table('bookings')->where('id', $id)->update(['updated_at' => now()->subHour()]);
        $before = Booking::find($id)->updated_at;

        $edited = $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 4), 'change_reason' => 'Different rooms'])->assertOk();

        $this->assertEqualsCanonicalizing($this->roomIds(1, 4), collect($edited->json('data.time_slots'))->pluck('room_id')->all());
        $this->assertTrue(Booking::find($id)->updated_at->gt($before), 'a rooms-only edit still shows up in the next sync');
    }

    public function test_check_in_status_and_restore_answers_keep_every_room(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');
        $reference = Booking::find($id)->reference_number;
        $every = $this->roomIds(1, 2, 3);

        $this->assertSame($every, $this->asAdmin()->patchJson("/api/bookings/{$id}/status", ['status' => 'confirmed', 'change_reason' => 'Deposit taken'])->assertOk()->json('data.room_ids'));
        $this->assertSame($every, $this->asAdmin()->patchJson("/api/bookings/{$id}/payment-status", ['payment_status' => 'partial', 'change_reason' => 'Deposit'])->assertOk()->json('data.room_ids'));
        $this->assertSame($every, $this->asAdmin()->postJson('/api/bookings/check-in', ['reference_number' => $reference])->assertOk()->json('data.room_ids'));

        $this->asAdmin()->deleteJson("/api/bookings/{$id}", ['change_reason' => 'Entered twice'])->assertOk();
        $this->assertSame($every, $this->asAdmin()->postJson("/api/bookings/{$id}/restore", ['change_reason' => 'Deleted by mistake'])->assertOk()->json('data.room_ids'));
        $this->assertSame($every, $this->asAdmin()->patchJson("/api/bookings/{$id}/cancel", ['change_reason' => 'Trip called off'])->assertOk()->json('data.room_ids'));
    }

    public function test_an_older_overlap_in_a_room_that_stays_put_does_not_block_other_edits(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');
        $overlap = Booking::create([
            'reference_number' => 'OLD-OVERLAP',
            'booking_date' => $this->date,
            'booking_time' => '13:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->brighton->id,
            'package_id' => $this->party->id,
            'room_id' => $this->rooms[1]->id,
            'participants' => 4,
            'total_amount' => 100,
            'status' => 'confirmed',
        ]);
        DB::table('package_time_slots')->insert([
            'package_id' => $this->party->id,
            'booking_id' => $overlap->id,
            'room_id' => $this->rooms[1]->id,
            'booked_date' => $this->date,
            'time_slot_start' => '13:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'status' => 'booked',
            'overlap_seq' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 2), 'change_reason' => 'Smaller group'])->assertOk();
        $this->assertSame($this->roomIds(1, 2), $this->slotRooms($id));

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 2, 4), 'change_reason' => 'One more room'])->assertOk();
        $this->assertSame($this->roomIds(1, 2, 4), $this->slotRooms($id));

        $moved = $this->asAdmin()->putJson("/api/bookings/{$id}", ['booking_time' => '13:15', 'change_reason' => 'Later bus'])->assertStatus(409);
        $this->assertStringContainsString('Room 1', (string) $moved->json('message'), 'moving the time still checks every room');
        $this->assertSame('13:00', substr((string) PackageTimeSlot::where('booking_id', $id)->value('time_slot_start'), 0, 5));
    }

    public function test_the_change_history_records_which_rooms_were_added_or_removed(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3), ['created_by' => $this->admin->id])->assertCreated()->json('data.id');

        $created = \App\Models\ActivityLog::where('entity_type', 'booking')->where('entity_id', $id)->where('action', 'Booking Created')->latest('id')->first();
        $this->assertSame(['Room 1', 'Room 2', 'Room 3'], array_column($created->metadata['rooms'] ?? [], 'name'));

        $this->asAdmin()->putJson("/api/bookings/{$id}", ['room_ids' => $this->roomIds(1, 4), 'change_reason' => 'Different rooms'])->assertOk();

        $edited = \App\Models\ActivityLog::where('entity_type', 'booking')->where('entity_id', $id)->where('action', 'Booking Edited')->latest('id')->first();
        $this->assertEquals(['from' => ['Room 1', 'Room 2', 'Room 3'], 'to' => ['Room 1', 'Room 4']], $edited->metadata['changes']['rooms'] ?? null);
    }

    public function test_printed_sheets_and_the_recent_bookings_tile_name_every_room(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');
        $booking = Booking::find($id);

        $this->assertSame('Room 1, Room 2, Room 3', $booking->spaceNames());
        $this->assertSame('Room 1, Room 2, Room 3', Booking::find($id)->load('room')->spaceNames());

        $this->asAdmin()->get("/api/bookings/{$id}/summary/view")->assertOk();
        $printed = Booking::with(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments'])->find($id);
        $sheet = view('exports.booking-summary', ['booking' => $printed, 'customer' => $printed->customer, 'location' => $printed->location, 'companyName' => 'ZapZone'])->render();
        $this->assertStringContainsString('Room 1, Room 2, Room 3', $sheet);
        $this->assertStringNotContainsString('Capacity: 30', $sheet, 'one room\'s capacity is not printed next to three rooms');

        $single = $this->publicBooking($this->rooms[4], '15:00')->assertCreated()->json('data.id');
        $this->assertSame('Room 4', Booking::find($single)->spaceNames());
    }

    public function test_the_confirmation_names_every_room(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1, 2, 3))->assertCreated()->json('data.id');

        $variables = app(\App\Services\EmailNotificationService::class)->buildVariables(Booking::find($id), 'booking', false);

        $this->assertSame('Room 1, Room 2, Room 3', $variables['room_name']);
    }

    public function test_bulk_restore_skips_a_booking_whose_gift_card_was_already_refunded(): void
    {
        $id = (int) $this->saveCustom($this->roomIds(1))->assertCreated()->json('data.id');
        Booking::find($id)->delete();
        $card = DB::table('gift_cards')->insertGetId(['code' => 'GC-MULTI-1', 'type' => 'fixed', 'initial_value' => 40, 'balance' => 40, 'max_usage' => 1, 'status' => 'active', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gift_card_reversals')->insert(['gift_card_id' => $card, 'payable_type' => \App\Models\Payment::TYPE_BOOKING, 'payable_id' => $id, 'amount' => 40, 'credited' => 40, 'reason' => 'booking_deleted', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->asAdmin()->postJson('/api/bookings/bulk-restore', ['ids' => [$id], 'change_reason' => 'Undo delete'])->assertOk();

        $this->assertSame([$id], collect($response->json('data.blocked'))->pluck('id')->map(fn ($value) => (int) $value)->all());
        $this->assertTrue(Booking::withTrashed()->find($id)->trashed(), 'the booking stays deleted');
    }
}
