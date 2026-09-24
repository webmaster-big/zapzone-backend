<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearablePackageFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $location = Location::create([
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
            'location_id' => $location->id,
        ]);

        $this->package = Package::create([
            'location_id' => $location->id,
            'name' => 'Party Package',
            'description' => 'Test package',
            'category' => 'party',
            'features' => ['Pizza', 'Soda'],
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 5,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    public function test_every_feature_can_be_removed_from_a_package(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/packages/{$this->package->id}", ['features' => []])
            ->assertOk();

        $this->assertSame([], $this->package->fresh()->features);
    }

    public function test_participant_limits_can_be_cleared(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/packages/{$this->package->id}", [
                'min_participants' => null,
                'max_participants' => null,
            ])
            ->assertOk();

        $fresh = $this->package->fresh();

        $this->assertNull($fresh->min_participants);
        $this->assertNull($fresh->max_participants);
    }

    public function test_features_left_out_of_the_request_are_kept(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/packages/{$this->package->id}", ['name' => 'Renamed Package'])
            ->assertOk();

        $fresh = $this->package->fresh();

        $this->assertSame('Renamed Package', $fresh->name);
        $this->assertSame(['Pizza', 'Soda'], $fresh->features);
    }
}
