<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\CacheGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class MembershipPlanLocationOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $locations = [];

    private MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Offer Co',
            'email' => 'offer@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Offer St',
        ]);

        foreach (['Brighton', 'Lansing', 'Taylor'] as $name) {
            $this->locations[$name] = Location::create([
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
            'first_name' => 'Offer',
            'last_name' => 'Admin',
            'email' => 'offer.admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->locations['Brighton']->id,
        ]);

        $this->plan = MembershipPlan::create([
            'company_id' => $company->id,
            'location_id' => null,
            'name' => 'Season Pass',
            'slug' => 'season-pass',
            'price' => 59.99,
            'billing_cycle' => 'one_time',
            'usage_type' => 'unlimited',
            'location_access_mode' => 'multi',
            'cancellation_mode' => 'immediate',
            'is_active' => true,
        ]);
        $this->plan->approvedLocations()->sync([
            $this->locations['Brighton']->id,
            $this->locations['Taylor']->id,
        ]);
    }

    private function publicPlanIdsAt(string $location): array
    {
        return collect(
            $this->getJson('/api/membership-plans/public?location_id=' . $this->locations[$location]->id)
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();
    }

    private function sellAt(array $names): void
    {
        $updatedAt = $this->plan->fresh()->updated_at;

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/membership-plans/' . $this->plan->id, [
                'name' => 'Season Pass',
                'slug' => 'season-pass',
                'price' => 59.99,
                'billing_cycle' => 'one_time',
                'usage_type' => 'unlimited',
                'location_access_mode' => 'multi',
                'cancellation_mode' => 'immediate',
                'is_active' => true,
                'approved_location_ids' => array_map(fn (string $name) => $this->locations[$name]->id, $names),
            ])
            ->assertOk();

        $this->assertEquals($updatedAt, $this->plan->fresh()->updated_at);
        $this->app['auth']->forgetGuards();
    }

    public function test_a_location_only_gets_the_plans_it_sells(): void
    {
        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Brighton'));
        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Taylor'));
        $this->assertSame([], $this->publicPlanIdsAt('Lansing'));

        $plan = $this->getJson('/api/membership-plans/public?location_id=' . $this->locations['Brighton']->id)
            ->assertOk()
            ->json('data.0');

        $this->assertNull($plan['location_id']);
        $this->assertSame('multi', $plan['location_access_mode']);
        $this->assertEqualsCanonicalizing(
            [$this->locations['Brighton']->id, $this->locations['Taylor']->id],
            collect($plan['approved_locations'])->pluck('id')->all()
        );
    }

    public function test_a_location_taken_off_a_plan_stops_getting_it_right_away(): void
    {
        $this->assertTrue(CacheGroups::supported());
        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Brighton'));

        $this->sellAt(['Taylor']);

        $this->assertSame([], $this->publicPlanIdsAt('Brighton'));
        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Taylor'));
    }

    public function test_a_location_added_to_a_plan_gets_it_right_away(): void
    {
        $this->assertTrue(CacheGroups::supported());
        $this->assertSame([], $this->publicPlanIdsAt('Lansing'));

        $this->sellAt(['Brighton', 'Lansing', 'Taylor']);

        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Lansing'));
        $this->assertSame([$this->plan->id], $this->publicPlanIdsAt('Brighton'));
    }
}
