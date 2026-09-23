<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomUngroupTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Location $location;

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
            'name' => 'Madison Heights | Escape Room',
            'address' => '1 Test Way',
            'city' => 'Madison Heights',
            'state' => 'MI',
            'zip_code' => '48071',
            'phone' => '2485551234',
            'email' => 'madison@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'first_name' => 'Company',
            'last_name' => 'Admin',
            'email' => 'admin.user@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function rageRoom(string $name): Room
    {
        return Room::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'capacity' => 20,
            'is_available' => true,
            'area_group' => 'Rage',
            'booking_interval' => 30,
            'break_time' => [['start' => '12:00', 'end' => '12:30']],
        ]);
    }

    public function test_a_space_can_be_taken_out_of_its_area_group(): void
    {
        $room = $this->rageRoom('Rage Room 2');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$room->id}", [
                'name' => 'Rage Room 2',
                'capacity' => 20,
                'is_available' => true,
                'break_time' => [['start' => '12:00', 'end' => '12:30']],
                'area_group' => null,
                'booking_interval' => 30,
            ])
            ->assertOk();

        $this->assertNull($room->fresh()->area_group);
    }

    public function test_an_ungrouped_space_no_longer_staggers_against_its_old_area(): void
    {
        $kept = $this->rageRoom('Rage Room 1');
        $freed = $this->rageRoom('Rage Room 2');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$freed->id}", ['area_group' => null])
            ->assertOk();

        $freed->refresh();

        $this->assertNull($freed->area_group);
        $this->assertNotContains($freed->id, $kept->fresh()->getRoomsInSameAreaGroup()->pluck('id')->all());
        $this->assertSame([$freed->id], $freed->getRoomsInSameAreaGroup()->pluck('id')->all());
    }

    public function test_breaks_can_be_removed_from_a_space(): void
    {
        $room = $this->rageRoom('Rage Room 3');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['break_time' => []])
            ->assertOk();

        $this->assertSame([], $room->fresh()->break_time);
    }

    public function test_capacity_can_be_cleared_on_a_space(): void
    {
        $room = $this->rageRoom('Rage Room 4');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['capacity' => null])
            ->assertOk();

        $this->assertNull($room->fresh()->capacity);
    }

    public function test_an_area_can_be_set_to_no_turnaround_at_all(): void
    {
        $a = $this->rageRoom('Rage Room 6');
        $b = $this->rageRoom('Rage Room 7');

        $this->actingAs($this->staff, 'sanctum')
            ->patchJson('/api/rooms/area-group/Rage/update-booking-interval', [
                'booking_interval' => 0,
                'location_id' => $this->location->id,
            ])
            ->assertOk();

        $this->assertSame(0, (int) $a->fresh()->booking_interval);
        $this->assertSame(0, (int) $b->fresh()->booking_interval);
    }

    public function test_a_single_space_can_be_set_to_no_turnaround(): void
    {
        $room = $this->rageRoom('Rage Room 8');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['booking_interval' => 0])
            ->assertOk();

        $this->assertSame(0, (int) $room->fresh()->booking_interval);
    }

    public function test_a_field_left_out_of_the_request_is_not_touched(): void
    {
        $room = $this->rageRoom('Rage Room 5');

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/rooms/{$room->id}", ['name' => 'Renamed'])
            ->assertOk();

        $room->refresh();

        $this->assertSame('Renamed', $room->name);
        $this->assertSame('Rage', $room->area_group);
        $this->assertSame(20, (int) $room->capacity);
    }
}
