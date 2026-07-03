<?php

namespace App\Services;

use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\EventPurchase;
use App\Models\Payment;
use App\Models\SmsNotification;
use App\Models\SmsNotificationLog;
use App\Models\User;
use App\Models\Waiver;
use Database\Seeders\DefaultSmsNotificationSeeder;
use Illuminate\Support\Facades\Log;

class SmsNotificationService
{
    protected SmsService $smsService;
    protected EmailNotificationService $variableSource;

    public function __construct()
    {
        $this->smsService = new SmsService();
        // Reuse the proven merge-variable builders from the email engine so
        // email and SMS always resolve the same {{fields}} identically.
        $this->variableSource = new EmailNotificationService();
    }

    public function triggerBookingNotification(Booking $booking, string $triggerType): void
    {
        if (!$this->enabled()) {
            return;
        }

        $booking->loadMissing(['customer', 'package', 'location.company', 'room', 'addOns']);
        $this->ensureDefaultsSeeded($booking->location?->company);

        $notifications = SmsNotification::findForBooking($booking, $triggerType);
        if ($notifications->isEmpty() && $triggerType === 'booking_created') {
            $notifications = SmsNotification::findForBooking($booking, SmsNotification::TRIGGER_BOOKING_CONFIRMED);
        }

        foreach ($notifications as $notification) {
            $this->send($notification, $booking, 'booking');
        }
    }

    public function triggerPurchaseNotification(AttractionPurchase $purchase, string $triggerType): void
    {
        if (!$this->enabled()) {
            return;
        }

        $purchase->loadMissing(['customer', 'attraction.location.company']);
        $this->ensureDefaultsSeeded($purchase->attraction?->location?->company);

        $notifications = SmsNotification::findForPurchase($purchase, $triggerType);
        if ($notifications->isEmpty() && $triggerType === 'purchase_created') {
            $notifications = SmsNotification::findForPurchase($purchase, SmsNotification::TRIGGER_PURCHASE_CONFIRMED);
        }

        foreach ($notifications as $notification) {
            $this->send($notification, $purchase, 'purchase');
        }
    }

    public function triggerEventNotification(EventPurchase $purchase, string $triggerType): void
    {
        if (!$this->enabled()) {
            return;
        }

        $purchase->loadMissing(['customer', 'event', 'location.company']);
        $this->ensureDefaultsSeeded($purchase->location?->company);

        $notifications = SmsNotification::findForEvent($purchase, $triggerType);
        if ($notifications->isEmpty() && $triggerType === 'event_created') {
            $notifications = SmsNotification::findForEvent($purchase, SmsNotification::TRIGGER_EVENT_CONFIRMED);
        }

        foreach ($notifications as $notification) {
            $this->send($notification, $purchase, 'event');
        }
    }

    public function triggerWaiverNotification(Waiver $waiver, string $triggerType): void
    {
        if (!$this->enabled()) {
            return;
        }

        $waiver->loadMissing(['customer', 'location.company', 'company', 'booking', 'event', 'template']);
        $this->ensureDefaultsSeeded($waiver->company ?? $waiver->location?->company);

        $notifications = SmsNotification::findForWaiver($waiver, $triggerType);

        foreach ($notifications as $notification) {
            $this->send($notification, $waiver, 'waiver');
        }
    }

    public function triggerPaymentNotification(Payment $payment, string $triggerType): void
    {
        if (!$this->enabled()) {
            return;
        }

        $payment->loadMissing(['payable']);

        $company = null;
        if ($payment->payable instanceof Booking) {
            $payment->payable->loadMissing('location.company');
            $company = $payment->payable->location?->company;
        } elseif ($payment->payable instanceof AttractionPurchase) {
            $payment->payable->loadMissing('attraction.location.company');
            $company = $payment->payable->attraction?->location?->company;
        } elseif ($payment->payable instanceof EventPurchase) {
            $payment->payable->loadMissing('location.company');
            $company = $payment->payable->location?->company;
        }
        $this->ensureDefaultsSeeded($company);

        foreach (SmsNotification::findForPayment($payment, $triggerType) as $notification) {
            $this->send($notification, $payment, 'payment');
        }
    }

    protected function enabled(): bool
    {
        return SmsService::isConfigured();
    }

    protected function ensureDefaultsSeeded(?Company $company): void
    {
        if (!$company) {
            return;
        }

        $hasDefaults = SmsNotification::where('company_id', $company->id)
            ->where('is_default', true)
            ->exists();

        if (!$hasDefaults) {
            try {
                DefaultSmsNotificationSeeder::seedForCompany($company);
            } catch (\Exception $e) {
                Log::warning('Failed to auto-seed SMS defaults', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function send(SmsNotification $notification, $entity, string $type, ?string $overrideRecipient = null): void
    {
        $recipients = $overrideRecipient
            ? [['phone' => $overrideRecipient, 'type' => 'custom']]
            : $this->getRecipients($notification, $entity, $type);

        if (empty($recipients)) {
            return;
        }

        $variables = $this->variableSource->buildVariables($entity, $type, false);
        $message = $this->toPlainText($this->replaceVariables($notification->getEffectiveBody(), $variables));

        if ($message === '') {
            return;
        }

        $segments = SmsNotification::segmentCount($message);

        foreach ($recipients as $recipient) {
            $log = null;
            try {
                $log = SmsNotificationLog::create([
                    'sms_notification_id' => $notification->id,
                    'recipient_phone' => $recipient['phone'],
                    'recipient_type' => $recipient['type'],
                    'notifiable_type' => get_class($entity),
                    'notifiable_id' => $entity->id,
                    'status' => SmsNotificationLog::STATUS_PENDING,
                    'segments' => $segments,
                ]);

                $sid = $this->smsService->sendSms($recipient['phone'], $message);
                $log->markAsSent($sid, $segments);
            } catch (\Throwable $e) {
                if ($log) {
                    $log->markAsFailed($e->getMessage());
                }
                Log::error('Failed to send SMS notification', [
                    'sms_notification_id' => $notification->id,
                    'recipient' => $recipient['phone'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getRecipients(SmsNotification $notification, $entity, string $type): array
    {
        $recipients = [];

        foreach (($notification->recipient_types ?? []) as $recipientType) {
            switch ($recipientType) {
                case SmsNotification::RECIPIENT_CUSTOMER:
                    $phone = $this->getCustomerPhone($entity, $type);
                    if ($phone) {
                        $recipients[] = ['phone' => $phone, 'type' => 'customer'];
                    }
                    break;
                case SmsNotification::RECIPIENT_STAFF:
                    foreach ($this->getRolePhones($entity, $type, ['attendant']) as $phone) {
                        $recipients[] = ['phone' => $phone, 'type' => 'staff'];
                    }
                    break;
                case SmsNotification::RECIPIENT_COMPANY_ADMIN:
                    foreach ($this->getCompanyRolePhones($entity, $type, ['company_admin', 'owner']) as $phone) {
                        $recipients[] = ['phone' => $phone, 'type' => 'company_admin'];
                    }
                    break;
                case SmsNotification::RECIPIENT_LOCATION_MANAGER:
                    foreach ($this->getRolePhones($entity, $type, ['location_manager']) as $phone) {
                        $recipients[] = ['phone' => $phone, 'type' => 'location_manager'];
                    }
                    break;
                case SmsNotification::RECIPIENT_CUSTOM:
                    foreach (($notification->custom_phones ?? []) as $phone) {
                        $recipients[] = ['phone' => $phone, 'type' => 'custom'];
                    }
                    break;
            }
        }

        $seen = [];
        return array_values(array_filter($recipients, function ($r) use (&$seen) {
            if (in_array($r['phone'], $seen, true)) {
                return false;
            }
            $seen[] = $r['phone'];
            return true;
        }));
    }

    protected function getCustomerPhone($entity, string $type): ?string
    {
        if ($type === 'payment') {
            $phone = $entity->customer?->phone ?? null;
            if (!$phone && $entity->payable) {
                $phone = $entity->payable->customer?->phone ?? ($entity->payable->guest_phone ?? null);
            }
            return $phone;
        }

        if ($type === 'waiver') {
            return $entity->adult_phone ?? $entity->customer?->phone;
        }

        return $entity->customer?->phone ?? ($entity->guest_phone ?? null);
    }

    protected function resolveLocationId($entity, string $type): ?int
    {
        if ($type === 'booking' || $type === 'event' || $type === 'waiver') {
            return $entity->location_id;
        }
        if ($type === 'purchase') {
            return $entity->attraction->location_id ?? null;
        }
        // payment
        $locationId = $entity->location_id ?? null;
        if (!$locationId && $entity->payable) {
            $locationId = $entity->payable->location_id ?? ($entity->payable->attraction->location_id ?? null);
        }
        return $locationId;
    }

    protected function resolveCompanyId($entity, string $type): ?int
    {
        if ($type === 'waiver') {
            return $entity->company_id ?? $entity->location?->company_id;
        }
        if ($type === 'booking' || $type === 'event') {
            return $entity->location?->company_id;
        }
        if ($type === 'purchase') {
            return $entity->attraction?->location?->company_id;
        }
        $payable = $entity->payable ?? null;
        if ($payable instanceof Booking || $payable instanceof EventPurchase) {
            return $payable->location?->company_id;
        }
        if ($payable instanceof AttractionPurchase) {
            return $payable->attraction?->location?->company_id;
        }
        return null;
    }

    protected function getRolePhones($entity, string $type, array $roles): array
    {
        $locationId = $this->resolveLocationId($entity, $type);
        if (!$locationId) {
            return [];
        }

        return User::where('location_id', $locationId)
            ->whereIn('role', $roles)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone')
            ->toArray();
    }

    protected function getCompanyRolePhones($entity, string $type, array $roles): array
    {
        $companyId = $this->resolveCompanyId($entity, $type);
        if (!$companyId) {
            return [];
        }

        return User::where('company_id', $companyId)
            ->whereIn('role', $roles)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone')
            ->toArray();
    }

    protected function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = preg_replace_callback(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                fn() => (string) ($value ?? ''),
                $content
            );
        }
        return $content;
    }

    /**
     * SMS is plain text: strip any HTML, decode entities, collapse whitespace.
     */
    protected function toPlainText(string $content): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $content);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}
