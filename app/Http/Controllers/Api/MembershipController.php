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
use App\Services\MembershipBenefitService;
use App\Support\CompanyLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

class MembershipController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(
        protected MembershipService $service,
        protected MembershipBenefitService $benefits,
    ) {}


    private function resolveAccountForMembership(Membership $membership): ?AuthorizeNetAccount
    {
        $plan = $membership->plan ?? $membership->load('plan')->plan;

        // If plan has a direct billing account set, use it
        if ($plan?->billing_account_id) {
            return AuthorizeNetAccount::where('id', $plan->billing_account_id)
                ->where('is_active', true)
                ->first();
        }

        // Fall back to member's home location account
        if (!$membership->home_location_id) {
            return null;
        }

        return AuthorizeNetAccount::where('location_id', $membership->home_location_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Charge a card via Authorize.Net using an Accept.js opaque token.
     * Never throws — always returns ['success', 'transaction_id', 'error'] so callers
     * can decide what to do with the membership without an exception deleting a
     * record that may already have been charged.
     */
    private function processAuthorizeNetCharge(
        AuthorizeNetAccount $account,
        float $amount,
        array $opaqueData,
        Customer $customer,
        string $invoiceNumber,
        int $refId,
        string $description
    ): array {
        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim($account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $opaque = new AnetAPI\OpaqueDataType();
            $opaque->setDataDescriptor($opaqueData['dataDescriptor']);
            $opaque->setDataValue($opaqueData['dataValue']);

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setOpaqueData($opaque);

            $billTo = new AnetAPI\CustomerAddressType();
            $billTo->setFirstName(substr($customer->first_name ?? '', 0, 50));
            $billTo->setLastName(substr($customer->last_name ?? '', 0, 50));
            if (! empty($customer->email)) $billTo->setEmail(substr($customer->email, 0, 255));
            if (! empty($customer->phone)) $billTo->setPhoneNumber(substr($customer->phone, 0, 25));

            $order = new AnetAPI\OrderType();
            $order->setInvoiceNumber(substr($invoiceNumber, 0, 20));
            $order->setDescription(substr($description, 0, 255));

            $transactionRequest = new AnetAPI\TransactionRequestType();
            $transactionRequest->setTransactionType('authCaptureTransaction');
            $transactionRequest->setAmount($amount);
            $transactionRequest->setPayment($paymentType);
            $transactionRequest->setBillTo($billTo);
            $transactionRequest->setOrder($order);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setRefId('MEM' . $refId);
            $apiRequest->setTransactionRequest($transactionRequest);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response   = $controller->executeWithApiResponse($environment);

            if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getMessages()) {
                    return ['success' => true, 'transaction_id' => $tresponse->getTransId(), 'error' => null];
                }
            }

            $errorMessage = 'Payment declined';
            if ($response) {
                $tresponse = $response->getTransactionResponse();
                if ($tresponse && $tresponse->getErrors()) {
                    $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                } elseif ($response->getMessages()) {
                    $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                }
            }

            Log::warning('Membership charge failed', ['ref_id' => $refId, 'error' => $errorMessage]);
            return ['success' => false, 'transaction_id' => null, 'error' => $errorMessage];
        } catch (\Exception $e) {
            Log::error('Membership charge exception', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return ['success' => false, 'transaction_id' => null, 'error' => 'Payment processing error.'];
        }
    }


    public function index(Request $request): JsonResponse
    {
        $query = Membership::with(['customer:id,first_name,last_name,email,phone', 'plan:id,name,tier,price,billing_cycle', 'homeLocation:id,name']);

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
        if ($request->filled('customer_id'))    $query->where('customer_id', $request->customer_id);
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
            'plan.planBenefits' => fn($q) => $q->where('is_active', true)->orderByDesc('priority'),
            'homeLocation:id,name',
            'visits' => fn($q) => $q->latest('visited_at')->limit(50),
            'visits.location:id,name',
            'visits.staff:id,first_name,last_name',
            'membershipPayments' => fn($q) => $q->latest()->limit(50),
            'notes' => fn($q) => $q->latest(),
            'notes.user:id,first_name,last_name',
            'auditLogs' => fn($q) => $q->latest()->limit(50),
            'auditLogs.user:id,first_name,last_name',
            'benefitRedemptions' => fn($q) => $q->whereNull('reversed_at')->latest()->limit(100),
            'benefitRedemptions.benefit:id,label,benefit_type',
            'benefitRedemptions.staff:id,first_name,last_name',
        ]);

        // Compute visits used this term for the detail page
        $plan = $membership->plan;
        if ($plan?->unlimited_visits_per_term) {
            $visitsUsed = $membership->visits
                ->filter(fn($v) => $v->counted_against_usage && $membership->current_term_start && $v->visited_at >= $membership->current_term_start)
                ->count();
        } else {
            $perTerm  = (int) ($plan?->visits_per_term ?? 0);
            $remaining = $membership->visits_remaining ?? $perTerm;
            $visitsUsed = max(0, $perTerm - $remaining);
        }
        $membership->setAttribute('visits_used_this_term', $visitsUsed);

        return response()->json(['success' => true, 'data' => $membership]);
    }

    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            'customer_id'          => 'nullable|exists:customers,id',
            'first_name'           => 'required_without:customer_id|string|max:100',
            'last_name'            => 'required_without:customer_id|string|max:100',
            'email'                => 'required_without:customer_id|email|max:255',
            'phone'                => 'nullable|string|max:30',
            'membership_plan_id'   => 'required|exists:membership_plans,id',
            'home_location_id'     => 'nullable|exists:locations,id',
            'sold_at_location_id'  => 'nullable|exists:locations,id',
            'is_comped'            => 'boolean',
            'discount_amount'      => 'nullable|numeric|min:0',
            'recurring_billing_authorized' => 'boolean',
            'terms_accepted'       => 'boolean',
            'payment_method_label' => 'nullable|string|max:120',
            'payment_profile_token'=> 'nullable|string|max:120',
            // In-person payment (task 1): how staff are settling this membership.
            'payment_type'                 => ['nullable', Rule::in(['charge', 'external', 'comp', 'none'])],
            'amount'                       => 'nullable|numeric|min:0',
            'opaque_data'                  => 'nullable|array',
            'opaque_data.dataDescriptor'   => 'nullable|string',
            'opaque_data.dataValue'        => 'nullable|string',
        ]);

        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);

        // Resolve (or create) the customer. New customers are de-duplicated by email.
        $customer = $this->resolveOrCreateCustomer($data);
        $customerId = $customer->id;

        // Idempotency guard (task 3): a 500/timeout previously caused the client to
        // retry and pile up duplicate memberships for the same person. If an identical
        // membership was just created, return it instead of creating another.
        $recent = Membership::where('customer_id', $customerId)
            ->where('membership_plan_id', $plan->id)
            ->whereIn('status', ['pending', 'active'])
            ->where('created_at', '>=', now()->subMinutes(2))
            ->latest()
            ->first();
        if ($recent) {
            return response()->json([
                'success' => true,
                'data'    => $recent->fresh()->load('plan', 'customer'),
                'message' => 'An identical membership was just created.',
            ], 200);
        }

        $isComped    = (bool) ($data['is_comped'] ?? false);
        $paymentType = $data['payment_type'] ?? ($isComped ? 'comp' : 'none');
        $chargeAmount = isset($data['amount']) ? (float) $data['amount'] : (float) $plan->price;

        // Create the membership atomically with any customer creation above.
        $membership = DB::transaction(function () use ($data, $plan, $customerId, $isComped) {
            $membershipData = array_intersect_key($data, array_flip([
                'home_location_id', 'sold_at_location_id',
                'discount_amount', 'recurring_billing_authorized',
                'terms_accepted', 'payment_method_label', 'payment_profile_token',
            ]));
            $membershipData['customer_id']        = $customerId;
            $membershipData['membership_plan_id'] = $plan->id;
            $membershipData['is_comped']          = $isComped;
            $membershipData['billing_amount']     = $plan->price;
            $membershipData['status']             = 'pending';
            if (! empty($membershipData['terms_accepted'])) {
                $membershipData['terms_accepted_at'] = now();
            }
            if (! empty($membershipData['recurring_billing_authorized'])) {
                $membershipData['recurring_billing_authorized_at'] = now();
            }
            return Membership::create($membershipData);
        });

        // Settle payment per the chosen method. The card charge happens OUTSIDE any
        // DB transaction so a slow gateway call never holds locks open.
        if ($paymentType === 'charge' && $chargeAmount > 0 && ! $isComped) {
            if (empty($data['opaque_data']['dataDescriptor']) || empty($data['opaque_data']['dataValue'])) {
                $membership->delete();
                return response()->json(['success' => false, 'message' => 'Payment information is required to charge a card.'], 422);
            }

            $account = $this->resolveAccountForMembership($membership);
            if (! $account) {
                $membership->delete();
                return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
            }

            $result = $this->processAuthorizeNetCharge(
                $account, $chargeAmount, $data['opaque_data'], $customer,
                'MEM-' . $membership->id, $membership->id, "Membership: {$plan->name}"
            );

            if (! $result['success']) {
                // The card was declined. Drop the pending membership and surface the
                // gateway message to staff. We deliberately don't record a failed
                // payment here (it would email the customer a dunning notice for a
                // membership that never existed).
                $membership->delete();
                return response()->json(['success' => false, 'message' => $result['error']], 402);
            }

            $this->service->recordPayment($membership, [
                'amount'         => $chargeAmount,
                'status'         => 'succeeded',
                'transaction_id' => $result['transaction_id'],
                'description'    => "In-person charge: {$plan->name}",
            ]);
            if (empty($membership->payment_method_label)) {
                $membership->payment_method_label = $data['payment_method_label'] ?? 'Card (in person)';
                $membership->save();
            }
        } elseif ($paymentType === 'external' && $chargeAmount > 0 && ! $isComped) {
            // Cash / external terminal — record the payment without touching the gateway.
            $this->service->recordPayment($membership, [
                'amount'      => $chargeAmount,
                'status'      => 'succeeded',
                'description' => 'External/cash payment recorded by staff',
            ]);
        }

        $this->service->activate($membership, ['note' => 'Created by staff']);

        return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan', 'customer')], 201);
    }

    private function resolveOrCreateCustomer(array $data): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::findOrFail($data['customer_id']);
        }

        $existing = Customer::where('email', $data['email'])->first();
        if ($existing) {
            return $existing;
        }

        return Customer::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'] ?? null,
            'password'   => Hash::make(Str::random(32)),
            'status'     => 'active',
        ]);
    }

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

        if ($plan->price <= 0) {
            $this->service->recordPayment($membership, [
                'amount'      => 0,
                'status'      => 'succeeded',
                'description' => "Complimentary: {$plan->name}",
            ]);
            $this->service->activate($membership);
            return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan')], 201);
        }

        if (empty($data['opaque_data']['dataDescriptor']) || empty($data['opaque_data']['dataValue'])) {
            $membership->delete();
            return response()->json(['success' => false, 'message' => 'Payment information is required.'], 422);
        }

        $account = $plan->billing_account_id
            ? AuthorizeNetAccount::where('id', $plan->billing_account_id)->where('is_active', true)->first()
            : AuthorizeNetAccount::where('location_id', $homeLocId)->where('is_active', true)->first();

        if (!$account) {
            $membership->delete();
            return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
        }

        $result = $this->processAuthorizeNetCharge(
            $account, (float) $plan->price, $data['opaque_data'], $customer,
            'MEM-' . $membership->id, $membership->id, "Membership: {$plan->name}"
        );

        if (! $result['success']) {
            $this->service->recordPayment($membership, [
                'amount'         => $plan->price,
                'status'         => 'failed',
                'description'    => "Purchase failed: {$plan->name}",
                'failure_reason' => $result['error'],
            ]);
            $membership->delete();
            return response()->json(['success' => false, 'message' => $result['error']], 402);
        }

        // Payment captured. From this point the membership is paid for and must NOT
        // be deleted even if recording the payment or activation hiccups (task 2).
        Log::info('Membership charged via Authorize.Net', [
            'membership_id'  => $membership->id,
            'transaction_id' => $result['transaction_id'],
            'amount'         => $plan->price,
        ]);

        $this->service->recordPayment($membership, [
            'amount'         => $plan->price,
            'status'         => 'succeeded',
            'transaction_id' => $result['transaction_id'],
            'description'    => "Initial purchase: {$plan->name}",
        ]);

        try {
            $this->service->activate($membership);
        } catch (\Throwable $e) {
            Log::error('Membership activation failed after successful charge', [
                'membership_id' => $membership->id,
                'error'         => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $membership->fresh()->load('plan'),
        ], 201);
    }

    public function myMembership(): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer, 401);

        $membership = Membership::with([
            'plan',
            'plan.approvedLocations:id,name',
            'plan.location:id,name',
            'plan.planBenefits' => fn($q) => $q->where('is_active', true)->orderByDesc('priority'),
            'plan.inheritsPlan.planBenefits' => fn($q) => $q->where('is_active', true)->orderByDesc('priority'),
            'homeLocation:id,name',
            'membershipPayments' => fn($q) => $q->latest()->limit(10),
            'benefitRedemptions' => fn($q) => $q->whereNull('reversed_at')->latest()->limit(50),
            'benefitRedemptions.benefit:id,label,benefit_type',
        ])
        ->where('customer_id', $customer->id)
        ->latest()
        ->first();

        if (! $membership) {
            return response()->json(['success' => true, 'data' => null]);
        }

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

        // Compute visits used this term so the customer dashboard can display accurate usage
        if ($plan?->unlimited_visits_per_term) {
            $termStart = $membership->current_term_start;
            $data['visits_used_this_term'] = $membership->visits()
                ->where('counted_against_usage', true)
                ->where('result', 'allowed')
                ->when($termStart, fn ($q) => $q->where('visited_at', '>=', $termStart))
                ->count();
        } else {
            $perTerm  = (int) ($plan?->visits_per_term ?? 0);
            $remaining = $membership->visits_remaining ?? $perTerm;
            $data['visits_used_this_term'] = max(0, $perTerm - $remaining);
        }

        if ($plan && $plan->planBenefits->isNotEmpty()) {
            $enriched = MembershipPlanBenefitController::resolveTargets($plan->planBenefits);
            if (isset($data['plan'])) {
                $data['plan']['plan_benefits'] = $enriched->map(fn ($b) => $b->toArray())->values()->toArray();
            }
        }

        if ($plan?->inheritsPlan && $plan->inheritsPlan->planBenefits?->isNotEmpty()) {
            $enrichedInherited = MembershipPlanBenefitController::resolveTargets($plan->inheritsPlan->planBenefits);
            if (isset($data['plan']['inherits_plan'])) {
                $data['plan']['inherits_plan']['plan_benefits'] = $enrichedInherited->map(fn ($b) => $b->toArray())->values()->toArray();
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Return every membership belonging to the authenticated customer (task 9).
     * Supports customers who hold more than one membership at once. The legacy
     * myMembership() endpoint still returns the single most recent one.
     */
    public function myMemberships(): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer, 401);

        $memberships = Membership::with([
            'plan:id,name,billing_cycle,price,location_access_mode,requires_photo,season_end_date',
            'plan.location:id,name',
            'homeLocation:id,name',
        ])
        ->where('customer_id', $customer->id)
        ->latest()
        ->get()
        ->map(function (Membership $membership) {
            $data = $membership->toArray();
            $data['valid_locations'] = $this->resolveValidLocations($membership->plan, $membership);
            return $data;
        })
        ->values();

        return response()->json(['success' => true, 'data' => $memberships]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id'         => 'nullable|integer|exists:locations,id',
            'membership_id'       => 'nullable|integer|exists:memberships,id',
            'items'               => 'required|array|min:1',
            'items.*.type'        => ['required', Rule::in(['package', 'attraction', 'event', 'addon'])],
            'items.*.id'          => 'nullable|integer',
            'items.*.category'    => 'nullable|string|max:150',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.quantity'    => 'nullable|integer|min:1',
        ]);

        $locationId = $data['location_id'] ?? null;
        $items      = $data['items'];

        $customer = $this->resolveCustomer();
        if ($customer) {
            $quote = $this->benefits->quoteForCustomer($customer, $locationId, $items);
            return response()->json(['success' => true, 'data' => $quote]);
        }

        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 401, 'Authentication required');

        if (empty($data['membership_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'membership_id is required for staff benefit quotes.',
            ], 422);
        }

        $membership = Membership::findOrFail($data['membership_id']);
        $quote = $this->benefits->quote($membership, $locationId, $items);

        return response()->json(['success' => true, 'data' => $quote]);
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

    /**
     * Manually extend a membership term (task 4). Staff can push the term end past
     * the plan's season end date or revive an expired/past-due membership.
     */
    public function extend(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        $data = $request->validate([
            'new_term_end' => 'required|date',
            'note'         => 'nullable|string|max:1000',
        ]);

        $newTermEnd = Carbon::parse($data['new_term_end'])->endOfDay();
        $membership = $this->service->extend($membership, $newTermEnd, $authUser->id, $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $membership->load('plan', 'customer')]);
    }

    /**
     * Permanently delete a membership (task 6). Company admins only, and only after
     * the membership has been canceled.
     */
    public function destroy(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && $authUser->role === 'company_admin', 403);

        if ($membership->status !== 'canceled') {
            return response()->json([
                'success' => false,
                'message' => 'Only canceled memberships can be deleted. Cancel the membership first.',
            ], 422);
        }

        $this->service->log($membership, 'deleted', [
            'status' => $membership->status,
        ], null, 'Deleted by ' . ($authUser->name ?? 'admin'));

        $membership->delete();

        return response()->json(['success' => true, 'message' => 'Membership deleted.']);
    }

    public function changePlan(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate([
            'membership_plan_id' => 'required|exists:membership_plans,id',
            'effective'          => ['nullable', Rule::in(['immediate','next_cycle'])],
            'note'               => 'nullable|string',
        ]);
        // Customers may only change their own membership; staff can change any.
        $customer = $this->resolveCustomer();
        $authUser = $this->resolveAuthUser($request);
        abort_unless(
            ($customer && (int) $customer->id === (int) $membership->customer_id) || $authUser,
            403
        );
        $before = ['membership_plan_id' => $membership->membership_plan_id];
        $membership->membership_plan_id = $data['membership_plan_id'];
        $membership->save();
        $this->service->log($membership, 'plan_change', $before, ['membership_plan_id' => $membership->membership_plan_id], $data['note'] ?? null);
        return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan')]);
    }

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
        return response()->json(['success' => true, 'data' => $note->load('user:id,first_name,last_name')], 201);
    }

    public function eligibility(Request $request, Membership $membership): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->eligibility($membership, $request->integer('location_id') ?: null),
        ]);
    }

    public function updatePaymentMethod(Request $request, Membership $membership): JsonResponse
    {
        $customer = $this->resolveCustomer();
        $authUser = $this->resolveAuthUser($request);

        $ownsIt = $customer && (int) $customer->id === (int) $membership->customer_id;
        abort_unless($ownsIt || $authUser, 403);

        $data = $request->validate([
            'payment_method_label'       => 'required|string|max:120',
            'payment_profile_token'      => 'nullable|string|max:255',
            'opaque_data'                => 'nullable|array',
            'opaque_data.dataDescriptor' => 'nullable|string',
            'opaque_data.dataValue'      => 'nullable|string',
        ]);
        $token = $data['payment_profile_token']
            ?? (!empty($data['opaque_data']['dataValue']) ? $data['opaque_data']['dataValue'] : null)
            ?? $membership->payment_profile_token;
        $membership->payment_method_label  = $data['payment_method_label'];
        $membership->payment_profile_token = $token;
        $membership->save();
        $this->service->log($membership, 'payment_method_update', null, ['payment_method_label' => $data['payment_method_label']]);
        return response()->json(['success' => true, 'data' => $membership->fresh()]);
    }

    public function retryPayment(Request $request, Membership $membership): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $lastFailed = $membership->membershipPayments()->where('status', 'failed')->latest()->first();
        $attempt = ($lastFailed?->retry_attempt ?? 0) + 1;

        $payment = $this->service->recordPayment($membership, [
            'amount'        => $membership->billing_amount,
            'status'        => $request->input('status', 'pending'),
            'retry_attempt' => $attempt,
            'description'   => "Manual retry by staff",
        ]);

        return response()->json(['success' => true, 'data' => $payment]);
    }

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

        if (empty($membershipPayment->transaction_id)) {
            $membershipPayment->update(['status' => 'refunded', 'failure_reason' => $note]);
            $this->service->log($membership, 'payment_refunded', $before, ['refund_amount' => $refundAmount], $note);
            return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
        }

        $account = $this->resolveAccountForMembership($membership);

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
        }

        try {
            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName(trim($account->api_login_id));
            $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

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

    public function voidMembershipPayment(Request $request, Membership $membership, MembershipPayment $membershipPayment): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser && in_array($authUser->role, ['company_admin', 'location_manager']), 403);

        abort_unless($membershipPayment->membership_id === $membership->id, 404);

        abort_unless(
            $membershipPayment->status === 'pending',
            422,
            'Only unsettled (pending) payments can be voided. For settled payments, use Refund instead.'
        );

        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $note   = $data['note'] ?? 'Payment voided by staff';
        $before = ['status' => $membershipPayment->status];

        if (empty($membershipPayment->transaction_id)) {
            $membershipPayment->update(['status' => 'voided', 'failure_reason' => $note, 'failed_at' => now()]);
            $this->service->log($membership, 'payment_voided', $before, ['status' => 'voided'], $note);
            // Voiding payment cancels the membership immediately
            $membership->canceled_at = now();
            $membership->cancellation_effective_at = now();
            $membership->save();
            $this->service->changeStatus($membership, 'canceled', 'Voided payment — ' . $note);
            return response()->json(['success' => true, 'data' => $membershipPayment->fresh()]);
        }

        $account = $this->resolveAccountForMembership($membership);

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
                    // Voiding payment cancels the membership immediately
                    $membership->canceled_at = now();
                    $membership->cancellation_effective_at = now();
                    $membership->save();
                    $this->service->changeStatus($membership, 'canceled', 'Voided payment — ' . $note);
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

    public function upgradePlan(Request $request, Membership $membership): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer && (int) $customer->id === (int) $membership->customer_id, 403);
        abort_unless(in_array($membership->status, ['active', 'past_due', 'pending']), 422);

        $data = $request->validate([
            'membership_plan_id'         => 'required|exists:membership_plans,id',
            'opaque_data'                => 'nullable|array',
            'opaque_data.dataDescriptor' => 'nullable|string',
            'opaque_data.dataValue'      => 'nullable|string',
        ]);

        $newPlan = MembershipPlan::findOrFail($data['membership_plan_id']);

        if ((int) $newPlan->id === (int) $membership->membership_plan_id) {
            return response()->json(['success' => false, 'message' => 'You are already on this plan.'], 422);
        }

        $currentPrice = (float) ($membership->billing_amount ?? $membership->plan?->price ?? 0);
        $newPrice     = (float) $newPlan->price;

        // Prorate the charge: only the remaining fraction of the current term.
        // e.g. upgrading $30 → $60 with 15 of 30 days left = ($60-$30)/30*15 = $15 due today.
        $today       = now()->startOfDay();
        $termEnd     = $membership->current_term_end ? \Carbon\Carbon::parse($membership->current_term_end)->startOfDay() : null;
        $termStart   = $membership->current_term_start ? \Carbon\Carbon::parse($membership->current_term_start)->startOfDay() : null;

        // Total days in the current billing cycle
        $billingDays = match ($membership->plan?->billing_cycle ?? 'monthly') {
            'annual'    => 365,
            'quarterly' => 90,
            'one_time'  => 0,   // one-time plans never get prorated
            'custom'    => max(1, (int) ($membership->plan?->custom_billing_days ?? 30)),
            default     => 30, // monthly
        };

        // If we have real term dates, use actual remaining days; otherwise fall back to billing_days
        $remainingDays = $billingDays;
        if ($termEnd && $termEnd > $today) {
            $remainingDays = max(0, (int) $today->diffInDays($termEnd, false));
        } elseif ($termEnd && $termEnd <= $today) {
            // Term already ended — new term started; charge full new-plan first billing
            $remainingDays = $billingDays;
        }

        $proratedDiff = ($newPrice > $currentPrice)
            ? round(($newPrice - $currentPrice) * $remainingDays / $billingDays, 2)
            : 0.0; // downgrade: no charge today, billing_amount updated for next cycle

        if ($proratedDiff > 0.01) {
            if (empty($data['opaque_data']['dataDescriptor']) || empty($data['opaque_data']['dataValue'])) {
                return response()->json([
                    'success'          => false,
                    'message'          => 'Payment information is required to upgrade to this plan.',
                    'requires_payment' => true,
                    'amount_due'       => $proratedDiff,
                    'prorated'         => true,
                    'remaining_days'   => $remainingDays,
                    'billing_days'     => $billingDays,
                ], 422);
            }

            $account = $this->resolveAccountForMembership($membership);

            if (!$account) {
                return response()->json(['success' => false, 'message' => 'Payment gateway not configured for this location.'], 503);
            }

            try {
                $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
                $merchantAuthentication->setName(trim($account->api_login_id));
                $merchantAuthentication->setTransactionKey(trim($account->transaction_key));
                $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

                $opaqueData = new AnetAPI\OpaqueDataType();
                $opaqueData->setDataDescriptor($data['opaque_data']['dataDescriptor']);
                $opaqueData->setDataValue($data['opaque_data']['dataValue']);

                $paymentType = new AnetAPI\PaymentType();
                $paymentType->setOpaqueData($opaqueData);

                $order = new AnetAPI\OrderType();
                $order->setInvoiceNumber(substr('UPG-' . $membership->id, 0, 20));
                $order->setDescription(substr("Plan upgrade: {$newPlan->name}", 0, 255));

                $transactionRequest = new AnetAPI\TransactionRequestType();
                $transactionRequest->setTransactionType('authCaptureTransaction');
                $transactionRequest->setAmount($proratedDiff);
                $transactionRequest->setPayment($paymentType);
                $transactionRequest->setOrder($order);

                $apiRequest = new AnetAPI\CreateTransactionRequest();
                $apiRequest->setMerchantAuthentication($merchantAuthentication);
                $apiRequest->setRefId('UPG' . $membership->id);
                $apiRequest->setTransactionRequest($transactionRequest);

                $controller = new AnetController\CreateTransactionController($apiRequest);
                $response   = $controller->executeWithApiResponse($environment);

                if ($response && $response->getMessages()->getResultCode() === 'Ok') {
                    $tresponse = $response->getTransactionResponse();
                    if ($tresponse && $tresponse->getMessages()) {
                        $this->service->recordPayment($membership, [
                            'amount'         => $proratedDiff,
                            'status'         => 'succeeded',
                            'transaction_id' => $tresponse->getTransId(),
                            'description'    => "Plan upgrade (prorated {$remainingDays}/{$billingDays} days): {$membership->plan?->name} → {$newPlan->name}",
                        ]);
                    } else {
                        return response()->json(['success' => false, 'message' => 'Payment processing error.'], 500);
                    }
                } else {
                    $errorMessage = 'Payment declined';
                    if ($response) {
                        $tresponse = $response->getTransactionResponse();
                        if ($tresponse && $tresponse->getErrors()) {
                            $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                        } elseif ($response->getMessages()) {
                            $errorMessage = $response->getMessages()->getMessage()[0]->getText();
                        }
                    }
                    return response()->json(['success' => false, 'message' => $errorMessage], 402);
                }
            } catch (\Exception $e) {
                Log::error('Plan upgrade payment exception', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'Payment processing error.'], 500);
            }
        }

        $before = ['membership_plan_id' => $membership->membership_plan_id, 'billing_amount' => $membership->billing_amount];
        $membership->membership_plan_id = $newPlan->id;
        $membership->billing_amount     = $newPlan->price;
        $membership->save();

        $this->service->log($membership, 'plan_change', $before, [
            'membership_plan_id' => $newPlan->id,
            'billing_amount'     => $newPlan->price,
        ], 'Customer plan change');

        return response()->json([
            'success'        => true,
            'data'           => $membership->fresh()->load('plan'),
            'prorated_charge'=> $proratedDiff,
            'remaining_days' => $remainingDays,
            'billing_days'   => $billingDays,
        ]);
    }

    private function resolveCustomer(): ?Customer
    {
        $user = Auth::guard('sanctum')->user();
        return $user instanceof Customer ? $user : null;
    }

    private function resolveValidLocations(?MembershipPlan $plan, ?Membership $membership = null): array
    {
        if (! $plan) return [];

        return match ($plan->location_access_mode) {
            'all'   => \App\Models\Location::where('company_id', $plan->company_id)
                            ->orderBy('name')
                            ->pluck('name')
                            ->all(),
            'multi' => $plan->approvedLocations->pluck('name')->filter()->sort()->values()->all(),
            default => array_filter([
                $membership?->homeLocation?->name ?? $plan->location?->name ?? null,
            ]),
        };
    }
}
