<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\SpecialPricing;
use App\Models\User;
use App\Support\CacheGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class CatalogCacheFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Fresh Catalog Co',
            'email' => 'owner@fresh.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Waterford | Zap Zone',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@fresh.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'first_name' => 'Desk',
            'last_name' => 'Manager',
            'email' => 'manager@fresh.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function asManager(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->manager->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function makeAttraction(string $name, int $order): Attraction
    {
        return Attraction::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'description' => "{$name} arena",
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'max_capacity' => 20,
            'is_active' => true,
            'display_order' => $order,
        ]);
    }

    public function test_the_booking_site_shows_a_new_attraction_order_straight_away(): void
    {
        $this->assertTrue(CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $laser = $this->makeAttraction('Laser Tag', 1);
        $axe = $this->makeAttraction('Axe Throwing', 2);
        $names = fn () => collect($this->asGuest()->getJson('/api/attractions/grouped')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Laser Tag', 'Axe Throwing'], $names());

        $this->asManager()->postJson('/api/attractions/reorder', ['items' => [
            ['id' => $axe->id, 'display_order' => 1],
            ['id' => $laser->id, 'display_order' => 2],
        ]])->assertOk();

        $this->assertSame(['Axe Throwing', 'Laser Tag'], $names());
    }

    public function test_the_booking_site_drops_a_deleted_special_price_straight_away(): void
    {
        $this->assertTrue(CacheGroups::supported(), 'these checks need the grouped cache switched on');

        $party = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Waterford Party',
            'description' => 'Two hours of fun',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 30,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $deal = SpecialPricing::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Today only',
            'discount_amount' => 10,
            'discount_type' => SpecialPricing::DISCOUNT_FIXED,
            'recurrence_type' => SpecialPricing::RECURRENCE_ONE_TIME,
            'specific_date' => now()->toDateString(),
            'entity_type' => SpecialPricing::ENTITY_PACKAGE,
            'entity_ids' => [$party->id],
            'is_active' => true,
        ]);

        $discount = fn () => (float) collect($this->asGuest()->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))
            ->firstWhere('name', 'Waterford Party')['special_pricing']['total_discount'];

        $this->assertSame(10.0, $discount());

        $this->asManager()->postJson('/api/special-pricings/bulk-delete', ['ids' => [$deal->id]])->assertOk();

        $this->assertSame(0.0, $discount());
    }
}
