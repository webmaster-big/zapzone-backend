<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Attraction;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class CatalogListPagingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Paging Co',
            'email' => 'paging@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Page St',
        ]);

        foreach (['Brighton', 'Waterford', 'Taylor'] as $name) {
            $this->locations[] = Location::create([
                'company_id' => $company->id,
                'name' => $name,
                'address' => '1 Test Way',
                'city' => $name,
                'state' => 'MI',
                'zip_code' => '48327',
                'phone' => '2485551234',
                'email' => strtolower($name) . '@zapzone.test',
                'timezone' => 'America/Detroit',
                'is_active' => true,
            ]);
        }

        $this->admin = User::create([
            'first_name' => 'Paging',
            'last_name' => 'Admin',
            'email' => 'paging.admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->locations[0]->id,
        ]);
    }

    private function collect(string $path, string $key, int $perPage, array $query = []): array
    {
        $ids = [];
        $page = 1;

        do {
            $data = $this->actingAs($this->admin, 'sanctum')
                ->getJson($path . '?' . http_build_query(array_merge($query, ['per_page' => $perPage, 'page' => $page])))
                ->assertOk()
                ->json('data');

            foreach ($data[$key] as $row) {
                $ids[] = $row['id'];
            }

            $lastPage = $data['pagination']['last_page'];
            $page++;
        } while ($page <= $lastPage);

        return $ids;
    }

    public function test_every_package_comes_back_once_across_pages_when_names_repeat(): void
    {
        $created = [];

        foreach ($this->locations as $location) {
            for ($i = 0; $i < 20; $i++) {
                $created[] = Package::create([
                    'location_id' => $location->id,
                    'name' => 'Party ' . ($i % 3),
                    'description' => 'Test package',
                    'category' => 'Birthday',
                    'price' => 100,
                    'pricing_type' => 'base',
                    'min_participants' => 1,
                    'max_participants' => 10,
                    'duration' => 60,
                    'duration_unit' => 'minutes',
                    'is_active' => true,
                ])->id;
            }
        }

        foreach ([['sort_by' => 'id', 'sort_order' => 'desc'], ['sort_by' => 'name', 'sort_order' => 'asc']] as $sort) {
            $ids = $this->collect('/api/packages', 'packages', 50, $sort);

            $this->assertCount(60, $ids);
            $this->assertSame([], array_values(array_diff($created, $ids)));
            $this->assertSame(count($ids), count(array_unique($ids)));
        }
    }

    public function test_attractions_add_ons_and_spaces_page_without_losing_rows_when_names_repeat(): void
    {
        $attractions = [];
        $addOns = [];
        $rooms = [];

        foreach ($this->locations as $location) {
            for ($i = 0; $i < 12; $i++) {
                $attractions[] = Attraction::create([
                    'location_id' => $location->id,
                    'name' => 'Laser Tag ' . ($i % 2),
                    'description' => 'Test attraction',
                    'price' => 10,
                    'max_capacity' => 20,
                    'category' => 'Adventure',
                    'is_active' => true,
                ])->id;
                $addOns[] = AddOn::create([
                    'location_id' => $location->id,
                    'name' => 'Pizza ' . ($i % 2),
                    'price' => 5,
                    'is_active' => true,
                ])->id;
                $rooms[] = Room::create([
                    'location_id' => $location->id,
                    'name' => 'Party Room ' . ($i % 2),
                    'is_available' => true,
                ])->id;
            }
        }

        foreach ([['/api/attractions', 'attractions', $attractions], ['/api/addons', 'add_ons', $addOns], ['/api/rooms', 'rooms', $rooms]] as [$path, $key, $expected]) {
            $ids = $this->collect($path, $key, 5);

            $this->assertSame([], array_values(array_diff($expected, $ids)), $path);
            $this->assertSame(count($ids), count(array_unique($ids)), $path);
        }
    }
}
