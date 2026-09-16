<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DayOff;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Room;
use App\Models\User;
use App\Services\Schedule\ScheduleDayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleDayWindowTest extends TestCase
{
    use RefreshDatabase;

    private const SUNDAY = '2026-09-13';

    private Location $location;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $company = Company::create([
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
    }

    private function room(string $name, bool $available = true): Room
    {
        return Room::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'capacity' => 20,
            'is_available' => $available,
            'booking_interval' => 15,
        ]);
    }

    private function package(string $name, string $start, string $end, array $rooms, int $interval = 15, array $days = ['sunday']): Package
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => $name,
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

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'weekly',
            'day_configuration' => $days,
            'time_slot_start' => $start,
            'time_slot_end' => $end,
            'time_slot_interval' => $interval,
            'priority' => 0,
            'is_active' => true,
        ]);

        if ($rooms !== []) {
            $package->rooms()->sync(collect($rooms)->pluck('id')->all());
        }

        return $package;
    }

    private function window(): array
    {
        return app(ScheduleDayWindow::class)->forDate($this->location->id, self::SUNDAY);
    }

    public function test_the_day_window_comes_from_the_package_schedules(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        $window = $this->window();

        $this->assertTrue($window['has_schedule']);
        $this->assertSame(12 * 60, $window['open_minutes']);
        $this->assertSame(18 * 60, $window['close_minutes']);
        $this->assertSame(15, $window['interval_minutes']);
    }

    public function test_a_room_serving_two_packages_spans_both_windows(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);
        $this->package('Evening', '16:00', '21:00', [$room]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertSame(12 * 60, $rooms[$room->id]['open_minutes']);
        $this->assertSame(21 * 60, $rooms[$room->id]['close_minutes']);
    }

    public function test_a_room_with_no_package_is_still_listed(): void
    {
        $booked = $this->room('Party Room 1');
        $idle = $this->room('Quiet Room');
        $this->package('Afternoon', '12:00', '18:00', [$booked]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertCount(2, $rooms);
        $this->assertNull($rooms[$idle->id]['open_minutes']);
        $this->assertSame('No package scheduled', $rooms[$idle->id]['reason']);
    }

    public function test_an_overnight_window_runs_past_midnight(): void
    {
        $room = $this->room('Late Room');
        $this->package('Late night', '20:00', '02:00', [$room]);

        $window = $this->window();

        $this->assertSame(20 * 60, $window['open_minutes']);
        $this->assertSame(26 * 60, $window['close_minutes']);
        $this->assertGreaterThan(ScheduleDayWindow::MINUTES_PER_DAY, $window['close_minutes']);
    }

    public function test_a_day_with_no_schedule_falls_back_to_a_usable_window(): void
    {
        $this->room('Party Room 1');
        $this->package('Weekday only', '12:00', '18:00', [], 15, ['monday']);

        $window = $this->window();

        $this->assertFalse($window['has_schedule']);
        $this->assertSame(ScheduleDayWindow::FALLBACK_OPEN, $window['open_minutes']);
        $this->assertSame(ScheduleDayWindow::FALLBACK_CLOSE, $window['close_minutes']);
    }

    public function test_a_full_day_closure_marks_every_room_closed(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'reason' => 'Maintenance',
            'is_recurring' => false,
        ]);

        $window = $this->window();

        $this->assertTrue($window['location_closed']);
        $this->assertTrue($window['rooms'][0]['closed_all_day']);
        $this->assertFalse($window['rooms'][0]['bookable']);
        $this->assertSame('Maintenance', $window['rooms'][0]['reason'], 'the day-off reason is shown so staff know why');
    }

    public function test_a_delayed_opening_shortens_the_window_instead_of_closing_the_day(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_end' => '14:00',
            'reason' => 'Opens late',
            'is_recurring' => false,
        ]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertFalse($rooms[$room->id]['closed_all_day'], 'a delayed opening must not close the whole day');
        $this->assertSame(14 * 60, $rooms[$room->id]['open_minutes']);
        $this->assertSame(18 * 60, $rooms[$room->id]['close_minutes']);
        $this->assertTrue($rooms[$room->id]['bookable']);
    }

    public function test_an_early_close_shortens_the_window_instead_of_closing_the_day(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '16:00',
            'reason' => 'Closes early',
            'is_recurring' => false,
        ]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertFalse($rooms[$room->id]['closed_all_day'], 'an early close must not close the whole day');
        $this->assertSame(12 * 60, $rooms[$room->id]['open_minutes']);
        $this->assertSame(16 * 60, $rooms[$room->id]['close_minutes']);
    }

    public function test_a_maintenance_block_is_carved_out_not_applied_to_the_whole_day(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '13:00',
            'time_end' => '14:00',
            'reason' => 'Maintenance',
            'is_recurring' => false,
        ]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');
        $entry = $rooms[$room->id];

        $this->assertFalse($entry['closed_all_day'], 'a one-hour block must not close the whole day');
        $this->assertSame(12 * 60, $entry['open_minutes']);
        $this->assertSame(18 * 60, $entry['close_minutes']);
        $this->assertCount(1, $entry['closed_ranges']);
        $this->assertSame(13 * 60, $entry['closed_ranges'][0]['start_minutes']);
        $this->assertSame(14 * 60, $entry['closed_ranges'][0]['end_minutes']);
        $this->assertSame('Maintenance', $entry['closed_ranges'][0]['reason']);
    }

    public function test_a_room_with_no_package_is_reported_unbookable(): void
    {
        $booked = $this->room('Party Room 1');
        $idle = $this->room('Quiet Room');
        $this->package('Afternoon', '12:00', '18:00', [$booked]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertFalse($rooms[$idle->id]['bookable'], 'a room with no package must never read as free');
        $this->assertSame('No package scheduled', $rooms[$idle->id]['reason']);
        $this->assertTrue($rooms[$booked->id]['bookable']);
    }

    public function test_an_unavailable_room_is_reported_closed(): void
    {
        $room = $this->room('Out of service', false);
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertTrue($rooms[$room->id]['closed_all_day']);
        $this->assertSame('Space unavailable', $rooms[$room->id]['reason']);
    }

    public function test_a_package_only_day_off_closes_just_that_package(): void
    {
        $room = $this->room('Party Room 1');
        $morning = $this->package('Morning', '10:00', '14:00', [$room]);
        $this->package('Evening', '16:00', '21:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'reason' => 'Equipment service',
            'is_recurring' => false,
            'package_ids' => [$morning->id],
        ]);

        $window = $this->window();
        $packageIds = collect($window['packages'])->pluck('package_id')->all();

        $this->assertNotContains($morning->id, $packageIds, 'a package closed for the day must not be offered');
        $this->assertCount(1, $packageIds, 'the other package still runs');
        $this->assertFalse($window['location_closed'], 'a package day-off must not close the whole location');

        $rooms = collect($window['rooms'])->keyBy('room_id');
        $this->assertTrue($rooms[$room->id]['bookable'], 'the room stays bookable through the package that is still running');
        $this->assertSame(16 * 60, $rooms[$room->id]['open_minutes'], 'the room window follows the package that is still running');
    }

    public function test_a_package_day_off_that_closes_early_narrows_that_package(): void
    {
        $room = $this->room('Party Room 1');
        $package = $this->package('All day', '10:00', '22:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '18:00',
            'reason' => 'Staff training',
            'is_recurring' => false,
            'package_ids' => [$package->id],
        ]);

        $entry = collect($this->window()['packages'])->firstWhere('package_id', $package->id);

        $this->assertNotNull($entry);
        $this->assertSame(10 * 60, $entry['open_minutes']);
        $this->assertSame(18 * 60, $entry['close_minutes'], 'the package must stop being offered when it closes early');
    }

    public function test_a_package_day_off_time_range_is_published_on_the_package(): void
    {
        $room = $this->room('Party Room 1');
        $package = $this->package('All day', '10:00', '22:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '13:00',
            'time_end' => '15:00',
            'reason' => 'Private hire',
            'is_recurring' => false,
            'package_ids' => [$package->id],
        ]);

        $entry = collect($this->window()['packages'])->firstWhere('package_id', $package->id);

        $this->assertNotNull($entry);
        $this->assertCount(1, $entry['closed_ranges']);
        $this->assertSame(13 * 60, $entry['closed_ranges'][0]['start_minutes']);
        $this->assertSame(15 * 60, $entry['closed_ranges'][0]['end_minutes']);
        $this->assertSame('Private hire', $entry['closed_ranges'][0]['reason']);
    }

    public function test_a_room_day_off_does_not_close_a_package(): void
    {
        $closed = $this->room('Party Room 1');
        $open = $this->room('Party Room 2');
        $package = $this->package('All day', '10:00', '22:00', [$closed, $open]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'reason' => 'Burst pipe',
            'is_recurring' => false,
            'room_ids' => [$closed->id],
        ]);

        $window = $this->window();
        $rooms = collect($window['rooms'])->keyBy('room_id');

        $this->assertTrue($rooms[$closed->id]['closed_all_day'], 'the targeted room is closed');
        $this->assertTrue($rooms[$open->id]['bookable'], 'the other room is untouched');
        $this->assertNotNull(
            collect($window['packages'])->firstWhere('package_id', $package->id),
            'a room closure must not withdraw the package everywhere'
        );
    }

    public function test_the_endpoint_returns_the_window_for_staff(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        $staff = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/schedule/day-window?date='.self::SUNDAY.'&location_id='.$this->location->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.open_minutes', 12 * 60)
            ->assertJsonPath('data.close_minutes', 18 * 60)
            ->assertJsonPath('data.has_schedule', true)
            ->assertJsonPath('data.rooms.0.room_id', $room->id);
    }

    public function test_the_endpoint_rejects_a_customer_token(): void
    {
        $customer = Customer::create([
            'first_name' => 'Guest',
            'last_name' => 'Visitor',
            'email' => 'guest@example.test',
            'phone' => '5550000000',
            'password' => bcrypt('secret-password'),
        ]);

        $token = $customer->createToken('customer-app')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/schedule/day-window?date='.self::SUNDAY.'&location_id='.$this->location->id)
            ->assertStatus(403);
    }

    public function test_the_endpoint_rejects_a_staff_user_with_no_company(): void
    {
        $orphan = User::create([
            'first_name' => 'No',
            'last_name' => 'Company',
            'email' => 'orphan@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/schedule/day-window?date='.self::SUNDAY.'&location_id='.$this->location->id)
            ->assertStatus(403);
    }

    public function test_the_endpoint_rejects_a_missing_date(): void
    {
        $staff = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk2@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/schedule/day-window')
            ->assertStatus(422);
    }

    public function test_the_day_window_is_the_package_aggregate_not_the_last_room(): void
    {
        $withPackage = $this->room('Party Room 1');
        $this->room('Quiet Room');
        $this->package('Afternoon', '12:00', '18:00', [$withPackage]);

        $window = $this->window();

        $this->assertTrue($window['has_schedule'], 'a scheduled package must not be reported as no-schedule');
        $this->assertSame(12 * 60, $window['open_minutes'], 'the axis must use the package window, not the last room');
        $this->assertSame(18 * 60, $window['close_minutes']);
    }

    public function test_a_narrow_last_room_does_not_shrink_the_whole_day(): void
    {
        $wide = $this->room('All day room');
        $narrow = $this->room('Evening only');
        $this->package('All day', '10:00', '22:00', [$wide]);
        $this->package('Evening', '18:00', '20:00', [$narrow]);

        $window = $this->window();

        $this->assertSame(10 * 60, $window['open_minutes']);
        $this->assertSame(22 * 60, $window['close_minutes']);
    }

    public function test_a_day_off_at_another_location_never_closes_these_rooms(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Afternoon', '12:00', '18:00', [$room]);

        $otherCompany = Company::create([
            'company_name' => 'Other Co',
            'email' => 'other@zapzone.test',
            'phone' => '5550000000',
            'address' => '9 Other St',
        ]);

        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'ZapZone Brighton',
            'address' => '2 Other Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        DayOff::create([
            'location_id' => $otherLocation->id,
            'date' => self::SUNDAY,
            'reason' => 'Thanksgiving',
            'is_recurring' => false,
        ]);

        $rooms = collect(app(ScheduleDayWindow::class)->forDate(null, self::SUNDAY)['rooms'])->keyBy('room_id');

        $this->assertFalse($rooms[$room->id]['closed_all_day'], 'another location\'s closure must not shut this room');
        $this->assertNotSame('Thanksgiving', $rooms[$room->id]['reason']);
    }

    public function test_an_early_close_after_midnight_trims_an_overnight_window(): void
    {
        $room = $this->room('Late Room');
        $this->package('Late night', '20:00', '02:00', [$room]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '01:00',
            'reason' => 'Closes early',
            'is_recurring' => false,
        ]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');

        $this->assertFalse($rooms[$room->id]['closed_all_day'], 'a 1am close must trim, not shut, an overnight room');
        $this->assertSame(20 * 60, $rooms[$room->id]['open_minutes']);
        $this->assertSame(25 * 60, $rooms[$room->id]['close_minutes'], 'the 1am close is 25:00 in the overnight frame');
    }

    public function test_the_narrowest_interval_wins(): void
    {
        $room = $this->room('Party Room 1');
        $this->package('Coarse', '12:00', '18:00', [$room], 60);
        $this->package('Fine', '13:00', '17:00', [$room], 15);

        $this->assertSame(15, $this->window()['interval_minutes']);
    }

    private function groupedRoom(string $name, int $bookingInterval, string $areaGroup): Room
    {
        return Room::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => $bookingInterval,
            'area_group' => $areaGroup,
        ]);
    }

    private function book(Room $room, Package $package, string $start, int $minutes): void
    {
        // package_time_slots.booking_id is NOT NULL, so the slot needs a booking to hang off.
        // bookings requires booking_date, booking_time, duration, location_id, participants,
        // reference_number and total_amount — every column that is NOT NULL with no default.
        $booking = \App\Models\Booking::create([
            'reference_number' => 'TEST-' . $room->id . '-' . str_replace(':', '', $start),
            'booking_date' => self::SUNDAY,
            'booking_time' => $start,
            'duration' => $minutes,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'room_id' => $room->id,
            'participants' => 1,
            'total_amount' => 0,
            'status' => 'confirmed',
        ]);

        \App\Models\PackageTimeSlot::create([
            'package_id' => $package->id,
            'room_id' => $room->id,
            'booking_id' => $booking->id,
            'booked_date' => self::SUNDAY,
            'time_slot_start' => $start,
            'duration' => $minutes,
            'duration_unit' => 'minutes',
            'status' => 'booked',
        ]);
    }

    /** Runs the real availability rules for one space at one minute. */
    private function bookable(Room $room, string $start): bool
    {
        $probe = new class { use \App\Traits\GeneratesAvailableTimeSlots;
            public function check($roomId, $date, $start, $minutes): bool {
                return ! $this->checkTimeSlotConflict($roomId, $date, $start, $minutes, 'minutes')
                    && ! $this->checkAreaGroupStaggerConflict($roomId, $date, $start);
            }
        };

        return $probe->check($room->id, self::SUNDAY, $start, 60);
    }

    private function spacedRoom(string $name, int $bookingInterval): Room
    {
        return Room::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => $bookingInterval,
        ]);
    }

    private function startsFor(Package $package): array
    {
        return collect($this->window()['packages'])->firstWhere('package_id', $package->id)['start_minutes'];
    }

    /**
     * The reported rule: the schedule interval decides which start times are OFFERED. A space's
     * booking_interval is its turnaround after a booking and must never thin this list.
     */
    public function test_the_schedule_interval_decides_the_offered_start_times(): void
    {
        $package = $this->package('Quarter hour', '12:00', '18:00', [$this->spacedRoom('Space A', 30)], 15);

        $this->assertSame(
            [720, 735, 750, 765, 780, 795, 810, 825, 840, 855, 870, 885, 900, 915, 930, 945, 960],
            $this->startsFor($package),
            'a 30 min space must not collapse a 15 min schedule to a sparse grid'
        );
    }

    public function test_the_space_interval_does_not_change_the_offered_start_times(): void
    {
        $fifteen = $this->package('Fifteen', '12:00', '18:00', [$this->spacedRoom('Space A', 15)], 15);
        $sixty = $this->package('Sixty', '12:00', '18:00', [$this->spacedRoom('Space B', 60)], 15);

        $this->assertSame(
            $this->startsFor($fifteen),
            $this->startsFor($sixty),
            'the space interval is a turnaround, not a grid'
        );
    }

    public function test_one_space_still_offers_every_schedule_slot_before_anything_is_booked(): void
    {
        $package = $this->package('Escape', '16:00', '21:00', [$this->spacedRoom('Space A', 30)], 15);
        $package->update(['duration' => 60, 'duration_unit' => 'minutes']);

        $starts = $this->startsFor($package);

        $this->assertSame(16 * 60, $starts[0]);
        $this->assertSame(15, $starts[1] - $starts[0], 'the next offered start is one schedule interval later');
    }

    public function test_the_package_interval_is_the_schedule_interval(): void
    {
        $package = $this->package('Half hour', '12:00', '18:00', [$this->spacedRoom('Space A', 30)], 15);

        $entry = collect($this->window()['packages'])->firstWhere('package_id', $package->id);

        $this->assertSame(15, $entry['interval_minutes']);
    }

    public function test_a_package_with_no_space_keeps_the_schedule_interval_grid(): void
    {
        $package = $this->package('Roomless', '12:00', '18:00', [], 60);

        $entry = collect($this->window()['packages'])->firstWhere('package_id', $package->id);

        $this->assertSame(60, $entry['interval_minutes']);
        $this->assertSame(
            [12 * 60, 13 * 60, 14 * 60, 15 * 60, 16 * 60],
            $entry['start_minutes'],
            'without spaces the schedule interval still rules, and a start must fit before closing'
        );
    }

    public function test_a_package_day_off_removes_the_starts_it_covers(): void
    {
        $package = $this->package('All day', '10:00', '22:00', [$this->spacedRoom('Space A', 30)]);

        $before = $this->startsFor($package);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '13:00',
            'time_end' => '15:00',
            'reason' => 'Private hire',
            'is_recurring' => false,
            'package_ids' => [$package->id],
        ]);

        $after = $this->startsFor($package);

        $this->assertNotEmpty($after);
        $this->assertLessThan(count($before), count($after));

        foreach ($after as $start) {
            $this->assertFalse(
                $start < 15 * 60 && $start + 120 > 13 * 60,
                "start {$start} overlaps the 13:00-15:00 closure"
            );
        }
    }

    public function test_an_early_close_drops_a_start_that_would_run_past_closing(): void
    {
        $package = $this->package('All day', '10:00', '22:00', [$this->spacedRoom('Space A', 30)]);

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::SUNDAY,
            'time_start' => '16:00',
            'reason' => 'Closing early',
            'is_recurring' => false,
            'package_ids' => [$package->id],
        ]);

        foreach ($this->startsFor($package) as $start) {
            $this->assertLessThanOrEqual(
                16 * 60,
                $start + 120,
                "start {$start} would still be running at the 16:00 close"
            );
        }
    }

    public function test_a_package_whose_only_space_is_unavailable_offers_no_starts(): void
    {
        $package = $this->package('Closed space', '12:00', '18:00', [$this->room('Space A', false)], 60);

        $entry = collect($this->window()['packages'])->firstWhere('package_id', $package->id);

        $this->assertSame(
            [],
            $entry['start_minutes'],
            'the booking page offers nothing when no space is available, so neither may the grid'
        );
    }

    /**
     * The client's staggering rules, as worked examples. The package interval generates the
     * list; the stagger only spaces bookings apart once one exists.
     */
    public function test_one_space_reopens_a_stagger_after_the_booking_ends(): void
    {
        $room = $this->spacedRoom('Solo', 30);
        $package = $this->package('Escape', '16:00', '21:00', [$room], 15);
        $package->update(['duration' => 60, 'duration_unit' => 'minutes']);

        $this->book($room, $package, '16:00', 60);

        $this->assertFalse($this->bookable($room, '17:00'), 'still turning over');
        $this->assertFalse($this->bookable($room, '17:15'), 'still turning over');
        $this->assertTrue($this->bookable($room, '17:30'), 'one hour plus a 30 min stagger');
    }

    public function test_the_turnaround_holds_on_both_sides_of_an_existing_booking(): void
    {
        $room = $this->spacedRoom('Solo', 30);
        $package = $this->package('Escape', '12:00', '22:00', [$room], 15);
        $package->update(['duration' => 60, 'duration_unit' => 'minutes']);

        $this->book($room, $package, '18:00', 60);

        $this->assertFalse(
            $this->bookable($room, '17:00'),
            'a booking ending exactly when the next one starts leaves no turnaround'
        );
        $this->assertTrue($this->bookable($room, '16:30'), 'a full turnaround before it is fine');
    }

    public function test_a_zero_stagger_lets_the_next_booking_start_when_the_last_ends(): void
    {
        $room = $this->spacedRoom('Back to back', 0);
        $package = $this->package('Escape', '16:00', '21:00', [$room], 15);
        $package->update(['duration' => 60, 'duration_unit' => 'minutes']);

        $this->book($room, $package, '16:00', 60);

        $this->assertFalse($this->bookable($room, '16:45'));
        $this->assertTrue($this->bookable($room, '17:00'), 'zero stagger means no gap at all');
    }

    public function test_a_stagger_walks_bookings_across_the_rooms_of_an_area(): void
    {
        $a = $this->groupedRoom('Room A', 15, 'Zone');
        $b = $this->groupedRoom('Room B', 15, 'Zone');
        $c = $this->groupedRoom('Room C', 15, 'Zone');
        $package = $this->package('Escape', '16:00', '21:00', [$a, $b, $c], 15);
        $package->update(['duration' => 60, 'duration_unit' => 'minutes']);

        $this->book($a, $package, '16:00', 60);

        $this->assertFalse($this->bookable($b, '16:05'), 'inside the 15 min stagger');
        $this->assertTrue($this->bookable($b, '16:15'), 'one stagger after room A');

        $this->book($b, $package, '16:15', 60);

        $this->assertTrue($this->bookable($c, '16:30'), 'one stagger after room B');
    }

    public function test_a_space_with_no_area_group_is_staggered_only_against_itself(): void
    {
        $grouped = $this->groupedRoom('Party Table', 15, 'Tables');
        $solo = $this->spacedRoom('Escape Room', 15);
        $package = $this->package('Escape', '16:00', '21:00', [$solo], 15);
        $other = $this->package('Party', '16:00', '21:00', [$grouped], 15);

        $this->book($grouped, $other, '16:00', 60);

        $this->assertTrue(
            $this->bookable($solo, '16:05'),
            'a space in no area must not be staggered against unrelated rooms'
        );
    }

    public function test_each_space_publishes_its_own_booking_interval(): void
    {
        $this->package('Half hour', '12:00', '18:00', [$this->spacedRoom('Space A', 30)]);

        $rooms = collect($this->window()['rooms'])->keyBy('room_id');
        $space = $rooms->first();

        $this->assertSame(30, $space['interval_minutes']);
    }
}
