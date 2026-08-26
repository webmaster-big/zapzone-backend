<?php

namespace App\Services;

use App\Models\CheckoutConcern;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CheckoutConcernService
{
    public function staffFor(int $locationId): Collection
    {
        return User::where('location_id', $locationId)
            ->where('status', 'active')
            ->get();
    }

    public function staffEmails(int $locationId): array
    {
        return $this->staffFor($locationId)
            ->pluck('email')
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    public function staffPhones(int $locationId): array
    {
        return $this->staffFor($locationId)
            ->pluck('phone')
            ->map(fn ($phone) => SmsService::toE164($phone))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function record(array $data): CheckoutConcern
    {
        $location = Location::with('company')->find($data['location_id']);

        if (!$location) {
            throw new \InvalidArgumentException('That venue could not be found.');
        }

        $concern = CheckoutConcern::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'kind' => $data['kind'],
            'name' => $this->singleLine($data['name']),
            'phone' => $this->singleLine($data['phone']),
            'email' => isset($data['email']) && $data['email'] !== '' ? strtolower(trim($data['email'])) : null,
            'message' => $data['message'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'entity_name' => $data['entity_name'] ?? null,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
            'context' => $data['context'] ?? null,
            'fingerprint' => $data['fingerprint'] ?? null,
            'status' => CheckoutConcern::STATUS_NEW,
        ]);

        $contact = $this->upsertContact($concern);

        if ($contact) {
            $concern->update(['contact_id' => $contact->id]);
        }

        Log::info('Checkout concern recorded', [
            'checkout_concern_id' => $concern->id,
            'kind' => $concern->kind,
            'company_id' => $concern->company_id,
            'location_id' => $concern->location_id,
            'venue' => $location->name,
            'guest_name' => $concern->name,
            'guest_phone' => $concern->phone,
            'guest_email' => $concern->email,
            'has_email' => (bool) $concern->email,
            'entity_type' => $concern->entity_type,
            'entity_id' => $concern->entity_id,
            'entity_name' => $concern->entity_name,
            'preferred_date' => $concern->preferred_date?->toDateString(),
            'preferred_time' => $concern->preferred_time,
            'step_reached' => data_get($concern->context, 'step_label'),
            'estimated_total' => data_get($concern->context, 'estimated_total'),
            'contact_id' => $contact?->id,
            'contact_created' => $contact ? ($contact->wasRecentlyCreated ? 'new' : 'updated') : 'none',
            'fingerprint' => $concern->fingerprint,
        ]);

        return $concern->fresh(['location.company', 'contact']);
    }

    protected function upsertContact(CheckoutConcern $concern): ?Contact
    {
        if (!$concern->email && !$concern->phone) {
            return null;
        }

        try {
            Log::info('Linking checkout concern to a contact', [
                'checkout_concern_id' => $concern->id,
                'matched_on' => $concern->email ? 'email' : 'phone',
                'email' => $concern->email,
                'phone' => $concern->phone,
            ]);

            return Contact::createOrUpdateFromSource(
                $concern->company_id,
                [
                    'email' => $concern->email,
                    'name' => $concern->name,
                    'phone' => $concern->phone,
                ],
                $concern->isScheduleHelp() ? 'checkout_schedule_help' : 'abandoned_checkout',
                [$concern->isScheduleHelp() ? 'schedule-help' : 'abandoned-checkout'],
                $concern->location_id
            );
        } catch (\Throwable $e) {
            Log::error('Could not store the checkout contact', [
                'checkout_concern_id' => $concern->id,
                'company_id' => $concern->company_id,
                'email' => $concern->email,
                'phone' => $concern->phone,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function alertStaff(CheckoutConcern $concern): array
    {
        $concern->loadMissing('location.company');

        $emails = $this->staffEmails($concern->location_id);
        $phones = $this->staffPhones($concern->location_id);

        $result = [
            'emails_sent' => [],
            'emails_failed' => [],
            'sms_sent' => [],
            'sms_failed' => [],
        ];

        $triggerType = $concern->isScheduleHelp()
            ? \App\Models\EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED
            : \App\Models\EmailNotification::TRIGGER_CHECKOUT_ABANDONED;

        Log::info('Alerting venue staff about a checkout concern', [
            'checkout_concern_id' => $concern->id,
            'kind' => $concern->kind,
            'trigger_type' => $triggerType,
            'location_id' => $concern->location_id,
            'venue' => $concern->location?->name,
            'staff_emails_found' => count($emails),
            'staff_emails' => $emails,
            'staff_phones_found' => count($phones),
            'staff_phones' => $phones,
            'sms_configured' => SmsService::isConfigured(),
            'mail_driver' => config('mail.default'),
            'gmail_api_enabled' => (bool) config('gmail.enabled'),
        ]);

        if (empty($emails) && empty($phones)) {
            Log::warning('No reachable staff for this venue - the concern was recorded but nobody was alerted', [
                'checkout_concern_id' => $concern->id,
                'location_id' => $concern->location_id,
                'venue' => $concern->location?->name,
                'active_staff_at_venue' => $this->staffFor($concern->location_id)->count(),
            ]);
        }

        if (\App\Models\EmailNotification::findForConcern($concern, $triggerType)->isNotEmpty()) {
            app(EmailNotificationService::class)->triggerCheckoutConcernNotification($concern, $triggerType);

            $result['emails_sent'] = $emails;
            $result['sms_sent'] = $phones;
            $result['via'] = 'notification_templates';

            $this->raiseBellNotification($concern);
            $concern->update(['alerted' => $result]);

            Log::info('Checkout concern alert dispatched via admin-editable templates', [
                'checkout_concern_id' => $concern->id,
                'kind' => $concern->kind,
                'trigger_type' => $triggerType,
                'venue' => $concern->location?->name,
                'emails' => $emails,
                'phones' => $phones,
            ]);

            return $result;
        }

        $view = $concern->isScheduleHelp()
            ? 'emails.staff-schedule-concern'
            : 'emails.staff-abandoned-checkout';

        $subject = $this->subjectFor($concern);

        if ($emails) {
            try {
                $html = view($view, [
                    'concern' => $concern,
                    'location' => $concern->location,
                    'reviewUrl' => rtrim(config('app.frontend_url'), '/') . '/customer-concerns',
                ])->render();

                foreach ($emails as $email) {
                    try {
                        $this->sendEmail($email, $subject, $html);
                        $result['emails_sent'][] = $email;
                        Log::info('Checkout concern email sent', [
                            'checkout_concern_id' => $concern->id,
                            'recipient' => $email,
                            'subject' => $subject,
                            'transport' => config('gmail.enabled') ? 'gmail_api' : config('mail.default'),
                        ]);
                    } catch (\Throwable $e) {
                        $result['emails_failed'][] = ['to' => $email, 'error' => $e->getMessage()];
                        Log::error('Staff checkout-concern email FAILED', [
                            'checkout_concern_id' => $concern->id,
                            'recipient' => $email,
                            'subject' => $subject,
                            'transport' => config('gmail.enabled') ? 'gmail_api' : config('mail.default'),
                            'error' => $e->getMessage(),
                            'exception' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Could not render the staff checkout-concern email', [
                    'checkout_concern_id' => $concern->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($phones && SmsService::isConfigured()) {
            $body = $this->smsBodyFor($concern);
            $sms = new SmsService();

            foreach ($phones as $phone) {
                try {
                    $sid = $sms->sendSms($phone, $body);
                    $result['sms_sent'][] = $phone;
                    Log::info('Checkout concern SMS accepted by provider', [
                        'checkout_concern_id' => $concern->id,
                        'recipient' => $phone,
                        'provider_sid' => $sid,
                        'body_length' => strlen($body),
                    ]);
                } catch (\Throwable $e) {
                    $result['sms_failed'][] = ['to' => $phone, 'error' => $e->getMessage()];
                    Log::error('Staff checkout-concern SMS FAILED', [
                        'checkout_concern_id' => $concern->id,
                        'recipient' => $phone,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        $result['via'] = 'built_in_defaults';

        $this->raiseBellNotification($concern);
        $concern->update(['alerted' => $result]);

        $level = ($result['emails_failed'] || $result['sms_failed']) ? 'warning' : 'info';

        Log::{$level}('Checkout concern alert finished via built-in defaults', [
            'checkout_concern_id' => $concern->id,
            'kind' => $concern->kind,
            'venue' => $concern->location?->name,
            'emails_sent' => $result['emails_sent'],
            'emails_failed' => $result['emails_failed'],
            'sms_sent' => $result['sms_sent'],
            'sms_failed' => $result['sms_failed'],
        ]);

        return $result;
    }

    protected function raiseBellNotification(CheckoutConcern $concern): void
    {
        try {
            Notification::create([
                'location_id' => $concern->location_id,
                'type' => 'customer',
                'priority' => $concern->isScheduleHelp() ? 'high' : 'medium',
                'title' => $concern->isScheduleHelp()
                    ? 'Customer needs help with the schedule'
                    : 'Checkout left unfinished',
                'message' => $this->smsBodyFor($concern),
                'status' => 'unread',
                'action_url' => '/customer-concerns',
                'action_text' => 'Call them back',
                'metadata' => [
                    'checkout_concern_id' => $concern->id,
                    'kind' => $concern->kind,
                    'customerName' => $concern->name,
                    'name' => $concern->name,
                    'phone' => $concern->phone,
                    'email' => $concern->email,
                    'entity_type' => $concern->entity_type,
                    'entity_id' => $concern->entity_id,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not create the staff notification for a checkout concern', [
                'checkout_concern_id' => $concern->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function hasPaidSince(int $locationId, ?string $email, \DateTimeInterface $since): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        $paid = fn ($query) => $query
            ->whereRaw('LOWER(guest_email) = ?', [$email])
            ->where('created_at', '>=', $since)
            ->where('amount_paid', '>', 0);

        if ($paid(\App\Models\Booking::where('location_id', $locationId))->exists()) {
            return true;
        }

        if ($paid(\App\Models\EventPurchase::where('location_id', $locationId))->exists()) {
            return true;
        }

        if ($paid(\App\Models\TicketOrder::where('location_id', $locationId))->exists()) {
            return true;
        }

        return $paid(\App\Models\AttractionPurchase::query())
            ->whereHas('attraction', fn ($q) => $q->where('location_id', $locationId))
            ->exists();
    }

    protected function singleLine(?string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));
    }

    protected function subjectFor(CheckoutConcern $concern): string
    {
        $venue = $this->singleLine($concern->location?->name) ?: 'your venue';
        $name = Str::limit($this->singleLine($concern->name), 60);

        return $concern->isScheduleHelp()
            ? "Schedule help needed — {$name} ({$venue})"
            : "Unfinished checkout — {$name} ({$venue})";
    }

    protected function smsBodyFor(CheckoutConcern $concern): string
    {
        $venue = $this->singleLine($concern->location?->name);
        $what = $concern->what_they_wanted;

        if ($concern->isScheduleHelp()) {
            $body = "Schedule help: {$concern->name} ({$concern->phone}) at {$venue}. {$what}.";

            if ($concern->message) {
                $body .= ' "' . Str::limit($this->singleLine($concern->message), 90) . '"';
            }

            return $body . ' Please call them back.';
        }

        return "Unfinished checkout: {$concern->name} ({$concern->phone}) at {$venue} left before paying. {$what}. Worth a call.";
    }

    protected function sendEmail(string $to, string $subject, string $html): void
    {
        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            try {
                (new GmailApiService())->sendEmail($to, $subject, $html, config('gmail.sender_name', 'Zap Zone'));

                return;
            } catch (\Throwable $e) {
                Log::warning('Gmail API send failed for a checkout concern; falling back to the mail transport.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Mail::html($html, function ($message) use ($to, $subject) {
            $message->to($to)
                ->subject($subject)
                ->from(config('mail.from.address'), config('mail.from.name'));
        });
    }
}
