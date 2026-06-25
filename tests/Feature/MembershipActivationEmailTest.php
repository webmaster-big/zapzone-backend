<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\GmailApiService;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for the membership sign-up confirmation email.
 *
 * Root cause of the original bug: in MembershipService::activate() the email send
 * lived INSIDE the DB::transaction() closure, so a slow/failing mail call or a
 * rollback in the surrounding sign-up flow (store()/purchase()) could lose the
 * activation email entirely. Cancellation email worked because it was never in a
 * transaction. The fix moved the email send to run AFTER the transaction commits.
 *
 * These tests lock in that behaviour: activation must trigger exactly one
 * confirmation email through GmailApiService, and the membership must end up active.
 */
class MembershipActivationEmailTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected MembershipPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email'        => 'admin@zapzone.test',
            'phone'        => '5551234567',
            'address'      => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name'       => 'ZapZone Brighton',
            'address'    => '8053 Challis Rd',
            'city'       => 'Brighton',
            'state'      => 'MI',
            'zip_code'   => '48116',
            'phone'      => '8105551234',
            'email'      => 'brighton@zapzone.test',
            'timezone'   => 'America/Detroit',
            'is_active'  => true,
        ]);

        $this->plan = MembershipPlan::create([
            'company_id'    => $this->company->id,
            'location_id'   => $this->location->id,
            'name'          => 'Local Explorer',
            'slug'          => 'local-explorer',
            'tier'          => 'basic',
            'price'         => 29.99,
            'billing_cycle' => 'monthly',
            'is_active'     => true,
        ]);
    }

    private function makePendingMembership(?string $email): Membership
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name'  => 'Member',
            'email'      => $email,
            'phone'      => $email ? '7345550000' : null,
            'password'   => Hash::make('secret-password'),
            'status'     => 'active',
        ]);

        return Membership::create([
            'customer_id'        => $customer->id,
            'membership_plan_id' => $this->plan->id,
            'home_location_id'   => $this->location->id,
            'status'             => 'pending',
            'billing_amount'     => $this->plan->price,
        ]);
    }

    public function test_activation_sends_confirmation_email_via_gmail(): void
    {
        $gmail = Mockery::mock(GmailApiService::class);
        $gmail->shouldReceive('sendEmail')
            ->once()
            ->withArgs(function ($to, $subject) {
                return $to === 'pat.member@example.com'
                    && str_contains($subject, 'Local Explorer')
                    && str_contains($subject, 'Active');
            });
        $this->app->instance(GmailApiService::class, $gmail);

        $membership = $this->makePendingMembership('pat.member@example.com');

        $activated = app(MembershipService::class)->activate($membership);

        $this->assertSame('active', $activated->status);
        $this->assertDatabaseHas('memberships', [
            'id'     => $membership->id,
            'status' => 'active',
        ]);
    }

    public function test_activation_still_completes_when_member_has_no_email(): void
    {
        // Walk-in members may have no email on file — the confirmation email is
        // simply skipped, but activation must still succeed.
        $gmail = Mockery::mock(GmailApiService::class);
        $gmail->shouldNotReceive('sendEmail');
        $this->app->instance(GmailApiService::class, $gmail);

        $membership = $this->makePendingMembership(null);

        $activated = app(MembershipService::class)->activate($membership);

        $this->assertSame('active', $activated->status);
    }
}
