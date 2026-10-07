<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ManagerLocationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    private array $venues = [];

    private User $admin;

    private User $manager;

    private User $singleManager;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = $this->makeCompany('Access Co');
        $this->otherCompany = $this->makeCompany('Elsewhere Co');

        $this->venues['brighton'] = $this->makeVenue($this->company, 'Brighton');
        $this->venues['waterford'] = $this->makeVenue($this->company, 'Waterford');
        $this->venues['lansing'] = $this->makeVenue($this->company, 'Lansing');
        $this->venues['closed'] = $this->makeVenue($this->company, 'Portage', false);
        $this->venues['foreign'] = $this->makeVenue($this->otherCompany, 'Toledo');

        $this->admin = $this->makeUser('admin', 'company_admin', null);
        $this->manager = $this->makeUser('multi', 'location_manager', $this->venues['brighton']);
        $this->singleManager = $this->makeUser('single', 'location_manager', $this->venues['lansing']);
        $this->attendant = $this->makeUser('desk', 'attendant', $this->venues['brighton']);

        $this->manager->locations()->sync([
            $this->venues['waterford']->id,
            $this->venues['closed']->id,
            $this->venues['foreign']->id,
        ]);
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Test St',
        ]);
    }

    private function makeVenue(Company $company, string $city, bool $active = true): Location
    {
        $location = Location::create([
            'company_id' => $company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => $active,
        ]);

        Room::create([
            'location_id' => $location->id,
            'name' => "{$city} Room",
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        return $location;
    }

    private function makeUser(string $handle, string $role, ?Location $location): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'first_name' => ucfirst($handle),
            'last_name' => 'Tester',
            'email' => "{$handle}@zapzone.test",
            'password' => 'Password123!',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function roomNames($response): array
    {
        return collect($response->json('data.rooms'))->pluck('name')->sort()->values()->all();
    }

    public function test_login_returns_home_and_the_locations_the_manager_can_work_at(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'multi@zapzone.test',
            'password' => 'Password123!',
        ])->assertOk();

        $this->assertSame($this->venues['brighton']->id, $response->json('user.location_id'));
        $this->assertSame($this->venues['brighton']->id, $response->json('user.home_location_id'));
        $this->assertSame(
            [$this->venues['brighton']->id, $this->venues['waterford']->id],
            collect($response->json('user.work_locations'))->pluck('id')->all()
        );
    }

    public function test_listing_shows_only_assigned_active_locations_of_the_same_company(): void
    {
        $token = $this->tokenFor($this->manager);

        $response = $this->as($token)->getJson('/api/staff-locations')->assertOk();

        $this->assertSame($this->venues['brighton']->id, $response->json('data.active_location_id'));
        $this->assertTrue($response->json('data.can_switch'));
        $this->assertSame(
            ['Brighton | Zap Zone', 'Waterford | Zap Zone'],
            collect($response->json('data.locations'))->pluck('name')->all()
        );
    }

    public function test_single_location_manager_cannot_switch_anywhere(): void
    {
        $token = $this->tokenFor($this->singleManager);

        $this->as($token)->getJson('/api/staff-locations')
            ->assertOk()
            ->assertJsonPath('data.can_switch', false)
            ->assertJsonCount(1, 'data.locations');

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['brighton']->id])
            ->assertForbidden();
    }

    public function test_switching_scopes_every_request_to_the_selected_location(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->assertSame(['Brighton Room'], $this->roomNames($this->as($token)->getJson('/api/rooms')));

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])
            ->assertOk()
            ->assertJsonPath('data.active_location_id', $this->venues['waterford']->id)
            ->assertJsonPath('data.home_location_id', $this->venues['brighton']->id);

        $this->assertSame(['Waterford Room'], $this->roomNames($this->as($token)->getJson('/api/rooms')));

        $this->as($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('location_id', $this->venues['waterford']->id)
            ->assertJsonPath('home_location_id', $this->venues['brighton']->id);

        $this->assertSame($this->venues['brighton']->id, (int) DB::table('users')->where('id', $this->manager->id)->value('location_id'));
    }

    public function test_the_other_assigned_location_is_closed_while_one_is_selected(): void
    {
        $token = $this->tokenFor($this->manager);
        $brightonRoom = Room::where('location_id', $this->venues['brighton']->id)->first();

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        $this->as($token)->getJson("/api/rooms/{$brightonRoom->id}")
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->venues['brighton']->id)
            ->assertJsonPath('message', 'That space belongs to Brighton | Zap Zone. Switch to Brighton | Zap Zone in the sidebar to open it.');
        $this->as($token)->getJson("/api/rooms/location/{$this->venues['brighton']->id}")
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->venues['brighton']->id);
        $this->as($token)->getJson("/api/rooms/location/{$this->venues['lansing']->id}")
            ->assertForbidden()
            ->assertJsonMissingPath('switch_location_id');
        $this->as($token)->getJson("/api/rooms/location/{$this->venues['waterford']->id}")->assertOk();
    }

    public function test_cannot_switch_to_unassigned_inactive_or_foreign_locations(): void
    {
        $token = $this->tokenFor($this->manager);

        foreach (['lansing', 'closed', 'foreign'] as $key) {
            $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues[$key]->id])
                ->assertForbidden();
        }

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => 999999])->assertForbidden();
        $this->as($token)->putJson('/api/staff-locations/active', [])->assertUnprocessable();

        $this->assertSame(['Brighton Room'], $this->roomNames($this->as($token)->getJson('/api/rooms')));
    }

    public function test_attendants_and_admins_cannot_switch(): void
    {
        $this->as($this->tokenFor($this->attendant))
            ->putJson('/api/staff-locations/active', ['location_id' => $this->venues['brighton']->id])
            ->assertForbidden();

        $this->as($this->tokenFor($this->admin))
            ->putJson('/api/staff-locations/active', ['location_id' => $this->venues['brighton']->id])
            ->assertForbidden();
    }

    public function test_each_session_keeps_its_own_selection(): void
    {
        $desk = $this->tokenFor($this->manager);
        $phone = $this->tokenFor($this->manager);

        $this->as($phone)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        $this->assertSame(['Brighton Room'], $this->roomNames($this->as($desk)->getJson('/api/rooms')));
        $this->assertSame(['Waterford Room'], $this->roomNames($this->as($phone)->getJson('/api/rooms')));
    }

    public function test_switching_back_home_clears_the_session_selection(): void
    {
        $token = $this->tokenFor($this->manager);
        $tokenId = (int) explode('|', $token)[0];

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();
        $this->assertSame($this->venues['waterford']->id, (int) PersonalAccessToken::find($tokenId)->active_location_id);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['brighton']->id])->assertOk();
        $this->assertNull(PersonalAccessToken::find($tokenId)->active_location_id);
    }

    public function test_removing_an_assignment_returns_open_sessions_to_home(): void
    {
        $token = $this->tokenFor($this->manager);
        $tokenId = (int) explode('|', $token)[0];

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        $this->manager->locations()->detach($this->venues['waterford']->id);

        $this->assertSame(['Brighton Room'], $this->roomNames($this->as($token)->getJson('/api/rooms')));
        $this->assertNull(PersonalAccessToken::find($tokenId)->active_location_id);
    }

    public function test_saving_the_signed_in_manager_never_moves_their_home_location(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        $this->as($token)->getJson('/api/user')->assertOk();
        $signedIn = auth('sanctum')->user();
        $this->assertSame($this->venues['waterford']->id, (int) $signedIn->location_id);

        $signedIn->forceFill(['last_login' => now()])->save();
        $signedIn->update(['phone' => '5550001111']);

        $this->assertSame($this->venues['brighton']->id, (int) DB::table('users')->where('id', $this->manager->id)->value('location_id'));
    }

    public function test_user_id_fallback_prefers_the_matching_session(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        Room::create([
            'location_id' => $this->venues['waterford']->id,
            'name' => 'Waterford Annex',
            'capacity' => 10,
            'is_available' => true,
            'booking_interval' => 15,
        ]);

        $this->app['auth']->forgetGuards();
        $user = (new class {
            use \App\Http\Traits\ScopesByAuthUser;

            public function resolve($request)
            {
                return $this->resolveAuthUser($request);
            }
        })->resolve(\Illuminate\Http\Request::create('/api/events', 'GET', ['user_id' => $this->manager->id], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ]));

        $this->assertSame($this->venues['waterford']->id, (int) $user->location_id);
    }

    public function test_admin_assigns_locations_and_only_admins_may(): void
    {
        $adminToken = $this->tokenFor($this->admin);

        $this->as($adminToken)->putJson("/api/users/{$this->singleManager->id}", [
            'location_ids' => [$this->venues['lansing']->id, $this->venues['brighton']->id],
        ])->assertOk()->assertJsonCount(1, 'data.locations');

        $this->assertTrue($this->singleManager->fresh()->canWorkAt($this->venues['brighton']->id));

        $this->as($adminToken)->putJson("/api/users/{$this->singleManager->id}", [
            'location_ids' => [$this->venues['foreign']->id],
        ])->assertUnprocessable();

        $this->as($adminToken)->putJson("/api/users/{$this->attendant->id}", [
            'location_ids' => [$this->venues['waterford']->id],
        ])->assertUnprocessable();

        $managerToken = $this->tokenFor($this->manager);
        $this->as($managerToken)->putJson("/api/users/{$this->attendant->id}", [
            'location_ids' => [$this->venues['waterford']->id],
        ])->assertForbidden();

        $this->as($managerToken)->putJson("/api/users/{$this->manager->id}", [
            'location_ids' => [$this->venues['lansing']->id],
        ])->assertForbidden();
    }

    public function test_demoting_a_manager_drops_their_extra_locations(): void
    {
        $this->as($this->tokenFor($this->admin))->putJson("/api/users/{$this->manager->id}", [
            'role' => 'attendant',
        ])->assertOk();

        $this->assertSame(0, DB::table('location_user')->where('user_id', $this->manager->id)->count());
    }

    public function test_admin_creates_a_manager_with_several_locations(): void
    {
        $response = $this->as($this->tokenFor($this->admin))->postJson('/api/users/staff', [
            'first_name' => 'New',
            'last_name' => 'Manager',
            'email' => 'new.manager@zapzone.test',
            'role' => 'location_manager',
            'location_id' => $this->venues['waterford']->id,
            'location_ids' => [$this->venues['waterford']->id, $this->venues['lansing']->id],
            'password_mode' => 'custom',
            'password' => 'Password123!',
            'send_email' => false,
        ])->assertCreated();

        $created = User::find($response->json('data.user.id'));
        $this->assertSame([$this->venues['waterford']->id, $this->venues['lansing']->id], $created->workLocationIds());
    }

    public function test_manager_alerts_reach_managers_assigned_to_the_location(): void
    {
        $ids = User::workingAt($this->venues['waterford']->id)
            ->where('role', 'location_manager')
            ->pluck('id')
            ->all();

        $this->assertSame([$this->manager->id], $ids);

        $brighton = User::workingAt($this->venues['brighton']->id)->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->manager->id, $this->attendant->id], $brighton);
    }

    private function terminalAt(Location $location): string
    {
        $secret = 'terminal-' . $location->id;

        (new \App\Models\StaffTerminal())->forceFill([
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'device_id' => 'device-' . $location->id,
            'label' => 'Front desk',
            'enrolled_by_user_id' => $this->admin->id,
            'ceiling_role' => 'location_manager',
            'token_hash' => hash('sha256', $secret),
        ])->save();

        return $secret;
    }

    public function test_pin_works_at_a_terminal_in_any_assigned_location_and_starts_there(): void
    {
        config(['staff_pins.enabled' => true, 'staff_pins.pepper' => 'spec10-test-pepper']);
        app(\App\Services\StaffPinService::class)->setPin($this->manager, '482915');

        $response = $this->withHeaders(['X-Staff-Terminal' => $this->terminalAt($this->venues['waterford'])])
            ->postJson('/api/staff-pin/unlock', ['pin' => '482915'])
            ->assertOk()
            ->assertJsonPath('user.location_id', $this->venues['waterford']->id)
            ->assertJsonPath('user.home_location_id', $this->venues['brighton']->id);

        $this->assertSame(['Waterford Room'], $this->roomNames($this->as($response->json('token'))->getJson('/api/rooms')));
        $this->assertSame($this->venues['brighton']->id, (int) DB::table('users')->where('id', $this->manager->id)->value('location_id'));
    }

    public function test_pin_is_refused_at_a_location_the_manager_is_not_assigned_to(): void
    {
        config(['staff_pins.enabled' => true, 'staff_pins.pepper' => 'spec10-test-pepper']);
        app(\App\Services\StaffPinService::class)->setPin($this->singleManager, '573026');

        $this->withHeaders(['X-Staff-Terminal' => $this->terminalAt($this->venues['waterford'])])
            ->postJson('/api/staff-pin/unlock', ['pin' => '573026'])
            ->assertStatus(422);
    }

    public function test_switch_is_recorded_in_the_activity_log(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->venues['waterford']->id])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Location Switched',
            'user_id' => $this->manager->id,
            'location_id' => $this->venues['waterford']->id,
        ]);
    }
}
