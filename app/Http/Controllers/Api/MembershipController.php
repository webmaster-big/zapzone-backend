<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipNote;
use App\Models\MembershipPayment;
use App\Models\MembershipPlan;
use App\Models\AuthorizeNetAccount;
use App\Services\MembershipService;
use App\Support\CompanyLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

class MembershipController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(protected MembershipService $service) {}

    // ----------------------------------------------------------
    // STAFF / ADMIN endpoints
    // ----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = Membership::with(['customer:id,first_name,last_name,email,phone', 'plan:id,name,tier,price,billing_cycle', 'homeLocation:id,name']);

        // Scope by company through plan / by location through home_location
        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where(function ($q) use ($authUser) {
                    $q->where('home_location_id', $authUser->location_id)
                      ->orWhere('sold_at_location_id', $authUser->location_id);
                });
            } elseif ($authUser->company_id) {
                $query->whereHas('plan', fn($q) => $q->where('company_id', $authUser->company_id));
            }
        }

        if ($request->filled('status'))         $query->where('status', $request->status);
        if ($request->filled('plan_id'))        $query->where('membership_plan_id', $request->plan_id);
        if ($request->filled('location_id'))    $query->where('home_location_id', $request->location_id);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('qr_token', $s)
                  ->orWhereHas('customer', function ($c) use ($s) {
                      $c->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%")
                        ->orWhere('phone', 'like', "%$s%");
                  });
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('id')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    public function show(Membership $membership): JsonResponse
    {
        $membership->load([
            'customer',
            'plan.approvedLocations:id,name',
            'homeLocation:id,name',
            'visits' => fn($q) => $q->latest('visited_at')->limit(50),
            'visits.location:id,name',
            'visits.staff:id,name',
            'membershipPayments' => fn($q) => $q->latest()->limit(50),
            'notes' => fn($q) => $q->latest(),
            'notes.user:id,name',
            'auditLogs' => fn($q) => $q->latest()->limit(50),
            'auditLogs.user:id,name',
        ]);

        return response()->json(['success' => true, 'data' => $membership]);
    }

    /**
     * Staff-side create (e.g. comped, manual signup at desk).
     *
     * Accepts either:
     *   (a) customer_id — link to an existing customer account, OR
     *   (b) first_name + last_name + email + phone — create a new customer on-the-fly.
     *
     * When a new customer is created a temporary random password is set and the
     * membership activation email (with QR code) is sent to their email address
     * by the normal activate() flow.  They can use "Forgot Password" from the
     * customer login page to set their own password afterwards.
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            // Existing customer — supply one or the other block, not both.
            'customer_id'          => 'nullable|exists:customers,id',
            // New customer fields (required when customer_id is omitted)
            'first_name'           => 'required_without:customer_id|string|max:100',
            'last_name'            => 'required_without:customer_id|string|max:100',
            'email'                => 'required_without:customer_id|email|max:255',
            'phone'                => 'nullable|string|max:30',
            // Membership fields
            'membership_plan_id'   => 'required|exists:membership_plans,id',
            'home_location_id'     => 'nullable|exists:locations,id',
            'sold_at_location_id'  => 'nullable|exists:locations,id',
            'is_comped'            => 'boolean',
            'discount_amount'      => 'nullable|numeric|min:0',
            'recurring_billing_authorized' => 'boolean',
            'terms_accepted'       => 'boolean',
            'payment_method_label' => 'nullable|string|max:120',
            'payment_profile_token'=> 'nullable|string|max:120',
        ]);

        // Resolve or create the customer account.
        if (empty($data['customer_id'])) {
            // Check if a customer with this email already exists.
            $existing = Customer::where('email', $data['email'])->first();
            if ($existing) {
                $customerId = $existing->id;
            } else {
                $customer = Customer::create([
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'email'      => $data['email'],
                    'phone'      => $data['phone'] ?? null,
                    'password'   => Hash::make(Str::random(32)),
                    'status'     => 'active',
                ]);
                $customerId = $customer->id;
            }
        } else {
            $customerId = (int) $data['customer_id'];
        }

        // Strip the new-customer fields before creating the membership.
        $membershipData = array_intersect_key($data, array_flip([
            'membership_plan_id', 'home_location_id', 'sold_at_location_id',
            'is_comped', 'discount_amount', 'recurring_billing_authorized',
            'terms_accepted', 'payment_method_label', 'payment_profile_token',
        ]));
        $membershipData['customer_id'] = $customerId;

        $plan = MembershipPlan::findOrFail($membershipData['membership_plan_id']);
        $membershipData['billing_amount'] = $plan->price;
        if (! empty($membershipData['terms_accepted'])) $membershipData['terms_accepted_at'] = now();
        if (! empty($membershipData['recurring_billing_authorized'])) $membershipData['recurring_billing_authorized_at'] = now();

        $membership = Membership::create($membershipData);
        $this->service->activate($membership, ['note' => 'Created by staff']);

        return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan', 'customer')], 201);
    }

    /**
     * Customer-side purchase (called from booking-frontend customer flow).
     * Customer must be authenticated. Charges via Authorize.Net Accept.js token.
     */
    public function purchase(Request $request): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer, 401, 'Customer authentication required');

        $data = $request->validate([
            'membership_plan_id'           => 'required|exists:membership_plans,id',
            'home_location_id'             => 'nullable|exists:locations,id',
            'home_location_name'           => 'nullable|string|max:150',
            'opaque_data'                  => 'nullable|array',
            'opaque_data.dataDescriptor'   => 'nullable|string',
            'opaque_data.dataValue'        => 'nullable|string',
            'terms_accepted'               => 'required|boolean|accepted',
            'recurring_billing_authorized' => 'required|boolean|accepted',
        ]);

        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);

        // Resolve home location ID — accept either an explicit ID or a name.
        $homeLocId = $data['home_location_id'] ?? null;
        if (!$homeLocId && !empty($data['home_location_name'])) {
            $homeLocId = \App\Models\Location::where('name', $data['home_location_name'])->value('id');
        }
        $homeLocId = $homeLocId ?? $plan->location_id;

        $membership = Membership::create([
            'customer_id'                    => $customer->id,
            'membership_plan_id'             => $plan->id,
            'home_location_id'               => $homeLocId,
            'sold_at_location_id'            => $homeLocId,
            'status'                         => 'pending',
            'billing_amount'                 => $plan->price,
            'terms_accepted'                 => true,
            'terms_accepted_at'              => now(),
            'recurring_billing_authorized'   => true,
            'recurring_billing_authorized_at'=> now(),
        ]);

        // Free / comped plan — activate immediately, no charge needed.
        if ($plan->price <= 0) {
            $this->service->recordPayment($membership, [
                'amount'      => 0,
                'status'      => 'succeeded',
                'description' => "Complimentary: {$plan->name}",
            ]);
            $this->service->activate($membership);
            return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan')], 201);
        }

        // Paid plan: opaque_data required.
        if (empty($data['opaque_data']['dataDescriptor']) || empty($data['opaque_data']['dataValue'])) {
            $membership->delete();
            return response()->json(['success' => false, 'message' => 'Payment information is required.'], 422);
        }

        // Look up the active Authorize.Net account for this location.
        $account = AuthorizeNetAccount::where('location_id', $homeLocId)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            $membership->delete();
            return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
        }

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim($account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            // Accept.js opaque data token (never touches raw card numbers).
            $opaqueData = new AnetAPI\OpaqueDataType();
            $opaqueData->setDataDescriptor($data['opaque_data']['dataDescriptor']);
            $opaqueData->setDataValue($data['opaque_data']['dataValue']);

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setOpaqueData($opaqueData);

            // Customer billing info.
            $billTo = new AnetAPI\CustomerAddressType();
            $billTo->setFirstName(substr($customer->first_name ?? '', 0, 50));
            $billTo->setLastName(substr($customer->last_name ?? '', 0, 50));
            if (!empty($customer->email))  $billTo->setEmail(substr($customer->email, 0, 255));
            if (!empty($customer->phone))  $billTo->setPhoneNumber(substr($customer->phone, 0, 25));

            // Order metadata.
            $order = new AnetAPI\OrderType();
            $order->setInvoiceNumber(substr('MEM-' . $membership->id, 0, 20));
            $order->setDescription(substr("Membership: {$plan->name}", 0, 255));

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('authCaptureTransaction');
            $transactionRequest->setAmount($plan->price);
            $transactionRequest->setPayment($paymentType);
            $transactionRequest->setBillTo($billTo);
            $transactionRequest->setOrder($order);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setRefId('MEM' . $membership->id);
            $apiRequest->setTransactionRequest($transactionRequest);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response   = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages()) {
                    $transactionId = $tresponse->getTransId();

                    Log::info('Membership charged via Authorize.Net', [
                        'membership_id'  => $membership->id,
                        'transaction_id' => $transactionId,
                        'amount'         => $plan->price,
                    ]);

                    $this->service->recordPayment($membership, [
                        'amount'         => $plan->price,
                        'status'         => 'succeeded',
                        'transaction_id' => $transactionId,
                        'description'    => "Initial purchase: {$plan->name}",
                    ]);

                    $this->service->activate($membership);

                    return response()->json([
                        'success' => true,
                        'data'    => $membership->fresh()->load('plan'),
                    ], 201);
                }
            }

            // Charge was declined or gateway returned an error.
            $errorMessage = 'Payment declined';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getErrors()) {
                    $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            Log::warning('Membership charge failed', [
                'membership_id' => $membership->id,
                'error'         => $errorMessage,
            ]);

            $this->service->recordPayment($membership, [
                'amount'         => $plan->price,
                'status'         => 'failed',
                'description'    => "Purchase failed: {$plan->name}",
                'failure_reason' => $errorMessage,
            ]);

            $membership->delete(); // Soft-delete the pending membership.

            return response()->json(['success' => false, 'message' => $errorMessage], 402);

        } catch (\Exception $e) {
            Log::error('Membership purchase exception', [
                'membership_id' => $membership->id,
                'error'         => $e->getMessage(),
            ]);
            $membership->delete();
            return response()->json(['success' => false, 'message' => 'Payment processing error.'], 500);
        }
    }

    /**
     * Customer view of their own active membership — returns full transparent details
     * including which locations the membership is valid at.
     */
    public function myMembership(): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer, 401);

        $membership = Membership::with([
            'plan',
            'plan.approvedLocations:id,name',
            'plan.location:id,name',
            'homeLocation:id,name',
            'membershipPayments' => fn($q) => $q->latest()->limit(10),
        ])
        ->where('customer_id', $customer->id)
        ->latest()
        ->first();

        if (! $membership) {
            return response()->json(['success' => true, 'data' => null]);
        }

        // Build a clear, customer-facing summary of which locations are valid for this membership.
        $plan               = $membership->plan;
        $validLocations     = $this->resolveValidLocations($plan, $membership);
        $locationAccessLabel = match ($plan?->location_access_mode) {
            'all'    => 'Valid at all locations',
            'multi'  => 'Valid at selected locations',
            'single' => 'Valid at home location only',
            default  => null,
        };

        $data                           = $membership->toArray();
        $data['valid_locations']        = $validLocations;
        $data['location_access_label']  = $locationAccessLabel;

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateStatus(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending','active','past_due','suspended','frozen','canceled','expired'])],
            'note'   => 'nullable|string',
        ]);
        $this->service->changeStatus($membership, $data['status'], $data['note'] ?? null);
        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    public function freeze(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate([
            'until' => 'nullable|date|after:today',
            'note'  => 'nullable|string',
        ]);
        $membership->frozen_until = $data['until'] ?? null;
        $membership->save();
        $this->service->changeStatus($membership, 'frozen', $data['note'] ?? null);
        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    public function cancel(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate([
            'effective' => ['nullable', Rule::in(['immediate', 'end_of_term'])],
            'note'      => 'nullable|string',
        ]);

        $mode = $data['effective'] ?? $membership->plan->cancellation_mode;
        $effectiveAt = $mode === 'immediate' ? now() : ($membership->current_term_end ?? now());

        $membership->canceled_at = now();
        $membership->cancellation_effective_at = $effectiveAt;
        $membership->save();

        if ($mode === 'immediate') {
            $this->service->changeStatus($membership, 'canceled', $data['note'] ?? null);
        } else {
            $this->service->log($membership, 'cancel_scheduled', null, [
                'effective_at' => $effectiveAt->toIso8601String(),
            ], $data['note'] ?? null);
        }

        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    public function changePlan(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate([
            'membership_plan_id' => 'required|exists:membership_plans,id',
            'effective'          => ['nullable', Rule::in(['immediate','next_cycle'])],
            'note'               => 'nullable|string',
        ]);
        $before = ['membership_plan_id' => $membership->membership_plan_id];
        $membership->membership_plan_id = $data['membership_plan_id'];
        $membership->save();
        $this->service->log($membership, 'plan_change', $before, ['membership_plan_id' => $membership->membership_plan_id], $data['note'] ?? null);
        return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan')]);
    }

    /**
     * Staff-only first-visit photo upload.
     */
    public function uploadPhoto(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $request->validate(['photo' => 'required|image|max:5120']);
        $path = $request->file('photo')->store('membership_photos', 'public');

        $before = ['photo_path' => $membership->photo_path];
        $membership->photo_path = $path;
        $membership->photo_taken_at = now();
        $membership->photo_taken_by_user_id = $authUser->id;
        $membership->save();

        $this->service->log($membership, 'photo_update', $before, ['photo_path' => $path]);
        return response()->json(['success' => true, 'data' => ['photo_url' => Storage::url($path)]]);
    }

    public function addNote(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            'type'       => ['nullable', Rule::in(['general','billing','access','manual_override','cancellation','internal_warning'])],
            'content'    => 'required|string',
            'pinned'     => 'boolean',
            'visibility' => ['nullable', Rule::in(['staff','manager_only'])],
        ]);
        $data['user_id'] = $authUser->id;
        $data['membership_id'] = $membership->id;

        $note = MembershipNote::create($data);
        return response()->json(['success' => true, 'data' => $note->load('user:id,name')], 201);
    }

    public function eligibility(Request $request, Membership $membership): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->eligibility($membership, $request->integer('location_id') ?: null),
        ]);
    }

    /**
     * Update customer's payment method on file (label-only; full token flow
     * delegated to existing Authorize.Net pipeline used elsewhere in the app).
     */
    public function updatePaymentMethod(Request $request, Membership $membership): JsonResponse
    {
        $customer = $this->resolveCustomer();
        $authUser = $this->resolveAuthUser($request);

        // Either the owning customer or staff may update
        $ownsIt = $customer && (int) $customer->id === (int) $membership->customer_id;
        abort_unless($ownsIt || $authUser, 403);

        $data = $request->validate([
            'payment_method_label'  => 'required|string|max:120',
            'payment_profile_token' => 'nullable|string|max:120',
        ]);
        $membership->fill($data)->save();
        $this->service->log($membership, 'payment_method_update', null, $data);
        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    /**
     * Retry a failed payment (staff-triggered).
     */
    public function retryPayment(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $lastFailed = $membership->membershipPayments()->where('status', 'failed')->latest()->first();
        $attempt = ($lastFailed?->retry_attempt ?? 0) + 1;

        // The actual charge is dispatched to the existing payment pipeline by the frontend.
        // We record the attempt here; status is set by the FE callback or webhook.
        $payment = $this->service->recordPayment($membership, [
            'amount'        => $membership->billing_amount,
            'status'        => $request->input('status', 'pending'),
            'retry_attempt' => $attempt,
            'description'   => "Manual retry by staff",
        ]);

        return response()->json(['success' => true, 'data' => $payment]);
    }

    /**
     * List payment history for a membership (paginated, newest first).
     * Accessible by staff and by the owning customer.
     */
    public function payments(Request $request, Membership $membership): JsonResponse
    {
        $customer = $this->resolveCustomer();
        $authUser = $this->resolveAuthUser($request);

        $ownsIt = $customer && (int) $customer->id === (int) $membership->customer_id;
        abort_unless($ownsIt || $authUser, 403);

        $payments = $membership->membershipPayments()
            ->latest()
            ->paginate((int) $request->get('per_page', 20));

        return response()->json(['success' => true, 'data' => $payments]);
    }

    /**
     * Refund a membership payment via Authorize.Net.
     * For settled transactions: issues a credit. For unsettled: auto-falls back to void.
     */
    public function refundMembershipPayment(Request $request, Membership $membership, MembershipPayment $membershipPayment): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        abort_unless($membershipPayment->membership_id === $membership->id, 404);

        abort_unless(
            $membershipPayment->status === 'succeeded',
            422,
            'Only succeeded payments can be refunded.'
        );

        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:' . $membershipPayment->amount,
            'note'   => 'nullable|string|max:500',
        ]);

        $refundAmount = isset($data['amount']) ? (float) $data['amount'] : (float) $membershipPayment->amount;
        $note         = $data['note'] ?? 'Refund processed by staff';
        $before       = ['status' => $membershipPayment->status, 'amount' => (float) $membershipPayment->amount];

        // No transaction_id → internal/manual payment; just mark refunded in DB.
        if (empty($membershipPayment->transaction_id)) {
            $membershipPayment->update(['status' => 'refunded', 'failure_reason' => $note]);
            $this->service->log($membership, 'payment_refunded', $before, ['refund_amount' => $refundAmount], $note);
            return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
        }

        $account = AuthorizeNetAccount::where('location_id', $membership->home_location_id)
            ->where('is_active', true)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
        }

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim($account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            // Refund requires card last four. Fetch from Authorize.Net GetTransactionDetails.
            $lastFour = null;
            try {
                $detailsReq = new AnetAPI\GetTransactionDetailsRequest();
                $detailsReq->setMerchantAuthentication($merchantAuthentication);
                $detailsReq->setTransId($membershipPayment->transaction_id);
                $detailsCtrl = new AnetController\GetTransactionDetailsController($detailsReq);
                $detailsResp = $detailsCtrl->executeWithApiResponse($environment);
                if ($detailsResp && $detailsResp->getMessages()->getResultCode() === 'Ok') {
                    $txn = $detailsResp->getTransaction();
                    if ($txn?->getPayment()?->getCreditCard()) {
                        $lastFour = substr($txn->getPayment()->getCreditCard()->getCardNumber(), -4);
                    }
                }
            } catch (\Exception) {}

            if (!$lastFour) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'Unable to retrieve card details from Authorize.Net. Try voiding instead if the transaction is unsettled.',
                    'error_code' => 'MISSING_CARD_LAST_FOUR',
                ], 400);
            }

            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber('XXXX' . $lastFour);
            $creditCard->setExpirationDate('XXXX');

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setCreditCard($creditCard);

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('refundTransaction');
            $transactionRequest->setAmount($refundAmount);
            $transactionRequest->setPayment($paymentType);
            $transactionRequest->setRefTransId($membershipPayment->transaction_id);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequest);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response   = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages()) {
                    $refundTxnId = $tresponse->getTransId();

                    Log::info('Membership payment refunded via Authorize.Net', [
                        'membership_id'   => $membership->id,
                        'original_txn_id' => $membershipPayment->transaction_id,
                        'refund_txn_id'   => $refundTxnId,
                        'amount'          => $refundAmount,
                    ]);

                    $membershipPayment->update(['status' => 'refunded', 'failure_reason' => $note]);
                    $this->service->log($membership, 'payment_refunded', $before, [
                        'refund_amount' => $refundAmount,
                        'refund_txn_id' => $refundTxnId,
                    ], $note);

                    return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
                }
            }

            // Determine error; if unsettled (E00027) and full refund → auto-void instead.
            $errorCode    = null;
            $errorMessage = 'Refund failed';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse?->getErrors()) {
                    $errorCode    = $tresponse->getErrors()[0]->getErrorCode();
                    $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $errorCode    = $response->getMessages()->getMessage()[0]->getCode();
                    $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            if ($errorCode === 'E00027' && $refundAmount >= (float) $membershipPayment->amount) {
                // Transaction is unsettled — void it instead.
                $voidReq = new AnetAPI\TransactionRequestType();
                $voidReq->setTransactionType('voidTransaction');
                $voidReq->setRefTransId($membershipPayment->transaction_id);

                $voidApiReq = new AnetAPI\CreateTransactionRequest();
                $voidApiReq->setMerchantAuthentication($merchantAuthentication);
                $voidApiReq->setTransactionRequest($voidReq);

                $voidCtrl = new AnetController\CreateTransactionController($voidApiReq);
                $voidResp = $voidCtrl->executeWithApiResponse($environment);

                if ($voidResp && $voidResp->getMessages()->getResultCode() === 'Ok') {
                    $voidTresponse = $voidResp->getTransactionResponse();
                    if ($voidTresponse && $voidTresponse->getMessages()) {
                        Log::info('Membership payment auto-voided (unsettled → refund fell back to void)', [
                            'membership_id'  => $membership->id,
                            'transaction_id' => $membershipPayment->transaction_id,
                        ]);
                        $membershipPayment->update(['status' => 'voided', 'failure_reason' => 'Auto-voided: transaction unsettled at time of refund request']);
                        $this->service->log($membership, 'payment_voided', $before, ['note' => 'Auto-voided: unsettled'], $note);
                        return response()->json(['success' => true, 'data' => $membershipPayment->fresh(), 'voided_instead' => true]);
                    }
                }
            }

            return response()->json(['success' => false, 'message' => $errorMessage, 'error_code' => $errorCode], 400);

        } catch (\Exception $e) {
            Log::error('Membership payment refund exception', ['error' => $e->getMessage(), 'membership_id' => $membership->id]);
            return response()->json(['success' => false, 'message' => 'Refund processing error.'], 500);
        }
    }

    /**
     * Void a membership payment via Authorize.Net.
     * Works on unsettled transactions (typically same-day). Marks as voided.
     */
    public function voidMembershipPayment(Request $request, Membership $membership, MembershipPayment $membershipPayment): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        abort_unless($membershipPayment->membership_id === $membership->id, 404);

        abort_unless(
            in_array($membershipPayment->status, ['pending', 'succeeded']),
            422,
            'Only pending or succeeded payments can be voided.'
        );

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $note   = $data['note'] ?? 'Payment voided by staff';
        $before = ['status' => $membershipPayment->status];

        // No transaction_id → mark voided in DB only.
        if (empty($membershipPayment->transaction_id)) {
            $membershipPayment->update(['status' => 'voided', 'failure_reason' => $note, 'failed_at' => now()]);
            $this->service->log($membership, 'payment_voided', $before, ['status' => 'voided'], $note);
            return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
        }

        $account = AuthorizeNetAccount::where('location_id', $membership->home_location_id)
            ->where('is_active', true)->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
        }

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim($account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('voidTransaction');
            $transactionRequest->setRefTransId($membershipPayment->transaction_id);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequest);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response   = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages()) {
                    Log::info('Membership payment voided via Authorize.Net', [
                        'membership_id'  => $membership->id,
                        'transaction_id' => $membershipPayment->transaction_id,
                    ]);

                    $membershipPayment->update(['status' => 'voided', 'failure_reason' => $note, 'failed_at' => now()]);
                    $this->service->log($membership, 'payment_voided', $before, ['status' => 'voided'], $note);
                    return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
                }
            }

            $errorMessage = 'Void failed';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse?->getErrors()) {
                    $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            return response()->json(['success' => false, 'message' => $errorMessage], 400);

        } catch (\Exception $e) {
            Log::error('Membership payment void exception', ['error' => $e->getMessage(), 'membership_id' => $membership->id]);
            return response()->json(['success' => false, 'message' => 'Void processing error.'], 500);
        }
    }

    /**
     * Unfreeze a membership — restores it to active and clears frozen_until.
     * Dedicated endpoint so staff don't need to know to call /status.
     */
    public function unfreeze(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $request->validate(['note' => 'nullable|string']);

        $membership->frozen_until = null;
        $membership->save();
        $this->service->changeStatus($membership, 'active', $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    private function resolveCustomer(): ?Customer
    {
        $user = Auth::guard('sanctum')->user();
        return $user instanceof Customer ? $user : null;
    }

    /**
     * Build the list of location names a membership / plan is valid at.
     * For 'all' plans we return the hard-coded canonical locations list so the
     * response is self-contained — no extra location-API call needed by the client.
     */
    private function resolveValidLocations(?MembershipPlan $plan, ?Membership $membership = null): array
    {
        if (! $plan) return [];

        return match ($plan->location_access_mode) {
            // Query the actual DB so names always match what the app shows in dropdowns.
            'all'   => \App\Models\Location::where('company_id', $plan->company_id)
                            ->orderBy('name')
                            ->pluck('name')
                            ->all(),
            'multi' => $plan->approvedLocations->pluck('name')->filter()->sort()->values()->all(),
            // single — valid only at the membership's own home location (or the plan's default)
            default => array_filter([
                $membership?->homeLocation?->name ?? $plan->location?->name ?? null,
            ]),
        };
    }
}
