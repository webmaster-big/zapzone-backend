<?php

namespace App\Services;

use App\Mail\DynamicCampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use App\Models\FollowUpOptOut;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Support\OperatingDay;
use App\Support\TemplatePlaceholders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class EmailCampaignSender
{
    public const STALLED_AFTER_MINUTES = 15;

    public const SCHEDULE_GRACE_MINUTES = 60;

    private const UNSUBSCRIBE_FOOTER = '<p style="margin-top: 24px; font-size: 11px; color: #9ca3af; text-align: center;">Don&#039;t want emails like this? <a href="{{opt_out_link}}" style="color: #6b7280;">Unsubscribe</a>.</p>';

    public function registrantRecipients($company, array $filters, ?string $onlyEmail = null, ?array $staffLocationIds = null): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($filters['registration_event_ids'] ?? []))));

        if ($company === null || $ids === [] || !RegistrationEvent::isAvailable()) {
            return [];
        }

        $forms = RegistrationEvent::query()
            ->with(['location.company'])
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->when($staffLocationIds !== null, fn ($query) => $query->whereIn('location_id', $staffLocationIds))
            ->get()
            ->keyBy('id');

        if ($forms->isEmpty()) {
            return [];
        }

        $entries = RegistrationEntry::query()
            ->visible()
            ->where('company_id', $company->id)
            ->whereIn('registration_event_id', $forms->keys()->all())
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($onlyEmail !== null, fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$onlyEmail]))
            ->orderByDesc('id')
            ->get(['id', 'registration_event_id', 'location_id', 'name', 'email', 'phone']);

        $photos = app(PhotoDeliveryService::class);
        $sessions = $this->registrantPhotoSessions((int) $company->id, $entries->pluck('id')->all(), $forms->pluck('location_id')->unique()->all());
        $optedOut = $this->optedOutEmails((int) $company->id, $entries->pluck('email')->all());
        $onlyWithPhotos = filter_var($filters['only_with_photos'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $recipients = [];

        foreach ($entries->groupBy(fn (RegistrationEntry $entry) => strtolower(trim((string) $entry->email))) as $email => $group) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($optedOut[$email])) {
                continue;
            }

            $photographed = [];

            foreach ($group as $entry) {
                foreach ($sessions[$entry->id] ?? [] as $session) {
                    $photographed[$session->id] ??= [$session, $entry];
                }
            }

            if ($photographed === []) {
                if ($onlyWithPhotos) {
                    continue;
                }

                $photographed = [[null, $group->first()]];
            }

            usort($photographed, fn (array $a, array $b) => $a[0] && $b[0] ? strcmp($this->sessionStamp($b[0]), $this->sessionStamp($a[0])) : 0);

            foreach ($photographed as [$session, $entry]) {
                $recipients[] = [
                    'email' => $email,
                    'type' => 'event_registrant',
                    'id' => (int) $entry->id,
                    'variables' => $this->registrantVariables($entry, $forms[$entry->registration_event_id], $session, $company, $photos),
                ];
            }
        }

        return $recipients;
    }

    protected function registrantPhotoSessions(int $companyId, array $entryIds, array $locationIds): array
    {
        if ($entryIds === [] || !PhotoSession::supportsRegistrations()) {
            return [];
        }

        $links = DB::table('photo_session_registrations as link')
            ->whereIn('link.registration_entry_id', $entryIds)
            ->whereExists(function ($delivered) {
                $delivered->selectRaw('1')
                    ->from('photo_deliveries as delivery')
                    ->whereColumn('delivery.photo_session_id', 'link.photo_session_id')
                    ->whereColumn('delivery.registration_entry_id', 'link.registration_entry_id')
                    ->where('delivery.channel', PhotoDelivery::CHANNEL_EMAIL)
                    ->where('delivery.status', '!=', PhotoDelivery::STATUS_CANCELED)
                    ->where(function ($primary) {
                        $primary->whereNull('delivery.duplicate_of_id')
                            ->orWhereExists(function ($original) {
                                $original->selectRaw('1')
                                    ->from('photo_deliveries as original')
                                    ->whereColumn('original.id', 'delivery.duplicate_of_id')
                                    ->where('original.status', '!=', PhotoDelivery::STATUS_CANCELED);
                            });
                    });
            })
            ->get(['link.photo_session_id', 'link.registration_entry_id']);

        if ($links->isEmpty()) {
            return [];
        }

        $sessions = PhotoSession::query()
            ->live()
            ->where('company_id', $companyId)
            ->whereIn('location_id', $locationIds)
            ->whereKey($links->pluck('photo_session_id')->unique()->all())
            ->where('access_expires_at', '>', now())
            ->withCount(['photos as ready_photos_count' => fn ($photos) => $photos->ready()])
            ->get()
            ->filter(fn (PhotoSession $session) => $session->ready_photos_count > 0)
            ->keyBy('id');

        $byEntry = [];

        foreach ($links as $link) {
            if (isset($sessions[$link->photo_session_id])) {
                $byEntry[$link->registration_entry_id][] = $sessions[$link->photo_session_id];
            }
        }

        return $byEntry;
    }

    protected function sessionStamp(PhotoSession $session): string
    {
        return ($session->captured_at ?: $session->created_at)?->format('YmdHis') . str_pad((string) $session->id, 12, '0', STR_PAD_LEFT);
    }

    public function optedOutEmails(int $companyId, array $emails): array
    {
        if ($emails === [] || !\App\Models\FollowUpOptOut::isAvailable()) {
            return [];
        }

        $keys = array_values(array_unique(array_map(fn ($email) => strtolower(trim((string) $email)), $emails)));

        return \App\Models\FollowUpOptOut::query()
            ->where('company_id', $companyId)
            ->whereIn('email', $keys)
            ->pluck('email')
            ->mapWithKeys(fn ($email) => [strtolower(trim((string) $email)) => true])
            ->all();
    }

    protected function registrantVariables(RegistrationEntry $entry, RegistrationEvent $form, ?PhotoSession $session, $company, PhotoDeliveryService $photos): array
    {
        $location = $form->location;
        $tz = OperatingDay::timezoneFor($location);
        $links = $photos->linkVariables((int) $company->id, $location);
        $email = strtolower(trim((string) $entry->email));

        return array_merge(
            $this->buildVariables(trim((string) $entry->name), strtolower(trim((string) $entry->email)), 'event_registrant', $company, $location, $entry->phone),
            [
                'recipient_first_name' => 'there',
                'recipient_last_name' => '',
                'first_name' => 'there',
                'business_name' => (string) ($company->company_name ?? 'Zap Zone'),
                'support_contact' => (string) config('photos.support_contact'),
                'photo_link' => $session ? $photos->photoLink($session) : '',
                'photo_count' => $session ? (string) $session->ready_photos_count : '0',
                'photo_date' => $session ? ($session->captured_at ?: $session->created_at)->copy()->setTimezone($tz)->format('M j, Y') : '',
                'expires_on' => $session?->access_expires_at?->copy()->setTimezone($tz)->format('M j, Y') ?? '',
                'photo_session_id' => $session ? (int) $session->id : null,
                'opt_out_link' => FollowUpOptOut::isAvailable() ? app(VisitFollowUpService::class)->optOutUrl((int) $company->id, $email) : '',
            ],
            $photos->eventDetails($form),
            array_diff_key($links, ['links' => true]),
            ['links' => $photos->linksHtml((int) $company->id, $location)]
        );
    }

    protected function registrantStillListed(EmailCampaign $campaign, string $email): bool
    {
        $ids = array_map('intval', (array) ($campaign->recipient_filters['registration_event_ids'] ?? []));

        return $ids !== [] && RegistrationEntry::query()
            ->visible()
            ->where('company_id', $campaign->company_id)
            ->whereIn('registration_event_id', RegistrationEvent::query()->whereIn('id', $ids)->pluck('id'))
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
            ->exists();
    }

    /** Checked as each customer email goes out, so someone who unsubscribed after the campaign was made is still left out. */
    protected function customerRefusal(EmailCampaign $campaign, EmailCampaignLog $log): ?string
    {
        if (!FollowUpOptOut::isAvailable()) {
            return 'Not sent: customer emails need an unsubscribe link, and unsubscribing is not set up on this server.';
        }

        return FollowUpOptOut::hasOptedOut((int) $campaign->company_id, $log->recipient_email)
            ? 'Not sent: this person unsubscribed.'
            : null;
    }

    public function buildVariables(string $name, string $email, string $type, $company, $location, ?string $phone = null): array
    {
        $locationAddress = $location
            ? trim(implode(', ', array_filter([
                $location->address,
                $location->city,
                $location->state,
                $location->zip_code
            ])))
            : '';

        return [
            'recipient_email' => $email,
            'recipient_name' => $name,
            'recipient_first_name' => explode(' ', $name)[0] ?? '',
            'recipient_last_name' => explode(' ', $name)[1] ?? '',
            'recipient_phone' => $phone ?? '',
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'company_address' => $company?->address ?? '',
            'location_name' => $location?->name ?? '',
            'location_email' => $location?->email ?? '',
            'location_phone' => $location?->phone ?? '',
            'location_address' => $locationAddress,
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
        ];
    }

    public static function queuedMessage(int $count): string
    {
        return "Sending {$count} emails in the background. A big list can take a while; refresh the campaign to follow along.";
    }

    public function sendWaiting(float $deadline): int
    {
        $sent = 0;

        EmailCampaign::query()
            ->where(fn ($due) => $due
                ->where('status', EmailCampaign::STATUS_SENDING)
                ->orWhere(fn ($scheduled) => $scheduled->where('status', EmailCampaign::STATUS_PENDING)->where('scheduled_at', '<=', now())))
            ->get()
            ->each(fn (EmailCampaign $campaign) => $this->recoverStalledSend($campaign));
        EmailCampaign::query()
            ->where('status', EmailCampaign::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())
            ->update(['status' => EmailCampaign::STATUS_SENDING, 'sent_at' => now()]);
        EmailCampaign::query()->where('status', EmailCampaign::STATUS_SENDING)->toBase()->update(['updated_at' => now()]);

        foreach (EmailCampaign::query()->where('status', EmailCampaign::STATUS_SENDING)->orderBy('id')->pluck('id') as $campaignId) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $campaign = EmailCampaign::query()->find($campaignId);

            if (!$campaign) {
                continue;
            }

            try {
                $sent += $this->sendCampaign($campaign, null, $deadline);
            } catch (\Throwable $e) {
                Log::error('Email campaign stopped while sending', ['campaign_id' => $campaignId, 'error' => $e->getMessage()]);
                $this->recoverStalledSend($campaign, true);
            }
        }

        return $sent;
    }

    public function cancelPending(EmailCampaign $campaign): void
    {
        EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignLog::STATUS_PENDING)
            ->update(['status' => EmailCampaignLog::STATUS_CANCELLED, 'error_message' => 'Not sent: the campaign was cancelled.']);

        $this->syncCounts($campaign);
    }

    public function sendCampaign(EmailCampaign $campaign, ?array $onlyLogIds = null, ?float $deadline = null): int
    {
        $status = EmailCampaign::query()->whereKey($campaign->id)->value('status');

        if ($status === EmailCampaign::STATUS_PENDING) {
            EmailCampaign::query()->whereKey($campaign->id)->where('status', EmailCampaign::STATUS_PENDING)
                ->update(['status' => EmailCampaign::STATUS_SENDING, 'sent_at' => now(), 'updated_at' => now()]);
        } elseif ($status !== EmailCampaign::STATUS_SENDING) {
            return 0;
        }

        $sent = 0;

        $logIds = EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignLog::STATUS_PENDING)
            ->when($onlyLogIds !== null, fn ($query) => $query->whereIn('id', $onlyLogIds))
            ->orderBy('id')
            ->pluck('id');

        foreach ($logIds as $logId) {
            if (($deadline !== null && microtime(true) >= $deadline) || !$this->stillSending($campaign->id)) {
                $this->syncCounts($campaign);

                return $sent;
            }

            $claimed = EmailCampaignLog::query()
                ->whereKey($logId)
                ->where('status', EmailCampaignLog::STATUS_PENDING)
                ->update(['status' => EmailCampaignLog::STATUS_SENDING, 'updated_at' => now()]);

            if ($claimed === 1 && ($log = EmailCampaignLog::query()->find($logId))) {
                $this->sendSingleEmail($campaign, $log);
                $sent++;
            }
        }

        EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignLog::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STALLED_AFTER_MINUTES))
            ->update(['status' => EmailCampaignLog::STATUS_FAILED, 'error_message' => 'Not sent: sending stopped part-way through.']);

        $unfinished = EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->whereIn('status', [EmailCampaignLog::STATUS_PENDING, EmailCampaignLog::STATUS_SENDING])
            ->exists();

        if (!$unfinished) {
            EmailCampaign::query()->whereKey($campaign->id)->where('status', EmailCampaign::STATUS_SENDING)
                ->update(['status' => EmailCampaign::STATUS_COMPLETED, 'completed_at' => now()]);
        }

        $this->syncCounts($campaign);

        return $sent;
    }

    protected function stillSending(int $campaignId): bool
    {
        if (!EmailCampaign::query()->whereKey($campaignId)->where('status', EmailCampaign::STATUS_SENDING)->exists()) {
            return false;
        }

        EmailCampaign::query()->whereKey($campaignId)->update(['updated_at' => now()]);

        return true;
    }

    public function recoverStalledSend(EmailCampaign $campaign, bool $now = false): void
    {
        if ($campaign->status === EmailCampaign::STATUS_PENDING && !$now) {
            $this->expireMissedSchedule($campaign);

            return;
        }

        if ($campaign->status !== EmailCampaign::STATUS_SENDING && !$now) {
            return;
        }

        $stopped = EmailCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', EmailCampaign::STATUS_SENDING)
            ->when(!$now, fn ($query) => $query->where('updated_at', '<', now()->subMinutes(self::STALLED_AFTER_MINUTES)))
            ->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => 'Sending stopped part-way through. The people it did not reach are listed as failed, so Resend to Failed Recipients sends to them.',
            ]);

        if ($stopped === 0) {
            return;
        }

        EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->whereIn('status', [EmailCampaignLog::STATUS_PENDING, EmailCampaignLog::STATUS_SENDING])
            ->update(['status' => EmailCampaignLog::STATUS_FAILED, 'error_message' => 'Not sent: sending stopped part-way through.']);

        $this->syncCounts($campaign);
        $campaign->refresh();
    }

    protected function expireMissedSchedule(EmailCampaign $campaign): void
    {
        if ($campaign->scheduled_at === null) {
            return;
        }

        $missed = EmailCampaign::query()
            ->whereKey($campaign->id)
            ->where('status', EmailCampaign::STATUS_PENDING)
            ->where('scheduled_at', '<', now()->subMinutes(self::SCHEDULE_GRACE_MINUTES))
            ->update([
                'status' => EmailCampaign::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => 'This campaign was due ' . $campaign->scheduled_at->copy()->timezone(config('app.timezone'))->format('M j \a\t g:i A') . ', but sending was down then, so nothing went out. Resend to Failed Recipients sends it now.',
            ]);

        if ($missed === 0) {
            return;
        }

        EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->where('status', EmailCampaignLog::STATUS_PENDING)
            ->update(['status' => EmailCampaignLog::STATUS_FAILED, 'error_message' => 'Not sent: the scheduled time passed while sending was down.']);

        $this->syncCounts($campaign);
        $campaign->refresh();
    }

    public function syncCounts(EmailCampaign $campaign): void
    {
        $counts = EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        EmailCampaign::query()->whereKey($campaign->id)->update([
            'sent_count' => (int) ($counts[EmailCampaignLog::STATUS_SENT] ?? 0) + (int) ($counts[EmailCampaignLog::STATUS_OPENED] ?? 0) + (int) ($counts['clicked'] ?? 0),
            'failed_count' => (int) ($counts[EmailCampaignLog::STATUS_FAILED] ?? 0),
        ]);
    }

    protected function currentRegistrantVariables(EmailCampaign $campaign, EmailCampaignLog $log): array|string
    {
        $email = strtolower(trim((string) $log->recipient_email));
        $current = $this->registrantRecipients($campaign->company, (array) ($campaign->recipient_filters ?? []), $email);
        $sessionId = $log->variables_used['photo_session_id'] ?? null;

        if ($sessionId !== null) {
            foreach ($current as $recipient) {
                if ((int) ($recipient['variables']['photo_session_id'] ?? 0) === (int) $sessionId) {
                    return $recipient['variables'];
                }
            }

            $named = $this->sessionsNamedByOtherLogs($campaign, $email, (int) $log->id);
            $replacement = collect($current)->first(fn (array $recipient) => ($recipient['variables']['photo_session_id'] ?? null) !== null
                && !in_array((int) $recipient['variables']['photo_session_id'], $named, true));

            if ($replacement !== null) {
                return $replacement['variables'];
            }

            $withoutPhotos = collect($current)->first(fn (array $recipient) => ($recipient['variables']['photo_session_id'] ?? null) === null);

            if ($withoutPhotos !== null && $this->mayGoWithoutLink($campaign, $email, (int) $log->id)) {
                return $withoutPhotos['variables'];
            }

            if ($current !== []) {
                return 'Not sent: these photos are no longer available.';
            }
        } elseif ($current !== []) {
            return $current[0]['variables'];
        }

        if (!$this->registrantStillListed($campaign, $email)) {
            return 'Not sent: this registration was hidden or removed.';
        }

        if (FollowUpOptOut::hasOptedOut((int) $campaign->company_id, $email)) {
            return 'Not sent: this person unsubscribed.';
        }

        return 'Not sent: their photos are no longer available.';
    }

    protected function otherLogsFor(EmailCampaign $campaign, string $email, int $logId)
    {
        return EmailCampaignLog::query()
            ->where('email_campaign_id', $campaign->id)
            ->whereRaw('LOWER(TRIM(recipient_email)) = ?', [$email])
            ->whereKeyNot($logId);
    }

    protected function sessionsNamedByOtherLogs(EmailCampaign $campaign, string $email, int $logId): array
    {
        return $this->otherLogsFor($campaign, $email, $logId)
            ->pluck('variables_used')
            ->map(fn ($variables) => $variables['photo_session_id'] ?? null)
            ->filter()
            ->map(fn ($sessionId) => (int) $sessionId)
            ->values()
            ->all();
    }

    protected function mayGoWithoutLink(EmailCampaign $campaign, string $email, int $logId): bool
    {
        return !$this->otherLogsFor($campaign, $email, $logId)
            ->where(fn ($other) => $other
                ->whereIn('status', [EmailCampaignLog::STATUS_SENT, EmailCampaignLog::STATUS_OPENED, EmailCampaignLog::STATUS_CLICKED])
                ->orWhere(fn ($earlier) => $earlier
                    ->where('id', '<', $logId)
                    ->whereIn('status', [EmailCampaignLog::STATUS_PENDING, EmailCampaignLog::STATUS_SENDING])))
            ->exists();
    }

    protected function sendSingleEmail(EmailCampaign $campaign, EmailCampaignLog $log): void
    {
        try {
            if ($log->recipient_type === 'event_registrant') {
                $current = $this->currentRegistrantVariables($campaign, $log);

                if (is_string($current)) {
                    $log->markAsFailed($current);
                    $campaign->incrementFailed();

                    return;
                }

                $log->forceFill(['variables_used' => $current])->save();
            }

            $variables = ['current_date' => now()->format('F j, Y'), 'current_year' => (string) now()->year] + $this->withEventDefaults($log->variables_used ?? []);
            $body = $campaign->body;

            if ($log->recipient_type === 'customer') {
                $refusal = $this->customerRefusal($campaign, $log);

                if ($refusal !== null) {
                    $log->markAsFailed($refusal);
                    $campaign->incrementFailed();

                    return;
                }

                $variables['opt_out_link'] = app(VisitFollowUpService::class)->optOutUrl((int) $campaign->company_id, FollowUpOptOut::normalize($log->recipient_email));
            }

            if (in_array($log->recipient_type, ['event_registrant', 'customer'], true) && $variables['opt_out_link'] !== '' && !preg_match('/\{\{\s*opt_out_link\s*\}\}/', $body)) {
                $body .= self::UNSUBSCRIBE_FOOTER;
            }

            $processedSubject = $this->stripPlaceholders($this->replaceVariables($campaign->subject, ['links' => '', 'opt_out_link' => ''] + $variables));
            $processedBody = $this->stripPlaceholders($this->replaceVariables($body, $variables, true));

            $htmlBody = $this->generateHtmlEmail($processedBody, $variables);

            $useGmailApi = config('gmail.enabled', false) &&
                (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                $emailAttachments = $this->prepareAttachments($campaign->attachments ?? []);

                $gmailService = new GmailApiService();
                $gmailService->sendEmail(
                    $log->recipient_email,
                    $processedSubject,
                    $htmlBody,
                    $variables['company_name'] ?? 'Zap Zone',
                    $emailAttachments
                );
            } else {
                Mail::to($log->recipient_email)
                    ->send(new DynamicCampaignMail(
                        $processedSubject,
                        $processedBody,
                        []
                    ));
            }

            $log->markAsSent();
            $campaign->incrementSent();

        } catch (\Throwable $e) {
            Log::error("Failed to send email to {$log->recipient_email}: " . $e->getMessage());
            $log->markAsFailed($e->getMessage());
            $campaign->incrementFailed();
        }
    }

    public function replaceVariables(string $content, array $variables, bool $escape = false): string
    {
        return TemplatePlaceholders::fill($content, $variables, $escape, ['links']);
    }

    public function stripPlaceholders(string $content): string
    {
        return (string) preg_replace('/\{\{\s*[A-Za-z0-9_]+\s*\}\}/', '', $content);
    }

    public function withEventDefaults(array $variables): array
    {
        return $variables + [
            'first_name' => $variables['recipient_first_name'] ?? '',
            'business_name' => $variables['company_name'] ?? '',
            'support_contact' => (string) config('photos.support_contact'),
            'event_name' => '',
            'event_date' => '',
            'photo_link' => '',
            'photo_count' => '',
            'photo_date' => '',
            'expires_on' => '',
            'links' => '',
            'opt_out_link' => '',
        ];
    }

    public function generateHtmlEmail(string $body, array $variables): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    {$body}
</body>
</html>
HTML;
    }

    public function prepareAttachments(array $storedAttachments): array
    {
        $emailAttachments = [];

        Log::info('Preparing attachments', ['count' => count($storedAttachments), 'attachments' => $storedAttachments]);

        foreach ($storedAttachments as $attachment) {
            $filePath = storage_path('app/public/' . $attachment['path']);

            Log::info('Processing attachment', ['path' => $filePath, 'exists' => file_exists($filePath)]);

            if (file_exists($filePath)) {
                $emailAttachments[] = [
                    'filename' => $attachment['original_name'],
                    'mime_type' => $attachment['mime_type'],
                    'data' => base64_encode(file_get_contents($filePath)),
                ];
            } else {
                Log::warning('Attachment file not found', ['path' => $filePath]);
            }
        }

        Log::info('Prepared attachments for email', ['count' => count($emailAttachments)]);

        return $emailAttachments;
    }
}
