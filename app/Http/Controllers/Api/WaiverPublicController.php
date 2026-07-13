<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverInviteRecipient;
use App\Models\WaiverSetting;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiverPublicController extends Controller
{
    public function __construct(private WaiverService $waivers)
    {
    }

    /**
     * Fetch the form context for a token-addressed waiver link: the frozen legal body,
     * which sections/fields to show, prefilled known info, and whether it's already done.
     * No PII is ever carried in the URL — it is resolved server-side from the token.
     */
    public function show(string $token): JsonResponse
    {
        $waiver = Waiver::with(['template', 'version', 'company', 'location:id,name'])
            ->where('access_token', $token)
            ->first();

        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }

        if ($waiver->isCompleted()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'message' => 'Your waiver has already been completed for this booking date.',
                    'submitted_at' => $waiver->submitted_at,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formContext($waiver, prefill: true),
        ]);
    }

    /** Status-only endpoint for an already-completed waiver link. */
    public function status(string $token): JsonResponse
    {
        $waiver = Waiver::where('access_token', $token)->first();
        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $waiver->status,
                'completed' => $waiver->isCompleted(),
                'submitted_at' => $waiver->submitted_at,
            ],
        ]);
    }

    /** Submit a token-addressed waiver (post-booking / email / SMS / staff-sent link). */
    public function submit(Request $request, string $token): JsonResponse
    {
        $waiver = Waiver::with('template')->where('access_token', $token)->first();
        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }
        if ($waiver->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Your waiver has already been completed for this booking date.',
            ], 409);
        }

        $template = $waiver->template;
        $data = $this->validateSubmission($request, $template);

        // duplicate prevention (against OTHER completed waivers for this person/date/template)
        $duplicate = $this->waivers->findDuplicate(
            $template->id,
            $waiver->selected_date,
            $waiver->customer_id,
            $data['adult_email'] ?? null,
            $data['adult_phone'] ?? null
        );
        [$allowed, $reason] = $this->waivers->evaluateDuplicateRule($template, $duplicate, $waiver->is_manager_assigned);
        if (!$allowed) {
            return response()->json(['success' => false, 'message' => $reason], 409);
        }

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => substr((string) $request->userAgent(), 0, 255),
            'source' => $waiver->source ?: Waiver::SOURCE_CONFIRMATION_EMAIL,
        ]);

        $this->notifySigned($completed);

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed. Thank you!',
            'data' => ['id' => $completed->id, 'status' => $completed->status],
        ]);
    }

    /**
     * Kiosk start — a blank form for an active template. No prefill, no saved data;
     * the frontend disables browser autofill per settings.
     */
    public function kioskShow(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::active()->find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $version = $template->versions()->first();
        if (!$version) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $settings = WaiverSetting::forCompany($template->company_id);

        // Resolve static tokens that are known at display time (company, location, dates).
        // Signer-specific tokens (full_name, booking_date, activity_name) are left blank
        // because the kiosk has no booking context — they are filled in after submission.
        $template->loadMissing(['company', 'location']);
        $company  = $template->company;
        $location = $template->location;

        $requestedLocationId = $request->input('location_id');
        if ($requestedLocationId) {
            $requestedLocation = \App\Models\Location::where('id', $requestedLocationId)
                ->where('company_id', $template->company_id)
                ->first();
            if ($requestedLocation) {
                $location = $requestedLocation;
            }
        }

        $packageIds    = $template->assigned_package_ids ?? [];
        $attractionIds = $template->assigned_attraction_ids ?? [];
        $eventIds      = $template->assigned_event_ids ?? [];
        $totalAssigned = count($packageIds) + count($attractionIds) + count($eventIds);
        $activityName  = '';
        if ($totalAssigned === 1) {
            if (count($packageIds) === 1) {
                $activityName = \App\Models\Package::find($packageIds[0])?->name ?? '';
            } elseif (count($attractionIds) === 1) {
                $activityName = \App\Models\Attraction::find($attractionIds[0])?->name ?? '';
            } else {
                $activityName = \App\Models\Event::find($eventIds[0])?->name ?? '';
            }
        }

        $staticVars = [
            'business_legal_name' => $company?->company_name ?? '',
            'company_name'        => $company?->company_name ?? '',
            'company_email'       => $company?->email ?? '',
            'company_phone'       => $company?->phone ?? '',
            'location_name'       => $location?->name ?? '',
            'location_address'    => trim(implode(', ', array_filter([
                $location?->address, $location?->city, $location?->state, $location?->zip_code,
            ]))),
            'current_date' => \Carbon\Carbon::now()->format('F j, Y'),
            'current_year' => \Carbon\Carbon::now()->format('Y'),
            'full_name'       => '',
            'adult_first_name'=> '',
            'adult_last_name' => '',
            'adult_email'     => '',
            'adult_phone'     => '',
            'relationship'    => '',
            'activity_name'   => $activityName,
            'booking_date'    => '',
            'visit_date'      => '',
        ];

        $body = $this->waivers->render($version->body_text, $staticVars);

        return response()->json([
            'success' => true,
            'data' => [
                'kiosk' => true,
                'template' => $this->templatePayload($template, $version),
                'body' => $body,
                'settings' => [
                    'inactivity_timeout_seconds' => $settings->kiosk_inactivity_timeout_seconds,
                    'disable_autofill' => $settings->kiosk_disable_autofill,
                ],
            ],
        ]);
    }

    /** Kiosk submit — creates a fresh completed waiver (walk-in). */
    public function kioskSubmit(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::active()->find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }
        $version = $template->versions()->first();
        if (!$version) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $data = $this->validateSubmission($request, $template);

        $resolvedLocationId = $template->location_id;
        $requestedLocationId = $request->input('location_id');
        if ($requestedLocationId) {
            $requestedLocation = \App\Models\Location::where('id', $requestedLocationId)
                ->where('company_id', $template->company_id)
                ->first();
            if ($requestedLocation) {
                $resolvedLocationId = $requestedLocation->id;
            }
        }

        $waiver = Waiver::create([
            'company_id' => $template->company_id,
            'location_id' => $resolvedLocationId,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $request->date('selected_date') ?? now()->toDateString(),
            'source' => Waiver::SOURCE_KIOSK,
        ]);

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => 'kiosk',
            'source' => Waiver::SOURCE_KIOSK,
        ]);

        $this->notifySigned($completed);

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed.',
            'data' => ['id' => $completed->id],
        ], 201);
    }

    // ---- bulk / chaperone (public, manage-token addressed) ----

    /** Chaperone status view — only invited person + complete/not-complete + resend. */
    public function bulkShow(string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::with(['recipients:id,waiver_bulk_invite_id,name,status,resent_count'])
            ->where('manage_token', $manageToken)
            ->first();

        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chaperone_name' => $invite->chaperone_name,
                'selected_date' => $invite->selected_date,
                'allow_shareable_link' => $invite->allow_shareable_link,
                // chaperone sees only limited status info, never full waiver details
                'recipients' => $invite->recipients->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'status' => $r->status,
                    'complete' => $r->status === WaiverInviteRecipient::STATUS_COMPLETE,
                    'resent_count' => $r->resent_count,
                ]),
                'summary' => [
                    'total' => $invite->recipients->count(),
                    'complete' => $invite->recipients->where('status', WaiverInviteRecipient::STATUS_COMPLETE)->count(),
                ],
            ],
        ]);
    }

    /** Chaperone adds parent/guardian contacts (manual entry or uploaded list). */
    public function bulkAddRecipients(Request $request, string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $validated = $request->validate([
            'recipients' => 'required|array|min:1|max:500',
            'recipients.*.name' => 'nullable|string|max:255',
            'recipients.*.email' => 'nullable|email|max:255',
            'recipients.*.phone' => 'nullable|string|max:30',
        ]);

        // existing contacts in this batch, to skip duplicate uploads
        $existing = $invite->recipients()
            ->get(['email', 'phone'])
            ->map(fn ($r) => strtolower(trim((string) ($r->email ?: $r->phone))))
            ->filter()
            ->all();

        $added = 0;
        foreach ($validated['recipients'] as $row) {
            if (empty($row['email']) && empty($row['phone'])) {
                continue; // need at least one channel
            }
            $key = strtolower(trim((string) ($row['email'] ?? $row['phone'])));
            if (in_array($key, $existing, true)) {
                continue;
            }
            $existing[] = $key;

            $invite->recipients()->create([
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'status' => WaiverInviteRecipient::STATUS_NOT_SENT,
            ]);
            $added++;
        }

        return response()->json([
            'success' => true,
            'message' => "{$added} contact(s) added.",
            'data' => ['added' => $added, 'total' => $invite->recipients()->count()],
        ]);
    }

    /** Chaperone sends waiver invites to all not-yet-sent recipients. */
    public function bulkSend(string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::with('template')->where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $recipients = $invite->recipients()
            ->whereIn('status', [WaiverInviteRecipient::STATUS_NOT_SENT, WaiverInviteRecipient::STATUS_FAILED])
            ->get();

        $sent = 0;
        foreach ($recipients as $recipient) {
            $waiver = $this->waivers->createForBulkRecipient($invite, $recipient);
            $recipient->update([
                'status' => WaiverInviteRecipient::STATUS_SENT,
                'last_sent_at' => now(),
            ]);
            $this->notifyParentInvite($waiver);
            $sent++;
        }

        return response()->json([
            'success' => true,
            'message' => "Invites sent to {$sent} recipient(s).",
            'data' => ['sent' => $sent],
        ]);
    }

    /** Chaperone resends a single recipient's invite. */
    public function bulkResendRecipient(string $manageToken, int $recipientId): JsonResponse
    {
        $invite = WaiverBulkInvite::with('template')->where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $recipient = $invite->recipients()->find($recipientId);
        if (!$recipient) {
            return response()->json(['success' => false, 'message' => 'Recipient not found.'], 404);
        }
        if ($recipient->status === WaiverInviteRecipient::STATUS_COMPLETE) {
            return response()->json(['success' => false, 'message' => 'This recipient already completed their waiver.'], 409);
        }

        $waiver = $this->waivers->createForBulkRecipient($invite, $recipient);
        $recipient->update([
            'status' => WaiverInviteRecipient::STATUS_SENT,
            'resent_count' => $recipient->resent_count + 1,
            'last_sent_at' => now(),
        ]);
        $this->notifyParentInvite($waiver);

        return response()->json(['success' => true, 'message' => 'Invite resent.']);
    }

    // ---- helpers ----

    /** Fire the parent/guardian waiver invite (email + paired SMS); never blocks. */
    private function notifyParentInvite(Waiver $waiver): void
    {
        try {
            app(\App\Services\EmailNotificationService::class)
                ->triggerWaiverNotification($waiver, \App\Models\EmailNotification::TRIGGER_WAIVER_PARENT_INVITE);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send parent waiver invite', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Fire the "waiver signed" confirmation (email + paired SMS); never blocks the response. */
    private function notifySigned(Waiver $waiver): void
    {
        try {
            app(\App\Services\EmailNotificationService::class)
                ->triggerWaiverNotification($waiver, \App\Models\EmailNotification::TRIGGER_WAIVER_SIGNED);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send waiver-signed notification', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formContext(Waiver $waiver, bool $prefill): array
    {
        $template = $waiver->template;
        $version = $waiver->version;

        return [
            'status' => $waiver->status,
            'template' => $this->templatePayload($template, $version),
            // legal body with whatever is known so far applied (autofill preview)
            'body' => $this->waivers->render(
                $version?->body_text ?? $template->body_text ?? '',
                $this->waivers->buildContentVariables($waiver)
            ),
            'prefill' => $prefill ? $this->waivers->prefillFor($waiver) : [],
            'selected_date' => $waiver->selected_date,
        ];
    }

    private function templatePayload(WaiverTemplate $template, $version): array
    {
        $clauses = $version?->clause_config ?? [];

        return [
            'id' => $template->id,
            'title' => $template->title,
            'version' => $version?->version,
            'max_minors' => $template->max_minors,
            'minor_section_enabled' => $template->minor_section_enabled,
            'dob_required' => $template->dob_required,
            'relationship_required' => $template->relationship_required,
            'photo_video_release_enabled' => $template->photo_video_release_enabled,
            'electronic_consent_enabled' => $template->electronic_consent_enabled,
            'marketing_consent_enabled' => $template->marketing_consent_enabled,
            'marketing_consent_text' => $template->marketing_consent_text,
            'marketing_helper_text' => $template->marketing_helper_text,
            'clause_config' => $clauses,
        ];
    }

    private function validateSubmission(Request $request, WaiverTemplate $template): array
    {
        $rules = [
            'adult_first_name' => 'required|string|max:255',
            'adult_last_name' => 'required|string|max:255',
            'adult_email' => 'required|email|max:255',
            'adult_phone' => 'required|string|max:30',
            'adult_dob' => 'nullable|date',
            'relationship' => 'nullable|string|max:100',
            'typed_legal_name' => 'required|string|max:255',
            'agreement_accepted' => 'accepted',
            'photo_video_consent' => 'nullable|boolean',
            'marketing_consent' => 'nullable|boolean',
            'minors' => 'nullable|array|max:' . max(1, (int) $template->max_minors),
            'minors.*.first_name' => 'required_with:minors|string|max:255',
            'minors.*.last_name' => 'required_with:minors|string|max:255',
            'minors.*.date_of_birth' => ($template->dob_required ? 'required_with:minors' : 'nullable') . '|date',
            'minors.*.relationship' => ($template->relationship_required ? 'required_with:minors' : 'nullable') . '|string|max:100',
        ];

        // electronic consent is required only when the clause is enabled
        $rules['electronic_consent_accepted'] = $template->electronic_consent_enabled ? 'accepted' : 'nullable|boolean';

        return $request->validate($rules);
    }
}
