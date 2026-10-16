<?php

namespace Tests\Feature;

use App\Exceptions\SlotTakenException;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpaceSlotOverlapTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Room $room;
    private Package $package;
    private User $desk;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
            'booking_rules.slot_conflict' => 'enforce',
            'checkout.enforce_charge_within_due' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->room = Room::create(['location_id' => $this->location->id, 'name' => 'Party Room 1', 'capacity' => 20, 'is_available' => true]);
        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->desk = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);
        $this->date = now()->addDays(5)->toDateString();
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::postJson($uri, $data, $headers, $options);
    }

    public function putJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::putJson($uri, $data, $headers, $options);
    }

    public function patchJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->freshControllers();

        return parent::patchJson($uri, $data, $headers, $options);
    }

    private function freshControllers(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
    }

    private function payload(string $email, string $time = '14:00', array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Guest ' . $email,
            'guest_email' => $email,
            'guest_phone' => '6165550000',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => $time,
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'send_email' => false,
        ], $overrides);
    }

    private function asDesk(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->desk->createToken('desk')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function slotsAtTwo()
    {
        return PackageTimeSlot::where('room_id', $this->room->id)
            ->whereDate('booked_date', $this->date)
            ->where('time_slot_start', '14:00:00')
            ->orderBy('overlap_seq')
            ->get();
    }

    public function test_a_cancelled_booking_frees_its_exact_start_for_the_next_guest(): void
    {
        $first = (int) $this->asGuest()->postJson('/api/bookings', $this->payload('first@example.com'))->assertCreated()->json('data.id');
        $this->asDesk()->patchJson("/api/bookings/{$first}/cancel", ['change_reason' => 'Guest called to cancel'])->assertOk();

        $second = (int) $this->asGuest()->postJson('/api/bookings', $this->payload('second@example.com'))->assertCreated()->json('data.id');

        $slots = $this->slotsAtTwo();
        $this->assertSame(['cancelled', 'booked'], $slots->pluck('status')->all());
        $this->assertSame($second, (int) $slots->firstWhere('status', 'booked')->booking_id);
    }

    public function test_staff_overlaps_at_the_same_start_each_keep_their_own_slot_and_the_room_stays_busy(): void
    {
        $first = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('first@example.com'))->assertCreated()->json('data.id');
        $second = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('second@example.com'))->assertCreated()->json('data.id');

        $slots = $this->slotsAtTwo();
        $this->assertSame([$first, $second], $slots->pluck('booking_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([0, 1], $slots->pluck('overlap_seq')->map(fn ($seq) => (int) $seq)->all());

        $this->asDesk()->patchJson("/api/bookings/{$first}/cancel", ['change_reason' => 'Guest called to cancel'])->assertOk();

        $this->asGuest()->postJson('/api/bookings', $this->payload('third@example.com'))->assertStatus(409);
        $this->assertSame(2, Booking::count(), 'the room is still taken by the second party');
    }

    public function test_moving_a_booking_onto_the_exact_start_of_another_keeps_both_slots(): void
    {
        $first = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('first@example.com'))->assertCreated()->json('data.id');
        $second = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('second@example.com', '16:00'))->assertCreated()->json('data.id');

        $this->asDesk()->putJson("/api/bookings/{$second}", ['booking_time' => '14:00', 'change_reason' => 'Party merged with friends'])->assertOk();

        $slots = $this->slotsAtTwo();
        $this->assertSame([$first, $second], $slots->pluck('booking_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_booking_that_loses_the_race_for_a_free_start_is_refused_and_not_saved(): void
    {
        $rival = Booking::create([
            'reference_number' => 'BKRIVAL',
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'guest_name' => 'Rival Guest',
            'guest_email' => 'rival@example.com',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        $raced = false;
        PackageTimeSlot::creating(function () use (&$raced, $rival) {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('package_time_slots')->insert([
                'package_id' => $this->package->id,
                'booking_id' => $rival->id,
                'room_id' => $this->room->id,
                'booked_date' => $this->date,
                'time_slot_start' => '14:00:00',
                'overlap_seq' => 0,
                'duration' => 60,
                'duration_unit' => 'minutes',
                'status' => 'booked',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->asGuest()->postJson('/api/bookings', $this->payload('late@example.com'))->assertStatus(409);

        $this->assertSame(0, Booking::where('guest_email', 'late@example.com')->count(), 'the losing booking is not left behind without its space');
        $this->assertSame(0, PackageTimeSlot::where('booking_id', '!=', $rival->id)->count());
    }

    public function test_a_booking_that_shares_its_start_can_still_be_edited(): void
    {
        $first = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('first@example.com'))->assertCreated()->json('data.id');
        $second = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('second@example.com'))->assertCreated()->json('data.id');

        foreach ([$first, $second] as $id) {
            $this->asDesk()->putJson("/api/bookings/{$id}", [
                'booking_date' => $this->date,
                'booking_time' => '14:00',
                'room_id' => $this->room->id,
                'notes' => 'Bringing a cake',
                'change_reason' => 'Guest called with a note',
            ])->assertOk();
        }

        $this->assertSame(['Bringing a cake', 'Bringing a cake'], Booking::orderBy('id')->pluck('notes')->all());
        $this->assertSame([0, 1], $this->slotsAtTwo()->pluck('overlap_seq')->map(fn ($seq) => (int) $seq)->all());
    }

    public function test_moving_a_booking_within_its_own_space_never_clashes_with_itself(): void
    {
        $this->desk->forceFill(['pin_hash' => bcrypt('2468')])->save();
        Booking::create([
            'reference_number' => 'BKWALKIN',
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'guest_name' => 'Walk-in without a space',
            'booking_date' => $this->date,
            'booking_time' => '10:00',
            'participants' => 2,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        $id = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('first@example.com'))->assertCreated()->json('data.id');
        $this->assertNotSame($id, (int) PackageTimeSlot::where('booking_id', $id)->value('id'), 'the booking and its slot have different numbers, as in production');

        $this->asDesk()->putJson("/api/bookings/{$id}", ['booking_time' => '14:30', 'change_reason' => 'Guest running late'])
            ->assertOk()
            ->assertJsonMissingPath('requires_override');

        $this->assertSame('14:30:00', (string) PackageTimeSlot::where('booking_id', $id)->value('time_slot_start'));
    }

    public function test_a_true_race_caught_by_the_database_is_refused_cleanly_on_save_and_on_edit(): void
    {
        $rival = Booking::create([
            'reference_number' => 'BKRIVAL2',
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'guest_name' => 'Rival Guest',
            'guest_email' => 'rival@example.com',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        $sneakIn = function (string $start) use ($rival) {
            DB::table('package_time_slots')->insert([
                'package_id' => $this->package->id,
                'booking_id' => $rival->id,
                'room_id' => $this->room->id,
                'booked_date' => $this->date,
                'time_slot_start' => $start,
                'overlap_seq' => 0,
                'duration' => 60,
                'duration_unit' => 'minutes',
                'status' => 'booked',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        new PackageTimeSlot();
        $racing = true;
        PackageTimeSlot::creating(function () use (&$racing, $sneakIn) {
            if ($racing) {
                $racing = false;
                $sneakIn('14:00:00');
            }
        });

        $this->asGuest()->postJson('/api/bookings', $this->payload('late@example.com'))->assertStatus(409);
        $this->assertSame(0, Booking::where('guest_email', 'late@example.com')->count());

        $moving = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('mover@example.com', '17:00'))->assertCreated()->json('data.id');
        $racing = true;
        PackageTimeSlot::updating(function () use (&$racing, $sneakIn) {
            if ($racing) {
                $racing = false;
                $sneakIn('16:00:00');
            }
        });

        $this->asDesk()->putJson("/api/bookings/{$moving}", ['booking_time' => '16:00', 'change_reason' => 'Guest asked for an earlier time'])->assertStatus(409);
        $this->assertSame('17:00:00', (string) PackageTimeSlot::where('booking_id', $moving)->value('time_slot_start'));
    }

    public function test_the_staff_slot_screen_refuses_a_lost_race_without_a_server_error(): void
    {
        $customer = \App\Models\Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
        $booking = Booking::create([
            'reference_number' => 'BKSLOT',
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'customer_id' => $customer->id,
            'guest_name' => 'Pat Guest',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        new PackageTimeSlot();
        PackageTimeSlot::creating(function () use ($booking) {
            DB::table('package_time_slots')->insert([
                'package_id' => $this->package->id,
                'booking_id' => $booking->id,
                'room_id' => $this->room->id,
                'booked_date' => $this->date,
                'time_slot_start' => '14:00:00',
                'overlap_seq' => 0,
                'duration' => 60,
                'duration_unit' => 'minutes',
                'status' => 'booked',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->asDesk()->postJson('/api/package-time-slots', [
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'booked_date' => $this->date,
            'time_slot_start' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
        ])->assertStatus(422);
    }

    public function test_a_forced_move_to_another_venue_onto_an_exact_start_keeps_both_slots(): void
    {
        $canton = Location::create([
            'company_id' => $this->location->company_id,
            'name' => 'Canton',
            'address' => '2 Test Way',
            'city' => 'Canton',
            'state' => 'MI',
            'zip_code' => '48187',
            'phone' => '7345551234',
            'email' => 'canton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $cantonRoom = Room::create(['location_id' => $canton->id, 'name' => 'Canton Room', 'capacity' => 20, 'is_available' => true]);
        $there = Booking::create([
            'reference_number' => 'BKTHERE',
            'location_id' => $canton->id,
            'package_id' => $this->package->id,
            'room_id' => $cantonRoom->id,
            'guest_name' => 'Already There',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);
        PackageTimeSlot::create([
            'package_id' => $this->package->id,
            'booking_id' => $there->id,
            'room_id' => $cantonRoom->id,
            'booked_date' => $this->date,
            'time_slot_start' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'status' => 'booked',
        ]);
        $moving = (int) $this->asDesk()->postJson('/api/bookings', $this->payload('mover@example.com'))->assertCreated()->json('data.id');
        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => 'Admin',
            'email' => 'owner.admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->location->company_id,
            'location_id' => $this->location->id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer ' . $owner->createToken('owner')->plainTextToken, 'Accept' => 'application/json']);

        $this->patchJson("/api/bookings/{$moving}/location", ['location_id' => $canton->id, 'room_id' => $cantonRoom->id, 'change_reason' => 'Guest prefers Canton'])->assertStatus(409);
        $this->patchJson("/api/bookings/{$moving}/location", ['location_id' => $canton->id, 'room_id' => $cantonRoom->id, 'force' => true, 'change_reason' => 'Guest prefers Canton'])->assertOk();

        $this->assertSame(
            [[$there->id, 0], [$moving, 1]],
            PackageTimeSlot::where('room_id', $cantonRoom->id)->orderBy('overlap_seq')->get()->map(fn ($slot) => [(int) $slot->booking_id, (int) $slot->overlap_seq])->all()
        );
    }

    public function test_a_slot_only_shares_its_start_with_an_active_booking_when_an_overlap_is_allowed(): void
    {
        $base = fn () => [
            'booking_id' => Booking::create([
                'reference_number' => 'BK' . strtoupper(uniqid()),
                'location_id' => $this->location->id,
                'package_id' => $this->package->id,
                'guest_name' => 'Slot Guest',
                'booking_date' => $this->date,
                'booking_time' => '14:00',
                'participants' => 4,
                'duration' => 60,
                'duration_unit' => 'minutes',
                'total_amount' => 100,
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'status' => 'confirmed',
            ])->id,
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'booked_date' => $this->date,
            'time_slot_start' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'status' => 'booked',
        ];
        $held = PackageTimeSlot::create($base());
        $this->assertSame(0, (int) $held->overlap_seq);

        try {
            PackageTimeSlot::create($base());
            $this->fail('a second active slot at the same start was saved without an allowed overlap');
        } catch (SlotTakenException) {
            $this->assertSame(1, PackageTimeSlot::count());
        }

        $shared = (new PackageTimeSlot())->allowOverlap()->fill($base());
        $shared->save();
        $this->assertSame(1, (int) $shared->overlap_seq);

        $held->update(['status' => 'cancelled']);
        $shared->update(['status' => 'cancelled']);
        $freed = PackageTimeSlot::create($base());
        $this->assertSame(2, (int) $freed->overlap_seq, 'cancelled slots never block the start; the new one takes the next free place');
    }
}
