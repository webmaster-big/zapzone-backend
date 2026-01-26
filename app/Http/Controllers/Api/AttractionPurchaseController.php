<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttractionPurchase;
use App\Models\Attraction;
use App\Mail\AttractionPurchaseReceipt;
use App\Services\GmailApiService;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class AttractionPurchaseController extends Controller
{
    /**
     * Display a listing of attraction purchases.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AttractionPurchase::with(['attraction', 'customer', 'createdBy']);

            // Role-based filtering
            if ($request->has('user_id')) {
                $authUser = User::where('id', $request->user_id)->first();
                if ($authUser && $authUser->role === 'location_manager') {
                    // Filter purchases to only show those from the manager's location
                    $query->whereHas('attraction', function ($q) use ($authUser) {
                        $q->where('location_id', $authUser->location_id);
                    });
                }
            }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by attraction
        if ($request->has('attraction_id')) {
            $query->where('attraction_id', $request->attraction_id);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment method
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('purchase_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('purchase_date', '<=', $request->end_date);
        }

        // Search by customer name, guest name, email, or attraction name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                // Search registered customers
                $q->whereHas('customer', function ($subQ) use ($search) {
                    $subQ->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                })
                // Search guest customers
                ->orWhere('guest_name', 'like', "%{$search}%")
                ->orWhere('guest_email', 'like', "%{$search}%")
                ->orWhere('guest_phone', 'like', "%{$search}%")
                // Search attractions
                ->orWhereHas('attraction', function ($subQ) use ($search) {
                    $subQ->where('name', 'like', "%{$search}%");
                });
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'purchase_date');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['purchase_date', 'total_amount', 'quantity', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100); // Max 100 items per page
        $purchases = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'purchases' => $purchases->items(),
                'pagination' => [
                    'current_page' => $purchases->currentPage(),
                    'last_page' => $purchases->lastPage(),
                    'per_page' => $purchases->perPage(),
                    'total' => $purchases->total(),
                    'from' => $purchases->firstItem(),
                    'to' => $purchases->lastItem(),
                ],
            ],
        ]);
        } catch (\PDOException $e) {
            Log::error('Database connection error in attraction purchases index', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database connection limit exceeded. Please try again in a few minutes.',
                'error' => config('app.debug') ? $e->getMessage() : 'Database error'
            ], 503);
        } catch (\Exception $e) {
            Log::error('Error fetching attraction purchases', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attraction purchases',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

 /**
     * Store a newly created attraction purchase.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attraction_id' => 'required|exists:attractions,id',
            'customer_id' => 'nullable|exists:customers,id',

            // Guest customer fields (required if no customer_id)
            'guest_name' => 'required_without:customer_id|string|max:255',
            'guest_email' => 'required_without:customer_id|email|max:255',
            'guest_phone' => 'nullable|string|max:20',
            'guest_address' => 'nullable|string|max:255',
            'guest_city' => 'nullable|string|max:100',
            'guest_state' => 'nullable|string|max:50',
            'guest_zip' => 'nullable|string|max:20',
            'guest_country' => 'nullable|string|max:100',

            'quantity' => 'required|integer|min:1',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater'])],
            'purchase_date' => 'required|date',
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'send_email' => 'nullable|boolean', // Optional flag to control email sending
        ]);

        // Get attraction to calculate total amount
        $attraction = Attraction::findOrFail($validated['attraction_id']);

        // Calculate total amount based on pricing type
        $totalAmount = $attraction->price * $validated['quantity'];

        $validated['total_amount'] = $totalAmount;
        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id() ?? null;

        $purchase = AttractionPurchase::create($validated);
        $purchase->load(['attraction', 'customer', 'createdBy']);

        // Create notification for customer
        if ($purchase->customer_id) {
            CustomerNotification::create([
                'customer_id' => $purchase->customer_id,
                'location_id' => $purchase->attraction->location_id ?? null,
                'type' => 'payment',
                'priority' => 'medium',
                'title' => 'Attraction Purchase Confirmed',
                'message' => "Your purchase of {$purchase->quantity} x {$purchase->attraction->name} has been confirmed. Total: $" . number_format($purchase->total_amount, 2),
                'status' => 'unread',
                'action_url' => "/attraction-purchases/{$purchase->id}",
                'action_text' => 'View Purchase',
                'metadata' => [
                    'purchase_id' => $purchase->id,
                    'attraction_id' => $purchase->attraction_id,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                ],
            ]);
        }

        // Create notification for location staff
        $customerName = $purchase->customer ? "{$purchase->customer->first_name} {$purchase->customer->last_name}" : $purchase->guest_name;
        if ($purchase->attraction->location_id) {
            Notification::create([
                'location_id' => $purchase->attraction->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'user_id' => $purchase->created_by ?? auth()->id(),
                'title' => 'New Attraction Purchase',
                'message' => "New purchase: {$purchase->quantity} x {$purchase->attraction->name} by {$customerName}. Total: $" . number_format($purchase->total_amount, 2),
                'status' => 'unread',
                'action_url' => "/attraction-purchases/{$purchase->id}",
                'action_text' => 'View Purchase',
                'metadata' => [
                    'purchase_id' => $purchase->id,
                    'attraction_id' => $purchase->attraction_id,
                    'customer_id' => $purchase->customer_id,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                ],
            ]);
        }

        // Log attraction purchase activity
        if($purchase->created_by){
            $purchase->load('createdBy');

        ActivityLog::log(
            action: 'Attraction Purchase Created',
            category: 'create',
            description: "Attraction purchase: {$purchase->quantity} x {$purchase->attraction->name} by {$customerName}",
            userId: $purchase->created_by,
            locationId: $purchase->attraction->location_id ?? null,
            entityType: 'attraction_purchase',
            entityId: $purchase->id,
            metadata: [
                'attraction_id' => $purchase->attraction_id,
                'customer_id' => $purchase->customer_id,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
            ]
          );
        }

        // Create or update contact from attraction purchase
        try {
            $contactEmail = $purchase->customer?->email ?? $purchase->guest_email;
            $contactName = $purchase->customer
                ? trim($purchase->customer->first_name . ' ' . $purchase->customer->last_name)
                : $purchase->guest_name;
            $contactPhone = $purchase->customer?->phone ?? $purchase->guest_phone;

            if ($contactEmail && $purchase->attraction->location_id) {
                $location = $purchase->attraction->location;
                if ($location && $location->company_id) {
                    Contact::createOrUpdateFromSource(
                        companyId: $location->company_id,
                        data: [
                            'email' => $contactEmail,
                            'name' => $contactName,
                            'phone' => $contactPhone,
                        ],
                        source: 'attraction_purchase',
                        tags: ['attraction_purchase', 'customer'],
                        locationId: $purchase->attraction->location_id,
                        createdBy: auth()->id()
                    );
                }
            }
        } catch (\Exception $e) {
            // Log but don't fail the purchase if contact creation fails
            Log::warning('Failed to create contact from attraction purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase created successfully',
            'data' => $purchase,
        ], 201);
    }

    /**
     * Store QR code and send receipt email (without storing QR on server)
     */
    public function storeQrCode(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    {
        Log::info('Attraction purchase receipt email initiated', [
            'purchase_id' => $attractionPurchase->id,
        ]);

        $validated = $request->validate([
            'qr_code' => 'required|string', // Base64 encoded QR code image
            'send_email' => 'nullable|boolean', // Optional flag to control email sending
        ]);

        // Check if email sending is disabled
        if (isset($validated['send_email']) && $validated['send_email'] === false) {
            Log::info('Email sending skipped per user request', [
                'purchase_id' => $attractionPurchase->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase created successfully. Email sending skipped.',
            ]);
        }

        // Get recipient email
        $recipientEmail = $attractionPurchase->customer
            ? $attractionPurchase->customer->email
            : $attractionPurchase->guest_email;

        Log::info('Preparing to send attraction purchase receipt', [
            'purchase_id' => $attractionPurchase->id,
            'recipient_email' => $recipientEmail,
            'has_customer' => $attractionPurchase->customer ? true : false,
        ]);

        if (!$recipientEmail) {
            Log::warning('No recipient email for attraction purchase receipt', [
                'purchase_id' => $attractionPurchase->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'No email address found for this purchase',
            ], 400);
        }

        $emailSent = false;
        $emailError = null;

        try {
            // Get base64 QR code (remove data URI prefix if present)
            $qrCodeBase64 = $validated['qr_code'];
            if (strpos($qrCodeBase64, 'data:image') === 0) {
                $qrCodeBase64 = substr($qrCodeBase64, strpos($qrCodeBase64, ',') + 1);
            }

            if (!$qrCodeBase64) {
                throw new \Exception("Failed to decode QR code data");
            }

            // Load relationships for email including location
            $attractionPurchase->load(['attraction.location', 'customer', 'createdBy']);

            Log::info('Preparing email for attraction purchase', [
                'purchase_id' => $attractionPurchase->id,
                'qr_code_size' => strlen($qrCodeBase64),
                'use_gmail_api' => config('gmail.enabled', false),
            ]);

            // Check if Gmail API should be used
            $useGmailApi = config('gmail.enabled', false) &&
                          (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                Log::info('Using Gmail API for attraction purchase receipt', [
                    'purchase_id' => $attractionPurchase->id,
                ]);

                // Send using Gmail API
                $gmailService = new GmailApiService();
                $mailable = new AttractionPurchaseReceipt($attractionPurchase, $qrCodeBase64);
                $emailBody = $mailable->render();

                $attachments = [[
                    'data' => $qrCodeBase64,
                    'filename' => 'qrcode.png',
                    'mime_type' => 'image/png'
                ]];

                $gmailService->sendEmail(
                    $recipientEmail,
                    'Your Attraction Purchase Receipt - Zap Zone',
                    $emailBody,
                    'Zap Zone',
                    $attachments
                );
            } else {
                Log::info('Using Laravel Mail (SMTP) for attraction purchase receipt', [
                    'purchase_id' => $attractionPurchase->id,
                    'mail_driver' => config('mail.default'),
                ]);

                // Decode base64 to create temporary file for attachment
                $qrCodeImage = base64_decode($qrCodeBase64);
                $tempPath = storage_path('app/temp/qr_' . $attractionPurchase->id . '_' . time() . '.png');

                // Create temp directory if not exists
                if (!file_exists(storage_path('app/temp'))) {
                    mkdir(storage_path('app/temp'), 0755, true);
                }

                file_put_contents($tempPath, $qrCodeImage);

                // Send using Laravel Mail (SMTP)
                \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($attractionPurchase, $tempPath, $recipientEmail, $qrCodeBase64) {
                    $mailable = new AttractionPurchaseReceipt($attractionPurchase, $qrCodeBase64);
                    $emailBody = $mailable->render();

                    $message->to($recipientEmail)
                        ->subject('Your Attraction Purchase Receipt - Zap Zone')
                        ->html($emailBody)
                        ->attach($tempPath, [
                            'as' => 'qrcode.png',
                            'mime' => 'image/png',
                        ]);
                });

                // Clean up temp file
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }

            $emailSent = true;

            Log::info('✅ Attraction purchase receipt sent successfully', [
                'email' => $recipientEmail,
                'purchase_id' => $attractionPurchase->id,
                'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
            ]);

        } catch (\Exception $e) {
            $emailError = $e->getMessage();

            Log::error('❌ Failed to send attraction purchase receipt', [
                'email' => $recipientEmail,
                'purchase_id' => $attractionPurchase->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send receipt email',
                'error' => $emailError,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Receipt sent successfully to ' . $recipientEmail,
            'data' => [
                'email_sent_to' => $recipientEmail,
                'email_sent' => $emailSent,
                'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
            ],
        ]);
    }

    /**
     * Display the specified attraction purchase.
     */
    public function show(AttractionPurchase $attractionPurchase): JsonResponse
    {
        $attractionPurchase->load(['attraction', 'customer', 'createdBy']);

        return response()->json([
            'success' => true,
            'data' => $attractionPurchase,
        ]);
    }

    /**
     * Update the specified attraction purchase.
     */
    public function update(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    {
        $validated = $request->validate([
            'attraction_id' => 'sometimes|exists:attractions,id',
            'customer_id' => 'sometimes|nullable|exists:customers,id',
            'guest_name' => 'sometimes|string|max:255',
            'guest_email' => 'sometimes|email|max:255',
            'guest_phone' => 'sometimes|nullable|string|max:20',
            'quantity' => 'sometimes|integer|min:1',
            'amount_paid' => 'sometimes|nullable|numeric|min:0',
            'payment_method' => ['sometimes', 'nullable', Rule::in(['card', 'in-store', 'paylater'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'cancelled'])],
            'purchase_date' => 'sometimes|date',
            'notes' => 'nullable|string',
        ]);

        // Recalculate total amount if attraction or quantity changed
        if (isset($validated['attraction_id']) || isset($validated['quantity'])) {
            $attractionId = $validated['attraction_id'] ?? $attractionPurchase->attraction_id;
            $quantity = $validated['quantity'] ?? $attractionPurchase->quantity;

            $attraction = Attraction::findOrFail($attractionId);
            $validated['total_amount'] = $attraction->price * $quantity;
        }

        $attractionPurchase->update($validated);
        $attractionPurchase->load(['attraction', 'customer', 'createdBy']);

        // Log attraction purchase update activity
        $customerName = $attractionPurchase->customer ? "{$attractionPurchase->customer->first_name} {$attractionPurchase->customer->last_name}" : $attractionPurchase->guest_name;
        ActivityLog::log(
            action: 'Attraction Purchase Updated',
            category: 'update',
            description: "Attraction purchase updated: {$attractionPurchase->attraction->name} by {$customerName}",
            userId: auth()->id(),
            locationId: $attractionPurchase->attraction->location_id ?? null,
            entityType: 'attraction_purchase',
            entityId: $attractionPurchase->id,
            metadata: [
                'attraction_id' => $attractionPurchase->attraction_id,
                'updated_fields' => array_keys($validated),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase updated successfully',
            'data' => $attractionPurchase,
        ]);
    }

    /**
     * Remove the specified attraction purchase.
     */
    public function destroy($id): JsonResponse
    {
        $attractionPurchase = AttractionPurchase::findOrFail($id);

        $user = User::findOrFail(auth()->id());

        $attractionName = $attractionPurchase->attraction->name;
        $purchaseId = $attractionPurchase->id;
        $locationId = $attractionPurchase->attraction->location_id ?? null;

        $attractionPurchase->delete();

        // Log attraction purchase deletion activity
        ActivityLog::log(
            action: 'Attraction Purchase Deleted',
            category: 'delete',
            description: "Attraction purchase deleted: {$attractionName} by {$user->first_name} {$user->last_name}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'attraction_purchase',
            entityId: $purchaseId
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase deleted successfully',
        ]);
    }

    // update status, amount paid, payment method
    public function updateStatus(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'completed', 'cancelled'])],
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater'])],
        ]);

        $attractionPurchase->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase status updated successfully',
            'data' => $attractionPurchase,
        ]);
    }

    /**
     * Mark purchase as completed.
     */
    public function markAsCompleted(AttractionPurchase $attractionPurchase): JsonResponse
    {
        if ($attractionPurchase->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase is already completed',
            ], 400);
        }

        $attractionPurchase->update(['status' => 'completed']);
        $attractionPurchase->load(['attraction', 'customer', 'createdBy']);

        return response()->json([
            'success' => true,
            'message' => 'Purchase marked as completed',
            'data' => $attractionPurchase,
        ]);
    }

    /**
     * Cancel purchase.
     */
    public function cancel(AttractionPurchase $attractionPurchase): JsonResponse
    {
        if ($attractionPurchase->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Purchase is already cancelled',
            ], 400);
        }

        $attractionPurchase->update(['status' => 'cancelled']);
        $attractionPurchase->load(['attraction', 'customer', 'createdBy']);

        return response()->json([
            'success' => true,
            'message' => 'Purchase cancelled successfully',
            'data' => $attractionPurchase,
        ]);
    }

    /**
     * Get purchase statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $query = AttractionPurchase::query();

        // Filter by date range if provided
        if ($request->has('start_date')) {
            $query->where('purchase_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('purchase_date', '<=', $request->end_date);
        }

        $stats = [
            'total_purchases' => $query->count(),
            'total_revenue' => $query->where('status', 'completed')->sum('total_amount'),
            'pending_purchases' => $query->where('status', 'pending')->count(),
            'completed_purchases' => $query->where('status', 'completed')->count(),
            'cancelled_purchases' => $query->where('status', 'cancelled')->count(),
            'total_quantity_sold' => $query->where('status', 'completed')->sum('quantity'),
            'by_payment_method' => AttractionPurchase::selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as revenue')
                ->where('status', 'completed')
                ->groupBy('payment_method')
                ->get(),
            'top_attractions' => AttractionPurchase::with('attraction')
                ->selectRaw('attraction_id, COUNT(*) as purchase_count, SUM(quantity) as total_quantity, SUM(total_amount) as total_revenue')
                ->where('status', 'completed')
                ->groupBy('attraction_id')
                ->orderBy('total_revenue', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get purchases by customer.
     */
    public function getByCustomer(int $customerId): JsonResponse
    {
        $purchases = AttractionPurchase::with(['attraction', 'createdBy'])
            ->where('customer_id', $customerId)
            ->orderBy('purchase_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $purchases,
        ]);
    }

    /**
     * Get purchases by attraction.
     */
    public function getByAttraction(int $attractionId): JsonResponse
    {
        $purchases = AttractionPurchase::with(['customer', 'createdBy'])
            ->where('attraction_id', $attractionId)
            ->orderBy('purchase_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $purchases,
        ]);
    }

    // /**
    //  * Send receipt email with QR code (API endpoint)
    //  */
    // public function sendReceipt(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'qr_code' => 'nullable|string', // Base64 encoded QR code image
    //         'email' => 'nullable|email', // Optional: override email address
    //     ]);

    //     try {
    //         // Get recipient email
    //         $recipientEmail = $validated['email'] ?? (
    //             $attractionPurchase->customer
    //                 ? $attractionPurchase->customer->email
    //                 : $attractionPurchase->guest_email
    //         );

    //         if (!$recipientEmail) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'No email address found for this purchase',
    //             ], 400);
    //         }

    //         $qrCodePath = null;

    //         // If QR code image (base64) is provided, save it temporarily
    //         if (isset($validated['qr_code']) && !empty($validated['qr_code'])) {
    //             $qrCodeImage = $validated['qr_code'];

    //             // Handle data URL format
    //             if (strpos($qrCodeImage, 'data:image') === 0) {
    //                 $qrCodePath = $this->saveQRCodeImage($qrCodeImage, $attractionPurchase->id);
    //             }
    //         }

    //         // Load relationships for email
    //         $attractionPurchase->load(['attraction', 'customer', 'createdBy']);

    //         // Send the email
    //         Mail::to($recipientEmail)->send(new AttractionPurchaseReceipt($attractionPurchase, $qrCodePath));

    //         // Clean up temporary QR code file
    //         if ($qrCodePath && file_exists($qrCodePath)) {
    //             unlink($qrCodePath);
    //         }

    //         Log::info('Receipt email sent successfully for purchase #' . $attractionPurchase->id);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Receipt email sent successfully',
    //         ]);

    //     } catch (\Exception $e) {
    //         Log::error('Failed to send receipt email for purchase #' . $attractionPurchase->id . ': ' . $e->getMessage());

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to send receipt email: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // /**
    //  * Save QR code image from base64 string
    //  */
    // private function saveQRCodeImage(string $base64Image, int $purchaseId): ?string
    // {
    //     try {
    //         // Extract base64 data (remove data:image/png;base64, prefix)
    //         if (strpos($base64Image, ',') !== false) {
    //             $imageData = substr($base64Image, strpos($base64Image, ',') + 1);
    //         } else {
    //             $imageData = $base64Image;
    //         }

    //         $imageData = base64_decode($imageData);

    //         if ($imageData === false) {
    //             throw new \Exception('Failed to decode base64 image');
    //         }

    //         // Create temporary file path
    //         $filename = 'qrcode_' . $purchaseId . '_' . time() . '.png';
    //         $tempPath = storage_path('app/temp/' . $filename);

    //         // Create temp directory if it doesn't exist
    //         $tempDir = storage_path('app/temp');
    //         if (!file_exists($tempDir)) {
    //             mkdir($tempDir, 0755, true);
    //         }

    //         // Save the file
    //         file_put_contents($tempPath, $imageData);

    //         return $tempPath;

    //     } catch (\Exception $e) {
    //         Log::error('Failed to save QR code image: ' . $e->getMessage());
    //         return null;
    //     }
    // }

/**
 * Verify a purchase ticket without modifying it
 * GET /api/attraction-purchases/{id}/verify
 */
public function verify(Request $request, int $id): JsonResponse
{
    try {
        $purchase = AttractionPurchase::with(['attraction', 'customer'])
            ->findOrFail($id);

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            if ($authUser && $authUser->role === 'location_manager') {
                // Check if purchase attraction belongs to manager's location
                if ($purchase->attraction && $purchase->attraction->location_id !== $authUser->location_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to view this purchase',
                    ], 403);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $purchase->id,
                'attraction_id' => $purchase->attraction_id,
                'customer_id' => $purchase->customer_id,
                'guest_name' => $purchase->guest_name,
                'guest_email' => $purchase->guest_email,
                'guest_phone' => $purchase->guest_phone,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
                'payment_method' => $purchase->payment_method,
                'status' => $purchase->status,
                'purchase_date' => $purchase->purchase_date,
                'notes' => $purchase->notes,
                'created_at' => $purchase->created_at,
                'updated_at' => $purchase->updated_at,
                'attraction' => $purchase->attraction ? [
                    'id' => $purchase->attraction->id,
                    'name' => $purchase->attraction->name,
                    'price' => $purchase->attraction->price,
                    'pricing_type' => $purchase->attraction->pricing_type,
                ] : null,
                'customer' => $purchase->customer ? [
                    'id' => $purchase->customer->id,
                    'first_name' => $purchase->customer->first_name,
                    'last_name' => $purchase->customer->last_name,
                    'email' => $purchase->customer->email,
                ] : null,
            ],
        ], 200);
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Purchase not found',
        ], 404);
    }
}

/**
 * Check-in a purchase ticket (mark as used/completed)
 * PATCH /api/attraction-purchases/{id}/check-in
 */
public function checkIn(int $id): JsonResponse
{
    try {
        $purchase = AttractionPurchase::with(['attraction', 'customer'])
            ->findOrFail($id);

        // Validate ticket status
        if ($purchase->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has already been used',
                'data' => $purchase,
            ], 400);
        }

        if ($purchase->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has been cancelled',
                'data' => $purchase,
            ], 400);
        }

        // Mark as completed (checked in)
        $purchase->status = 'completed';
        $purchase->save();

        // Reload with relationships
        $purchase->load(['attraction', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Ticket checked in successfully',
            'data' => [
                'id' => $purchase->id,
                'attraction_id' => $purchase->attraction_id,
                'customer_id' => $purchase->customer_id,
                'guest_name' => $purchase->guest_name,
                'guest_email' => $purchase->guest_email,
                'guest_phone' => $purchase->guest_phone,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
                'payment_method' => $purchase->payment_method,
                'status' => $purchase->status, // Now 'completed'
                'purchase_date' => $purchase->purchase_date,
                'notes' => $purchase->notes,
                'created_at' => $purchase->created_at,
                'updated_at' => $purchase->updated_at,
                'attraction' => $purchase->attraction ? [
                    'id' => $purchase->attraction->id,
                    'name' => $purchase->attraction->name,
                    'price' => $purchase->attraction->price,
                    'pricing_type' => $purchase->attraction->pricing_type,
                ] : null,
                'customer' => $purchase->customer ? [
                    'id' => $purchase->customer->id,
                    'first_name' => $purchase->customer->first_name,
                    'last_name' => $purchase->customer->last_name,
                    'email' => $purchase->customer->email,
                ] : null,
            ],
        ], 200);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Purchase not found',
        ], 404);
    }
}

    /**
     * Bulk delete attraction purchases
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:attraction_purchases,id',
        ]);

        $purchases = AttractionPurchase::with('attraction')->whereIn('id', $validated['ids'])->get();
        $deletedCount = 0;
        $locationIds = [];

        foreach ($purchases as $purchase) {
            if ($purchase->attraction && $purchase->attraction->location_id) {
                $locationIds[] = $purchase->attraction->location_id;
            }
            $purchase->delete();
            $deletedCount++;
        }

        // Log bulk deletion
        ActivityLog::log(
            action: 'Bulk Attraction Purchases Deleted',
            category: 'delete',
            description: "{$deletedCount} attraction purchases deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'attraction_purchase',
            metadata: ['deleted_count' => $deletedCount, 'ids' => $validated['ids']]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} attraction purchases deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

}


