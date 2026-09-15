<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkInBookingTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Package $package;

    private Room $room;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $company->id,
            'name' => 'ZapZone Waterford',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
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
            'name' => 'Walk-in Party',
            'description' => 'Test package',
            'category' => 'party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Walk In Guest',
            'guest_phone' => '2485559999',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => '2026-09-20',
            'booking_time' => '18:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
        ], $overrides);
    }

    public function test_staff_can_book_a_walk_in_with_no_email(): void
    {
        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/bookings', $this->payload());

        $response->assertSuccessful();

        $this->assertDatabaseHas('bookings', [
            'guest_name' => 'Walk In Guest',
            'guest_email' => null,
        ]);
    }

    public function test_an_empty_email_string_from_the_form_is_accepted(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/bookings', $this->payload(['guest_email' => '']))
            ->assertSuccessful();

        $this->assertDatabaseHas('bookings', [
            'guest_name' => 'Walk In Guest',
            'guest_email' => null,
        ]);
    }

    public function test_an_online_booking_still_requires_an_email(): void
    {
        $this->postJson('/api/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_email');
    }

    public function test_two_walk_ins_in_the_same_slot_both_get_created(): void
    {
        $second = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room 2',
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/bookings', $this->payload(['guest_name' => 'First Family']))
            ->assertSuccessful();

        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/bookings', $this->payload([
                'guest_name' => 'Second Family',
                'room_id' => $second->id,
            ]))
            ->assertSuccessful();

        $this->assertSame(
            2,
            Booking::whereNull('guest_email')->count(),
            'a walk-in with no email must never be mistaken for an earlier one'
        );
        $this->assertSame(1, Booking::where('guest_name', 'First Family')->count());
        $this->assertSame(1, Booking::where('guest_name', 'Second Family')->count());
    }

    public function test_duplicate_detection_still_works_when_an_email_is_given(): void
    {
        $withEmail = $this->payload([
            'guest_name' => 'Same Guest',
            'guest_email' => 'same.guest@example.test',
        ]);

        $this->actingAs($this->staff, 'sanctum')->postJson('/api/bookings', $withEmail)->assertSuccessful();
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/bookings', $withEmail)->assertSuccessful();

        $this->assertSame(
            1,
            Booking::where('guest_email', 'same.guest@example.test')->count(),
            'the same guest submitting the same slot twice must still be de-duplicated'
        );
    }
}
