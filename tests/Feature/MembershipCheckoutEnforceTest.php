<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\User;
use App\Services\MembershipBenefitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class MembershipCheckoutEnforceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $location;
    private Package $package;
    private MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'google_calendar.auto_sync' => false, 'checkout.membership_hardening' => 'enforce']);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->plan = MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer',
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Sam',
            'last_name' => 'Member',
            'email' => $email,
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
    }

    private function membership(Customer $customer, string $status = 'active'): Membership
    {
        return Membership::create([
            'customer_id' => $customer->id,
            'membership_plan_id' => $this->plan->id,
            'home_location_id' => $this->location->id,
            'status' => $status,
            'billing_amount' => $this->plan->price,
        ]);
    }

    private function payload(string $email, Membership $membership, array $overrides = []): array
    {
        return array_merge([
            'guest_name' => 'Sam Member',
            'guest_email' => $email,
            'guest_phone' => '7345550000',
            'package_id' => $this->package->id,
            'location_id' => $this->location->id,
            'type' => 'package',
            'booking_date' => now()->addDays(5)->toDateString(),
            'booking_time' => '15:00',
            'participants' => 4,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 90,
            'amount_paid' => 0,
            'payment_method' => 'in-store',
            'send_email' => false,
            'membership_id' => $membership->id,
            'membership_applied' => [['membership_plan_benefit_id' => 999, 'benefit_type' => 'package_discount', 'value_mode' => 'fixed', 'value_applied' => 10]],
        ], $overrides);
    }

    private function as(?object $who): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(array_filter([
            'Authorization' => $who ? 'Bearer ' . $who->createToken('test')->plainTextToken : null,
            'Accept' => 'application/json',
        ]));
    }

    public function test_someone_elses_membership_discount_is_refused_and_nothing_is_booked(): void
    {
        $owner = $this->customer('owner@example.com');
        $membership = $this->membership($owner);
        $stranger = $this->customer('stranger@example.com');

        $this->as(null)->postJson('/api/bookings', $this->payload('stranger@example.com', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', MembershipBenefitService::DROPPED_CLAIM_MESSAGE);
        $this->as($stranger)->postJson('/api/bookings', $this->payload('stranger@example.com', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', MembershipBenefitService::DROPPED_CLAIM_MESSAGE);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_member_still_gets_their_own_membership(): void
    {
        $member = $this->customer('member@example.com');
        $membership = $this->membership($member);

        $id = $this->as($member)->postJson('/api/bookings', $this->payload('member@example.com', $membership))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($membership->id, (int) Booking::findOrFail($id)->membership_id);
    }

    public function test_the_desk_cannot_apply_an_expired_membership_discount(): void
    {
        $member = $this->customer('lapsed@example.com');
        $membership = $this->membership($member, 'expired');
        $desk = User::create([
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'desk@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'attendant',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);

        $this->as($desk)->postJson('/api/bookings', $this->payload('lapsed@example.com', $membership, ['customer_id' => $member->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', MembershipBenefitService::DROPPED_CLAIM_MESSAGE);

        $this->as($desk)->postJson('/api/bookings', $this->payload('lapsed@example.com', $membership, ['customer_id' => $member->id, 'membership_applied' => []]))
            ->assertCreated();
        $this->assertNull(Booking::firstOrFail()->membership_id, 'a membership with no discount on it is simply left off');
    }

    public function test_log_mode_still_lets_a_claim_through_so_the_setting_can_be_turned_back(): void
    {
        config(['checkout.membership_hardening' => 'log']);
        $membership = $this->membership($this->customer('owner@example.com'));

        $this->as(null)->postJson('/api/bookings', $this->payload('someone@example.com', $membership))->assertCreated();
    }
}
