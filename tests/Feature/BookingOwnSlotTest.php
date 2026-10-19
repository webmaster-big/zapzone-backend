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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BookingOwnSlotTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $waterford;

    private Location $brighton;

    private Room $room;

    private Package $party;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'booking_rules.slot_conflict' => 'enforce']);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->setTime(12, 0));

        $this->company = Company::create([
            'company_name' => 'Own Slot Co',
            'email' => 'owner@ownslot.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->waterford = $this->makeLocation($this->company, 'Waterford');
        $this->brighton = $this->makeLocation($this->company, 'Brighton');

        $this->room = Room::create([
            'location_id' => $this->waterford->id,
            'name' => 'Party Room',
            'capacity' => 30,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        $this->party = Package::create([
            'location_id' => $this->waterford->id,
            'name' => 'Waterford Party',
            'description' => 'Two hours of fun',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->party->rooms()->attach($this->room->id);

        PackageAvailabilitySchedule::create([
            'package_id' => $this->party->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '10:00',
            'time_slot_end' => '22:00',
            'time_slot_interval' => 60,
            'is_active' => true,
        ]);

        $this->manager = $this->staff('location_manager', $this->waterford);
        $this->manager->forceFill(['pin_hash' => Hash::make('4321')])->save();

        $earlier = $this->book('10:00', 20);
        while ((int) PackageTimeSlot::max('id') === (int) Booking::max('id')) {
            PackageTimeSlot::create([
                'package_id' => $this->party->id,
                'room_id' => $this->room->id,
                'booking_id' => $earlier->id,
                'booked_date' => now()->addDays(21)->toDateString(),
                'time_slot_start' => '10:00',
                'duration' => 120,
                'duration_unit' => 'minutes',
                'status' => 'cancelled',
            ]);
        }
    }

    private function makeLocation(Company $company, string $city): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '.' . uniqid() . '@ownslot.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function staff(string $role, Location $location, ?Company $company = null): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@ownslot.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => ($company ?? $this->company)->id,
            'location_id' => $location->id,
        ]);
    }

    private function as(User $account): static
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $account->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function book(string $time, int $daysAhead = 10): Booking
    {
        return Booking::findOrFail($this->tryToBook($time, $daysAhead)->assertSuccessful()->json('data.id'));
    }

    private function tryToBook(string $time, int $daysAhead = 10)
    {
        return $this->as($this->manager)->postJson('/api/bookings', [
            'guest_name' => 'Desk Guest',
            'guest_email' => 'guest.' . uniqid() . '@example.com',
            'guest_phone' => '2485559999',
            'package_id' => $this->party->id,
            'location_id' => $this->waterford->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => now()->addDays($daysAhead)->toDateString(),
            'booking_time' => $time,
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'send_email' => false,
        ]);
    }

    private function slotStatusOf(Booking $booking): ?string
    {
        return PackageTimeSlot::where('booking_id', $booking->id)->value('status');
    }

    private function setStatus(Booking $booking, string $status)
    {
        return $this->as($this->manager)->putJson("/api/bookings/{$booking->id}", ['status' => $status, 'change_reason' => 'Guest called the desk']);
    }

    public function test_cancelling_from_the_edit_screen_frees_the_space(): void
    {
        $cancelled = $this->book('14:00');

        $this->setStatus($cancelled, 'cancelled')->assertOk();

        $this->assertSame('cancelled', $this->slotStatusOf($cancelled));
        $this->book('15:00');
    }

    public function test_reinstating_a_cancelled_booking_checks_the_space_again(): void
    {
        $first = $this->book('14:00');
        $this->setStatus($first, 'cancelled')->assertOk();
        $this->book('15:00');

        $this->setStatus($first, 'confirmed')
            ->assertStatus(409)
            ->assertJsonPath('requires_override', true);

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $this->slotStatusOf($first));
    }

    public function test_a_reinstated_booking_holds_its_space_again(): void
    {
        $booking = $this->book('14:00');
        $this->setStatus($booking, 'cancelled')->assertOk();

        $this->setStatus($booking, 'confirmed')->assertOk();

        $this->assertSame('booked', $this->slotStatusOf($booking));
        $this->tryToBook('15:00')->assertStatus(409)->assertJsonPath('requires_override', true);
    }

    public function test_moving_a_booking_within_its_own_space_does_not_clash_with_itself(): void
    {
        $booking = $this->book('14:00');
        $ownSlot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNotSame($booking->id, $ownSlot->id, 'the slot and booking numbers differ, as they do in production');

        $this->as($this->manager)
            ->putJson("/api/bookings/{$booking->id}", ['booking_time' => '15:00', 'change_reason' => 'Guest running late'])
            ->assertOk();

        $this->assertSame('15:00', \Carbon\Carbon::parse($booking->fresh()->booking_time)->format('H:i'));
        $this->assertNull($booking->fresh()->overlap_override_by, 'no manager approval was needed');
    }

    public function test_a_real_clash_with_another_booking_still_needs_a_manager(): void
    {
        $early = $this->book('12:00');
        $this->book('16:00');

        $this->as($this->manager)
            ->putJson("/api/bookings/{$early->id}", ['booking_time' => '15:00', 'change_reason' => 'Guest asked for later'])
            ->assertStatus(409)
            ->assertJsonPath('requires_override', true);

        $this->assertSame('12:00', \Carbon\Carbon::parse($early->fresh()->booking_time)->format('H:i'));
    }

    public function test_recording_a_payment_keeps_a_cancelled_booking_cancelled(): void
    {
        $booking = $this->book('14:00');
        $this->setStatus($booking, 'cancelled')->assertOk();

        $this->as($this->manager)
            ->putJson("/api/bookings/{$booking->id}", ['amount_paid' => 25, 'status' => 'confirmed', 'change_reason' => 'Cancellation fee'])
            ->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('cancelled', $this->slotStatusOf($booking));
    }

    public function test_a_cancelled_booking_whose_gift_card_was_refunded_cannot_be_reinstated(): void
    {
        $booking = $this->book('14:00');
        $this->setStatus($booking, 'cancelled')->assertOk();
        $card = \Illuminate\Support\Facades\DB::table('gift_cards')->insertGetId([
            'code' => 'REFUND-' . uniqid(),
            'type' => 'fixed',
            'initial_value' => 50,
            'balance' => 50,
            'max_usage' => 1,
            'status' => 'active',
            'created_by' => $this->manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('gift_card_reversals')->insert([
            'gift_card_id' => $card,
            'payable_type' => \App\Models\Payment::TYPE_BOOKING,
            'payable_id' => $booking->id,
            'amount' => 50,
            'credited' => 50,
            'reason' => 'booking_cancelled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->setStatus($booking, 'confirmed')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This record was cancelled and its gift card refunded. It cannot be reactivated - create a new one so the gift card is redeemed again.');

        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_clearing_the_space_releases_its_slot(): void
    {
        $booking = $this->book('14:00');

        $this->as($this->manager)
            ->putJson("/api/bookings/{$booking->id}", ['room_id' => null, 'change_reason' => 'Party moved outdoors'])
            ->assertOk();

        $this->assertNull($booking->fresh()->room_id);
        $this->assertNull($this->slotStatusOf($booking), 'the space is no longer held');
        $this->book('15:00');
    }

    public function test_a_slot_belongs_to_its_bookings_location_even_if_the_space_moves(): void
    {
        $slot = PackageTimeSlot::where('booking_id', $this->book('14:00')->id)->firstOrFail();
        $this->room->update(['location_id' => $this->brighton->id]);

        $this->as($this->staff('attendant', $this->brighton))->deleteJson("/api/package-time-slots/{$slot->id}")->assertForbidden();
        $this->as($this->staff('attendant', $this->waterford))->deleteJson("/api/package-time-slots/{$slot->id}")->assertOk();
    }

    public function test_a_moved_bookings_slot_belongs_to_its_new_location(): void
    {
        $brightonRoom = Room::create([
            'location_id' => $this->brighton->id,
            'name' => 'Brighton Room',
            'capacity' => 30,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
        $booking = $this->book('14:00');

        $this->as($this->staff('company_admin', $this->waterford))
            ->patchJson("/api/bookings/{$booking->id}/location", ['location_id' => $this->brighton->id, 'room_id' => $brightonRoom->id, 'change_reason' => 'Guest moved venues'])
            ->assertOk();

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame($brightonRoom->id, (int) $slot->room_id);
        $this->assertSame($this->party->id, (int) $slot->package_id, 'the slot still names the Waterford package');

        $this->as($this->staff('attendant', $this->waterford))->deleteJson("/api/package-time-slots/{$slot->id}")->assertForbidden();
        $this->as($this->staff('attendant', $this->brighton))->deleteJson("/api/package-time-slots/{$slot->id}")->assertOk();
        $this->assertSame($this->brighton->id, (int) \App\Models\ActivityLog::where('action', 'Package Time Slot Deleted')->where('entity_id', $slot->id)->value('location_id'));
    }

    public function test_only_staff_who_work_at_the_slots_location_can_delete_it(): void
    {
        $slot = PackageTimeSlot::where('booking_id', $this->book('14:00')->id)->firstOrFail();

        $elsewhere = Company::create(['company_name' => 'Elsewhere Co', 'email' => 'owner.' . uniqid() . '@elsewhere.test', 'phone' => '5559870000', 'address' => '9 Far Rd']);
        $outsider = $this->staff('company_admin', $this->makeLocation($elsewhere, 'Lansing'), $elsewhere);

        $this->as($this->staff('location_manager', $this->brighton))->deleteJson("/api/package-time-slots/{$slot->id}")->assertForbidden();
        $this->as($this->staff('attendant', $this->brighton))->deleteJson("/api/package-time-slots/{$slot->id}")->assertForbidden();
        $this->as($outsider)->deleteJson("/api/package-time-slots/{$slot->id}")->assertForbidden();
        $this->assertNotNull(PackageTimeSlot::find($slot->id), 'the space stays held for the booking');

        $this->as($this->staff('attendant', $this->waterford))->deleteJson("/api/package-time-slots/{$slot->id}")->assertOk();
        $this->assertNull(PackageTimeSlot::find($slot->id));
        $this->assertSame($this->waterford->id, (int) \App\Models\ActivityLog::where('action', 'Package Time Slot Deleted')->where('entity_id', $slot->id)->value('location_id'));
    }
}
