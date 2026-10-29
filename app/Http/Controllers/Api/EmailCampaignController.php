<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStaff;
use App\Http\Traits\ScopesByAuthUser;
use App\Support\DateRange;
use App\Mail\DynamicCampaignMail;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use App\Models\EmailTemplate;
use App\Models\FollowUpOptOut;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\User;
use App\Services\EmailCampaignSender;
use App\Services\PhotoDeliveryService;
use App\Services\VisitFollowUpService;
use App\Support\OperatingDay;
use App\Services\GmailApiService;
use App\Support\EmailHtml;
use App\Support\TemplatePlaceholders;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmailCampaignController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailCampaign::with(['company', 'location', 'template', 'sender'])
            ->where('company_id', $user->company_id);

        if (EnsureStaff::effectiveRole($user) === 'attendant') {
            $query->whereJsonDoesntContain('recipient_types', EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS);
        } elseif (!$this->isCompanyLevel($user)) {
            $this->onlyOwnEventCampaigns($query, $request);
        }

        if (in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $query->where(function ($q) use ($user) {
                $q->where('location_id', $user->location_id)
                  ->orWhereNull('location_id');
            });
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('body', 'like', $like);
                });
            }
        }

        $query->withCount(['logs as sent_emails' => function ($q) {
            $q->where('status', 'sent');
        }]);

        $sortBy = $request->get('sort_by');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['name', 'subject', 'status', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $campaigns = $query->paginate($request->per_page ?? 15);
        $campaigns->getCollection()->each(fn (EmailCampaign $campaign) => $this->recoverStalledSend($campaign));

        return response()->json([
            'success' => true,
            'data' => $campaigns,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body' => ['required', 'string', EmailHtml::rule()],
            'email_template_id' => ['nullable', Rule::exists('email_templates', 'id')->where('company_id', Auth::user()?->company_id)],
            'recipient_types' => 'required|array|min:1',
            'recipient_types.*' => [Rule::in(EmailCampaign::RECIPIENT_TYPES)],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'recipient_filters' => 'nullable|array',
            'recipient_filters.status' => 'nullable|string',
            'recipient_filters.location_id' => 'nullable|exists:locations,id',
            'recipient_filters.registration_event_ids' => 'nullable|array|max:100',
            'recipient_filters.registration_event_ids.*' => 'integer|min:1|distinct',
            'recipient_filters.only_with_photos' => 'nullable|boolean',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,png,jpg,jpeg,gif',
            'scheduled_at' => 'nullable|date|after:now|before:+1 year',
            'send_now' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
        ], [
            'scheduled_at.after' => 'Pick a time in the future.',
            'scheduled_at.before' => 'Pick a time within the next year.',
        ]);

        if ($tooLarge = $this->attachmentsTooLarge($request)) {
            return $tooLarge;
        }

        if ($denied = $this->denyForeignLocations($request, $validated)) {
            return $denied;
        }

        if (($forms = $this->eventFormsFor($request, $validated)) instanceof JsonResponse) {
            return $forms;
        }

        $user = Auth::user();
        $recipients = $this->getRecipients(new EmailCampaign([
            'company_id' => $user->company_id,
            'location_id' => $this->campaignLocationId($user, $validated),
            'recipient_types' => $validated['recipient_types'],
            'custom_emails' => $validated['custom_emails'] ?? [],
            'recipient_filters' => $validated['recipient_filters'] ?? [],
        ]), $user);

        if ($recipients === [] && in_array(EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS, $validated['recipient_types'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Nobody would get this email. No one from the picked events can get it right now: their photos are not ready, they gave no email address, or they unsubscribed.',
                'errors' => ['recipient_filters.registration_event_ids' => ['Nobody from the picked events can get this email right now.']],
            ], 422);
        }

        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('email-attachments', 'public');
                $attachments[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        }

        try {
            DB::beginTransaction();

            $campaign = EmailCampaign::create([
                'company_id' => $user->company_id,
                'location_id' => $this->campaignLocationId($user, $validated),
                'email_template_id' => $validated['email_template_id'] ?? null,
                'sent_by' => $user->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'recipient_types' => $validated['recipient_types'],
                'custom_emails' => $validated['custom_emails'] ?? [],
                'recipient_filters' => $validated['recipient_filters'] ?? [],
                'attachments' => $attachments,
                'scheduled_at' => !empty($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at'])->setTimezone(config('app.timezone')) : null,
                'status' => EmailCampaign::STATUS_PENDING,
            ]);

            $campaign->update(['total_recipients' => count($recipients)]);

            foreach (array_chunk($recipients, 500) as $chunk) {
                $now = now();

                EmailCampaignLog::query()->insert(array_map(fn (array $recipient) => [
                    'email_campaign_id' => $campaign->id,
                    'recipient_email' => $recipient['email'],
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'] ?? null,
                    'status' => EmailCampaignLog::STATUS_PENDING,
                    'variables_used' => json_encode($recipient['variables']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            DB::commit();

            $inBackground = false;

            if (($validated['send_now'] ?? true) && empty($validated['scheduled_at'])) {
                if (count($recipients) > self::SEND_IN_REQUEST_LIMIT) {
                    $inBackground = true;
                    $campaign->markAsSending();
                } else {
                    $this->sendCampaign($campaign);
                }
            }

            $campaign->refresh();
            $campaign->load(['company', 'location', 'template', 'sender', 'logs']);

            return response()->json([
                'success' => true,
                'message' => match (true) {
                    !empty($validated['scheduled_at']) => 'Email campaign scheduled successfully',
                    $inBackground => EmailCampaignSender::queuedMessage(count($recipients)),
                    $campaign->status === EmailCampaign::STATUS_FAILED => (string) $campaign->error_message,
                    $campaign->status === EmailCampaign::STATUS_SENDING => EmailCampaignSender::queuedMessage(count($recipients)),
                    default => 'Email campaign sent successfully',
                },
                'data' => $campaign,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create email campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create email campaign',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(EmailCampaign $emailCampaign): JsonResponse
    {
        $user = Auth::user();

        if ($emailCampaign->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($denied = $this->denyForeignRecord($emailCampaign, 'campaign')) {
            return $denied;
        }

        if ($denied = $this->denyEventCampaign($emailCampaign, $user)) {
            return $denied;
        }

        $this->recoverStalledSend($emailCampaign);
        $emailCampaign->load(['company', 'location', 'template', 'sender', 'logs']);

        $stats = [
            'total' => $emailCampaign->total_recipients,
            'sent' => $emailCampaign->logs()->where('status', 'sent')->count(),
            'failed' => $emailCampaign->logs()->where('status', 'failed')->count(),
            'pending' => $emailCampaign->logs()->where('status', 'pending')->count(),
            'opened' => $emailCampaign->logs()->where('status', 'opened')->count(),
            'clicked' => $emailCampaign->logs()->where('status', 'clicked')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $emailCampaign,
            'statistics' => $stats,
        ]);
    }

    public function cancel(EmailCampaign $emailCampaign): JsonResponse
    {
        $user = Auth::user();

        if ($emailCampaign->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($denied = $this->denyForeignRecord($emailCampaign, 'campaign')) {
            return $denied;
        }

        if ($denied = $this->denyEventCampaign($emailCampaign, $user)) {
            return $denied;
        }

        if ($refused = $this->companyWideCampaignRefusal($emailCampaign, $user)) {
            return $refused;
        }

        if (!in_array($emailCampaign->status, [EmailCampaign::STATUS_PENDING, EmailCampaign::STATUS_SENDING])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a campaign that is not pending or sending',
            ], 400);
        }

        $cancelled = EmailCampaign::query()
            ->whereKey($emailCampaign->id)
            ->whereIn('status', [EmailCampaign::STATUS_PENDING, EmailCampaign::STATUS_SENDING])
            ->update(['status' => EmailCampaign::STATUS_CANCELLED]);

        if ($cancelled === 0) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign had already finished, so there was nothing left to cancel.',
                'data' => $emailCampaign->fresh(),
            ], 409);
        }

        $emailCampaign->refresh();
        $this->sender()->cancelPending($emailCampaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign cancelled successfully',
            'data' => $emailCampaign,
        ]);
    }

    public function resend(Request $request, EmailCampaign $emailCampaign): JsonResponse
    {
        $user = Auth::user();

        if ($emailCampaign->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($denied = $this->denyForeignRecord($emailCampaign, 'campaign')) {
            return $denied;
        }

        if ($denied = $this->denyEventCampaign($emailCampaign, $user)) {
            return $denied;
        }

        if ($refused = $this->companyWideCampaignRefusal($emailCampaign, $user)) {
            return $refused;
        }

        $this->recoverStalledSend($emailCampaign);

        if ($emailCampaign->status === EmailCampaign::STATUS_SENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign is still sending. Try again once it finishes.',
            ], 409);
        }

        $resendType = $request->validate(['type' => ['sometimes', Rule::in(['failed', 'all'])]])['type'] ?? 'failed';

        try {
            $ready = ($resendType === 'failed'
                ? $emailCampaign->logs()->where('status', EmailCampaignLog::STATUS_FAILED)
                : $emailCampaign->logs())
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if ($ready === []) {
                return response()->json([
                    'success' => true,
                    'message' => 'There was no one left to send it to again.',
                    'data' => $emailCampaign->fresh(['logs']),
                ]);
            }

            $claimed = DB::transaction(function () use ($emailCampaign, $resendType) {
                $claimed = EmailCampaign::query()
                    ->whereKey($emailCampaign->id)
                    ->where('status', '!=', EmailCampaign::STATUS_SENDING)
                    ->update(['status' => EmailCampaign::STATUS_SENDING, 'sent_at' => $emailCampaign->sent_at ?? now(), 'completed_at' => null, 'error_message' => null, 'updated_at' => now()]);

                if ($claimed === 0) {
                    return 0;
                }

                $logs = fn () => EmailCampaignLog::query()->where('email_campaign_id', $emailCampaign->id);

                if ($resendType === 'failed') {
                    $logs()->where('status', EmailCampaignLog::STATUS_PENDING)
                        ->update(['status' => EmailCampaignLog::STATUS_CANCELLED, 'error_message' => 'Not sent: the campaign was cancelled.']);
                    $logs()->where('status', EmailCampaignLog::STATUS_FAILED)->update(['status' => EmailCampaignLog::STATUS_PENDING, 'error_message' => null]);
                } else {
                    $logs()->update(['status' => EmailCampaignLog::STATUS_PENDING, 'error_message' => null]);
                }

                return $claimed;
            });

            if ($claimed === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'This campaign is still sending. Try again once it finishes.',
                ], 409);
            }

            $this->syncCounts($emailCampaign);

            if (count($ready) > self::SEND_IN_REQUEST_LIMIT) {
                return response()->json([
                    'success' => true,
                    'message' => EmailCampaignSender::queuedMessage(count($ready)),
                    'data' => $emailCampaign->fresh(['logs']),
                ]);
            }

            $this->sendCampaign($emailCampaign->fresh(), $ready);
            $after = $emailCampaign->fresh(['logs']);

            return response()->json([
                'success' => true,
                'message' => match ($after->status) {
                    EmailCampaign::STATUS_FAILED => (string) $after->error_message,
                    EmailCampaign::STATUS_SENDING => EmailCampaignSender::queuedMessage(count($ready)),
                    default => 'Emails resent successfully',
                },
                'data' => $after,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to resend campaign: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to resend emails',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function previewRecipients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_types' => 'required|array|min:1',
            'recipient_types.*' => [Rule::in(EmailCampaign::RECIPIENT_TYPES)],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'recipient_filters' => 'nullable|array',
            'recipient_filters.status' => 'nullable|string',
            'recipient_filters.location_id' => 'nullable|exists:locations,id',
            'recipient_filters.registration_event_ids' => 'nullable|array|max:100',
            'recipient_filters.registration_event_ids.*' => 'integer|min:1|distinct',
            'recipient_filters.only_with_photos' => 'nullable|boolean',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        if ($denied = $this->denyForeignLocations($request, $validated)) {
            return $denied;
        }

        if (($forms = $this->eventFormsFor($request, $validated)) instanceof JsonResponse) {
            return $forms;
        }

        $user = Auth::user();

        $tempCampaign = new EmailCampaign([
            'company_id' => $user->company_id,
            'location_id' => $this->campaignLocationId($user, $validated),
            'recipient_types' => $validated['recipient_types'],
            'custom_emails' => $validated['custom_emails'] ?? [],
            'recipient_filters' => $validated['recipient_filters'] ?? [],
        ]);

        $recipients = $this->getRecipients($tempCampaign, $user);

        $summary = [];
        foreach ($recipients as $recipient) {
            $type = $recipient['type'];
            if (!isset($summary[$type])) {
                $summary[$type] = 0;
            }
            $summary[$type]++;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_recipients' => count($recipients),
                'by_type' => $summary,
                'with_photos' => count(array_filter($recipients, fn (array $recipient) => $recipient['type'] === 'event_registrant' && ($recipient['variables']['photo_link'] ?? '') !== '')),
                'sample_recipients' => array_map(fn (array $recipient) => [
                    'email' => $recipient['email'],
                    'type' => $recipient['type'],
                    'name' => $recipient['variables']['recipient_name'] ?? null,
                ], array_slice($recipients, 0, 10)),
            ],
        ]);
    }

    public function destroy(EmailCampaign $emailCampaign): JsonResponse
    {
        $user = Auth::user();

        if ($emailCampaign->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found',
            ], 404);
        }

        if ($denied = $this->denyForeignRecord($emailCampaign, 'campaign')) {
            return $denied;
        }

        if ($denied = $this->denyEventCampaign($emailCampaign, $user)) {
            return $denied;
        }

        if ($refused = $this->companyWideCampaignRefusal($emailCampaign, $user)) {
            return $refused;
        }

        $emailCampaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $user = Auth::user();

        $scope = function ($query) use ($user, $request) {
            if (!$this->isCompanyLevel($user)) {
                $own = $this->staffLocationIds($request);
                $query->where(fn ($inScope) => $inScope->whereIn('location_id', $own)->orWhereNull('location_id'));
            }

            if (EnsureStaff::effectiveRole($user) === 'attendant') {
                $query->whereJsonDoesntContain('recipient_types', EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS);
            } elseif (!$this->isCompanyLevel($user)) {
                $this->onlyOwnEventCampaigns($query, $request);
            }

            return $query;
        };

        $query = $scope(EmailCampaign::where('company_id', $user->company_id));

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        DateRange::apply($query, 'created_at', $request->start_date, $request->end_date);

        $totalCampaigns = $query->count();
        $totalEmailsSent = $query->sum('sent_count');
        $totalEmailsFailed = $query->sum('failed_count');

        $statusBreakdown = $scope(EmailCampaign::where('company_id', $user->company_id))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $recentCampaigns = $scope(EmailCampaign::where('company_id', $user->company_id))
            ->with(['sender'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_campaigns' => $totalCampaigns,
                'total_emails_sent' => $totalEmailsSent,
                'total_emails_failed' => $totalEmailsFailed,
                'success_rate' => $totalEmailsSent + $totalEmailsFailed > 0
                    ? round(($totalEmailsSent / ($totalEmailsSent + $totalEmailsFailed)) * 100, 2)
                    : 0,
                'status_breakdown' => $statusBreakdown,
                'recent_campaigns' => $recentCampaigns,
            ],
        ]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => ['required', 'string', EmailHtml::rule()],
            'test_email' => 'required|email',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,png,jpg,jpeg,gif',
        ]);

        if ($tooLarge = $this->attachmentsTooLarge($request)) {
            return $tooLarge;
        }

        $attachments = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('email-attachments/test', 'public');
                $attachments[] = [
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        }

        $user = Auth::user();

        try {
            $variables = $this->buildVariables(
                'Test Recipient',
                $validated['test_email'],
                'test',
                $user->company,
                $user->location
            ) + $this->sampleEventValues($user);

            $processedSubject = $this->sender()->stripPlaceholders($this->sender()->replaceVariables($validated['subject'], ['links' => ''] + $variables));
            $processedBody = $this->sender()->stripPlaceholders($this->sender()->replaceVariables($validated['body'], $variables, true));

            $htmlBody = $this->sender()->generateHtmlEmail($processedBody, $variables);

            $useGmailApi = GmailApiService::enabled();

            if ($useGmailApi) {
                $emailAttachments = $this->sender()->prepareAttachments($attachments);

                Log::info('Using Gmail API for test campaign email', [
                    'to' => $validated['test_email'],
                    'subject' => $processedSubject,
                    'attachments_count' => count($emailAttachments),
                ]);

                $gmailService = new GmailApiService();
                $gmailService->sendEmail(
                    $validated['test_email'],
                    $processedSubject,
                    $htmlBody,
                    $user->company?->company_name ?? 'Zap Zone',
                    $emailAttachments
                );
            } else {
                Mail::to($validated['test_email'])
                    ->send(new DynamicCampaignMail(
                        $processedSubject,
                        $processedBody,
                        []
                    ));
            }

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $validated['test_email'],
                'method' => $useGmailApi ? (\App\Services\ResendService::isConfigured() ? 'Resend' : 'Gmail API') : 'SMTP',
                'attachments_count' => count($attachments),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send test email: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function getRecipients(EmailCampaign $campaign, $user): array
    {
        $recipients = [];
        $filters = $campaign->recipient_filters ?? [];
        $company = $user->company;
        $location = $campaign->location ?? ($this->isCompanyLevel($user) ? null : $user->location);

        if (in_array(EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS, $campaign->recipient_types, true)) {
            $recipients = $this->sender()->registrantRecipients($company, $filters, null, $this->staffLocationIds(request()));
        }

        foreach ($campaign->recipient_types as $type) {
            switch ($type) {
                case 'customers':
                    $recipients = array_merge($recipients, $this->getCustomerRecipients($company, $location, $filters));
                    break;

                case 'attendants':
                    $recipients = array_merge($recipients, $this->getUserRecipients($company, $location, 'attendant', $filters));
                    break;

                case 'company_admin':
                    $recipients = array_merge($recipients, $this->getUserRecipients($company, $location, 'admin', $filters));
                    break;

                case 'location_managers':
                    $recipients = array_merge($recipients, $this->getUserRecipients($company, $location, 'location_manager', $filters));
                    break;

                case 'custom':
                    if (!empty($campaign->custom_emails)) {
                        foreach ($campaign->custom_emails as $email) {
                            $recipients[] = [
                                'email' => $email,
                                'type' => 'custom',
                                'id' => null,
                                'variables' => $this->buildVariables(
                                    'Valued Customer',
                                    $email,
                                    'custom',
                                    $company,
                                    $location
                                ),
                            ];
                        }
                    }
                    break;
            }
        }

        $uniqueRecipients = [];
        $seenEmails = [];
        foreach ($recipients as $recipient) {
            $email = strtolower(trim((string) $recipient['email']));
            $key = $recipient['type'] === 'event_registrant'
                ? $email . '#' . ($recipient['variables']['photo_session_id'] ?? '')
                : $email;

            if (isset($uniqueRecipients[$key]) || ($recipient['type'] !== 'event_registrant' && isset($seenEmails[$email]))) {
                continue;
            }

            $uniqueRecipients[$key] = $recipient;
            $seenEmails[$email] = true;
        }

        return array_values($uniqueRecipients);
    }

    protected function getCustomerRecipients($company, $location, array $filters): array
    {
        $recipients = [];

        $contactQuery = Contact::byCompany($company->id);

        if (!empty($filters['location_id'])) {
            $contactQuery->where('location_id', $filters['location_id']);
        } elseif ($location) {
            $contactQuery->where('location_id', $location->id);
        }

        if (!empty($filters['status'])) {
            $contactQuery->where('status', $filters['status']);
        } else {
            $contactQuery->where('status', 'active');
        }

        $contactQuery->whereNotNull('email')->where('email', '!=', '');

        $contacts = $contactQuery->get();

        // customers must be able to unsubscribe: nobody who already has is listed, and everyone
        // else gets the same company-wide opt-out link that event registrants get
        if (!FollowUpOptOut::isAvailable()) {
            return [];
        }

        $optedOut = $this->optedOutEmails((int) $company->id, $contacts->pluck('email')->all());
        $followUps = app(VisitFollowUpService::class);

        foreach ($contacts as $contact) {
            $email = strtolower(trim((string) $contact->email));

            if (isset($optedOut[$email])) {
                continue;
            }

            $recipients[] = [
                'email' => $contact->email,
                'type' => 'customer',
                'id' => $contact->id,
                'variables' => array_merge($this->buildVariablesForContact($contact, $company, $location), [
                    'opt_out_link' => $followUps->optOutUrl((int) $company->id, $email),
                ]),
            ];
        }

        return $recipients;
    }

    protected function getUserRecipients($company, $location, string $role, array $filters): array
    {
        $query = User::where('company_id', $company->id);

        if ($role === 'admin') {
            $query->whereIn('role', ['company_admin', 'owner']);
        } elseif ($role === 'location_manager') {
            $query->where('role', 'location_manager');
        } else {
            $query->where('role', $role);
        }

        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        } elseif ($location) {
            if (in_array($role, ['attendant', 'location_manager'])) {
                $query->where('location_id', $location->id);
            }
        }

        $query->where('status', 'active')
            ->whereNotNull('email')
            ->where('email', '!=', '');

        $users = $query->get();
        $recipients = [];

        foreach ($users as $userRecord) {
            $recipients[] = [
                'email' => $userRecord->email,
                'type' => $role === 'admin' ? 'company_admin' : ($role === 'location_manager' ? 'location_manager' : 'attendant'),
                'id' => $userRecord->id,
                'variables' => $this->buildVariablesForUser($userRecord, $company, $location),
            ];
        }

        return $recipients;
    }

    protected function buildVariablesForGuest(string $name, string $email, ?string $phone, $company, $location): array
    {
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        return array_merge(
            $this->buildVariables($name, $email, 'customer', $company, $location),
            [
                'customer_email' => $email,
                'customer_name' => $name,
                'customer_first_name' => $firstName,
                'customer_last_name' => $lastName,
                'customer_phone' => $phone ?? '',
            ]
        );
    }

    protected function buildVariablesForCustomer($customer, $company, $location): array
    {
        $fullName = trim($customer->first_name . ' ' . $customer->last_name);
        $address = trim(implode(', ', array_filter([
            $customer->address,
            $customer->city,
            $customer->state,
            $customer->zip
        ])));

        return array_merge(
            $this->buildVariables($fullName, $customer->email, 'customer', $company, $location),
            [
                'customer_email' => $customer->email,
                'customer_name' => $fullName,
                'customer_first_name' => $customer->first_name ?? '',
                'customer_last_name' => $customer->last_name ?? '',
                'customer_phone' => $customer->phone ?? '',
                'customer_address' => $address,
                'customer_total_bookings' => (string) ($customer->total_bookings ?? 0),
                'customer_total_spent' => '$' . number_format($customer->total_spent ?? 0, 2),
                'customer_last_visit' => $customer->last_visit?->format('F j, Y') ?? 'N/A',
            ]
        );
    }

    protected function buildVariablesForContact(Contact $contact, $company, $location): array
    {
        $fullName = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        return array_merge(
            $this->buildVariables($fullName, $contact->email, 'contact', $company, $location, $contact->phone),
            [
                'recipient_first_name' => $contact->first_name ?? '',
                'recipient_last_name' => $contact->last_name ?? '',
            ]
        );
    }

    protected function isCompanyLevel($user): bool
    {
        return $user !== null && in_array(\App\Http\Middleware\EnsureStaff::effectiveRole($user), ['company_admin', 'admin', 'owner'], true);
    }

    protected function campaignLocationId($user, array $validated): ?int
    {
        if (!empty($validated['location_id'])) {
            return (int) $validated['location_id'];
        }

        return $this->isCompanyLevel($user) ? null : ($this->staffLocationIds(request())[0] ?? $user->location_id);
    }

    protected function companyWideCampaignRefusal(EmailCampaign $campaign, $user): ?JsonResponse
    {
        if ($campaign->location_id !== null || $this->isCompanyLevel($user)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'This campaign went to every location, so only a company admin can change it.',
        ], 403);
    }

    private const SEND_IN_REQUEST_LIMIT = 20;

    public const MAX_ATTACHMENT_BYTES = 20971520;

    protected function attachmentsTooLarge(Request $request): ?JsonResponse
    {
        $total = collect($request->file('attachments', []))->sum(fn ($file) => (int) $file->getSize());

        if ($total <= self::MAX_ATTACHMENT_BYTES) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'The attachments add up to more than 20 MB, which is too big to email. Remove some files or share a link instead.',
            'errors' => ['attachments' => ['The attachments can add up to 20 MB at most.']],
        ], 422);
    }

    protected function eventFormsFor(Request $request, array &$validated)
    {
        if (!in_array(EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS, $validated['recipient_types'] ?? [], true)) {
            unset($validated['recipient_filters']['registration_event_ids'], $validated['recipient_filters']['only_with_photos'], $validated['recipient_filters']['registration_location_ids']);

            return null;
        }

        $user = Auth::user();

        if (!in_array(EnsureStaff::effectiveRole($user), ['company_admin', 'admin', 'owner', 'location_manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only managers and admins can email event registrants.',
            ], 403);
        }

        if (!RegistrationEvent::isAvailable()) {
            return $this->invalidEvents('Event registrations are not set up yet.');
        }

        if (!empty($validated['scheduled_at'])) {
            return $this->invalidEvents('Emails to event registrants go out right away, so they cannot be scheduled.');
        }

        $picked = $validated['recipient_filters']['registration_event_ids'] ?? [];
        $ids = is_array($picked) ? array_values(array_unique(array_map('intval', $picked))) : [];

        if ($ids === []) {
            return $this->invalidEvents('Pick at least one event.');
        }

        $forms = RegistrationEvent::query()->where('company_id', $user->company_id)->whereIn('id', $ids)->get();

        if ($forms->count() !== count($ids)) {
            return $this->invalidEvents('One of the picked events is no longer available. Refresh the list and pick again.');
        }

        $own = $this->staffLocationIds($request);

        if ($forms->contains(fn (RegistrationEvent $form) => !in_array((int) $form->location_id, $own, true))) {
            return response()->json([
                'success' => false,
                'message' => 'You can only email people who registered at your own location.',
            ], 403);
        }

        $validated['recipient_filters']['registration_event_ids'] = $ids;
        $validated['recipient_filters']['registration_location_ids'] = $forms->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return $forms;
    }

    protected function invalidEvents(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => ['recipient_filters.registration_event_ids' => [$message]],
        ], 422);
    }

    protected function denyEventCampaign(EmailCampaign $campaign, $user): ?JsonResponse
    {
        if (!in_array(EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS, (array) $campaign->recipient_types, true) || $this->isCompanyLevel($user)) {
            return null;
        }

        if (EnsureStaff::effectiveRole($user) !== 'location_manager') {
            return response()->json([
                'success' => false,
                'message' => 'Only managers and admins can open campaigns sent to event registrants.',
            ], 403);
        }

        $ids = array_map('intval', (array) ($campaign->recipient_filters['registration_event_ids'] ?? []));
        $own = $this->staffLocationIds(request());
        $locations = RegistrationEvent::withTrashed()->whereIn('id', $ids)->pluck('location_id')->map(fn ($id) => (int) $id)->unique();

        if ($locations->contains(fn (int $id) => !in_array($id, $own, true))) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign went to people who registered at other locations.',
            ], 403);
        }

        return null;
    }

    protected function denyForeignLocations(Request $request, array $validated): ?JsonResponse
    {
        foreach ([$validated['location_id'] ?? null, $validated['recipient_filters']['location_id'] ?? null] as $locationId) {
            if (!empty($locationId)) {
                $location = $this->scopedLocation($request, $locationId);

                if ($location instanceof JsonResponse) {
                    return $location;
                }
            }
        }

        return null;
    }

    protected function buildVariablesForUser($userRecord, $company, $location): array
    {
        $fullName = trim($userRecord->first_name . ' ' . $userRecord->last_name);

        return array_merge(
            $this->buildVariables($fullName, $userRecord->email, $userRecord->role, $company, $location),
            [
                'user_email' => $userRecord->email,
                'user_name' => $fullName,
                'user_first_name' => $userRecord->first_name ?? '',
                'user_last_name' => $userRecord->last_name ?? '',
                'user_role' => ucfirst(str_replace('_', ' ', $userRecord->role ?? '')),
                'user_department' => $userRecord->department ?? '',
                'user_position' => $userRecord->position ?? '',
            ]
        );
    }

    protected function onlyOwnEventCampaigns($query, Request $request): void
    {
        $own = $this->staffLocationIds($request)[0] ?? 0;

        $query->where(function ($visible) use ($own) {
            $visible->whereJsonDoesntContain('recipient_types', EmailCampaign::RECIPIENT_TYPE_EVENT_REGISTRANTS)
                ->orWhere(function ($ownEvents) use ($own) {
                    $ownEvents->whereJsonLength('recipient_filters->registration_location_ids', 1)
                        ->whereJsonContains('recipient_filters->registration_location_ids', $own);
                });
        });
    }

    protected function sampleEventValues($user): array
    {
        $location = $user->location ?? $user->company?->locations()->first();
        $photos = app(PhotoDeliveryService::class);

        return [
            'first_name' => 'there',
            'business_name' => (string) ($user->company?->company_name ?? 'Zap Zone'),
            'support_contact' => (string) config('photos.support_contact'),
            'event_name' => 'Trunk or Treat',
            'event_date' => now()->addDays(7)->format('M j, Y'),
            'photo_link' => $photos->photoLinkBase() . '/photos/sample',
            'photo_count' => '3',
            'photo_date' => now()->format('M j, Y'),
            'expires_on' => now()->addDays(30)->format('M j, Y'),
            'opt_out_link' => '',
        ] + array_diff_key($photos->linkVariables((int) $user->company_id, $location), ['links' => true])
            + ['links' => $photos->linksHtml((int) $user->company_id, $location)];
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:5120', // 5MB max
        ]);

        try {
            $file = $request->file('image');
            $filename = uniqid('email_img_') . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('email-images', $filename, 'public');

            $url = asset('storage/' . $path);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => $url,
                    'path' => $path,
                    'filename' => $filename,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ],
                'message' => 'Image uploaded successfully. Use the URL in your email body with an <img> tag.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function sender(): EmailCampaignSender
    {
        return app(EmailCampaignSender::class);
    }

    protected function optedOutEmails(int $companyId, array $emails): array
    {
        return $this->sender()->optedOutEmails($companyId, $emails);
    }

    protected function buildVariables(string $name, string $email, string $type, $company, $location, ?string $phone = null): array
    {
        return $this->sender()->buildVariables($name, $email, $type, $company, $location, $phone);
    }

    protected function sendCampaign(EmailCampaign $campaign, ?array $onlyLogIds = null): void
    {
        $this->sender()->sendCampaign($campaign, $onlyLogIds);
    }

    protected function recoverStalledSend(EmailCampaign $campaign, bool $now = false): void
    {
        $this->sender()->recoverStalledSend($campaign, $now);
    }

    protected function syncCounts(EmailCampaign $campaign): void
    {
        $this->sender()->syncCounts($campaign);
    }
}
