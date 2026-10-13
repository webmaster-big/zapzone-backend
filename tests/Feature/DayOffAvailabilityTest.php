<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DayOff;
use App\Models\DayOffChangeLog;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DayOffAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $waterford;

    private User $manager;

    private Package $birthday;

    private Package $vip;

    private Room $partyRoom;

    private Attraction $laserTag;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->brighton = $this->venue('Brighton');
        $this->waterford = $this->venue('Waterford');
        $this->manager = $this->user('manager', 'location_manager', $this->brighton);

        $this->birthday = $this->package('Basic Birthday', $this->brighton);
        $this->vip = $this->package('VIP Birthday', $this->brighton);
        $this->partyRoom = Room::create([
            'location_id' => $this->brighton->id,
            'name' => 'Party Room 1',
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
        $this->laserTag = Attraction::create([
            'location_id' => $this->brighton->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $this->date = Carbon::today()->addDays(20)->toDateString();
    }

    private function venue(string $city): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => "ZapZone {$city}",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function user(string $handle, string $role, ?Location $location): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'first_name' => ucfirst($handle),
            'last_name' => 'Tester',
            'email' => "{$handle}@zapzone.test",
            'password' => 'Password123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function package(string $name, Location $location): Package
    {
        return Package::create([
            'location_id' => $location->id,
            'name' => $name,
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
    }

    private function as(User|Customer $user): static
    {
        $token = $user->createToken('test')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function block(array $overrides = []): array
    {
        return array_merge([
            'location_id' => $this->brighton->id,
            'date' => $this->date,
            'block_type' => 'full_day',
            'scope' => 'location',
            'reason' => 'Private buyout',
            'group_name' => 'Acme Corp Holiday Party',
            'is_recurring' => false,
        ], $overrides);
    }

    private function create(array $overrides = [], ?User $as = null)
    {
        return $this->as($as ?? $this->manager)->postJson('/api/day-offs', $this->block($overrides));
    }

    public function test_a_manager_blocks_the_whole_location_for_a_day_and_it_is_logged_permanently(): void
    {
        $response = $this->create()->assertCreated();

        $response->assertJsonPath('data.block_type', 'full_day')
            ->assertJsonPath('data.group_name', 'Acme Corp Holiday Party')
            ->assertJsonPath('data.reason', 'Private buyout')
            ->assertJsonPath('data.scope_label', 'Entire location')
            ->assertJsonPath('data.window_label', 'Closed all day')
            ->assertJsonPath('data.set_by.id', $this->manager->id)
            ->assertJsonPath('data.set_by.name', 'Manager Tester');

        $dayOff = DayOff::sole();
        $this->assertSame($this->manager->id, (int) $dayOff->created_by);
        $this->assertSame($this->manager->id, (int) $dayOff->updated_by);

        $log = DayOffChangeLog::sole();
        $this->assertSame('created', $log->action);
        $this->assertSame($dayOff->id, (int) $log->day_off_id);
        $this->assertSame($this->brighton->id, (int) $log->location_id);
        $this->assertSame($this->manager->id, (int) $log->changed_by);
        $this->assertSame('Manager Tester', $log->changed_by_name);
        $this->assertSame('location_manager', $log->changed_by_role);
        $this->assertNull($log->before);
        $this->assertSame('Acme Corp Holiday Party', $log->after['group_name']);
        $this->assertStringContainsString('Entire location', $log->summary);

        $this->assertSame(1, ActivityLog::where('entity_type', 'day_off')->where('action', 'Day Off Created')->count());
    }

    public function test_reason_group_name_kind_and_scope_are_all_required(): void
    {
        foreach (['reason', 'group_name', 'block_type', 'scope'] as $field) {
            $payload = $this->block();
            unset($payload[$field]);

            $this->as($this->manager)->postJson('/api/day-offs', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->create(['reason' => '   '])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->create(['group_name' => ''])->assertStatus(422)->assertJsonValidationErrors('group_name');

        $this->assertSame(0, DayOff::count());
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_older_closed_hours_fields_are_refused_so_a_day_cannot_be_split(): void
    {
        $this->create(['time_start' => '12:00', 'time_end' => '15:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['time_start', 'time_end']);

        $this->assertSame(0, DayOff::count());
    }

    public function test_one_continuous_operating_window(): void
    {
        $this->create(['block_type' => 'operating_window', 'opens_at' => '12:00', 'closes_at' => '18:00'])
            ->assertCreated()
            ->assertJsonPath('data.block_type', 'operating_window')
            ->assertJsonPath('data.window_label', 'Open only 12:00 PM – 6:00 PM')
            ->assertJsonPath('data.opens_at', '12:00')
            ->assertJsonPath('data.closes_at', '18:00')
            ->assertJsonPath('data.blocked_ranges', [
                ['start_minutes' => 0, 'end_minutes' => 720],
                ['start_minutes' => 1080, 'end_minutes' => 1440],
            ]);

        $dayOff = DayOff::sole();
        $this->assertNull($dayOff->time_start);
        $this->assertNull($dayOff->time_end);
    }

    public function test_a_window_must_actually_block_something_and_close_after_it_opens(): void
    {
        $this->create(['block_type' => 'operating_window'])
            ->assertStatus(422)->assertJsonValidationErrors('opens_at');

        $this->create(['block_type' => 'operating_window', 'opens_at' => '18:00', 'closes_at' => '12:00'])
            ->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $this->create(['block_type' => 'operating_window', 'opens_at' => '12:00', 'closes_at' => '12:00'])
            ->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $this->create(['block_type' => 'operating_window', 'opens_at' => '00:00'])
            ->assertStatus(422)->assertJsonValidationErrors('opens_at');

        $this->create(['block_type' => 'operating_window', 'closes_at' => '00:00'])
            ->assertStatus(422)->assertJsonValidationErrors('opens_at');

        $this->assertSame(0, DayOff::count());

        $this->create(['block_type' => 'operating_window', 'opens_at' => '18:00', 'closes_at' => '00:00'])
            ->assertCreated()
            ->assertJsonPath('data.window_label', 'Open only 6:00 PM – midnight');
    }

    public function test_a_full_day_closure_ignores_any_stray_times(): void
    {
        $this->create(['block_type' => 'full_day', 'opens_at' => '12:00', 'closes_at' => '18:00'])->assertCreated();

        $dayOff = DayOff::sole();
        $this->assertNull($dayOff->opens_at);
        $this->assertNull($dayOff->closes_at);
        $this->assertTrue($dayOff->isFullDay());
    }

    public function test_packages_rooms_and_attractions_can_be_blocked(): void
    {
        $this->create(['scope' => 'packages', 'package_ids' => [$this->birthday->id, $this->vip->id]])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'packages')
            ->assertJsonPath('data.scope_label', 'Packages: Basic Birthday, VIP Birthday');

        $this->create(['scope' => 'rooms', 'room_ids' => [$this->partyRoom->id]])
            ->assertCreated()
            ->assertJsonPath('data.scope_label', 'Space: Party Room 1');

        $this->create(['scope' => 'attractions', 'attraction_ids' => [$this->laserTag->id], 'block_type' => 'operating_window', 'closes_at' => '17:00'])
            ->assertCreated()
            ->assertJsonPath('data.scope_label', 'Attraction: Laser Tag')
            ->assertJsonPath('data.window_label', 'Closes early at 5:00 PM');

        $this->assertSame(3, DayOff::count());
    }

    public function test_a_targeted_scope_with_nothing_picked_is_never_turned_into_a_whole_location_block(): void
    {
        $this->create(['scope' => 'packages'])->assertStatus(422)->assertJsonValidationErrors('package_ids');
        $this->create(['scope' => 'packages', 'package_ids' => []])->assertStatus(422)->assertJsonValidationErrors('package_ids');
        $this->create(['scope' => 'rooms', 'room_ids' => null])->assertStatus(422)->assertJsonValidationErrors('room_ids');

        $this->assertSame(0, DayOff::count());
    }

    public function test_only_the_chosen_scope_is_stored(): void
    {
        $this->create([
            'scope' => 'rooms',
            'room_ids' => [$this->partyRoom->id],
            'package_ids' => [$this->birthday->id],
        ])->assertCreated();

        $dayOff = DayOff::sole();
        $this->assertNull($dayOff->package_ids);
        $this->assertSame([$this->partyRoom->id], $dayOff->room_ids);
    }

    public function test_items_from_another_location_are_refused(): void
    {
        $waterfordPackage = $this->package('Waterford Party', $this->waterford);

        $this->create(['scope' => 'packages', 'package_ids' => [$this->birthday->id, $waterfordPackage->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('package_ids');

        $this->assertSame(0, DayOff::count());
    }

    public function test_one_block_per_item_per_day(): void
    {
        $first = $this->create()->assertCreated();

        $this->create(['block_type' => 'operating_window', 'opens_at' => '15:00'])
            ->assertStatus(422)
            ->assertJsonPath('conflict_day_off_id', $first->json('data.id'))
            ->assertJsonValidationErrors('date');

        $this->create(['scope' => 'packages', 'package_ids' => [$this->birthday->id]])->assertCreated();

        $this->create(['scope' => 'packages', 'package_ids' => [$this->vip->id, $this->birthday->id], 'block_type' => 'operating_window', 'closes_at' => '17:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Package "Basic Birthday"'));

        $this->create(['scope' => 'packages', 'package_ids' => [$this->vip->id]])->assertCreated();

        $this->create(['date' => Carbon::parse($this->date)->addDay()->toDateString()])->assertCreated();

        $this->assertSame(4, DayOff::count());
    }

    public function test_a_yearly_block_counts_on_every_year_it_covers(): void
    {
        $this->create(['is_recurring' => true])->assertCreated();

        $nextYear = Carbon::parse($this->date)->addYear()->toDateString();
        $this->create(['date' => $nextYear])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->create(['date' => $nextYear, 'scope' => 'rooms', 'room_ids' => [$this->partyRoom->id]])->assertCreated();
    }

    public function test_a_new_yearly_block_is_checked_against_upcoming_one_off_blocks_but_not_old_ones(): void
    {
        $lastYear = Carbon::parse($this->date)->subYear()->toDateString();
        DayOff::create(['location_id' => $this->brighton->id, 'date' => $lastYear, 'reason' => 'Old', 'group_name' => 'Old group']);

        $this->create(['is_recurring' => true])->assertCreated();

        DayOff::query()->delete();

        $nextYear = Carbon::parse($this->date)->addYear()->toDateString();
        DayOff::create(['location_id' => $this->brighton->id, 'date' => $nextYear, 'reason' => 'Upcoming', 'group_name' => 'Upcoming group']);

        $this->create(['is_recurring' => true])->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_only_managers_and_admins_can_change_availability(): void
    {
        $attendant = $this->user('attendant', 'attendant', $this->brighton);
        $this->create([], $attendant)->assertForbidden();

        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        $this->as($customer)->postJson('/api/day-offs', $this->block())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->postJson('/api/day-offs', $this->block())->assertUnauthorized();

        $admin = $this->user('admin', 'company_admin', null);
        $this->create(['location_id' => $this->waterford->id], $admin)->assertCreated();

        $this->assertSame(1, DayOff::count());
    }

    public function test_a_manager_cannot_block_another_location(): void
    {
        $this->create(['location_id' => $this->waterford->id])->assertForbidden();
        $this->assertSame(0, DayOff::count());
    }

    public function test_attendants_can_read_blocks_but_not_the_change_log(): void
    {
        $this->create()->assertCreated();
        $attendant = $this->user('attendant', 'attendant', $this->brighton);

        $this->as($attendant)->getJson('/api/day-offs')->assertOk()->assertJsonCount(1, 'data.day_offs');
        $this->as($attendant)->getJson('/api/day-offs/change-log')->assertForbidden();
    }

    public function test_changes_record_who_changed_what(): void
    {
        $id = $this->create()->json('data.id');
        $admin = $this->user('admin', 'company_admin', null);

        $this->as($admin)->putJson("/api/day-offs/{$id}", [
            'block_type' => 'operating_window',
            'opens_at' => '14:00',
            'closes_at' => '20:00',
            'group_name' => 'Acme Corp (moved)',
        ])->assertOk()
            ->assertJsonPath('data.window_label', 'Open only 2:00 PM – 8:00 PM')
            ->assertJsonPath('data.set_by.id', $this->manager->id)
            ->assertJsonPath('data.last_changed_by.id', $admin->id);

        $log = DayOffChangeLog::where('action', 'updated')->sole();
        $this->assertSame($admin->id, (int) $log->changed_by);
        $this->assertSame('full_day', $log->before['block_type']);
        $this->assertSame('operating_window', $log->after['block_type']);
        $this->assertEqualsCanonicalizing(['block_type', 'opens_at', 'closes_at', 'group_name', 'updated_by'], $log->changed_fields);
    }

    public function test_saving_without_a_change_writes_nothing(): void
    {
        $id = $this->create()->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", [
            'reason' => 'Private buyout',
            'block_type' => 'full_day',
            'scope' => 'location',
            'date' => $this->date,
        ])->assertOk()->assertJsonPath('message', 'Nothing changed');

        $this->assertSame(1, DayOffChangeLog::count());
    }

    public function test_changing_hours_or_targets_needs_the_kind_or_scope(): void
    {
        $id = $this->create()->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['opens_at' => '12:00'])
            ->assertStatus(422)->assertJsonValidationErrors('block_type');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['package_ids' => [$this->birthday->id]])
            ->assertStatus(422)->assertJsonValidationErrors('scope');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['scope' => 'packages', 'package_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('package_ids');
    }

    public function test_moving_a_block_onto_an_item_that_already_has_one_is_refused(): void
    {
        $this->create()->assertCreated();
        $other = $this->create(['date' => Carbon::parse($this->date)->addDay()->toDateString()])->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$other}", ['date' => $this->date])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertSame(Carbon::parse($this->date)->addDay()->toDateString(), DayOff::find($other)->date->toDateString());
    }

    public function test_an_older_block_needs_a_group_name_and_a_new_format_before_it_moves(): void
    {
        $legacy = DayOff::create([
            'location_id' => $this->brighton->id,
            'date' => $this->date,
            'time_start' => '15:00',
            'time_end' => '21:00',
            'reason' => 'Private event',
        ]);

        $this->as($this->manager)->putJson("/api/day-offs/{$legacy->id}", ['reason' => 'Private event in the arena'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('group_name');

        $this->as($this->manager)->putJson("/api/day-offs/{$legacy->id}", [
            'group_name' => 'Smith Wedding',
            'date' => Carbon::parse($this->date)->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('block_type');

        $this->as($this->manager)->putJson("/api/day-offs/{$legacy->id}", ['group_name' => 'Smith Wedding'])
            ->assertOk()
            ->assertJsonPath('data.block_type', 'legacy_closure')
            ->assertJsonPath('data.window_label', 'Closed 3:00 PM – 9:00 PM');

        $this->as($this->manager)->putJson("/api/day-offs/{$legacy->id}", [
            'block_type' => 'operating_window',
            'closes_at' => '15:00',
        ])->assertOk()->assertJsonPath('data.block_type', 'operating_window');

        $legacy->refresh();
        $this->assertNull($legacy->time_start);
        $this->assertNull($legacy->time_end);
        $this->assertSame('15:00', DayOff::shortClock($legacy->closes_at));
    }

    public function test_deleting_keeps_the_record_of_what_was_blocked(): void
    {
        $id = $this->create()->json('data.id');

        $this->as($this->manager)->deleteJson("/api/day-offs/{$id}")->assertOk();

        $this->assertNull(DayOff::find($id));
        $log = DayOffChangeLog::where('action', 'deleted')->sole();
        $this->assertSame($id, (int) $log->day_off_id);
        $this->assertSame('Acme Corp Holiday Party', $log->before['group_name']);
        $this->assertNull($log->after);

        $this->as($this->manager)->getJson("/api/day-offs/change-log?day_off_id={$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.changes')
            ->assertJsonPath('data.changes.0.action', 'deleted')
            ->assertJsonPath('data.changes.1.action', 'created')
            ->assertJsonPath('data.changes.0.changed_by.name', 'Manager Tester');
    }

    public function test_bulk_delete_logs_every_block_and_skips_other_locations(): void
    {
        $mine = $this->create()->json('data.id');
        $admin = $this->user('admin', 'company_admin', null);
        $theirs = $this->create(['location_id' => $this->waterford->id], $admin)->json('data.id');

        $this->as($this->manager)->postJson('/api/day-offs/bulk-delete', ['ids' => [$mine, $theirs]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertNull(DayOff::find($mine));
        $this->assertNotNull(DayOff::find($theirs));
        $this->assertSame(1, DayOffChangeLog::where('action', 'deleted')->count());
    }

    public function test_the_change_log_cannot_be_edited_or_removed(): void
    {
        $this->create()->assertCreated();
        $log = DayOffChangeLog::sole();

        try {
            $log->update(['summary' => 'tampered']);
            $this->fail('An availability change record was edited.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('An availability change record was deleted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permanent', $e->getMessage());
        }

        $this->assertSame(1, DayOffChangeLog::count());
        $this->assertNotSame('tampered', DayOffChangeLog::sole()->summary);
    }

    public function test_the_change_log_only_shows_your_own_locations(): void
    {
        $admin = $this->user('admin', 'company_admin', null);
        $this->create(['location_id' => $this->waterford->id], $admin)->assertCreated();
        $this->create()->assertCreated();

        $this->as($this->manager)->getJson('/api/day-offs/change-log')
            ->assertOk()
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.location_id', $this->brighton->id);

        $this->as($this->manager)->getJson("/api/day-offs/change-log?location_id={$this->waterford->id}")->assertForbidden();

        $this->as($admin)->getJson('/api/day-offs/change-log')->assertOk()->assertJsonCount(2, 'data.changes');
    }

    public function test_booking_calendars_get_the_window_as_closed_halves_and_guests_never_see_private_details(): void
    {
        $this->create(['block_type' => 'operating_window', 'opens_at' => '12:00', 'closes_at' => '18:00'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $public = $this->withHeaders(['Authorization' => ''])->getJson("/api/day-offs/location/{$this->brighton->id}")->assertOk();

        $public->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.time_start', null)
            ->assertJsonPath('data.0.time_end', '12:00')
            ->assertJsonPath('data.1.time_start', '18:00')
            ->assertJsonPath('data.1.time_end', null);

        foreach ($public->json('data') as $closure) {
            $this->assertArrayNotHasKey('reason', $closure);
            $this->assertArrayNotHasKey('group_name', $closure);
            $this->assertArrayNotHasKey('set_by', $closure);
        }

        $this->assertStringNotContainsString('Acme', $public->getContent());

        $staff = $this->as($this->user('attendant', 'attendant', $this->brighton))
            ->getJson("/api/day-offs/location/{$this->brighton->id}")
            ->assertOk();

        $staff->assertJsonPath('data.0.group_name', 'Acme Corp Holiday Party')
            ->assertJsonPath('data.0.window_label', 'Open only 12:00 PM – 6:00 PM')
            ->assertJsonPath('data.0.set_by.name', 'Manager Tester');
    }

    public function test_the_calendar_lists_each_day_a_block_covers(): void
    {
        $this->create(['is_recurring' => true, 'date' => Carbon::today()->subYear()->addDays(3)->toDateString()])->assertCreated();
        $this->create()->assertCreated();
        $admin = $this->user('admin', 'company_admin', null);
        $this->create(['location_id' => $this->waterford->id, 'date' => Carbon::today()->addDays(5)->toDateString()], $admin)->assertCreated();

        $start = Carbon::today()->toDateString();
        $end = Carbon::today()->addDays(30)->toDateString();

        $response = $this->as($this->manager)
            ->getJson("/api/day-offs/calendar?start_date={$start}&end_date={$end}")
            ->assertOk();

        $this->assertSame(
            [Carbon::today()->addDays(3)->toDateString(), $this->date],
            collect($response->json('data.occurrences'))->pluck('occurrence_date')->all()
        );
        $response->assertJsonPath('data.occurrences.0.group_name', 'Acme Corp Holiday Party');

        $this->as($admin)
            ->getJson("/api/day-offs/calendar?start_date={$start}&end_date={$end}&location_id={$this->waterford->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.occurrences');

        $this->as($this->manager)
            ->getJson("/api/day-offs/calendar?start_date={$start}&end_date={$end}&location_id={$this->waterford->id}")
            ->assertForbidden();

        $this->as($this->manager)
            ->getJson('/api/day-offs/calendar?start_date=' . $start . '&end_date=' . Carbon::today()->addDays(200)->toDateString())
            ->assertStatus(422);
    }

    private function outsider(): User
    {
        $other = Company::create([
            'company_name' => 'Other Fun Co',
            'email' => 'owner@other.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);

        return User::create([
            'company_id' => $other->id,
            'first_name' => 'Outside',
            'last_name' => 'Admin',
            'email' => 'outsider@other.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);
    }

    public function test_private_details_reach_only_staff_who_work_at_the_location(): void
    {
        $this->create()->assertCreated();

        $elsewhere = $this->user('waterfordstaff', 'attendant', $this->waterford);
        $response = $this->as($elsewhere)->getJson("/api/day-offs/location/{$this->brighton->id}");
        $this->assertContains($response->status(), [200, 403]);
        $this->assertStringNotContainsString('Acme', $response->getContent());

        $response = $this->as($this->outsider())->getJson("/api/day-offs/location/{$this->brighton->id}")->assertOk();
        $this->assertStringNotContainsString('Acme', $response->getContent());
        $this->assertStringNotContainsString('Manager Tester', $response->getContent());

        $this->as($this->user('admin', 'company_admin', null))->getJson("/api/day-offs/location/{$this->brighton->id}")
            ->assertOk()
            ->assertJsonPath('data.0.group_name', 'Acme Corp Holiday Party');
    }

    public function test_another_company_cannot_read_blocks_through_the_day_schedule_or_check_date(): void
    {
        $this->create()->assertCreated();
        $outsider = $this->outsider();

        $schedule = $this->as($outsider)->getJson("/api/schedule/day-window?date={$this->date}")->assertOk();
        $this->assertSame([], $schedule->json('data.blocked_periods'));
        $this->assertStringNotContainsString('Acme', $schedule->getContent());

        $this->as($outsider)->getJson("/api/schedule/day-window?date={$this->date}&location_id={$this->brighton->id}")
            ->assertForbidden();

        $this->as($outsider)->postJson('/api/day-offs/check-date', ['location_id' => $this->brighton->id, 'date' => $this->date])
            ->assertForbidden();

        $this->as($this->user('admin', 'company_admin', null))->getJson("/api/schedule/day-window?date={$this->date}")
            ->assertOk()
            ->assertJsonPath('data.blocked_periods.0.group_name', 'Acme Corp Holiday Party');
    }

    public function test_changing_one_time_keeps_the_other(): void
    {
        $id = $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '18:00'])->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['block_type' => 'operating_window', 'opens_at' => '11:00'])
            ->assertOk()
            ->assertJsonPath('data.window_label', 'Open only 11:00 AM – 6:00 PM');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['block_type' => 'operating_window', 'opens_at' => null])
            ->assertOk()
            ->assertJsonPath('data.window_label', 'Closes early at 6:00 PM');
    }

    public function test_a_block_changed_by_someone_else_meanwhile_is_not_overwritten(): void
    {
        $id = $this->create()->json('data.id');
        $fired = false;

        DayOff::retrieved(function (DayOff $dayOff) use (&$fired) {
            if (! $fired) {
                $fired = true;
                \Illuminate\Support\Facades\DB::table('day_offs')->where('id', $dayOff->id)->update(['reason' => 'Changed at the same moment']);
            }
        });

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['reason' => 'My change'])
            ->assertStatus(409);

        $this->assertSame('Changed at the same moment', DayOff::find($id)->reason);
        $this->assertSame(0, DayOffChangeLog::where('action', 'updated')->count());
    }

    public function test_removing_a_block_someone_else_just_removed_logs_nothing_twice(): void
    {
        $id = $this->create()->json('data.id');
        $fired = false;

        DayOff::retrieved(function (DayOff $dayOff) use (&$fired) {
            if (! $fired) {
                $fired = true;
                \Illuminate\Support\Facades\DB::table('day_offs')->where('id', $dayOff->id)->delete();
            }
        });

        $this->as($this->manager)->deleteJson("/api/day-offs/{$id}")->assertNotFound();
        $this->assertSame(0, DayOffChangeLog::where('action', 'deleted')->count());
    }

    public function test_listing_searches_group_names(): void
    {
        $this->create()->assertCreated();
        $this->create(['scope' => 'rooms', 'room_ids' => [$this->partyRoom->id], 'group_name' => 'Smith Wedding'])->assertCreated();

        $this->as($this->manager)->getJson('/api/day-offs?search=Smith')
            ->assertOk()
            ->assertJsonCount(1, 'data.day_offs')
            ->assertJsonPath('data.day_offs.0.group_name', 'Smith Wedding');
    }

    public function test_a_yearly_block_cannot_sit_on_february_29(): void
    {
        $leapDay = Carbon::create(Carbon::today()->year + (4 - Carbon::today()->year % 4) % 4, 2, 29)->toDateString();
        if (Carbon::parse($leapDay)->isPast()) {
            $leapDay = Carbon::parse($leapDay)->addYears(4)->toDateString();
        }

        $this->create(['date' => $leapDay, 'is_recurring' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $id = $this->create(['date' => $leapDay])->assertCreated()->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$id}", ['is_recurring' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $legacy = DayOff::create([
            'location_id' => $this->brighton->id,
            'date' => $leapDay,
            'is_recurring' => true,
            'reason' => 'Old leap day',
            'group_name' => 'Old group',
            'room_ids' => [$this->partyRoom->id],
        ]);

        $this->as($this->manager)->putJson("/api/day-offs/{$legacy->id}", ['reason' => 'Still closed'])->assertOk();
        $this->assertSame('Still closed', $legacy->fresh()->reason);
    }

    public function test_a_block_moved_to_another_location_stays_in_the_old_location_history(): void
    {
        $admin = $this->user('admin', 'company_admin', null);
        $id = $this->create()->assertCreated()->json('data.id');

        $this->as($admin)->putJson("/api/day-offs/{$id}", ['location_id' => $this->waterford->id])->assertOk();

        $move = DayOffChangeLog::where('action', 'updated')->sole();
        $this->assertSame($this->waterford->id, (int) $move->location_id);
        $this->assertSame($this->brighton->id, (int) $move->previous_location_id);

        $this->as($this->manager)->getJson('/api/day-offs/change-log')
            ->assertOk()
            ->assertJsonCount(2, 'data.changes')
            ->assertJsonPath('data.changes.0.action', 'updated')
            ->assertJsonPath('data.changes.0.location_name', 'ZapZone Waterford')
            ->assertJsonPath('data.changes.0.previous_location_name', 'ZapZone Brighton');

        $this->as($this->manager)->getJson("/api/day-offs/change-log?location_id={$this->brighton->id}&day_off_id={$id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.changes');

        $this->assertNull(DayOffChangeLog::where('action', 'created')->sole()->previous_location_id);
    }

    public function test_deleting_a_location_logs_each_of_its_blocks_first(): void
    {
        $admin = $this->user('admin', 'company_admin', null);
        $this->create(['location_id' => $this->waterford->id], $admin)->assertCreated();
        $this->create(['location_id' => $this->waterford->id, 'scope' => 'rooms', 'room_ids' => [Room::create([
            'location_id' => $this->waterford->id,
            'name' => 'Waterford Room',
            'capacity' => 10,
            'is_available' => true,
            'booking_interval' => 15,
        ])->id], 'date' => Carbon::parse($this->date)->addDay()->toDateString()], $admin)->assertCreated();
        $this->create()->assertCreated();

        $this->as($admin)->deleteJson("/api/locations/{$this->waterford->id}")->assertOk();

        $this->assertSame(0, DayOff::where('location_id', $this->waterford->id)->count());
        $this->assertSame(1, DayOff::where('location_id', $this->brighton->id)->count());

        $removed = DayOffChangeLog::where('action', 'deleted')->orderBy('id')->get();
        $this->assertCount(2, $removed);
        foreach ($removed as $log) {
            $this->assertSame($this->waterford->id, (int) $log->location_id);
            $this->assertSame($admin->id, (int) $log->changed_by);
            $this->assertTrue($log->context['location_deleted']);
            $this->assertNotNull($log->before);
            $this->assertNull($log->after);
            $this->assertStringContainsString('ZapZone Waterford was deleted', $log->summary);
            $this->assertSame($this->company->id, (int) $log->company_id);
        }

        $history = $this->as($admin)->getJson('/api/day-offs/change-log?per_page=100')->assertOk()->json('data.changes');
        $this->assertCount(2, collect($history)->where('action', 'deleted')->where('location_id', $this->waterford->id));

        $this->as($this->manager)->getJson('/api/day-offs/change-log?per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data.changes')
            ->assertJsonPath('data.changes.0.location_id', $this->brighton->id);
    }

    public function test_deleting_a_company_logs_the_blocks_of_every_location_first(): void
    {
        $other = Company::create([
            'company_name' => 'Other Fun Co',
            'email' => 'other@zapzone.test',
            'phone' => '5559876543',
            'address' => '9 Elm St',
        ]);
        $otherVenue = Location::create([
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
        $owner = User::create([
            'company_id' => $other->id,
            'location_id' => null,
            'first_name' => 'Owner',
            'last_name' => 'Tester',
            'email' => 'owner@other.test',
            'password' => 'Password123!',
            'role' => 'company_admin',
            'status' => 'active',
        ]);

        $this->create(['location_id' => $otherVenue->id], $owner)->assertCreated();
        $this->create()->assertCreated();

        $this->as($this->manager)->deleteJson("/api/companies/{$other->id}")->assertForbidden();
        $this->as($this->manager)->deleteJson("/api/companies/{$this->company->id}")->assertForbidden();
        $this->as($this->user('admin', 'company_admin', null))->deleteJson("/api/companies/{$other->id}")->assertForbidden();
        $this->assertNotNull(Company::find($other->id));

        $this->as($owner)->deleteJson("/api/companies/{$other->id}")->assertOk();

        $this->assertSame(0, DayOff::where('location_id', $otherVenue->id)->count());
        $this->assertSame(1, DayOff::where('location_id', $this->brighton->id)->count());

        $removed = DayOffChangeLog::where('action', 'deleted')->sole();
        $this->assertSame($otherVenue->id, (int) $removed->location_id);
        $this->assertSame($owner->id, (int) $removed->changed_by);
        $this->assertSame('Owner Tester', $removed->changed_by_name);
        $this->assertTrue($removed->context['company_deleted']);
        $this->assertStringContainsString('the company Other Fun Co was deleted', $removed->summary);
        $this->assertSame('Owner Tester', ActivityLog::where('action', 'Company Deleted')->whereNull('user_id')->sole()->actor_name);
    }

    public function test_a_new_window_cannot_split_a_day_around_an_older_closed_hours_block(): void
    {
        DayOff::create([
            'location_id' => $this->brighton->id,
            'date' => $this->date,
            'time_start' => '15:00',
            'time_end' => '21:00',
            'attraction_ids' => [$this->laserTag->id],
            'reason' => 'Older closure',
        ]);

        $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '22:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '14:00'])->assertCreated();

        DayOff::where('reason', '!=', 'Older closure')->delete();

        $this->create(['group_name' => 'Full day'])->assertCreated();
        $this->assertSame(0, DayOff::whereNotNull('opens_at')->count());

        DayOff::where('group_name', 'Full day')->delete();

        $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '22:00', 'scope' => 'rooms', 'room_ids' => [$this->partyRoom->id]])
            ->assertCreated();
    }

    public function test_converting_one_half_of_an_older_closure_pair_merges_the_other_half(): void
    {
        $older = fn (array $times) => DayOff::create(array_merge([
            'location_id' => $this->brighton->id,
            'date' => $this->date,
            'reason' => 'Short hours',
        ], $times));

        $morning = $older(['time_end' => '10:00']);
        $evening = $older(['time_start' => '18:00']);
        $roomOnly = $older(['time_start' => '19:00', 'room_ids' => [$this->partyRoom->id]]);

        $this->as($this->manager)->putJson("/api/day-offs/{$morning->id}", [
            'block_type' => 'operating_window',
            'opens_at' => '10:00',
            'closes_at' => '18:00',
            'scope' => 'location',
            'group_name' => 'Short hours',
            'reason' => 'Short hours',
        ])->assertOk()->assertJsonPath('merged_day_off_ids', [$evening->id]);

        $this->assertNull(DayOff::find($evening->id));
        $this->assertNotNull(DayOff::find($roomOnly->id));
        $this->assertSame('operating_window', $morning->fresh()->block_type);

        $merge = DayOffChangeLog::where('action', 'deleted')->sole();
        $this->assertSame($evening->id, (int) $merge->day_off_id);
        $this->assertSame($morning->id, $merge->context['merged_into_day_off_id']);
        $this->assertSame(1, DayOffChangeLog::where('action', 'updated')->where('day_off_id', $morning->id)->count());
    }

    public function test_an_older_pair_is_not_merged_when_the_block_also_moves(): void
    {
        $nextDay = Carbon::parse($this->date)->addDay()->toDateString();
        $morning = DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Short hours', 'time_end' => '10:00']);
        $evening = DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Short hours', 'time_start' => '18:00']);
        $thereAlready = DayOff::create(['location_id' => $this->brighton->id, 'date' => $nextDay, 'reason' => 'Other', 'time_start' => '20:00']);

        $this->as($this->manager)->putJson("/api/day-offs/{$morning->id}", [
            'date' => $nextDay,
            'block_type' => 'full_day',
            'scope' => 'location',
            'group_name' => 'Moved',
            'reason' => 'Moved',
        ])->assertStatus(422)->assertJsonPath('conflict_day_off_id', $thereAlready->id);

        $this->assertNotNull(DayOff::find($thereAlready->id));
        $this->assertNotNull(DayOff::find($evening->id));

        $thereAlready->delete();

        $this->as($this->manager)->putJson("/api/day-offs/{$morning->id}", [
            'date' => $nextDay,
            'block_type' => 'full_day',
            'scope' => 'location',
            'group_name' => 'Moved',
            'reason' => 'Moved',
        ])->assertOk()->assertJsonPath('merged_day_off_ids', []);

        $this->assertNotNull(DayOff::find($evening->id));
    }

    public function test_converting_an_older_half_is_refused_when_the_new_hours_would_lose_the_other_half(): void
    {
        $morning = DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Short hours', 'time_end' => '10:00']);
        $evening = DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Short hours', 'time_start' => '20:00']);

        $this->as($this->manager)->putJson("/api/day-offs/{$morning->id}", [
            'block_type' => 'operating_window',
            'opens_at' => '10:00',
            'scope' => 'location',
            'group_name' => 'Short hours',
            'reason' => 'Short hours',
        ])->assertStatus(422)->assertJsonPath('conflict_day_off_id', $evening->id);

        $this->assertNotNull(DayOff::find($evening->id));
        $this->assertSame('legacy_closure', $morning->fresh()->block_type);
        $this->assertSame(0, DayOffChangeLog::count());
    }

    public function test_changing_only_the_hours_still_checks_for_split_days(): void
    {
        DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Lunch', 'time_start' => '12:00', 'time_end' => '14:00']);
        $window = $this->create(['block_type' => 'operating_window', 'opens_at' => '15:00', 'scope' => 'packages', 'package_ids' => [$this->birthday->id]])->assertCreated()->json('data.id');

        $this->as($this->manager)->putJson("/api/day-offs/{$window}", ['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '20:00'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_a_package_window_cannot_split_a_day_around_an_older_closure_on_one_of_its_spaces(): void
    {
        $this->birthday->rooms()->sync([$this->partyRoom->id]);
        DayOff::create(['location_id' => $this->brighton->id, 'date' => $this->date, 'reason' => 'Cleaning', 'time_start' => '12:00', 'time_end' => '14:00', 'room_ids' => [$this->partyRoom->id]]);

        $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '20:00', 'scope' => 'packages', 'package_ids' => [$this->birthday->id]])
            ->assertStatus(422)->assertJsonValidationErrors('date');
        $this->create(['block_type' => 'operating_window', 'opens_at' => '10:00', 'closes_at' => '20:00', 'scope' => 'packages', 'package_ids' => [$this->vip->id]])
            ->assertCreated();
    }

    public function test_managers_and_pin_terminals_only_change_their_own_location(): void
    {
        $loose = $this->user('loose', 'location_manager', null);
        $this->create([], $loose)->assertForbidden();

        $admin = $this->user('admin', 'company_admin', null);
        $pinToken = $admin->createToken('pin', ['role:location_manager'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$pinToken}", 'Accept' => 'application/json'])
            ->postJson('/api/day-offs', $this->block(['location_id' => $this->waterford->id]))
            ->assertForbidden();

        $this->create(['location_id' => $this->waterford->id], $admin)->assertCreated();
    }

    public function test_an_account_without_a_company_never_sees_block_details(): void
    {
        $this->create()->assertCreated();
        $loose = $this->user('loose', 'company_admin', null);
        $loose->update(['company_id' => null]);

        $closures = $this->as($loose)->getJson("/api/day-offs/location/{$this->brighton->id}")->assertOk()->json('data');
        $this->assertNotEmpty($closures);
        $this->assertArrayNotHasKey('reason', $closures[0]);
        $this->assertArrayNotHasKey('group_name', $closures[0]);
    }

    public function test_a_window_may_close_early_the_next_morning_but_not_later(): void
    {
        $this->create(['block_type' => 'operating_window', 'opens_at' => '20:00', 'closes_at' => '01:00'])
            ->assertCreated()
            ->assertJsonPath('data.window_label', 'Open only 8:00 PM – 1:00 AM (next morning)');

        $this->create(['date' => Carbon::parse($this->date)->addDay()->toDateString(), 'block_type' => 'operating_window', 'opens_at' => '20:00', 'closes_at' => '07:00'])
            ->assertStatus(422)->assertJsonValidationErrors('closes_at');
    }
}
