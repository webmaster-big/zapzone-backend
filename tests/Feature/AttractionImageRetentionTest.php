<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttractionImageRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Location $location;

    /** @var list<string> */
    private array $madeFiles = [];

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

    protected function tearDown(): void
    {
        foreach ($this->madeFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function storedImage(string $name): string
    {
        $relative = 'images/attractions/' . $name;
        $absolute = storage_path('app/public/' . $relative);

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        file_put_contents($absolute, 'test-image-bytes');
        $this->madeFiles[] = $absolute;

        return $relative;
    }

    private function attractionWith(array $images): Attraction
    {
        return Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Rage Room',
            'description' => 'Smash things',
            'category' => 'rage',
            'price' => 25,
            'pricing_type' => 'per_person',
            'max_capacity' => 10,
            'image' => $images,
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rage Room',
            'pricing_type' => 'per_person',
        ], $overrides);
    }

    public function test_renaming_an_attraction_keeps_its_picture_on_disk(): void
    {
        $kept = $this->storedImage('retain-' . uniqid() . '.jpg');
        $attraction = $this->attractionWith([$kept]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/attractions/{$attraction->id}", $this->payload([
                'name' => 'Rage Room Renamed',
                'image' => [$kept],
            ]))
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $kept), 'the retained image file was deleted by an unrelated edit');
        $this->assertSame([$kept], $attraction->fresh()->image);
    }

    public function test_removing_one_picture_deletes_only_that_file(): void
    {
        $kept = $this->storedImage('keep-' . uniqid() . '.jpg');
        $dropped = $this->storedImage('drop-' . uniqid() . '.jpg');
        $attraction = $this->attractionWith([$kept, $dropped]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/attractions/{$attraction->id}", $this->payload(['image' => [$kept]]))
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $kept));
        $this->assertFileDoesNotExist(storage_path('app/public/' . $dropped));
        $this->assertSame([$kept], $attraction->fresh()->image);
    }

    public function test_removing_every_picture_clears_the_record_and_the_files(): void
    {
        $only = $this->storedImage('last-' . uniqid() . '.jpg');
        $attraction = $this->attractionWith([$only]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/attractions/{$attraction->id}", $this->payload(['image' => []]))
            ->assertOk();

        $this->assertSame([], $attraction->fresh()->image);
        $this->assertFileDoesNotExist(storage_path('app/public/' . $only));
    }

    public function test_a_picture_another_attraction_still_uses_is_not_deleted(): void
    {
        $shared = $this->storedImage('shared-' . uniqid() . '.jpg');

        $first = $this->attractionWith([$shared]);
        $second = $this->attractionWith([$shared]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/attractions/{$first->id}", $this->payload(['image' => []]))
            ->assertOk();

        $this->assertSame([], $first->fresh()->image);
        $this->assertSame([$shared], $second->fresh()->image);
        $this->assertFileExists(
            storage_path('app/public/' . $shared),
            'a file still referenced by another attraction must not be deleted'
        );
    }

    public function test_an_edit_that_never_mentions_images_leaves_them_alone(): void
    {
        $kept = $this->storedImage('untouched-' . uniqid() . '.jpg');
        $attraction = $this->attractionWith([$kept]);

        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/attractions/{$attraction->id}", $this->payload(['name' => 'Renamed Again']))
            ->assertOk();

        $this->assertFileExists(storage_path('app/public/' . $kept));
        $this->assertSame([$kept], $attraction->fresh()->image);
    }
}
