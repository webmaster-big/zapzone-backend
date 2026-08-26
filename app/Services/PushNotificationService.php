<?php

namespace App\Services;

use App\Models\MobilePushDevice;
use App\Models\MobilePushNotificationLog;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public const PUSHABLE_TITLES = [
        'New Booking Received',
        'Booking Cancelled',
        'New Attraction Purchase',
        'New Event Purchase',
        'New Order Received',
        'Order Cancelled',
        'Order Checked In',
        'Online Payment Received',
        'Payment Refunded',
        'Partial Refund Processed',
        'Manual Refund Processed',
        'Manual Partial Refund Processed',
        'Payment Voided',
        'Location Change Request',
        'Location Change Approved',
        'Location Change Rejected',
        'Photo delivery failed',
        'Customer needs help with the schedule',
        'Checkout left unfinished',
    ];

    public const ADMIN_ROLES = ['company_admin', 'admin', 'owner'];

    public const MANAGER_ROLE = 'location_manager';

    public function __construct(private readonly ExpoPushService $expo)
    {
    }

    public function sendForNotification(Notification $notification): void
    {
        if (!$this->shouldPush($notification) || !ExpoPushService::isConfigured()) {
            return;
        }

        $devices = $this->devicesFor($notification);

        if ($devices->isEmpty()) {
            return;
        }

        $payload = $this->payloadFor($notification);
        $logs = [];
        $messages = [];

        foreach ($devices as $device) {
            $logs[] = MobilePushNotificationLog::create([
                'notification_id' => $notification->id,
                'mobile_push_device_id' => $device->id,
                'user_id' => $device->user_id,
                'expo_push_token' => $device->expo_push_token,
                'status' => MobilePushNotificationLog::STATUS_PENDING,
            ]);

            $messages[] = ['to' => $device->expo_push_token] + $payload;
        }

        $results = $this->expo->send($messages);

        $this->recordResults($notification, $devices, $logs, $results);
    }

    protected function shouldPush(Notification $notification): bool
    {
        return in_array((string) $notification->title, self::PUSHABLE_TITLES, true);
    }

    protected function devicesFor(Notification $notification): Collection
    {
        $recipientIds = $this->recipientIds($notification);

        if ($recipientIds === []) {
            return MobilePushDevice::query()->whereRaw('1 = 0')->get();
        }

        return MobilePushDevice::query()
            ->active()
            ->whereIn('user_id', $recipientIds)
            ->get();
    }

    /**
     * Managers are matched on the notification's own location; admins on the
     * company that location belongs to. The actor who caused the event is
     * dropped, mirroring what the web admin already does with the same column.
     *
    **/
    protected function recipientIds(Notification $notification): array
    {
        $location = $notification->location;

        if (!$location) {
            return [];
        }

        $companyId = $location->company_id;

        $query = User::query()
            ->where('status', 'active')
            ->where(function ($group) use ($notification, $companyId) {
                $group->where(function ($manager) use ($notification) {
                    $manager->where('role', self::MANAGER_ROLE)
                        ->where('location_id', $notification->location_id);
                });

                if ($companyId) {
                    $group->orWhere(function ($admin) use ($companyId) {
                        $admin->whereIn('role', self::ADMIN_ROLES)
                            ->where('company_id', $companyId);
                    });
                }
            });

        if ($notification->user_id) {
            $query->where('id', '!=', $notification->user_id);
        }

        return $query->pluck('id')->all();
    }

    /**
     * Only fields the notification already carries. `action_url` is the same
     * value the web admin navigates on, so the app has a working destination
     * without inventing a second routing scheme.
    **/
    protected function payloadFor(Notification $notification): array
    {
        return [
            'title' => (string) $notification->title,
            'body' => (string) $notification->message,
            'sound' => 'default',
            'priority' => in_array($notification->priority, ['high', 'urgent'], true) ? 'high' : 'default',
            'data' => array_filter([
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'priority' => $notification->priority,
                'location_id' => $notification->location_id,
                'action_url' => $notification->action_url,
            ], fn ($value) => $value !== null),
        ];
    }

    protected function recordResults(Notification $notification, Collection $devices, array $logs, array $results): void
    {
        $failures = [];

        foreach ($logs as $index => $log) {
            $result = $results[$index] ?? null;

            if (($result['status'] ?? null) === 'ok') {
                $log->markAsSent($result['ticket_id'] ?? null);
                continue;
            }

            $code = (string) ($result['error_code'] ?? 'NoResult');
            $message = (string) ($result['error_message'] ?? 'Expo returned no result for this message.');

            $log->markAsFailed($code, $message);

            if (count($failures) < 5) {
                $failures[] = [
                    'device_id' => $devices[$index]->id ?? null,
                    'token' => $devices[$index]?->maskedToken(),
                    'error_code' => $code,
                    'error_message' => $message,
                ];
            }
        }

        if ($failures !== []) {
            Log::warning('Some push notifications could not be delivered', [
                'notification_id' => $notification->id,
                'attempted' => count($logs),
                'failed' => count(array_filter(
                    $results,
                    fn ($result) => ($result['status'] ?? null) !== 'ok'
                )),
                'sample' => $failures,
            ]);
        }
    }
}
