<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailNotification;
use App\Models\EmailNotificationLog;
use App\Models\EscapeRoomSession;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\FollowUpOptOut;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\Promo;
use App\Models\User;
use App\Models\VisitFollowUp;
use App\Models\Waiver;
use App\Support\CompletedVisit;
use App\Support\OperatingDay;
use App\Support\SchemaSupport;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitFollowUpService
{
    public const SEND_WINDOW_START_HOUR = 9;

    public const SEND_WINDOW_END_HOUR = 20;

    public const REVIEW_REPEAT_DAYS = 30;

    public const STUCK_SENDING_MINUTES = 15;

    public const MAX_REVIEW_DELAY_HOURS = 720;

    public const MAX_VISIT_AGE_DAYS = 3;

    public const MAX_COMMENT_LENGTH = 2000;

    public const LOW_RATING = 2;

    public const PROMO_ALERT_DAYS = 7;

    public const OPT_OUT_PREFIX = 'u.';

    public const REOPENED_REASON = 'The visit was taken out of Completed before this email went out.';

    public const TEXT_VARIABLES = [
        'customer_name',
        'customer_first_name',
        'customer_email',
        'first_name',
        'activity_name',
        'room_name',
        'visit_date',
        'visit_time',
        'visit_when',
        'booking_reference',
        'completion_time',
        'escape_result',
        'photo_link',
        'photos_line',
        'photo_count',
        'expires_on',
        'promo_code',
        'promo_offer',
        'promo_name',
        'promo_description',
        'promo_expires',
        'promo_terms',
        'review_link',
        'rating_link',
        'opt_out_link',
    ];

    public const OPTIONAL_TEXT = [
        'completion_time',
        'escape_result',
        'photo_link',
        'photos_line',
        'expires_on',
        'promo_code',
        'promo_offer',
        'promo_name',
        'promo_description',
        'promo_expires',
        'promo_terms',
        'review_link',
        'rating_link',
        'opt_out_link',
    ];

    public const HTML_SECTIONS = [
        'game_result_section',
        'group_photo_section',
        'promo_section',
        'rating_section',
        'review_section',
    ];

    public function __construct(protected EmailNotificationService $emails)
    {
    }

    public function isAvailable(): bool
    {
        return VisitFollowUp::isAvailable();
    }

    public function thanksEmailFor(CompletedVisit $visit): ?EmailNotification
    {
        return $this->resolveEmail($visit, EmailNotification::TRIGGER_VISIT_COMPLETED, EmailNotification::DEFAULT_THANKS_FOR_PLAYING);
    }

    public function reviewEmailFor(CompletedVisit $visit): ?EmailNotification
    {
        return $this->resolveEmail($visit, EmailNotification::TRIGGER_VISIT_FOLLOWUP, EmailNotification::DEFAULT_REVIEW_REQUEST);
    }

    protected function resolveEmail(CompletedVisit $visit, string $trigger, string $defaultKey): ?EmailNotification
    {
        $found = EmailNotification::resolveForVisit($visit, $trigger);

        if ($found || EmailNotification::where('company_id', $visit->companyId)->where('default_key', $defaultKey)->exists()) {
            return $found;
        }

        $company = Company::find($visit->companyId);

        if (!$company) {
            return null;
        }

        try {
            DefaultEmailNotificationSeeder::seedForCompany($company);
        } catch (\Throwable $e) {
            Log::warning('Visit follow-up defaults could not be seeded', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return null;
        }

        return EmailNotification::resolveForVisit($visit, $trigger);
    }

    public function gameHandlesBooking(Booking $booking): bool
    {
        $booking->loadMissing('package', 'location');

        if (!$booking->package?->isEscapeRoom()) {
            return false;
        }

        try {
            $escapeRooms = app(EscapeRoomSessionService::class);

            if (!$escapeRooms->isEnabled()) {
                return false;
            }

            if ($booking->location && $escapeRooms->templateForRoom($booking->location, $booking->package) !== null) {
                return true;
            }

            $time = $escapeRooms->bookingTime($booking);

            return $booking->booking_date !== null && $time !== null && EscapeRoomSession::where('package_id', $booking->package_id)
                ->whereDate('session_date', $booking->booking_date->toDateString())
                ->where('session_time', $time . ':00')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function bookingCompleted(Booking $booking, ?User $user = null): ?array
    {
        if (!$this->isAvailable() || $booking->status !== 'completed') {
            return null;
        }

        $since = now()->startOfSecond();

        if (!$this->gameHandlesBooking($booking) && ($visit = CompletedVisit::fromBooking($booking))) {
            $this->completeVisit($visit, array_values(array_filter([$this->bookerRecipient($booking)])), $user);
        }

        return $this->summaryFor(VisitFollowUp::VISIT_BOOKING, (int) $booking->id, $since);
    }

    public function eventPurchaseCompleted(EventPurchase $purchase, ?User $user = null): ?array
    {
        if (!$this->isAvailable() || $purchase->status !== 'completed') {
            return null;
        }

        $since = now()->startOfSecond();

        if ($visit = CompletedVisit::fromEventPurchase($purchase)) {
            $this->completeVisit($visit, array_values(array_filter([$this->bookerRecipient($purchase)])), $user);
        }

        return $this->summaryFor(VisitFollowUp::VISIT_EVENT_PURCHASE, (int) $purchase->id, $since);
    }

    public function visitReopened(string $visitType, int $visitId, ?User $user = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $canceled = VisitFollowUp::forVisit($visitType, $visitId)
            ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
            ->update([
                'status' => VisitFollowUp::STATUS_CANCELED,
                'error' => self::REOPENED_REASON,
                'reason' => VisitFollowUp::REASON_REOPENED,
                'updated_at' => now(),
            ]);

        if ($canceled > 0) {
            $row = VisitFollowUp::forVisit($visitType, $visitId)->first();

            ActivityLog::log(
                'visit_follow_up_canceled',
                'email',
                sprintf('Canceled %d follow-up email(s) because the %s is no longer marked complete', $canceled, str_replace('_', ' ', $visitType)),
                $user?->id,
                $row?->location_id,
                $visitType,
                $visitId
            );
        }

        return $canceled;
    }

    public function visitMoved(string $visitType, int $visitId, ?int $locationId): int
    {
        if (!$this->isAvailable() || $locationId === null) {
            return 0;
        }

        return VisitFollowUp::forVisit($visitType, $visitId)
            ->where(fn ($query) => $query->whereNull('location_id')->orWhere('location_id', '!=', $locationId))
            ->update(['location_id' => $locationId]);
    }

    public function bookerChanged(Booking|EventPurchase $subject, ?User $user = null): ?array
    {
        $subject = $subject->fresh() ?? $subject;

        if (!$this->isAvailable() || $subject->status !== 'completed') {
            return null;
        }

        if ($subject instanceof Booking && $this->gameHandlesBooking($subject)) {
            return null;
        }

        $visit = $subject instanceof Booking ? CompletedVisit::fromBooking($subject) : CompletedVisit::fromEventPurchase($subject);

        if (!$visit) {
            return null;
        }

        $current = $this->bookerRecipient($subject);
        $stale = VisitFollowUp::forVisit($visit->type, $visit->id())
            ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
            ->when($current, fn ($query) => $query->where('recipient_email', '!=', $current['email']))
            ->get();

        if ($stale->isEmpty()) {
            return null;
        }

        $reviewDue = null;

        foreach ($stale as $row) {
            $moved = VisitFollowUp::whereKey($row->id)
                ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
                ->update([
                    'status' => VisitFollowUp::STATUS_CANCELED,
                    'error' => $current
                        ? 'The guest\'s email address was changed, so this email was not sent to the old address.'
                        : 'The email address was removed from this visit.',
                    'reason' => VisitFollowUp::REASON_RECIPIENT_CHANGED,
                    'updated_at' => now(),
                ]);

            if ($moved && $row->kind === VisitFollowUp::KIND_REVIEW) {
                $reviewDue = $row->status === VisitFollowUp::STATUS_SCHEDULED && $row->due_at ? $row->due_at : now();
            }
        }

        if ($current && $reviewDue && ($review = $this->reviewEmailFor($visit))) {
            $this->createRows(
                $visit,
                VisitFollowUp::KIND_REVIEW,
                [$current],
                $review,
                $reviewDue->isFuture() ? $reviewDue : $this->reviewDueAt($review, $visit->location, now()),
                $user
            );
        }

        ActivityLog::log(
            'visit_follow_up_recipient_changed',
            'email',
            sprintf('The guest email on %s changed, so %d waiting follow-up email(s) to the old address were canceled', $visit->reference ?: $visit->activityName, $stale->count()),
            $user?->id,
            $visit->locationId(),
            $visit->type,
            $visit->id()
        );

        return $this->summaryFor($visit->type, $visit->id());
    }

    public function completeVisit(CompletedVisit $visit, array $recipients, ?User $user, bool $force = false): void
    {
        if ($recipients === []) {
            return;
        }

        $held = $force ? null : $this->visitDateProblem($visit);
        $sent = 0;
        $thanks = $this->thanksEmailFor($visit);

        if ($thanks) {
            foreach ($this->createRows($visit, VisitFollowUp::KIND_THANKS, $recipients, $thanks, now(), $user, $held) as $row) {
                if ($row->status === VisitFollowUp::STATUS_SCHEDULED && $this->send($row)) {
                    $sent++;
                }
            }
        }

        $reviews = $this->scheduleReviews($visit, $recipients, $user, now(), $held);

        ActivityLog::log(
            'visit_follow_up_started',
            'email',
            sprintf(
                '%s %s: %s; %s',
                $visit->activityName,
                $visit->reference ? '(' . $visit->reference . ')' : '',
                match (true) {
                    $held !== null => 'nothing sent automatically: ' . $held['error'],
                    $thanks !== null => sprintf('sent the Thanks for Playing email to %d guest(s)', $sent),
                    default => 'the Thanks for Playing email is switched off',
                },
                $this->reviewScheduleLabel($reviews)
            ),
            $user?->id,
            $visit->locationId(),
            $visit->type,
            $visit->id(),
            ['thanks_notification_id' => $thanks?->id, 'thanks_sent' => $sent, 'review_ids' => collect($reviews)->pluck('id')->all(), 'held' => $held['reason'] ?? null]
        );
    }

    public function visitDateProblem(CompletedVisit $visit): ?array
    {
        if (!$visit->date) {
            return null;
        }

        $today = OperatingDay::calendarDateFor($visit->location);
        $date = Carbon::parse($visit->date)->toDateString();
        $label = Carbon::parse($date)->format('M j, Y');

        if ($date > $today) {
            return [
                'reason' => VisitFollowUp::REASON_VISIT_DATE,
                'error' => sprintf('The visit is on %s, which has not happened yet, so nothing was sent automatically. Use Send now if the guest has visited.', $label),
            ];
        }

        $age = (int) abs(Carbon::parse($date)->startOfDay()->diffInDays(Carbon::parse($today)->startOfDay()));

        if ($age > self::MAX_VISIT_AGE_DAYS) {
            return [
                'reason' => VisitFollowUp::REASON_VISIT_DATE,
                'error' => sprintf('The visit was on %s, more than %d days ago, so nothing was sent automatically. Use Send now to send it anyway.', $label, self::MAX_VISIT_AGE_DAYS),
            ];
        }

        return null;
    }

    protected function reviewScheduleLabel(array $reviews): string
    {
        $scheduled = collect($reviews)->where('status', VisitFollowUp::STATUS_SCHEDULED);

        if ($scheduled->isEmpty()) {
            return 'no review request scheduled';
        }

        return sprintf('review request scheduled for %d guest(s) at %s', $scheduled->count(), $scheduled->first()->due_at?->toIso8601String());
    }

    public function gameCompleted(EscapeRoomSession $game, Collection $players, ?User $user, bool $sendThanksWithoutPhoto = false): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $visit = CompletedVisit::fromGame($game->fresh() ?? $game);
        $recipients = $this->playerRecipients($players);

        if (!$visit || $recipients === []) {
            return;
        }

        if ($sendThanksWithoutPhoto && ($thanks = $this->thanksEmailFor($visit))) {
            foreach ($this->createRows($visit, VisitFollowUp::KIND_THANKS, $recipients, $thanks, now(), $user) as $row) {
                if ($row->status === VisitFollowUp::STATUS_SCHEDULED) {
                    $this->send($row);
                }
            }
        }

        $this->scheduleReviews($visit, $recipients, $user, now());
    }

    public function gamePlayersAdded(EscapeRoomSession $game, Collection $players, ?User $user): void
    {
        if (!$this->isAvailable() || !($visit = CompletedVisit::fromGame($game->fresh() ?? $game))) {
            return;
        }

        $this->scheduleReviews($visit, $this->playerRecipients($players), $user, now());
    }

    public function gamePlayerRedirected(EscapeRoomSession $game, Waiver $waiver, string $email, ?User $user): void
    {
        if (!$this->isAvailable() || !($visit = CompletedVisit::fromGame($game->fresh() ?? $game))) {
            return;
        }

        $normalized = FollowUpOptOut::normalize($email);
        $reviews = VisitFollowUp::forVisit(VisitFollowUp::VISIT_ESCAPE_ROOM_GAME, (int) $game->id)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->where('waiver_id', $waiver->id)
            ->get();
        $settled = $reviews->contains(fn (VisitFollowUp $row) => in_array($row->status, [VisitFollowUp::STATUS_SENT, VisitFollowUp::STATUS_SENDING], true)
            || $row->rating !== null
            || $row->reason === VisitFollowUp::REASON_STAFF
            || $row->reason === VisitFollowUp::REASON_OPTED_OUT
            || $this->marketingBlock($visit->companyId, $row->recipient_email, $waiver->id) !== null);

        if ($settled) {
            return;
        }

        $waiting = $reviews->filter(fn (VisitFollowUp $row) => $row->recipient_email !== $normalized
            && in_array($row->status, [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED], true));
        $due = null;

        foreach ($waiting as $row) {
            $moved = VisitFollowUp::whereKey($row->id)
                ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
                ->update([
                    'status' => VisitFollowUp::STATUS_CANCELED,
                    'error' => 'The photo was re-sent to a different address, so the review request follows it there.',
                    'reason' => VisitFollowUp::REASON_REDIRECTED,
                    'updated_at' => now(),
                ]);

            if ($moved) {
                $due = $row->status === VisitFollowUp::STATUS_SCHEDULED && $row->due_at?->isFuture() ? $row->due_at : ($due ?? now());
            }
        }

        $recipient = [[
            'email' => $normalized,
            'name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
            'waiver_id' => $waiver->id,
            'customer_id' => $waiver->customer_id,
        ]];

        if ($due !== null && ($review = $this->reviewEmailFor($visit))) {
            $this->createRows($visit, VisitFollowUp::KIND_REVIEW, $recipient, $review, $due->isFuture() ? $due : $this->reviewDueAt($review, $visit->location, now()), $user);
        } elseif ($reviews->isEmpty() || $reviews->contains(fn (VisitFollowUp $row) => $row->recipient_email === $normalized)) {
            $this->scheduleReviews($visit, $recipient, $user, now());
        }
    }

    public function waiverLeftGame(int $gameId, int $waiverId): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $rows = VisitFollowUp::forVisit(VisitFollowUp::VISIT_ESCAPE_ROOM_GAME, $gameId)
            ->where('waiver_id', $waiverId)
            ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $stayers = Waiver::where('escape_room_session_id', $gameId)
            ->where('status', Waiver::STATUS_COMPLETED)
            ->whereKeyNot($waiverId)
            ->get(['id', 'adult_email', 'adult_first_name', 'adult_last_name', 'customer_id']);
        $canceled = 0;

        foreach ($rows as $row) {
            $stayer = $stayers->first(fn (Waiver $waiver) => FollowUpOptOut::normalize($waiver->adult_email) === $row->recipient_email);
            $changed = VisitFollowUp::whereKey($row->id)
                ->whereIn('status', [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED])
                ->update($stayer
                    ? $this->playerFields($stayer, $row) + ['updated_at' => now()]
                    : [
                        'status' => VisitFollowUp::STATUS_CANCELED,
                        'error' => 'The player was taken out of this game, so this email was not sent.',
                        'reason' => VisitFollowUp::REASON_LEFT_GAME,
                        'updated_at' => now(),
                    ]);

            $canceled += $stayer ? 0 : $changed;
        }

        return $canceled;
    }

    public function visitRestored(string $visitType, int $visitId, ?User $user): ?array
    {
        if (!$this->isAvailable() || !($visit = CompletedVisit::find($visitType, $visitId)) || !$visit->isStillComplete()) {
            return null;
        }

        $gone = VisitFollowUp::forVisit($visitType, $visitId)->where('reason', VisitFollowUp::REASON_VISIT_GONE)->get();

        if ($gone->isEmpty()) {
            return null;
        }

        $held = $visit->isGame() ? null : $this->visitDateProblem($visit);

        foreach ($gone->groupBy('kind') as $kind => $rows) {
            $recipients = $rows->map(fn (VisitFollowUp $row) => [
                'email' => $row->recipient_email,
                'name' => $row->recipient_name,
                'waiver_id' => $row->waiver_id,
                'customer_id' => $row->customer_id,
            ])->values()->all();

            if ($kind === VisitFollowUp::KIND_REVIEW) {
                $this->scheduleReviews($visit, $recipients, $user, now(), $held);
            } elseif ($thanks = $this->thanksEmailFor($visit)) {
                foreach ($this->createRows($visit, VisitFollowUp::KIND_THANKS, $recipients, $thanks, now(), $user, $held) as $row) {
                    if ($row->status === VisitFollowUp::STATUS_SCHEDULED) {
                        $this->send($row);
                    }
                }
            }
        }

        return $visit->isGame() ? null : $this->summaryFor($visitType, $visitId);
    }

    public function playerRecipients(Collection $waivers): array
    {
        $seen = [];
        $recipients = [];

        foreach ($waivers as $waiver) {
            $email = FollowUpOptOut::normalize($waiver->adult_email);

            if (!$this->validEmail($email) || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $recipients[] = [
                'email' => $email,
                'name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
                'waiver_id' => $waiver->id,
                'customer_id' => $waiver->customer_id,
            ];
        }

        return $recipients;
    }

    public function bookerRecipient(Booking|EventPurchase $subject): ?array
    {
        $subject->loadMissing('customer');
        $email = FollowUpOptOut::normalize($subject->customer?->email ?: $subject->guest_email);

        if (!$this->validEmail($email)) {
            return null;
        }

        $name = $subject->customer
            ? trim(($subject->customer->first_name ?? '') . ' ' . ($subject->customer->last_name ?? ''))
            : trim((string) $subject->guest_name);

        return [
            'email' => $email,
            'name' => $name,
            'waiver_id' => null,
            'customer_id' => $subject->customer_id,
        ];
    }

    public function scheduleReviews(CompletedVisit $visit, array $recipients, ?User $user, ?Carbon $from = null, ?array $held = null): array
    {
        if ($recipients === [] || !$this->isAvailable()) {
            return [];
        }

        $review = $this->reviewEmailFor($visit);

        if (!$review) {
            return [];
        }

        return $this->createRows(
            $visit,
            VisitFollowUp::KIND_REVIEW,
            $recipients,
            $review,
            $this->reviewDueAt($review, $visit->location, $from ?? now()),
            $user,
            $held
        );
    }

    public function reviewDueAt(EmailNotification $review, ?Location $location, Carbon $from): Carbon
    {
        $hours = (int) ($review->send_after_hours ?: EmailNotification::REVIEW_REQUEST_DEFAULT_HOURS);
        $hours = max(1, min($hours, self::MAX_REVIEW_DELAY_HOURS));
        $due = $from->copy()->addHours($hours);

        return $this->intoSendingWindow($due->lessThan(now()) ? now() : $due, $location);
    }

    public function intoSendingWindow(Carbon $at, ?Location $location): Carbon
    {
        $local = $at->copy()->setTimezone(OperatingDay::timezoneFor($location));

        if ($local->hour < self::SEND_WINDOW_START_HOUR) {
            $local = $local->setTime(self::SEND_WINDOW_START_HOUR, 0);
        } elseif ($local->hour >= self::SEND_WINDOW_END_HOUR) {
            $local = $local->addDay()->setTime(self::SEND_WINDOW_START_HOUR, 0);
        }

        return $local->setTimezone(config('app.timezone'));
    }

    public function insideSendingWindow(?Location $location): bool
    {
        $hour = OperatingDay::localNow($location)->hour;

        return $hour >= self::SEND_WINDOW_START_HOUR && $hour < self::SEND_WINDOW_END_HOUR;
    }

    protected function createRows(CompletedVisit $visit, string $kind, array $recipients, EmailNotification $notification, Carbon $dueAt, ?User $user, ?array $held = null): array
    {
        $rows = [];

        foreach ($recipients as $recipient) {
            $email = FollowUpOptOut::normalize($recipient['email'] ?? null);

            if (!$this->validEmail($email)) {
                continue;
            }

            $existing = VisitFollowUp::forVisit($visit->type, $visit->id())
                ->where('kind', $kind)
                ->where('recipient_email', $email)
                ->first();

            if ($existing && !$existing->canRevive()) {
                continue;
            }

            $block = $held ?? ($kind === VisitFollowUp::KIND_REVIEW
                ? $this->reviewBlock($visit->companyId, $email, $existing?->id, $recipient['waiver_id'] ?? $existing?->waiver_id, $dueAt)
                : null);

            $fields = [
                'status' => $block ? VisitFollowUp::STATUS_SKIPPED : VisitFollowUp::STATUS_SCHEDULED,
                'error' => $block['error'] ?? null,
                'reason' => $block['reason'] ?? null,
                'due_at' => $dueAt,
                'email_notification_id' => $notification->id,
                'location_id' => $visit->locationId(),
            ];

            if ($existing) {
                $revived = VisitFollowUp::whereKey($existing->id)
                    ->where('status', $existing->status)
                    ->where('attempts', $existing->attempts)
                    ->update($fields + [
                        'waiver_id' => $recipient['waiver_id'] ?? $existing->waiver_id,
                        'customer_id' => array_key_exists('customer_id', $recipient) ? $recipient['customer_id'] : $existing->customer_id,
                        'recipient_name' => mb_substr((string) ($recipient['name'] ?? ''), 0, 190) ?: $existing->recipient_name,
                        'attempts' => 0,
                        'updated_at' => now(),
                    ]);

                if ($revived === 1) {
                    $rows[] = $existing->fresh();
                }

                continue;
            }

            try {
                $rows[] = VisitFollowUp::create($fields + [
                    'company_id' => $visit->companyId,
                    'visit_type' => $visit->type,
                    'visit_id' => $visit->id(),
                    'kind' => $kind,
                    'waiver_id' => $recipient['waiver_id'] ?? null,
                    'customer_id' => $recipient['customer_id'] ?? null,
                    'recipient_email' => $email,
                    'recipient_name' => mb_substr((string) ($recipient['name'] ?? ''), 0, 190) ?: null,
                    'created_by' => $user?->id,
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return $rows;
    }

    public function reviewBlock(int $companyId, string $email, ?int $exceptId = null, ?int $waiverId = null, ?Carbon $at = null): ?array
    {
        if ($block = $this->marketingBlock($companyId, $email, $waiverId)) {
            return $block;
        }

        $others = VisitFollowUp::where('company_id', $companyId)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->where('recipient_email', $email)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId));

        $recent = (clone $others)
            ->where('status', VisitFollowUp::STATUS_SENT)
            ->where('sent_at', '>=', ($at && $at->isFuture() ? $at : now())->copy()->subDays(self::REVIEW_REPEAT_DAYS))
            ->orderByDesc('sent_at')
            ->first();

        if ($recent) {
            return [
                'reason' => VisitFollowUp::REASON_ASKED_RECENTLY,
                'error' => sprintf(
                    'This guest was already asked for a review on %s. We ask at most once every %d days.',
                    $recent->sent_at->copy()->setTimezone(OperatingDay::timezoneFor($recent->location))->format('M j, Y'),
                    self::REVIEW_REPEAT_DAYS
                ),
            ];
        }

        return null;
    }

    public function marketingBlock(int $companyId, ?string $email, ?int $waiverId = null): ?array
    {
        $email = FollowUpOptOut::normalize($email);

        if ($email === '') {
            return null;
        }

        if (FollowUpOptOut::hasOptedOut($companyId, $email)) {
            return ['reason' => VisitFollowUp::REASON_OPTED_OUT, 'error' => 'This guest unsubscribed from review requests and offers.'];
        }

        if ($waiverId && SchemaSupport::hasColumn('waivers', 'marketing_consent_status')
            && Waiver::whereKey($waiverId)->where('marketing_consent_status', Waiver::MARKETING_WITHDRAWN)->exists()) {
            return ['reason' => VisitFollowUp::REASON_OPTED_OUT, 'error' => 'This guest withdrew their marketing consent on their waiver.'];
        }

        if (Contact::where('company_id', $companyId)->where('email', $email)->where('status', 'inactive')->exists()) {
            return ['reason' => VisitFollowUp::REASON_OPTED_OUT, 'error' => 'This guest is marked inactive in Contacts, so they are left out of review requests and offers.'];
        }

        return null;
    }

    public function send(VisitFollowUp $row): bool
    {
        $claimed = $this->claim($row);

        if (!$claimed) {
            return false;
        }

        try {
            $outcome = $this->deliver($claimed);
        } catch (\Throwable $e) {
            Log::error('Visit follow-up email failed', [
                'visit_follow_up_id' => $claimed->id,
                'error' => $e->getMessage(),
            ]);

            $outcome = ['status' => VisitFollowUp::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000), 'reason' => null];
        }

        $claimed->forceFill($outcome)->save();

        if ($claimed->status === VisitFollowUp::STATUS_FAILED && $claimed->gaveUp()) {
            $this->alertStaffOfFailure($claimed);
        }

        return $claimed->status === VisitFollowUp::STATUS_SENT;
    }

    protected function claim(VisitFollowUp $row): ?VisitFollowUp
    {
        $fresh = $row->fresh();

        if (!$fresh || !in_array($fresh->status, [VisitFollowUp::STATUS_SCHEDULED, VisitFollowUp::STATUS_FAILED], true)) {
            return null;
        }

        $claimed = VisitFollowUp::whereKey($fresh->id)
            ->where('status', $fresh->status)
            ->where('attempts', $fresh->attempts)
            ->update([
                'status' => VisitFollowUp::STATUS_SENDING,
                'attempts' => $fresh->attempts + 1,
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $fresh->fresh() : null;
    }

    protected function deliver(VisitFollowUp $row): array
    {
        $visit = CompletedVisit::find($row->visit_type, (int) $row->visit_id);

        if (!$visit) {
            return ['status' => VisitFollowUp::STATUS_SKIPPED, 'error' => 'The visit no longer exists.', 'reason' => VisitFollowUp::REASON_VISIT_GONE];
        }

        $place = $visit->locationId() !== null ? ['location_id' => $visit->locationId()] : [];

        if (!$visit->isStillComplete()) {
            return ['status' => VisitFollowUp::STATUS_CANCELED, 'error' => self::REOPENED_REASON, 'reason' => VisitFollowUp::REASON_REOPENED] + $place;
        }

        if ($stale = $this->staleRecipient($row, $visit)) {
            if ($row->kind === VisitFollowUp::KIND_REVIEW && !$visit->isGame()
                && ($current = $this->bookerRecipient($visit->subject))
                && ($review = $this->reviewEmailFor($visit))) {
                VisitFollowUp::whereKey($row->id)->update(['status' => VisitFollowUp::STATUS_CANCELED] + $stale + ['updated_at' => now()]);
                $this->createRows($visit, VisitFollowUp::KIND_REVIEW, [$current], $review, $this->intoSendingWindow(now(), $visit->location), null);
            }

            return ['status' => VisitFollowUp::STATUS_CANCELED] + $stale + $place;
        }

        $isReview = $row->kind === VisitFollowUp::KIND_REVIEW;
        $notification = $isReview ? $this->reviewEmailFor($visit) : $this->thanksEmailFor($visit);

        if (!$notification) {
            return [
                'status' => VisitFollowUp::STATUS_SKIPPED,
                'error' => $isReview
                    ? 'The Review Request email is switched off in Email Notifications.'
                    : 'The Thanks for Playing email is switched off in Email Notifications.',
                'reason' => VisitFollowUp::REASON_SWITCHED_OFF,
            ] + $place;
        }

        if ($isReview && ($block = $this->reviewBlock($visit->companyId, $row->recipient_email, $row->id, $row->waiver_id))) {
            return ['status' => VisitFollowUp::STATUS_SKIPPED] + $block + $place;
        }

        $built = $this->build(
            $notification,
            $visit,
            ['email' => $row->recipient_email, 'name' => $row->recipient_name, 'waiver_id' => $row->waiver_id],
            $row,
            $isReview ? null : $this->photoSessionForVisit($visit)
        );

        $log = $this->openLog($notification, $row->recipient_email, $visit->subject);

        try {
            $this->emails->sendEmail($row->recipient_email, $built['subject'], $built['html'], ['company_name' => $built['from_name']], $built['attachments']);
        } catch (\Throwable $e) {
            $log?->markAsFailed(mb_substr($e->getMessage(), 0, 1000));

            throw $e;
        }

        $log?->markAsSent();

        return [
            'status' => VisitFollowUp::STATUS_SENT,
            'sent_at' => now(),
            'error' => null,
            'reason' => null,
            'email_notification_id' => $notification->id,
        ] + $place;
    }

    protected function staleRecipient(VisitFollowUp $row, CompletedVisit $visit): ?array
    {
        if ($visit->isGame()) {
            if ($row->waiver_id === null) {
                return null;
            }

            [$player, $problem] = $this->gamePlayer($row, $visit);

            if ($player && (int) $player->id !== (int) $row->waiver_id) {
                $row->forceFill($this->playerFields($player, $row));
            }

            return $player ? null : ['reason' => VisitFollowUp::REASON_LEFT_GAME, 'error' => match ($problem) {
                'booking_cancelled' => "The player's booking was cancelled, so this email was not sent.",
                'booking_deleted' => "The player's booking was deleted, so this email was not sent.",
                default => 'The player was taken out of this game, so this email was not sent.',
            }];
        }

        $current = $this->bookerRecipient($visit->subject);

        return $current !== null && $current['email'] === $row->recipient_email
            ? null
            : [
                'reason' => VisitFollowUp::REASON_RECIPIENT_CHANGED,
                'error' => $current
                    ? 'The guest\'s email address was changed, so this email was not sent to the old address.'
                    : 'The email address was removed from this visit.',
            ];
    }

    protected function playerFields(Waiver $waiver, VisitFollowUp $row): array
    {
        return [
            'waiver_id' => $waiver->id,
            'customer_id' => $waiver->customer_id,
            'recipient_name' => mb_substr(trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')), 0, 190) ?: $row->recipient_name,
        ];
    }

    protected function gamePlayer(VisitFollowUp $row, CompletedVisit $visit): array
    {
        $candidates = Waiver::withoutHeavyColumns()
            ->with('booking:id,status')
            ->where('escape_room_session_id', $visit->id())
            ->where('status', Waiver::STATUS_COMPLETED)
            ->get()
            ->filter(fn (Waiver $waiver) => (int) $waiver->id === (int) $row->waiver_id
                || FollowUpOptOut::normalize($waiver->adult_email) === $row->recipient_email)
            ->sortByDesc(fn (Waiver $waiver) => (int) $waiver->id === (int) $row->waiver_id ? 1 : 0)
            ->values();
        $bookingProblem = fn (Waiver $waiver) => !$waiver->booking_id ? null
            : (!$waiver->booking ? 'booking_deleted' : ($waiver->booking->status === 'cancelled' ? 'booking_cancelled' : null));
        $player = $candidates->first(fn (Waiver $waiver) => $bookingProblem($waiver) === null);

        if ($player) {
            return [$player, null];
        }

        return [null, $candidates->isEmpty() ? 'left' : $bookingProblem($candidates->first())];
    }

    public function openLog(EmailNotification $notification, string $email, $notifiable): ?EmailNotificationLog
    {
        try {
            return EmailNotificationLog::create([
                'email_notification_id' => $notification->id,
                'recipient_email' => $email,
                'recipient_type' => EmailNotification::RECIPIENT_CUSTOMER,
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->getKey(),
                'status' => EmailNotificationLog::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write the email log for a visit follow-up', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function sendDue(int $limit = 200): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'released' => 0, 'held' => 0];

        if (!$this->isAvailable()) {
            return $counts;
        }

        $stuck = VisitFollowUp::where('status', VisitFollowUp::STATUS_SENDING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_SENDING_MINUTES))
            ->limit($limit)
            ->get();

        foreach ($stuck as $row) {
            $released = VisitFollowUp::whereKey($row->id)
                ->where('status', VisitFollowUp::STATUS_SENDING)
                ->where('attempts', $row->attempts)
                ->update([
                    'status' => VisitFollowUp::STATUS_FAILED,
                    'error' => 'The send was interrupted before it finished.',
                    'updated_at' => now(),
                ]);

            if ($released === 1) {
                $counts['released']++;
                $fresh = $row->fresh();

                if ($fresh?->gaveUp()) {
                    $this->alertStaffOfFailure($fresh);
                }
            }
        }

        $due = VisitFollowUp::due()->with('location')->orderBy('due_at')->limit($limit)->get();
        $retrying = VisitFollowUp::retryable()->with('location')->orderBy('updated_at')->limit($limit)->get();

        foreach ($due->concat($retrying)->unique('id') as $row) {
            if (!$this->insideSendingWindow($row->location)) {
                $counts['held']++;

                if ($row->status === VisitFollowUp::STATUS_SCHEDULED) {
                    VisitFollowUp::whereKey($row->id)
                        ->where('status', VisitFollowUp::STATUS_SCHEDULED)
                        ->update(['due_at' => $this->intoSendingWindow(now(), $row->location)]);
                }

                continue;
            }

            $this->send($row);
            $status = $row->fresh()?->status;

            if ($status === VisitFollowUp::STATUS_SENT) {
                $counts['sent']++;
            } elseif ($status === VisitFollowUp::STATUS_FAILED) {
                $counts['failed']++;
            } elseif (in_array($status, [VisitFollowUp::STATUS_SKIPPED, VisitFollowUp::STATUS_CANCELED], true)) {
                $counts['skipped']++;
            }
        }

        return $counts;
    }

    public function sendNow(VisitFollowUp $row, User $user): VisitFollowUp
    {
        if ($row->reason === VisitFollowUp::REASON_REDIRECTED) {
            throw new \DomainException('The photo was re-sent to a different address, so this email goes to that address instead.');
        }

        if ($row->reason === VisitFollowUp::REASON_LEFT_GAME) {
            $visit = CompletedVisit::find($row->visit_type, (int) $row->visit_id);
            [$player, $problem] = $visit && $visit->isGame() ? $this->gamePlayer($row, $visit) : [null, 'left'];

            if (!$player) {
                throw new \DomainException($problem === 'left'
                    ? 'This player is not in the game any more, so this email cannot be sent.'
                    : "This player's booking was cancelled or deleted, so this email cannot be sent.");
            }
        }

        $heldForDate = $row->reason === VisitFollowUp::REASON_VISIT_DATE;

        $reset = VisitFollowUp::whereKey($row->id)
            ->whereNotIn('status', [VisitFollowUp::STATUS_SENT, VisitFollowUp::STATUS_SENDING])
            ->update([
                'status' => VisitFollowUp::STATUS_SCHEDULED,
                'attempts' => 0,
                'error' => null,
                'reason' => null,
                'due_at' => now(),
                'updated_at' => now(),
            ]);

        $row->refresh();

        if ($reset === 0) {
            throw new \DomainException($row->status === VisitFollowUp::STATUS_SENT
                ? 'This email has already been sent.'
                : 'This email is being sent right now.');
        }

        $this->send($row);
        $row->refresh();

        if ($heldForDate && $row->kind === VisitFollowUp::KIND_THANKS && in_array($row->status, [VisitFollowUp::STATUS_SENT, VisitFollowUp::STATUS_FAILED], true)) {
            $this->resumeHeldReview($row, $user);
        }

        ActivityLog::log(
            'visit_follow_up_sent_now',
            'email',
            sprintf('Sent the %s email to %s now (%s)', $row->kind === VisitFollowUp::KIND_REVIEW ? 'review request' : 'Thanks for Playing', $row->maskedEmail(), $row->status),
            $user->id,
            $row->location_id,
            $row->visit_type,
            $row->visit_id,
            ['visit_follow_up_id' => $row->id, 'status' => $row->status]
        );

        return $row;
    }

    protected function resumeHeldReview(VisitFollowUp $thanks, User $user): void
    {
        $visit = CompletedVisit::find($thanks->visit_type, (int) $thanks->visit_id);

        if (!$visit) {
            return;
        }

        $review = VisitFollowUp::forVisit($thanks->visit_type, (int) $thanks->visit_id)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->where('recipient_email', $thanks->recipient_email)
            ->first();

        if ($review && $review->reason !== VisitFollowUp::REASON_VISIT_DATE) {
            return;
        }

        $this->scheduleReviews($visit, [[
            'email' => $thanks->recipient_email,
            'name' => $thanks->recipient_name,
            'waiver_id' => $thanks->waiver_id,
            'customer_id' => $thanks->customer_id,
        ]], $user, now());
    }

    public function cancel(VisitFollowUp $row, User $user): VisitFollowUp
    {
        $canceled = VisitFollowUp::whereKey($row->id)
            ->where('status', VisitFollowUp::STATUS_SCHEDULED)
            ->update([
                'status' => VisitFollowUp::STATUS_CANCELED,
                'error' => 'Canceled by staff.',
                'reason' => VisitFollowUp::REASON_STAFF,
                'updated_at' => now(),
            ]);

        $row->refresh();

        if ($canceled === 0) {
            throw new \DomainException('Only an email that is still waiting to go out can be canceled.');
        }

        ActivityLog::log(
            'visit_follow_up_canceled_by_staff',
            'email',
            sprintf('Canceled the %s email to %s', $row->kind === VisitFollowUp::KIND_REVIEW ? 'review request' : 'Thanks for Playing', $row->maskedEmail()),
            $user->id,
            $row->location_id,
            $row->visit_type,
            $row->visit_id,
            ['visit_follow_up_id' => $row->id]
        );

        return $row;
    }

    public function sendThanksFor(CompletedVisit $visit, User $user): array
    {
        if ($visit->isGame()) {
            throw new \DomainException('Escape-room games send the Thanks for Playing email from the game screen.');
        }

        if (!$visit->isStillComplete()) {
            throw new \DomainException('Mark the visit as Completed first.');
        }

        if ($visit->subject instanceof Booking && $this->gameHandlesBooking($visit->subject)) {
            throw new \DomainException('This is an escape-room booking, so its Thanks for Playing email goes out from the game screen with the group photo.');
        }

        if (!$this->thanksEmailFor($visit)) {
            throw new \DomainException('The Thanks for Playing email is switched off in Email Notifications.');
        }

        $recipient = $this->bookerRecipient($visit->subject);

        if (!$recipient) {
            throw new \DomainException('There is no valid email address on this ' . ($visit->type === VisitFollowUp::VISIT_BOOKING ? 'booking' : 'purchase') . '.');
        }

        $since = now()->startOfSecond();
        $existing = VisitFollowUp::forVisit($visit->type, $visit->id())
            ->where('kind', VisitFollowUp::KIND_THANKS)
            ->where('recipient_email', $recipient['email'])
            ->first();

        if ($existing && $existing->status === VisitFollowUp::STATUS_SENT) {
            throw new \DomainException('The Thanks for Playing email has already been sent to this address for this visit.');
        }

        if ($existing) {
            $this->sendNow($existing, $user);
        } else {
            $this->completeVisit($visit, [$recipient], $user, true);
        }

        return $this->summaryFor($visit->type, $visit->id(), $since);
    }

    public function summaryFor(string $visitType, int $visitId, ?Carbon $since = null): array
    {
        $visit = CompletedVisit::find($visitType, $visitId);
        $rows = $this->isAvailable()
            ? VisitFollowUp::forVisit($visitType, $visitId)->orderBy('id')->get()
            : collect();
        $booking = $visit && $visit->subject instanceof Booking ? $visit->subject : null;
        $handledByGame = $booking ? $this->gameHandlesBooking($booking) : false;
        $thanks = $visit ? $this->thanksEmailFor($visit) : null;
        $review = $visit ? $this->reviewEmailFor($visit) : null;
        $recipient = $visit && !$visit->isGame() ? $this->bookerRecipient($visit->subject) : null;
        $completed = $visit?->isStillComplete() ?? false;
        $thanksRows = $rows->where('kind', VisitFollowUp::KIND_THANKS);
        $present = fn (VisitFollowUp $row) => $row->toStaffArray() + [
            'sent_in_this_action' => $since !== null
                && $row->status === VisitFollowUp::STATUS_SENT
                && $row->sent_at !== null
                && $row->sent_at->greaterThanOrEqualTo($since),
            'is_current_recipient' => $recipient !== null && $row->recipient_email === $recipient['email'],
        ];

        return [
            'available' => $this->isAvailable(),
            'visit_type' => $visitType,
            'visit_id' => $visitId,
            'completed' => $completed,
            'handled_by_game' => $handledByGame,
            'is_ticket_order_line' => $visit?->subject instanceof EventPurchase && $visit->subject->ticket_order_id !== null,
            'recipient_email_masked' => $recipient ? \App\Services\WaiverProfileService::maskEmail($recipient['email']) : null,
            'max_visit_age_days' => self::MAX_VISIT_AGE_DAYS,
            'thanks_email' => $this->notificationInfo($thanks, $visit, EmailNotification::DEFAULT_THANKS_FOR_PLAYING),
            'review_email' => $this->notificationInfo($review, $visit, EmailNotification::DEFAULT_REVIEW_REQUEST),
            'thanks' => $thanksRows->map($present)->values()->all(),
            'reviews' => $rows->where('kind', VisitFollowUp::KIND_REVIEW)->map($present)->values()->all(),
            'can_send_thanks' => $this->isAvailable()
                && $completed
                && !$handledByGame
                && !($visit?->isGame())
                && $thanks !== null
                && $recipient !== null
                && !$thanksRows->contains(fn (VisitFollowUp $row) => $row->recipient_email === $recipient['email']
                    && in_array($row->status, [VisitFollowUp::STATUS_SENT, VisitFollowUp::STATUS_SENDING], true)),
        ];
    }

    public function gameSummary(EscapeRoomSession $game): array
    {
        $visit = CompletedVisit::fromGame($game);
        $rows = $this->isAvailable()
            ? VisitFollowUp::forVisit(VisitFollowUp::VISIT_ESCAPE_ROOM_GAME, (int) $game->id)->orderBy('id')->get()
            : collect();
        $reviews = $rows->where('kind', VisitFollowUp::KIND_REVIEW);
        $thanks = $visit ? $this->thanksEmailFor($visit) : null;
        $review = $visit ? $this->reviewEmailFor($visit) : null;

        return [
            'available' => $this->isAvailable(),
            'thanks_email' => $this->notificationInfo($thanks, $visit, EmailNotification::DEFAULT_THANKS_FOR_PLAYING),
            'review_email' => $this->notificationInfo($review, $visit, EmailNotification::DEFAULT_REVIEW_REQUEST),
            'thanks_by_waiver' => $rows->where('kind', VisitFollowUp::KIND_THANKS)->whereNotNull('waiver_id')
                ->keyBy('waiver_id')->map->toStaffArray()->all(),
            'reviews_by_waiver' => $reviews->whereNotNull('waiver_id')
                ->sortBy('id')->keyBy('waiver_id')->map->toStaffArray()->all(),
            'thanks_by_email' => $rows->where('kind', VisitFollowUp::KIND_THANKS)
                ->sortBy('id')->keyBy('recipient_email')->map->toStaffArray()->all(),
            'reviews_by_email' => $reviews->sortBy('id')->keyBy('recipient_email')->map->toStaffArray()->all(),
            'contacted_waiver_ids' => $rows->reject->leftGame()->pluck('waiver_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'contacted_emails' => $rows->reject->leftGame()->pluck('recipient_email')->unique()->values()->all(),
            'reviews' => self::reviewCounts($reviews),
        ];
    }

    public function gameReviewCounts(int $gameId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        return self::reviewCounts(
            VisitFollowUp::forVisit(VisitFollowUp::VISIT_ESCAPE_ROOM_GAME, $gameId)
                ->where('kind', VisitFollowUp::KIND_REVIEW)
                ->get(['id', 'status', 'rating', 'due_at'])
        );
    }

    protected static function reviewCounts(Collection $reviews): array
    {
        $rated = $reviews->whereNotNull('rating');

        return [
            'scheduled' => $reviews->where('status', VisitFollowUp::STATUS_SCHEDULED)->count(),
            'sent' => $reviews->where('status', VisitFollowUp::STATUS_SENT)->count(),
            'skipped' => $reviews->whereIn('status', [VisitFollowUp::STATUS_SKIPPED, VisitFollowUp::STATUS_CANCELED])->count(),
            'failed' => $reviews->where('status', VisitFollowUp::STATUS_FAILED)->count(),
            'rated' => $rated->count(),
            'average_rating' => $rated->isEmpty() ? null : round((float) $rated->avg('rating'), 1),
            'next_due_at' => $reviews->where('status', VisitFollowUp::STATUS_SCHEDULED)->sortBy('due_at')->first()?->due_at?->toIso8601String(),
        ];
    }

    public function overridesFor(EmailNotification $notification): array
    {
        if (!$notification->isVisitTrigger()) {
            return [];
        }

        return EmailNotification::with('location:id,name')
            ->where('company_id', $notification->company_id)
            ->where('trigger_type', $notification->trigger_type)
            ->where('is_active', true)
            ->whereKeyNot($notification->id)
            ->get()
            ->filter(fn (EmailNotification $other) => EmailNotification::visitOrder($other, $notification) < 0
                && $other->visitScopeOverlaps($notification))
            ->sort(fn (EmailNotification $a, EmailNotification $b) => EmailNotification::visitOrder($a, $b))
            ->take(20)
            ->map(function (EmailNotification $other) use ($notification) {
                $promo = $other->trigger_type === EmailNotification::TRIGGER_VISIT_COMPLETED && $other->promo_id
                    ? Promo::find($other->promo_id)
                    : null;

                return [
                    'id' => $other->id,
                    'name' => $other->name,
                    'location_name' => $other->location?->name,
                    'promo_code' => $promo?->code,
                    'covers_everything' => $other->visitScopeCovers($notification),
                ];
            })
            ->values()
            ->all();
    }

    public function escapeRoomsWithoutThanks(int $companyId): ?array
    {
        if (!Package::supportsEscapeRoomFlag()) {
            return null;
        }

        $rooms = Package::escapeRooms()
            ->with('location.company')
            ->whereHas('location', fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->get();

        if ($rooms->isEmpty()) {
            return null;
        }

        return $rooms
            ->filter(function (Package $room) use ($companyId) {
                $booking = new Booking(['package_id' => $room->id, 'location_id' => $room->location_id]);
                $booking->setRelation('package', $room);
                $booking->setRelation('location', $room->location);
                $visit = new CompletedVisit(
                    VisitFollowUp::VISIT_BOOKING,
                    $booking,
                    $companyId,
                    $room->location,
                    EmailNotification::ENTITY_PACKAGE,
                    (int) $room->id,
                    (string) $room->name,
                    null,
                    null,
                    null
                );

                return EmailNotification::resolveForVisit($visit, EmailNotification::TRIGGER_VISIT_COMPLETED) === null;
            })
            ->map(fn (Package $room) => trim($room->name . ($room->location?->name ? ' (' . $room->location->name . ')' : '')))
            ->values()
            ->all();
    }

    public function notificationInfo(?EmailNotification $notification, ?CompletedVisit $visit, string $defaultKey): array
    {
        $shown = $notification ?? ($visit
            ? EmailNotification::where('company_id', $visit->companyId)->where('default_key', $defaultKey)->first()
            : null);

        return [
            'active' => $notification !== null,
            'id' => $shown?->id,
            'name' => $shown?->name,
            'hours' => $shown && $shown->trigger_type === EmailNotification::TRIGGER_VISIT_FOLLOWUP
                ? (int) ($shown->send_after_hours ?: EmailNotification::REVIEW_REQUEST_DEFAULT_HOURS)
                : null,
            'promo' => $notification && $visit ? $this->promoInfo($notification, $visit->companyId, $visit->locationId(), $visit->location?->name) : null,
        ];
    }

    public function promoInfo(EmailNotification $notification, int $companyId, ?int $locationId = null, ?string $locationName = null): ?array
    {
        if (!EmailNotification::supportsPromo() || !$notification->promo_id) {
            return null;
        }

        $promo = $notification->relationLoaded('promo') ? $notification->promo : Promo::with('creator')->find($notification->promo_id);

        if (!$promo) {
            return [
                'id' => $notification->promo_id,
                'code' => null,
                'offer' => null,
                'name' => null,
                'ends_on' => null,
                'terms' => '',
                'location_note' => null,
                'problem' => 'The chosen promo code no longer exists, so no code is shown in the email. Pick another one.',
            ];
        }

        return [
            'id' => $promo->id,
            'code' => $promo->code,
            'offer' => $promo->offerLabel(),
            'name' => $promo->name,
            'ends_on' => $promo->end_date?->toDateString(),
            'terms' => self::promoTerms($promo),
            'location_note' => $locationId === null ? self::promoLocationNote($promo, $companyId) : null,
            'problem' => self::promoProblem($promo, $companyId, $locationId, $locationName),
        ];
    }

    public static function promoProblem(Promo $promo, int $companyId, ?int $locationId = null, ?string $locationName = null): ?string
    {
        if ($promo->deleted) {
            return 'This promo code was deleted, so no code is shown in the email.';
        }
        if (!$promo->belongsToCompany($companyId)) {
            return 'This promo code belongs to another company.';
        }
        if ($promo->code_mode === 'unique' || $promo->batch_id) {
            return 'This code comes from a bulk batch, where each code works only once. Pick a shared code instead.';
        }
        if ($promo->status !== 'active') {
            return 'This promo code is ' . ($promo->status ?: 'inactive') . ', so no code is shown in the email.';
        }
        if (!$promo->hasStarted()) {
            return 'This promo code does not start until ' . $promo->start_date->format('M j, Y') . ', so no code is shown before then.';
        }
        if ($promo->isExpired()) {
            return 'This promo code expired on ' . $promo->end_date->format('M j, Y') . ', so no code is shown in the email.';
        }
        if ($promo->isUsedUp()) {
            return 'This promo code has been used the maximum number of times, so no code is shown in the email.';
        }
        if ($locationId !== null && !$promo->appliesToLocation($locationId)) {
            return 'This promo code does not work at ' . ($locationName ?: 'this location') . ', so no code is shown in emails for it.';
        }

        return null;
    }

    public static function promoTerms(Promo $promo): string
    {
        $items = collect()
            ->merge(!empty($promo->package_ids) ? Package::whereIn('id', $promo->package_ids)->orderBy('name')->pluck('name') : [])
            ->merge(!empty($promo->attraction_ids) ? Attraction::whereIn('id', $promo->attraction_ids)->orderBy('name')->pluck('name') : [])
            ->merge(!empty($promo->event_ids) ? Event::whereIn('id', $promo->event_ids)->orderBy('name')->pluck('name') : [])
            ->filter()
            ->unique()
            ->values();
        $places = !$promo->isAllLocations()
            ? Location::whereIn('id', $promo->location_ids)->orderBy('name')->pluck('name')->filter()->values()
            : collect();
        $parts = [];

        if ($items->isNotEmpty()) {
            $parts[] = 'on ' . self::listNames($items);
        }

        if ($places->isNotEmpty()) {
            $parts[] = 'at ' . self::listNames($places) . ($places->count() === 1 ? ' only' : '');
        }

        return $parts === [] ? '' : 'Valid ' . implode(' ', $parts) . '.';
    }

    public static function promoLocationNote(Promo $promo, int $companyId): ?string
    {
        if ($promo->isAllLocations()) {
            return null;
        }

        $all = Location::where('company_id', $companyId)->count();
        $covered = Location::where('company_id', $companyId)->whereIn('id', $promo->location_ids)->count();

        return $covered < $all
            ? sprintf('This code works at %d of your %d locations. Guests of the other locations get this email without a code.', $covered, $all)
            : null;
    }

    protected static function listNames(Collection $names): string
    {
        $names = $names->values();

        if ($names->count() > 4) {
            return $names->take(3)->implode(', ') . ' and ' . ($names->count() - 3) . ' more';
        }

        return $names->count() > 1
            ? $names->slice(0, -1)->implode(', ') . ' and ' . $names->last()
            : (string) $names->first();
    }

    public function usablePromo(EmailNotification $notification, CompletedVisit $visit): ?Promo
    {
        if (!EmailNotification::supportsPromo() || !$notification->promo_id) {
            return null;
        }

        $promo = Promo::with('creator')->find($notification->promo_id);
        $problem = $promo
            ? self::promoProblem($promo, $visit->companyId, $visit->locationId(), $visit->location?->name)
            : 'The chosen promo code no longer exists.';

        if ($problem === null) {
            return $promo;
        }

        Log::warning('Visit follow-up email sent without its promo code', [
            'email_notification_id' => $notification->id,
            'promo_id' => $notification->promo_id,
            'reason' => $problem,
        ]);

        $this->alertStaffOfDroppedPromo($notification, $promo, $visit, $problem);

        return null;
    }

    public function photoSessionForVisit(CompletedVisit $visit): ?PhotoSession
    {
        if (!$visit->subject instanceof Booking) {
            return null;
        }

        $bookingId = (int) $visit->subject->id;

        $session = PhotoSession::whereNull('purged_at')
            ->where('location_id', $visit->locationId())
            ->whereHas('waivers', fn ($query) => $query->where('waivers.booking_id', $bookingId))
            ->whereDoesntHave('waivers', fn ($query) => $query->where(fn ($other) => $other
                ->whereNull('waivers.booking_id')
                ->orWhere('waivers.booking_id', '!=', $bookingId)))
            ->when(EscapeRoomSession::isAvailable(), fn ($query) => $query->whereDoesntHave('escapeRoomSession'))
            ->orderByDesc('id')
            ->first();

        return $session && $session->accessIsActive() && $session->photos()->ready()->exists() ? $session : null;
    }

    public function gamePhotoEmail(PhotoSession $session, PhotoDelivery $delivery): array
    {
        $game = $session->linkedEscapeRoomSession();
        $visit = $game ? CompletedVisit::fromGame($game) : null;

        if (!$visit) {
            throw new \RuntimeException('This photo is not linked to an escape-room game.');
        }

        $notification = $this->thanksEmailFor($visit);

        if (!$notification) {
            throw new \RuntimeException('The Thanks for Playing email is switched off in Email Notifications, so the photo was not sent.');
        }

        return $this->build(
            $notification,
            $visit,
            ['email' => $delivery->destination, 'name' => $delivery->recipient_name, 'waiver_id' => $delivery->waiver_id],
            null,
            $session,
            $delivery
        );
    }

    public function build(
        EmailNotification $notification,
        CompletedVisit $visit,
        array $recipient,
        ?VisitFollowUp $row,
        ?PhotoSession $photoSession,
        ?PhotoDelivery $delivery = null
    ): array {
        [$text, $html, $attachments] = $this->content($notification, $visit, $recipient, $row, $photoSession, $delivery);

        $body = $this->ensureEssentials(self::dropEmptyBlocks($notification->getEffectiveBody(), $text), $notification, $html, $text);

        return $this->assemble($notification->getEffectiveSubject(), $body, $text, $html, $attachments) + [
            'notification' => $notification,
            'from_name' => $this->fromName($notification, $visit->location),
        ];
    }

    public function preview(EmailNotification $notification, ?string $subject = null, ?string $body = null, array $params = []): array
    {
        [$text, $html, $location] = $this->sampleContent($notification, $params);
        $body = $this->ensureEssentials(self::dropEmptyBlocks($body ?? $notification->getEffectiveBody(), $text), $notification, $html, $text);

        return $this->assemble($subject ?? $notification->getEffectiveSubject(), $body, $text, $html, []) + [
            'from_name' => $this->fromName($notification, $location, null, array_key_exists('from_name', $params) ? (string) ($params['from_name'] ?? '') : null),
        ];
    }

    protected function assemble(string $subject, string $body, array $text, array $html, array $attachments): array
    {
        $escaped = array_map(fn ($value) => e((string) $value), $text);
        $rendered = self::render($body, $html + $escaped, false);
        $subject = trim((string) preg_replace('/\s{2,}/', ' ', self::render($subject, $text + array_fill_keys(self::HTML_SECTIONS, ''), false)));

        return [
            'subject' => $subject,
            'body' => $rendered,
            'html' => $this->emails->generateHtmlEmail($rendered),
            'attachments' => array_values(array_filter(array_map(function (array $attachment) use ($rendered) {
                $fallback = !empty($attachment['fallback_attachment']);
                unset($attachment['fallback_attachment']);

                if (empty($attachment['content_id']) || str_contains($rendered, 'cid:' . $attachment['content_id'])) {
                    return $attachment;
                }

                unset($attachment['content_id']);

                return $fallback ? $attachment : null;
            }, $attachments))),
            'variables' => $text,
        ];
    }

    public function fromName(?EmailNotification $notification, ?Location $location, ?Company $company = null, ?string $override = null): string
    {
        $custom = trim((string) ($override ?? ($notification && EmailNotification::supportsFollowUpSettings() ? $notification->from_name : '')));

        if ($custom !== '') {
            return $custom;
        }

        $company ??= $location?->company ?? ($notification ? Company::find($notification->company_id) : null);

        return (string) ($company?->company_name ?: config('mail.from.name'));
    }

    public function reviewLinkFor(?EmailNotification $notification, ?Location $location): string
    {
        $custom = $notification && EmailNotification::supportsFollowUpSettings() ? trim((string) $notification->review_url) : '';

        return $custom !== '' ? $custom : (string) ($location?->review_url ?? '');
    }

    public static function when(?string $date, ?string $time): string
    {
        if (!$date) {
            return '';
        }

        $label = Carbon::parse($date)->format('F j, Y');

        return $time ? $label . ' at ' . Carbon::createFromFormat('H:i', $time)->format('g:i A') : $label;
    }

    public static function render(string $template, array $values, bool $keepUnknown = true): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $match) use ($values, $keepUnknown) {
            if (array_key_exists($match[1], $values)) {
                return (string) ($values[$match[1]] ?? '');
            }

            return $keepUnknown ? $match[0] : '';
        }, $template);
    }

    public static function dropEmptyBlocks(string $body, array $text): string
    {
        $optional = array_intersect_key($text, array_flip(self::OPTIONAL_TEXT));
        $empty = array_keys(array_filter($optional, fn ($value) => trim((string) $value) === ''));

        if ($empty === []) {
            return $body;
        }

        $emptyPattern = '/\{\{\s*(?:' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $empty)) . ')\s*\}\}/';
        $filledNames = array_values(array_diff(array_keys($optional), $empty));
        $filledPattern = $filledNames === []
            ? null
            : '/\{\{\s*(?:' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $filledNames)) . ')\s*\}\}/';

        foreach (['p', 'li'] as $tag) {
            $body = (string) preg_replace_callback(
                '/<' . $tag . '\b[^>]*>(?:(?!<\/?' . $tag . '\b).)*<\/' . $tag . '>/is',
                fn (array $match) => preg_match($emptyPattern, $match[0]) && !($filledPattern && preg_match($filledPattern, $match[0]))
                    ? ''
                    : $match[0],
                $body
            );
        }

        return $body;
    }

    protected function content(
        EmailNotification $notification,
        CompletedVisit $visit,
        array $recipient,
        ?VisitFollowUp $row,
        ?PhotoSession $photoSession,
        ?PhotoDelivery $delivery
    ): array {
        $location = $visit->location;
        $company = $location?->company;
        $tz = OperatingDay::timezoneFor($location);
        $name = trim((string) ($recipient['name'] ?? ''));
        $first = $name !== '' ? explode(' ', $name)[0] : 'there';
        $game = $visit->game;
        $email = (string) ($recipient['email'] ?? '');

        $text = array_merge($this->emails->buildCommonVariables($location, $company), [
            'customer_name' => $name !== '' ? $name : 'there',
            'customer_first_name' => $first,
            'customer_email' => $email,
            'first_name' => $first,
            'activity_name' => $visit->activityName,
            'room_name' => $game ? $visit->activityName : '',
            'visit_date' => $visit->date ? Carbon::parse($visit->date)->format('F j, Y') : '',
            'visit_time' => $visit->time ? Carbon::createFromFormat('H:i', $visit->time)->format('g:i A') : '',
            'visit_when' => self::when($visit->date, $visit->time),
            'booking_reference' => (string) ($visit->reference ?? ''),
            'completion_time' => $game?->completionLabel() ?? '',
            'escape_result' => $game?->resultLabel() ?? '',
            'photo_link' => '',
            'photos_line' => '',
            'photo_count' => '0',
            'expires_on' => '',
            'promo_code' => '',
            'promo_offer' => '',
            'promo_name' => '',
            'promo_description' => '',
            'promo_expires' => '',
            'promo_terms' => '',
            'review_link' => $this->reviewLinkFor($notification, $location),
            'rating_link' => $row?->token ? $row->feedbackUrl() : '',
            'opt_out_link' => $row?->token
                ? $row->feedbackUrl() . '?unsubscribe=1'
                : ($email !== '' ? $this->optOutUrl($visit->companyId, $email, (int) $notification->id) : ''),
            'business_name' => $company?->company_name ?? '',
            'session_date' => $visit->date ? Carbon::parse($visit->date)->format('M j, Y') : '',
            'session_time' => $visit->time ? Carbon::createFromFormat('H:i', $visit->time)->format('g:i A') : '',
        ]);

        $html = array_fill_keys(self::HTML_SECTIONS, '');
        $attachments = [];

        if ($game && $game->completed_at !== null && $text['escape_result'] !== '') {
            $html['game_result_section'] = self::gameResultHtml($text['escape_result']);
        }

        if ($photoSession) {
            [$photoText, $photoHtml, $photoAttachments] = $this->photoParts($photoSession, $visit, $delivery);
            $text = array_merge($text, $photoText);
            $html['group_photo_section'] = $photoHtml;
            $attachments = $photoAttachments;
        }

        if ($notification->trigger_type === EmailNotification::TRIGGER_VISIT_COMPLETED
            && !$this->marketingBlock($visit->companyId, $email, $recipient['waiver_id'] ?? null)
            && ($promo = $this->usablePromo($notification, $visit))) {
            $text = array_merge($text, self::promoText($promo, $tz));
            $html['promo_section'] = self::promoHtml($text);
        }

        if ($notification->trigger_type === EmailNotification::TRIGGER_VISIT_FOLLOWUP) {
            if ($row?->token) {
                $html['rating_section'] = self::ratingHtml($visit->activityName, fn (int $stars) => $row->feedbackUrl($stars));
            }

            if ($text['review_link'] !== '') {
                $html['review_section'] = self::reviewHtml($text['review_link']);
            }
        }

        return [$text, $html, $attachments];
    }

    protected function photoParts(PhotoSession $session, CompletedVisit $visit, ?PhotoDelivery $delivery): array
    {
        $deliveries = app(PhotoDeliveryService::class);
        $session->loadMissing('location.company');
        $variables = $deliveries->variables($session, $delivery);
        $attachments = $deliveries->photoAttachments($session, $visit->activityName);

        $text = [
            'photo_link' => $variables['photo_link'] ?? '',
            'expires_on' => $variables['expires_on'] ?? '',
            'photo_count' => (string) count($attachments),
            'photos_line' => count($attachments) > 1
                ? 'Your ' . count($attachments) . ' group photos are in this email. You can also view and download them here:'
                : 'Your group photo is in this email. You can also view and download it here:',
        ];

        if ($attachments === []) {
            return [$text, self::photoLinkHtml($text['photo_link'], $text['expires_on'], null, 'View your photos'), []];
        }

        $cid = 'group-photo-' . $session->id . '-' . Str::lower(Str::random(10)) . '@zapzone';
        $attachments[0] += ['content_id' => $cid, 'fallback_attachment' => true];

        return [
            $text,
            self::photoLinkHtml($text['photo_link'], $text['expires_on'], $cid, 'View and download your photos', $text['photos_line']),
            $attachments,
        ];
    }

    protected function ensureEssentials(string $body, EmailNotification $notification, array $html, array $text = []): string
    {
        $has = fn (array $names) => (bool) preg_match('/\{\{\s*(' . implode('|', $names) . ')\s*\}\}/', $body);
        $isThanks = $notification->trigger_type === EmailNotification::TRIGGER_VISIT_COMPLETED;
        $isReview = $notification->trigger_type === EmailNotification::TRIGGER_VISIT_FOLLOWUP;

        $sections = $isThanks
            ? [
                'game_result_section' => ['game_result_section', 'escape_result', 'completion_time'],
                'group_photo_section' => ['group_photo_section', 'photo_link'],
                'promo_section' => ['promo_section', 'promo_code'],
            ]
            : ($isReview
                ? [
                    'rating_section' => ['rating_section', 'rating_link'],
                    'review_section' => ['review_section', 'review_link'],
                ]
                : []);

        $order = array_keys($sections);

        foreach ($sections as $section => $names) {
            if (($html[$section] ?? '') === '' || $has($names)) {
                continue;
            }

            $body = self::insertSection($body, $section, $order);
        }

        $commercial = $isThanks && ($html['promo_section'] ?? '') !== '';

        if (($commercial || $isReview) && !$has(['opt_out_link'])) {
            $body .= "\n" . '<p style="max-width: 600px; margin: 16px auto 0; text-align: center; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 11px; color: #9ca3af;">'
                . ($isReview ? 'Don&#039;t want review requests?' : 'Don&#039;t want offers like this?')
                . ' <a href="{{opt_out_link}}" style="color: #6b7280;">Unsubscribe</a>.</p>';
        }

        if ($commercial && trim((string) ($text['location_address'] ?? '')) !== '' && !$has(['location_address'])) {
            $body .= "\n" . '<p style="max-width: 600px; margin: 6px auto 0; text-align: center; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 11px; color: #9ca3af;">{{location_name}} &middot; {{location_address}}</p>';
        }

        return $body;
    }

    protected static function insertSection(string $body, string $section, array $order): string
    {
        $placeholder = '{{' . $section . '}}';
        $index = array_search($section, $order, true);
        $position = fn (string $name) => preg_match('/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/', $body, $match, PREG_OFFSET_CAPTURE)
            ? [$match[0][1], strlen($match[0][0])]
            : null;

        foreach (array_slice($order, $index + 1) as $later) {
            if ($found = $position($later)) {
                return substr($body, 0, $found[0]) . $placeholder . "\n" . substr($body, $found[0]);
            }
        }

        foreach (array_reverse(array_slice($order, 0, $index)) as $earlier) {
            if ($found = $position($earlier)) {
                $end = $found[0] + $found[1];

                return substr($body, 0, $end) . "\n" . $placeholder . substr($body, $end);
            }
        }

        preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $body, $matches, PREG_OFFSET_CAPTURE);
        $paragraphs = $matches[0];
        $content = array_values(array_filter($paragraphs, fn (array $paragraph) => !self::isFooterParagraph($paragraph[0])));

        if (count($content) >= 2) {
            $at = $content[count($content) - 1][1];

            return substr($body, 0, $at) . $placeholder . "\n" . substr($body, $at);
        }

        if (count($content) === 1) {
            $end = $content[0][1] + strlen($content[0][0]);

            return substr($body, 0, $end) . "\n" . $placeholder . substr($body, $end);
        }

        if ($paragraphs !== []) {
            $at = $paragraphs[0][1];

            return substr($body, 0, $at) . $placeholder . "\n" . substr($body, $at);
        }

        return $body . "\n" . $placeholder;
    }

    protected static function isFooterParagraph(string $paragraph): bool
    {
        return (bool) preg_match('/&copy;|©|\{\{\s*current_year\s*\}\}|rights reserved|\{\{\s*opt_out_link\s*\}\}|unsubscribe/i', $paragraph);
    }

    public static function promoText(Promo $promo, string $tz): array
    {
        return [
            'promo_code' => (string) $promo->code,
            'promo_offer' => $promo->offerLabel(),
            'promo_name' => (string) ($promo->name ?? ''),
            'promo_description' => (string) ($promo->description ?: ($promo->name ?? '')),
            'promo_expires' => $promo->end_date ? 'Valid until ' . $promo->end_date->format('F j, Y') : '',
            'promo_terms' => self::promoTerms($promo),
        ];
    }

    public static function gameResultHtml(string $result): string
    {
        $result = e($result);

        return <<<HTML
<div style="margin: 24px 0; padding: 18px 20px; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; text-align: center;">
    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #4338ca;">Your result</p>
    <p style="margin: 0; font-size: 20px; font-weight: 700; color: #1e1b4b;">{$result}</p>
</div>
HTML;
    }

    public static function photoLinkHtml(string $link, string $expiresOn, ?string $cid, string $buttonLabel, string $line = ''): string
    {
        $image = $cid
            ? '<img src="cid:' . e($cid) . '" alt="Your group photo" width="536" style="display: block; width: 100%; max-width: 536px; height: auto; margin: 0 auto 14px auto; border-radius: 8px; border: 1px solid #e5e7eb;">'
            : '';
        $lineHtml = $line !== '' ? '<p style="margin: 0 0 14px 0; font-size: 14px; color: #374151; line-height: 1.5;">' . e($line) . '</p>' : '';
        $button = $link !== ''
            ? '<a href="' . e($link) . '" style="display: inline-block; background-color: #1e40af; color: #ffffff; padding: 11px 26px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">' . e($buttonLabel) . '</a>'
            : '';
        $expiry = $expiresOn !== '' ? '<p style="margin: 10px 0 0 0; font-size: 12px; color: #6b7280;">This link works until ' . e($expiresOn) . '.</p>' : '';

        return <<<HTML
<div style="margin: 24px 0; text-align: center;">
    {$image}
    {$lineHtml}
    {$button}
    {$expiry}
</div>
HTML;
    }

    public static function promoHtml(array $text): string
    {
        $offer = e($text['promo_offer'] ?? '');
        $code = e($text['promo_code'] ?? '');
        $description = ($text['promo_description'] ?? '') !== '' ? '<p style="margin: 10px 0 0 0; font-size: 13px; color: #713f12;">' . e($text['promo_description']) . '</p>' : '';
        $terms = ($text['promo_terms'] ?? '') !== '' ? '<p style="margin: 6px 0 0 0; font-size: 12px; color: #854d0e;">' . e($text['promo_terms']) . '</p>' : '';
        $expires = ($text['promo_expires'] ?? '') !== '' ? '<p style="margin: 6px 0 0 0; font-size: 12px; color: #854d0e;">' . e($text['promo_expires']) . '</p>' : '';

        return <<<HTML
<div style="margin: 24px 0; padding: 22px 20px; background: #fefce8; border: 2px dashed #ca8a04; border-radius: 10px; text-align: center;">
    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #854d0e;">A thank-you for your next visit</p>
    <p style="margin: 0 0 12px 0; font-size: 22px; font-weight: 700; color: #713f12;">{$offer}</p>
    <p style="margin: 0 0 6px 0; font-size: 13px; color: #713f12;">Use code</p>
    <p style="margin: 0; display: inline-block; padding: 8px 18px; background: #ffffff; border: 1px solid #fde68a; border-radius: 6px; font-family: 'Courier New', Courier, monospace; font-size: 22px; font-weight: 700; letter-spacing: 2px; color: #111827;">{$code}</p>
    {$description}
    {$terms}
    {$expires}
</div>
HTML;
    }

    public static function ratingHtml(string $activityName, callable $linkFor): string
    {
        $activity = e($activityName);
        $cells = '';

        foreach ([1, 2, 3, 4, 5] as $stars) {
            $link = e($linkFor($stars));
            $cells .= "\n        " . '<td style="padding: 0 4px; text-align: center;">'
                . "\n            " . '<a href="' . $link . '" title="' . $stars . ' out of 5" style="display: block; text-decoration: none; font-size: 34px; line-height: 1; color: #f59e0b;">&#9733;</a>'
                . "\n            " . '<span style="display: block; margin-top: 4px; font-size: 11px; color: #6b7280;">' . $stars . '</span>'
                . "\n        " . '</td>';
        }

        return <<<HTML
<div style="margin: 24px 0; text-align: center;">
    <p style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">How would you rate {$activity}?</p>
    <table role="presentation" align="center" cellpadding="0" cellspacing="0" style="margin: 0 auto;"><tr>{$cells}
    </tr></table>
    <p style="margin: 10px 0 0 0; font-size: 12px; color: #6b7280;">Tap a star: 1 is poor, 5 is amazing.</p>
</div>
HTML;
    }

    public static function reviewHtml(string $link): string
    {
        $link = e($link);

        return <<<HTML
<div style="margin: 24px 0; text-align: center;">
    <p style="margin: 0 0 12px 0; font-size: 14px; color: #374151; line-height: 1.5;">Had a great time? A quick public review helps other groups find us.</p>
    <a href="{$link}" style="display: inline-block; background-color: #059669; color: #ffffff; padding: 11px 26px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">Leave a review</a>
</div>
HTML;
    }

    public function sampleVariables(EmailNotification $notification, array $params = []): array
    {
        [$text, $html] = $this->sampleContent($notification, $params);

        return array_merge(array_map(fn ($value) => e((string) $value), $text), $html);
    }

    protected function sampleContent(EmailNotification $notification, array $params = []): array
    {
        $company = $notification->company ?? Company::find($notification->company_id);
        $location = $notification->location ?? $company?->locations()->orderBy('id')->first();
        $tz = OperatingDay::timezoneFor($location);
        $isReview = $notification->trigger_type === EmailNotification::TRIGGER_VISIT_FOLLOWUP;
        $sampleLink = rtrim((string) config('app.frontend_url'), '/') . '/feedback/sample';
        [$activity, $isGame] = $this->sampleActivity($notification, $company);
        $reviewLink = array_key_exists('review_url', $params)
            ? (trim((string) $params['review_url']) ?: (string) ($location?->review_url ?? ''))
            : $this->reviewLinkFor($notification, $location);

        $text = array_merge($this->emails->buildCommonVariables($location, $company), [
            'customer_name' => 'Jordan Rivera',
            'customer_first_name' => 'Jordan',
            'customer_email' => 'jordan@example.com',
            'first_name' => 'Jordan',
            'activity_name' => $activity,
            'room_name' => $isGame ? $activity : '',
            'visit_date' => now($tz)->format('F j, Y'),
            'visit_time' => '3:00 PM',
            'visit_when' => now($tz)->format('F j, Y') . ' at 3:00 PM',
            'booking_reference' => $isGame ? '' : 'BK-SAMPLE',
            'completion_time' => $isGame ? '47:12' : '',
            'escape_result' => $isGame ? 'Your group escaped in 47:12!' : '',
            'photo_link' => rtrim((string) config('app.frontend_url'), '/') . '/photos/sample-link',
            'photos_line' => 'Your group photo is in this email. You can also view and download it here:',
            'photo_count' => '1',
            'expires_on' => now($tz)->addDays(PhotoSession::ACCESS_VALID_DAYS)->format('M j, Y'),
            'promo_code' => '',
            'promo_offer' => '',
            'promo_name' => '',
            'promo_description' => '',
            'promo_expires' => '',
            'promo_terms' => '',
            'review_link' => $reviewLink,
            'rating_link' => $sampleLink,
            'opt_out_link' => $sampleLink . '?unsubscribe=1',
            'business_name' => $company?->company_name ?? '',
            'session_date' => now($tz)->format('M j, Y'),
            'session_time' => '3:00 PM',
        ]);

        $html = array_fill_keys(self::HTML_SECTIONS, '');

        if ($isReview) {
            $html['rating_section'] = self::ratingHtml($text['activity_name'], fn (int $stars) => $sampleLink . '?rating=' . $stars);
            $html['review_section'] = $text['review_link'] !== ''
                ? self::reviewHtml($text['review_link'])
                : '<div style="margin: 24px 0; padding: 12px; border: 1px dashed #d1d5db; border-radius: 8px; text-align: center; font-size: 12px; color: #6b7280;">The &ldquo;Leave a review&rdquo; button appears here when this email or the location has a review link.</div>';
        } else {
            if ($isGame) {
                $html['game_result_section'] = self::gameResultHtml($text['escape_result']);
            }

            $html['group_photo_section'] = '<div style="margin: 24px 0; padding: 48px 12px; background: #f3f4f6; border: 1px dashed #9ca3af; border-radius: 8px; text-align: center; font-size: 13px; color: #6b7280;">The group photo appears here when staff took one.</div>'
                . self::photoLinkHtml($text['photo_link'], $text['expires_on'], null, 'View and download your photos', $text['photos_line']);

            $promoId = array_key_exists('promo_id', $params) ? $params['promo_id'] : $notification->promo_id;
            $promo = $promoId ? Promo::with('creator')->find((int) $promoId) : null;

            if ($promo && $company && self::promoProblem($promo, (int) $company->id, $notification->location_id ? (int) $notification->location_id : null, $notification->location?->name) === null) {
                $text = array_merge($text, self::promoText($promo, $tz));
                $html['promo_section'] = self::promoHtml($text);
            }
        }

        return [$text, $html, $location];
    }

    protected function sampleActivity(EmailNotification $notification, ?Company $company): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($notification->entity_ids ?? []))));
        $filter = EmailNotification::supportsFollowUpSettings() ? $notification->activity_filter : null;

        if ($notification->entity_type === EmailNotification::ENTITY_EVENT) {
            $name = $ids !== [] ? Event::whereIn('id', $ids)->orderBy('name')->value('name') : null;

            return [$name ?: 'Your Event', false];
        }

        if ($notification->entity_type === EmailNotification::ENTITY_PACKAGE && $ids !== []) {
            $packages = Package::whereIn('id', $ids)->orderBy('name')->get();
            $rooms = $packages->filter(fn (Package $package) => $package->isEscapeRoom());
            $pick = $filter === EmailNotification::ACTIVITY_NOT_ESCAPE_ROOM
                ? $packages->reject(fn (Package $package) => $package->isEscapeRoom())->first()
                : ($rooms->first() ?? $packages->first());

            return [$pick?->name ?: 'Your Visit', $pick?->isEscapeRoom() ?? false];
        }

        if ($filter === EmailNotification::ACTIVITY_NOT_ESCAPE_ROOM) {
            return ['Birthday Party', false];
        }

        $room = $company && Package::supportsEscapeRoomFlag()
            ? Package::escapeRooms()->whereHas('location', fn ($query) => $query->where('company_id', $company->id))->orderBy('name')->value('name')
            : null;

        if ($room || $filter === EmailNotification::ACTIVITY_ESCAPE_ROOM) {
            return [$room ?: 'Escape Room', true];
        }

        return ['Birthday Party', false];
    }

    public function findByToken(string $token): ?VisitFollowUp
    {
        if (!$this->isAvailable() || strlen($token) < 20 || strlen($token) > 64) {
            return null;
        }

        return VisitFollowUp::where('token', $token)
            ->where('kind', VisitFollowUp::KIND_REVIEW)
            ->first();
    }

    public function publicPayload(VisitFollowUp $row): array
    {
        $visit = CompletedVisit::find($row->visit_type, (int) $row->visit_id);
        $location = $visit?->location ?? $row->location;
        $notification = $row->email_notification_id ? EmailNotification::find($row->email_notification_id) : null;
        $reviewLink = $this->reviewLinkFor($notification, $location);

        return [
            'opt_out_only' => false,
            'activity_name' => $visit?->activityName,
            'location_name' => $location?->name,
            'company_name' => $location?->company?->company_name,
            'brand_name' => $this->fromName($notification, $location),
            'visit_date' => $visit?->date,
            'first_name' => $row->recipient_name ? explode(' ', trim($row->recipient_name))[0] : null,
            'rating' => $row->rating,
            'comment' => $row->comment,
            'rated_at' => $row->rated_at?->toIso8601String(),
            'review_url' => $reviewLink !== '' ? $reviewLink : null,
            'unsubscribed' => FollowUpOptOut::hasOptedOut((int) $row->company_id, $row->recipient_email),
        ];
    }

    public function rate(VisitFollowUp $row, int $rating, ?string $comment): VisitFollowUp
    {
        $previousRating = $row->rating;
        $previousComment = $row->comment;
        $comment = $comment !== null ? trim(mb_substr($comment, 0, self::MAX_COMMENT_LENGTH)) : $row->comment;

        $row->forceFill([
            'rating' => max(1, min(5, $rating)),
            'comment' => $comment !== '' ? $comment : null,
            'rated_at' => now(),
        ])->save();

        $changed = $row->rating !== $previousRating || $row->comment !== $previousComment;

        if ($row->alert_notification_id) {
            if ($changed) {
                $this->refreshLowRatingAlert($row);
            }
        } elseif ($row->rating <= self::LOW_RATING && ($id = $this->alertStaffOfLowRating($row))) {
            $claimed = VisitFollowUp::whereKey($row->id)->whereNull('alert_notification_id')->update(['alert_notification_id' => $id]);

            if ($claimed === 1) {
                $row->alert_notification_id = $id;
            } else {
                Notification::whereKey($id)->delete();
            }
        }

        return $row;
    }

    public function unsubscribe(VisitFollowUp $row): void
    {
        $this->unsubscribeEmail((int) $row->company_id, (string) $row->recipient_email, (int) $row->id);
    }

    public function unsubscribeEmail(int $companyId, string $email, ?int $rowId = null): void
    {
        $email = FollowUpOptOut::normalize($email);

        if (!FollowUpOptOut::isAvailable() || !$this->validEmail($email)) {
            return;
        }

        try {
            FollowUpOptOut::firstOrCreate(
                ['company_id' => $companyId, 'email' => $email],
                ['visit_follow_up_id' => $rowId]
            );
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        if ($this->isAvailable()) {
            VisitFollowUp::where('company_id', $companyId)
                ->where('kind', VisitFollowUp::KIND_REVIEW)
                ->where('recipient_email', $email)
                ->where('status', VisitFollowUp::STATUS_SCHEDULED)
                ->update([
                    'status' => VisitFollowUp::STATUS_SKIPPED,
                    'error' => 'This guest unsubscribed from review requests and offers.',
                    'reason' => VisitFollowUp::REASON_OPTED_OUT,
                    'updated_at' => now(),
                ]);
        }

        try {
            Contact::where('company_id', $companyId)->where('email', $email)->where('status', 'active')->update(['status' => 'inactive']);
        } catch (\Throwable $e) {
            Log::warning('Could not mark the unsubscribed guest inactive in Contacts', ['company_id' => $companyId, 'error' => $e->getMessage()]);
        }
    }

    public function optOutUrl(int $companyId, string $email, ?int $notificationId = null): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/feedback/' . $this->optOutToken($companyId, $email, $notificationId) . '?unsubscribe=1';
    }

    public function optOutToken(int $companyId, string $email, ?int $notificationId = null): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([$companyId, FollowUpOptOut::normalize($email), $notificationId])), '+/', '-_'), '=');

        return self::OPT_OUT_PREFIX . $payload . '.' . $this->optOutSignature($payload);
    }

    public function parseOptOutToken(string $token): ?array
    {
        if (!str_starts_with($token, self::OPT_OUT_PREFIX) || strlen($token) > 700) {
            return null;
        }

        [$payload, $signature] = array_pad(explode('.', substr($token, strlen(self::OPT_OUT_PREFIX)), 2), 2, '');

        if ($payload === '' || !hash_equals($this->optOutSignature($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (!is_array($data) || !is_int($data[0] ?? null) || !is_string($data[1] ?? null) || !$this->validEmail($data[1])) {
            return null;
        }

        return [
            'company_id' => $data[0],
            'email' => $data[1],
            'notification_id' => is_int($data[2] ?? null) ? $data[2] : null,
        ];
    }

    public function optOutPayload(array $optOut): array
    {
        $company = Company::find($optOut['company_id']);
        $notification = $optOut['notification_id']
            ? EmailNotification::where('company_id', $optOut['company_id'])->find($optOut['notification_id'])
            : null;

        return [
            'opt_out_only' => true,
            'activity_name' => null,
            'location_name' => null,
            'company_name' => $company?->company_name,
            'brand_name' => $this->fromName($notification, null, $company),
            'visit_date' => null,
            'first_name' => null,
            'rating' => null,
            'comment' => null,
            'rated_at' => null,
            'review_url' => null,
            'unsubscribed' => FollowUpOptOut::hasOptedOut($optOut['company_id'], $optOut['email']),
        ];
    }

    protected function optOutSignature(string $payload): string
    {
        return substr(hash_hmac('sha256', 'visit-opt-out|' . $payload, (string) config('app.key')), 0, 32);
    }

    public function adminPath(string $visitType, int $visitId): string
    {
        if ($visitType === VisitFollowUp::VISIT_ESCAPE_ROOM_GAME) {
            $game = EscapeRoomSession::isAvailable() ? EscapeRoomSession::find($visitId) : null;

            return '/photos/escape-rooms?' . http_build_query(array_filter(['date' => $game?->dateKey(), 'session' => $visitId]));
        }

        return $visitType === VisitFollowUp::VISIT_EVENT_PURCHASE ? '/events/purchases/' . $visitId : '/bookings/' . $visitId;
    }

    protected function lowRatingMessage(VisitFollowUp $row, ?int $previous = null): string
    {
        $visit = CompletedVisit::find($row->visit_type, (int) $row->visit_id);

        return sprintf(
            '%s %s %s %d out of 5.%s',
            $row->recipient_name ?: 'A guest',
            $previous !== null ? 'changed their rating of' : 'rated',
            $visit?->activityName ?? 'their visit',
            $row->rating,
            $row->comment ? ' "' . Str::limit($row->comment, 160) . '"' : ''
        );
    }

    protected function alertStaffOfLowRating(VisitFollowUp $row): ?int
    {
        try {
            return (int) Notification::create([
                'location_id' => $row->location_id,
                'type' => 'customer',
                'priority' => 'high',
                'title' => 'Low rating from a guest',
                'message' => $this->lowRatingMessage($row),
                'status' => 'unread',
                'action_url' => $this->adminPath($row->visit_type, (int) $row->visit_id),
                'action_text' => 'Open visit',
                'metadata' => ['visit_follow_up_id' => $row->id, 'rating' => $row->rating],
            ])->id;
        } catch (\Throwable $e) {
            Log::warning('Could not record the low-rating alert', ['visit_follow_up_id' => $row->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function refreshLowRatingAlert(VisitFollowUp $row): void
    {
        try {
            $alert = Notification::find($row->alert_notification_id);

            if (!$alert) {
                return;
            }

            $alert->update([
                'message' => $this->lowRatingMessage($row, 1),
                'priority' => $row->rating <= self::LOW_RATING ? 'high' : 'low',
                'status' => $row->rating <= self::LOW_RATING ? 'unread' : $alert->status,
                'metadata' => array_merge((array) $alert->metadata, ['rating' => $row->rating]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not update the low-rating alert', ['visit_follow_up_id' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    protected function alertStaffOfFailure(VisitFollowUp $row): void
    {
        try {
            Notification::create([
                'location_id' => $row->location_id,
                'type' => 'system',
                'priority' => 'medium',
                'title' => $row->kind === VisitFollowUp::KIND_REVIEW ? 'Review request not delivered' : 'Thanks for Playing email not delivered',
                'message' => sprintf('The email to %s could not be sent after %d attempts: %s', $row->maskedEmail(), $row->attempts, Str::limit((string) $row->error, 200)),
                'status' => 'unread',
                'action_url' => $this->adminPath($row->visit_type, (int) $row->visit_id),
                'action_text' => 'Open visit',
                'metadata' => ['visit_follow_up_id' => $row->id],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record the follow-up failure alert', ['visit_follow_up_id' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    protected function alertStaffOfDroppedPromo(EmailNotification $notification, ?Promo $promo, CompletedVisit $visit, string $problem): void
    {
        try {
            $key = sprintf('visit-promo-dropped:%d:%d:%d', $notification->id, (int) $notification->promo_id, (int) $visit->locationId());

            if (!Cache::add($key, true, now()->addDays(self::PROMO_ALERT_DAYS))) {
                return;
            }

            Notification::create([
                'location_id' => $visit->locationId(),
                'type' => 'system',
                'priority' => 'medium',
                'title' => 'Return-visit offer left out',
                'message' => sprintf(
                    '"%s" is going out without its promo code%s. %s',
                    $notification->name,
                    $promo?->code ? ' ' . $promo->code : '',
                    $problem
                ),
                'status' => 'unread',
                'action_url' => '/admin/email/notifications/edit/' . $notification->id,
                'action_text' => 'Choose a promo code',
                'metadata' => ['email_notification_id' => $notification->id, 'promo_id' => $notification->promo_id],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record the dropped-promo alert', ['email_notification_id' => $notification->id, 'error' => $e->getMessage()]);
        }
    }

    protected function validEmail(?string $email): bool
    {
        return $email !== null && $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
