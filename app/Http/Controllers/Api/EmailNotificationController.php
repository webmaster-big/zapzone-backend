<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EmailNotification;
use App\Models\Package;
use App\Services\EmailNotificationService;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EmailNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user->company_id) {
            $company = \App\Models\Company::find($user->company_id);
            if ($company) {
                DefaultEmailNotificationSeeder::seedForCompany($company);
            }
        }

        $query = EmailNotification::with(['company', 'location', 'template'])
            ->where('company_id', $user->company_id);

        if (in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $query->where(function ($q) use ($user) {
                $q->where('location_id', $user->location_id)
                  ->orWhereNull('location_id');
            });
        }

        if ($request->has('location_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('location_id', $request->location_id)
                    ->orWhereNull('location_id');
            });
        }

        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_default')) {
            $query->where('is_default', $request->boolean('is_default'));
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                      ->orWhere('subject', 'like', $like)
                      ->orWhere('description', 'like', $like)
                      ->orWhere('trigger_type', 'like', $like);
                });
            }
        }

        $query->withCount('logs');

        $sortBy = $request->get('sort_by');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['name', 'subject', 'is_active', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc');
        }

        $notifications = $query->paginate($request->per_page ?? 15);

        $notifications->getCollection()->transform(function ($notification) {
            $notification->effective_subject = $notification->getEffectiveSubject();
            $notification->effective_body = $notification->getEffectiveBody();
            $notification->is_subject_customized = $notification->is_default
                ? $notification->isSubjectCustomized()
                : false;
            $notification->is_body_customized = $notification->is_default
                ? $notification->isBodyCustomized()
                : false;
            return $notification;
        });

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $allTriggerTypes = array_keys(EmailNotification::getAllTriggerTypes());
        $allEntityTypes = array_keys(EmailNotification::getEntityTypes());
        $allRecipientTypes = array_keys(EmailNotification::getRecipientTypes());

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger_type' => ['required', Rule::in($allTriggerTypes)],
            'entity_type' => ['required', Rule::in($allEntityTypes)],
            'entity_ids' => 'nullable|array',
            'entity_ids.*' => 'integer',
            'email_template_id' => 'nullable|exists:email_templates,id',
            'subject' => 'required_without:email_template_id|nullable|string|max:255',
            'body' => 'required_without:email_template_id|nullable|string',
            'recipient_types' => 'required|array|min:1',
            'recipient_types.*' => [Rule::in($allRecipientTypes)],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'include_qr_code' => 'boolean',
            'is_active' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
            'send_before_hours' => 'nullable|integer|min:1',
            'send_after_hours' => 'nullable|integer|min:1',
        ]);

        $user = Auth::user();

        try {
            DB::beginTransaction();

            $notification = EmailNotification::create([
                'company_id' => $user->company_id,
                'location_id' => $validated['location_id'] ?? $user->location_id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'trigger_type' => $validated['trigger_type'],
                'entity_type' => $validated['entity_type'],
                'entity_ids' => $validated['entity_ids'] ?? [],
                'email_template_id' => $validated['email_template_id'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'body' => $validated['body'] ?? null,
                'recipient_types' => $validated['recipient_types'],
                'custom_emails' => $validated['custom_emails'] ?? [],
                'include_qr_code' => $validated['include_qr_code'] ?? true,
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => false,
                'send_before_hours' => $validated['send_before_hours'] ?? null,
                'send_after_hours' => $validated['send_after_hours'] ?? null,
            ]);

            DB::commit();

            $notification->load(['company', 'location', 'template']);

            return response()->json([
                'success' => true,
                'message' => 'Email notification created successfully',
                'data' => $notification,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create email notification: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create email notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $emailNotification->load(['company', 'location', 'template', 'logs' => function ($query) {
            $query->orderBy('created_at', 'desc')->limit(50);
        }]);

        $stats = [
            'total_sent' => $emailNotification->logs()->sent()->count(),
            'total_failed' => $emailNotification->logs()->failed()->count(),
            'total_pending' => $emailNotification->logs()->pending()->count(),
        ];

        $entityNames = $this->getEntityNames($emailNotification);

        $defaultInfo = null;
        if ($emailNotification->is_default) {
            $defaultInfo = [
                'default_key' => $emailNotification->default_key,
                'is_body_customized' => $emailNotification->isBodyCustomized(),
                'is_subject_customized' => $emailNotification->isSubjectCustomized(),
                'default_subject' => $emailNotification->default_subject,
                'default_body' => $emailNotification->default_body,
            ];
        }

        $emailNotification->effective_subject = $emailNotification->getEffectiveSubject();
        $emailNotification->effective_body = $emailNotification->getEffectiveBody();

        return response()->json([
            'success' => true,
            'data' => $emailNotification,
            'statistics' => $stats,
            'entity_names' => $entityNames,
            'default_info' => $defaultInfo,
        ]);
    }

    public function update(Request $request, EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $allTriggerTypes = array_keys(EmailNotification::getAllTriggerTypes());
        $allEntityTypes = array_keys(EmailNotification::getEntityTypes());
        $allRecipientTypes = array_keys(EmailNotification::getRecipientTypes());

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'trigger_type' => ['sometimes', 'required', Rule::in($allTriggerTypes)],
            'entity_type' => ['sometimes', 'required', Rule::in($allEntityTypes)],
            'entity_ids' => 'nullable|array',
            'entity_ids.*' => 'integer',
            'email_template_id' => 'nullable|exists:email_templates,id',
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string',
            'recipient_types' => 'sometimes|required|array|min:1',
            'recipient_types.*' => [Rule::in($allRecipientTypes)],
            'custom_emails' => 'nullable|array',
            'custom_emails.*' => 'email',
            'include_qr_code' => 'boolean',
            'is_active' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
            'send_before_hours' => 'nullable|integer|min:1',
            'send_after_hours' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($emailNotification->is_default) {
            $allowedDefaultFields = ['subject', 'body', 'is_active', 'include_qr_code', 'recipient_types', 'custom_emails', 'send_before_hours', 'send_after_hours', 'description'];
            $validated = array_intersect_key($validated, array_flip($allowedDefaultFields));
        }

        try {
            $emailNotification->update($validated);

            $emailNotification->load(['company', 'location', 'template']);

            return response()->json([
                'success' => true,
                'message' => 'Email notification updated successfully',
                'data' => $emailNotification,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update email notification: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update email notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        if ($emailNotification->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Default email notifications cannot be deleted. You can disable them or reset to default instead.',
            ], 403);
        }

        $emailNotification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email notification deleted successfully',
        ]);
    }

    public function toggleStatus(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $emailNotification->update([
            'is_active' => !$emailNotification->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email notification status updated',
            'data' => $emailNotification,
        ]);
    }

    public function duplicate(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $newNotification = $emailNotification->replicate();
        $newNotification->name = $emailNotification->name . ' (Copy)';
        $newNotification->is_active = false;
        $newNotification->save();

        $newNotification->load(['company', 'location', 'template']);

        return response()->json([
            'success' => true,
            'message' => 'Email notification duplicated successfully',
            'data' => $newNotification,
        ]);
    }

    public function getEntities(Request $request): JsonResponse
    {
        $user = Auth::user();
        $entityType = $request->input('entity_type', 'package');
        $locationId = $request->input('location_id');

        if ($entityType === 'package') {
            $query = Package::query();

            if ($locationId) {
                $query->where('location_id', $locationId);
            } elseif ($user->location_id) {
                $query->where('location_id', $user->location_id);
            }

            $entities = $query->select('id', 'name', 'location_id', 'is_active')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        } else {
            $query = Attraction::query();

            if ($locationId) {
                $query->where('location_id', $locationId);
            } elseif ($user->location_id) {
                $query->where('location_id', $user->location_id);
            }

            $entities = $query->select('id', 'name', 'location_id', 'is_active')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => $entities,
        ]);
    }

    public function getVariables(Request $request): JsonResponse
    {
        $triggerType = $request->input('trigger_type', 'booking_created');

        $variables = EmailNotificationService::getAvailableVariables();

        $category = EmailNotification::getTriggerCategory($triggerType);

        $typeMapping = [
            'booking' => 'booking',
            'payment' => 'payment',
            'purchase' => 'purchase',
            'customer' => 'customer',
            'gift_card' => 'gift_card',
            'promo' => 'common',
            'feedback' => 'common',
            'special' => 'common',
            'report' => 'report',
        ];

        $type = $typeMapping[$category] ?? 'booking';

        return response()->json([
            'success' => true,
            'data' => [
                'specific' => $variables[$type] ?? [],
                'common' => $variables['common'] ?? [],
            ],
        ]);
    }

    public function getTriggerTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getTriggerTypes(),
            'flat' => EmailNotification::getAllTriggerTypes(),
        ]);
    }

    public function getEntityTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getEntityTypes(),
        ]);
    }

    public function getRecipientTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getRecipientTypes(),
        ]);
    }

    public function getLogs(Request $request, EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $query = $emailNotification->logs();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    public function resendLog(Request $request, EmailNotification $emailNotification, int $logId): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $log = $emailNotification->logs()->findOrFail($logId);

        if ($log->status !== 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only resend failed notifications',
            ], 400);
        }

        try {
            $service = new EmailNotificationService();

            $entity = $log->notifiable;

            if (!$entity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original booking/purchase not found',
                ], 404);
            }

            $log->update(['status' => 'pending', 'error_message' => null]);

            if ($log->notifiable_type === 'App\\Models\\Booking') {
                $service->processBookingCreated($entity);
            } else {
                $service->processPurchaseCreated($entity);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification resent successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resend notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function getEntityNames(EmailNotification $notification): array
    {
        $entityIds = $notification->entity_ids ?? [];

        if (empty($entityIds)) {
            return ['All ' . ucfirst($notification->entity_type) . 's'];
        }

        if ($notification->entity_type === 'package') {
            return Package::whereIn('id', $entityIds)
                ->pluck('name')
                ->toArray();
        } else {
            return Attraction::whereIn('id', $entityIds)
                ->pluck('name')
                ->toArray();
        }
    }

    public function sendTest(Request $request, EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        if ($emailNotification->trigger_type === EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT
            && !in_array($user->role, ['company_admin', 'owner'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to send a test of this report.',
            ], 403);
        }

        $validated = $request->validate([
            'test_email' => 'required|email',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'purchase_id' => 'nullable|integer|exists:attraction_purchases,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'package_id' => 'nullable|integer|exists:packages,id',
            'attraction_id' => 'nullable|integer|exists:attractions,id',
            'date' => 'nullable|date',
        ]);

        try {
            $variables = $this->buildSampleVariables($emailNotification, $validated);

            $subject = $this->replaceVariables($emailNotification->getEffectiveSubject(), $variables);
            $body = $this->replaceVariables($emailNotification->getEffectiveBody(), $variables);

            $htmlBody = $this->generateHtmlEmail($body);

            $useGmailApi = config('gmail.enabled', false) &&
                (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                $gmailService = new \App\Services\GmailApiService();
                $gmailService->sendEmail(
                    $validated['test_email'],
                    '[TEST] ' . $subject,
                    $htmlBody,
                    $variables['company_name'] ?? 'Zap Zone',
                    []
                );
            } else {
                \Illuminate\Support\Facades\Mail::html($htmlBody, function ($message) use ($validated, $subject, $variables) {
                    $message->to($validated['test_email'])
                        ->subject('[TEST] ' . $subject)
                        ->from(config('mail.from.address'), $variables['company_name'] ?? config('mail.from.name'));
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $validated['test_email'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send test notification: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function buildSampleVariables(EmailNotification $notification, array $params = []): array
    {
        $company = $notification->company;
        $location = $notification->location ?? $company?->locations()->first();

        $common = [
            'company_name' => $company?->company_name ?? 'Sample Company',
            'company_email' => $company?->email ?? 'info@example.com',
            'company_phone' => $company?->phone ?? '(555) 123-4567',
            'company_address' => $company?->address ?? '123 Main St',
            'location_name' => $location?->name ?? 'Sample Location',
            'location_address' => $location?->address ?? '456 Business Ave, City, ST 12345',
            'location_phone' => $location?->phone ?? '(555) 987-6543',
            'location_email' => $location?->email ?? 'location@example.com',
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
        ];

        $customer = null;
        if (!empty($params['customer_id'])) {
            $customer = Customer::find($params['customer_id']);
        }

        $booking = null;
        if (!empty($params['booking_id'])) {
            $booking = Booking::with(['customer', 'package', 'room', 'addons', 'location'])->find($params['booking_id']);
            if ($booking && !$customer) {
                $customer = $booking->customer;
            }
            if ($booking && $booking->location) {
                $location = $booking->location;
                $common['location_name'] = $location->name ?? $common['location_name'];
                $common['location_address'] = $location->address ?? $common['location_address'];
                $common['location_phone'] = $location->phone ?? $common['location_phone'];
                $common['location_email'] = $location->email ?? $common['location_email'];
            }
        }

        $purchase = null;
        if (!empty($params['purchase_id'])) {
            $purchase = AttractionPurchase::with(['customer', 'attraction', 'location'])->find($params['purchase_id']);
            if ($purchase && !$customer) {
                $customer = $purchase->customer;
            }
            if ($purchase && $purchase->location) {
                $location = $purchase->location;
                $common['location_name'] = $location->name ?? $common['location_name'];
                $common['location_address'] = $location->address ?? $common['location_address'];
                $common['location_phone'] = $location->phone ?? $common['location_phone'];
                $common['location_email'] = $location->email ?? $common['location_email'];
            }
        }

        $package = null;
        if (!empty($params['package_id'])) {
            $package = Package::find($params['package_id']);
        } elseif ($booking) {
            $package = $booking->package;
        }

        $attraction = null;
        if (!empty($params['attraction_id'])) {
            $attraction = Attraction::find($params['attraction_id']);
        } elseif ($purchase) {
            $attraction = $purchase->attraction;
        }

        if ($customer) {
            $common['customer_name'] = trim($customer->first_name . ' ' . $customer->last_name);
            $common['customer_first_name'] = $customer->first_name ?? '';
            $common['customer_last_name'] = $customer->last_name ?? '';
            $common['customer_email'] = $customer->email ?? '';
            $common['customer_phone'] = $customer->phone ?? '';
        } else {
            $common['customer_name'] = 'John Doe';
            $common['customer_first_name'] = 'John';
            $common['customer_last_name'] = 'Doe';
            $common['customer_email'] = 'john.doe@example.com';
            $common['customer_phone'] = '(555) 111-2222';
        }

        if ($notification->trigger_type === EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT) {
            $tz = config('app.timezone', 'America/Detroit');
            $dateParam = $params['date'] ?? $params['report_date'] ?? null;
            $reportDate = \Carbon\Carbon::today($tz);
            if (is_string($dateParam) && $dateParam !== '') {
                try {
                    $reportDate = \Carbon\Carbon::parse($dateParam, $tz)->startOfDay();
                } catch (\Throwable) {
                    $reportDate = \Carbon\Carbon::today($tz);
                }
            }
            $reportCompanyName = $company?->company_name ?? config('mail.from.name', 'Zap Zone');

            return array_merge($common, app(\App\Services\AccountingReportService::class)
                ->buildDailyReportVariables($reportDate, $reportCompanyName, $notification->company_id));
        }

        $isBookingTrigger = str_starts_with($notification->trigger_type, 'booking_') ||
                           str_starts_with($notification->trigger_type, 'payment_');

        if ($isBookingTrigger) {
            return array_merge($common, $this->buildBookingVariables($booking, $package));
        } else {
            return array_merge($common, $this->buildPurchaseVariables($purchase, $attraction));
        }
    }

    protected function buildBookingVariables(?Booking $booking, ?Package $package): array
    {
        if ($booking) {
            $addonsHtml = '';
            $addonsTotal = 0;
            if ($booking->addons && $booking->addons->count() > 0) {
                $addonParts = [];
                foreach ($booking->addons as $addon) {
                    $qty = $addon->pivot->quantity ?? 1;
                    $price = $addon->pivot->price ?? $addon->price ?? 0;
                    $addonParts[] = $addon->name . ' x' . $qty . ' - $' . number_format($price * $qty, 2);
                    $addonsTotal += $price * $qty;
                }
                $addonsHtml = implode('<br>', $addonParts);
            }

            $room = $booking->room;
            $pkg = $package ?? $booking->package;

            return [
                'booking_id' => (string) $booking->id,
                'booking_reference' => $booking->reference_number ?? 'BK-' . str_pad($booking->id, 6, '0', STR_PAD_LEFT),
                'booking_date' => $booking->booking_date?->format('F j, Y') ?? now()->format('F j, Y'),
                'booking_time' => $booking->start_time?->format('g:i A') ?? '2:00 PM',
                'booking_status' => ucfirst($booking->status ?? 'pending'),
                'booking_participants' => (string) ($booking->participants ?? 1),
                'booking_total' => '$' . number_format($booking->total_amount ?? 0, 2),
                'booking_amount_paid' => '$' . number_format($booking->amount_paid ?? 0, 2),
                'booking_balance' => '$' . number_format(max(0, ($booking->total_amount ?? 0) - ($booking->amount_paid ?? 0)), 2),
                'booking_payment_status' => ucfirst($booking->payment_status ?? 'pending'),
                'booking_payment_method' => ucfirst($booking->payment_method ?? 'cash'),
                'booking_notes' => $booking->notes ?? '',
                'booking_created_at' => $booking->created_at?->format('F j, Y g:i A') ?? now()->format('F j, Y g:i A'),
                'package_name' => $pkg?->name ?? 'Sample Package',
                'package_description' => $pkg?->description ?? 'Package description',
                'package_duration' => ($pkg?->duration ?? 60) . ' minutes',
                'package_price' => '$' . number_format($pkg?->price ?? 0, 2),
                'package_min_participants' => (string) ($pkg?->min_participants ?? 1),
                'package_max_participants' => (string) ($pkg?->max_participants ?? 10),
                'room_name' => $room?->name ?? 'Room A',
                'room_description' => $room?->description ?? '',
                'addons_list' => $addonsHtml ?: 'None',
                'addons_total' => '$' . number_format($addonsTotal, 2),
                'qr_code' => $this->generateQrCodeHtml($booking->reference_number ?? 'BK-' . $booking->id),
                'qr_code_url' => $booking->qr_code_url ?? config('app.url') . '/api/bookings/' . $booking->id . '/qr',
            ];
        }

        return [
            'booking_id' => '12345',
            'booking_reference' => 'BK-2026-00001',
            'booking_date' => now()->addDays(7)->format('F j, Y'),
            'booking_time' => '2:00 PM',
            'booking_status' => 'Confirmed',
            'booking_participants' => '4',
            'booking_total' => '$150.00',
            'booking_amount_paid' => '$150.00',
            'booking_balance' => '$0.00',
            'booking_payment_status' => 'Paid',
            'booking_payment_method' => 'Credit Card',
            'booking_notes' => 'Birthday party',
            'booking_created_at' => now()->format('F j, Y g:i A'),
            'package_name' => $package?->name ?? 'Sample Package',
            'package_description' => $package?->description ?? 'This is a sample package description.',
            'package_duration' => ($package?->duration ?? 60) . ' minutes',
            'package_price' => '$' . number_format($package?->price ?? 35, 2),
            'package_min_participants' => (string) ($package?->min_participants ?? 2),
            'package_max_participants' => (string) ($package?->max_participants ?? 10),
            'room_name' => 'Room A',
            'room_description' => 'Main gaming room',
            'addons_list' => 'Pizza Party x2 - $30.00<br>Drinks Package x4 - $20.00',
            'addons_total' => '$50.00',
            'qr_code' => $this->generateSampleQrCodeHtml(),
            'qr_code_url' => 'https://example.com/qr/sample.png',
        ];
    }

    protected function buildPurchaseVariables(?AttractionPurchase $purchase, ?Attraction $attraction): array
    {
        if ($purchase) {
            $attr = $attraction ?? $purchase->attraction;

            return [
                'purchase_id' => (string) $purchase->id,
                'purchase_reference' => $purchase->reference_number ?? 'AP-' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT),
                'purchase_date' => $purchase->purchase_date?->format('F j, Y') ?? $purchase->created_at?->format('F j, Y') ?? now()->format('F j, Y'),
                'purchase_status' => ucfirst($purchase->status ?? 'pending'),
                'purchase_quantity' => (string) ($purchase->quantity ?? 1),
                'purchase_unit_price' => '$' . number_format($purchase->unit_price ?? 0, 2),
                'purchase_total' => '$' . number_format($purchase->total_amount ?? 0, 2),
                'purchase_amount_paid' => '$' . number_format($purchase->amount_paid ?? 0, 2),
                'purchase_balance' => '$' . number_format(max(0, ($purchase->total_amount ?? 0) - ($purchase->amount_paid ?? 0)), 2),
                'purchase_payment_method' => ucfirst($purchase->payment_method ?? 'cash'),
                'purchase_notes' => $purchase->notes ?? '',
                'purchase_created_at' => $purchase->created_at?->format('F j, Y g:i A') ?? now()->format('F j, Y g:i A'),
                'attraction_name' => $attr?->name ?? 'Sample Attraction',
                'attraction_description' => $attr?->description ?? '',
                'attraction_price' => '$' . number_format($attr?->price ?? 0, 2),
                'attraction_duration' => ($attr?->duration ?? 30) . ' minutes',
                'qr_code' => $this->generateQrCodeHtml($purchase->reference_number ?? 'AP-' . $purchase->id),
                'qr_code_url' => $purchase->qr_code_url ?? config('app.url') . '/api/attraction-purchases/' . $purchase->id . '/qr',
            ];
        }

        return [
            'purchase_id' => '67890',
            'purchase_reference' => 'AP-2026-00001',
            'purchase_date' => now()->format('F j, Y'),
            'purchase_status' => 'Completed',
            'purchase_quantity' => '2',
            'purchase_unit_price' => '$25.00',
            'purchase_total' => '$50.00',
            'purchase_amount_paid' => '$50.00',
            'purchase_balance' => '$0.00',
            'purchase_payment_method' => 'Credit Card',
            'purchase_notes' => '',
            'purchase_created_at' => now()->format('F j, Y g:i A'),
            'attraction_name' => $attraction?->name ?? 'Sample Attraction',
            'attraction_description' => $attraction?->description ?? 'This is a sample attraction description.',
            'attraction_price' => '$' . number_format($attraction?->price ?? 25, 2),
            'attraction_duration' => ($attraction?->duration ?? 30) . ' minutes',
            'qr_code' => $this->generateSampleQrCodeHtml(),
            'qr_code_url' => 'https://example.com/qr/sample.png',
        ];
    }

    protected function generateQrCodeHtml(string $reference): string
    {
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($reference);
        return '<img src="' . $qrUrl . '" alt="QR Code" style="width:150px;height:150px;border-radius:8px;">';
    }

    protected function generateSampleQrCodeHtml(): string
    {
        return '<div style="width:150px;height:150px;background:#eee;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;color:#666;">[QR Code]</div>';
    }

    protected function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $safeValue = $value ?? '';
            $content = preg_replace_callback(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                fn() => $safeValue,
                $content
            );
        }

        return $content;
    }

    protected function generateHtmlEmail(string $body): string
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


    public function getDefaults(Request $request): JsonResponse
    {
        $user = Auth::user();

        $company = $user->company;
        if ($company) {
            DefaultEmailNotificationSeeder::seedForCompany($company);
        }

        $query = EmailNotification::where('company_id', $user->company_id)
            ->where('is_default', true)
            ->with(['company', 'location']);

        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        $defaults = $query->orderBy('name')->get()->map(function ($notification) {
            return [
                'id' => $notification->id,
                'name' => $notification->name,
                'description' => $notification->description,
                'default_key' => $notification->default_key,
                'trigger_type' => $notification->trigger_type,
                'entity_type' => $notification->entity_type,
                'recipient_types' => $notification->recipient_types,
                'subject' => $notification->getEffectiveSubject(),
                'body' => $notification->getEffectiveBody(),
                'is_active' => $notification->is_active,
                'is_default' => true,
                'is_body_customized' => $notification->isBodyCustomized(),
                'is_subject_customized' => $notification->isSubjectCustomized(),
                'default_subject' => $notification->default_subject,
                'include_qr_code' => $notification->include_qr_code,
                'send_before_hours' => $notification->send_before_hours,
                'send_after_hours' => $notification->send_after_hours,
                'updated_at' => $notification->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $defaults,
            'available_keys' => EmailNotification::getDefaultKeys(),
        ]);
    }

    public function resetDefault(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        if (!$emailNotification->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Only default email notifications can be reset',
            ], 400);
        }

        $emailNotification->resetToDefault();

        $emailNotification->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Email notification reset to default template',
            'data' => [
                'id' => $emailNotification->id,
                'subject' => $emailNotification->getEffectiveSubject(),
                'body' => $emailNotification->getEffectiveBody(),
                'is_body_customized' => false,
                'is_subject_customized' => false,
            ],
        ]);
    }

    public function preview(Request $request, EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        if ($emailNotification->trigger_type === EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT
            && !in_array($user->role, ['company_admin', 'owner'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to preview this report.',
            ], 403);
        }

        $subject = $request->input('subject', $emailNotification->getEffectiveSubject());
        $body = $request->input('body', $emailNotification->getEffectiveBody());

        $variables = $this->buildSampleVariables($emailNotification, $request->all());

        $processedSubject = $this->replaceVariables($subject, $variables);
        $processedBody = $this->replaceVariables($body, $variables);

        $htmlEmail = $this->generateHtmlEmail($processedBody);

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $processedSubject,
                'body' => $processedBody,
                'html' => $htmlEmail,
                'variables_used' => $variables,
            ],
        ]);
    }

    public function seedDefaults(): JsonResponse
    {
        $user = Auth::user();
        $company = $user->company;

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Company not found',
            ], 404);
        }

        DefaultEmailNotificationSeeder::seedForCompany($company);

        $defaults = EmailNotification::where('company_id', $company->id)
            ->where('is_default', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Default email notifications seeded successfully',
            'data' => $defaults,
            'count' => $defaults->count(),
        ]);
    }

    public function getDefaultKeys(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getDefaultKeys(),
        ]);
    }
}
