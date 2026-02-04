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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EmailNotificationController extends Controller
{
    /**
     * Display a listing of email notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = EmailNotification::with(['company', 'location', 'template'])
            ->where('company_id', $user->company_id);

        // Filter by location if specified
        if ($request->has('location_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('location_id', $request->location_id)
                    ->orWhereNull('location_id');
            });
        }

        // Filter by trigger type
        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }

        // Filter by entity type
        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $notifications = $query
            ->withCount('logs')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * Store a newly created email notification.
     */
    public function store(Request $request): JsonResponse
    {
        $allTriggerTypes = array_keys(EmailNotification::getAllTriggerTypes());
        $allEntityTypes = array_keys(EmailNotification::getEntityTypes());
        $allRecipientTypes = array_keys(EmailNotification::getRecipientTypes());

        $validated = $request->validate([
            'name' => 'required|string|max:255',
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

    /**
     * Display the specified email notification.
     */
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

        // Get statistics
        $stats = [
            'total_sent' => $emailNotification->logs()->sent()->count(),
            'total_failed' => $emailNotification->logs()->failed()->count(),
            'total_pending' => $emailNotification->logs()->pending()->count(),
        ];

        // Get entity names
        $entityNames = $this->getEntityNames($emailNotification);

        return response()->json([
            'success' => true,
            'data' => $emailNotification,
            'statistics' => $stats,
            'entity_names' => $entityNames,
        ]);
    }

    /**
     * Update the specified email notification.
     */
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
        ]);

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

    /**
     * Remove the specified email notification.
     */
    public function destroy(EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $emailNotification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email notification deleted successfully',
        ]);
    }

    /**
     * Toggle the active status of an email notification.
     */
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

    /**
     * Duplicate an email notification.
     */
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

    /**
     * Get available entities (packages or attractions) for selection.
     */
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

    /**
     * Get available variables for email templates.
     */
    public function getVariables(Request $request): JsonResponse
    {
        $triggerType = $request->input('trigger_type', 'booking_created');

        $variables = EmailNotificationService::getAvailableVariables();

        // Determine which variable set based on trigger type category
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

    /**
     * Get all available trigger types.
     */
    public function getTriggerTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getTriggerTypes(),
            'flat' => EmailNotification::getAllTriggerTypes(),
        ]);
    }

    /**
     * Get all available entity types.
     */
    public function getEntityTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getEntityTypes(),
        ]);
    }

    /**
     * Get all available recipient types.
     */
    public function getRecipientTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EmailNotification::getRecipientTypes(),
        ]);
    }

    /**
     * Get notification logs.
     */
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

    /**
     * Resend a failed notification.
     */
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

            // Get the original entity
            $entity = $log->notifiable;

            if (!$entity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original booking/purchase not found',
                ], 404);
            }

            // Reset log status
            $log->update(['status' => 'pending', 'error_message' => null]);

            // Trigger resend based on type
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

    /**
     * Get entity names for display.
     */
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

    /**
     * Test send a notification.
     */
    public function sendTest(Request $request, EmailNotification $emailNotification): JsonResponse
    {
        $user = Auth::user();

        if ($emailNotification->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $validated = $request->validate([
            'test_email' => 'required|email',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'purchase_id' => 'nullable|integer|exists:attraction_purchases,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'package_id' => 'nullable|integer|exists:packages,id',
            'attraction_id' => 'nullable|integer|exists:attractions,id',
        ]);

        try {
            // Build variables - use real data if IDs are provided
            $variables = $this->buildSampleVariables($emailNotification, $validated);

            // Get subject and body
            $subject = $this->replaceVariables($emailNotification->getEffectiveSubject(), $variables);
            $body = $this->replaceVariables($emailNotification->getEffectiveBody(), $variables);

            // Generate HTML
            $htmlBody = $this->generateHtmlEmail($body);

            // Send test email
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

    /**
     * Build sample variables for test emails.
     * Uses real data if IDs are provided, otherwise uses sample data.
     */
    protected function buildSampleVariables(EmailNotification $notification, array $params = []): array
    {
        $company = $notification->company;
        $location = $notification->location ?? $company?->locations()->first();

        // Common variables (always present)
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

        // Try to get real customer data
        $customer = null;
        if (!empty($params['customer_id'])) {
            $customer = Customer::find($params['customer_id']);
        }

        // Try to get real booking data
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

        // Try to get real purchase data
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

        // Try to get real package data
        $package = null;
        if (!empty($params['package_id'])) {
            $package = Package::find($params['package_id']);
        } elseif ($booking) {
            $package = $booking->package;
        }

        // Try to get real attraction data
        $attraction = null;
        if (!empty($params['attraction_id'])) {
            $attraction = Attraction::find($params['attraction_id']);
        } elseif ($purchase) {
            $attraction = $purchase->attraction;
        }

        // Build customer variables
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

        // Determine if this is a booking or purchase trigger
        $isBookingTrigger = str_starts_with($notification->trigger_type, 'booking_') ||
                           str_starts_with($notification->trigger_type, 'payment_');

        if ($isBookingTrigger) {
            return array_merge($common, $this->buildBookingVariables($booking, $package));
        } else {
            return array_merge($common, $this->buildPurchaseVariables($purchase, $attraction));
        }
    }

    /**
     * Build booking-related variables.
     */
    protected function buildBookingVariables(?Booking $booking, ?Package $package): array
    {
        if ($booking) {
            // Build real booking data
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

        // Sample booking data
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

    /**
     * Build purchase-related variables.
     */
    protected function buildPurchaseVariables(?AttractionPurchase $purchase, ?Attraction $attraction): array
    {
        if ($purchase) {
            // Build real purchase data
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

        // Sample purchase data
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

    /**
     * Generate a simple QR code placeholder HTML.
     */
    protected function generateQrCodeHtml(string $reference): string
    {
        // Use a free QR code API to generate real QR code
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($reference);
        return '<img src="' . $qrUrl . '" alt="QR Code" style="width:150px;height:150px;border-radius:8px;">';
    }

    /**
     * Generate sample QR code HTML.
     */
    protected function generateSampleQrCodeHtml(): string
    {
        return '<div style="width:150px;height:150px;background:#eee;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;color:#666;">[QR Code]</div>';
    }

    /**
     * Replace variables in content.
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
     * Generate HTML email.
     */
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
}
