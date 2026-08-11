<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Notification;
use App\Models\PhotoDelivery;
use App\Models\PhotoMessageTemplate;
use App\Models\PhotoSession;
use App\Models\Waiver;
use App\Support\OperatingDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PhotoDeliveryService
{
    protected SmsService $sms;

    public function __construct()
    {
        $this->sms = new SmsService();
    }

    public function smsAvailable(): bool
    {
        return SmsService::isConfigured();
    }

    public function emailAvailable(): bool
    {
        return $this->emailTransport() !== 'log';
    }

    public function emailTransport(): string
    {
        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        return $useGmailApi ? 'gmail_api' : (string) config('mail.default', 'smtp');
    }

    public function channelDiagnostics(): array
    {
        return [
            'sms_available' => $this->smsAvailable(),
            'email_available' => $this->emailAvailable(),
            'email_transport' => $this->emailTransport(),
            'sms_note' => $this->smsAvailable()
                ? null
                : 'SMS is not configured on this site, so photo links go out by email only. Set TWILIO_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM_NUMBER to switch it on.',
            'email_note' => $this->emailAvailable()
                ? null
                : 'The mail transport is set to "log", so emails are written to the Laravel log instead of being sent.',
        ];
    }

    /**
     * Channels present on the waiver record, regardless of what this site can send.
     */
    public function contactChannelsOnRecord(Waiver $waiver): array
    {
        $channels = [];

        if ($this->validEmail($waiver->adult_email)) {
            $channels[] = PhotoDelivery::CHANNEL_EMAIL;
        }
        if ($this->validPhone($waiver->adult_phone)) {
            $channels[] = PhotoDelivery::CHANNEL_SMS;
        }

        return $channels;
    }

    /**
     * Channels that can actually carry a photo link right now: present on the
     * waiver AND supported by this site's configuration.
     */
    public function contactableChannels(Waiver $waiver): array
    {
        return array_values(array_filter(
            $this->contactChannelsOnRecord($waiver),
            fn ($channel) => $channel === PhotoDelivery::CHANNEL_EMAIL ? $this->emailAvailable() : $this->smsAvailable()
        ));
    }

    public function unavailableReason(Waiver $waiver): ?string
    {
        $onRecord = $this->contactChannelsOnRecord($waiver);

        if ($this->contactableChannels($waiver) !== []) {
            return null;
        }

        if ($onRecord === []) {
            return 'This waiver has no email address or mobile number.';
        }
        if ($onRecord === [PhotoDelivery::CHANNEL_SMS]) {
            return 'This waiver only has a mobile number, and SMS is not configured on this site.';
        }
        if ($onRecord === [PhotoDelivery::CHANNEL_EMAIL]) {
            return 'This waiver only has an email address, and the mail transport is not sending yet.';
        }

        return 'Neither email nor SMS can be sent from this site yet.';
    }

    public function queueWaiverDeliveries(PhotoSession $session, Collection $waivers, string $schedule, ?int $userId = null): array
    {
        $session->loadMissing('location');
        $location = $session->location;

        $kind = $schedule === PhotoSession::SCHEDULE_NEXT_DAY
            ? PhotoDelivery::KIND_NEXT_DAY
            : PhotoDelivery::KIND_IMMEDIATE;

        $scheduledFor = $kind === PhotoDelivery::KIND_NEXT_DAY
            ? OperatingDay::nextNineAm($location)
            : null;

        $seen = [];
        $created = [];
        $skippedWaiverIds = [];

        foreach ($waivers as $waiver) {
            $channels = $this->contactableChannels($waiver);
            $recipientName = trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? ''));

            // A channel present on the waiver that this site cannot send is recorded so the
            // delivery log explains the gap, rather than silently dropping the contact.
            foreach (array_diff($this->contactChannelsOnRecord($waiver), $channels) as $unsupported) {
                PhotoDelivery::create([
                    'photo_session_id' => $session->id,
                    'company_id' => $session->company_id,
                    'location_id' => $session->location_id,
                    'waiver_id' => $waiver->id,
                    'kind' => $kind,
                    'channel' => $unsupported,
                    'destination' => $unsupported === PhotoDelivery::CHANNEL_EMAIL
                        ? strtolower(trim((string) $waiver->adult_email))
                        : $this->normalizePhone($waiver->adult_phone),
                    'recipient_name' => $recipientName,
                    'status' => PhotoDelivery::STATUS_SKIPPED,
                    'error' => $unsupported === PhotoDelivery::CHANNEL_SMS
                        ? 'SMS is not configured on this site.'
                        : 'The mail transport is not sending yet.',
                    'created_by' => $userId,
                ]);
            }

            if ($channels === []) {
                $skippedWaiverIds[] = $waiver->id;
                continue;
            }

            foreach ($channels as $channel) {
                $destination = $channel === PhotoDelivery::CHANNEL_EMAIL
                    ? strtolower(trim((string) $waiver->adult_email))
                    : $this->normalizePhone($waiver->adult_phone);

                $key = $channel . '|' . $destination;
                $primary = $seen[$key] ?? null;

                $delivery = PhotoDelivery::create([
                    'photo_session_id' => $session->id,
                    'company_id' => $session->company_id,
                    'location_id' => $session->location_id,
                    'waiver_id' => $waiver->id,
                    'duplicate_of_id' => $primary?->id,
                    'kind' => $kind,
                    'channel' => $channel,
                    'destination' => $destination,
                    'recipient_name' => $recipientName,
                    'status' => $primary
                        ? PhotoDelivery::STATUS_SKIPPED
                        : ($kind === PhotoDelivery::KIND_NEXT_DAY ? PhotoDelivery::STATUS_SCHEDULED : PhotoDelivery::STATUS_QUEUED),
                    'scheduled_for' => $primary ? null : $scheduledFor,
                    'created_by' => $userId,
                ]);

                if (!$primary) {
                    $seen[$key] = $delivery;
                    $created[] = $delivery;
                }
            }
        }

        if ($kind === PhotoDelivery::KIND_IMMEDIATE) {
            foreach ($created as $delivery) {
                $this->send($delivery);
            }
        }

        // A kiosk session keeps delivery_method = kiosk_qr for life. Overwriting it here
        // (a manager resending from the library) would make requiresContactBeforeAccess()
        // false and permanently drop that visitor's contact gate.
        $keepsKioskGate = $session->source === PhotoSession::SOURCE_KIOSK;

        $session->forceFill(array_merge(
            $keepsKioskGate ? [] : ['delivery_method' => PhotoSession::DELIVERY_WAIVER_MESSAGE],
            [
                'delivery_schedule' => $schedule,
                'delivered_at' => $kind === PhotoDelivery::KIND_IMMEDIATE ? now() : $session->delivered_at,
            ]
        ))->save();

        return [
            'created' => count($created),
            'skipped_waiver_ids' => $skippedWaiverIds,
        ];
    }

    public function queueKioskDeliveries(PhotoSession $session): array
    {
        $created = [];

        if ($this->validEmail($session->kiosk_contact_email) && $this->emailAvailable()) {
            $created[] = PhotoDelivery::create([
                'photo_session_id' => $session->id,
                'company_id' => $session->company_id,
                'location_id' => $session->location_id,
                'kind' => PhotoDelivery::KIND_KIOSK,
                'channel' => PhotoDelivery::CHANNEL_EMAIL,
                'destination' => strtolower(trim((string) $session->kiosk_contact_email)),
                'recipient_name' => $session->kiosk_contact_name,
                'status' => PhotoDelivery::STATUS_QUEUED,
            ]);
        }

        if ($this->validPhone($session->kiosk_contact_phone)) {
            $smsReady = $this->smsAvailable();
            $created[] = PhotoDelivery::create([
                'photo_session_id' => $session->id,
                'company_id' => $session->company_id,
                'location_id' => $session->location_id,
                'kind' => PhotoDelivery::KIND_KIOSK,
                'channel' => PhotoDelivery::CHANNEL_SMS,
                'destination' => $this->normalizePhone($session->kiosk_contact_phone),
                'recipient_name' => $session->kiosk_contact_name,
                'status' => $smsReady ? PhotoDelivery::STATUS_QUEUED : PhotoDelivery::STATUS_SKIPPED,
                'error' => $smsReady ? null : 'SMS is not configured on this site.',
            ]);
        }

        foreach ($created as $delivery) {
            if ($delivery->status === PhotoDelivery::STATUS_QUEUED) {
                $this->send($delivery);
            }
        }

        $session->forceFill(['delivered_at' => now()])->save();

        return [
            'created' => collect($created)->where('status', '!=', PhotoDelivery::STATUS_SKIPPED)->count(),
            'skipped' => collect($created)->where('status', PhotoDelivery::STATUS_SKIPPED)->count(),
        ];
    }

    public function send(PhotoDelivery $delivery): bool
    {
        if ($delivery->isDuplicate()) {
            return false;
        }

        $delivery->loadMissing('session.location', 'session.photos');
        $session = $delivery->session;

        if (!$session || !$session->accessIsActive()) {
            $delivery->update([
                'status' => PhotoDelivery::STATUS_FAILED,
                'error' => 'The photo session is no longer available.',
                'attempts' => $delivery->attempts + 1,
            ]);

            return false;
        }

        $template = PhotoMessageTemplate::forCompany($session->company_id, $this->templateKind($delivery->kind));
        $variables = $this->variables($session, $delivery);

        try {
            if ($delivery->channel === PhotoDelivery::CHANNEL_EMAIL) {
                $this->sendEmail(
                    $delivery->destination,
                    $template->render('email_subject', $variables),
                    $this->wrapHtml($template->render('email_body', $variables)),
                    $variables['business_name']
                );
            } else {
                $this->sms->sendSms($delivery->destination, $template->render('sms_body', $variables));
            }

            $delivery->update([
                'status' => PhotoDelivery::STATUS_SENT,
                'sent_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'error' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => PhotoDelivery::STATUS_FAILED,
                'attempts' => $delivery->attempts + 1,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Photo delivery failed', [
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel,
                'error' => $e->getMessage(),
            ]);

            if ($delivery->attempts >= PhotoDelivery::MAX_ATTEMPTS) {
                $this->notifyBackend(
                    $session->location,
                    'Photo delivery failed',
                    sprintf(
                        'Session #%d could not be delivered by %s to %s after %d attempts.',
                        $session->id,
                        strtoupper($delivery->channel),
                        $delivery->maskedDestination(),
                        $delivery->attempts
                    ),
                    ['photo_session_id' => $session->id, 'delivery_id' => $delivery->id]
                );
            }

            return false;
        }
    }

    public function retry(PhotoDelivery $delivery, ?int $userId = null): bool
    {
        $delivery->update(['status' => PhotoDelivery::STATUS_QUEUED, 'error' => null]);
        $sent = $this->send($delivery);

        ActivityLog::log(
            'photo_delivery_retried',
            'photos',
            sprintf('Retried %s delivery for photo session #%d', $delivery->channel, $delivery->photo_session_id),
            $userId,
            $delivery->location_id,
            'photo_delivery',
            $delivery->id,
            ['result' => $sent ? 'sent' : 'failed']
        );

        return $sent;
    }

    public function cancel(PhotoDelivery $delivery, ?int $userId = null): void
    {
        $delivery->update(['status' => PhotoDelivery::STATUS_CANCELED, 'scheduled_for' => null]);

        ActivityLog::log(
            'photo_delivery_canceled',
            'photos',
            sprintf('Canceled scheduled %s delivery for photo session #%d', $delivery->channel, $delivery->photo_session_id),
            $userId,
            $delivery->location_id,
            'photo_delivery',
            $delivery->id
        );
    }

    public function notifyBackend(?Location $location, string $title, string $message, array $metadata = []): void
    {
        try {
            Notification::create([
                'location_id' => $location?->id,
                'type' => 'system',
                'priority' => 'high',
                'title' => $title,
                'message' => $message,
                'status' => 'unread',
                'action_url' => '/photos/delivery-log',
                'action_text' => 'Open delivery log',
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record a backend photo notification', ['error' => $e->getMessage()]);
        }

        if (!$location) {
            return;
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$this->validEmail($setting->failure_notify_email)) {
            return;
        }

        try {
            $this->sendEmail(
                $setting->failure_notify_email,
                '[' . $location->name . '] ' . $title,
                $this->wrapHtml('<p>' . e($message) . '</p>'),
                $location->company?->name ?? 'Zap Zone'
            );
        } catch (\Throwable $e) {
            Log::warning('Could not email the photo failure alert', ['error' => $e->getMessage()]);
        }
    }

    public function variables(PhotoSession $session, ?PhotoDelivery $delivery = null): array
    {
        $location = $session->location;
        $tz = OperatingDay::timezoneFor($location);
        $name = $delivery?->recipient_name ?: $session->kiosk_contact_name;
        $firstName = trim(explode(' ', trim((string) $name))[0] ?? '');

        return [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'location_name' => $location?->name ?? 'Zap Zone',
            'photo_date' => ($session->captured_at ?: $session->created_at)->copy()->setTimezone($tz)->format('M j, Y'),
            'photo_link' => $this->photoLink($session),
            'expires_on' => $session->access_expires_at?->copy()->setTimezone($tz)->format('M j, Y') ?? '',
            'business_name' => $location?->company?->name ?? 'Zap Zone',
            'support_contact' => (string) config('photos.support_contact'),
            'photo_count' => (string) $session->photos()->ready()->count(),
        ];
    }

    public function photoLink(PhotoSession $session): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/photos/' . $session->access_token;
    }

    protected function templateKind(string $deliveryKind): string
    {
        return match ($deliveryKind) {
            PhotoDelivery::KIND_NEXT_DAY => PhotoMessageTemplate::KIND_NEXT_DAY,
            PhotoDelivery::KIND_KIOSK => PhotoMessageTemplate::KIND_KIOSK,
            default => PhotoMessageTemplate::KIND_IMMEDIATE,
        };
    }

    protected function sendEmail(string $to, string $subject, string $html, ?string $fromName = null): void
    {
        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            try {
                (new GmailApiService())->sendEmail($to, $subject, $html, $fromName ?: 'Zap Zone');

                return;
            } catch (\Throwable $e) {
                Log::warning('Gmail API send failed for a photo link; falling back to the mail transport.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Mail::html($html, function ($message) use ($to, $subject, $fromName) {
            $message->to($to)
                ->subject($subject)
                ->from(config('mail.from.address'), $fromName ?: config('mail.from.name'));
        });
    }

    protected function wrapHtml(string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#111827; line-height:1.6;">
    {$body}
</body>
</html>
HTML;
    }

    public function validEmail(?string $value): bool
    {
        return $value !== null
            && trim($value) !== ''
            && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validPhone(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $digits = preg_replace('/[^0-9]/', '', $value);

        return strlen((string) $digits) >= 10;
    }

    public function normalizePhone(?string $value): string
    {
        $raw = trim((string) $value);
        $digits = preg_replace('/[^0-9]/', '', $raw);

        if (strlen((string) $digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen((string) $digits) === 11 && str_starts_with((string) $digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}
