<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\MessageLink;
use App\Models\Notification;
use App\Models\PhotoDelivery;
use App\Models\PhotoMessageTemplate;
use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\SmsSenderVerification;
use App\Models\User;
use App\Models\Waiver;
use App\Support\OperatingDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PhotoDeliveryService
{
    public const SETTLE_WINDOW_DAYS = 7;

    public const RECENT_TEXT_DAYS = 14;

    public const LINKS_TEXT_LIMIT = 480;

    protected SmsService $sms;

    protected bool $lastLookupFailed = false;

    protected array $senderBlockedMemo = [];

    public function __construct()
    {
        $this->sms = app(SmsService::class);
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

    public function channelDiagnostics(?int $companyId = null, bool $withStats = true): array
    {
        $texting = $companyId ? $this->textingStatus($companyId, $withStats) : $this->configuredTextingStatus();

        return [
            'sms_available' => $this->smsAvailable(),
            'email_available' => $this->emailAvailable(),
            'email_transport' => $this->emailTransport(),
            'sms_status' => $texting,
            'sms_note' => $texting['note'],
            'email_note' => $this->emailAvailable()
                ? null
                : 'Email is not switched on yet, so messages are written to the site log instead of being delivered. Your administrator can enable it in the site settings.',
            'photo_link_base' => $this->photoLinkBase(),
            'photo_link_note' => $this->photoLinkNote(),
        ];
    }

    protected function configuredTextingStatus(): array
    {
        $sender = SmsService::senderNumber();
        $configured = $this->smsAvailable();

        return [
            'state' => $configured ? 'unverified' : 'not_configured',
            'headline' => $configured ? 'Texting not confirmed yet' : 'Texting is switched off',
            'note' => $configured ? null : 'Text messaging is not switched on yet, so photo links go out by email only. Your administrator can enable it in the site settings.',
            'sender' => $sender,
            'sender_kind' => SmsService::senderKind($sender),
            'verified' => null,
            'problem' => null,
            'recent' => $this->emptyTextStats(),
        ];
    }

    protected function emptyTextStats(int $days = self::RECENT_TEXT_DAYS): array
    {
        return [
            'days' => $days,
            'attempted' => 0,
            'received' => 0,
            'delivered' => 0,
            'awaiting_carrier' => 0,
            'unconfirmed' => 0,
            'not_delivered' => 0,
            'failed' => 0,
            'skipped' => 0,
            'top_problem' => null,
        ];
    }

    protected function emailFallbackNote(): string
    {
        return $this->emailAvailable()
            ? 'Guests with an email address on their waiver or event registration also get the email. For a guest with only a phone number, show the direct staff QR code instead.'
            : 'Email is not switched on either, so show the guest the direct staff QR code instead.';
    }

    public function textingStatus(?int $companyId, bool $withStats = true): array
    {
        $sender = SmsService::senderNumber();
        $status = $this->configuredTextingStatus();

        if (!$this->smsAvailable()) {
            return $status;
        }

        if ($withStats) {
            $status['recent'] = $this->recentTextStats($companyId);
        }

        $verification = $companyId && $sender
            ? SmsSenderVerification::activeFor($companyId, $sender)->with(['confirmer', 'location'])->latest('confirmed_at')->first()
            : null;

        if ($verification) {
            $status['verified'] = [
                'at' => $verification->confirmed_at?->toIso8601String(),
                'method' => $verification->method,
                'by_name' => $verification->confirmer
                    ? trim(($verification->confirmer->first_name ?? '') . ' ' . ($verification->confirmer->last_name ?? ''))
                    : null,
                'location_name' => $verification->location?->name,
                'photo_delivery_id' => $verification->photo_delivery_id,
            ];
        }

        $problem = $this->recentSenderProblem($companyId);
        $status['problem'] = $problem;

        if ($problem) {
            return array_merge($status, [
                'state' => 'failing',
                'headline' => 'Texts are not reaching phones',
                'note' => 'Recent photo texts are not reaching guests\' phones: ' . $problem['meaning'] . ' ' . $this->emailFallbackNote(),
            ]);
        }

        if ($verification) {
            return array_merge($status, [
                'state' => 'verified',
                'headline' => 'Texting confirmed working',
                'note' => null,
            ]);
        }

        $pending = $companyId && $sender ? $this->pendingStaffReceipt($companyId, $sender) : null;

        return array_merge($status, [
            'state' => 'unverified',
            'headline' => 'Texting not confirmed yet',
            'pending_receipt' => $pending,
            'note' => $pending
                ? sprintf(
                    'A staff member saw a photo text on a guest\'s phone on %s. Texting shows as confirmed once the phone carrier reports that text delivered, or after %d hours if the carrier reports no problem. ',
                    $pending['at_label'],
                    PhotoDelivery::CARRIER_CHECK_HOURS
                ) . $this->emailFallbackNote()
                : 'Photo texts are being sent, but no guest has confirmed receiving one on their phone yet, so texting is not confirmed as working. ' . $this->emailFallbackNote(),
        ]);
    }

    protected function pendingStaffReceipt(int $companyId, string $sender): ?array
    {
        $lastReset = $this->latestResetFor($companyId);

        $receipt = PhotoDelivery::where('company_id', $companyId)
            ->where('channel', PhotoDelivery::CHANNEL_SMS)
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->where('receipt_confirmed_via', PhotoDelivery::RECEIPT_VIA_STAFF)
            ->where('provider_from', $sender)
            ->where('sent_at', '>=', now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS)->subDays(self::SETTLE_WINDOW_DAYS))
            ->when($lastReset, fn ($q) => $q->where('sent_at', '>', $lastReset))
            ->where(function ($q) {
                $q->whereNull('provider_status')->orWhereNotIn('provider_status', SmsService::NOT_DELIVERED_STATUSES);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('sms_sender_verifications')
                    ->whereColumn('sms_sender_verifications.provider_sid', 'photo_deliveries.provider_sid');
            })
            ->latest('receipt_confirmed_at')
            ->first();

        if (!$receipt) {
            return null;
        }

        return [
            'photo_delivery_id' => $receipt->id,
            'at' => $receipt->receipt_confirmed_at?->toIso8601String(),
            'at_label' => $receipt->receipt_confirmed_at?->format('M j') ?? 'a recent day',
            'settles_after' => $receipt->sent_at?->copy()->addHours(PhotoDelivery::CARRIER_CHECK_HOURS)->toIso8601String(),
        ];
    }

    public function recentTextStats(?int $companyId, int $days = self::RECENT_TEXT_DAYS): array
    {
        $query = PhotoDelivery::where('channel', PhotoDelivery::CHANNEL_SMS)
            ->whereNull('duplicate_of_id')
            ->where('created_at', '>=', now()->subDays($days));

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $rows = $query->latest('id')->limit(1000)->get();
        $byOutcome = $rows->groupBy(fn (PhotoDelivery $d) => $d->outcome())->map->count();
        $count = fn (string $outcome) => (int) ($byOutcome[$outcome] ?? 0);

        $problem = $rows
            ->filter(fn (PhotoDelivery $d) => in_array($d->outcome(), [PhotoDelivery::OUTCOME_NOT_DELIVERED, PhotoDelivery::OUTCOME_FAILED], true)
                && $d->provider_error_code !== null)
            ->groupBy('provider_error_code')
            ->map->count()
            ->sortDesc();

        $topCode = $problem->keys()->first();

        return [
            'days' => $days,
            'attempted' => $rows->whereNotIn('status', [PhotoDelivery::STATUS_SKIPPED, PhotoDelivery::STATUS_CANCELED, PhotoDelivery::STATUS_SCHEDULED])->count(),
            'received' => $count(PhotoDelivery::OUTCOME_RECEIVED),
            'delivered' => $count(PhotoDelivery::OUTCOME_DELIVERED),
            'awaiting_carrier' => $count(PhotoDelivery::OUTCOME_AWAITING),
            'unconfirmed' => $count(PhotoDelivery::OUTCOME_UNCONFIRMED),
            'not_delivered' => $count(PhotoDelivery::OUTCOME_NOT_DELIVERED),
            'failed' => $count(PhotoDelivery::OUTCOME_FAILED),
            'skipped' => $count(PhotoDelivery::OUTCOME_SKIPPED),
            'top_problem' => $topCode === null ? null : [
                'code' => (string) $topCode,
                'meaning' => SmsService::meaningOf((string) $topCode),
                'count' => (int) $problem->first(),
            ],
        ];
    }

    protected function settledTextsQuery(?string $sender)
    {
        return PhotoDelivery::where('channel', PhotoDelivery::CHANNEL_SMS)
            ->whereNull('duplicate_of_id')
            ->where('created_at', '>=', now()->subDays(self::RECENT_TEXT_DAYS))
            ->where(function ($q) use ($sender) {
                $q->where('provider_from', $sender)
                    ->orWhere(function ($refused) {
                        $refused->whereNull('provider_from')->where('status', PhotoDelivery::STATUS_FAILED);
                    });
            })
            ->where(function ($q) {
                $q->whereNotNull('receipt_confirmed_at')
                    ->orWhereIn('provider_status', SmsService::FINAL_STATUSES)
                    ->orWhere(function ($failed) {
                        $failed->where('status', PhotoDelivery::STATUS_FAILED)->whereNotNull('provider_error_code');
                    });
            })
            ->where(function ($q) {
                $q->whereNull('provider_error_code')->orWhereNotIn('provider_error_code', SmsService::GUEST_PROBLEM_CODES);
            })
            ->orderByRaw("CASE WHEN status = 'failed' THEN updated_at ELSE COALESCE(sent_at, created_at) END DESC")
            ->orderByDesc('id');
    }

    public function senderBlockedNow(): bool
    {
        $sender = SmsService::senderNumber();

        if ($sender === null) {
            return false;
        }

        return $this->senderBlockCode() !== null;
    }

    public function senderBlockCode(): ?string
    {
        $sender = SmsService::senderNumber();

        if ($sender === null) {
            return null;
        }

        if (!array_key_exists($sender, $this->senderBlockedMemo)) {
            $latest = $this->latestDecisiveText($sender);
            $this->senderBlockedMemo[$sender] = $this->isSenderBlock($latest) ? (string) $latest->provider_error_code : null;
        }

        return $this->senderBlockedMemo[$sender];
    }

    protected function latestDecisiveText(?string $sender): ?PhotoDelivery
    {
        return $this->settledTextsQuery($sender)
            ->where(function ($q) {
                $q->whereNotNull('receipt_confirmed_at')
                    ->orWhereIn('provider_status', ['delivered', 'read'])
                    ->orWhereIn('provider_error_code', SmsService::SENDER_PROBLEM_CODES);
            })
            ->first();
    }

    protected function isSenderBlock(?PhotoDelivery $delivery): bool
    {
        return $delivery !== null
            && !$delivery->reachedGuest()
            && in_array((string) $delivery->provider_error_code, SmsService::SENDER_PROBLEM_CODES, true);
    }

    public function blockedNow(PhotoDelivery $delivery): bool
    {
        return $delivery->isText() && $this->senderBlockedNow();
    }

    public function retryWouldHelp(PhotoDelivery $delivery): bool
    {
        return $delivery->retryIsUseful() && !$this->blockedNow($delivery);
    }

    protected function recentSenderProblem(?int $companyId): ?array
    {
        $sender = SmsService::senderNumber();

        $latestAnywhere = $this->latestDecisiveText($sender);
        $blockedEverywhere = $this->isSenderBlock($latestAnywhere);

        $settled = $companyId
            ? $this->settledTextsQuery($sender)->where('company_id', $companyId)->limit(10)->get()
            : $this->settledTextsQuery($sender)->limit(10)->get();

        $lastThreeMissed = $settled->count() >= 3 && $settled->take(3)->every(fn (PhotoDelivery $d) => !$d->reachedGuest());

        if (!$blockedEverywhere && !$lastThreeMissed) {
            return null;
        }

        $missed = $settled->reject(fn (PhotoDelivery $d) => $d->reachedGuest());
        $code = $blockedEverywhere
            ? (string) $latestAnywhere->provider_error_code
            : (string) ($missed->pluck('provider_error_code')->filter(fn ($c) => in_array((string) $c, SmsService::SENDER_PROBLEM_CODES, true))->countBy()->sortDesc()->keys()->first()
                ?? $missed->pluck('provider_error_code')->filter()->countBy()->sortDesc()->keys()->first()
                ?? '');
        $seen = $blockedEverywhere ? $latestAnywhere : $settled->first();

        return [
            'code' => $code !== '' ? $code : null,
            'meaning' => SmsService::meaningOf($code) ?? 'The phone carriers are not delivering them.',
            'seen_at' => ($seen->sent_at ?? $seen->created_at)?->toIso8601String(),
        ];
    }

    public function lookupIsDue(PhotoDelivery $delivery): bool
    {
        return $delivery->provider_checked_at === null
            || $delivery->provider_checked_at->lessThanOrEqualTo(now()->subSeconds(10));
    }

    public function refreshCarrierStatus(PhotoDelivery $delivery, bool $force = false): PhotoDelivery
    {
        $this->lastLookupFailed = false;
        $this->senderBlockedMemo = [];

        if (!$delivery->isText() || $delivery->provider_sid === null || SmsService::isFinalStatus($delivery->provider_status)) {
            return $delivery;
        }

        if (!$force && !$this->lookupIsDue($delivery)) {
            return $delivery;
        }

        $sid = $delivery->provider_sid;

        try {
            $message = $this->sms->fetchMessage($sid);
        } catch (\Throwable $e) {
            $this->lastLookupFailed = true;
            Log::warning('Could not check a photo text with the provider', [
                'delivery_id' => $delivery->id,
                'error' => $e->getMessage(),
            ]);
            PhotoDelivery::whereKey($delivery->id)->where('provider_sid', $sid)->update(['provider_checked_at' => now()]);

            return $delivery->fresh() ?? $delivery;
        }

        $status = $message['status'] !== '' ? $message['status'] : $delivery->provider_status;
        $changes = [
            'provider_status' => $status,
            'provider_error_code' => $message['error_code'],
            'provider_checked_at' => now(),
        ];

        if (in_array($status, ['delivered', 'read'], true)) {
            $changes['delivered_at'] = $delivery->delivered_at ?? now();
        }

        PhotoDelivery::whereKey($delivery->id)->where('provider_sid', $sid)->update($changes);

        if (in_array($status, SmsService::NOT_DELIVERED_STATUSES, true)) {
            $this->withdrawReceiptFor($delivery->id, $sid, 'The phone carrier later reported this text as not delivered.');
        }

        $fresh = $delivery->fresh() ?? $delivery;

        if ($fresh->provider_sid === $sid && $fresh->carrierSaysDelivered() && $fresh->receipt_confirmed_at !== null) {
            $this->recordVerification($fresh, $fresh->receipt_confirmed_via === PhotoDelivery::RECEIPT_VIA_LINK
                ? SmsSenderVerification::METHOD_GUEST_OPENED_LINK
                : SmsSenderVerification::METHOD_STAFF_CONFIRMED, $fresh->receipt_confirmed_by);
        }

        return $fresh;
    }

    protected function withdrawReceiptFor(int $deliveryId, string $sid, string $reason): void
    {
        PhotoDelivery::whereKey($deliveryId)
            ->where('provider_sid', $sid)
            ->whereNotNull('receipt_confirmed_at')
            ->update([
                'receipt_confirmed_at' => null,
                'receipt_confirmed_by' => null,
                'receipt_confirmed_via' => null,
            ]);

        SmsSenderVerification::where('photo_delivery_id', $deliveryId)
            ->where('provider_sid', $sid)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ]);
    }

    public function refreshAwaiting(Collection $deliveries, int $limit = 10, float $budgetSeconds = 6.0): Collection
    {
        $startedAt = microtime(true);
        $refreshed = [];
        $looked = 0;

        $candidates = $deliveries
            ->filter(fn (PhotoDelivery $d) => $d->isAwaitingCarrier() && $this->lookupIsDue($d))
            ->sortBy(fn (PhotoDelivery $d) => $d->provider_checked_at?->getTimestamp() ?? 0);

        foreach ($candidates as $delivery) {
            if ($looked >= $limit || microtime(true) - $startedAt > $budgetSeconds) {
                break;
            }

            $looked++;
            $refreshed[$delivery->id] = $this->refreshCarrierStatus($delivery);

            if ($this->lastLookupFailed) {
                break;
            }
        }

        return $deliveries->map(fn (PhotoDelivery $d) => $refreshed[$d->id] ?? $d);
    }

    public function settleUnansweredReceipts(?float $deadline = null, int $limit = 25): int
    {
        $sender = SmsService::senderNumber();

        if ($sender === null) {
            return 0;
        }

        $settled = 0;
        $windowEnd = now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS);

        $candidates = PhotoDelivery::where('channel', PhotoDelivery::CHANNEL_SMS)
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->whereNotNull('provider_sid')
            ->where('receipt_confirmed_via', PhotoDelivery::RECEIPT_VIA_STAFF)
            ->where('provider_from', $sender)
            ->where('sent_at', '<', $windowEnd)
            ->where('sent_at', '>=', $windowEnd->copy()->subDays(self::SETTLE_WINDOW_DAYS))
            ->where(function ($q) {
                $q->whereNull('provider_status')->orWhereNotIn('provider_status', SmsService::FINAL_STATUSES);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('sms_sender_verifications')
                    ->whereColumn('sms_sender_verifications.provider_sid', 'photo_deliveries.provider_sid');
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('sms_sender_verifications')
                    ->whereColumn('sms_sender_verifications.company_id', 'photo_deliveries.company_id')
                    ->where('sms_sender_verifications.method', SmsSenderVerification::METHOD_RESET)
                    ->whereColumn('sms_sender_verifications.revoked_at', '>=', 'photo_deliveries.sent_at');
            })
            ->orderByRaw('provider_checked_at IS NOT NULL, provider_checked_at ASC')
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get();

        $failuresInARow = 0;

        foreach ($candidates as $delivery) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }

            $sid = $delivery->provider_sid;
            $checked = $this->refreshCarrierStatus($delivery, true);

            if ($this->lastLookupFailed) {
                if (++$failuresInARow >= 3) {
                    break;
                }

                continue;
            }

            $failuresInARow = 0;

            if ($checked->provider_sid !== $sid
                || $checked->status !== PhotoDelivery::STATUS_SENT
                || $checked->receipt_confirmed_at === null
                || $checked->carrierSaysNotDelivered()) {
                continue;
            }

            if ($checked->carrierSaysDelivered()
                || $this->recordVerification($checked, SmsSenderVerification::METHOD_STAFF_CONFIRMED, $checked->receipt_confirmed_by)) {
                $settled += SmsSenderVerification::where('provider_sid', $sid)->whereNull('revoked_at')->exists() ? 1 : 0;
            }
        }

        return $settled;
    }

    public function confirmReceipt(PhotoDelivery $delivery, ?User $user, string $via = PhotoDelivery::RECEIPT_VIA_STAFF): array
    {
        $byStaff = $via === PhotoDelivery::RECEIPT_VIA_STAFF;
        $allowed = $byStaff ? $delivery->canConfirmReceipt() : $delivery->canRecordGuestReceipt();

        if (!$allowed) {
            return ['recorded' => false, 'verified' => false, 'delivery' => $delivery];
        }

        $written = PhotoDelivery::whereKey($delivery->id)
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->where('provider_sid', $delivery->provider_sid)
            ->when(!$byStaff, fn ($q) => $q->where('link_nonce', $delivery->link_nonce))
            ->whereNull('receipt_confirmed_at')
            ->where(function ($q) {
                $q->whereNull('provider_status')->orWhereNotIn('provider_status', SmsService::NOT_DELIVERED_STATUSES);
            })
            ->update([
                'receipt_confirmed_at' => now(),
                'receipt_confirmed_by' => $byStaff ? $user?->id : null,
                'receipt_confirmed_via' => $via,
                'updated_at' => now(),
            ]);

        $fresh = $delivery->fresh() ?? $delivery;

        if ($written !== 1) {
            return ['recorded' => false, 'verified' => false, 'delivery' => $fresh];
        }

        $verified = !$byStaff || $fresh->carrierSaysDelivered()
            ? $this->recordVerification(
                $fresh,
                $byStaff ? SmsSenderVerification::METHOD_STAFF_CONFIRMED : SmsSenderVerification::METHOD_GUEST_OPENED_LINK,
                $byStaff ? $user?->id : null
            )
            : false;

        ActivityLog::log(
            'photo_text_receipt_confirmed',
            'photos',
            sprintf(
                $byStaff
                    ? 'Confirmed the guest received the photo text for photo session #%d'
                    : 'A guest opened the photo link from the text for photo session #%d',
                $fresh->photo_session_id
            ),
            $byStaff ? $user?->id : null,
            $fresh->location_id,
            'photo_delivery',
            $fresh->id,
            ['via' => $via, 'confirmed_texting' => $verified]
        );

        return ['recorded' => true, 'verified' => $verified, 'delivery' => $fresh];
    }

    protected function recordVerification(PhotoDelivery $delivery, string $method, ?int $userId): bool
    {
        if (!$delivery->company_id || !$delivery->provider_sid || !$delivery->senderIsCurrent()) {
            return false;
        }

        $sender = SmsService::senderNumber();

        if ($delivery->carrierSaysNotDelivered()) {
            return false;
        }

        $lastReset = $this->latestResetFor((int) $delivery->company_id);

        if ($lastReset && $delivery->sent_at && $delivery->sent_at->lessThanOrEqualTo($lastReset)) {
            return false;
        }

        if (SmsSenderVerification::where('provider_sid', $delivery->provider_sid)->whereNull('revoked_at')->exists()) {
            return false;
        }

        $verification = SmsSenderVerification::create([
            'company_id' => $delivery->company_id,
            'location_id' => $delivery->location_id,
            'sender' => $sender,
            'photo_delivery_id' => $delivery->id,
            'provider_sid' => $delivery->provider_sid,
            'method' => $method,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        $current = PhotoDelivery::find($delivery->id);
        $resetSince = $delivery->sent_at !== null && SmsSenderVerification::where('company_id', $delivery->company_id)
            ->where('method', SmsSenderVerification::METHOD_RESET)
            ->where('revoked_at', '>=', $delivery->sent_at)
            ->exists();

        if (!$current
            || $current->provider_sid !== $delivery->provider_sid
            || $current->status !== PhotoDelivery::STATUS_SENT
            || $current->receipt_confirmed_at === null
            || $current->carrierSaysNotDelivered()
            || $resetSince) {
            $verification->forceFill([
                'revoked_at' => now(),
                'revoke_reason' => 'The text changed while this confirmation was being recorded.',
            ])->save();

            return false;
        }

        return true;
    }

    public function resetVerification(int $companyId, ?int $userId, string $reason, ?int $locationId = null): int
    {
        $marker = SmsSenderVerification::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'sender' => SmsService::senderNumber() ?? '',
            'method' => SmsSenderVerification::METHOD_RESET,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
            'revoked_at' => now(),
            'revoked_by' => $userId,
            'revoke_reason' => mb_substr($reason, 0, 255),
        ]);

        return SmsSenderVerification::where('company_id', $companyId)
            ->whereNull('revoked_at')
            ->whereKeyNot($marker->id)
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $userId,
                'revoke_reason' => mb_substr($reason, 0, 255),
            ]);
    }

    public function recordLinkOpen(PhotoSession $session, ?string $ref): void
    {
        $delivery = PhotoDelivery::fromLinkRef($session->id, $ref);

        if (!$delivery || $delivery->status !== PhotoDelivery::STATUS_SENT || $delivery->isDuplicate()) {
            return;
        }

        if ($delivery->opened_at === null) {
            PhotoDelivery::whereKey($delivery->id)->whereNull('opened_at')->update(['opened_at' => now()]);
            $delivery->opened_at = now();
        }

        if ($delivery->canRecordGuestReceipt()) {
            $this->confirmReceipt($delivery, null, PhotoDelivery::RECEIPT_VIA_LINK);
        } elseif ($delivery->receipt_confirmed_via === PhotoDelivery::RECEIPT_VIA_STAFF
            && $delivery->provider_sid !== null
            && !$delivery->carrierSaysNotDelivered()) {
            $this->upgradeStaffReceiptToLink($delivery);
        }
    }

    protected function upgradeStaffReceiptToLink(PhotoDelivery $delivery): void
    {
        $written = PhotoDelivery::whereKey($delivery->id)
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->where('provider_sid', $delivery->provider_sid)
            ->where('link_nonce', $delivery->link_nonce)
            ->where('receipt_confirmed_via', PhotoDelivery::RECEIPT_VIA_STAFF)
            ->where(function ($q) {
                $q->whereNull('provider_status')->orWhereNotIn('provider_status', SmsService::NOT_DELIVERED_STATUSES);
            })
            ->update([
                'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_LINK,
                'receipt_confirmed_by' => null,
                'updated_at' => now(),
            ]);

        if ($written !== 1) {
            return;
        }

        $fresh = $delivery->fresh() ?? $delivery;
        $verified = $this->recordVerification($fresh, SmsSenderVerification::METHOD_GUEST_OPENED_LINK, null);

        ActivityLog::log(
            'photo_text_receipt_confirmed',
            'photos',
            sprintf('A guest opened the photo link from the text for photo session #%d', $fresh->photo_session_id),
            null,
            $fresh->location_id,
            'photo_delivery',
            $fresh->id,
            ['via' => PhotoDelivery::RECEIPT_VIA_LINK, 'confirmed_texting' => $verified, 'after_staff_receipt' => true]
        );
    }

    public function summarizeOutcome(Collection $deliveries, ?int $companyId = null): array
    {
        $real = $deliveries->reject(fn (PhotoDelivery $d) => $d->isDuplicate())->values();
        $attempted = $real->reject(fn (PhotoDelivery $d) => $d->status === PhotoDelivery::STATUS_SKIPPED)->values();
        $outcomes = $attempted->map(fn (PhotoDelivery $d) => $d->outcome());

        $good = $outcomes->filter(fn ($o) => in_array($o, [
            PhotoDelivery::OUTCOME_SENT,
            PhotoDelivery::OUTCOME_DELIVERED,
            PhotoDelivery::OUTCOME_RECEIVED,
            PhotoDelivery::OUTCOME_AWAITING,
            PhotoDelivery::OUTCOME_UNCONFIRMED,
        ], true))->count();
        $bad = $outcomes->filter(fn ($o) => in_array($o, [PhotoDelivery::OUTCOME_FAILED, PhotoDelivery::OUTCOME_NOT_DELIVERED], true))->count();
        $awaiting = $outcomes->filter(fn ($o) => $o === PhotoDelivery::OUTCOME_AWAITING)->count();
        $pending = $attempted->filter(fn (PhotoDelivery $d) => $d->isAwaitingCarrier())->count();
        $scheduled = $outcomes->filter(fn ($o) => $o === PhotoDelivery::OUTCOME_SCHEDULED)->count();
        $texts = $attempted->filter(fn (PhotoDelivery $d) => $d->isText());
        $textReached = $texts->filter(fn (PhotoDelivery $d) => $d->reachedGuest())->count();
        $failureAdvice = $this->failureAdvice($attempted);

        if ($attempted->isEmpty()) {
            $reason = $real->pluck('error')->filter()->first();

            return $this->outcomeSummary('failed', 'Nothing was sent', $reason ?: 'None of the selected guests could be contacted.', 0, $deliveries, $companyId);
        }

        if ($scheduled === $attempted->count()) {
            return $this->outcomeSummary('scheduled', 'Scheduled for 9:00 AM tomorrow', 'Nothing goes out until 9:00 AM tomorrow in this location\'s time zone. The delivery log shows how it went after that.', 0, $deliveries, $companyId);
        }

        if ($bad === 0) {
            $message = $awaiting > 0
                ? 'Everything was accepted for delivery. This screen updates when the phone carrier confirms the text reached the phone.'
                : ($texts->isNotEmpty() && $textReached === $texts->count()
                    ? 'Everything went out, and the text reached the phone.'
                    : ($texts->isEmpty()
                        ? 'The email was handed to the email service.'
                        : 'Everything went out. The phone carrier has not confirmed whether the text arrived.'));

            if ($awaiting === 0 && $pending > 0) {
                $message = 'Recorded as received. The phone carrier has not confirmed delivery yet, so this screen keeps checking.';
            }

            return $this->outcomeSummary('sent', $awaiting > 0 ? 'Sent, waiting for the carrier' : 'Photos sent', $message, $pending, $deliveries, $companyId);
        }

        if ($good === 0) {
            return $this->outcomeSummary('failed', 'The photos could not be sent', 'Nothing reached the guest. ' . $failureAdvice, $pending, $deliveries, $companyId);
        }

        return $this->outcomeSummary(
            'partial',
            'Sent, but not by every method',
            sprintf('%d of %d messages went out. The one%s marked in red did not. ', $good, $good + $bad, $bad === 1 ? '' : 's') . $failureAdvice,
            $pending,
            $deliveries,
            $companyId
        );
    }

    protected function failureAdvice(Collection $attempted): string
    {
        $bad = $attempted->filter(fn (PhotoDelivery $d) => in_array($d->outcome(), [PhotoDelivery::OUTCOME_FAILED, PhotoDelivery::OUTCOME_NOT_DELIVERED], true));
        $blocked = $bad->filter(fn (PhotoDelivery $d) => $this->blockedNow($d));
        $retryable = $bad->filter(fn (PhotoDelivery $d) => $d->canRetry()
            && $this->retryWouldHelp($d)
            && !($blocked->isNotEmpty() && $d->isText()));

        if ($blocked->isNotEmpty()) {
            return (in_array((string) $this->senderBlockCode(), SmsService::CARRIER_BLOCK_CODES, true)
                    ? 'The phone carriers are blocking texts from this number, so sending the text again will not help.'
                    : 'The texting account or number is not set up correctly, so no text can go out until an administrator fixes it.')
                . ($retryable->isNotEmpty() ? ' Retry can still send the email again.' : '');
        }

        return $retryable->isNotEmpty()
            ? 'Each message below says why, and Retry sends it again.'
            : 'Each message below says why. Sending it again to the same number or address will not help.';
    }

    protected function outcomeSummary(string $result, string $headline, string $message, int $carrierPending, Collection $deliveries, ?int $companyId): array
    {
        $texting = $this->smsAvailable() ? $this->textingStatus($companyId, false) : null;
        $confirmableRows = $deliveries->filter(fn (PhotoDelivery $d) => $d->canConfirmReceipt());
        $confirmable = $confirmableRows->pluck('id')->values()->all();
        $canConfirmTexting = $confirmableRows->contains(fn (PhotoDelivery $d) => $this->receiptCanVerify($d));

        return [
            'result' => $result,
            'headline' => $headline,
            'message' => $message,
            'texts_blocked' => $deliveries->contains(fn (PhotoDelivery $d) => $this->blockedNow($d)
                && in_array($d->outcome(), [PhotoDelivery::OUTCOME_FAILED, PhotoDelivery::OUTCOME_NOT_DELIVERED], true)),
            'carrier_pending' => $carrierPending,
            'delivery_ids' => $deliveries->pluck('id')->values()->all(),
            'confirmable_ids' => $confirmable,
            'texting_state' => $texting['state'] ?? 'not_configured',
            'ask_for_receipt' => $canConfirmTexting && in_array($texting['state'] ?? null, ['unverified', 'failing'], true),
        ];
    }

    public function receiptCanVerify(PhotoDelivery $delivery): bool
    {
        if (!$delivery->company_id || !$delivery->senderIsCurrent()) {
            return false;
        }

        $lastReset = $this->latestResetFor((int) $delivery->company_id);

        return $lastReset === null || ($delivery->sent_at !== null && $delivery->sent_at->greaterThan($lastReset));
    }

    protected function latestResetFor(int $companyId): ?\Illuminate\Support\Carbon
    {
        $at = SmsSenderVerification::where('company_id', $companyId)
            ->where('method', SmsSenderVerification::METHOD_RESET)
            ->max('revoked_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    public function photoLinkBase(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    /**
     * Every photo message carries a link built from FRONTEND_URL. Left at its development
     * default in a live environment, messages send successfully and still reach nobody,
     * because the address only resolves on the machine that sent it.
     */
    public function photoLinkNote(): ?string
    {
        $base = $this->photoLinkBase();
        $isLocalAddress = (bool) preg_match('/^https?:\/\/(localhost|127\.0\.0\.1|0\.0\.0\.0)/i', $base);

        if ($base === '') {
            return 'No public web address is set for this site, so photo links cannot be built. Your administrator needs to set the address visitors use.';
        }

        if ($isLocalAddress && app()->environment() !== 'local') {
            return 'Photo links currently point at ' . $base . ', which only opens on the server itself, so visitors would not be able to reach them. Your administrator needs to set the address visitors use.';
        }

        return null;
    }

    /**
     * Send a sample message on one channel so staff can confirm the channel works and see
     * the provider's own words when it does not.
     */
    public function sendTest(Location $location, string $channel, string $destination, string $kind = PhotoMessageTemplate::KIND_IMMEDIATE): array
    {
        if ($kind === PhotoMessageTemplate::KIND_ESCAPE_ROOM) {
            return [
                'success' => false,
                'message' => 'Escape-room games now send the Thanks for Playing email. Edit it and send a test from Email Notifications.',
            ];
        }

        $location->loadMissing('company');
        $template = PhotoMessageTemplate::forCompany($location->company_id, $kind);
        $tz = OperatingDay::timezoneFor($location);

        $variables = [
            'first_name' => 'there',
            'location_name' => $location->name ?? 'Zap Zone',
            'photo_date' => now($tz)->format('M j, Y'),
            'photo_link' => $this->photoLinkBase() . '/photos/' . str_pad('sample-link-', 48, 'x')
                . '?r=' . base_convert((string) ((int) PhotoDelivery::max('id') + 1), 10, 36) . '-00000000',
            'expires_on' => now($tz)->addDays(30)->format('M j, Y'),
            'business_name' => $location->company?->company_name ?? 'Zap Zone',
            'support_contact' => (string) config('photos.support_contact'),
            'photo_count' => '2',
            'room_name' => 'The Vault',
            'session_date' => now($tz)->format('M j, Y'),
            'session_time' => '3:00 PM',
            'completion_time' => '47:12',
            'escape_result' => 'Your group escaped in 47:12!',
            'photos_line' => 'Your group photos are attached (real emails include them). You can also view and download them here:',
            'location_phone' => (string) ($location->phone ?? ''),
            'event_name' => 'Trunk or Treat',
            'event_date' => now($tz)->format('M j, Y'),
        ] + $this->linkVariables($location->company_id, $location);

        $bodyVariables = array_map(fn ($value) => e((string) $value), $variables);
        $bodyVariables['links'] = $this->linksHtml($location->company_id, $location);

        try {
            if ($channel === PhotoDelivery::CHANNEL_EMAIL) {
                if (!$this->validEmail($destination)) {
                    return ['success' => false, 'message' => 'Please enter a valid email address.'];
                }
                if (!$this->emailAvailable()) {
                    return ['success' => false, 'message' => 'Email is not switched on yet, so nothing would actually be delivered. Ask your administrator to enable it, then try again.'];
                }

                $this->sendEmail(
                    $destination,
                    '[Test] ' . $template->render('email_subject', $variables),
                    $this->wrapHtml($template->render('email_body', $bodyVariables)),
                    $variables['business_name']
                );

                return [
                    'success' => true,
                    'message' => 'Test email sent to ' . $destination . '. If it does not arrive, check the junk folder before reporting a problem.',
                ];
            }

            if (!$this->validPhone($destination)) {
                return ['success' => false, 'message' => 'Please enter a valid mobile number.'];
            }
            if (!$this->smsAvailable()) {
                return ['success' => false, 'message' => 'Text messaging is not switched on yet. Ask your administrator to enable it, then try again.'];
            }

            $body = trim($template->render('sms_body', $variables));

            if ($body === '') {
                return ['success' => false, 'message' => 'The text message wording is empty. Add it under Message wording below.'];
            }

            $sent = $this->sms->sendMessage($this->normalizePhone($destination), '[Test] ' . $body);
            $size = SmsService::segmentInfo($body);

            return [
                'success' => true,
                'message' => 'The text provider accepted the test for ' . $sent['to'] . '. Checking whether the phone carrier delivers it…',
                'provider_reference' => $sent['sid'],
                'provider_status' => $sent['status'],
                'characters' => $size['characters'],
                'segments' => $size['segments'],
                'encoding' => $size['encoding'],
            ];
        } catch (\Throwable $e) {
            Log::warning('Photo channel test failed', [
                'location_id' => $location->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'The message could not be sent. ' . SmsService::describeFailure($e),
            ];
        }
    }

    /**
     * Channels present on the waiver record, regardless of what this site can send.
     */
    public function contactChannelsOnRecord(Waiver|RegistrationEntry $record): array
    {
        $channels = [];

        if ($this->validEmail($this->recordEmail($record))) {
            $channels[] = PhotoDelivery::CHANNEL_EMAIL;
        }
        if ($this->validPhone($this->recordPhone($record))) {
            $channels[] = PhotoDelivery::CHANNEL_SMS;
        }

        return $channels;
    }

    /**
     * Channels that can actually carry a photo link right now: present on the
     * waiver AND supported by this site's configuration.
     */
    public function contactableChannels(Waiver|RegistrationEntry $record, bool $allowText = true): array
    {
        return array_values(array_filter(
            $this->contactChannelsOnRecord($record),
            fn ($channel) => $channel === PhotoDelivery::CHANNEL_EMAIL
                ? $this->emailAvailable()
                : $allowText && $this->smsAvailable()
        ));
    }

    public function unavailableReason(Waiver|RegistrationEntry $record, bool $allowText = true): ?string
    {
        $onRecord = $this->contactChannelsOnRecord($record);

        if ($this->contactableChannels($record, $allowText) !== []) {
            return null;
        }

        $noun = $record instanceof RegistrationEntry ? 'registration' : 'waiver';
        $textWithheld = $record instanceof RegistrationEntry && !$allowText && $this->smsAvailable();

        if ($onRecord === []) {
            // Something may well be written in these fields; say so rather than claim the
            // waiver is blank, because staff can then go and correct what is there.
            $hasSomething = trim((string) $this->recordEmail($record)) !== '' || trim((string) $this->recordPhone($record)) !== '';

            return $hasSomething
                ? "This {$noun} has no email address or mobile number that can be used. Please check the contact details on the record."
                : "This {$noun} has no email address or mobile number.";
        }
        if ($onRecord === [PhotoDelivery::CHANNEL_SMS]) {
            return $textWithheld
                ? 'This registration only has a mobile number. Event registrations are only texted when the guest asked for a text.'
                : "This {$noun} only has a mobile number, and text messaging is not switched on yet.";
        }
        if ($onRecord === [PhotoDelivery::CHANNEL_EMAIL]) {
            return "This {$noun} only has an email address, and email is not switched on yet.";
        }

        return $textWithheld
            ? 'Email is not switched on yet, and event registrations are only texted when the guest asked for a text.'
            : 'Neither email nor text messaging is switched on yet, so nothing can be sent.';
    }

    public function queueWaiverDeliveries(PhotoSession $session, Collection $waivers, string $schedule, ?int $userId = null): array
    {
        return $this->queueDeliveries($session, $waivers, collect(), $schedule, $userId);
    }

    public function queueDeliveries(
        PhotoSession $session,
        Collection $waivers,
        Collection $entries,
        string $schedule,
        ?int $userId = null,
        bool $textRegistrants = false
    ): array {
        $session->loadMissing('location');
        $location = $session->location;

        $kind = $schedule === PhotoSession::SCHEDULE_NEXT_DAY
            ? PhotoDelivery::KIND_NEXT_DAY
            : PhotoDelivery::KIND_IMMEDIATE;

        $scheduledFor = $kind === PhotoDelivery::KIND_NEXT_DAY
            ? OperatingDay::nextNineAm($location)
            : null;

        $linksEntries = PhotoSession::supportsRegistrations();
        $seen = [];
        $created = [];
        $rows = [];
        $skippedWaiverIds = [];
        $skippedEntryIds = [];

        $recipients = $waivers->toBase()
            ->map(fn (Waiver $waiver) => ['record' => $waiver, 'allow_text' => true])
            ->concat($entries->toBase()->map(fn (RegistrationEntry $entry) => ['record' => $entry, 'allow_text' => $textRegistrants]));

        foreach ($recipients as ['record' => $record, 'allow_text' => $allowText]) {
            $isEntry = $record instanceof RegistrationEntry;
            $channels = $this->contactableChannels($record, $allowText);
            $recipientName = $this->recordName($record);
            $link = $isEntry
                ? array_merge(['waiver_id' => null], $linksEntries ? ['registration_entry_id' => $record->id] : [])
                : ['waiver_id' => $record->id];

            // A channel present on the waiver that this site cannot send is recorded so the
            // delivery log explains the gap, rather than silently dropping the contact.
            foreach (array_diff($this->contactChannelsOnRecord($record), $channels) as $unsupported) {
                $rows[] = PhotoDelivery::create(array_merge($link, [
                    'photo_session_id' => $session->id,
                    'company_id' => $session->company_id,
                    'location_id' => $session->location_id,
                    'kind' => $kind,
                    'channel' => $unsupported,
                    'destination' => $unsupported === PhotoDelivery::CHANNEL_EMAIL
                        ? strtolower(trim((string) $this->recordEmail($record)))
                        : $this->normalizePhone($this->recordPhone($record)),
                    'recipient_name' => $recipientName,
                    'status' => PhotoDelivery::STATUS_SKIPPED,
                    'error' => $unsupported === PhotoDelivery::CHANNEL_SMS
                        ? ($isEntry && !$allowText && $this->smsAvailable()
                            ? 'Not texted: event registrations get email unless staff confirm the guest asked for a text.'
                            : 'Text messaging is not switched on yet.')
                        : 'Email is not switched on yet.',
                    'created_by' => $userId,
                ]));
            }

            if ($channels === []) {
                if ($isEntry) {
                    $skippedEntryIds[] = $record->id;
                } else {
                    $skippedWaiverIds[] = $record->id;
                }
                continue;
            }

            foreach ($channels as $channel) {
                $destination = $channel === PhotoDelivery::CHANNEL_EMAIL
                    ? strtolower(trim((string) $this->recordEmail($record)))
                    : $this->normalizePhone($this->recordPhone($record));

                $key = $channel . '|' . $destination;
                $primary = $seen[$key] ?? null;

                $delivery = PhotoDelivery::create(array_merge($link, [
                    'photo_session_id' => $session->id,
                    'company_id' => $session->company_id,
                    'location_id' => $session->location_id,
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
                ]));
                $rows[] = $delivery;

                if (!$primary) {
                    $seen[$key] = $delivery;
                    $created[] = $delivery;
                }
            }
        }

        if ($kind === PhotoDelivery::KIND_IMMEDIATE) {
            if (count($created) > 1 && function_exists('set_time_limit')) {
                @set_time_limit(180);
            }

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
            'skipped_registration_entry_ids' => $skippedEntryIds,
            'deliveries' => PhotoDelivery::whereIn('id', collect($rows)->pluck('id'))->orderBy('id')->get(),
        ];
    }

    protected function recordEmail(Waiver|RegistrationEntry $record): ?string
    {
        return $record instanceof RegistrationEntry ? $record->email : $record->adult_email;
    }

    protected function recordPhone(Waiver|RegistrationEntry $record): ?string
    {
        return $record instanceof RegistrationEntry ? $record->phone : $record->adult_phone;
    }

    protected function recordName(Waiver|RegistrationEntry $record): string
    {
        return $record instanceof RegistrationEntry
            ? trim((string) $record->name)
            : trim(($record->adult_first_name ?? '') . ' ' . ($record->adult_last_name ?? ''));
    }

    public function createEscapeRoomDeliveries(PhotoSession $session, Collection $waivers, ?int $userId = null): array
    {
        $created = [];
        $duplicates = 0;
        $skippedWaiverIds = [];

        $seen = PhotoDelivery::where('photo_session_id', $session->id)
            ->where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)
            ->where('channel', PhotoDelivery::CHANNEL_EMAIL)
            ->whereNull('duplicate_of_id')
            ->where('status', '!=', PhotoDelivery::STATUS_CANCELED)
            ->get()
            ->keyBy('destination')
            ->all();

        foreach ($waivers as $waiver) {
            if (!$this->validEmail($waiver->adult_email)) {
                $skippedWaiverIds[] = $waiver->id;
                continue;
            }

            $destination = strtolower(trim((string) $waiver->adult_email));
            $primary = $seen[$destination] ?? null;

            $delivery = PhotoDelivery::create([
                'photo_session_id' => $session->id,
                'company_id' => $session->company_id,
                'location_id' => $session->location_id,
                'waiver_id' => $waiver->id,
                'duplicate_of_id' => $primary?->id,
                'kind' => PhotoDelivery::KIND_ESCAPE_ROOM,
                'channel' => PhotoDelivery::CHANNEL_EMAIL,
                'destination' => $destination,
                'recipient_name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
                'status' => $primary ? PhotoDelivery::STATUS_SKIPPED : PhotoDelivery::STATUS_QUEUED,
                'created_by' => $userId,
            ]);

            if ($primary) {
                $duplicates++;
                continue;
            }

            $seen[$destination] = $delivery;
            $created[] = $delivery;
        }

        return [
            'deliveries' => $created,
            'duplicates' => $duplicates,
            'skipped_waiver_ids' => $skippedWaiverIds,
        ];
    }

    public function sendEscapeRoomDeliveries(PhotoSession $session, array $deliveries): int
    {
        $sent = 0;

        foreach ($deliveries as $delivery) {
            $claimed = $this->claimForSending($delivery);

            if ($claimed && $this->send($claimed)) {
                $sent++;
            }
        }

        $session->forceFill([
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_WAIVER_MESSAGE,
            'delivery_schedule' => PhotoSession::SCHEDULE_IMMEDIATE,
            'delivered_at' => $session->delivered_at ?? now(),
        ])->save();

        return $sent;
    }

    public function claimForSending(PhotoDelivery $delivery, string $expectedStatus = PhotoDelivery::STATUS_QUEUED): ?PhotoDelivery
    {
        $seen = $delivery->getRawOriginal('updated_at');
        $now = now()->format('Y-m-d H:i:s');

        $claimed = $seen === null
            ? \Illuminate\Support\Facades\DB::update(
                'UPDATE photo_deliveries SET updated_at = ? WHERE id = ? AND status = ? AND updated_at IS NULL',
                [$now, $delivery->id, $expectedStatus]
            )
            : \Illuminate\Support\Facades\DB::update(
                'UPDATE photo_deliveries SET updated_at = IF(updated_at >= ?, DATE_ADD(updated_at, INTERVAL 1 SECOND), ?) WHERE id = ? AND status = ? AND updated_at = ?',
                [$now, $now, $delivery->id, $expectedStatus, $seen]
            );

        if ($claimed !== 1) {
            return null;
        }

        $fresh = $delivery->fresh();
        $fresh?->setRelations($delivery->getRelations());

        return $fresh;
    }

    public function queueKioskDeliveries(PhotoSession $session): array
    {
        $created = [];

        if ($this->validEmail($session->kiosk_contact_email)) {
            $emailReady = $this->emailAvailable();
            $created[] = PhotoDelivery::create([
                'photo_session_id' => $session->id,
                'company_id' => $session->company_id,
                'location_id' => $session->location_id,
                'kind' => PhotoDelivery::KIND_KIOSK,
                'channel' => PhotoDelivery::CHANNEL_EMAIL,
                'destination' => strtolower(trim((string) $session->kiosk_contact_email)),
                'recipient_name' => $session->kiosk_contact_name,
                'status' => $emailReady ? PhotoDelivery::STATUS_QUEUED : PhotoDelivery::STATUS_SKIPPED,
                'error' => $emailReady ? null : 'Email is not switched on yet.',
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
                'error' => $smsReady ? null : 'Text messaging is not switched on yet.',
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

        if ($this->registrationWithdrawn($delivery)) {
            $delivery->update([
                'status' => PhotoDelivery::STATUS_CANCELED,
                'error' => 'This registration was hidden or removed before the photo was sent.',
            ]);

            return false;
        }

        $isEscapeRoom = $delivery->kind === PhotoDelivery::KIND_ESCAPE_ROOM;
        $emailLog = null;

        $delivery->forceFill(['link_nonce' => strtolower(\Illuminate\Support\Str::random(12))])->saveQuietly();

        if ($delivery->channel === PhotoDelivery::CHANNEL_SMS) {
            $this->retirePreviousText($delivery);
        }

        try {
            if ($isEscapeRoom && $delivery->channel === PhotoDelivery::CHANNEL_EMAIL) {
                $followUps = app(VisitFollowUpService::class);
                $built = $followUps->gamePhotoEmail($session, $delivery);
                $emailLog = $followUps->openLog($built['notification'], $delivery->destination, $session->linkedEscapeRoomSession());

                $this->sendEmail(
                    $delivery->destination,
                    $built['subject'],
                    $built['html'],
                    $built['from_name'],
                    $built['attachments']
                );

                $emailLog?->markAsSent();
            } elseif ($delivery->channel === PhotoDelivery::CHANNEL_EMAIL) {
                $template = $this->templateFor($session, $delivery);
                $variables = $this->variables($session, $delivery);
                $bodyVariables = array_map(fn ($value) => e((string) $value), $variables);
                $bodyVariables['links'] = $this->linksHtml($session->company_id, $session->location);

                $this->sendEmail(
                    $delivery->destination,
                    $template->render('email_subject', $variables),
                    $this->wrapHtml($template->render('email_body', $bodyVariables)),
                    $variables['business_name'],
                    $template->attach_photos ? $this->photoAttachments($session, $variables['event_name'] ?: 'photo') : []
                );
            } else {
                $template = $this->templateFor($session, $delivery);
                $variables = $this->variables($session, $delivery);

                $body = trim($template->render('sms_body', $variables));

                if ($body === '') {
                    throw new \RuntimeException(
                        'The text message wording is empty. Add it under Photos, Settings, Message wording.'
                    );
                }

                $textSent = $this->sms->sendMessage($delivery->destination, $body);
            }

            $this->writeSendResult($delivery, array_merge([
                'status' => PhotoDelivery::STATUS_SENT,
                'sent_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'error' => null,
                'opened_at' => null,
            ], isset($textSent) ? [
                'provider_sid' => $textSent['sid'],
                'provider_from' => $textSent['from'],
                'provider_status' => $textSent['status'],
                'provider_error_code' => null,
                'provider_checked_at' => null,
                'delivered_at' => null,
                'receipt_confirmed_at' => null,
                'receipt_confirmed_by' => null,
                'receipt_confirmed_via' => null,
            ] : []));

            return true;
        } catch (\Throwable $e) {
            $emailLog?->markAsFailed(mb_substr($e->getMessage(), 0, 1000));

            $isText = $delivery->channel === PhotoDelivery::CHANNEL_SMS;

            $this->writeSendResult($delivery, array_merge([
                'status' => PhotoDelivery::STATUS_FAILED,
                'attempts' => $delivery->attempts + 1,
                'error' => mb_substr($isText ? SmsService::describeFailure($e) : $e->getMessage(), 0, 1000),
                'opened_at' => null,
            ], $isText ? [
                'provider_sid' => null,
                'provider_from' => SmsService::senderNumber(),
                'provider_status' => null,
                'provider_error_code' => SmsService::failureCode($e),
                'provider_checked_at' => null,
                'delivered_at' => null,
                'receipt_confirmed_at' => null,
                'receipt_confirmed_by' => null,
                'receipt_confirmed_via' => null,
            ] : []));

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

    protected function writeSendResult(PhotoDelivery $delivery, array $attributes): void
    {
        $attributes['updated_at'] = now();
        $this->senderBlockedMemo = [];

        PhotoDelivery::whereKey($delivery->id)->update($attributes);

        $delivery->forceFill($attributes)->syncOriginal();
    }

    protected function retirePreviousText(PhotoDelivery $delivery): void
    {
        $previous = PhotoDelivery::find($delivery->id);

        if (!$previous || $previous->provider_sid === null || $previous->carrierSaysDelivered()) {
            return;
        }

        $verified = SmsSenderVerification::where('photo_delivery_id', $delivery->id)
            ->where('provider_sid', $previous->provider_sid)
            ->whereNull('revoked_at')
            ->exists();

        if (!$verified) {
            return;
        }

        $checked = $this->refreshCarrierStatus($previous, true);

        if (!$this->lastLookupFailed && $checked->provider_sid === $previous->provider_sid && $checked->carrierSaysDelivered()) {
            return;
        }

        SmsSenderVerification::where('photo_delivery_id', $delivery->id)
            ->where('provider_sid', $previous->provider_sid)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => 'The photo was sent again before the phone carrier confirmed the earlier text.',
            ]);
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
                $location->company?->company_name ?? 'Zap Zone'
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
        $firstName = $delivery?->registration_entry_id ? '' : trim(explode(' ', trim((string) $name))[0] ?? '');

        return [
            'first_name' => $firstName !== '' ? $firstName : 'there',
            'location_name' => $location?->name ?? 'Zap Zone',
            'photo_date' => ($session->captured_at ?: $session->created_at)->copy()->setTimezone($tz)->format('M j, Y'),
            'photo_link' => $this->photoLink($session, $delivery),
            'expires_on' => $session->access_expires_at?->copy()->setTimezone($tz)->format('M j, Y') ?? '',
            'business_name' => $location?->company?->company_name ?? 'Zap Zone',
            'support_contact' => (string) config('photos.support_contact'),
            'photo_count' => (string) $session->photos()->ready()->count(),
        ] + $this->eventVariables($delivery, $tz) + $this->escapeRoomVariables($session, $tz) + $this->linkVariables($session->company_id, $location);
    }

    protected function eventVariables(?PhotoDelivery $delivery, string $tz): array
    {
        $entryId = $delivery ? $this->effectiveEntryId($delivery) : null;
        $form = $entryId !== null
            ? RegistrationEntry::query()->with('event.location')->find($entryId)?->event
            : null;

        if ($form === null) {
            return ['event_name' => '', 'event_date' => ''];
        }

        $first = \Illuminate\Support\Carbon::parse($form->firstDay());
        $last = $form->event_date;

        return [
            'event_name' => (string) $form->name,
            'event_date' => match (true) {
                $first->toDateString() === $last->toDateString() => $last->format('M j, Y'),
                $first->year === $last->year => $first->format('M j') . ' to ' . $last->format('M j, Y'),
                default => $first->format('M j, Y') . ' to ' . $last->format('M j, Y'),
            },
            'location_phone' => (string) ($form->location?->phone ?? ''),
        ];
    }

    protected function effectiveEntryId(PhotoDelivery $delivery): ?int
    {
        if (!PhotoSession::supportsRegistrations()) {
            return null;
        }

        if ($delivery->registration_entry_id) {
            return (int) $delivery->registration_entry_id;
        }

        if (!$delivery->exists) {
            return null;
        }

        $candidates = PhotoDelivery::query()
            ->where('duplicate_of_id', $delivery->id)
            ->whereNotNull('registration_entry_id')
            ->orderBy('id')
            ->pluck('registration_entry_id');

        foreach ($candidates as $candidate) {
            $entry = RegistrationEntry::query()->find($candidate);

            if ($entry && $entry->hidden_at === null && RegistrationEvent::query()->whereKey($entry->registration_event_id)->exists()) {
                return (int) $entry->id;
            }
        }

        return null;
    }

    protected array $resolvedLinks = [];

    protected function resolvedLinks(?int $companyId, ?Location $location): \Illuminate\Support\Collection
    {
        $key = ($companyId ?? 0) . ':' . ($location?->id ?? 0);

        return $this->resolvedLinks[$key] ??= MessageLink::resolvedFor($companyId, $location);
    }

    public function linkVariables(?int $companyId, ?Location $location): array
    {
        $links = $this->resolvedLinks($companyId, $location);

        $lines = [];
        $length = 0;

        foreach ($links->filter(fn (array $link) => $link['url'] !== null) as $link) {
            $line = $link['label'] . ': ' . $link['url'];

            if ($length + mb_strlen($line) + 1 > self::LINKS_TEXT_LIMIT) {
                continue;
            }

            $lines[] = $line;
            $length += mb_strlen($line) + 1;
        }

        return ['links' => implode("\n", $lines)]
            + $links->mapWithKeys(fn (array $link) => ['link_' . $link['key'] => (string) $link['url']])->all();
    }

    public function linksHtml(?int $companyId, ?Location $location): string
    {
        return $this->resolvedLinks($companyId, $location)
            ->filter(fn (array $link) => $link['url'] !== null)
            ->map(fn (array $link) => '<a href="' . e($link['url']) . '">' . e($link['label']) . '</a>')
            ->implode(' &middot; ');
    }

    protected function templateFor(PhotoSession $session, PhotoDelivery $delivery): PhotoMessageTemplate
    {
        if ($delivery->kind !== PhotoDelivery::KIND_ESCAPE_ROOM && $this->effectiveEntryId($delivery) !== null) {
            $event = PhotoMessageTemplate::forCompany($session->company_id, PhotoMessageTemplate::KIND_EVENT_REGISTRATION);

            if ($event->is_active) {
                return $event;
            }
        }

        return PhotoMessageTemplate::forCompany($session->company_id, $this->templateKind($delivery->kind));
    }

    protected function escapeRoomVariables(PhotoSession $session, string $tz): array
    {
        $blank = array_fill_keys(PhotoMessageTemplate::ESCAPE_ROOM_VARIABLES, '');
        $escape = $session->linkedEscapeRoomSession();

        if (!$escape) {
            return $blank;
        }

        $escape->loadMissing('package');
        $time = \Illuminate\Support\Carbon::createFromFormat('H:i', $escape->timeKey(), $tz);

        $photoCount = $session->photos()->ready()->count();

        return [
            'room_name' => $escape->package?->name ?? '',
            'session_date' => $escape->session_date?->format('M j, Y') ?? '',
            'session_time' => $time ? $time->format('g:i A') : $escape->timeKey(),
            'completion_time' => $escape->completionLabel(),
            'escape_result' => $escape->resultLabel(),
            'photos_line' => $photoCount > 1
                ? "Your {$photoCount} group photos are attached. You can also view and download them here:"
                : 'Your group photo is attached. You can also view and download it here:',
            'location_phone' => (string) ($session->location?->phone ?? ''),
        ];
    }

    protected ?string $attachmentCacheKey = null;

    protected array $attachmentCache = [];

    public function photoAttachments(PhotoSession $session, string $roomName): array
    {
        $cacheKey = $session->id . '|' . $roomName;

        if ($this->attachmentCacheKey !== $cacheKey) {
            $this->attachmentCache = $this->readPhotoAttachments($session, $roomName);
            $this->attachmentCacheKey = $cacheKey;
        }

        return $this->attachmentCache;
    }

    protected function readPhotoAttachments(PhotoSession $session, string $roomName): array
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(PhotoProcessingService::DISK);
        $slug = \Illuminate\Support\Str::slug($roomName) ?: 'group';
        $attachments = [];

        foreach ($session->photos()->ready()->orderBy('position')->get() as $index => $photo) {
            $path = $photo->pathForVariant('delivery');

            if (!$path || !$disk->exists($path)) {
                continue;
            }

            $attachments[] = [
                'data' => base64_encode($disk->get($path)),
                'filename' => $slug . '-photo-' . ($index + 1) . '.jpg',
                'mime_type' => 'image/jpeg',
            ];
        }

        return $attachments;
    }

    public function photoLink(PhotoSession $session, ?PhotoDelivery $delivery = null): string
    {
        $link = rtrim((string) config('app.frontend_url'), '/') . '/photos/' . $session->access_token;

        return $delivery && $delivery->exists ? $link . '?r=' . $delivery->linkRef() : $link;
    }

    protected function templateKind(string $deliveryKind): string
    {
        return match ($deliveryKind) {
            PhotoDelivery::KIND_NEXT_DAY => PhotoMessageTemplate::KIND_NEXT_DAY,
            PhotoDelivery::KIND_KIOSK => PhotoMessageTemplate::KIND_KIOSK,
            PhotoDelivery::KIND_ESCAPE_ROOM => PhotoMessageTemplate::KIND_ESCAPE_ROOM,
            default => PhotoMessageTemplate::KIND_IMMEDIATE,
        };
    }

    protected function sendEmail(string $to, string $subject, string $html, ?string $fromName = null, array $attachments = []): void
    {
        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            try {
                (new GmailApiService())->sendEmail($to, $subject, $html, $fromName ?: 'Zap Zone', $attachments);

                return;
            } catch (\Throwable $e) {
                Log::warning('Gmail API send failed for a photo link; falling back to the mail transport.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Mail::html($html, function ($message) use ($to, $subject, $fromName, $attachments) {
            $message->to($to)
                ->subject($subject)
                ->from(config('mail.from.address'), $fromName ?: config('mail.from.name'));

            foreach ($attachments as $attachment) {
                if (isset($attachment['content_id'])) {
                    $message->getSymfonyMessage()->addPart(
                        (new \Symfony\Component\Mime\Part\DataPart(
                            base64_decode($attachment['data']),
                            $attachment['filename'],
                            $attachment['mime_type']
                        ))->asInline()->setContentId($attachment['content_id'])
                    );

                    continue;
                }

                $message->attachData(
                    base64_decode($attachment['data']),
                    $attachment['filename'],
                    ['mime' => $attachment['mime_type']]
                );
            }
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

    protected function registrationWithdrawn(PhotoDelivery $delivery): bool
    {
        if (!$delivery->registration_entry_id || !PhotoSession::supportsRegistrations()) {
            return false;
        }

        $entry = RegistrationEntry::query()->find($delivery->registration_entry_id);

        return $entry === null
            || $entry->hidden_at !== null
            || !RegistrationEvent::query()->whereKey($entry->registration_event_id)->exists();
    }

    public function validEmail(?string $value): bool
    {
        return $value !== null
            && trim($value) !== ''
            && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * A number is only offered as an SMS destination when it can be texted, so the
     * delivery log never shows a queued row for an address Twilio would refuse.
     */
    public function validPhone(?string $value): bool
    {
        return SmsService::toE164($value) !== null;
    }

    public function normalizePhone(?string $value): string
    {
        return SmsService::toE164($value) ?? trim((string) $value);
    }
}
