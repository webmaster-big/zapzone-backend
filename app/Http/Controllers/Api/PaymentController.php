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
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
            'method' => ['required', Rule::in(['card', 'cash'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'notes' => 'nullable|string',
            'payment_id' => 'nullable|string|unique:payments,payment_id',
            'location_id' => 'nullable|exists:locations,id',
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

        // Log payment creation activity
        ActivityLog::log(
            action: 'Payment Created',
            category: 'create',
            description: "Payment of $" . number_format($payment->amount, 2) . " created via {$payment->method}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
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

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'notes' => 'sometimes|nullable|string',
        ]);

        $previousStatus = $payment->status;

        if (isset($validated['status'])) {
            if ($validated['status'] === 'completed' && $payment->status !== 'completed') {
                $validated['paid_at'] = now();
            } elseif ($validated['status'] === 'refunded' && $payment->status !== 'refunded') {
                $validated['refunded_at'] = now();
            }
        }

        $payment->update($validated);

        // Create notifications if status changed to completed
        if (isset($validated['status']) && $validated['status'] === 'completed' && $previousStatus !== 'completed') {
            // Customer notification
            if ($payment->customer_id) {
                CustomerNotification::create([
                    'customer_id' => $payment->customer_id,
                    'location_id' => $payment->location_id,
                    'type' => 'payment',
                    'priority' => 'medium',
                    'title' => 'Payment Confirmed',
                    'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been confirmed.",
                    'status' => 'unread',
                    'action_url' => "/payments/{$payment->id}",
                    'action_text' => 'View Payment',
                    'metadata' => [
                        'payment_id' => $payment->id,
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                    ],
                ]);
            }

            // Location notification
            if ($payment->location_id) {
                Notification::create([
                    'location_id' => $payment->location_id,
                    'type' => 'payment',
                    'priority' => 'medium',
                    'user_id' => auth()->id(),
                    'title' => 'Payment Confirmed',
                    'message' => "Payment of $" . number_format($payment->amount, 2) . " has been marked as completed.",
                    'status' => 'unread',
                    'action_url' => "/payments/{$payment->id}",
                    'action_text' => 'View Payment',
                    'metadata' => [
                        'payment_id' => $payment->id,
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $payment->amount,
                        'customer_id' => $payment->customer_id,
                    ],
                ]);
            }
        }

        // Log payment update activity if status changed
        if (isset($validated['status'])) {
            ActivityLog::log(
                action: 'Payment Updated',
                category: 'update',
                description: "Payment status updated to {$validated['status']}",
                userId: auth()->id(),
                locationId: $payment->location_id,
                entityType: 'payment',
                entityId: $payment->id,
                metadata: [
                    'transaction_id' => $payment->transaction_id,
                    'new_status' => $validated['status'],
                    'amount' => $payment->amount,
                ]
            );
        }

        return response()->json(['success' => true, 'message' => 'Payment updated successfully', 'data' => $payment]);
    }

    public function refund(Payment $payment): JsonResponse
    {
        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        $payment->update(['status' => 'refunded', 'refunded_at' => now()]);

        // Create notification for customer
        if ($payment->customer_id) {
            CustomerNotification::create([
                'customer_id' => $payment->customer_id,
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'high',
                'title' => 'Payment Refunded',
                'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been refunded.",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
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
                'title' => 'Payment Refunded',
                'message' => "Payment of $" . number_format($payment->amount, 2) . " has been refunded. Transaction: {$payment->transaction_id}",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'customer_id' => $payment->customer_id,
                    'refunded_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        // Log payment refund activity
        ActivityLog::log(
            action: 'Payment Refunded',
            category: 'update',
            description: "Payment of $" . number_format($payment->amount, 2) . " refunded",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Payment refunded successfully', 'data' => $payment]);
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
            // New polymorphic fields
            'payable_id' => 'nullable|integer',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE])],
            // Backward compatibility
            'booking_id' => 'nullable|exists:bookings,id',
            'attraction_purchase_id' => 'nullable|exists:attraction_purchases,id',
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
        ]);

        // Determine payable_id and payable_type from request
        $payableId = $request->payable_id;
        $payableType = $request->payable_type;

        // Backward compatibility: convert booking_id to payable_id/payable_type
        if ($request->booking_id && !$payableId) {
            $payableId = $request->booking_id;
            $payableType = Payment::TYPE_BOOKING;
        }

        // Handle attraction_purchase_id
        if ($request->attraction_purchase_id && !$payableId) {
            $payableId = $request->attraction_purchase_id;
            $payableType = Payment::TYPE_ATTRACTION_PURCHASE;
        }

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

                    // Success - create payment record
                    $payment = Payment::create([
                        'payable_id' => $payableId,
                        'payable_type' => $payableType,
                        'customer_id' => $request->customer_id,
                        'location_id' => $request->location_id,
                        'amount' => $request->amount,
                        'currency' => 'USD',
                        'method' => 'card',
                        'status' => 'completed',
                        'transaction_id' => $transactionId,
                        'payment_id' => $transactionId,
                        'notes' => $request->description ?? 'Authorize.Net payment via Accept.js',
                        'paid_at' => now(),
                    ]);

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

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment processed successfully',
                        'transaction_id' => $transactionId,
                        'auth_code' => $tresponse->getAuthCode(),
                        'payment' => $payment,
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

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
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

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
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
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'method' => ['nullable', Rule::in(['card', 'cash'])],
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

        $pdf = Pdf::loadView('exports.payment-invoices-report', [
            'payments' => $payments,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'filters' => count($filters) > 0 ? $filters : null,
            'reportTitle' => 'Payment Invoices Report',
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

            // Render the invoice view
            $invoiceHtml = view('exports.payment-invoice', [
                'payment' => $payment,
                'payable' => $payable,
                'customer' => $customer,
                'location' => $location,
                'company' => $company,
                'companyName' => $companyName,
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
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'method' => ['nullable', Rule::in(['card', 'cash'])],
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

            $pdf = Pdf::loadView('exports.payment-invoices-report', [
                'payments' => $payments,
                'summary' => $summary,
                'company' => $company,
                'companyName' => $companyName,
                'locationName' => $locationName,
                'filters' => count($filters) > 0 ? $filters : null,
                'reportTitle' => $this->getReportTitle($request, $dateRange),
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
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
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

        $pdf = Pdf::loadView('exports.package-invoices-report', [
            'payments' => $payments,
            'package' => $package,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'dateRange' => $dateRange,
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
}
