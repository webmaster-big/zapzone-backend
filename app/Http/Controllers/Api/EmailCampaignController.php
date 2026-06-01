<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DynamicCampaignMail;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\GmailApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmailCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailCampaign::with(['company', 'location', 'template', 'sender'])
            ->where('company_id', $user->company_id);

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

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        $campaigns = $query->withCount(['logs as sent_emails' => function ($q) {
            $q->where('status', 'sent');
        }])
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

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
            'body' => 'required|string',
            'email_template_id' => 'nullable|exists:email_templates,id',
            'recipient_types' => 'required|array|min:1',
            'recipient_types.*' => [Rule::in(['customers', 'attendants', 'company_admin', 'location_managers', 'custom'])],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'recipient_filters' => 'nullable|array',
            'recipient_filters.status' => 'nullable|string',
            'recipient_filters.location_id' => 'nullable|exists:locations,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,png,jpg,jpeg,gif',
            'scheduled_at' => 'nullable|date|after:now',
            'send_now' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
        ]);

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

        $user = Auth::user();

        try {
            DB::beginTransaction();

            $campaign = EmailCampaign::create([
                'company_id' => $user->company_id,
                'location_id' => $validated['location_id'] ?? $user->location_id,
                'email_template_id' => $validated['email_template_id'] ?? null,
                'sent_by' => $user->id,
                'name' => $validated['name'],
                'subject' => $validated['subject'],
                'body' => $validated['body'],
                'recipient_types' => $validated['recipient_types'],
                'custom_emails' => $validated['custom_emails'] ?? [],
                'recipient_filters' => $validated['recipient_filters'] ?? [],
                'attachments' => $attachments,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'status' => EmailCampaign::STATUS_PENDING,
            ]);

            $recipients = $this->getRecipients($campaign, $user);
            $campaign->update(['total_recipients' => count($recipients)]);

            foreach ($recipients as $recipient) {
                EmailCampaignLog::create([
                    'email_campaign_id' => $campaign->id,
                    'recipient_email' => $recipient['email'],
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'] ?? null,
                    'status' => EmailCampaignLog::STATUS_PENDING,
                    'variables_used' => $recipient['variables'],
                ]);
            }

            DB::commit();

            if (($validated['send_now'] ?? true) && empty($validated['scheduled_at'])) {
                $this->sendCampaign($campaign);
            }

            $campaign->load(['company', 'location', 'template', 'sender', 'logs']);

            return response()->json([
                'success' => true,
                'message' => empty($validated['scheduled_at'])
                    ? 'Email campaign sent successfully'
                    : 'Email campaign scheduled successfully',
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

        if (!in_array($emailCampaign->status, [EmailCampaign::STATUS_PENDING, EmailCampaign::STATUS_SENDING])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a campaign that is not pending or sending',
            ], 400);
        }

        $emailCampaign->update(['status' => EmailCampaign::STATUS_CANCELLED]);

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

        $resendType = $request->input('type', 'failed'); // 'failed' or 'all'

        try {
            if ($resendType === 'failed') {
                $logs = $emailCampaign->logs()->where('status', 'failed')->get();
            } else {
                $logs = $emailCampaign->logs;
            }

            $emailCampaign->update(['status' => EmailCampaign::STATUS_SENDING]);

            foreach ($logs as $log) {
                $this->sendSingleEmail($emailCampaign, $log);
            }

            $emailCampaign->markAsCompleted();

            return response()->json([
                'success' => true,
                'message' => 'Emails resent successfully',
                'data' => $emailCampaign->fresh(['logs']),
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
            'recipient_types.*' => [Rule::in(['customers', 'attendants', 'company_admin', 'location_managers', 'custom'])],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'recipient_filters' => 'nullable|array',
            'recipient_filters.status' => 'nullable|string',
            'recipient_filters.location_id' => 'nullable|exists:locations,id',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $user = Auth::user();

        $tempCampaign = new EmailCampaign([
            'company_id' => $user->company_id,
            'location_id' => $validated['location_id'] ?? $user->location_id,
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
                'sample_recipients' => array_slice($recipients, 0, 10), // Show first 10 as sample
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

        $emailCampaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailCampaign::where('company_id', $user->company_id);

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $totalCampaigns = $query->count();
        $totalEmailsSent = $query->sum('sent_count');
        $totalEmailsFailed = $query->sum('failed_count');

        $statusBreakdown = EmailCampaign::where('company_id', $user->company_id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $recentCampaigns = EmailCampaign::where('company_id', $user->company_id)
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
            'body' => 'required|string',
            'test_email' => 'required|email',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,png,jpg,jpeg,gif',
        ]);

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
            );

            $processedSubject = $this->replaceVariables($validated['subject'], $variables);
            $processedBody = $this->replaceVariables($validated['body'], $variables);

            $htmlBody = $this->generateHtmlEmail($processedBody, $variables);

            $useGmailApi = config('gmail.enabled', false) &&
                (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                $emailAttachments = $this->prepareAttachments($attachments);

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
                        $validated['subject'],
                        $validated['body'],
                        $variables
                    ));
            }

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $validated['test_email'],
                'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
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
        $location = $campaign->location ?? $user->location;

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
            if (!in_array($recipient['email'], $seenEmails)) {
                $seenEmails[] = $recipient['email'];
                $uniqueRecipients[] = $recipient;
            }
        }

        return $uniqueRecipients;
    }

    protected function getCustomerRecipients($company, $location, array $filters): array
    {
        $recipients = [];

        $contactQuery = Contact::query();

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

        foreach ($contacts as $contact) {
            $recipients[] = [
                'email' => $contact->email,
                'type' => 'customer',
                'id' => $contact->id,
                'variables' => $this->buildVariables(
                    $contact->name,
                    $contact->email,
                    'contact',
                    $company,
                    $location,
                    $contact->phone
                ),
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

    protected function buildVariables(string $name, string $email, string $type, $company, $location, ?string $phone = null): array
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

    protected function sendCampaign(EmailCampaign $campaign): void
    {
        $campaign->markAsSending();

        $logs = $campaign->logs()->where('status', EmailCampaignLog::STATUS_PENDING)->get();

        foreach ($logs as $log) {
            $this->sendSingleEmail($campaign, $log);
        }

        $campaign->markAsCompleted();
    }

    protected function sendSingleEmail(EmailCampaign $campaign, EmailCampaignLog $log): void
    {
        try {
            $variables = $log->variables_used ?? [];

            $processedSubject = $this->replaceVariables($campaign->subject, $variables);
            $processedBody = $this->replaceVariables($campaign->body, $variables);

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
                        $campaign->subject,
                        $campaign->body,
                        $variables
                    ));
            }

            $log->markAsSent();
            $campaign->incrementSent();

        } catch (\Exception $e) {
            Log::error("Failed to send email to {$log->recipient_email}: " . $e->getMessage());
            $log->markAsFailed($e->getMessage());
            $campaign->incrementFailed();
        }
    }

    protected function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = preg_replace(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                $value ?? '',
                $content
            );
        }

        return $content;
    }

    protected function generateHtmlEmail(string $body, array $variables): string
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

    protected function prepareAttachments(array $storedAttachments): array
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
