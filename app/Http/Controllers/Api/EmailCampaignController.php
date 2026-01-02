<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DynamicCampaignMail;
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
    /**
     * Display a listing of email campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailCampaign::with(['company', 'location', 'template', 'sender'])
            ->where('company_id', $user->company_id);

        // Filter by location if specified
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by name
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

    /**
     * Store and send a new email campaign.
     */
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
            'scheduled_at' => 'nullable|date|after:now',
            'send_now' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $user = Auth::user();

        try {
            DB::beginTransaction();

            // Create the campaign
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
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'status' => EmailCampaign::STATUS_PENDING,
            ]);

            // Get recipients
            $recipients = $this->getRecipients($campaign, $user);
            $campaign->update(['total_recipients' => count($recipients)]);

            // Create log entries for each recipient
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

            // Send immediately if requested and not scheduled
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

    /**
     * Display the specified email campaign.
     */
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

        // Get statistics
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

    /**
     * Cancel a scheduled campaign.
     */
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

    /**
     * Resend a campaign or resend to failed recipients.
     */
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
                // Only resend to failed recipients
                $logs = $emailCampaign->logs()->where('status', 'failed')->get();
            } else {
                // Resend to all recipients
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

    /**
     * Get recipient count preview before sending.
     */
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

        // Create a temporary campaign object for counting
        $tempCampaign = new EmailCampaign([
            'company_id' => $user->company_id,
            'location_id' => $validated['location_id'] ?? $user->location_id,
            'recipient_types' => $validated['recipient_types'],
            'custom_emails' => $validated['custom_emails'] ?? [],
            'recipient_filters' => $validated['recipient_filters'] ?? [],
        ]);

        $recipients = $this->getRecipients($tempCampaign, $user);

        // Group by type for summary
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

    /**
     * Delete a campaign.
     */
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

    /**
     * Get campaign statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailCampaign::where('company_id', $user->company_id);

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        // Date range filter
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

    /**
     * Send a test email before sending the actual campaign.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'test_email' => 'required|email',
        ]);

        $user = Auth::user();

        try {
            $variables = $this->buildVariables(
                'Test Recipient',
                $validated['test_email'],
                'test',
                $user->company,
                $user->location
            );

            // Replace variables in subject and body
            $processedSubject = $this->replaceVariables($validated['subject'], $variables);
            $processedBody = $this->replaceVariables($validated['body'], $variables);

            // Generate HTML email body
            $htmlBody = $this->generateHtmlEmail($processedBody, $variables);

            // Check if Gmail API should be used
            $useGmailApi = config('gmail.enabled', false) &&
                (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                Log::info('Using Gmail API for test campaign email', [
                    'to' => $validated['test_email'],
                    'subject' => $processedSubject,
                ]);

                $gmailService = new GmailApiService();
                $gmailService->sendEmail(
                    $validated['test_email'],
                    $processedSubject,
                    $htmlBody,
                    $user->company?->company_name ?? 'Zap Zone'
                );
            } else {
                // Fallback to Laravel Mail
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

    /**
     * Get all recipients based on campaign settings.
     */
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

        // Remove duplicates by email
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

    /**
     * Get customer recipients.
     */
    protected function getCustomerRecipients($company, $location, array $filters): array
    {
        $query = Customer::query();

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', 'active');
        }

        // Only get customers with valid emails
        $query->whereNotNull('email')->where('email', '!=', '');

        $customers = $query->get();
        $recipients = [];

        foreach ($customers as $customer) {
            $recipients[] = [
                'email' => $customer->email,
                'type' => 'customer',
                'id' => $customer->id,
                'variables' => $this->buildVariablesForCustomer($customer, $company, $location),
            ];
        }

        return $recipients;
    }

    /**
     * Get user recipients by role.
     */
    protected function getUserRecipients($company, $location, string $role, array $filters): array
    {
        $query = User::where('company_id', $company->id);

        // Filter by role
        if ($role === 'admin') {
            $query->whereIn('role', ['admin', 'owner']);
        } elseif ($role === 'location_manager') {
            $query->where('role', 'location_manager');
        } else {
            $query->where('role', $role);
        }

        // Apply location filter if specified
        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        } elseif ($location) {
            // If no specific filter but we have a location, filter by it for attendants/managers
            if (in_array($role, ['attendant', 'location_manager'])) {
                $query->where('location_id', $location->id);
            }
        }

        // Only active users with valid emails
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

    /**
     * Build variables for a customer.
     */
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

    /**
     * Build variables for a user (attendant/admin).
     */
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

    /**
     * Build base variables.
     */
    protected function buildVariables(string $name, string $email, string $type, $company, $location): array
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

    /**
     * Send the campaign emails.
     */
    protected function sendCampaign(EmailCampaign $campaign): void
    {
        $campaign->markAsSending();

        $logs = $campaign->logs()->where('status', EmailCampaignLog::STATUS_PENDING)->get();

        foreach ($logs as $log) {
            $this->sendSingleEmail($campaign, $log);
        }

        $campaign->markAsCompleted();
    }

    /**
     * Send a single email.
     */
    protected function sendSingleEmail(EmailCampaign $campaign, EmailCampaignLog $log): void
    {
        try {
            $variables = $log->variables_used ?? [];

            // Replace variables in subject and body
            $processedSubject = $this->replaceVariables($campaign->subject, $variables);
            $processedBody = $this->replaceVariables($campaign->body, $variables);

            // Generate HTML email body
            $htmlBody = $this->generateHtmlEmail($processedBody, $variables);

            // Check if Gmail API should be used
            $useGmailApi = config('gmail.enabled', false) &&
                (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                $gmailService = new GmailApiService();
                $gmailService->sendEmail(
                    $log->recipient_email,
                    $processedSubject,
                    $htmlBody,
                    $variables['company_name'] ?? 'Zap Zone'
                );
            } else {
                // Fallback to Laravel Mail
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

    /**
     * Replace template variables with actual values.
     */
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

    /**
     * Generate HTML email from body content.
     */
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
}
