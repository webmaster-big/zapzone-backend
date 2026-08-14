<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MobilePushDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobilePushDeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';
    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    protected Company $company;
    protected Location $location;
    protected User $admin;
    protected User $manager;
    protected User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Brighton',
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
        ]);

        $this->admin = $this->makeUser('company_admin', 'admin@test.com');
        $this->manager = $this->makeUser('location_manager', 'manager@test.com');
        $this->attendant = $this->makeUser('attendant', 'attendant@test.com');
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'first_name' => 'Test',
            'last_name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function register(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/mobile/push-devices', $payload + [
            'expo_push_token' => self::TOKEN_A,
            'platform' => 'android',
        ]);
    }

    public function test_admin_can_register_a_device(): void
    {
        $response = $this->register($this->admin, ['device_name' => 'Pixel 8']);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'expo_push_token' => self::TOKEN_A,
                    'platform' => 'android',
                    'device_name' => 'Pixel 8',
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('mobile_push_devices', [
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'expo_push_token' => self::TOKEN_A,
            'is_active' => true,
        ]);

        $this->assertNotNull(MobilePushDevice::forToken(self::TOKEN_A)->first()->last_used_at);
    }

    public function test_location_manager_can_register_a_device(): void
    {
        $this->register($this->manager)->assertStatus(201);

        $this->assertDatabaseHas('mobile_push_devices', [
            'user_id' => $this->manager->id,
            'expo_push_token' => self::TOKEN_A,
        ]);
    }

    public function test_attendant_is_rejected(): void
    {
        $this->register($this->attendant)->assertStatus(403);

        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/mobile/push-devices', [
            'expo_push_token' => self::TOKEN_A,
            'platform' => 'android',
        ])->assertStatus(401);

        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_customer_token_is_rejected(): void
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Customer',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        $token = $customer->createToken($customer->email)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/mobile/push-devices', [
                'expo_push_token' => self::TOKEN_A,
                'platform' => 'android',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_invalid_expo_token_is_rejected(): void
    {
        $this->register($this->admin, ['expo_push_token' => 'not-a-push-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expo_push_token');

        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_invalid_platform_is_rejected(): void
    {
        $this->register($this->admin, ['platform' => 'windows'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');

        $this->assertDatabaseCount('mobile_push_devices', 0);
    }

    public function test_same_token_does_not_create_a_duplicate(): void
    {
        $this->register($this->admin)->assertStatus(201);
        $this->register($this->admin, ['device_name' => 'Renamed'])->assertStatus(200);

        $this->assertDatabaseCount('mobile_push_devices', 1);
        $this->assertDatabaseHas('mobile_push_devices', [
            'expo_push_token' => self::TOKEN_A,
            'user_id' => $this->admin->id,
            'device_name' => 'Renamed',
        ]);
    }

    public function test_re_registration_refreshes_the_last_used_timestamp(): void
    {
        $this->register($this->admin)->assertStatus(201);

        $device = MobilePushDevice::forToken(self::TOKEN_A)->first();
        $device->forceFill(['last_used_at' => now()->subWeek()])->save();
        $stale = $device->fresh()->last_used_at;

        $this->register($this->admin)->assertStatus(200);

        $this->assertTrue($device->fresh()->last_used_at->greaterThan($stale));
    }

    public function test_same_user_can_register_multiple_devices(): void
    {
        $this->register($this->admin, ['device_name' => 'Phone'])->assertStatus(201);
        $this->register($this->admin, [
            'expo_push_token' => self::TOKEN_B,
            'device_name' => 'Tablet',
        ])->assertStatus(201);

        $this->assertDatabaseCount('mobile_push_devices', 2);
        $this->assertSame(2, MobilePushDevice::forUser($this->admin->id)->active()->count());
    }

    public function test_inactive_device_is_reactivated(): void
    {
        MobilePushDevice::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'expo_push_token' => self::TOKEN_A,
            'platform' => 'android',
            'is_active' => false,
        ]);

        $this->register($this->admin)->assertStatus(200);

        $this->assertDatabaseCount('mobile_push_devices', 1);
        $this->assertTrue(MobilePushDevice::forToken(self::TOKEN_A)->first()->is_active);
    }

    public function test_token_registered_by_another_user_moves_to_the_new_owner(): void
    {
        $this->register($this->admin)->assertStatus(201);

        $this->register($this->manager)->assertStatus(200);
    
        $this->assertDatabaseCount('mobile_push_devices', 1);
        $this->assertDatabaseHas('mobile_push_devices', [
            'expo_push_token' => self::TOKEN_A,
            'user_id' => $this->manager->id,
            'is_active' => true,
        ]);
        $this->assertSame(0, MobilePushDevice::forUser($this->admin->id)->count());

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Push Device Reassigned',
            'entity_type' => 'mobile_push_device',
            'user_id' => $this->manager->id,
        ]);
    }

    public function test_user_can_deactivate_their_own_device(): void
    {
        $this->register($this->admin)->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/mobile/push-devices', ['expo_push_token' => self::TOKEN_A])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('mobile_push_devices', [
            'expo_push_token' => self::TOKEN_A,
            'is_active' => false,
        ]);
    }

    public function test_user_cannot_deactivate_another_users_device(): void
    {
        $this->register($this->admin)->assertStatus(201);

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson('/api/mobile/push-devices', ['expo_push_token' => self::TOKEN_A])
            ->assertStatus(404);

        $this->assertDatabaseHas('mobile_push_devices', [
            'expo_push_token' => self::TOKEN_A,
            'user_id' => $this->admin->id,
            'is_active' => true,
        ]);
    }
}
