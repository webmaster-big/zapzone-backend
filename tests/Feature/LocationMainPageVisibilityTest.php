<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class LocationMainPageVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $waterford;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Visibility Co',
            'email' => 'visibility@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->brighton = $this->makeLocation('Brighton');
        $this->waterford = $this->makeLocation('Waterford');
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
            'email' => strtolower($city) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeUser(string $role, Location $location): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => "{$role}.{$location->id}@zapzone.test",
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);
    }

    private function storefrontRow(int $locationId): ?array
    {
        $rows = $this->getJson('/api/storefront/locations')->assertOk()->json('data');

        return collect($rows)->firstWhere('id', $locationId);
    }

    public function test_locations_are_shown_on_the_main_page_by_default(): void
    {
        $this->assertTrue($this->brighton->fresh()->show_on_main_page);
        $this->assertSame(true, $this->storefrontRow($this->brighton->id)['show_on_main_page']);
        $this->assertSame(true, $this->storefrontRow($this->waterford->id)['show_on_main_page']);
    }

    public function test_a_manager_can_hide_their_own_location_without_losing_it(): void
    {
        $package = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Laser Tag Party',
            'description' => 'Two rounds of laser tag',
            'category' => 'Laser Tag',
            'price' => 199,
            'max_participants' => 10,
            'duration' => 2,
            'duration_unit' => 'hours',
            'is_active' => true,
        ]);

        $this->assertNotNull($this->storefrontRow($this->brighton->id));

        $manager = $this->makeUser('location_manager', $this->brighton);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/locations/{$this->brighton->id}", ['show_on_main_page' => false])
            ->assertOk()
            ->assertJsonPath('data.show_on_main_page', false);

        $fresh = $this->brighton->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->show_on_main_page);
        $this->assertTrue($fresh->is_active);
        $this->assertSame('brighton', $fresh->slug);
        $this->assertNotNull(Package::find($package->id));

        $row = $this->storefrontRow($this->brighton->id);
        $this->assertNotNull($row, 'a hidden location must still resolve for its own storefront link');
        $this->assertSame(false, $row['show_on_main_page']);
        $this->assertSame('brighton', $row['slug']);
        $this->assertSame(true, $this->storefrontRow($this->waterford->id)['show_on_main_page']);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/locations/{$this->brighton->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.show_on_main_page', false);

        $log = ActivityLog::where('entity_type', 'location')
            ->where('entity_id', $this->brighton->id)
            ->where('action', 'Location Updated')
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertContains('show_on_main_page', $log->metadata['changed_fields']);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/locations/{$this->brighton->id}", ['show_on_main_page' => true])
            ->assertOk()
            ->assertJsonPath('data.show_on_main_page', true);

        $this->assertSame(true, $this->storefrontRow($this->brighton->id)['show_on_main_page']);
    }

    public function test_a_manager_cannot_hide_another_location(): void
    {
        $manager = $this->makeUser('location_manager', $this->brighton);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/locations/{$this->waterford->id}", ['show_on_main_page' => false])
            ->assertForbidden();

        $this->assertTrue($this->waterford->fresh()->show_on_main_page);
    }

    public function test_an_attendant_cannot_hide_their_location(): void
    {
        $attendant = $this->makeUser('attendant', $this->brighton);

        $this->actingAs($attendant, 'sanctum')
            ->putJson("/api/locations/{$this->brighton->id}", ['show_on_main_page' => false])
            ->assertForbidden();

        $this->assertTrue($this->brighton->fresh()->show_on_main_page);
    }

    public function test_a_company_admin_can_hide_any_location_and_the_flag_is_validated(): void
    {
        $admin = $this->makeUser('company_admin', $this->brighton);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/locations/{$this->waterford->id}", ['show_on_main_page' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_on_main_page');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/locations/{$this->waterford->id}", ['show_on_main_page' => false])
            ->assertOk();

        $this->assertFalse($this->waterford->fresh()->show_on_main_page);
        $this->assertSame(true, $this->storefrontRow($this->brighton->id)['show_on_main_page']);
        $this->assertSame(false, $this->storefrontRow($this->waterford->id)['show_on_main_page']);
    }

    public function test_a_blank_or_null_flag_is_refused_instead_of_crashing_the_save(): void
    {
        $admin = $this->makeUser('company_admin', $this->brighton);

        foreach (['', null] as $value) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/locations/{$this->waterford->id}", ['show_on_main_page' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('show_on_main_page');
        }

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/locations/{$this->waterford->id}", ['phone' => '2485550000'])
            ->assertOk();

        $this->assertTrue($this->waterford->fresh()->show_on_main_page);
    }

    public function test_a_new_location_can_be_created_hidden(): void
    {
        $admin = $this->makeUser('company_admin', $this->brighton);

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/locations', [
                'name' => 'Canton | Zap Zone',
                'address' => '1 Test Way',
                'city' => 'Canton',
                'state' => 'MI',
                'zip_code' => '48187',
                'phone' => '7345551234',
                'email' => 'canton@zapzone.test',
                'timezone' => 'America/Detroit',
                'show_on_main_page' => false,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertFalse(Location::find($id)->show_on_main_page);
        $this->assertSame(false, $this->storefrontRow($id)['show_on_main_page']);
    }
}
