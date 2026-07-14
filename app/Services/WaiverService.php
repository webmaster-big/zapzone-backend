<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Waiver;
use App\Models\WaiverMinor;
use App\Models\WaiverTemplate;
use App\Models\WaiverTemplateVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WaiverService
{
    /**
     * Tokens that autofill inside the waiver legal body. Keys are the {{merge_tags}}
     * the builder can drop into body_text; values describe them for the builder UI.
     */
    public static function contentTokens(): array
    {
        return [
            'business_legal_name' => 'Company legal name',
            'company_name' => 'Company name',
            'company_email' => 'Company email',
            'company_phone' => 'Company phone',
            'location_name' => 'Location name',
            'location_address' => 'Location address',
            'activity_name' => 'Booked activity / attraction / event',
            'booking_date' => 'Selected booking / visit date',
            'visit_date' => 'Selected booking / visit date (alias)',
            'full_name' => 'Adult / guardian full name',
            'adult_first_name' => 'Adult / guardian first name',
            'adult_last_name' => 'Adult / guardian last name',
            'adult_email' => 'Adult / guardian email',
            'adult_phone' => 'Adult / guardian phone',
            'relationship' => 'Relationship to participant',
            'current_date' => "Today's date",
            'current_year' => 'Current year',
        ];
    }

    public static function defaultPhotoVideoReleaseText(): string
    {
        return 'I grant {{company_name}} permission to photograph and record me and any minors listed on this waiver during our visit, and to use those images and recordings for promotional purposes.';
    }

    /**
     * Build the autofill value map for a waiver from its linked booking / event /
     * customer / location. Used to render the legal body with real data.
     */
    public function buildContentVariables(Waiver $waiver): array
    {
        $waiver->loadMissing(['company', 'location', 'booking.package', 'event', 'attractionPurchase.attraction', 'customer', 'template']);

        $company = $waiver->company ?? $waiver->location?->company;
        $location = $waiver->location;

        // Activity name resolution, most-specific first:
        //  1. a concrete linked record (booking → package, event, attraction purchase)
        //  2. a name captured at assign time (manager-assigned, no concrete record)
        //  3. the template's sole assigned activity, when it maps to exactly one
        $activityName = $waiver->event?->name
            ?? $waiver->booking?->package?->name
            ?? $waiver->attractionPurchase?->attraction?->name
            ?? $waiver->manual_activity_name
            ?? $this->soleAssignedActivityName($waiver->template);

        $fullName = $waiver->adult_full_name !== ''
            ? $waiver->adult_full_name
            : ($waiver->typed_legal_name ?? '');

        $date = $waiver->selected_date ? $waiver->selected_date->format('F j, Y') : '';

        return [
            'business_legal_name' => $company?->company_name ?? '',
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'location_name' => $location?->name ?? '',
            'location_address' => trim(implode(', ', array_filter([
                $location?->address, $location?->city, $location?->state, $location?->zip_code,
            ]))),
            'activity_name' => $activityName ?? '',
            'booking_date' => $date,
            'visit_date' => $date,
            'full_name' => $fullName,
            'adult_first_name' => $waiver->adult_first_name ?? '',
            'adult_last_name' => $waiver->adult_last_name ?? '',
            'adult_email' => $waiver->adult_email ?? '',
            'adult_phone' => $waiver->adult_phone ?? '',
            'relationship' => $waiver->relationship ?? '',
            'current_date' => Carbon::now()->format('F j, Y'),
            'current_year' => Carbon::now()->format('Y'),
        ];
    }

    public function staticContentVariables(WaiverTemplate $template, ?\App\Models\Location $location = null): array
    {
        $template->loadMissing(['company', 'location']);
        $company = $template->company;
        $location = $location ?: $template->location;

        return [
            'business_legal_name' => $company?->company_name ?? '',
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'location_name' => $location?->name ?? '',
            'location_address' => trim(implode(', ', array_filter([
                $location?->address, $location?->city, $location?->state, $location?->zip_code,
            ]))),
            'activity_name' => $this->soleAssignedActivityName($template) ?? '',
            'booking_date' => '',
            'visit_date' => '',
            'full_name' => '',
            'adult_first_name' => '',
            'adult_last_name' => '',
            'adult_email' => '',
            'adult_phone' => '',
            'relationship' => '',
            'current_date' => Carbon::now()->format('F j, Y'),
            'current_year' => Carbon::now()->format('Y'),
        ];
    }

    /**
     * Replace {{ tokens }} in a body with their autofill values. Unknown tokens are
     * left untouched so a half-configured template still renders readably.
     * Mirrors EmailNotificationService::replaceVariables().
     */
    public function render(string $body, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $body = preg_replace_callback(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                fn () => (string) ($value ?? ''),
                $body
            );
        }
        return $body;
    }

    /** Render a waiver's frozen version body with its autofill values applied. */
    public function renderForWaiver(Waiver $waiver): string
    {
        $body = $waiver->version?->body_text ?? $waiver->template?->body_text ?? '';
        return $this->render($body, $this->buildContentVariables($waiver));
    }

    /**
     * Ensure a template has a current version snapshot, creating a new one when the
     * legal body or any clause flag changed. Returns the active version model.
     */
    public function syncVersion(WaiverTemplate $template, ?int $userId = null): WaiverTemplateVersion
    {
        $latest = $template->versions()->first();
        $config = $this->snapshotClauseConfig($template);

        $changed = !$latest
            || $latest->body_text !== $template->body_text
            || $latest->clause_config != $config;

        if (!$changed) {
            return $latest;
        }

        $nextVersion = ($latest?->version ?? 0) + 1;

        $version = $template->versions()->create([
            'version' => $nextVersion,
            'body_text' => $template->body_text,
            'clause_config' => $config,
            'created_by' => $userId,
        ]);

        $template->forceFill(['current_version' => $nextVersion])->save();

        return $version;
    }

    /** The clause/marketing config frozen into a version snapshot. */
    public function snapshotClauseConfig(WaiverTemplate $template): array
    {
        $config = [];
        foreach (WaiverTemplate::CLAUSE_FIELDS as $field) {
            $config[$field] = $template->{$field};
        }
        return $config;
    }

    /**
     * Find an existing completed waiver that blocks a new submission for the same
     * person + date + template (used for duplicate prevention).
     */
    public function findDuplicate(
        int $templateId,
        $selectedDate,
        ?int $customerId,
        ?string $email,
        ?string $phone
    ): ?Waiver {
        return Waiver::completed()
            ->where('waiver_template_id', $templateId)
            ->whereDate('selected_date', $selectedDate)
            ->where(function ($q) use ($customerId, $email, $phone) {
                if ($customerId) {
                    $q->orWhere('customer_id', $customerId);
                }
                if ($email) {
                    $q->orWhere('adult_email', $email);
                }
                if ($phone) {
                    $q->orWhere('adult_phone', $phone);
                }
            })
            ->first();
    }

    /**
     * Decide whether a self-serve submission may proceed given the template's rule and
     * any existing duplicate. Returns [allowed, reason].
     */
    public function evaluateDuplicateRule(WaiverTemplate $template, ?Waiver $duplicate, bool $isManagerAssigned): array
    {
        if (!$duplicate) {
            return [true, null];
        }

        return match ($template->duplicate_rule) {
            WaiverTemplate::DUPLICATE_ALLOW => [true, null],
            WaiverTemplate::DUPLICATE_MANAGER_ONLY => $isManagerAssigned
                ? [true, null]
                : [false, 'A waiver has already been completed for this booking date.'],
            default => [false, 'A waiver has already been completed for this booking date.'],
        };
    }

    /**
     * Complete a pending waiver: persist participant + minor data, marketing consent,
     * capture metadata, and flip status to completed. Wrapped in a transaction.
     */
    public function completeSubmission(Waiver $waiver, array $data, array $context = []): Waiver
    {
        return DB::transaction(function () use ($waiver, $data, $context) {
            $template = $waiver->template;

            $marketingOptIn = (bool) ($data['marketing_consent'] ?? false);

            $waiver->fill([
                'adult_first_name' => $data['adult_first_name'] ?? null,
                'adult_last_name' => $data['adult_last_name'] ?? null,
                'adult_email' => $data['adult_email'] ?? null,
                'adult_phone' => $data['adult_phone'] ?? null,
                'adult_dob' => $data['adult_dob'] ?? null,
                'relationship' => $data['relationship'] ?? null,
                'typed_legal_name' => $data['typed_legal_name'] ?? null,
                'agreement_accepted' => (bool) ($data['agreement_accepted'] ?? false),
                'electronic_consent_accepted' => (bool) ($data['electronic_consent_accepted'] ?? false),
                'photo_video_consent' => array_key_exists('photo_video_consent', $data)
                    ? (bool) $data['photo_video_consent']
                    : null,
                'marketing_consent_status' => $marketingOptIn
                    ? Waiver::MARKETING_OPTED_IN
                    : Waiver::MARKETING_NOT_OPTED_IN,
                'marketing_consent_at' => $marketingOptIn ? Carbon::now() : null,
                'marketing_consent_source' => $marketingOptIn ? ($context['source'] ?? $waiver->source) : null,
                'status' => Waiver::STATUS_COMPLETED,
                'submitted_at' => Carbon::now(),
                'expires_at' => $this->computeExpiry($template),
                'ip_address' => $context['ip'] ?? $waiver->ip_address,
                'device' => $context['device'] ?? $waiver->device,
            ]);

            if (!empty($context['source'])) {
                $waiver->source = $context['source'];
            }

            $waiver->save();

            // replace minors wholesale (waiver is immutable once completed, so this only
            // runs on the single completing call)
            $waiver->minors()->delete();
            foreach (($data['minors'] ?? []) as $minor) {
                $waiver->minors()->create([
                    'first_name' => $minor['first_name'] ?? '',
                    'last_name' => $minor['last_name'] ?? '',
                    'date_of_birth' => $minor['date_of_birth'] ?? null,
                    'relationship' => $minor['relationship'] ?? null,
                ]);
            }

            // link a bulk invite recipient as complete, if this came through a bulk flow
            if ($waiver->bulk_invite_recipient_id && $waiver->inviteRecipient) {
                $waiver->inviteRecipient->update([
                    'status' => \App\Models\WaiverInviteRecipient::STATUS_COMPLETE,
                    'waiver_id' => $waiver->id,
                ]);
            }

            return $waiver->fresh(['minors']);
        });
    }

    /** Compute an expiry date from the template's validity window (null = never). */
    public function computeExpiry(?WaiverTemplate $template): ?Carbon
    {
        if (!$template || !$template->validity_duration_days) {
            return null;
        }
        return Carbon::now()->addDays($template->validity_duration_days);
    }

    /**
     * Build the prefill payload returned to a (non-kiosk) public waiver form: known
     * customer / guest info eager-loaded from the linked booking or event.
     */
    public function prefillFor(Waiver $waiver): array
    {
        $waiver->loadMissing(['customer', 'booking', 'event']);

        $customer = $waiver->customer;
        $booking = $waiver->booking;

        return array_filter([
            'adult_first_name' => $waiver->adult_first_name
                ?? $customer?->first_name
                ?? $this->firstWord($booking?->guest_name),
            'adult_last_name' => $waiver->adult_last_name
                ?? $customer?->last_name
                ?? $this->restWords($booking?->guest_name),
            'adult_email' => $waiver->adult_email ?? $customer?->email ?? $booking?->guest_email,
            'adult_phone' => $waiver->adult_phone ?? $customer?->phone ?? $booking?->guest_phone,
            'adult_dob' => optional($customer?->date_of_birth)->toDateString(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** The active version row for a template, creating one if somehow missing. */
    public function currentVersion(WaiverTemplate $template, ?int $userId = null): WaiverTemplateVersion
    {
        $version = $template->versions()
            ->where('version', $template->current_version)
            ->first();

        return $version ?: $this->syncVersion($template, $userId);
    }

    /**
     * Create a pending waiver for a booking when a template applies and none exists yet.
     * Returns the waiver (existing or new), or null when no template covers the booking.
     */
    public function ensureForBooking(Booking $booking): ?Waiver
    {
        $existing = Waiver::where('booking_id', $booking->id)->first();
        if ($existing) {
            return $existing;
        }

        $booking->loadMissing(['location', 'attractions']);
        $companyId = $booking->location?->company_id;
        if (!$companyId) {
            return null;
        }

        $template = WaiverTemplate::resolveForActivity(
            $companyId,
            $booking->location_id,
            $booking->package_id,
            $booking->attractions?->pluck('id')->all() ?? [],
        );
        if (!$template) {
            return null;
        }

        return $this->createPending($template, [
            'company_id' => $companyId,
            'location_id' => $booking->location_id,
            'customer_id' => $booking->customer_id,
            'booking_id' => $booking->id,
            'selected_date' => $booking->booking_date,
            'adult_email' => $booking->customer?->email ?? $booking->guest_email,
            'adult_phone' => $booking->customer?->phone ?? $booking->guest_phone,
        ]);
    }

    /** Create a pending waiver for an event purchase when a template applies. */
    public function ensureForEventPurchase(\App\Models\EventPurchase $purchase): ?Waiver
    {
        $existing = Waiver::where('event_id', $purchase->event_id)
            ->where('customer_id', $purchase->customer_id)
            ->whereDate('selected_date', $purchase->purchase_date)
            ->first();
        if ($existing) {
            return $existing;
        }

        $purchase->loadMissing(['location', 'event']);
        $companyId = $purchase->location?->company_id;
        if (!$companyId) {
            return null;
        }

        $template = WaiverTemplate::resolveForActivity(
            $companyId,
            $purchase->location_id,
            null,
            [],
            $purchase->event_id,
        );
        if (!$template) {
            return null;
        }

        return $this->createPending($template, [
            'company_id' => $companyId,
            'location_id' => $purchase->location_id,
            'customer_id' => $purchase->customer_id,
            'event_id' => $purchase->event_id,
            'selected_date' => $purchase->purchase_date,
            'adult_email' => $purchase->customer?->email ?? $purchase->guest_email,
            'adult_phone' => $purchase->customer?->phone ?? $purchase->guest_phone,
        ]);
    }

    /** Create a pending waiver for an attraction purchase when a template applies. */
    public function ensureForAttractionPurchase(\App\Models\AttractionPurchase $purchase): ?Waiver
    {
        $existing = Waiver::where('attraction_purchase_id', $purchase->id)->first();
        if ($existing) {
            return $existing;
        }

        $purchase->loadMissing(['attraction.location']);
        $companyId = $purchase->attraction?->location?->company_id;
        $locationId = $purchase->location_id ?? $purchase->attraction?->location_id;
        if (!$companyId) {
            return null;
        }

        $template = WaiverTemplate::resolveForActivity(
            $companyId,
            $locationId,
            null,
            [$purchase->attraction_id],
        );
        if (!$template) {
            return null;
        }

        return $this->createPending($template, [
            'company_id' => $companyId,
            'location_id' => $locationId,
            'customer_id' => $purchase->customer_id,
            'attraction_purchase_id' => $purchase->id,
            'selected_date' => $purchase->scheduled_date ?? $purchase->purchase_date,
            'adult_email' => $purchase->customer?->email ?? $purchase->guest_email,
            'adult_phone' => $purchase->customer?->phone ?? $purchase->guest_phone,
        ]);
    }

    /**
     * Create (or return the existing) pending waiver for a bulk-invite recipient, seeded
     * with the recipient's contact info so the parent-invite notification can reach them.
     */
    public function createForBulkRecipient(
        \App\Models\WaiverBulkInvite $invite,
        \App\Models\WaiverInviteRecipient $recipient
    ): Waiver {
        if ($recipient->waiver_id && $recipient->waiver) {
            return $recipient->waiver;
        }

        $template = $invite->template;
        $version = $this->currentVersion($template);

        [$first, $last] = $this->splitName($recipient->name);

        $waiver = Waiver::create([
            'company_id' => $invite->company_id,
            'location_id' => $invite->location_id,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'booking_id' => $invite->booking_id,
            'event_id' => $invite->event_id,
            'bulk_invite_id' => $invite->id,
            'bulk_invite_recipient_id' => $recipient->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $invite->selected_date,
            'adult_first_name' => $first,
            'adult_last_name' => $last,
            'adult_email' => $recipient->email,
            'adult_phone' => $recipient->phone,
            'source' => Waiver::SOURCE_BULK_INVITE,
        ]);

        $recipient->update(['waiver_id' => $waiver->id]);

        return $waiver;
    }

    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return [null, null];
        }
        $parts = explode(' ', $name, 2);
        return [$parts[0], $parts[1] ?? null];
    }

    /** Shared pending-waiver creation. */
    private function createPending(WaiverTemplate $template, array $attrs): Waiver
    {
        $version = $this->currentVersion($template);

        return Waiver::create(array_merge([
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'status' => Waiver::STATUS_PENDING,
            'source' => Waiver::SOURCE_CONFIRMATION_EMAIL,
        ], $attrs));
    }

    /**
     * Pending, reminder-eligible waivers whose visit date falls inside the reminder
     * window and whose linked booking still exists. Used by waivers:send-reminders.
     */
    public function dueForReminder(int $windowHours): \Illuminate\Support\Collection
    {
        $now = Carbon::now();
        $until = (clone $now)->addHours($windowHours);

        return Waiver::with(['template', 'booking', 'company', 'location'])
            ->pending()
            ->where('reminder_sent', false)
            ->whereDate('selected_date', '>=', $now->toDateString())
            ->whereDate('selected_date', '<=', $until->toDateString())
            ->whereHas('template', fn ($q) => $q->where('reminder_eligible', true))
            // a waiver tied to a booking only reminds while that booking still exists
            ->where(function ($q) {
                $q->whereNull('booking_id')->orWhereHas('booking');
            })
            ->get();
    }

    /**
     * When a template maps to exactly one activity (package/attraction/event), return
     * that activity's name — used to autofill {{activity_name}} for waivers without a
     * concrete linked record (e.g. manager-assigned). Null when zero or many.
     */
    private function soleAssignedActivityName(?WaiverTemplate $template): ?string
    {
        if (!$template) {
            return null;
        }

        $packages = $template->assigned_package_ids ?? [];
        $attractions = $template->assigned_attraction_ids ?? [];
        $events = $template->assigned_event_ids ?? [];

        if (count($packages) + count($attractions) + count($events) !== 1) {
            return null;
        }

        if (count($packages) === 1) {
            return optional(\App\Models\Package::find($packages[0]))->name;
        }
        if (count($attractions) === 1) {
            return optional(\App\Models\Attraction::find($attractions[0]))->name;
        }
        return optional(\App\Models\Event::find($events[0]))->name;
    }

    private function firstWord(?string $name): ?string
    {
        if (!$name) {
            return null;
        }
        return explode(' ', trim($name), 2)[0] ?? null;
    }

    private function restWords(?string $name): ?string
    {
        if (!$name) {
            return null;
        }
        $parts = explode(' ', trim($name), 2);
        return $parts[1] ?? null;
    }
}
