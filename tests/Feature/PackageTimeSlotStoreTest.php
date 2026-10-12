<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageTimeSlotStoreTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Package $package;

    private Room $room;

    private Room $otherRoom;

    private Customer $customer;

    private User $attendant;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = Carbon::today()->addDays(9)->toDateString();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $company->id,
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

        $this->room = Room::create(['location_id' => $this->location->id, 'name' => 'Party Room 1', 'capacity' => 20, 'is_available' => true, 'booking_interval' => 15]);
        $this->otherRoom = Room::create(['location_id' => $this->location->id, 'name' => 'Arcade Corner', 'capacity' => 20, 'is_available' => true, 'booking_interval' => 15]);

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

        $this->customer = Customer::create([
            'first_name' => 'Jamie',
            'last_name' => 'Guest',
            'email' => 'jamie@example.com',
            'phone' => '2485559999',
            'password' => 'Password123!',
        ]);

        $this->attendant = User::create([
            'company_id' => $company->id,
            'location_id' => $this->location->id,
            'first_name' => 'Attendant',
            'last_name' => 'Tester',
            'email' => 'attendant@zapzone.test',
            'password' => 'Password123!',
            'role' => 'attendant',
            'status' => 'active',
        ]);
    }

    private function booking(string $time, ?int $roomId = null): Booking
    {
        $token = $this->attendant->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $id = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->postJson('/api/bookings', [
            'customer_id' => $this->customer->id,
            'guest_name' => 'Jamie Guest',
            'guest_email' => 'jamie@example.com',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $roomId,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => $time,
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
        ])->assertSuccessful()->json('data.id');

        $this->app['auth']->forgetGuards();

        return Booking::findOrFail($id);
    }

    private function slotFor(Booking $booking, array $overrides = []): array
    {
        return array_merge([
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'booked_date' => $this->date,
            'time_slot_start' => '14:00',
            'duration' => 2,
            'duration_unit' => 'hours',
        ], $overrides);
    }

    public function test_a_public_caller_can_only_record_the_slot_its_booking_already_describes(): void
    {
        $booking = $this->booking('14:00');

        $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->postJson('/api/package-time-slots', $this->slotFor($booking, ['time_slot_start' => '16:00']))
            ->assertStatus(422);
        $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->postJson('/api/package-time-slots', $this->slotFor($booking, ['room_id' => $this->otherRoom->id]))
            ->assertStatus(422);
        $this->assertSame(0, PackageTimeSlot::count());

        $created = $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->postJson('/api/package-time-slots', $this->slotFor($booking, ['duration' => 8, 'duration_unit' => 'hours']))
            ->assertCreated()
            ->assertJsonMissingPath('data.customer_id')
            ->json('data.id');

        $slot = PackageTimeSlot::findOrFail($created);
        $this->assertSame(120.0, (float) $slot->duration);
        $this->assertSame('minutes', $slot->duration_unit);

        $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->postJson('/api/package-time-slots', $this->slotFor($booking))
            ->assertOk()
            ->assertJsonPath('data.id', $created);
        $this->assertSame(1, PackageTimeSlot::count());
    }

    public function test_a_booking_that_already_has_its_room_slot_is_not_doubled(): void
    {
        $booking = $this->booking('16:00', $this->room->id);
        $this->assertSame(1, PackageTimeSlot::where('booking_id', $booking->id)->count());

        $this->withHeaders(['Authorization' => '', 'Accept' => 'application/json'])
            ->postJson('/api/package-time-slots', $this->slotFor($booking, ['time_slot_start' => '16:00']))
            ->assertOk();

        $this->assertSame(1, PackageTimeSlot::where('booking_id', $booking->id)->count());
    }
}
