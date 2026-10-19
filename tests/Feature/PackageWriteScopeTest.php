<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAddOn;
use App\Models\PackageAttraction;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageWriteScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $waterford;

    private Room $brightonRoom;

    private Room $waterfordRoom;

    private Package $waterfordParty;

    private Package $brightonParty;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Scope Co',
            'email' => 'owner@scope.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->brighton = $this->makeLocation('Brighton');
        $this->waterford = $this->makeLocation('Waterford');
        $this->brightonRoom = $this->makeRoom($this->brighton);
        $this->waterfordRoom = $this->makeRoom($this->waterford);
        $this->waterfordParty = $this->makePackage($this->waterford, 'Waterford Party');
        $this->brightonParty = $this->makePackage($this->brighton, 'Brighton Party');
    }

    private function makeLocation(string $city): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '@scope.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeRoom(Location $location): Room
    {
        return Room::create([
            'location_id' => $location->id,
            'name' => "{$location->city} Room",
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
    }

    private function makePackage(Location $location, string $name): Package
    {
        $package = Package::create([
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
            'display_order' => 5,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '10:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'is_active' => true,
        ]);

        return $package;
    }

    private function makeAttraction(Location $location): Attraction
    {
        return Attraction::create([
            'location_id' => $location->id,
            'name' => "{$location->city} Laser Tag",
            'description' => 'Arena laser tag',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function makeAddOn(Location $location): AddOn
    {
        return AddOn::create([
            'location_id' => $location->id,
            'name' => "{$location->city} Pizza",
            'price' => 15,
            'is_active' => true,
        ]);
    }

    private function staff(string $role, ?Location $location = null): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@scope.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => ($location ?? $this->waterford)->id,
        ]);
    }

    private function outsider(): User
    {
        $otherCompany = Company::create([
            'company_name' => 'Elsewhere Co',
            'email' => 'owner.' . uniqid() . '@elsewhere.test',
            'phone' => '5559870000',
            'address' => '9 Far Rd',
        ]);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Lansing | Elsewhere',
            'address' => '9 Far Rd',
            'city' => 'Lansing',
            'state' => 'MI',
            'zip_code' => '48901',
            'phone' => '5179870000',
            'email' => 'lansing.' . uniqid() . '@elsewhere.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        return User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider.' . uniqid() . '@elsewhere.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);
    }

    private function tokenFor(User $account, array $abilities = ['*']): string
    {
        return $account->createToken('test', $abilities)->plainTextToken;
    }

    private function as(?string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token === null
            ? $this->withHeaders(['Accept' => 'application/json'])
            : $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function importRow(Location $location, array $overrides = []): array
    {
        return array_merge([
            'location_id' => $location->id,
            'name' => 'Imported ' . uniqid(),
            'description' => 'Imported',
            'price' => 50,
            'category' => 'Birthday',
            'duration' => 60,
            'duration_unit' => 'minutes',
        ], $overrides);
    }

    private function assertEveryWriteIsRefused(string $token, Package $package, string $why): void
    {
        $id = $package->id;
        $attraction = $this->makeAttraction($package->location);
        $addOn = $this->makeAddOn($package->location);
        $linkedAttraction = $this->makeAttraction($package->location);
        $linkedAddOn = $this->makeAddOn($package->location);
        PackageAttraction::create(['package_id' => $id, 'attraction_id' => $linkedAttraction->id]);
        PackageAddOn::create(['package_id' => $id, 'add_on_id' => $linkedAddOn->id]);
        $scheduleId = $package->availabilitySchedules()->value('id');

        $attempts = [
            'toggle' => fn () => $this->as($token)->patchJson("/api/packages/{$id}/toggle-status"),
            'delete' => fn () => $this->as($token)->deleteJson("/api/packages/{$id}"),
            'clear add-ons' => fn () => $this->as($token)->deleteJson("/api/packages/addons/{$id}"),
            'attach attraction' => fn () => $this->as($token)->postJson("/api/packages/{$id}/attractions/attach", ['attraction_ids' => [$attraction->id]]),
            'detach attraction' => fn () => $this->as($token)->postJson("/api/packages/{$id}/attractions/detach", ['attraction_ids' => [$linkedAttraction->id]]),
            'attach add-on' => fn () => $this->as($token)->postJson("/api/packages/{$id}/addons/attach", ['addon_ids' => [$addOn->id]]),
            'detach add-on' => fn () => $this->as($token)->postJson("/api/packages/{$id}/addons/detach", ['addon_ids' => [$linkedAddOn->id]]),
            'add schedule' => fn () => $this->as($token)->postJson("/api/packages/{$id}/availability-schedules", ['availability_type' => 'daily', 'time_slot_start' => '10:00', 'time_slot_end' => '12:00', 'time_slot_interval' => 60]),
            'replace schedules' => fn () => $this->as($token)->putJson("/api/packages/{$id}/availability-schedules", ['schedules' => []]),
            'delete schedule' => fn () => $this->as($token)->deleteJson("/api/packages/{$id}/availability-schedules/{$scheduleId}"),
            'link space' => fn () => $this->as($token)->postJson('/api/packages/room/create', ['package_id' => $id, 'room_id' => $package->location_id === $this->waterford->id ? $this->waterfordRoom->id : $this->brightonRoom->id]),
            'reorder' => fn () => $this->as($token)->postJson('/api/packages/reorder', ['items' => [['id' => $id, 'display_order' => 99]]]),
            'advance time' => fn () => $this->as($token)->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$id], 'min_booking_notice_hours' => 7]),
            'import' => fn () => $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [$this->importRow($package->location)]]),
        ];

        $packagesBefore = Package::withTrashed()->count();

        foreach ($attempts as $label => $attempt) {
            $attempt()->assertForbidden();
        }

        $fresh = Package::withTrashed()->find($id);
        $this->assertFalse($fresh->trashed(), "{$why}: the package must not be deleted");
        $this->assertTrue((bool) $fresh->is_active, "{$why}: the package must stay switched on");
        $this->assertSame(5, (int) $fresh->display_order, "{$why}: the order must not change");
        $this->assertNull($fresh->min_booking_notice_hours, "{$why}: the advance time must not change");
        $this->assertSame(1, $fresh->availabilitySchedules()->count(), "{$why}: the schedule must stay");
        $this->assertSame([$linkedAttraction->id], DB::table('package_attractions')->where('package_id', $id)->pluck('attraction_id')->map(fn ($v) => (int) $v)->all(), "{$why}: links stay exactly as they were");
        $this->assertSame([$linkedAddOn->id], DB::table('package_add_ons')->where('package_id', $id)->pluck('add_on_id')->map(fn ($v) => (int) $v)->all(), "{$why}: add-ons stay exactly as they were");
        $this->assertSame(0, DB::table('package_rooms')->where('package_id', $id)->count(), "{$why}: no space may be linked");
        $this->assertSame($packagesBefore, Package::withTrashed()->count(), "{$why}: nothing may be imported");

        $package->delete();
        $this->as($token)->postJson("/api/packages/{$id}/restore")->assertForbidden();
        $this->assertTrue(Package::withTrashed()->find($id)->trashed(), "{$why}: a refused restore leaves it deleted");
        $this->as($token)->deleteJson("/api/packages/{$id}/force")->assertForbidden();
        $this->assertNotNull(Package::withTrashed()->find($id), "{$why}: the package must not be purged");
        $package->restore();
    }

    public function test_staff_of_another_location_cannot_change_a_package(): void
    {
        $this->assertEveryWriteIsRefused($this->tokenFor($this->staff('location_manager', $this->brighton)), $this->waterfordParty, 'another location');
    }

    public function test_an_attendant_of_another_location_cannot_change_a_package(): void
    {
        $this->assertEveryWriteIsRefused($this->tokenFor($this->staff('attendant', $this->brighton)), $this->waterfordParty, 'another location attendant');
    }

    public function test_another_company_cannot_change_a_package(): void
    {
        $this->assertEveryWriteIsRefused($this->tokenFor($this->outsider()), $this->waterfordParty, 'another company');
    }

    public function test_staff_can_still_change_their_own_location_packages(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));
        $id = $this->waterfordParty->id;
        $attraction = $this->makeAttraction($this->waterford);
        $addOn = $this->makeAddOn($this->waterford);

        $this->as($token)->patchJson("/api/packages/{$id}/toggle-status")->assertOk()->assertJsonPath('data.is_active', false);
        $this->as($token)->patchJson("/api/packages/{$id}/toggle-status")->assertOk()->assertJsonPath('data.is_active', true);
        $this->as($token)->postJson("/api/packages/{$id}/attractions/attach", ['attraction_ids' => [$attraction->id]])->assertOk();
        $this->as($token)->postJson("/api/packages/{$id}/addons/attach", ['addon_ids' => [$addOn->id]])->assertOk();
        $this->as($token)->postJson('/api/packages/room/create', ['package_id' => $id, 'room_id' => $this->waterfordRoom->id])->assertCreated();
        $this->as($token)->postJson('/api/packages/reorder', ['items' => [['id' => $id, 'display_order' => 2]]])->assertOk();
        $this->as($token)->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$id], 'min_booking_notice_hours' => 4])->assertOk()->assertJsonPath('data.updated_count', 1);
        $this->as($token)->putJson("/api/packages/{$id}/availability-schedules", ['schedules' => [['availability_type' => 'daily', 'time_slot_start' => '11:00', 'time_slot_end' => '15:00', 'time_slot_interval' => 60]]])->assertOk();

        $fresh = $this->waterfordParty->fresh();
        $this->assertSame(2, (int) $fresh->display_order);
        $this->assertSame(4, (int) $fresh->min_booking_notice_hours);
        $this->assertSame(1, DB::table('package_attractions')->where('package_id', $id)->count());
        $this->assertSame(1, DB::table('package_add_ons')->where('package_id', $id)->count());
        $this->assertSame(1, DB::table('package_rooms')->where('package_id', $id)->count());

        $this->as($token)->deleteJson("/api/packages/addons/{$id}")->assertOk();
        $this->assertSame(0, DB::table('package_add_ons')->where('package_id', $id)->count(), 'clearing add-ons really removes them');

        $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [$this->importRow($this->waterford, ['name' => 'Own Import'])]])->assertCreated();
        $this->assertTrue(Package::where('name', 'Own Import')->where('location_id', $this->waterford->id)->exists());

        $this->as($token)->deleteJson("/api/packages/{$id}")->assertOk();
        $this->as($token)->postJson("/api/packages/{$id}/restore")->assertOk()->assertJsonPath('success', true);
        $this->assertFalse($this->waterfordParty->fresh()->trashed());

        $this->as($token)->deleteJson("/api/packages/{$id}")->assertOk();
        $this->as($token)->deleteJson("/api/packages/{$id}/force")->assertOk();
        $this->assertNull(Package::withTrashed()->find($id));
    }

    public function test_a_company_admin_can_change_packages_at_every_location_of_the_company(): void
    {
        $token = $this->tokenFor($this->staff('company_admin', $this->brighton));

        $this->as($token)->patchJson("/api/packages/{$this->waterfordParty->id}/toggle-status")->assertOk();
        $this->as($token)->postJson('/api/packages/reorder', ['items' => [
            ['id' => $this->waterfordParty->id, 'display_order' => 1],
            ['id' => $this->brightonParty->id, 'display_order' => 2],
        ]])->assertOk();
        $this->as($token)->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$this->waterfordParty->id, $this->brightonParty->id], 'min_booking_notice_hours' => 6])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(1, (int) $this->waterfordParty->fresh()->display_order);
        $this->assertSame(2, (int) $this->brightonParty->fresh()->display_order);
        $this->assertSame(6, (int) $this->brightonParty->fresh()->min_booking_notice_hours);
    }

    public function test_a_mixed_list_only_changes_the_packages_the_caller_manages(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));

        $this->as($token)->postJson('/api/packages/reorder', ['items' => [
            ['id' => $this->waterfordParty->id, 'display_order' => 1],
            ['id' => $this->brightonParty->id, 'display_order' => 9],
        ]])->assertOk();
        $this->as($token)->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$this->waterfordParty->id, $this->brightonParty->id], 'min_booking_notice_hours' => 3])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.package_ids', [$this->waterfordParty->id]);

        $this->assertSame(1, (int) $this->waterfordParty->fresh()->display_order);
        $this->assertSame(5, (int) $this->brightonParty->fresh()->display_order);
        $this->assertSame(3, (int) $this->waterfordParty->fresh()->min_booking_notice_hours);
        $this->assertNull($this->brightonParty->fresh()->min_booking_notice_hours);
    }

    public function test_a_manager_of_several_locations_is_told_to_switch_first(): void
    {
        $manager = $this->staff('location_manager', $this->brighton);
        $manager->locations()->sync([$this->waterford->id]);
        $token = $this->tokenFor($manager);
        $url = "/api/packages/{$this->waterfordParty->id}/toggle-status";

        $this->as($token)->patchJson($url)
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->waterford->id);
        $this->assertTrue((bool) $this->waterfordParty->fresh()->is_active);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->waterford->id])->assertOk();

        $this->as($token)->patchJson($url)->assertOk();
        $this->assertFalse((bool) $this->waterfordParty->fresh()->is_active);
    }

    public function test_a_pin_narrowed_manager_keeps_only_their_own_location(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'), ['role:attendant']);

        $this->as($token)->patchJson("/api/packages/{$this->waterfordParty->id}/toggle-status")->assertOk();
        $this->as($token)->patchJson("/api/packages/{$this->brightonParty->id}/toggle-status")->assertForbidden();
        $this->assertTrue((bool) $this->brightonParty->fresh()->is_active);
    }

    public function test_restoring_a_package_succeeds_and_is_logged(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));
        $id = $this->waterfordParty->id;

        $this->as($token)->postJson("/api/packages/{$id}/restore")
            ->assertStatus(400)
            ->assertJsonPath('message', 'Package is not deleted');

        $this->waterfordParty->delete();

        $this->as($token)->postJson("/api/packages/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'Package restored successfully');

        $this->assertFalse($this->waterfordParty->fresh()->trashed());
        $this->assertTrue(DB::table('activity_logs')->where('action', 'Package Restored')->where('entity_id', $id)->exists());
    }

    public function test_links_must_come_from_the_package_location(): void
    {
        $token = $this->tokenFor($this->staff('company_admin', $this->brighton));
        $id = $this->waterfordParty->id;

        $this->as($token)->postJson("/api/packages/{$id}/attractions/attach", ['attraction_ids' => [$this->makeAttraction($this->brighton)->id]])->assertStatus(422);
        $this->as($token)->postJson("/api/packages/{$id}/addons/attach", ['addon_ids' => [$this->makeAddOn($this->brighton)->id]])->assertStatus(422);
        $this->as($token)->postJson('/api/packages/room/create', ['package_id' => $id, 'room_id' => $this->brightonRoom->id])->assertStatus(422);

        $import = $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [
            $this->importRow($this->waterford, ['name' => 'Linked Wrong', 'room_ids' => [$this->brightonRoom->id]]),
            $this->importRow($this->waterford, ['name' => 'Linked Right', 'room_ids' => [$this->waterfordRoom->id]]),
        ]])->assertStatus(207);

        $this->assertSame(1, $import->json('data.imported_count'));
        $this->assertStringContainsString('belong to a different location', $import->json('errors.0.error'));
        $this->assertFalse(Package::where('name', 'Linked Wrong')->exists());
        $this->assertSame(0, DB::table('package_attractions')->where('package_id', $id)->count());
        $this->assertSame(0, DB::table('package_rooms')->where('package_id', $id)->count());
    }

    public function test_an_import_with_a_wrongly_written_monthly_day_is_refused(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));
        $schedule = fn (array $days) => ['availability_type' => 'monthly', 'day_configuration' => $days, 'time_slot_start' => '10:00', 'time_slot_end' => '14:00', 'time_slot_interval' => 60];

        $import = $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [
            $this->importRow($this->waterford, ['name' => 'Backwards Monthly', 'availability_schedules' => [$schedule(['sunday-first'])]]),
            $this->importRow($this->waterford, ['name' => 'Proper Monthly', 'availability_schedules' => [$schedule(['first-sunday'])]]),
            $this->importRow($this->waterford, ['name' => 'Blank Day Weekly', 'availability_schedules' => [['availability_type' => 'weekly', 'day_configuration' => ['monday', ''], 'time_slot_start' => '10:00', 'time_slot_end' => '14:00', 'time_slot_interval' => 60]]]),
        ]])->assertStatus(207);

        $this->assertSame(2, $import->json('data.imported_count'));
        $this->assertStringContainsString('"sunday-first", which is not a valid day', $import->json('errors.0.error'));
        $this->assertFalse(Package::where('name', 'Backwards Monthly')->exists());
        $this->assertSame(['first-sunday'], Package::where('name', 'Proper Monthly')->firstOrFail()->availabilitySchedules()->first()->day_configuration);
        $this->assertTrue(Package::where('name', 'Blank Day Weekly')->exists(), 'a blank day is skipped, as the schedule editor skips it');
    }

    public function test_saving_schedules_is_all_or_nothing(): void
    {
        PackageAvailabilitySchedule::creating(function (PackageAvailabilitySchedule $schedule) {
            if (str_starts_with((string) $schedule->time_slot_start, '15:00')) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->putJson("/api/packages/{$this->waterfordParty->id}/availability-schedules", ['schedules' => [
                ['availability_type' => 'daily', 'time_slot_start' => '11:00', 'time_slot_end' => '14:00', 'time_slot_interval' => 60],
                ['availability_type' => 'daily', 'time_slot_start' => '15:00', 'time_slot_end' => '19:00', 'time_slot_interval' => 60],
            ]])
            ->assertStatus(500);

        $kept = $this->waterfordParty->availabilitySchedules()->get();
        $this->assertCount(1, $kept, 'a failed save keeps the schedules the package had');
        $this->assertStringStartsWith('10:00', (string) $kept->first()->time_slot_start);
    }

    public function test_the_booking_site_shows_a_new_order_straight_away(): void
    {
        $this->assertTrue(\App\Support\CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $names = fn () => collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Waterford Party', 'Brighton Party'], $names());

        $this->as($this->tokenFor($this->staff('company_admin', $this->brighton)))
            ->postJson('/api/packages/reorder', ['items' => [
                ['id' => $this->brightonParty->id, 'display_order' => 1],
                ['id' => $this->waterfordParty->id, 'display_order' => 2],
            ]])
            ->assertOk();

        $this->assertSame(['Brighton Party', 'Waterford Party'], $names());
    }

    public function test_the_public_location_list_shows_a_new_advance_time_and_order_straight_away(): void
    {
        $this->assertTrue(\App\Support\CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $listed = fn () => collect(collect($this->as(null)->getJson('/api/locations')->assertOk()->json('data'))
            ->firstWhere('id', $this->waterford->id)['packages'])
            ->firstWhere('id', $this->waterfordParty->id);

        $this->assertNull($listed()['min_booking_notice_hours']);
        $this->assertSame(5, (int) $listed()['display_order']);

        $token = $this->tokenFor($this->staff('location_manager'));

        $this->as($token)->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$this->waterfordParty->id], 'min_booking_notice_hours' => 7])->assertOk();
        $this->assertSame(7, (int) $listed()['min_booking_notice_hours']);

        $this->as($token)->postJson('/api/packages/reorder', ['items' => [['id' => $this->waterfordParty->id, 'display_order' => 2]]])->assertOk();
        $this->assertSame(2, (int) $listed()['display_order']);
    }

    public function test_a_refused_import_row_leaves_no_photo_or_half_made_package_behind(): void
    {
        $folder = storage_path('app/public/images/packages');
        $filesNow = fn () => is_dir($folder) ? array_values(array_diff(scandir($folder), ['.', '..'])) : [];
        $before = $filesNow();
        $token = $this->tokenFor($this->staff('location_manager'));

        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        $photo = 'data:image/png;base64,' . base64_encode(ob_get_clean());

        try {
            $import = $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [
                $this->importRow($this->waterford, ['name' => 'Misspelled Day Party', 'image' => [$photo], 'availability_schedules' => [
                    ['availability_type' => 'weekly', 'day_configuration' => ['someday'], 'time_slot_start' => '10:00', 'time_slot_end' => '14:00', 'time_slot_interval' => 60],
                ]]),
                $this->importRow($this->waterford, ['name' => 'Broken Time Party', 'image' => [$photo], 'room_ids' => [$this->waterfordRoom->id], 'availability_schedules' => [
                    ['availability_type' => 'daily', 'time_slot_start' => 'soon', 'time_slot_end' => '14:00', 'time_slot_interval' => 60],
                ]]),
                $this->importRow($this->waterford, ['name' => 'Half Uploaded Party', 'image' => [$photo, 'data:image/png;base64,!!!']]),
            ]])->assertStatus(207);

            $this->assertSame(0, $import->json('data.imported_count'));
            $this->assertSame(3, $import->json('data.failed_count'));
            $this->assertFalse(Package::where('name', 'Half Uploaded Party')->exists());
            $this->assertFalse(Package::where('name', 'Broken Time Party')->exists(), 'a row that fails part-way is undone');
            $this->assertSame(0, DB::table('package_rooms')->where('room_id', $this->waterfordRoom->id)->count(), 'and so are its links');
            $this->assertSame($before, $filesNow(), 'refused rows keep no uploaded photo');
        } finally {
            foreach (array_diff($filesNow(), $before) as $leftover) {
                @unlink($folder . '/' . $leftover);
            }
        }
    }

    public function test_clearing_every_schedule_shows_on_the_booking_site_straight_away(): void
    {
        $this->assertTrue(\App\Support\CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $schedulesShown = fn () => count(collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))
            ->firstWhere('name', 'Waterford Party')['availability_schedules']);

        $this->assertSame(1, $schedulesShown());

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->putJson("/api/packages/{$this->waterfordParty->id}/availability-schedules", ['schedules' => []])
            ->assertOk();

        $this->assertSame(0, $this->waterfordParty->availabilitySchedules()->count());
        $this->assertSame(0, $schedulesShown());
    }

    public function test_a_storefront_list_cached_while_an_import_is_saving_is_refreshed_after_it(): void
    {
        $this->assertTrue(\App\Support\CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $key = 'packages:grouped:' . md5('|' . \Carbon\Carbon::today()->toDateString());
        Package::created(fn () => \App\Support\CacheGroups::put([\App\Support\CacheGroups::PACKAGES], $key, ['cached before the import finished'], 600));

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->postJson('/api/packages/bulk-import', ['packages' => [$this->importRow($this->waterford, ['name' => 'Fresh Import'])]])
            ->assertCreated();

        $names = collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Fresh Import', $names);
    }

    public function test_bulk_no_buffer_removes_the_advance_time(): void
    {
        $this->waterfordParty->update(['min_booking_notice_hours' => 5]);

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->patchJson('/api/packages/bulk-update-min-notice', ['package_ids' => [$this->waterfordParty->id], 'min_booking_notice_hours' => null])
            ->assertOk()
            ->assertJsonPath('message', '1 package(s) now take last-minute bookings with no minimum notice');

        $this->assertNull($this->waterfordParty->fresh()->min_booking_notice_hours);
    }

    public function test_an_import_with_a_row_for_another_location_writes_nothing(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));
        $before = Package::count();

        $this->as($token)->postJson('/api/packages/bulk-import', ['packages' => [
            $this->importRow($this->waterford),
            $this->importRow($this->brighton),
        ]])->assertForbidden();

        $this->assertSame($before, Package::count());
    }
}
