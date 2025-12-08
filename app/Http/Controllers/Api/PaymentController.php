<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\AuthorizeNetAccount;
use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['booking', 'customer']);

        if ($request->has('booking_id')) {
            $query->where('booking_id', $request->booking_id);
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
            'booking_id' => 'nullable|exists:bookings,id',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'string|size:3',
            'method' => ['required', Rule::in(['card', 'cash'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'notes' => 'nullable|string',
            'payment_id' => 'nullable|string|unique:payments,payment_id',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $validated['transaction_id'] = 'TXN' . now()->format('YmdHis') . strtoupper(Str::random(6));

        if ($validated['status'] === 'completed') {
            $validated['paid_at'] = now();
        }

        $payment = Payment::create($validated);
        $payment->load(['booking', 'customer']);

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
                    'booking_id' => $payment->booking_id,
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
        $payment->load(['booking', 'customer']);
        return response()->json(['success' => true, 'data' => $payment]);
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
            'booking_id' => 'nullable|exists:bookings,id',
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

            // 2. Get decrypted credentials
            $apiLoginId = $account->api_login_id;
            $transactionKey = $account->transaction_key;
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            // 3. Build merchant authentication
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            // 4. Create payment from opaque data
            $opaqueData = new AnetAPI\OpaqueDataType();
            $opaqueData->setDataDescriptor($request->opaqueData['dataDescriptor']);
            $opaqueData->setDataValue($request->opaqueData['dataValue']);

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
            if ($request->order_id) {
                $order = new AnetAPI\OrderType();
                $order->setInvoiceNumber($request->order_id);
                if ($request->description) {
                    $order->setDescription($request->description);
                }
                $transactionRequestType->setOrder($order);
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
                    // Success - create payment record
                    $payment = Payment::create([
                        'booking_id' => $request->booking_id,
                        'customer_id' => $request->customer_id,
                        'location_id' => $request->location_id,
                        'amount' => $request->amount,
                        'currency' => 'USD',
                        'method' => 'card',
                        'status' => 'completed',
                        'transaction_id' => $tresponse->getTransId(),
                        'payment_id' => $tresponse->getTransId(),
                        'notes' => $request->description ?? 'Authorize.Net payment via Accept.js',
                        'paid_at' => now(),
                    ]);

                    Log::info('Authorize.Net payment successful with customer data', [
                        'transaction_id' => $tresponse->getTransId(),
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
                                'transaction_id' => $tresponse->getTransId(),
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
                        'title' => 'Online Payment Received',
                        'message' => "Online payment of $" . number_format($payment->amount, 2) . " received via Authorize.Net. Auth Code: {$tresponse->getAuthCode()}",
                        'status' => 'unread',
                        'action_url' => "/payments/{$payment->id}",
                        'action_text' => 'View Payment',
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'transaction_id' => $tresponse->getTransId(),
                            'auth_code' => $tresponse->getAuthCode(),
                            'amount' => $payment->amount,
                            'method' => 'card',
                            'customer_id' => $payment->customer_id,
                            'booking_id' => $payment->booking_id,
                        ],
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment processed successfully',
                        'transaction_id' => $tresponse->getTransId(),
                        'auth_code' => $tresponse->getAuthCode(),
                        'payment' => $payment,
                    ]);
                } else {
                    // Transaction failed
                    $errorMessage = 'Transaction failed';
                    if ($tresponse->getErrors() != null) {
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net transaction failed', [
                        'error' => $errorMessage,
                        'location_id' => $request->location_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage
                    ], 400);
                }
            } else {
                // API error
                $errorMessage = 'Unknown error';
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorMessage = $errorMessages[0]->getText();
                }

                Log::error('Authorize.Net API error', [
                    'error' => $errorMessage,
                    'location_id' => $request->location_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Payment processing exception', [
                'error' => $e->getMessage(),
                'location_id' => $request->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
