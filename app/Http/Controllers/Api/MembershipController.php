<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipNote;
use App\Models\MembershipPlan;
use App\Services\MembershipService;
use App\Support\CompanyLocations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        abort_unless($authUser, 403);

        $data = $request->validate([
            'customer_id'          => 'required|exists:customers,id',
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

        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
        $data['billing_amount'] = $plan->price;
        if (! empty($data['terms_accepted'])) $data['terms_accepted_at'] = now();
        if (! empty($data['recurring_billing_authorized'])) $data['recurring_billing_authorized_at'] = now();

        $membership = Membership::create($data);
        $this->service->activate($membership, ['note' => 'Created by staff']);

        return response()->json(['success' => true, 'data' => $membership->fresh()->load('plan', 'customer')], 201);
    }

    /**
     * Customer-side purchase (called from booking-frontend customer flow).
     * Customer must be authenticated.
     */
    public function purchase(Request $request): JsonResponse
    {
        $customer = $this->resolveCustomer();
        abort_unless($customer, 401, 'Customer authentication required');

        $data = $request->validate([
            'membership_plan_id'    => 'required|exists:membership_plans,id',
            'home_location_id'      => 'nullable|exists:locations,id',
            'home_location_name'    => 'nullable|string|max:150',
            'payment_method_label'  => 'nullable|string|max:120',
            'payment_profile_token' => 'nullable|string|max:120',
            'opaque_data'           => 'nullable|array', // Accept.js payload
            'terms_accepted'        => 'required|boolean|accepted',
            'recurring_billing_authorized' => 'required|boolean|accepted',
        ]);

        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);

        // Resolve home location ID — accept either an explicit ID or a name (for hard-coded dropdowns).
        $homeLocId = $data['home_location_id'] ?? null;
        if (!$homeLocId && !empty($data['home_location_name'])) {
            $homeLocId = \App\Models\Location::where('name', $data['home_location_name'])->value('id');
        }
        $homeLocId = $homeLocId ?? $plan->location_id;

        $membership = Membership::create([
            'customer_id'           => $customer->id,
            'membership_plan_id'    => $plan->id,
            'home_location_id'      => $homeLocId,
            'sold_at_location_id'   => $homeLocId,
            'status'                => 'pending',
            'billing_amount'        => $plan->price,
            'payment_method_label'  => $data['payment_method_label'] ?? null,
            'payment_profile_token' => $data['payment_profile_token'] ?? null,
            'terms_accepted'        => true,
            'terms_accepted_at'     => now(),
            'recurring_billing_authorized'    => true,
            'recurring_billing_authorized_at' => now(),
        ]);

        // For free / comped plans, activate immediately.
        // For paid plans, we'd hand off to AuthorizeNetPaymentService here.
        // In this initial build we mark the payment as succeeded if the FE indicates so
        // (so the integration point is wired but the charge call itself is delegated to
        // the existing PaymentService pipeline on the frontend if a token was provided).
        $paymentStatus = $plan->price > 0 && empty($data['payment_profile_token']) && empty($data['opaque_data'])
            ? 'pending'
            : 'succeeded';

        $this->service->recordPayment($membership, [
            'amount'      => $plan->price,
            'status'      => $paymentStatus,
            'description' => "Initial purchase: {$plan->name}",
        ]);

        if ($paymentStatus === 'succeeded' || $plan->price <= 0) {
            $this->service->activate($membership);
        }

        return response()->json([
            'success' => true,
            'data'    => $membership->fresh()->load('plan'),
        ], 201);
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
            'all'   => CompanyLocations::NAMES,
            'multi' => $plan->approvedLocations->pluck('name')->filter()->sort()->values()->all(),
            // single — valid only at the membership's own home location (or the plan's default)
            default => array_filter([
                $membership?->homeLocation?->name ?? $plan->location?->name ?? null,
            ]),
        };
    }
}
