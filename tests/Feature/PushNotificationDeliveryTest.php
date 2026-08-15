<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\MobilePushDevice;
use App\Models\MobilePushNotificationLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Company $otherCompany;
    protected Location $location;
    protected Location $otherLocation;
    protected Location $otherCompanyLocation;

    protected User $admin;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->company = $this->makeCompany('ZapZone Test', 'admin@zapzone.test');
        $this->otherCompany = $this->makeCompany('Rival Fun Co', 'admin@rival.test');

        $this->location = $this->makeLocation($this->company, 'Brighton', 'brighton@zapzone.test');
        $this->otherLocation = $this->makeLocation($this->company, 'Canton', 'canton@zapzone.test');
        $this->otherCompanyLocation = $this->makeLocation($this->otherCompany, 'Rival Venue', 'venue@rival.test');

        $this->admin = $this->makeUser('company_admin', 'admin@test.com', $this->company);
        $this->manager = $this->makeUser('location_manager', 'manager@test.com', $this->company, $this->location);
    }

    private function fakeExpoOk(): void
    {
        Http::fake(function ($request) {
            $messages = $request->data();

            return Http::response([
                'data' => array_map(
                    fn (int $i) => ['status' => 'ok', 'id' => 'ticket-' . $i],
                    array_keys($messages)
                ),
            ], 200);
        });
    }

    private function makeCompany(string $name, string $email): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => $email,
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function makeLocation(Company $company, string $name, string $email): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => $name,
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => $email,
        ]);
    }

    private function makeUser(string $role, string $email, Company $company, ?Location $location = null, string $status = 'active'): User
    {
        return User::create([
            'company_id' => $company->id,
            'location_id' => $location?->id,
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function giveDevice(User $user, string $token, bool $active = true): MobilePushDevice
    {
        return MobilePushDevice::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'expo_push_token' => $token,
            'platform' => 'android',
            'is_active' => $active,
        ]);
    }

    /**
     * A booking notification for $location, exactly as BookingController writes it.
     */
    private function createNotification(array $overrides = []): Notification
    {
        return Notification::create($overrides + [
            'location_id' => $this->location->id,
            'type' => 'booking',
            'priority' => 'medium',
            'title' => 'New Booking Received',
            'message' => 'Jane Doe — 08-14 at 3:00 PM • $240.00 (BK-1042)',
            'status' => 'unread',
            'action_url' => '/bookings/42',
            'action_text' => 'View Booking',
            'metadata' => ['booking_id' => 42],
        ]);
    }

    private function pushedTokens(): array
    {
        $tokens = [];

        foreach (Http::recorded() as [$request, $response]) {
            foreach ($request->data() as $message) {
                $tokens[] = $message['to'];
            }
        }

        return $tokens;
    }

    // recipient targeting

    public function test_company_admin_receives_the_push(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification();

        $this->assertContains('ExponentPushToken[admin000000000000000000]', $this->pushedTokens());
    }

    public function test_company_wide_admin_aliases_are_targeted(): void
    {
        // 'admin' and 'owner' cannot be exercised end to end: users.role is an enum of
        // company_admin, location_manager and attendant, so MySQL rejects the insert.
        // Both aliases are nonetheless honoured by EnsurePhotoStaff, the waiver module
        // and the push registration gate, so targeting has to keep accepting them or a
        // registered device would silently never be sent anything.
        $this->assertSame(
            ['company_admin', 'admin', 'owner'],
            PushNotificationService::ADMIN_ROLES
        );
    }

    public function test_location_manager_at_that_location_receives_the_push(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->manager, 'ExponentPushToken[manager0000000000000000]');

        $this->createNotification();

        $this->assertContains('ExponentPushToken[manager0000000000000000]', $this->pushedTokens());
    }

    public function test_location_manager_from_another_location_is_not_pushed(): void
    {
        $this->fakeExpoOk();

        $otherManager = $this->makeUser('location_manager', 'other-manager@test.com', $this->company, $this->otherLocation);
        $this->giveDevice($otherManager, 'ExponentPushToken[wrongloc00000000000000]');
        $this->giveDevice($this->manager, 'ExponentPushToken[manager0000000000000000]');

        $this->createNotification();

        $tokens = $this->pushedTokens();
        $this->assertContains('ExponentPushToken[manager0000000000000000]', $tokens);
        $this->assertNotContains('ExponentPushToken[wrongloc00000000000000]', $tokens);
    }

    public function test_admin_from_another_company_is_not_pushed(): void
    {
        $this->fakeExpoOk();

        $rivalAdmin = $this->makeUser('company_admin', 'rival-admin@test.com', $this->otherCompany);
        $this->giveDevice($rivalAdmin, 'ExponentPushToken[rival00000000000000000]');
        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification();

        $tokens = $this->pushedTokens();
        $this->assertContains('ExponentPushToken[admin000000000000000000]', $tokens);
        $this->assertNotContains('ExponentPushToken[rival00000000000000000]', $tokens);
    }

    public function test_attendant_is_not_pushed(): void
    {
        $this->fakeExpoOk();

        $attendant = $this->makeUser('attendant', 'attendant@test.com', $this->company, $this->location);
        $this->giveDevice($attendant, 'ExponentPushToken[attendant0000000000000]');

        $this->createNotification();

        $this->assertNotContains('ExponentPushToken[attendant0000000000000]', $this->pushedTokens());
    }

    public function test_inactive_user_is_not_pushed(): void
    {
        $this->fakeExpoOk();

        $former = $this->makeUser('location_manager', 'former@test.com', $this->company, $this->location, 'inactive');
        $this->giveDevice($former, 'ExponentPushToken[former00000000000000000]');

        $this->createNotification();

        $this->assertNotContains('ExponentPushToken[former00000000000000000]', $this->pushedTokens());
    }

    // ---------------------------------------------------------- actor suppression

    public function test_the_actor_does_not_receive_their_own_notification(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->manager, 'ExponentPushToken[manager0000000000000000]');
        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        // The manager took the booking, so the alert is news to everyone but them.
        $this->createNotification(['user_id' => $this->manager->id]);

        $tokens = $this->pushedTokens();
        $this->assertNotContains('ExponentPushToken[manager0000000000000000]', $tokens);
        $this->assertContains('ExponentPushToken[admin000000000000000000]', $tokens);
    }

    // ------------------------------------------------------------------ devices

    public function test_inactive_devices_are_ignored(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[live0000000000000000000]');
        $this->giveDevice($this->admin, 'ExponentPushToken[loggedout00000000000000]', false);

        $this->createNotification();

        $tokens = $this->pushedTokens();
        $this->assertContains('ExponentPushToken[live0000000000000000000]', $tokens);
        $this->assertNotContains('ExponentPushToken[loggedout00000000000000]', $tokens);
    }

    public function test_every_active_device_of_one_user_is_pushed(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[phone000000000000000000]');
        $this->giveDevice($this->admin, 'ExponentPushToken[tablet00000000000000000]');

        $this->createNotification();

        $tokens = $this->pushedTokens();
        $this->assertContains('ExponentPushToken[phone000000000000000000]', $tokens);
        $this->assertContains('ExponentPushToken[tablet00000000000000000]', $tokens);
    }

    public function test_nothing_is_sent_when_no_devices_are_registered(): void
    {
        $this->createNotification();

        Http::assertNothingSent();
        $this->assertDatabaseCount('mobile_push_notification_logs', 0);
    }

    // -------------------------------------------------------- allowlist filtering

    public function test_supported_notification_titles_are_pushed(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification(['title' => 'Payment Voided', 'type' => 'payment', 'priority' => 'high']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('mobile_push_notification_logs', 1);
    }

    public function test_unsupported_notification_titles_are_not_pushed(): void
    {
        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        // Deliberately off the allowlist: it duplicates the booking/purchase alert
        // for the same transaction.
        $this->createNotification(['title' => 'Payment Received', 'type' => 'payment']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('mobile_push_notification_logs', 0);
    }

    public function test_a_manually_created_notification_is_not_pushed(): void
    {
        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification(['title' => 'Staff meeting at 4pm', 'type' => 'system']);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ payload

    public function test_the_payload_carries_the_fields_the_app_needs(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $notification = $this->createNotification();

        $sent = Http::recorded()[0][0]->data()[0];

        $this->assertSame('ExponentPushToken[admin000000000000000000]', $sent['to']);
        $this->assertSame('New Booking Received', $sent['title']);
        $this->assertSame('Jane Doe — 08-14 at 3:00 PM • $240.00 (BK-1042)', $sent['body']);
        $this->assertSame($notification->id, $sent['data']['notification_id']);
        $this->assertSame('booking', $sent['data']['type']);
        $this->assertSame($this->location->id, $sent['data']['location_id']);
        $this->assertSame('/bookings/42', $sent['data']['action_url']);
    }

    // ----------------------------------------------------------------- batching

    public function test_more_than_one_hundred_messages_are_split_across_requests(): void
    {
        $this->fakeExpoOk();

        for ($i = 0; $i < 101; $i++) {
            $this->giveDevice($this->admin, sprintf('ExponentPushToken[batch%017d]', $i));
        }

        $this->createNotification();

        Http::assertSentCount(2);

        $counts = array_map(
            fn ($pair) => count($pair[0]->data()),
            Http::recorded()->all()
        );

        $this->assertSame([100, 1], $counts);
        $this->assertCount(101, $this->pushedTokens());
        $this->assertDatabaseCount('mobile_push_notification_logs', 101);
        $this->assertSame(101, MobilePushNotificationLog::sent()->count());
    }

    // ------------------------------------------------------------------ logging

    public function test_ticket_information_is_persisted(): void
    {
        $this->fakeExpoOk();

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $notification = $this->createNotification();

        $log = MobilePushNotificationLog::firstOrFail();

        $this->assertSame($notification->id, $log->notification_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('ExponentPushToken[admin000000000000000000]', $log->expo_push_token);
        $this->assertSame(MobilePushNotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('ticket-0', $log->ticket_id);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error_code);
    }

    public function test_a_rejected_token_is_logged_with_its_expo_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'status' => 'error',
                    'message' => 'The recipient device is not registered.',
                    'details' => ['error' => 'DeviceNotRegistered'],
                ]],
            ], 200),
        ]);

        $this->giveDevice($this->admin, 'ExponentPushToken[stale000000000000000000]');

        $this->createNotification();

        $log = MobilePushNotificationLog::firstOrFail();

        $this->assertSame(MobilePushNotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame('DeviceNotRegistered', $log->error_code);
        $this->assertNull($log->ticket_id);
        $this->assertNull($log->sent_at);

        // Retiring the token belongs to receipt processing, not to this step.
        $this->assertTrue(MobilePushDevice::firstOrFail()->is_active);
    }

    // ------------------------------------------------------------ failure safety

    public function test_an_expo_outage_does_not_lose_the_notification(): void
    {
        Http::fake(['*' => Http::response('service unavailable', 503)]);

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $notification = $this->createNotification();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'title' => 'New Booking Received',
        ]);

        $log = MobilePushNotificationLog::firstOrFail();
        $this->assertSame(MobilePushNotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame('HttpError', $log->error_code);
    }

    public function test_a_connection_failure_does_not_break_the_business_operation(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        // The booking write that produced this notification must still stand.
        $notification = $this->createNotification();

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertDatabaseHas('mobile_push_notification_logs', [
            'notification_id' => $notification->id,
            'status' => MobilePushNotificationLog::STATUS_FAILED,
            'error_code' => 'TransportError',
        ]);
    }

    public function test_a_malformed_expo_response_is_recorded_as_a_failure(): void
    {
        Http::fake([
            '*' => Http::response([
                'errors' => [['code' => 'PUSH_TOO_MANY_NOTIFICATIONS', 'message' => 'Too many notifications.']],
            ], 200),
        ]);

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification();

        $this->assertDatabaseHas('mobile_push_notification_logs', [
            'status' => MobilePushNotificationLog::STATUS_FAILED,
            'error_code' => 'PUSH_TOO_MANY_NOTIFICATIONS',
        ]);
    }

    public function test_push_can_be_switched_off_entirely(): void
    {
        config(['expo.enabled' => false]);

        $this->giveDevice($this->admin, 'ExponentPushToken[admin000000000000000000]');

        $this->createNotification();

        Http::assertNothingSent();
        $this->assertDatabaseCount('mobile_push_notification_logs', 0);
    }
}
