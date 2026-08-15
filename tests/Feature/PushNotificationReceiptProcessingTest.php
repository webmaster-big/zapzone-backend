<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\MobilePushDevice;
use App\Models\MobilePushNotificationLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationReceiptProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected User $admin;
    protected Notification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
        ]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'company_admin',
            'status' => 'active',
        ]);

        // Off the push allowlist on purpose, so building the fixture does not fire
        // the delivery hook and these tests only exercise receipt processing.
        $this->notification = Notification::create([
            'location_id' => $this->location->id,
            'type' => 'payment',
            'priority' => 'medium',
            'title' => 'Payment Received',
            'message' => 'Payment of $240.00 received.',
            'status' => 'unread',
        ]);
    }

    // helpers

    private function makeDevice(string $token, bool $active = true): MobilePushDevice
    {
        return MobilePushDevice::create([
            'user_id' => $this->admin->id,
            'company_id' => $this->company->id,
            'expo_push_token' => $token,
            'platform' => 'android',
            'is_active' => $active,
        ]);
    }

    private function makeLog(MobilePushDevice $device, string $ticketId, array $overrides = []): MobilePushNotificationLog
    {
        return MobilePushNotificationLog::create($overrides + [
            'notification_id' => $this->notification->id,
            'mobile_push_device_id' => $device->id,
            'user_id' => $device->user_id,
            'expo_push_token' => $device->expo_push_token,
            'status' => MobilePushNotificationLog::STATUS_SENT,
            'ticket_id' => $ticketId,
            'sent_at' => now(),
        ]);
    }

    private function fakeReceipts(array $map): void
    {
        Http::fake(function ($request) use ($map) {
            $data = [];

            foreach ($request->data()['ids'] ?? [] as $id) {
                if (array_key_exists($id, $map)) {
                    $data[$id] = $map[$id];
                }
            }

            return Http::response(['data' => $data], 200);
        });
    }

    private function fakeAllDelivered(): void
    {
        Http::fake(function ($request) {
            return Http::response([
                'data' => array_fill_keys($request->data()['ids'] ?? [], ['status' => 'ok']),
            ], 200);
        });
    }

    private function requestedTicketIds(): array
    {
        $ids = [];

        foreach (Http::recorded() as [$request, $response]) {
            foreach ($request->data()['ids'] ?? [] as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // successful receipt

    public function test_a_delivered_receipt_is_recorded_and_the_device_stays_active(): void
    {
        $device = $this->makeDevice('ExponentPushToken[live0000000000000000000]');
        $log = $this->makeLog($device, 'ticket-live');

        $this->fakeReceipts(['ticket-live' => ['status' => 'ok']]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Checked 1 push receipts.')
            ->expectsOutputToContain('Successful: 1')
            ->assertSuccessful();

        $log->refresh();
        $this->assertSame(MobilePushNotificationLog::RECEIPT_OK, $log->receipt_status);
        $this->assertNull($log->error_code);
        $this->assertTrue($device->fresh()->is_active);
    }

    public function test_the_receipts_endpoint_is_the_one_that_gets_called(): void
    {
        $device = $this->makeDevice('ExponentPushToken[live0000000000000000000]');
        $this->makeLog($device, 'ticket-live');

        $this->fakeAllDelivered();

        $this->artisan('push:check-receipts')->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getReceipts'));
    }

    // device not registered

    public function test_device_not_registered_switches_the_device_off(): void
    {
        $device = $this->makeDevice('ExponentPushToken[gone0000000000000000000]');
        $log = $this->makeLog($device, 'ticket-gone');

        $this->fakeReceipts([
            'ticket-gone' => [
                'status' => 'error',
                'message' => 'The recipient device is not registered.',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Devices deactivated: 1')
            ->assertSuccessful();

        $log->refresh();
        $this->assertSame(MobilePushNotificationLog::RECEIPT_ERROR, $log->receipt_status);
        $this->assertSame('DeviceNotRegistered', $log->error_code);
        $this->assertSame('The recipient device is not registered.', $log->error_message);

        $this->assertFalse($device->fresh()->is_active);
    }

    public function test_only_the_device_named_by_the_receipt_is_switched_off(): void
    {
        $dead = $this->makeDevice('ExponentPushToken[gone0000000000000000000]');
        $alive = $this->makeDevice('ExponentPushToken[fine0000000000000000000]');

        $this->makeLog($dead, 'ticket-gone');
        $this->makeLog($alive, 'ticket-fine');

        $this->fakeReceipts([
            'ticket-gone' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-fine' => ['status' => 'ok'],
        ]);

        $this->artisan('push:check-receipts')->assertSuccessful();

        $this->assertFalse($dead->fresh()->is_active);
        $this->assertTrue($alive->fresh()->is_active);
    }

    // other errors

    public function test_other_expo_errors_are_recorded_without_touching_the_device(): void
    {
        $device = $this->makeDevice('ExponentPushToken[big00000000000000000000]');
        $log = $this->makeLog($device, 'ticket-big');

        $this->fakeReceipts([
            'ticket-big' => [
                'status' => 'error',
                'message' => 'Message too big',
                'details' => ['error' => 'MessageTooBig'],
            ],
        ]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Errors: 1')
            ->expectsOutputToContain('Devices deactivated: 0')
            ->assertSuccessful();

        $log->refresh();
        $this->assertSame(MobilePushNotificationLog::RECEIPT_ERROR, $log->receipt_status);
        $this->assertSame('MessageTooBig', $log->error_code);

        $this->assertTrue($device->fresh()->is_active);
    }

    public function test_an_error_with_no_details_still_records_something_useful(): void
    {
        $device = $this->makeDevice('ExponentPushToken[vague000000000000000000]');
        $log = $this->makeLog($device, 'ticket-vague');

        $this->fakeReceipts(['ticket-vague' => ['status' => 'error']]);

        $this->artisan('push:check-receipts')->assertSuccessful();

        $log->refresh();
        $this->assertSame(MobilePushNotificationLog::RECEIPT_ERROR, $log->receipt_status);
        $this->assertSame('ExpoError', $log->error_code);
        $this->assertTrue($device->fresh()->is_active);
    }

    // multiple receipts

    public function test_several_receipts_are_processed_in_one_run(): void
    {
        $devices = [];
        foreach (range(1, 3) as $i) {
            $devices[$i] = $this->makeDevice(sprintf('ExponentPushToken[multi%018d]', $i));
            $this->makeLog($devices[$i], 'ticket-' . $i);
        }

        $this->fakeReceipts([
            'ticket-1' => ['status' => 'ok'],
            'ticket-2' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-3' => ['status' => 'ok'],
        ]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Checked 3 push receipts.')
            ->expectsOutputToContain('Successful: 2')
            ->expectsOutputToContain('Errors: 1')
            ->expectsOutputToContain('Devices deactivated: 1')
            ->assertSuccessful();

        $this->assertSame(2, MobilePushNotificationLog::where('receipt_status', MobilePushNotificationLog::RECEIPT_OK)->count());
        $this->assertFalse($devices[2]->fresh()->is_active);
    }

    // batching

    public function test_more_than_one_thousand_tickets_are_split_across_requests(): void
    {
        $device = $this->makeDevice('ExponentPushToken[batch000000000000000000]');

        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = [
                'notification_id' => $this->notification->id,
                'mobile_push_device_id' => $device->id,
                'user_id' => $this->admin->id,
                'expo_push_token' => $device->expo_push_token,
                'status' => MobilePushNotificationLog::STATUS_SENT,
                'ticket_id' => 'bulk-ticket-' . $i,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        MobilePushNotificationLog::insert($rows);

        $this->fakeAllDelivered();

        $this->artisan('push:check-receipts')->assertSuccessful();

        Http::assertSentCount(2);

        $counts = array_map(
            fn ($pair) => count($pair[0]->data()['ids']),
            Http::recorded()->all()
        );

        $this->assertSame([1000, 1], $counts);
        $this->assertSame(1001, MobilePushNotificationLog::where('receipt_status', MobilePushNotificationLog::RECEIPT_OK)->count());
    }

    // idempotency

    public function test_already_processed_receipts_are_not_asked_about_again(): void
    {
        $device = $this->makeDevice('ExponentPushToken[fresh000000000000000000]');

        $this->makeLog($device, 'ticket-done', ['receipt_status' => MobilePushNotificationLog::RECEIPT_OK]);
        $this->makeLog($device, 'ticket-failed', [
            'receipt_status' => MobilePushNotificationLog::RECEIPT_ERROR,
            'error_code' => 'MessageTooBig',
        ]);
        $this->makeLog($device, 'ticket-new');

        $this->fakeAllDelivered();

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Checked 1 push receipts.')
            ->assertSuccessful();

        $this->assertSame(['ticket-new'], $this->requestedTicketIds());
    }

    public function test_a_second_run_does_not_deactivate_the_same_device_twice(): void
    {
        $device = $this->makeDevice('ExponentPushToken[gone0000000000000000000]');
        $this->makeLog($device, 'ticket-gone');

        $this->fakeReceipts([
            'ticket-gone' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Devices deactivated: 1')
            ->assertSuccessful();

        // Nothing is left awaiting, so the second run has no work and asks Expo nothing.
        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('No push receipts are waiting to be checked.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertFalse($device->fresh()->is_active);
    }

    public function test_a_log_without_a_ticket_is_never_asked_about(): void
    {
        $device = $this->makeDevice('ExponentPushToken[nosend00000000000000000]');

        MobilePushNotificationLog::create([
            'notification_id' => $this->notification->id,
            'mobile_push_device_id' => $device->id,
            'user_id' => $this->admin->id,
            'expo_push_token' => $device->expo_push_token,
            'status' => MobilePushNotificationLog::STATUS_FAILED,
            'error_code' => 'HttpError',
            'ticket_id' => null,
        ]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('No push receipts are waiting to be checked.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    // missing / expired receipts

    public function test_a_missing_receipt_leaves_the_log_and_device_alone(): void
    {
        $device = $this->makeDevice('ExponentPushToken[slow0000000000000000000]');
        $log = $this->makeLog($device, 'ticket-not-ready');

        // Expo answers, but has nothing to say about this ticket yet.
        $this->fakeReceipts([]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Still awaiting a receipt: 1')
            ->assertSuccessful();

        $log->refresh();
        $this->assertNull($log->receipt_status);
        $this->assertNull($log->error_code);
        $this->assertTrue($device->fresh()->is_active);
    }

    public function test_tickets_older_than_the_expo_retention_window_are_dropped(): void
    {
        $device = $this->makeDevice('ExponentPushToken[stale000000000000000000]');
        $this->makeLog($device, 'ticket-ancient', ['sent_at' => now()->subDays(2)]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('No push receipts are waiting to be checked.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertTrue($device->fresh()->is_active);
    }

    // API failure

    public function test_an_expo_outage_does_not_crash_the_command(): void
    {
        $device = $this->makeDevice('ExponentPushToken[down0000000000000000000]');
        $log = $this->makeLog($device, 'ticket-down');

        Http::fake(['*' => Http::response('service unavailable', 503)]);

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Still awaiting a receipt: 1')
            ->assertSuccessful();

        // Nothing was learned, so nothing is recorded and the run repeats later.
        $log->refresh();
        $this->assertNull($log->receipt_status);
        $this->assertTrue($device->fresh()->is_active);
    }

    public function test_a_connection_failure_does_not_crash_the_command(): void
    {
        $device = $this->makeDevice('ExponentPushToken[timeout0000000000000000]');
        $log = $this->makeLog($device, 'ticket-timeout');

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'));

        $this->artisan('push:check-receipts')->assertSuccessful();

        $log->refresh();
        $this->assertNull($log->receipt_status);
        $this->assertTrue($device->fresh()->is_active);
    }

    public function test_a_request_level_rejection_does_not_mark_anything(): void
    {
        $device = $this->makeDevice('ExponentPushToken[toomany0000000000000000]');
        $log = $this->makeLog($device, 'ticket-toomany');

        Http::fake([
            '*' => Http::response([
                'errors' => [['code' => 'PUSH_TOO_MANY_RECEIPTS', 'message' => 'Too many receipts.']],
            ], 200),
        ]);

        $this->artisan('push:check-receipts')->assertSuccessful();

        $log->refresh();
        $this->assertNull($log->receipt_status);
        $this->assertTrue($device->fresh()->is_active);
    }

    // nothing to do

    public function test_nothing_is_requested_when_there_are_no_receipts_to_check(): void
    {
        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('No push receipts are waiting to be checked.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_receipt_checking_respects_the_push_kill_switch(): void
    {
        config(['expo.enabled' => false]);

        $device = $this->makeDevice('ExponentPushToken[off00000000000000000000]');
        $log = $this->makeLog($device, 'ticket-off');

        $this->artisan('push:check-receipts')
            ->expectsOutputToContain('Expo push is switched off')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($log->fresh()->receipt_status);
    }
}
