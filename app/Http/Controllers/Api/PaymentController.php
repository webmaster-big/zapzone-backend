<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\Location;
use App\Models\Company;
use App\Models\Package;
use App\Models\AuthorizeNetAccount;
use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Mail\BookingCancellation;
use App\Mail\AttractionPurchaseCancellation;
use App\Mail\BookingConfirmation;
use App\Mail\AttractionPurchaseReceipt;
use App\Services\GmailApiService;
use App\Services\EmailNotificationService;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['customer', 'location', 'booking', 'attractionPurchase']);

        // Filter by payable_id (the ID of the booking or attraction purchase)
        if ($request->has('payable_id')) {
            $query->where('payable_id', $request->payable_id);
        }

        // Filter by payable_type ('booking' or 'attraction_purchase')
        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        // Backward compatibility: support booking_id filter (maps to payable_id with type 'booking')
        if ($request->has('booking_id')) {
            $query->where('payable_id', $request->booking_id)
                  ->where('payable_type', Payment::TYPE_BOOKING);
        }

        // Filter by attraction_purchase_id
        if ($request->has('attraction_purchase_id')) {
            $query->where('payable_id', $request->attraction_purchase_id)
                  ->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('method')) {
            $query->byMethod($request->method);
        }

        $perPage = $request->get('per_page', 15);
        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments->items(),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payable_id' => 'nullable|integer',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
            // Backward compatibility: support booking_id (will be converted to payable_id/payable_type)
            'booking_id' => 'nullable|exists:bookings,id',
            'attraction_purchase_id' => 'nullable|exists:attraction_purchases,id',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'string|size:3',
            'method' => ['required', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'notes' => 'nullable|string',
            'payment_id' => 'nullable|string|unique:payments,payment_id',
            'location_id' => 'nullable|exists:locations,id',
            'signature_image' => 'nullable|string',
            'terms_accepted' => 'nullable|boolean',
        ]);

        // Handle backward compatibility: convert booking_id to payable_id/payable_type
        if (isset($validated['booking_id']) && !isset($validated['payable_id'])) {
            $validated['payable_id'] = $validated['booking_id'];
            $validated['payable_type'] = Payment::TYPE_BOOKING;
            unset($validated['booking_id']);
        }

        // Handle attraction_purchase_id
        if (isset($validated['attraction_purchase_id']) && !isset($validated['payable_id'])) {
            $validated['payable_id'] = $validated['attraction_purchase_id'];
            $validated['payable_type'] = Payment::TYPE_ATTRACTION_PURCHASE;
            unset($validated['attraction_purchase_id']);
        }

        $validated['transaction_id'] = 'TXN' . now()->format('YmdHis') . strtoupper(Str::random(6));

        // Handle signature image upload (base64)
        if (isset($validated['signature_image']) && !empty($validated['signature_image'])) {
            $validated['signature_image'] = $this->handleSignatureUpload($validated['signature_image']);
        }

        if ($validated['status'] === 'completed') {
            $validated['paid_at'] = now();
        }

        $payment = Payment::create($validated);
        $payment->load(['customer', 'location']);

        // Create notification for customer if payment is completed
        if ($payment->customer_id && $payment->status === 'completed') {
            CustomerNotification::create([
                'customer_id' => $payment->customer_id,
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'title' => 'Payment Received',
                'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been processed successfully.",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                ],
            ]);
        }

        // Create notification for location staff if payment is completed
        if ($payment->location_id && $payment->status === 'completed') {
            Notification::create([
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'user_id' => auth()->id(),
                'title' => 'Payment Received',
                'message' => "Payment of $" . number_format($payment->amount, 2) . " received via {$payment->method}. Transaction: {$payment->transaction_id}",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'customer_id' => $payment->customer_id,
                    'payable_id' => $payment->payable_id,
                    'payable_type' => $payment->payable_type,
                ],
            ]);
        }

        // Log payment creation activity with detailed metadata
        $customerName = $payment->customer
            ? "{$payment->customer->first_name} {$payment->customer->last_name}"
            : 'Guest';

        ActivityLog::log(
            action: 'Payment Recorded',
            category: 'create',
            description: "Payment of $" . number_format($payment->amount, 2) . " recorded via {$payment->method} for {$customerName}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'transaction_id' => $payment->transaction_id,
                'recorded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'recorded_at' => now()->toIso8601String(),
                'payment_details' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency ?? 'USD',
                    'method' => $payment->method,
                    'status' => $payment->status,
                ],
                'customer' => [
                    'id' => $payment->customer_id,
                    'name' => $customerName,
                ],
                'payable' => [
                    'type' => $payment->payable_type,
                    'id' => $payment->payable_id,
                ],
                'location_id' => $payment->location_id,
                'notes' => $payment->notes,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => $payment,
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['customer', 'location']);

        // Load the related payable entity based on type
        $payableDetails = $payment->getPayableDetails();

        return response()->json([
            'success' => true,
            'data' => $payment,
            'payable' => $payableDetails,
        ]);
    }

    /**
     * Link a payment to a booking or attraction purchase.
     *
     * Use this after charging the customer and then creating the booking/purchase.
     * Flow: charge → create booking/purchase → call this to link payment to the entity.
     *
     * Updates the payable_id and payable_type on the payment record, and syncs
     * the amount_paid on the related booking or attraction purchase.
     */
    public function updatePayable(Request $request, string $id): JsonResponse
    {
        $transactionId = $request->query('transaction_id');

        // Find payment by ID or transaction_id
        $payment = $id
            ? Payment::findOrFail($id)
            : Payment::where('transaction_id', $transactionId)->firstOrFail();

        $validated = $request->validate([
            'payable_id' => 'required|integer',
            'payable_type' => ['required', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
        ]);

        // Verify the target entity exists
        if ($validated['payable_type'] === Payment::TYPE_BOOKING) {
            $payable = Booking::find($validated['payable_id']);
            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found',
                ], 404);
            }
        } elseif ($validated['payable_type'] === Payment::TYPE_ATTRACTION_PURCHASE) {
            $payable = AttractionPurchase::find($validated['payable_id']);
            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attraction purchase not found',
                ], 404);
            }
        }

        $previousPayableId = $payment->payable_id;
        $previousPayableType = $payment->payable_type;

        // Update the payment's payable link
        $payment->update($validated);

        // Sync amount_paid on the newly linked entity
        if ($payment->status === 'completed') {
            $totalPaid = Payment::where('payable_id', $validated['payable_id'])
                ->where('payable_type', $validated['payable_type'])
                ->where('status', 'completed')
                ->sum('amount');

            $payable->update(['amount_paid' => $totalPaid]);

            // Auto-determine status for attraction purchases
            if ($validated['payable_type'] === Payment::TYPE_ATTRACTION_PURCHASE) {
                if ($totalPaid >= $payable->total_amount && $payable->status === AttractionPurchase::STATUS_PENDING) {
                    $payable->update(['status' => AttractionPurchase::STATUS_CONFIRMED]);
                }
            }
        }

        // If the payment was previously linked to another entity, recalculate that entity's amount_paid
        if ($previousPayableId && $previousPayableType) {
            $previousTotalPaid = Payment::where('payable_id', $previousPayableId)
                ->where('payable_type', $previousPayableType)
                ->where('status', 'completed')
                ->sum('amount');

            if ($previousPayableType === Payment::TYPE_BOOKING) {
                $previousPayable = Booking::find($previousPayableId);
            } else {
                $previousPayable = AttractionPurchase::find($previousPayableId);
            }

            if ($previousPayable) {
                $previousPayable->update(['amount_paid' => $previousTotalPaid]);
            }
        }

        // Log activity
        ActivityLog::log(
            action: 'Payment Linked',
            category: 'update',
            description: "Payment #{$payment->id} (\${$payment->amount}) linked to {$validated['payable_type']} #{$validated['payable_id']}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'payment_id' => $payment->id,
                'payable_id' => $validated['payable_id'],
                'payable_type' => $validated['payable_type'],
                'previous_payable_id' => $previousPayableId,
                'previous_payable_type' => $previousPayableType,
                'amount' => $payment->amount,
                'linked_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
            ]
        );

        $payment->load(['customer', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Payment linked to ' . str_replace('_', ' ', $validated['payable_type']) . ' successfully',
            'data' => $payment,
            'payable' => $payable->fresh(),
        ]);
    }

    /**
     * Refund a payment via Authorize.Net
     * Allows specifying a partial or full refund amount
     * Requires the original transaction to be settled (usually 24-48 hours after capture)
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'notes' => 'sometimes|nullable|string',
            'cancel' => 'sometimes|boolean',
        ]);

        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        if ($payment->method !== 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Only Authorize.Net payments can be refunded through this endpoint'], 400);
        }

        $refundAmount = $validated['amount'] ?? $payment->amount;

        // Calculate total already refunded for this original payment
        $totalAlreadyRefunded = Payment::where('status', 'refunded')
            ->where('notes', 'like', '%Refund from Payment #' . $payment->id . ' %')
            ->sum('amount');

        $maxRefundable = round($payment->amount - $totalAlreadyRefunded, 2);

        if ($refundAmount > $maxRefundable) {
            return response()->json([
                'success' => false,
                'message' => $maxRefundable <= 0
                    ? 'This payment has already been fully refunded'
                    : 'Refund amount cannot exceed the remaining refundable balance of $' . number_format($maxRefundable, 2),
                'data' => [
                    'original_amount' => (float) $payment->amount,
                    'total_already_refunded' => $totalAlreadyRefunded,
                    'max_refundable' => $maxRefundable,
                ],
            ], 400);
        }

        try {
            // 1. Get Authorize.Net account for the location
            $account = AuthorizeNetAccount::where('location_id', $payment->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location',
                ], 503);
            }

            // 2. Get decrypted credentials
            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            // 3. Build merchant authentication
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            // 4. Create credit card payment type (last 4 digits required for refund)
            // For refunds, Authorize.Net requires a payment reference
            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber('XXXX' . substr($payment->payment_id ?? $payment->transaction_id, -4));
            $creditCard->setExpirationDate('XXXX');

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setCreditCard($creditCard);

            // 5. Create refund transaction request
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType('refundTransaction');
            $transactionRequestType->setAmount($refundAmount);
            $transactionRequestType->setPayment($paymentType);
            $transactionRequestType->setRefTransId($payment->transaction_id);

            // 6. Create and execute the request
            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            // 7. Process response
            if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    $refundTransactionId = $tresponse->getTransId();
                    $isFullRefund = ($refundAmount + $totalAlreadyRefunded) >= $payment->amount;

                    // Create a NEW refund payment record (preserves the original payment as-is)
                    $refundPayment = Payment::create([
                        'payable_id' => $payment->payable_id,
                        'payable_type' => $payment->payable_type,
                        'customer_id' => $payment->customer_id,
                        'transaction_id' => $refundTransactionId,
                        'amount' => $refundAmount,
                        'currency' => $payment->currency ?? 'USD',
                        'method' => 'authorize.net',
                        'status' => 'refunded',
                        'notes' => "Refund from Payment #{$payment->id} "
                            . ($validated['notes'] ?? '' ? " — {$validated['notes']}" : ''),
                        'refunded_at' => now(),
                        'payment_id' => $refundTransactionId,
                        'location_id' => $payment->location_id,
                    ]);

                    // Append a reference note to the original payment (do NOT change its status)
                    $payment->update([
                        'notes' => trim(($payment->notes ?? '') . "\nRefund of $" . number_format($refundAmount, 2) . " issued → Refund Payment #{$refundPayment->id} (TXN: {$refundTransactionId})"),
                    ]);

                    Log::info('💰 Authorize.Net refund successful', [
                        'original_payment_id' => $payment->id,
                        'original_transaction_id' => $payment->transaction_id,
                        'refund_payment_id' => $refundPayment->id,
                        'refund_transaction_id' => $refundTransactionId,
                        'refund_amount' => $refundAmount,
                        'original_amount' => $payment->amount,
                        'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                        'is_full_refund' => $isFullRefund,
                        'location_id' => $payment->location_id,
                    ]);

                    // Create notification for customer
                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                            'message' => "A refund of $" . number_format($refundAmount, 2) . " has been processed for your payment.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$refundPayment->id}",
                            'action_text' => 'View Refund',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'refund_payment_id' => $refundPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'refund_transaction_id' => $refundTransactionId,
                                'refund_amount' => $refundAmount,
                                'original_amount' => $payment->amount,
                                'is_full_refund' => $isFullRefund,
                                'refunded_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    // Create notification for location staff
                    if ($payment->location_id) {
                        Notification::create([
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'user_id' => auth()->id(),
                            'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                            'message' => "Refund of $" . number_format($refundAmount, 2) . " for Payment #{$payment->id} (TXN: {$payment->transaction_id}). Refund Payment #{$refundPayment->id}",
                            'status' => 'unread',
                            'action_url' => "/payments/{$refundPayment->id}",
                            'action_text' => 'View Refund',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'refund_payment_id' => $refundPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'refund_transaction_id' => $refundTransactionId,
                                'refund_amount' => $refundAmount,
                                'original_amount' => $payment->amount,
                                'customer_id' => $payment->customer_id,
                                'is_full_refund' => $isFullRefund,
                                'refunded_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    // Log activity
                    $customerName = $payment->customer
                        ? "{$payment->customer->first_name} {$payment->customer->last_name}"
                        : 'Guest';

                    ActivityLog::log(
                        action: $isFullRefund ? 'Payment Refunded' : 'Payment Partially Refunded',
                        category: 'create',
                        description: "Refund of $" . number_format($refundAmount, 2) . " processed via Authorize.Net for {$customerName}. Refund Payment #{$refundPayment->id}",
                        userId: auth()->id(),
                        locationId: $payment->location_id,
                        entityType: 'payment',
                        entityId: $refundPayment->id,
                        metadata: [
                            'original_payment_id' => $payment->id,
                            'refund_payment_id' => $refundPayment->id,
                            'transaction_id' => $payment->transaction_id,
                            'refund_transaction_id' => $refundTransactionId,
                            'refunded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                            'refunded_at' => now()->toIso8601String(),
                            'payment_details' => [
                                'original_amount' => $payment->amount,
                                'refund_amount' => $refundAmount,
                                'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                                'remaining_balance' => $maxRefundable - $refundAmount,
                                'is_full_refund' => $isFullRefund,
                                'method' => $payment->method,
                            ],
                            'customer' => [
                                'id' => $payment->customer_id,
                                'name' => $customerName,
                            ],
                            'payable' => [
                                'type' => $payment->payable_type,
                                'id' => $payment->payable_id,
                            ],
                            'notes' => $validated['notes'] ?? null,
                        ]
                    );

                    // Update the related booking or attraction purchase
                    $isCancelled = $validated['cancel'] ?? $isFullRefund;
                    $payable = null;

                    if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
                        $payable = Booking::find($payment->payable_id);
                        if ($payable) {
                            if ($isCancelled) {
                                $payable->update([
                                    'status' => 'cancelled',
                                    'payment_status' => 'refunded',
                                    'cancelled_at' => now(),
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                ]);
                            } else {
                                $payable->update([
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                    'payment_status' => ($payable->amount_paid - $refundAmount) <= 0 ? 'refunded' : 'partial',
                                ]);
                            }

                            // Send cancellation email
                            if ($isCancelled) {
                                $email = $payable->customer_email;
                                if ($email) {
                                    try {
                                        Mail::to($email)->send(new BookingCancellation($payable, $refundPayment, $refundAmount, 'refund'));
                                        Log::info('📧 Booking cancellation email sent', ['booking_id' => $payable->id, 'email' => $email]);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send booking cancellation email', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                                    }
                                }
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
                        $payable = AttractionPurchase::find($payment->payable_id);
                        if ($payable) {
                            if ($isCancelled) {
                                $payable->update([
                                    'status' => $isFullRefund ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                ]);
                            } else {
                                $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                                $payable->update([
                                    'amount_paid' => $newAmountPaid,
                                    'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                                ]);
                            }

                            // Send cancellation email
                            if ($isCancelled) {
                                $email = $payable->customer_email;
                                if ($email) {
                                    try {
                                        Mail::to($email)->send(new AttractionPurchaseCancellation($payable, $refundPayment, $refundAmount, 'refund'));
                                        Log::info('📧 Attraction purchase cancellation email sent', ['purchase_id' => $payable->id, 'email' => $email]);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send attraction purchase cancellation email', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                                    }
                                }
                            }
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => $isFullRefund ? 'Full refund processed successfully' : 'Partial refund processed successfully',
                        'data' => [
                            'original_payment' => $payment->fresh(),
                            'refund_payment' => $refundPayment->fresh(),
                        ],
                        'refund_transaction_id' => $refundTransactionId,
                        'refund_amount' => $refundAmount,
                        'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                        'remaining_balance' => $maxRefundable - $refundAmount,
                        'is_full_refund' => $isFullRefund,
                        'payable_cancelled' => $isCancelled,
                        'payable' => $payable?->fresh(),
                    ]);
                } else {
                    $errorMessage = 'Refund transaction failed';
                    $errorCode = null;
                    if ($tresponse && $tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net refund transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'transaction_id' => $payment->transaction_id,
                        'location_id' => $payment->location_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                $errorMessage = 'Unknown error';
                $errorCode = null;
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();
                }

                Log::error('Authorize.Net refund API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'transaction_id' => $payment->transaction_id,
                    'location_id' => $payment->location_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_code' => $errorCode,
                ], 400);
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Refund - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $payment->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Refund processing exception', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment->transaction_id,
                'location_id' => $payment->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Refund processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manual refund for non-Authorize.Net payments (cash, in-store, card)
     * Creates a new payment record with 'refunded' status to track the refund.
     * Updates the related booking or attraction purchase accordingly.
     * No external payment gateway call — the actual money return is handled by staff in person.
     */
    public function manualRefund(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'notes' => 'required|string|max:1000',
            'cancel' => 'sometimes|boolean',
        ]);

        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        if ($payment->method === 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Authorize.Net payments must be refunded through the /refund endpoint'], 400);
        }

        $refundAmount = $validated['amount'] ?? $payment->amount;

        // Calculate total already refunded for this original payment
        $totalAlreadyRefunded = Payment::where('status', 'refunded')
            ->where('notes', 'like', '%Refund from Payment #' . $payment->id . ' %')
            ->sum('amount');

        $maxRefundable = round($payment->amount - $totalAlreadyRefunded, 2);

        if ($refundAmount > $maxRefundable) {
            return response()->json([
                'success' => false,
                'message' => $maxRefundable <= 0
                    ? 'This payment has already been fully refunded'
                    : 'Refund amount cannot exceed the remaining refundable balance of $' . number_format($maxRefundable, 2),
                'data' => [
                    'original_amount' => (float) $payment->amount,
                    'total_already_refunded' => $totalAlreadyRefunded,
                    'max_refundable' => $maxRefundable,
                ],
            ], 400);
        }

        $isFullRefund = ($refundAmount + $totalAlreadyRefunded) >= $payment->amount;

        // Create a NEW refund payment record
        $refundPayment = Payment::create([
            'payable_id' => $payment->payable_id,
            'payable_type' => $payment->payable_type,
            'customer_id' => $payment->customer_id,
            'transaction_id' => 'REFUND-' . $payment->transaction_id . '-' . strtoupper(Str::random(4)),
            'amount' => $refundAmount,
            'currency' => $payment->currency ?? 'USD',
            'method' => $payment->method,
            'status' => 'refunded',
            'notes' => "Refund from Payment #{$payment->id} (Original TXN: {$payment->transaction_id}) — {$validated['notes']}",
            'refunded_at' => now(),
            'payment_id' => null,
            'location_id' => $payment->location_id,
        ]);

        // Append reference note to the original payment
        $payment->update([
            'notes' => trim(($payment->notes ?? '') . "\nManual refund of $" . number_format($refundAmount, 2) . " issued → Refund Payment #{$refundPayment->id}"),
        ]);

        Log::info('💰 Manual refund processed', [
            'original_payment_id' => $payment->id,
            'refund_payment_id' => $refundPayment->id,
            'refund_amount' => $refundAmount,
            'original_amount' => $payment->amount,
            'total_refunded' => $totalAlreadyRefunded + $refundAmount,
            'is_full_refund' => $isFullRefund,
            'method' => $payment->method,
            'location_id' => $payment->location_id,
        ]);

        // Create notification for customer
        if ($payment->customer_id) {
            CustomerNotification::create([
                'customer_id' => $payment->customer_id,
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'high',
                'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                'message' => "A refund of $" . number_format($refundAmount, 2) . " has been processed for your {$payment->method} payment.",
                'status' => 'unread',
                'action_url' => "/payments/{$refundPayment->id}",
                'action_text' => 'View Refund',
                'metadata' => [
                    'original_payment_id' => $payment->id,
                    'refund_payment_id' => $refundPayment->id,
                    'refund_amount' => $refundAmount,
                    'original_amount' => $payment->amount,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                    'refunded_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        // Create notification for location staff
        if ($payment->location_id) {
            Notification::create([
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'high',
                'user_id' => auth()->id(),
                'title' => $isFullRefund ? 'Manual Refund Processed' : 'Manual Partial Refund Processed',
                'message' => "Manual refund of $" . number_format($refundAmount, 2) . " ({$payment->method}) for Payment #{$payment->id}. Refund Payment #{$refundPayment->id}",
                'status' => 'unread',
                'action_url' => "/payments/{$refundPayment->id}",
                'action_text' => 'View Refund',
                'metadata' => [
                    'original_payment_id' => $payment->id,
                    'refund_payment_id' => $refundPayment->id,
                    'refund_amount' => $refundAmount,
                    'original_amount' => $payment->amount,
                    'customer_id' => $payment->customer_id,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                    'refunded_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        // Log activity
        $customerName = $payment->customer
            ? "{$payment->customer->first_name} {$payment->customer->last_name}"
            : 'Guest';

        ActivityLog::log(
            action: $isFullRefund ? 'Manual Payment Refund' : 'Manual Partial Payment Refund',
            category: 'create',
            description: "Manual refund of $" . number_format($refundAmount, 2) . " ({$payment->method}) for {$customerName}. Refund Payment #{$refundPayment->id}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $refundPayment->id,
            metadata: [
                'original_payment_id' => $payment->id,
                'refund_payment_id' => $refundPayment->id,
                'transaction_id' => $payment->transaction_id,
                'refunded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'refunded_at' => now()->toIso8601String(),
                'payment_details' => [
                    'original_amount' => $payment->amount,
                    'refund_amount' => $refundAmount,
                    'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                    'remaining_balance' => $maxRefundable - $refundAmount,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                ],
                'customer' => [
                    'id' => $payment->customer_id,
                    'name' => $customerName,
                ],
                'payable' => [
                    'type' => $payment->payable_type,
                    'id' => $payment->payable_id,
                ],
                'notes' => $validated['notes'],
            ]
        );

        // Update the related booking or attraction purchase
        $isCancelled = $validated['cancel'] ?? $isFullRefund;
        $payable = null;

        if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
            $payable = Booking::find($payment->payable_id);
            if ($payable) {
                if ($isCancelled) {
                    $payable->update([
                        'status' => 'cancelled',
                        'payment_status' => 'refunded',
                        'cancelled_at' => now(),
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                    ]);
                } else {
                    $payable->update([
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                        'payment_status' => ($payable->amount_paid - $refundAmount) <= 0 ? 'refunded' : 'partial',
                    ]);
                }

                // Send cancellation email
                if ($isCancelled) {
                    $email = $payable->customer_email;
                    if ($email) {
                        try {
                            Mail::to($email)->send(new BookingCancellation($payable, $refundPayment, $refundAmount, 'refund'));
                            Log::info('📧 Booking cancellation email sent (manual refund)', ['booking_id' => $payable->id, 'email' => $email]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send booking cancellation email (manual refund)', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                        }
                    }
                }
            }
        } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
            $payable = AttractionPurchase::find($payment->payable_id);
            if ($payable) {
                if ($isCancelled) {
                    $payable->update([
                        'status' => $isFullRefund ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                    ]);
                } else {
                    $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                    $payable->update([
                        'amount_paid' => $newAmountPaid,
                        'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                    ]);
                }

                // Send cancellation email
                if ($isCancelled) {
                    $email = $payable->customer_email;
                    if ($email) {
                        try {
                            Mail::to($email)->send(new AttractionPurchaseCancellation($payable, $refundPayment, $refundAmount, 'refund'));
                            Log::info('📧 Attraction purchase cancellation email sent (manual refund)', ['purchase_id' => $payable->id, 'email' => $email]);
                        } catch (\Exception $e) {
                            Log::error('Failed to send attraction purchase cancellation email (manual refund)', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                        }
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => $isFullRefund ? 'Full manual refund processed successfully' : 'Partial manual refund processed successfully',
            'data' => [
                'original_payment' => $payment->fresh(),
                'refund_payment' => $refundPayment->fresh(),
            ],
            'refund_amount' => $refundAmount,
            'total_refunded' => $totalAlreadyRefunded + $refundAmount,
            'remaining_balance' => $maxRefundable - $refundAmount,
            'is_full_refund' => $isFullRefund,
            'payable_cancelled' => $isCancelled,
            'payable' => $payable?->fresh(),
        ]);
    }

    /**
     * Void a transaction via Authorize.Net
     * Used for unsettled transactions (before the batch is settled, typically within 24 hours)
     */
    public function voidTransaction(Payment $payment): JsonResponse
    {
        if (!in_array($payment->status, ['completed', 'pending'])) {
            return response()->json(['success' => false, 'message' => 'Only completed or pending payments can be voided'], 400);
        }

        if ($payment->method !== 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Only Authorize.Net payments can be voided through this endpoint'], 400);
        }

        try {
            // 1. Get Authorize.Net account for the location
            $account = AuthorizeNetAccount::where('location_id', $payment->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location',
                ], 503);
            }

            // 2. Get decrypted credentials
            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            // 3. Build merchant authentication
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            // 4. Create void transaction request
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType('voidTransaction');
            $transactionRequestType->setRefTransId($payment->transaction_id);

            // 5. Create and execute the request
            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            // 6. Process response
            if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    $previousStatus = $payment->status;
                    $voidAmount = $payment->amount;

                    // Update original payment status to voided
                    $payment->update([
                        'status' => 'voided',
                        'refunded_at' => now(),
                    ]);

                    // Create a NEW void payment record for the audit trail
                    $voidPayment = Payment::create([
                        'payable_id' => $payment->payable_id,
                        'payable_type' => $payment->payable_type,
                        'customer_id' => $payment->customer_id,
                        'transaction_id' => 'VOID-' . $payment->transaction_id,
                        'amount' => $voidAmount,
                        'currency' => $payment->currency ?? 'USD',
                        'method' => 'authorize.net',
                        'status' => 'voided',
                        'notes' => "Void of Payment #{$payment->id} (Original TXN: {$payment->transaction_id})",
                        'refunded_at' => now(),
                        'payment_id' => 'VOID-' . ($payment->payment_id ?? $payment->transaction_id),
                        'location_id' => $payment->location_id,
                    ]);

                    // Append reference note to original payment
                    $payment->update([
                        'notes' => trim(($payment->notes ?? '') . "\nTransaction voided → Void Payment #{$voidPayment->id}"),
                    ]);

                    Log::info('🚫 Authorize.Net void successful', [
                        'original_payment_id' => $payment->id,
                        'void_payment_id' => $voidPayment->id,
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $voidAmount,
                        'location_id' => $payment->location_id,
                    ]);

                    // Create notification for customer
                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'title' => 'Payment Voided',
                            'message' => "Your payment of $" . number_format($voidAmount, 2) . " has been voided.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$voidPayment->id}",
                            'action_text' => 'View Details',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'void_payment_id' => $voidPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'amount' => $voidAmount,
                                'voided_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    // Create notification for location staff
                    if ($payment->location_id) {
                        Notification::create([
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'user_id' => auth()->id(),
                            'title' => 'Payment Voided',
                            'message' => "Payment #{$payment->id} of $" . number_format($voidAmount, 2) . " has been voided. Void Payment #{$voidPayment->id}",
                            'status' => 'unread',
                            'action_url' => "/payments/{$voidPayment->id}",
                            'action_text' => 'View Details',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'void_payment_id' => $voidPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'amount' => $voidAmount,
                                'customer_id' => $payment->customer_id,
                                'voided_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    // Log activity
                    $customerName = $payment->customer
                        ? "{$payment->customer->first_name} {$payment->customer->last_name}"
                        : 'Guest';

                    ActivityLog::log(
                        action: 'Payment Voided',
                        category: 'create',
                        description: "Payment #{$payment->id} of $" . number_format($voidAmount, 2) . " voided via Authorize.Net for {$customerName}. Void Payment #{$voidPayment->id}",
                        userId: auth()->id(),
                        locationId: $payment->location_id,
                        entityType: 'payment',
                        entityId: $voidPayment->id,
                        metadata: [
                            'original_payment_id' => $payment->id,
                            'void_payment_id' => $voidPayment->id,
                            'transaction_id' => $payment->transaction_id,
                            'voided_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                            'voided_at' => now()->toIso8601String(),
                            'payment_details' => [
                                'amount' => $voidAmount,
                                'method' => $payment->method,
                                'original_status' => $previousStatus,
                                'new_status' => 'voided',
                            ],
                            'customer' => [
                                'id' => $payment->customer_id,
                                'name' => $customerName,
                            ],
                            'payable' => [
                                'type' => $payment->payable_type,
                                'id' => $payment->payable_id,
                            ],
                        ]
                    );

                    // Update the related booking or attraction purchase (void = always cancel)
                    $payable = null;

                    if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
                        $payable = Booking::find($payment->payable_id);
                        if ($payable) {
                            $payable->update([
                                'status' => 'cancelled',
                                'payment_status' => 'refunded',
                                'cancelled_at' => now(),
                                'amount_paid' => max(0, $payable->amount_paid - $voidAmount),
                            ]);

                            // Send cancellation email
                            $email = $payable->customer_email;
                            if ($email) {
                                try {
                                    Mail::to($email)->send(new BookingCancellation($payable, $voidPayment, $voidAmount, 'void'));
                                    Log::info('📧 Booking void cancellation email sent', ['booking_id' => $payable->id, 'email' => $email]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send booking void cancellation email', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                                }
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
                        $payable = AttractionPurchase::find($payment->payable_id);
                        if ($payable) {
                            $newAmountPaid = max(0, $payable->amount_paid - $voidAmount);
                            $payable->update([
                                'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                                'amount_paid' => $newAmountPaid,
                            ]);

                            // Send cancellation email
                            $email = $payable->customer_email;
                            if ($email) {
                                try {
                                    Mail::to($email)->send(new AttractionPurchaseCancellation($payable, $voidPayment, $voidAmount, 'void'));
                                    Log::info('📧 Attraction purchase void cancellation email sent', ['purchase_id' => $payable->id, 'email' => $email]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send attraction purchase void cancellation email', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                                }
                            }
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment voided successfully',
                        'data' => [
                            'original_payment' => $payment->fresh(),
                            'void_payment' => $voidPayment->fresh(),
                        ],
                        'void_amount' => $voidAmount,
                        'payable_cancelled' => true,
                        'payable' => $payable?->fresh(),
                    ]);
                } else {
                    $errorMessage = 'Void transaction failed';
                    $errorCode = null;
                    if ($tresponse && $tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net void transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'transaction_id' => $payment->transaction_id,
                        'location_id' => $payment->location_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                $errorMessage = 'Unknown error';
                $errorCode = null;
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();
                }

                Log::error('Authorize.Net void API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'transaction_id' => $payment->transaction_id,
                    'location_id' => $payment->location_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_code' => $errorCode,
                ], 400);
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Void - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $payment->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Void processing exception', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment->transaction_id,
                'location_id' => $payment->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Void processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process payment using Accept.js (Authorize.Net)
     * This method receives tokenized payment data from the frontend
     */
    public function charge(Request $request): JsonResponse
    {
        // Log incoming request for debugging
        Log::info('💳 Payment charge request received', [
            'location_id' => $request->location_id,
            'amount' => $request->amount,
            'has_customer_data' => $request->has('customer'),
            'customer_data' => $request->customer ?? 'NOT PROVIDED',
            'all_request_data' => $request->except(['opaqueData']), // Don't log sensitive payment data
        ]);

        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'opaqueData' => 'required|array',
            'opaqueData.dataDescriptor' => 'required|string',
            'opaqueData.dataValue' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'order_id' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'description' => 'nullable|string',
            'customer' => 'nullable|array',
            'customer.first_name' => 'nullable|string|max:50',
            'customer.last_name' => 'nullable|string|max:50',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'nullable|string|max:25',
            'customer.address' => 'nullable|string|max:60',
            'customer.city' => 'nullable|string|max:40',
            'customer.state' => 'nullable|string|max:40',
            'customer.zip' => 'nullable|string|max:20',
            'customer.country' => 'nullable|string|max:60',
            'signature_image' => 'nullable|string',
            'terms_accepted' => 'nullable|boolean',
            // Payable linking - link payment to booking or attraction purchase
            'payable_id' => 'nullable|integer',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
            // Email confirmation - send booking/purchase confirmation email after successful payment
            'send_email' => 'nullable|boolean',
            'qr_code' => 'nullable|string', // Base64 encoded QR code for email attachment
        ]);

        try {
            // 1. Get Authorize.Net account for the location
            $account = AuthorizeNetAccount::where('location_id', $request->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location'
                ], 503);
            }

            // 2. Get decrypted credentials and trim whitespace
            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            Log::info('🔐 Authorize.Net credentials loaded', [
                'location_id' => $request->location_id,
                'account_id' => $account->id,
                'environment' => $account->environment,
                'environment_constant' => $account->isProduction() ? 'PRODUCTION' : 'SANDBOX',
                'api_login_id_preview' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -2),
                'api_login_id_length' => strlen($apiLoginId),
                'transaction_key_length' => strlen($transactionKey),
                'has_whitespace' => [
                    'api_login_id' => $apiLoginId !== $account->api_login_id,
                    'transaction_key' => $transactionKey !== $account->transaction_key,
                ],
            ]);

            // 3. Build merchant authentication
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            // 4. Create payment from opaque data
            $opaqueData = new AnetAPI\OpaqueDataType();
            $opaqueData->setDataDescriptor($request->opaqueData['dataDescriptor']);
            $opaqueData->setDataValue($request->opaqueData['dataValue']);

            Log::info('🎫 Opaque data received from Accept.js', [
                'dataDescriptor' => $request->opaqueData['dataDescriptor'],
                'dataValue_length' => strlen($request->opaqueData['dataValue']),
                'dataValue_preview' => substr($request->opaqueData['dataValue'], 0, 50) . '...',
                'backend_environment' => $account->environment,
                'backend_api_login_id' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -2),
                'note' => 'Token MUST be created with same API Login ID and matching Public Client Key',
            ]);

            $paymentOne = new AnetAPI\PaymentType();
            $paymentOne->setOpaqueData($opaqueData);

            // 5. Create transaction request
            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($request->amount);
            $transactionRequestType->setPayment($paymentOne);

            // Add customer billing information if provided
            if ($request->has('customer')) {
                $customerData = $request->customer;

                Log::info('🔍 Processing customer billing data', [
                    'has_first_name' => !empty($customerData['first_name']),
                    'has_last_name' => !empty($customerData['last_name']),
                    'has_email' => !empty($customerData['email']),
                    'has_phone' => !empty($customerData['phone']),
                    'has_address' => !empty($customerData['address']),
                    'has_city' => !empty($customerData['city']),
                    'has_state' => !empty($customerData['state']),
                    'has_zip' => !empty($customerData['zip']),
                    'has_country' => !empty($customerData['country']),
                    'customer_data_keys' => array_keys($customerData),
                ]);

                $billTo = new AnetAPI\CustomerAddressType();

                if (!empty($customerData['first_name'])) {
                    $billTo->setFirstName(substr($customerData['first_name'], 0, 50));
                }
                if (!empty($customerData['last_name'])) {
                    $billTo->setLastName(substr($customerData['last_name'], 0, 50));
                }
                if (!empty($customerData['email'])) {
                    $billTo->setEmail(substr($customerData['email'], 0, 255));
                }
                if (!empty($customerData['phone'])) {
                    $billTo->setPhoneNumber(substr($customerData['phone'], 0, 25));
                }
                if (!empty($customerData['address'])) {
                    $billTo->setAddress(substr($customerData['address'], 0, 60));
                }
                if (!empty($customerData['city'])) {
                    $billTo->setCity(substr($customerData['city'], 0, 40));
                }
                if (!empty($customerData['state'])) {
                    $billTo->setState(substr($customerData['state'], 0, 40));
                }
                if (!empty($customerData['zip'])) {
                    $billTo->setZip(substr($customerData['zip'], 0, 20));
                }
                if (!empty($customerData['country'])) {
                    $billTo->setCountry(substr($customerData['country'], 0, 60));
                }

                $transactionRequestType->setBillTo($billTo);

                Log::info('✅ Customer billing data successfully added to transaction', [
                    'customer_name' => ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''),
                    'email' => $customerData['email'] ?? null,
                    'phone' => $customerData['phone'] ?? null,
                    'address' => $customerData['address'] ?? null,
                    'city' => $customerData['city'] ?? null,
                    'state' => $customerData['state'] ?? null,
                    'zip' => $customerData['zip'] ?? null,
                    'country' => $customerData['country'] ?? null,
                ]);
            } else {
                Log::warning('⚠️ No customer billing data provided in payment request');
            }

            // Add order information if provided
            // Authorize.Net invoiceNumber max length is 20 characters
            if ($request->order_id) {
                $order = new AnetAPI\OrderType();
                $invoiceNumber = substr($request->order_id, 0, 20);
                $order->setInvoiceNumber($invoiceNumber);
                if ($request->description) {
                    $order->setDescription(substr($request->description, 0, 255)); // Max 255 chars
                }
                $transactionRequestType->setOrder($order);

                if (strlen($request->order_id) > 20) {
                    Log::warning('Order ID truncated for Authorize.Net', [
                        'original' => $request->order_id,
                        'truncated' => $invoiceNumber,
                        'original_length' => strlen($request->order_id)
                    ]);
                }
            }

            // 6. Create and execute the request
            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            // 7. Process response
            if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    // Get the transaction ID
                    $transactionId = $tresponse->getTransId();

                    // Validate we have a real transaction ID (not 0 or empty)
                    if (empty($transactionId) || $transactionId == '0') {
                        Log::error('Authorize.Net returned success but no valid transaction ID', [
                            'transaction_id' => $transactionId,
                            'response_code' => $tresponse->getResponseCode(),
                            'auth_code' => $tresponse->getAuthCode(),
                            'location_id' => $request->location_id,
                            'amount' => $request->amount,
                            'environment' => $account->environment,
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'Payment processing error: Invalid transaction ID received',
                            'error_code' => 'INVALID_TRANSACTION_ID',
                        ], 400);
                    }

                    // Success - create payment record with optional payable linking
                    $payment = Payment::create([
                        'customer_id' => $request->customer_id,
                        'location_id' => $request->location_id,
                        'amount' => $request->amount,
                        'currency' => 'USD',
                        'method' => 'authorize.net',
                        'status' => 'completed',
                        'transaction_id' => $transactionId,
                        'payment_id' => $transactionId,
                        'payable_id' => $request->payable_id,
                        'payable_type' => $request->payable_type,
                        'notes' => $request->description ?? 'Authorize.Net payment via Accept.js',
                        'paid_at' => now(),
                        'signature_image' => $request->signature_image ? $this->handleSignatureUpload($request->signature_image) : null,
                        'terms_accepted' => $request->boolean('terms_accepted', false),
                    ]);

                    // Update amount_paid on the linked booking or attraction purchase
                    $payable = null;
                    if ($payment->payable_id && $payment->payable_type) {
                        if ($payment->payable_type === Payment::TYPE_BOOKING) {
                            $payable = Booking::find($payment->payable_id);
                            if ($payable) {
                                $totalPaid = Payment::where('payable_id', $payable->id)
                                    ->where('payable_type', Payment::TYPE_BOOKING)
                                    ->where('status', 'completed')
                                    ->sum('amount');
                                $payable->update([
                                    'amount_paid' => $totalPaid,
                                    'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : 'partial',
                                    'payment_method' => 'authorize.net',
                                    'transaction_id' => $transactionId,
                                    'status' => 'confirmed',
                                ]);
                            }
                        } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                            $payable = AttractionPurchase::find($payment->payable_id);
                            if ($payable) {
                                $totalPaid = Payment::where('payable_id', $payable->id)
                                    ->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)
                                    ->where('status', 'completed')
                                    ->sum('amount');
                                $payable->update([
                                    'amount_paid' => $totalPaid,
                                    'payment_method' => 'authorize.net',
                                    'transaction_id' => $transactionId,
                                    'status' => $totalPaid >= $payable->total_amount ? AttractionPurchase::STATUS_CONFIRMED : AttractionPurchase::STATUS_PENDING,
                                ]);
                            }
                        }
                    }

                    Log::info('Authorize.Net payment successful with customer data', [
                        'transaction_id' => $transactionId,
                        'amount' => $request->amount,
                        'location_id' => $request->location_id,
                        'customer_name' => $request->has('customer') ?
                            ($request->customer['first_name'] ?? '') . ' ' . ($request->customer['last_name'] ?? '') :
                            'Not provided',
                        'customer_email' => $request->customer['email'] ?? 'Not provided',
                    ]);

                    // Create notification for customer
                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'medium',
                            'title' => 'Payment Successful',
                            'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been processed successfully via credit card.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$payment->id}",
                            'action_text' => 'View Receipt',
                            'metadata' => [
                                'payment_id' => $payment->id,
                                'transaction_id' => $transactionId,
                                'auth_code' => $tresponse->getAuthCode(),
                                'amount' => $payment->amount,
                                'method' => 'card',
                            ],
                        ]);
                    }

                    // Create notification for location staff
                    Notification::create([
                        'location_id' => $payment->location_id,
                        'type' => 'payment',
                        'priority' => 'medium',
                        'user_id' => auth()->id(),
                        'title' => 'Online Payment Received',
                        'message' => "Online payment of $" . number_format($payment->amount, 2) . " received via Authorize.Net. Auth Code: {$tresponse->getAuthCode()}",
                        'status' => 'unread',
                        'action_url' => "/payments/{$payment->id}",
                        'action_text' => 'View Payment',
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'transaction_id' => $transactionId,
                            'auth_code' => $tresponse->getAuthCode(),
                            'amount' => $payment->amount,
                            'method' => 'card',
                            'customer_id' => $payment->customer_id,
                            'payable_id' => $payment->payable_id,
                            'payable_type' => $payment->payable_type,
                        ],
                    ]);

                    // Send confirmation email if requested
                    $emailSent = false;
                    $emailError = null;
                    $sendEmail = $request->boolean('send_email', false);

                    if ($sendEmail && $payable) {
                        try {
                            $qrCode = $request->qr_code;

                            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                                // Send booking confirmation email with QR code
                                $booking = $payable;
                                $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

                                $recipientEmail = $booking->customer
                                    ? $booking->customer->email
                                    : $booking->guest_email;

                                if ($recipientEmail && $qrCode) {
                                    // Process QR code
                                    $qrCodeData = $qrCode;
                                    if (strpos($qrCodeData, 'data:image') === 0) {
                                        $qrCodeData = substr($qrCodeData, strpos($qrCodeData, ',') + 1);
                                    }

                                    $qrCodeImage = base64_decode($qrCodeData);
                                    if ($qrCodeImage) {
                                        // Save QR code file
                                        $fileName = 'qr_' . $booking->id . '.png';
                                        $qrCodePath = 'qrcodes/' . $fileName;
                                        $fullPath = storage_path('app/public/' . $qrCodePath);

                                        $dir = dirname($fullPath);
                                        if (!file_exists($dir)) {
                                            mkdir($dir, 0755, true);
                                        }
                                        file_put_contents($fullPath, $qrCodeImage);

                                        // Update booking with QR code path
                                        $booking->update(['qr_code_path' => $qrCodePath]);

                                        // Send email
                                        $useGmailApi = config('gmail.enabled', false) &&
                                            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

                                        if ($useGmailApi) {
                                            $gmailService = new GmailApiService();
                                            $mailable = new BookingConfirmation($booking, $fullPath);
                                            $emailBody = $mailable->render();

                                            $gmailService->sendEmail(
                                                $recipientEmail,
                                                'Your Booking Confirmation - Zap Zone',
                                                $emailBody,
                                                'Zap Zone',
                                                [['data' => $qrCodeData, 'filename' => 'booking-qrcode.png', 'mime_type' => 'image/png']]
                                            );
                                        } else {
                                            Mail::send([], [], function ($message) use ($booking, $fullPath, $recipientEmail) {
                                                $mailable = new BookingConfirmation($booking, $fullPath);
                                                $emailBody = $mailable->render();
                                                $message->to($recipientEmail)
                                                    ->subject('Your Booking Confirmation - Zap Zone')
                                                    ->html($emailBody)
                                                    ->attach($fullPath, ['as' => 'booking-qrcode.png', 'mime' => 'image/png']);
                                            });
                                        }

                                        $emailSent = true;
                                        Log::info('Booking confirmation email sent from charge()', [
                                            'booking_id' => $booking->id,
                                            'email' => $recipientEmail,
                                        ]);
                                    }
                                }
                            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                                // Send attraction purchase receipt email with QR code
                                $purchase = $payable;
                                $purchase->load(['attraction.location', 'customer', 'createdBy']);

                                $recipientEmail = $purchase->customer
                                    ? $purchase->customer->email
                                    : $purchase->guest_email;

                                if ($recipientEmail && $qrCode) {
                                    // Process QR code
                                    $qrCodeBase64 = $qrCode;
                                    if (strpos($qrCodeBase64, 'data:image') === 0) {
                                        $qrCodeBase64 = substr($qrCodeBase64, strpos($qrCodeBase64, ',') + 1);
                                    }

                                    if ($qrCodeBase64) {
                                        $useGmailApi = config('gmail.enabled', false) &&
                                            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

                                        if ($useGmailApi) {
                                            $gmailService = new GmailApiService();
                                            $mailable = new AttractionPurchaseReceipt($purchase, $qrCodeBase64);
                                            $emailBody = $mailable->render();

                                            $gmailService->sendEmail(
                                                $recipientEmail,
                                                'Your Attraction Purchase Receipt - Zap Zone',
                                                $emailBody,
                                                'Zap Zone',
                                                [['data' => $qrCodeBase64, 'filename' => 'qrcode.png', 'mime_type' => 'image/png']]
                                            );
                                        } else {
                                            $qrCodeImage = base64_decode($qrCodeBase64);
                                            $tempPath = storage_path('app/temp/qr_' . $purchase->id . '_' . time() . '.png');
                                            if (!file_exists(storage_path('app/temp'))) {
                                                mkdir(storage_path('app/temp'), 0755, true);
                                            }
                                            file_put_contents($tempPath, $qrCodeImage);

                                            Mail::send([], [], function ($message) use ($purchase, $tempPath, $recipientEmail, $qrCodeBase64) {
                                                $mailable = new AttractionPurchaseReceipt($purchase, $qrCodeBase64);
                                                $emailBody = $mailable->render();
                                                $message->to($recipientEmail)
                                                    ->subject('Your Attraction Purchase Receipt - Zap Zone')
                                                    ->html($emailBody)
                                                    ->attach($tempPath, ['as' => 'qrcode.png', 'mime' => 'image/png']);
                                            });

                                            if (file_exists($tempPath)) {
                                                unlink($tempPath);
                                            }
                                        }

                                        $emailSent = true;
                                        Log::info('Attraction purchase receipt sent from charge()', [
                                            'purchase_id' => $purchase->id,
                                            'email' => $recipientEmail,
                                        ]);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $emailError = $e->getMessage();
                            Log::error('Failed to send confirmation email from charge()', [
                                'payment_id' => $payment->id,
                                'payable_type' => $payment->payable_type,
                                'payable_id' => $payment->payable_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment processed successfully',
                        'transaction_id' => $transactionId,
                        'auth_code' => $tresponse->getAuthCode(),
                        'payment' => $payment,
                        'email_sent' => $emailSent,
                        'email_error' => $emailError,
                    ]);
                } else {
                    // Transaction failed
                    $errorMessage = 'Transaction failed';
                    $errorCode = null;
                    if ($tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'location_id' => $request->location_id,
                        'environment' => $account->environment,
                        'response_code' => $tresponse->getResponseCode(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                // API error
                $errorMessage = 'Unknown error';
                $errorCode = null;
                $allErrors = [];
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();

                    // Collect all error messages
                    foreach ($errorMessages as $msg) {
                        $allErrors[] = [
                            'code' => $msg->getCode(),
                            'text' => $msg->getText(),
                        ];
                    }
                }

                Log::error('Authorize.Net API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'all_errors' => $allErrors,
                    'location_id' => $request->location_id,
                    'environment' => $account->environment,
                    'account_id' => $account->id,
                    'response_null' => $response === null,
                    'is_auth_error' => $errorCode === 'E00007',
                    'suggestion' => $errorCode === 'E00007' ? 'Check if credentials match environment. Run test-connection endpoint.' : null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_code' => $errorCode,
                    'environment' => $account->environment,
                    'help' => $errorCode === 'E00007' ? 'Authentication failed. Please verify your Authorize.Net credentials match the selected environment (sandbox/production).' : null,
                ], 400);
            }

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Payment processing - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $request->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Payment processing exception', [
                'error' => $e->getMessage(),
                'location_id' => $request->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate PDF invoice for a single payment
     *
     * @param Payment $payment
     * @return \Illuminate\Http\Response
     */
    public function invoice(Payment $payment)
    {
        $payment->load(['customer', 'location']);

        // Get the payable entity (booking or attraction purchase)
        $payable = $payment->getPayableDetails();

        // Load related data for payable
        if ($payable) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payable->load(['package', 'customer']);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable->load(['attraction', 'customer']);
            }
        }

        // Get location info
        $location = $payment->location;

        // Get company info
        $company = null;
        $companyName = 'ZapZone';
        if ($location && $location->company_id) {
            $company = Company::find($location->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        // Get customer info
        $customer = $payment->customer;

        $timezone = $location->timezone ?? 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoice_' . str_pad($payment->id, 6, '0', STR_PAD_LEFT) . '_' . date('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Stream PDF invoice for viewing in browser
     *
     * @param Payment $payment
     * @return \Illuminate\Http\Response
     */
    public function invoiceView(Payment $payment)
    {
        $payment->load(['customer', 'location']);

        // Get the payable entity (booking or attraction purchase)
        $payable = $payment->getPayableDetails();

        // Load related data for payable
        if ($payable) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payable->load(['package', 'customer', 'room', 'location', 'addOns', 'attractions']);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable->load(['attraction', 'customer', 'location']);
            }
        }

        // Get location info
        $location = $payment->location;

        // Get company info
        $company = null;
        $companyName = 'ZapZone';
        if ($location && $location->company_id) {
            $company = Company::find($location->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        // Get customer info
        $customer = $payment->customer;

        $timezone = $location->timezone ?? 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->stream('invoice_' . str_pad($payment->id, 6, '0', STR_PAD_LEFT) . '.pdf');
    }

    /**
     * Generate PDF report with multiple invoices (filtered)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function invoicesReport(Request $request)
    {
        $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'method' => ['nullable', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_ids' => 'nullable|array',
            'payment_ids.*' => 'exists:payments,id',
        ]);

        // Build query
        $query = Payment::with(['customer', 'location']);

        // Apply filters
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
        }

        // If specific payment IDs are provided
        if ($request->has('payment_ids') && is_array($request->payment_ids)) {
            $query->whereIn('id', $request->payment_ids);
        }

        // Get payments
        $payments = $query->orderBy('created_at', 'desc')->get();

        // Load payable relationships for each payment
        foreach ($payments as $payment) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payment->payable = Booking::with('package')->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payment->payable = AttractionPurchase::with('attraction')->find($payment->payable_id);
            }
        }

        // Calculate summary
        $summary = [
            'total_count' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'completed_count' => $payments->where('status', 'completed')->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_count' => $payments->where('status', 'pending')->count(),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'refunded_count' => $payments->where('status', 'refunded')->count(),
            'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
        ];

        // Get location and company info
        $locationName = 'All Locations';
        $companyName = 'ZapZone';
        $company = null;

        if ($request->has('location_id')) {
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
                if ($location->company_id) {
                    $company = Company::find($location->company_id);
                    if ($company) {
                        $companyName = $company->name;
                    }
                }
            }
        }

        // Build filters display
        $filters = [];
        if ($request->has('start_date') && $request->has('end_date')) {
            $filters['date_range'] = Carbon::parse($request->start_date)->format('M d, Y') .
                                     ' - ' . Carbon::parse($request->end_date)->format('M d, Y');
        } elseif ($request->has('start_date')) {
            $filters['date_range'] = 'From ' . Carbon::parse($request->start_date)->format('M d, Y');
        } elseif ($request->has('end_date')) {
            $filters['date_range'] = 'Until ' . Carbon::parse($request->end_date)->format('M d, Y');
        }

        if ($request->has('status')) {
            $filters['status'] = $request->status;
        }
        if ($request->has('method')) {
            $filters['method'] = $request->method;
        }
        if ($request->has('payable_type')) {
            $filters['payable_type'] = $request->payable_type;
        }

        $timezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoices-report', [
            'payments' => $payments,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'filters' => count($filters) > 0 ? $filters : null,
            'reportTitle' => 'Payment Invoices Report',
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices_report_' . date('Ymd_His') . '.pdf';

        // Check if user wants to download or stream
        if ($request->get('download', false)) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Export multiple individual invoices as a single PDF (each invoice on separate page)
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function invoicesBulk(Request $request)
    {
        $request->validate([
            'payment_ids' => 'required|array|min:1',
            'payment_ids.*' => 'exists:payments,id',
        ]);

        $payments = Payment::with(['customer', 'location'])
            ->whereIn('id', $request->payment_ids)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for the provided IDs'
            ], 404);
        }

        // Build HTML for all invoices
        $html = '';
        $totalPayments = $payments->count();
        $index = 0;

        foreach ($payments as $payment) {
            $index++;

            // Get the payable entity
            $payable = $payment->getPayableDetails();

            if ($payable) {
                if ($payment->payable_type === Payment::TYPE_BOOKING) {
                    $payable->load(['package', 'customer', 'room', 'location', 'addOns', 'attractions']);
                } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                    $payable->load(['attraction', 'customer', 'location']);
                }
            }

            // Get location info
            $location = $payment->location;

            // Get company info
            $company = null;
            $companyName = 'ZapZone';
            if ($location && $location->company_id) {
                $company = Company::find($location->company_id);
                if ($company) {
                    $companyName = $company->name;
                }
            }

            $customer = $payment->customer;
            $timezone = $location->timezone ?? 'UTC';

            // Render the invoice view
            $invoiceHtml = view('exports.payment-invoice', [
                'payment' => $payment,
                'payable' => $payable,
                'customer' => $customer,
                'location' => $location,
                'company' => $company,
                'companyName' => $companyName,
                'timezone' => $timezone,
            ])->render();

            $html .= $invoiceHtml;

            // Add page break between invoices (except for the last one)
            if ($index < $totalPayments) {
                $html .= '<div style="page-break-after: always;"></div>';
            }
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices_bulk_' . date('Ymd_His') . '.pdf';

        if ($request->get('download', true)) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    /**
     * Export invoices for a specific day
     *
     * @param Request $request
     * @param string $date
     * @return \Illuminate\Http\Response
     */
    public function invoicesDay(Request $request, string $date)
    {
        $request->merge([
            'start_date' => $date,
            'end_date' => $date,
        ]);

        return $this->invoicesExport($request);
    }

    /**
     * Export invoices for a week
     *
     * @param Request $request
     * @param string $week - 'current', 'next', or a date string
     * @return \Illuminate\Http\Response
     */
    public function invoicesWeek(Request $request, string $week = 'current')
    {
        if ($week === 'current') {
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();
        } elseif ($week === 'next') {
            $startOfWeek = now()->addWeek()->startOfWeek();
            $endOfWeek = now()->addWeek()->endOfWeek();
        } else {
            $date = Carbon::parse($week);
            $startOfWeek = $date->startOfWeek();
            $endOfWeek = $date->copy()->endOfWeek();
        }

        $request->merge([
            'start_date' => $startOfWeek->format('Y-m-d'),
            'end_date' => $endOfWeek->format('Y-m-d'),
        ]);

        return $this->invoicesExport($request);
    }

    /**
     * Export invoices with comprehensive filtering options
     *
     * Query params:
     * - payment_ids: comma-separated or array of payment IDs
     * - payable_type: 'booking' or 'attraction_purchase'
     * - date: specific date (Y-m-d) for single day export
     * - start_date: start date for date range
     * - end_date: end date for date range
     * - week: 'current', 'next', or date string for week containing that date
     * - location_id: filter by location
     * - customer_id: filter by customer
     * - status: filter by payment status
     * - method: filter by payment method (card, cash)
     * - view_mode: 'report' for summary table, 'individual' for one invoice per page (default)
     * - stream: true to view in browser, false to download
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function invoicesExport(Request $request)
    {
        $request->validate([
            'payment_ids' => 'nullable|array',
            'payment_ids.*' => 'exists:payments,id',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
            'date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'week' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id',
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'method' => ['nullable', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'view_mode' => ['nullable', Rule::in(['report', 'individual'])],
        ]);

        $query = Payment::with(['customer', 'location']);
        $dateRange = null;

        // Filter by specific payment IDs
        if ($request->has('payment_ids')) {
            $ids = is_array($request->payment_ids)
                ? $request->payment_ids
                : explode(',', $request->payment_ids);
            $query->whereIn('id', $ids);
        }

        // Filter by payable_type (booking or attraction_purchase)
        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        // Filter by single date
        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('created_at', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
            $dateRange = ['start' => $request->start_date, 'end' => now()->format('Y-m-d')];
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
            $dateRange = ['start' => 'Beginning', 'end' => $request->end_date];
        }

        // Filter by week
        if ($request->has('week') && !$request->has('start_date') && !$request->has('date')) {
            $weekParam = $request->week;

            if ($weekParam === 'current') {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();
            } elseif ($weekParam === 'next') {
                $startOfWeek = now()->addWeek()->startOfWeek();
                $endOfWeek = now()->addWeek()->endOfWeek();
            } else {
                $date = Carbon::parse($weekParam);
                $startOfWeek = $date->startOfWeek();
                $endOfWeek = $date->copy()->endOfWeek();
            }

            $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
            $dateRange = ['start' => $startOfWeek->format('Y-m-d'), 'end' => $endOfWeek->format('Y-m-d')];
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by method
        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        // Sort by date
        $query->orderBy('created_at', 'desc');

        $payments = $query->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for the specified criteria'
            ], 404);
        }

        // Load payable relationships for each payment
        foreach ($payments as $payment) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payment->payable = Booking::with(['package', 'customer', 'room', 'location', 'addOns', 'attractions'])->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payment->payable = AttractionPurchase::with(['attraction', 'customer', 'location'])->find($payment->payable_id);
            }
        }

        // Determine view mode
        $viewMode = $request->get('view_mode', 'individual');

        // Get location and company info
        $location = null;
        $locationName = 'All Locations';
        $company = null;
        $companyName = 'ZapZone';

        if ($request->has('location_id')) {
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
                if ($location->company_id) {
                    $company = Company::find($location->company_id);
                    if ($company) {
                        $companyName = $company->name;
                    }
                }
            }
        }

        if ($viewMode === 'report') {
            // Summary report view
            $summary = [
                'total_count' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'completed_count' => $payments->where('status', 'completed')->count(),
                'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
                'pending_count' => $payments->where('status', 'pending')->count(),
                'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
                'refunded_count' => $payments->where('status', 'refunded')->count(),
                'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
                'booking_count' => $payments->where('payable_type', Payment::TYPE_BOOKING)->count(),
                'booking_amount' => $payments->where('payable_type', Payment::TYPE_BOOKING)->sum('amount'),
                'attraction_count' => $payments->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)->count(),
                'attraction_amount' => $payments->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)->sum('amount'),
            ];

            // Build filters display
            $filters = [];
            if ($dateRange) {
                if ($dateRange['start'] === $dateRange['end']) {
                    $filters['date_range'] = Carbon::parse($dateRange['start'])->format('l, F j, Y');
                } else {
                    $filters['date_range'] = Carbon::parse($dateRange['start'])->format('M d, Y') .
                                             ' - ' . Carbon::parse($dateRange['end'])->format('M d, Y');
                }
            }
            if ($request->has('payable_type')) {
                $filters['payable_type'] = $request->payable_type;
            }
            if ($request->has('status')) {
                $filters['status'] = $request->status;
            }
            if ($request->has('method')) {
                $filters['method'] = $request->method;
            }

            $reportTimezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

            $pdf = Pdf::loadView('exports.payment-invoices-report', [
                'payments' => $payments,
                'summary' => $summary,
                'company' => $company,
                'companyName' => $companyName,
                'locationName' => $locationName,
                'filters' => count($filters) > 0 ? $filters : null,
                'reportTitle' => $this->getReportTitle($request, $dateRange),
                'timezone' => $reportTimezone,
            ]);
        } else {
            // Individual invoices (one per page)
            $html = '';
            $totalPayments = $payments->count();
            $index = 0;

            foreach ($payments as $payment) {
                $index++;

                $payable = $payment->payable;
                $paymentLocation = $payment->location ?? $location;
                $paymentCompany = null;
                $paymentCompanyName = $companyName;

                if ($paymentLocation && $paymentLocation->company_id) {
                    $paymentCompany = Company::find($paymentLocation->company_id);
                    if ($paymentCompany) {
                        $paymentCompanyName = $paymentCompany->name;
                    }
                }

                $invoiceHtml = view('exports.payment-invoice', [
                    'payment' => $payment,
                    'payable' => $payable,
                    'customer' => $payment->customer,
                    'location' => $paymentLocation,
                    'company' => $paymentCompany,
                    'companyName' => $paymentCompanyName,
                    'timezone' => $paymentLocation->timezone ?? 'UTC',
                ])->render();

                $html .= $invoiceHtml;

                if ($index < $totalPayments) {
                    $html .= '<div style="page-break-after: always;"></div>';
                }
            }

            $pdf = Pdf::loadHTML($html);
        }

        $pdf->setPaper('A4', 'portrait');

        // Generate filename
        $filename = 'invoices';
        if ($request->has('payable_type')) {
            $filename .= '-' . str_replace('_', '-', $request->payable_type);
        }
        if ($dateRange) {
            if ($dateRange['start'] === $dateRange['end']) {
                $filename .= '-' . $dateRange['start'];
            } else {
                $filename .= '-' . $dateRange['start'] . '-to-' . $dateRange['end'];
            }
        } else {
            $filename .= '-' . now()->format('Y-m-d');
        }
        $filename .= '.pdf';

        // Stream or download based on request
        if ($request->get('stream', false)) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    /**
     * Generate report title based on filters
     */
    private function getReportTitle(Request $request, ?array $dateRange): string
    {
        $title = 'Payment Invoices';

        if ($request->has('payable_type')) {
            $title = $request->payable_type === Payment::TYPE_BOOKING
                ? 'Package Booking Invoices'
                : 'Attraction Purchase Invoices';
        }

        if ($dateRange) {
            if ($dateRange['start'] === $dateRange['end']) {
                $title .= ' - ' . Carbon::parse($dateRange['start'])->format('F j, Y');
            }
        }

        return $title;
    }

    /**
     * Export package-specific invoices (all invoices for bookings of a specific package)
     * Lists all payment invoices grouped by package in a consistent invoice format
     *
     * Access control:
     * - If user has location_id: only invoices for that location
     * - If user has no location_id (company admin): access to all locations in company
     *
     * Query params:
     * - package_id: required - the package to generate invoices for
     * - date: specific date (Y-m-d) for single day
     * - start_date, end_date: date range
     * - location_id: filter by location (only if user is company admin)
     * - status: filter by payment status
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function packageInvoicesExport(Request $request)
    {
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'location_id' => 'nullable|exists:locations,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
        ]);

        // Get authenticated user
        $user = auth()->user();

        // Get the package
        $package = Package::findOrFail($request->package_id);

        // Query payments for bookings of this package
        $query = Payment::with(['customer', 'location'])
            ->where('payable_type', Payment::TYPE_BOOKING)
            ->whereHas('booking', function ($q) use ($request) {
                $q->where('package_id', $request->package_id);
            });

        // Apply location-based access control
        if ($user->location_id) {
            // User is a location manager - can only see their location's invoices
            $query->where('location_id', $user->location_id);
        } else {
            // User is a company admin (no location_id) - can see all locations in their company
            // But can optionally filter by a specific location
            if ($request->has('location_id')) {
                // Verify the location belongs to user's company
                $location = Location::where('id', $request->location_id)
                    ->where('company_id', $user->company_id)
                    ->first();

                if (!$location) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Location not found or access denied'
                    ], 403);
                }

                $query->where('location_id', $request->location_id);
            } else {
                // Filter by all locations in the user's company
                $companyLocationIds = Location::where('company_id', $user->company_id)->pluck('id');
                $query->whereIn('location_id', $companyLocationIds);
            }
        }

        $dateRange = null;

        // Filter by single date
        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('created_at', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
            $dateRange = ['start' => $request->start_date, 'end' => now()->format('Y-m-d')];
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
            $dateRange = ['start' => 'Beginning', 'end' => $request->end_date];
        }

        // Note: location_id filtering is already handled in the access control section above

        // Filter by payment status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort by date
        $query->orderBy('created_at', 'desc');

        $payments = $query->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for this package with the specified criteria'
            ], 404);
        }

        // Load booking details for each payment
        foreach ($payments as $payment) {
            $payment->payable = Booking::with([
                'package',
                'customer',
                'room',
                'location',
                'addOns',
                'attractions'
            ])->find($payment->payable_id);
        }

        // Get location and company info based on user context
        $location = null;
        $locationName = 'All Locations';
        $company = null;
        $companyName = 'ZapZone';

        // Get company info from user
        if ($user->company_id) {
            $company = Company::find($user->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        // Get location info
        if ($user->location_id) {
            // Location manager - use their location
            $location = Location::find($user->location_id);
            if ($location) {
                $locationName = $location->name;
            }
        } elseif ($request->has('location_id')) {
            // Company admin filtering by specific location
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
            }
        }
        // If no location specified, keep "All Locations"

        // Calculate summary
        $summary = [
            'total_invoices' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'completed_count' => $payments->where('status', 'completed')->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_count' => $payments->where('status', 'pending')->count(),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'refunded_count' => $payments->where('status', 'refunded')->count(),
            'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
            'total_bookings' => $payments->unique('payable_id')->count(),
        ];

        $packageTimezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

        $pdf = Pdf::loadView('exports.package-invoices-report', [
            'payments' => $payments,
            'package' => $package,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'dateRange' => $dateRange,
            'timezone' => $packageTimezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        // Generate filename
        $filename = 'invoices-' . \Illuminate\Support\Str::slug($package->name);
        if ($dateRange) {
            if ($dateRange['start'] === $dateRange['end']) {
                $filename .= '-' . $dateRange['start'];
            } else {
                $filename .= '-' . $dateRange['start'] . '-to-' . $dateRange['end'];
            }
        } else {
            $filename .= '-' . now()->format('Y-m-d');
        }
        $filename .= '.pdf';

        // Stream or download
        if ($request->get('stream', false)) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    /**
     * Handle signature image upload from base64 data URI.
     * Stores the image to storage/app/public/images/signatures/ and returns the relative path.
     *
     * @param string $image Base64 data URI or existing path
     * @return string Relative path to the stored signature image
     */
    private function handleSignatureUpload(string $image): string
    {
        // Check if it's a base64 string
        if (is_string($image) && strpos($image, 'data:image') === 0) {
            // Extract base64 data
            preg_match('/data:image\/(\w+);base64,/', $image, $matches);
            $imageType = $matches[1] ?? 'png';
            $base64Data = substr($image, strpos($image, ',') + 1);
            $imageData = base64_decode($base64Data, true);

            // Generate unique filename
            $filename = uniqid() . '.' . $imageType;
            $path = 'images/signatures';
            $fullPath = storage_path('app/public/' . $path);

            // Create directory if it doesn't exist
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            // Save the file
            file_put_contents($fullPath . '/' . $filename, $imageData);

            // Return the relative path
            return $path . '/' . $filename;
        }

        // If it's already a file path or URL, return as is
        return $image;
    }
}
