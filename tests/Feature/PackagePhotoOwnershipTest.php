<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class PackagePhotoOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Photo Owner Co',
            'email' => 'owner@photos.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->admin = User::create([
            'first_name' => 'Photo',
            'last_name' => 'Admin',
            'email' => 'admin@photos.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $relative) {
            @unlink(storage_path('app/public/' . $relative));
        }

        parent::tearDown();
    }

    private function location(string $name): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => $name,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($name) . '@photos.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function package(Location $location, string $name, ?array $image, array $extra = []): Package
    {
        return Package::create(array_merge([
            'location_id' => $location->id,
            'name' => $name,
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'image' => $image,
        ], $extra));
    }

    private function storedPhoto(): string
    {
        $relative = 'images/packages/photo-owner-' . uniqid() . '.png';
        $absolute = storage_path('app/public/' . $relative);

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        file_put_contents($absolute, 'photo bytes');
        $this->made[] = $relative;

        return $relative;
    }

    private function photoData(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));

        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->admin->createToken('photos')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_11_26_000002_save_borrowed_package_photos.php');
        $migration->up();
    }

    private function catalogImageAt(Package $package): mixed
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $groups = $this->getJson('/api/packages/grouped-by-name')->assertOk()->json('data');

        foreach ($groups as $group) {
            foreach ($group['locations'] as $entry) {
                if ((int) $entry['package_id'] === (int) $package->id) {
                    return $entry['image'];
                }
            }
        }

        $this->fail("package {$package->id} is not in the catalog");
    }

    private function update(Package $package, array $changes): void
    {
        $this->asAdmin()->putJson("/api/packages/{$package->id}", $changes)->assertOk();

        foreach ((array) $package->fresh()->image as $image) {
            if (is_string($image) && str_starts_with($image, 'images/packages/')) {
                $this->made[] = $image;
            }
        }
    }

    public function test_a_package_that_borrowed_a_photo_gets_its_own_saved_reference(): void
    {
        $photo = $this->storedPhoto();
        $owner = $this->package($this->location('Brighton'), 'Arcade Party', [$photo]);
        $borrower = $this->package($this->location('Waterford'), 'Arcade Party', null);
        $this->assertNull($this->catalogImageAt($borrower), 'before: the Waterford row has no photo of its own');

        $this->runBackfill();

        $this->assertSame([$photo], $borrower->fresh()->image);
        $this->assertSame([$photo], $owner->fresh()->image);
        $this->assertSame([$photo], $this->catalogImageAt($borrower));
    }

    public function test_the_saved_photo_survives_renaming_replacing_and_clearing_the_other_package(): void
    {
        $photo = $this->storedPhoto();
        $owner = $this->package($this->location('Brighton'), 'Arcade Party', [$photo]);
        $borrower = $this->package($this->location('Waterford'), 'Arcade Party', null);
        $this->runBackfill();

        $this->update($owner, ['name' => 'Arcade Party Deluxe']);
        $this->assertSame([$photo], $this->catalogImageAt($borrower), 'renaming the other package');

        $this->update($owner, ['image' => $this->photoData()]);
        $this->assertNotSame([$photo], $owner->fresh()->image);
        $this->assertFileExists(storage_path('app/public/' . $photo), 'Waterford still shows this file');
        $this->assertSame([$photo], $this->catalogImageAt($borrower), 'replacing the other package photo');

        $this->update($owner, ['image' => null]);
        $this->assertNull($owner->fresh()->image);
        $this->assertSame([$photo], $this->catalogImageAt($borrower), 'clearing the other package photo');
        $this->assertFileExists(storage_path('app/public/' . $photo));
    }

    public function test_every_package_without_a_photo_in_a_listed_group_is_filled_and_a_second_run_changes_nothing(): void
    {
        $photo = $this->storedPhoto();
        $own = $this->storedPhoto();
        $brighton = $this->location('Brighton');
        $waterford = $this->location('Waterford');
        $this->package($brighton, 'Arcade Party', [$photo], ['display_order' => 0]);
        $hasOwn = $this->package($waterford, 'Arcade Party', [$own]);
        $inactive = $this->package($waterford, 'Arcade Party', null, ['is_active' => false]);
        $hidden = Package::tracksOnlineAvailability() ? $this->package($waterford, 'Arcade Party', null, ['available_online' => false]) : null;
        $otherName = $this->package($waterford, 'Laser Tag', null);
        $otherCase = $this->package($waterford, 'ARCADE PARTY', null);

        $this->runBackfill();
        $first = Package::orderBy('id')->get(['id', 'image'])->map(fn ($p) => [$p->id, $p->image])->all();
        $this->runBackfill();

        $this->assertSame($first, Package::orderBy('id')->get(['id', 'image'])->map(fn ($p) => [$p->id, $p->image])->all());
        $this->assertSame([$own], $hasOwn->fresh()->image);
        $this->assertSame([$photo], $inactive->fresh()->image, 'a switched-off package keeps the photo it would borrow when switched back on');
        $this->assertNull($otherName->fresh()->image);
        $this->assertNull($otherCase->fresh()->image, 'the catalog groups by the exact name');

        if ($hidden) {
            $this->assertSame([$photo], $hidden->fresh()->image);
        }
    }

    public function test_a_deleted_package_gets_the_photo_too_so_restoring_it_keeps_its_picture(): void
    {
        $photo = $this->storedPhoto();
        $this->package($this->location('Brighton'), 'Arcade Party', [$photo]);
        $trashed = $this->package($this->location('Waterford'), 'Arcade Party', null);
        $trashed->delete();

        $this->runBackfill();
        $trashed->restore();

        $this->assertSame([$photo], $trashed->fresh()->image);
    }

    public function test_a_seasonal_group_switched_off_everywhere_still_saves_its_photo_without_changing_what_guests_see_today(): void
    {
        $photo = $this->storedPhoto();
        $brighton = $this->location('Brighton');
        $waterford = $this->location('Waterford');
        $this->package($brighton, 'Halloween Party', [$photo], ['is_active' => false]);
        $sleeping = $this->package($waterford, 'Halloween Party', null, ['is_active' => false]);
        $this->package($brighton, 'Winter Party', [$this->storedPhoto()], ['is_active' => false]);
        $listedWithoutPhoto = $this->package($waterford, 'Winter Party', null);

        $this->runBackfill();

        $this->assertSame([$photo], $sleeping->fresh()->image, 'switched back on next season, it shows the group photo as before');
        $this->assertNull($listedWithoutPhoto->fresh()->image, 'a package guests can see today keeps showing what it shows today');
    }

    public function test_a_percent_encoded_web_address_to_the_same_file_keeps_it(): void
    {
        $relative = 'images/packages/arcade party ' . uniqid() . '.png';
        $absolute = storage_path('app/public/' . $relative);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, 'photo bytes');
        $this->made[] = $relative;
        $brighton = $this->location('Brighton');
        $owner = $this->package($brighton, 'Arcade Party', [$relative]);
        $this->package($brighton, 'Arcade Party (Copy)', ['https://api.example.test/storage/images/packages/' . rawurlencode(basename($relative))]);

        $this->update($owner, ['image' => $this->photoData()]);

        $this->assertFileExists($absolute, 'the copy still uses this file through an encoded web address');
    }

    public function test_a_web_address_and_a_relative_path_to_the_same_file_count_as_one_file(): void
    {
        $photo = $this->storedPhoto();
        $brighton = $this->location('Brighton');
        $owner = $this->package($brighton, 'Arcade Party', [$photo]);
        $copy = $this->package($brighton, 'Arcade Party (Copy)', ['https://api.example.test/storage/' . $photo]);

        $this->update($owner, ['image' => $this->photoData()]);

        $this->assertFileExists(storage_path('app/public/' . $photo), 'the copy still points at this file by its web address');
        $this->assertSame(['https://api.example.test/storage/' . $photo], $copy->fresh()->image);
    }

    public function test_saving_a_package_with_its_photo_written_as_a_web_address_keeps_the_file(): void
    {
        $photo = $this->storedPhoto();
        $owner = $this->package($this->location('Brighton'), 'Arcade Party', [$photo]);

        $this->update($owner, ['image' => 'https://api.example.test/storage/' . $photo]);

        $this->assertFileExists(storage_path('app/public/' . $photo));
    }

    public function test_the_last_package_to_drop_a_copied_photo_still_removes_the_file(): void
    {
        $photo = $this->storedPhoto();
        $brighton = $this->location('Brighton');
        $owner = $this->package($brighton, 'Arcade Party', [$photo]);
        $copy = $this->package($brighton, 'Arcade Party (Copy)', [$photo]);

        $this->update($owner, ['image' => $this->photoData()]);
        $this->assertFileExists(storage_path('app/public/' . $photo), 'the copy still uses it');

        $this->update($copy, ['image' => $this->photoData()]);
        $this->assertFileDoesNotExist(storage_path('app/public/' . $photo), 'nothing uses it any more');
    }

    public function test_each_spelling_of_a_stored_photo_maps_to_one_file(): void
    {
        $this->assertSame('images/packages/a.png', Package::imageStoragePath('images/packages/a.png'));
        $this->assertSame('images/packages/a.png', Package::imageStoragePath('/storage/images/packages/a.png'));
        $this->assertSame('images/packages/a.png', Package::imageStoragePath('https://api.example.test/storage/images/packages/a.png?v=2'));
        $this->assertSame('images/packages/a b.png', Package::imageStoragePath('https://api.example.test/storage/images/packages/a%20b.png'));
        $this->assertNull(Package::imageStoragePath('images/attractions/a.png'));
        $this->assertNull(Package::imageStoragePath('images/packages/../../.env'));
        $this->assertNull(Package::imageStoragePath('data:image/png;base64,AAAA'));
        $this->assertNull(Package::imageStoragePath(''));
        $this->assertNull(Package::imageStoragePath(null));
    }
}
