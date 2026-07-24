<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\AttractionPurchase;
use App\Models\AttractionPurchaseAddOn;
use App\Models\Attraction;
use App\Mail\AttractionPurchaseReceipt;
use App\Services\GmailApiService;
use App\Services\EmailNotificationService;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\User;
use App\Models\Membership;
use App\Services\DiscountService;
use App\Services\MembershipBenefitService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttractionPurchaseController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    public function index(Request $request): JsonResponse
    {
        try {
            $query = AttractionPurchase::with(['attraction', 'customer', 'createdBy', 'addOns']);

            $authUser = $this->resolveAuthUser($request);
            if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->whereHas('attraction', function ($q) use ($authUser) {
                    $q->where('location_id', $authUser->location_id);
                });
            }
            if ($authUser && $authUser->company_id) {
                $query->whereHas('attraction.location', function ($q) use ($authUser) {
                    $q->where('company_id', $authUser->company_id);
                });
            }

        if ($request->filled('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->filled('attraction_id')) {
            $query->where('attraction_id', $request->attraction_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('start_date')) {
            $query->where('purchase_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('purchase_date', '<=', $request->end_date);
        }

        if ($request->filled('scheduled_from')) {
            $query->whereRaw('COALESCE(scheduled_date, purchase_date) >= ?', [$request->scheduled_from]);
        }
        if ($request->filled('scheduled_to')) {
            $query->whereRaw('COALESCE(scheduled_date, purchase_date) <= ?', [$request->scheduled_to]);
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('guest_name', 'like', $like)
                      ->orWhere('guest_email', 'like', $like)
                      ->orWhere('guest_phone', 'like', $like)
                      ->orWhere('transaction_id', 'like', $like)
                      ->orWhere('notes', 'like', $like)
                      ->orWhereHas('customer', function ($c) use ($like) {
                          $c->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                      })
                      ->orWhereHas('attraction', function ($a) use ($like) {
                          $a->where('name', 'like', $like);
                      });
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }

        $sortBy = $request->get('sort_by', 'purchase_date');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['purchase_date', 'total_amount', 'quantity', 'status', 'created_at', 'amount_paid', 'scheduled_date', 'updated_at', 'id'])) {
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

    public function customerPurchases(Request $request): JsonResponse
    {
        $query = AttractionPurchase::select([
                'id', 'attraction_id', 'customer_id', 'created_by',
                'guest_name', 'guest_email', 'guest_phone',
                'guest_address', 'guest_city', 'guest_state', 'guest_zip', 'guest_country',
                'quantity', 'total_amount', 'amount_paid',
                'payment_method', 'status',
                'transaction_id', 'purchase_date', 'scheduled_date', 'scheduled_time',
                'notes', 'checked_in_at',
                'created_at', 'updated_at'
            ])
            ->with([
                'attraction:id,name,price,pricing_type,category,duration,duration_unit,image,location_id',
                'attraction.location:id,name',
                'customer:id,first_name,last_name,email,phone',
                'addOns:id,name',
            ]);

        if ($request->has('guest_email')) {
            $guestEmail = $request->guest_email;
            $query->where(function ($q) use ($guestEmail) {
                $q->where('guest_email', $guestEmail)
                  ->orWhereHas('customer', function ($customerQuery) use ($guestEmail) {
                      $customerQuery->where('email', $guestEmail);
                  });
            });
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('attraction', function ($attractionQuery) use ($search) {
                    $attractionQuery->where('name', 'like', "%{$search}%");
                })
                ->orWhereHas('attraction.location', function ($locationQuery) use ($search) {
                    $locationQuery->where('name', 'like', "%{$search}%");
                });
            });
        }

        $sortBy = $request->get('sort_by', 'purchase_date');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['purchase_date', 'total_amount', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100);
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
    }

    public function store(Request $request, DiscountService $discounts): JsonResponse
    {
        $validated = $request->validate([
            'attraction_id' => 'required|exists:attractions,id',
            'customer_id' => 'nullable|exists:customers,id',
            'membership_id' => 'nullable|exists:memberships,id',
            'membership_applied' => 'nullable|array',
            'membership_applied.*.membership_plan_benefit_id' => 'nullable|integer',
            'membership_applied.*.benefit_type' => 'nullable|string',
            'membership_applied.*.value_mode' => 'nullable|string',
            'membership_applied.*.value_applied' => 'nullable|numeric',

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
            'total_amount' => 'required|numeric|min:0',
            'applied_fees' => 'nullable|array',
            'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
            'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
            'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
            'discount_amount' => 'nullable|numeric|min:0',
            'applied_discounts' => 'nullable|array',
            'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
            'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
            'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.special_pricing_id' => 'nullable|integer',
            'applied_discounts.*.source' => 'nullable|string',
            'promo_id' => 'nullable|exists:promos,id',
            'gift_card_id' => 'nullable|exists:gift_cards,id',
            'promo_code' => 'nullable|string',
            'gift_card_code' => 'nullable|string',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater', 'authorize.net'])],
            'purchase_date' => 'required|date',
            'scheduled_date' => 'nullable|date',
            'scheduled_time' => 'nullable|date_format:H:i',
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'send_email' => 'nullable|boolean', // Optional flag to control email sending

            'additional_addons' => 'nullable|array',
            'additional_addons.*.addon_id' => 'required_with:additional_addons|exists:add_ons,id',
            'additional_addons.*.quantity' => 'nullable|integer|min:1',
            'additional_addons.*.price_at_purchase' => 'nullable|numeric|min:0',
        ]);

        $duplicateQuery = AttractionPurchase::where('attraction_id', $validated['attraction_id'])
            ->where('quantity', $validated['quantity'])
            ->where('status', AttractionPurchase::STATUS_PENDING);

        if (!empty($validated['customer_id'])) {
            $duplicateQuery->where('customer_id', $validated['customer_id']);
        } else {
            $duplicateQuery->where('guest_email', $validated['guest_email'] ?? null);
        }

        $existingPending = $duplicateQuery->first();
        if ($existingPending) {
            $existingPending->load(['attraction', 'customer', 'createdBy', 'addOns']);
            Log::info('Duplicate attraction purchase prevented (existing pending found)', [
                'existing_purchase_id' => $existingPending->id,
                'attraction_id' => $validated['attraction_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'guest_email' => $validated['guest_email'] ?? null,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Attraction purchase already exists',
                'data' => $existingPending,
            ], 200);
        }

        if (!isset($validated['payment_method'])) {
            $validated['payment_method'] = 'paylater';
        }

        if (in_array($validated['payment_method'], ['in-store', 'card'])) {
            $validated['status'] = AttractionPurchase::STATUS_CONFIRMED;
        } else {
            $validated['status'] = AttractionPurchase::STATUS_PENDING;
        }

        $validated['created_by'] = auth()->id() ?? null;

        $attractionLocationId = Attraction::find($validated['attraction_id'])?->location_id;

        $purchase = DB::transaction(function () use (&$validated, $discounts, $request, $attractionLocationId) {
            $hasCode = !empty($validated['promo_id']) || !empty($validated['gift_card_id'])
                || !empty($validated['promo_code']) || !empty($validated['gift_card_code']);

            if ($hasCode) {
                $discountResult = $discounts->applyToCheckout([
                    'promo_id' => $validated['promo_id'] ?? null,
                    'gift_card_id' => $validated['gift_card_id'] ?? null,
                    'promo_code' => $validated['promo_code'] ?? null,
                    'gift_card_code' => $validated['gift_card_code'] ?? null,
                    'location_id' => $attractionLocationId,
                    'customer_id' => $validated['customer_id'] ?? null,
                    'subtotal' => (float) ($validated['total_amount'] ?? 0),
                    'items' => [['type' => 'attraction', 'id' => (int) $validated['attraction_id']]],
                    'tracking_prefix' => 'srv:attraction_purchase:' . uniqid(),
                ], $request);

                if ($discountResult['discount_amount'] > 0) {
                    $validated['total_amount'] = max(0, round(((float) ($validated['total_amount'] ?? 0)) - $discountResult['discount_amount'], 2));
                    $validated['discount_amount'] = round(((float) ($validated['discount_amount'] ?? 0)) + $discountResult['discount_amount'], 2);
                    $validated['applied_discounts'] = array_merge($validated['applied_discounts'] ?? [], $discountResult['applied_discounts']);
                    $validated['promo_id'] = $discountResult['promo_id'] ?? ($validated['promo_id'] ?? null);
                    $validated['gift_card_id'] = $discountResult['gift_card_id'] ?? ($validated['gift_card_id'] ?? null);
                }
            }

            if (!empty($validated['applied_discounts'])) {
                $membershipDiscount = collect($validated['applied_discounts'])
                    ->filter(fn($d) => str_starts_with($d['discount_name'] ?? '', 'Member Savings'))
                    ->sum('discount_amount');
                if ($membershipDiscount > 0) {
                    $validated['membership_discount'] = $membershipDiscount;
                }
            }

            return AttractionPurchase::create($validated);
        });

        if (isset($validated['additional_addons']) && is_array($validated['additional_addons'])) {
            foreach ($validated['additional_addons'] as $addon) {
                AttractionPurchaseAddOn::create([
                    'attraction_purchase_id' => $purchase->id,
                    'add_on_id' => $addon['addon_id'],
                    'quantity' => $addon['quantity'] ?? 1,
                    'price_at_purchase' => $addon['price_at_purchase'] ?? 0,
                ]);
            }
        }

        $purchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        // Create a pending waiver if a template covers this attraction, so the
        // confirmation can include the {{waiver_link}}. Non-fatal.
        try {
            app(\App\Services\WaiverService::class)->ensureForAttractionPurchase($purchase);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to create waiver for attraction purchase', [
                'attraction_purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->recordMembershipRedemptions($purchase, $validated);

        if ($purchase->customer_id && $purchase->status !== AttractionPurchase::STATUS_PENDING && (float) ($purchase->amount_paid ?? 0) > 0) {
            CustomerNotification::create([
                'customer_id' => $purchase->customer_id,
                'location_id' => $purchase->attraction->location_id ?? null,
                'type' => 'payment',
                'priority' => 'medium',
                'title' => 'Attraction Purchase Confirmed',
                'message' => "Your purchase of {$purchase->quantity} x {$purchase->attraction->name} has been confirmed. Total: $" . number_format($purchase->total_amount, 2),
                'status' => 'unread',
                'action_url' => "/attractions/purchases/{$purchase->id}",
                'action_text' => 'View Purchase',
                'metadata' => [
                    'purchase_id' => $purchase->id,
                    'attraction_id' => $purchase->attraction_id,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                ],
            ]);
        }

        $customerName = $purchase->customer ? "{$purchase->customer->first_name} {$purchase->customer->last_name}" : $purchase->guest_name;
        if ($purchase->status !== AttractionPurchase::STATUS_PENDING && $purchase->attraction->location_id && (float) ($purchase->amount_paid ?? 0) > 0) {
            Notification::create([
                'location_id' => $purchase->attraction->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'user_id' => $purchase->created_by ?? auth()->id(),
                'title' => 'New Attraction Purchase',
                'message' => "{$customerName} — {$purchase->quantity}x {$purchase->attraction->name} • $" . number_format($purchase->total_amount, 2),
                'status' => 'unread',
                'action_url' => "/attractions/purchases/{$purchase->id}",
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
                'created_by' => [
                    'user_id' => $purchase->created_by,
                    'name' => $purchase->createdBy ? $purchase->createdBy->first_name . ' ' . $purchase->createdBy->last_name : null,
                    'email' => $purchase->createdBy?->email,
                ],
                'created_at' => now()->toIso8601String(),
                'purchase_details' => [
                    'purchase_id' => $purchase->id,
                    'attraction_id' => $purchase->attraction_id,
                    'attraction_name' => $purchase->attraction->name,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'amount_paid' => $purchase->amount_paid,
                    'payment_method' => $purchase->payment_method,
                    'status' => $purchase->status,
                ],
                'customer_details' => [
                    'customer_id' => $purchase->customer_id,
                    'name' => $customerName,
                    'email' => $purchase->customer?->email ?? $purchase->guest_email,
                    'phone' => $purchase->customer?->phone ?? $purchase->guest_phone,
                ],
            ]
          );
        }

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
                            'sms_consent' => $validated['sms_consent'] ?? false,
                        ],
                        source: 'attraction_purchase',
                        tags: ['attraction_purchase', 'customer'],
                        locationId: $purchase->attraction->location_id,
                        createdBy: auth()->id()
                    );
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to create contact from attraction purchase', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }

        $sendEmail = $validated['send_email'] ?? true;
        if ($sendEmail && $purchase->status !== AttractionPurchase::STATUS_PENDING) {
            try {
                $emailNotificationService = new EmailNotificationService();
                $emailNotificationService->processPurchaseCreated($purchase);
            } catch (\Exception $e) {
                Log::warning('Failed to send automated email notifications for attraction purchase', [
                    'purchase_id' => $purchase->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('Email notifications skipped per send_email=false', [
                'purchase_id' => $purchase->id,
            ]);
        }

        $this->recordConversion('purchase_completed', $purchase, (float) ($purchase->total_amount ?? 0));

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase created successfully',
            'data' => $purchase,
        ], 201);
    }

    private function recordMembershipRedemptions(AttractionPurchase $purchase, array $validated): void
    {
        if (empty($validated['membership_id'])) {
            return;
        }

        try {
            $membership = Membership::find($validated['membership_id']);
            if (! $membership) {
                return;
            }

            // Use pre-computed applied data if sent by frontend (correct original prices)
            if (! empty($validated['membership_applied'])) {
                $locationId = $purchase->attraction->location_id ?? null;
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $purchase,
                    $validated['membership_applied'],
                    $locationId,
                    auth()->id()
                );
                return;
            }

            $qty       = max(1, (int) ($purchase->quantity ?? 1));
            $unitPrice = $qty > 0 ? (float) $purchase->total_amount / $qty : (float) $purchase->total_amount;
            $locationId = $purchase->attraction->location_id ?? null;

            $quote = app(MembershipBenefitService::class)->quote($membership, $locationId, [[
                'type'       => 'attraction',
                'id'         => $purchase->attraction_id,
                'unit_price' => $unitPrice,
                'quantity'   => $qty,
            ]]);

            if (! empty($quote['applied'])) {
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $purchase,
                    $quote['applied'],
                    $locationId,
                    auth()->id()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to record membership redemptions for attraction purchase', [
                'purchase_id' => $purchase->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function storeQrCode(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    {
        Log::info('Attraction purchase receipt email initiated', [
            'purchase_id' => $attractionPurchase->id,
        ]);

        $validated = $request->validate([
            'qr_code' => 'required|string', // Base64 encoded QR code image
            'send_email' => 'nullable|boolean', // Optional flag to control email sending
        ]);

        if (isset($validated['send_email']) && $validated['send_email'] === false) {
            Log::info('Email sending skipped per user request', [
                'purchase_id' => $attractionPurchase->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Purchase created successfully. Email sending skipped.',
            ]);
        }

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
            $qrCodeBase64 = $validated['qr_code'];
            if (strpos($qrCodeBase64, 'data:image') === 0) {
                $qrCodeBase64 = substr($qrCodeBase64, strpos($qrCodeBase64, ',') + 1);
            }

            if (!$qrCodeBase64) {
                throw new \Exception("Failed to decode QR code data");
            }

            $attractionPurchase->load(['attraction.location', 'customer', 'createdBy']);

            Log::info('Preparing email for attraction purchase', [
                'purchase_id' => $attractionPurchase->id,
                'qr_code_size' => strlen($qrCodeBase64),
                'use_gmail_api' => config('gmail.enabled', false),
            ]);

            $useGmailApi = config('gmail.enabled', false) &&
                          (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                Log::info('Using Gmail API for attraction purchase receipt', [
                    'purchase_id' => $attractionPurchase->id,
                ]);

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

                $qrCodeImage = base64_decode($qrCodeBase64);
                $tempPath = storage_path('app/temp/qr_' . $attractionPurchase->id . '_' . time() . '.png');

                if (!file_exists(storage_path('app/temp'))) {
                    mkdir(storage_path('app/temp'), 0755, true);
                }

                file_put_contents($tempPath, $qrCodeImage);

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

    public function show(AttractionPurchase $attractionPurchase): JsonResponse
    {
        $attractionPurchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        return response()->json([
            'success' => true,
            'data' => $attractionPurchase,
        ]);
    }

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
            'applied_fees' => 'nullable|array',
            'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
            'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
            'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'applied_discounts' => 'nullable|array',
            'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
            'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
            'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.special_pricing_id' => 'nullable|integer',
            'payment_method' => ['sometimes', 'nullable', Rule::in(['card', 'in-store', 'paylater', 'authorize.net'])],
            'status' => ['sometimes', Rule::in(AttractionPurchase::STATUSES)],
            'purchase_date' => 'sometimes|date',
            'scheduled_date' => 'nullable|date',
            'scheduled_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string',

            'additional_addons' => 'nullable|array',
            'additional_addons.*.addon_id' => 'required_with:additional_addons|exists:add_ons,id',
            'additional_addons.*.quantity' => 'nullable|integer|min:1',
            'additional_addons.*.price_at_purchase' => 'nullable|numeric|min:0',
        ]);

        if (isset($validated['attraction_id']) || isset($validated['quantity'])) {
            $attractionId = $validated['attraction_id'] ?? $attractionPurchase->attraction_id;
            $quantity = $validated['quantity'] ?? $attractionPurchase->quantity;

            $attraction = Attraction::findOrFail($attractionId);
            $validated['total_amount'] = $attraction->price * $quantity;
        }

        $attractionPurchase->update($validated);

        if (isset($validated['additional_addons'])) {
            AttractionPurchaseAddOn::where('attraction_purchase_id', $attractionPurchase->id)->delete();
            if (is_array($validated['additional_addons'])) {
                foreach ($validated['additional_addons'] as $addon) {
                    AttractionPurchaseAddOn::create([
                        'attraction_purchase_id' => $attractionPurchase->id,
                        'add_on_id' => $addon['addon_id'],
                        'quantity' => $addon['quantity'] ?? 1,
                        'price_at_purchase' => $addon['price_at_purchase'] ?? 0,
                    ]);
                }
            }
        }

        $attractionPurchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        $customerName = $attractionPurchase->customer ? "{$attractionPurchase->customer->first_name} {$attractionPurchase->customer->last_name}" : $attractionPurchase->guest_name;
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Attraction Purchase Updated',
            category: 'update',
            description: "Attraction purchase updated: {$attractionPurchase->attraction->name} by {$customerName}",
            userId: auth()->id(),
            locationId: $attractionPurchase->attraction->location_id ?? null,
            entityType: 'attraction_purchase',
            entityId: $attractionPurchase->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($validated),
                'purchase_details' => [
                    'purchase_id' => $attractionPurchase->id,
                    'attraction_id' => $attractionPurchase->attraction_id,
                    'attraction_name' => $attractionPurchase->attraction->name,
                    'quantity' => $attractionPurchase->quantity,
                    'total_amount' => $attractionPurchase->total_amount,
                ],
                'customer_details' => [
                    'customer_id' => $attractionPurchase->customer_id,
                    'name' => $customerName,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase updated successfully',
            'data' => $attractionPurchase,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        Log::info('Attraction purchase delete request received', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $attractionPurchase = AttractionPurchase::find($id);

            if (!$attractionPurchase) {
                Log::warning('Attraction purchase delete failed: not found', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Attraction purchase not found',
                ], 404);
            }

            $userId = null;
            $user = null;
            try {
                $authUser = auth()->user();
                if ($authUser instanceof User) {
                    $userId = $authUser->id;
                    $user = $authUser;
                }
            } catch (\Exception $e) {
                Log::info('Auth resolution skipped on attraction purchase delete', ['error' => $e->getMessage()]);
            }

            $deletedByName = $user ? "{$user->first_name} {$user->last_name}" : 'system/public';
            $attractionName = $attractionPurchase->attraction->name ?? 'Unknown';
            $purchaseId = $attractionPurchase->id;
            $locationId = $attractionPurchase->attraction->location_id ?? null;

            $deleted = $attractionPurchase->delete();

            $verify = AttractionPurchase::withTrashed()->find($purchaseId);
            Log::info('Attraction purchase delete verification', [
                'id' => $purchaseId,
                'delete_returned' => $deleted,
                'deleted_at' => $verify?->deleted_at,
                'trashed' => $verify?->trashed(),
            ]);

            if (!$deleted || !$verify?->trashed()) {
                Log::warning('Attraction purchase soft delete did not persist, forcing via query', ['id' => $purchaseId]);
                AttractionPurchase::where('id', $purchaseId)->update(['deleted_at' => now()]);
            }

            ActivityLog::log(
                action: 'Attraction Purchase Deleted',
                category: 'delete',
                description: "Attraction purchase deleted: {$attractionName} by {$deletedByName}",
                userId: $userId,
                locationId: $locationId,
                entityType: 'attraction_purchase',
                entityId: $purchaseId,
                metadata: [
                    'deleted_by' => [
                        'user_id' => $userId,
                        'name' => $deletedByName,
                        'email' => $user?->email,
                    ],
                    'deleted_at' => now()->toIso8601String(),
                    'purchase_details' => [
                        'purchase_id' => $purchaseId,
                        'attraction_name' => $attractionName,
                        'location_id' => $locationId,
                    ],
                ]
            );

            Log::info('Attraction purchase deleted successfully', ['id' => $purchaseId, 'attraction' => $attractionName]);

            return response()->json([
                'success' => true,
                'message' => 'Attraction purchase deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Attraction purchase delete failed with exception', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attraction purchase: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, AttractionPurchase $attractionPurchase): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(AttractionPurchase::STATUSES)],
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater', 'authorize.net'])],
        ]);

        $previousStatus = $attractionPurchase->status;

        if (isset($validated['amount_paid']) && !isset($validated['status'])) {
            $newAmountPaid = $validated['amount_paid'];
            if ($newAmountPaid >= $attractionPurchase->total_amount) {
                $validated['status'] = AttractionPurchase::STATUS_CONFIRMED;
            } else {
                $validated['status'] = AttractionPurchase::STATUS_PENDING;
            }
        }

        $attractionPurchase->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase status updated successfully',
            'data' => $attractionPurchase->fresh(['attraction', 'customer', 'createdBy', 'addOns']),
        ]);
    }

    public function markAsConfirmed(AttractionPurchase $attractionPurchase): JsonResponse
    {
        if ($attractionPurchase->status === AttractionPurchase::STATUS_CONFIRMED) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase is already confirmed',
            ], 400);
        }

        if ($attractionPurchase->status === AttractionPurchase::STATUS_CHECKED_IN) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase has already been checked in',
            ], 400);
        }

        $attractionPurchase->update(['status' => AttractionPurchase::STATUS_CONFIRMED]);
        $attractionPurchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Purchase marked as confirmed',
            'data' => $attractionPurchase,
        ]);
    }

    public function cancel(AttractionPurchase $attractionPurchase): JsonResponse
    {
        if ($attractionPurchase->status === AttractionPurchase::STATUS_CANCELLED) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase is already cancelled',
            ], 400);
        }

        if ($attractionPurchase->status === AttractionPurchase::STATUS_CHECKED_IN) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a checked-in purchase',
            ], 400);
        }

        $attractionPurchase->update(['status' => AttractionPurchase::STATUS_CANCELLED]);
        $attractionPurchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        app(MembershipBenefitService::class)->reverseForRedeemable($attractionPurchase, 'purchase_cancelled');

        $this->recordConversion(
            'purchase_cancelled',
            $attractionPurchase,
            -1 * (float) ($attractionPurchase->total_amount ?? 0),
            ['tracking_id' => 'srv:attraction_purchase:'.$attractionPurchase->id.':cancelled']
        );

        return response()->json([
            'success' => true,
            'message' => 'Purchase cancelled successfully',
            'data' => $attractionPurchase,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $query = AttractionPurchase::query();

        if ($request->filled('start_date')) {
            $query->where('purchase_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('purchase_date', '<=', $request->end_date);
        }

        $stats = [
            'total_purchases' => $query->count(),
            'total_revenue' => (clone $query)->whereIn('status', [AttractionPurchase::STATUS_CONFIRMED, AttractionPurchase::STATUS_CHECKED_IN])->sum('total_amount'),
            'pending_purchases' => (clone $query)->where('status', AttractionPurchase::STATUS_PENDING)->count(),
            'confirmed_purchases' => (clone $query)->where('status', AttractionPurchase::STATUS_CONFIRMED)->count(),
            'checked_in_purchases' => (clone $query)->where('status', AttractionPurchase::STATUS_CHECKED_IN)->count(),
            'cancelled_purchases' => (clone $query)->where('status', AttractionPurchase::STATUS_CANCELLED)->count(),
            'refunded_purchases' => (clone $query)->where('status', AttractionPurchase::STATUS_REFUNDED)->count(),
            'total_quantity_sold' => (clone $query)->whereIn('status', [AttractionPurchase::STATUS_CONFIRMED, AttractionPurchase::STATUS_CHECKED_IN])->sum('quantity'),
            'by_payment_method' => AttractionPurchase::selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as revenue')
                ->whereIn('status', [AttractionPurchase::STATUS_CONFIRMED, AttractionPurchase::STATUS_CHECKED_IN])
                ->groupBy('payment_method')
                ->get(),
            'top_attractions' => AttractionPurchase::with('attraction')
                ->selectRaw('attraction_id, COUNT(*) as purchase_count, SUM(quantity) as total_quantity, SUM(total_amount) as total_revenue')
                ->whereIn('status', [AttractionPurchase::STATUS_CONFIRMED, AttractionPurchase::STATUS_CHECKED_IN])
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






















public function verify(Request $request, int $id): JsonResponse
{
    try {
        $purchase = AttractionPurchase::with(['attraction', 'customer'])
            ->findOrFail($id);

        $authUser = $this->resolveAuthUser($request);
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && $purchase->attraction
            && (int) $purchase->attraction->location_id !== (int) $authUser->location_id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this purchase',
            ], 403);
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

public function checkIn(Request $request, int $id): JsonResponse
{
    try {
        $purchase = AttractionPurchase::with(['attraction', 'customer'])
            ->findOrFail($id);

        $authUser = null;
        if ($request->has('user_id')) {
            $authUser = User::find($request->user_id);
        }

        if ($purchase->status === AttractionPurchase::STATUS_CHECKED_IN) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has already been checked in',
                'data' => $purchase,
            ], 400);
        }

        if ($purchase->status === AttractionPurchase::STATUS_CANCELLED) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has been cancelled',
                'data' => $purchase,
            ], 400);
        }

        if ($purchase->status === AttractionPurchase::STATUS_REFUNDED) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has been refunded',
                'data' => $purchase,
            ], 400);
        }

        if ($purchase->status === AttractionPurchase::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This ticket has not been fully paid yet. Payment must be completed before check-in.',
                'data' => $purchase,
            ], 400);
        }

        $purchase->status = AttractionPurchase::STATUS_CHECKED_IN;
        $purchase->checked_in_at = now();
        $purchase->checked_in_by = $authUser ? $authUser->id : null;
        $purchase->save();

        $purchase->load(['attraction', 'customer']);

        $customerName = $purchase->customer
            ? ($purchase->customer->first_name . ' ' . $purchase->customer->last_name)
            : ($purchase->guest_name ?? 'Guest');

        $locationId = $purchase->attraction ? $purchase->attraction->location_id : null;

        ActivityLog::log(
            action: 'Attraction Purchase Checked In',
            category: 'check-in',
            description: "Attraction purchase #{$purchase->id} checked in for {$customerName}",
            userId: $authUser ? $authUser->id : null,
            locationId: $locationId,
            entityType: 'attraction_purchase',
            entityId: $purchase->id,
            metadata: [
                'purchase_id' => $purchase->id,
                'customer_name' => $customerName,
                'checked_in_at' => now()->toIso8601String(),
                'checked_in_by' => $authUser ? ($authUser->name ?? $authUser->email) : 'System',
                'attraction' => $purchase->attraction ? [
                    'id' => $purchase->attraction->id,
                    'name' => $purchase->attraction->name,
                ] : null,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
            ]
        );

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
                'status' => $purchase->status, // Now 'checked-in'
                'checked_in_at' => $purchase->checked_in_at,
                'checked_in_by' => $purchase->checked_in_by,
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

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Attraction Purchases Deleted',
            category: 'delete',
            description: "{$deletedCount} attraction purchases deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'attraction_purchase',
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'purchase_ids' => $validated['ids'],
                'affected_locations' => array_unique($locationIds),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} attraction purchases deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    public function trashed(Request $request): JsonResponse
    {
        try {
            $query = AttractionPurchase::onlyTrashed()->with(['attraction', 'customer', 'createdBy', 'addOns']);

            $authUser = $this->resolveAuthUser($request);
            if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->whereHas('attraction', function ($q) use ($authUser) {
                    $q->where('location_id', $authUser->location_id);
                });
            }

            if ($request->filled('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->filled('search')) {
                $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $query->where(function ($q) use ($like, $term) {
                        $q->where('guest_name', 'like', $like)
                          ->orWhere('guest_email', 'like', $like)
                          ->orWhere('guest_phone', 'like', $like)
                          ->orWhere('transaction_id', 'like', $like)
                          ->orWhere('notes', 'like', $like)
                          ->orWhereHas('customer', function ($c) use ($like) {
                              $c->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like)
                                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                          })
                          ->orWhereHas('attraction', function ($a) use ($like) {
                              $a->where('name', 'like', $like);
                          });
                        if (ctype_digit($term)) {
                            $q->orWhere('id', (int) $term);
                        }
                    });
                }
            }

            $sortBy = $request->get('sort_by', 'deleted_at');
            $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'desc';
            }
            if (!in_array($sortBy, ['deleted_at', 'purchase_date', 'total_amount', 'quantity', 'status', 'created_at', 'amount_paid', 'scheduled_date', 'updated_at', 'id'])) {
                $sortBy = 'deleted_at';
            }
            $query->orderBy($sortBy, $sortOrder);

            $perPage = min($request->get('per_page', 15), 100);
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
        } catch (\Exception $e) {
            Log::error('Error fetching trashed attraction purchases', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trashed attraction purchases',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function restore(int $id): JsonResponse
    {
        $purchase = AttractionPurchase::onlyTrashed()->findOrFail($id);

        $purchase->restore();
        $purchase->load(['attraction', 'customer', 'createdBy', 'addOns']);

        $currentUser = auth()->user();
        $customerName = $purchase->customer
            ? "{$purchase->customer->first_name} {$purchase->customer->last_name}"
            : $purchase->guest_name;

        ActivityLog::log(
            action: 'Attraction Purchase Restored',
            category: 'update',
            description: "Attraction purchase restored: {$purchase->attraction->name} by {$customerName}",
            userId: auth()->id(),
            locationId: $purchase->attraction->location_id ?? null,
            entityType: 'attraction_purchase',
            entityId: $purchase->id,
            metadata: [
                'restored_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'restored_at' => now()->toIso8601String(),
                'purchase_details' => [
                    'purchase_id' => $purchase->id,
                    'attraction_name' => $purchase->attraction->name,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase restored successfully',
            'data' => $purchase,
        ]);
    }

    public function forceDelete(int $id): JsonResponse
    {
        $purchase = AttractionPurchase::onlyTrashed()->findOrFail($id);

        $attractionName = $purchase->attraction->name ?? 'Unknown';
        $purchaseId = $purchase->id;
        $locationId = $purchase->attraction->location_id ?? null;

        $purchase->forceDelete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Attraction Purchase Permanently Deleted',
            category: 'delete',
            description: "Attraction purchase permanently deleted: {$attractionName}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'attraction_purchase',
            entityId: $purchaseId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction purchase permanently deleted',
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $purchases = AttractionPurchase::onlyTrashed()->whereIn('id', $validated['ids'])->get();
        $restoredCount = 0;

        foreach ($purchases as $purchase) {
            $purchase->restore();
            $restoredCount++;
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Attraction Purchases Restored',
            category: 'update',
            description: "{$restoredCount} attraction purchases restored in bulk operation",
            userId: auth()->id(),
            entityType: 'attraction_purchase',
            metadata: [
                'restored_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'restored_at' => now()->toIso8601String(),
                'restored_count' => $restoredCount,
                'purchase_ids' => $validated['ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$restoredCount} attraction purchases restored successfully",
            'data' => ['restored_count' => $restoredCount],
        ]);
    }

    public function publicForceDelete($id): JsonResponse
    {
        Log::info('Attraction purchase public force delete request', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $attractionPurchase = AttractionPurchase::withTrashed()->find($id);

            if (!$attractionPurchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attraction purchase not found',
                ], 404);
            }

            if (!$attractionPurchase->trashed() && $attractionPurchase->status !== AttractionPurchase::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending attraction purchases can be force deleted',
                ], 403);
            }

            $attractionName = $attractionPurchase->attraction->name ?? 'Unknown';
            $purchaseId = $attractionPurchase->id;
            $locationId = $attractionPurchase->attraction->location_id ?? null;

            $attractionPurchase->forceDelete();

            ActivityLog::log(
                action: 'Attraction Purchase Force Deleted (Payment Error)',
                category: 'delete',
                description: "Pending attraction purchase force deleted: {$attractionName}",
                userId: null,
                locationId: $locationId,
                entityType: 'attraction_purchase',
                entityId: $purchaseId,
                metadata: [
                    'reason' => 'payment_error_cleanup',
                    'attraction_name' => $attractionName,
                    'deleted_at' => now()->toIso8601String(),
                ]
            );

            Log::info('Attraction purchase force deleted successfully', ['id' => $purchaseId, 'attraction' => $attractionName]);

            return response()->json([
                'success' => true,
                'message' => 'Attraction purchase permanently deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Attraction purchase public force delete failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to force delete attraction purchase: ' . $e->getMessage(),
            ], 500);
        }
    }

}


