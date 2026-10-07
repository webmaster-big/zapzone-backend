<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ShareableToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InactiveStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private User $admin;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Zap Zone',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Arcade St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton | Zap Zone',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->admin = $this->staff('company_admin', 'owner');
        $this->attendant = $this->staff('attendant', 'desk');
    }

    public function test_an_inactive_staff_members_existing_token_is_refused_everywhere(): void
    {
        $token = $this->attendant->createToken('desk')->plainTextToken;

        $this->as($token)->getJson('/api/user')->assertOk();
        $this->as($token)->getJson('/api/notifications/live')->assertOk();

        User::whereKey($this->attendant->id)->update(['status' => 'inactive']);

        $this->as($token)->getJson('/api/user')->assertUnauthorized();
        $this->as($token)->getJson('/api/notifications/live')->assertUnauthorized();
        $this->as($token)->getJson('/api/bookings')->assertUnauthorized();
    }

    public function test_deactivating_a_staff_member_revokes_their_tokens_and_nobody_elses(): void
    {
        $deskToken = $this->attendant->createToken('desk')->plainTextToken;
        $this->attendant->createToken('tablet');
        $adminToken = $this->admin->createToken('owner')->plainTextToken;

        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(0, $this->attendant->tokens()->count());
        $this->as($deskToken)->getJson('/api/user')->assertUnauthorized();

        $this->assertSame(1, $this->admin->tokens()->count());
        $this->as($adminToken)->getJson('/api/user')->assertOk();
    }

    public function test_an_inactive_account_cannot_log_in_and_a_reactivated_one_can(): void
    {
        $adminToken = $this->admin->createToken('owner')->plainTextToken;
        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/login', ['email' => $this->attendant->email, 'password' => 'secret-password'])
            ->assertForbidden()
            ->assertJsonPath('errors.email.0', 'Your account is inactive. Please ask an administrator to reactivate it.');

        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $token = $this->postJson('/api/login', ['email' => $this->attendant->email, 'password' => 'secret-password'])
            ->assertOk()
            ->json('token');

        $this->as($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $this->attendant->id);
    }

    public function test_reactivating_does_not_bring_old_tokens_back(): void
    {
        $oldToken = $this->attendant->createToken('desk')->plainTextToken;
        $adminToken = $this->admin->createToken('owner')->plainTextToken;

        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")->assertOk();
        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")->assertOk();

        $this->assertSame('active', $this->attendant->fresh()->status);
        $this->as($oldToken)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_reactivating_someone_deactivated_outside_the_app_still_revokes_their_old_tokens(): void
    {
        $oldToken = $this->attendant->createToken('desk')->plainTextToken;
        $adminToken = $this->admin->createToken('owner')->plainTextToken;

        User::whereKey($this->attendant->id)->update(['status' => 'inactive']);
        $this->assertSame(1, $this->attendant->tokens()->count());

        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(0, $this->attendant->tokens()->count());
        $this->as($oldToken)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_nobody_can_deactivate_their_own_account(): void
    {
        $adminToken = $this->admin->createToken('owner')->plainTextToken;

        $this->as($adminToken)->putJson("/api/users/{$this->admin->id}", ['status' => 'inactive'])->assertForbidden();
        $this->as($adminToken)->patchJson("/api/users/{$this->admin->id}/toggle-status")->assertForbidden();

        $this->assertSame('active', $this->admin->fresh()->status);
        $this->as($adminToken)->getJson('/api/user')->assertOk();

        $this->as($adminToken)->putJson("/api/users/{$this->admin->id}", ['first_name' => 'Owner', 'status' => 'active'])->assertOk();
    }

    public function test_deactivating_someone_withdraws_the_invitations_they_sent(): void
    {
        $invite = fn (User $by, string $email, array $extra = []) => ShareableToken::create(array_merge([
            'email' => $email,
            'role' => 'attendant',
            'created_by' => $by->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ], $extra));
        $pending = $invite($this->attendant, 'friend@example.com');
        $accepted = $invite($this->attendant, 'joined@example.com', ['used_at' => now()]);
        $someoneElses = $invite($this->admin, 'newhire@example.com');

        $adminToken = $this->admin->createToken('owner')->plainTextToken;
        $this->as($adminToken)->patchJson("/api/users/{$this->attendant->id}/toggle-status")->assertOk();

        $this->assertFalse($pending->fresh()->is_active);
        $this->assertTrue($accepted->fresh()->is_active);
        $this->assertTrue($someoneElses->fresh()->is_active);
    }

    public function test_deleting_a_staff_account_withdraws_the_invitations_they_sent(): void
    {
        $pending = ShareableToken::create([
            'email' => 'friend@example.com',
            'role' => 'attendant',
            'created_by' => $this->attendant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);

        $adminToken = $this->admin->createToken('owner')->plainTextToken;
        $this->as($adminToken)->deleteJson("/api/users/{$this->attendant->id}")->assertSuccessful();

        $this->assertNull(User::find($this->attendant->id));
        $this->assertFalse($pending->fresh()->is_active);
    }

    public function test_customer_tokens_are_not_affected(): void
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        $token = $customer->createToken('customer')->plainTextToken;

        $this->as($token)->getJson('/api/user')->assertOk()->assertJsonPath('email', 'pat@example.com');
    }

    private function staff(string $role, string $handle): User
    {
        return User::create([
            'first_name' => ucfirst($handle),
            'last_name' => 'Staff',
            'email' => "{$handle}@zapzone.test",
            'password' => 'secret-password',
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'active',
        ]);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
