<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Mail\EventPurchaseConfirmation;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\EventPurchase;
use App\Models\Event;
use App\Models\EmailNotification;
use App\Models\Notification;
use App\Models\Membership;
use App\Services\MembershipBenefitService;
use App\Services\EmailNotificationService;
use App\Services\GmailApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventPurchaseController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    public function index(Request $request): JsonResponse
    {
        try {
            $query = EventPurchase::with([
                'event:id,name,start_date,end_date,time_start,time_end,price',
                'customer:id,first_name,last_name,email,phone',
                'location:id,name',
                'addOns:id,name',
            ]);

            $this->applyAuthScope($query, $request);

            // event_purchases has no direct company_id — scope through location instead
            $authUser = $this->resolveAuthUser($request);
            if ($authUser && $authUser->company_id) {
                $companyId = $authUser->company_id;
                $query->whereHas('location', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                });
            }

            if ($request->filled('event_id')) {
                $query->byEvent($request->event_id);
            }
            if ($request->filled('location_id')) {
                $query->byLocation($request->location_id);
            }
            if ($request->filled('status')) {
                $query->byStatus($request->status);
            }
            if ($request->filled('customer_id')) {
                $query->byCustomer($request->customer_id);
            }
            if ($request->filled('purchase_date')) {
                $query->byDate($request->purchase_date);
            }
            if ($request->filled('start_date')) {
                $query->where('purchase_date', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->where('purchase_date', '<=', $request->end_date);
            }
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }
            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            if ($request->filled('search')) {
                $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $query->where(function ($q) use ($like, $term) {
                        $q->where('reference_number', 'like', $like)
                          ->orWhere('guest_name', 'like', $like)
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
                          ->orWhereHas('event', function ($e) use ($like) {
                              $e->where('name', 'like', $like);
                          });
                        if (ctype_digit($term)) {
                            $q->orWhere('id', (int) $term);
                        }
                    });
                }
            }

            $sortBy = $request->get('sort_by');
            $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'desc';
            }

            if ($sortBy && in_array($sortBy, ['purchase_date', 'purchase_time', 'total_amount', 'amount_paid', 'status', 'created_at', 'updated_at', 'id'])) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('purchase_date', 'desc')
                    ->orderBy('purchase_time', 'asc');
            }

            $purchases = $query->get();

            return response()->json($purchases);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch event purchases', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_id' => 'required|exists:events,id',
                'customer_id' => 'nullable|exists:customers,id',
                'membership_id' => 'nullable|exists:memberships,id',
                'membership_applied' => 'nullable|array',
                'membership_applied.*.membership_plan_benefit_id' => 'nullable|integer',
                'membership_applied.*.benefit_type' => 'nullable|string',
                'membership_applied.*.value_mode' => 'nullable|string',
                'membership_applied.*.value_applied' => 'nullable|numeric',
                'location_id' => 'required|exists:locations,id',
                'guest_name' => 'nullable|string|max:255',
                'guest_email' => 'nullable|email|max:255',
                'guest_phone' => 'nullable|string|max:50',
                'purchase_date' => 'required|date',
                'purchase_time' => 'required|date_format:H:i',
                'quantity' => 'required|integer|min:1',
                'total_amount' => 'nullable|numeric|min:0',
                'applied_fees' => 'nullable|array',
                'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
                'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
                'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
                'applied_discounts' => 'nullable|array',
                'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
                'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
                'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
                'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
                'applied_discounts.*.special_pricing_id' => 'nullable|integer',
                'amount_paid' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:card,in-store,paylater,authorize.net',
                'transaction_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'special_requests' => 'nullable|string',
                'send_email' => 'nullable|boolean',
                'sms_consent' => 'nullable|boolean',
                'add_ons' => 'nullable|array',
                'add_ons.*.add_on_id' => 'required_with:add_ons|exists:add_ons,id',
                'add_ons.*.quantity' => 'required_with:add_ons|integer|min:1',
                'add_ons.*.price_at_purchase' => 'required_with:add_ons|numeric|min:0',
            ]);

            $event = Event::findOrFail($validated['event_id']);
            if (!$event->is_active) {
                return response()->json(['message' => 'This event is not currently active'], 422);
            }

            if ((int) ($validated['location_id'] ?? 0) !== (int) $event->location_id) {
                Log::warning('Event purchase location_id did not match event location; corrected to event location', [
                    'event_id' => $event->id,
                    'submitted_location_id' => $validated['location_id'] ?? null,
                    'event_location_id' => $event->location_id,
                ]);
                $validated['location_id'] = $event->location_id;
            }

            if (!$event->isDateValid($validated['purchase_date'])) {
                return response()->json(['message' => 'Selected date is not available for this event'], 422);
            }

            $availableSlots = $event->getAvailableTimeSlotsForDate($validated['purchase_date']);
            if (!in_array($validated['purchase_time'], $availableSlots)) {
                return response()->json(['message' => 'Selected time slot is not available'], 422);
            }

            $duplicateQuery = EventPurchase::where('event_id', $validated['event_id'])
                ->where('purchase_date', $validated['purchase_date'])
                ->where('purchase_time', $validated['purchase_time'])
                ->where('quantity', $validated['quantity'])
                ->where('status', 'pending');

            if (!empty($validated['customer_id'])) {
                $duplicateQuery->where('customer_id', $validated['customer_id']);
            } else {
                $duplicateQuery->where('guest_email', $validated['guest_email'] ?? null);
            }

            $existingPending = $duplicateQuery->first();
            if ($existingPending) {
                $existingPending->load(['event', 'customer', 'location:id,name', 'addOns']);
                Log::info('Duplicate event purchase prevented (existing pending found)', [
                    'existing_purchase_id' => $existingPending->id,
                    'event_id' => $validated['event_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'guest_email' => $validated['guest_email'] ?? null,
                ]);
                return response()->json($existingPending, 200);
            }

            $validated['reference_number'] = 'EVT-' . strtoupper(Str::random(8));

            if (!isset($validated['payment_method'])) {
                $validated['payment_method'] = 'paylater';
            }

            if (in_array($validated['payment_method'], ['in-store', 'card'])) {
                $validated['status'] = 'confirmed';
                $validated['payment_status'] = ($validated['amount_paid'] ?? 0) >= ($validated['total_amount'] ?? 0) ? 'paid' : 'partial';
            } else {
                $validated['status'] = 'pending';
                $validated['payment_status'] = 'pending';
            }

            $addOns = $validated['add_ons'] ?? [];
            $sendEmail = $validated['send_email'] ?? true;
            $smsConsent = $validated['sms_consent'] ?? false;
            unset($validated['add_ons'], $validated['send_email'], $validated['sms_consent']);

            // Derive membership_discount from applied_discounts entries
            if (! empty($validated['applied_discounts'])) {
                $membershipDiscount = collect($validated['applied_discounts'])
                    ->filter(fn($d) => str_starts_with($d['discount_name'] ?? '', 'Member Savings'))
                    ->sum('discount_amount');
                if ($membershipDiscount > 0) {
                    $validated['membership_discount'] = $membershipDiscount;
                }
            }

            $purchase = EventPurchase::create($validated);

            if (!empty($addOns)) {
                foreach ($addOns as $addOn) {
                    $purchase->addOns()->attach($addOn['add_on_id'], [
                        'quantity' => $addOn['quantity'],
                        'price_at_purchase' => $addOn['price_at_purchase'],
                    ]);
                }
            }

            $purchase->load(['event.location.company', 'customer', 'location:id,name', 'addOns']);

            // Create a pending waiver if a template covers this event, so the
            // confirmation can include the {{waiver_link}}. Non-fatal.
            try {
                app(\App\Services\WaiverService::class)->ensureForEventPurchase($purchase);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to create waiver for event purchase', [
                    'event_purchase_id' => $purchase->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->recordMembershipRedemptions($purchase, $validated);

            if ($purchase->customer_id && $purchase->status === 'confirmed' && (float) ($purchase->amount_paid ?? 0) > 0) {
                CustomerNotification::create([
                    'customer_id' => $purchase->customer_id,
                    'location_id' => $purchase->location_id,
                    'type' => 'payment',
                    'priority' => 'medium',
                    'title' => 'Event Purchase Confirmed',
                    'message' => "Your purchase of {$purchase->quantity} ticket(s) for {$purchase->event->name} has been confirmed. Total: $" . number_format($purchase->total_amount, 2),
                    'status' => 'unread',
                    'action_url' => "/events/purchases/{$purchase->id}",
                    'action_text' => 'View Purchase',
                    'metadata' => [
                        'purchase_id' => $purchase->id,
                        'event_id' => $purchase->event_id,
                        'quantity' => $purchase->quantity,
                        'total_amount' => $purchase->total_amount,
                    ],
                ]);
            }

            $customerName = $purchase->customer ? "{$purchase->customer->first_name} {$purchase->customer->last_name}" : $purchase->guest_name;
            if ($purchase->status === 'confirmed' && (float) ($purchase->amount_paid ?? 0) > 0) {
                $formattedDate = \Carbon\Carbon::parse($purchase->purchase_date)->format('m-d');
                $formattedTime = \Carbon\Carbon::parse($purchase->purchase_time)->format('g:i A');
                Notification::create([
                    'location_id' => $purchase->location_id,
                    'type' => 'payment',
                    'priority' => 'medium',
                    'user_id' => auth()->id(),
                    'title' => 'New Event Purchase',
                    'message' => "{$customerName} — {$purchase->quantity}x {$purchase->event->name} on {$formattedDate} at {$formattedTime} • $" . number_format($purchase->total_amount, 2),
                    'status' => 'unread',
                    'action_url' => "/events/purchases/{$purchase->id}",
                    'action_text' => 'View Purchase',
                    'metadata' => [
                        'purchase_id' => $purchase->id,
                        'event_id' => $purchase->event_id,
                        'customer_id' => $purchase->customer_id,
                        'quantity' => $purchase->quantity,
                        'total_amount' => $purchase->total_amount,
                    ],
                ]);
            }

            if (auth()->id()) {
                ActivityLog::log(
                    action: 'Event Purchase Created',
                    category: 'create',
                    description: "Event purchase: {$purchase->quantity} x {$purchase->event->name} by {$customerName}",
                    userId: auth()->id(),
                    locationId: $purchase->location_id,
                    entityType: 'event_purchase',
                    entityId: $purchase->id,
                    metadata: [
                        'created_by' => [
                            'user_id' => auth()->id(),
                            'email' => auth()->user()?->email,
                        ],
                        'created_at' => now()->toIso8601String(),
                        'purchase_details' => [
                            'purchase_id' => $purchase->id,
                            'event_id' => $purchase->event_id,
                            'event_name' => $purchase->event->name,
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

                if ($contactEmail && $purchase->location && $purchase->location->company_id) {
                    Contact::createOrUpdateFromSource(
                        companyId: $purchase->location->company_id,
                        data: [
                            'email' => $contactEmail,
                            'name' => $contactName,
                            'phone' => $contactPhone,
                            'sms_consent' => $smsConsent,
                        ],
                        source: 'event_purchase',
                        tags: ['event_purchase', 'customer'],
                        locationId: $purchase->location_id,
                        createdBy: auth()->id()
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Failed to create contact from event purchase', [
                    'purchase_id' => $purchase->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($sendEmail && $purchase->status === 'confirmed') {
                try {
                    app(EmailNotificationService::class)
                        ->triggerEventNotification($purchase, EmailNotification::TRIGGER_EVENT_CONFIRMED);
                } catch (\Exception $e) {
                    Log::warning('Failed to send event purchase confirmation notification', [
                        'purchase_id' => $purchase->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->recordConversion('event_purchase_completed', $purchase, (float) ($purchase->total_amount ?? 0));

            return response()->json($purchase, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create event purchase', 'error' => $e->getMessage()], 500);
        }
    }

    private function recordMembershipRedemptions(EventPurchase $purchase, array $validated): void
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
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $purchase,
                    $validated['membership_applied'],
                    $purchase->location_id,
                    auth()->id()
                );
                return;
            }

            $qty       = max(1, (int) ($purchase->quantity ?? 1));
            $unitPrice = $qty > 0 ? (float) $purchase->total_amount / $qty : (float) $purchase->total_amount;

            $quote = app(MembershipBenefitService::class)->quote($membership, $purchase->location_id, [[
                'type'       => 'event',
                'id'         => $purchase->event_id,
                'unit_price' => $unitPrice,
                'quantity'   => $qty,
            ]]);

            if (! empty($quote['applied'])) {
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $purchase,
                    $quote['applied'],
                    $purchase->location_id,
                    auth()->id()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to record membership redemptions for event purchase', [
                'purchase_id' => $purchase->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function show(EventPurchase $eventPurchase): JsonResponse
    {
        return response()->json(
            $eventPurchase->load(['event', 'customer', 'location:id,name', 'addOns'])
        );
    }

    public function update(Request $request, EventPurchase $eventPurchase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'guest_name' => 'nullable|string|max:255',
                'guest_email' => 'nullable|email|max:255',
                'guest_phone' => 'nullable|string|max:50',
                'purchase_date' => 'sometimes|date',
                'purchase_time' => 'sometimes|date_format:H:i',
                'quantity' => 'sometimes|integer|min:1',
                'total_amount' => 'nullable|numeric|min:0',
                'applied_fees' => 'nullable|array',
                'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
                'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
                'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
                'amount_paid' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'applied_discounts' => 'nullable|array',
                'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
                'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
                'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
                'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
                'applied_discounts.*.special_pricing_id' => 'nullable|integer',
                'payment_method' => 'nullable|in:card,in-store,paylater,authorize.net',
                'payment_status' => 'in:paid,partial,pending',
                'status' => 'in:pending,confirmed,checked-in,completed,cancelled',
                'notes' => 'nullable|string',
                'special_requests' => 'nullable|string',
                'add_ons' => 'nullable|array',
                'add_ons.*.add_on_id' => 'required_with:add_ons|exists:add_ons,id',
                'add_ons.*.quantity' => 'required_with:add_ons|integer|min:1',
                'add_ons.*.price_at_purchase' => 'required_with:add_ons|numeric|min:0',
            ]);

            $addOns = $validated['add_ons'] ?? null;
            unset($validated['add_ons']);

            $originalDate = optional($eventPurchase->purchase_date)->format('Y-m-d');
            $originalTime = $eventPurchase->purchase_time;
            $originalStatus = $eventPurchase->status;

            if (isset($validated['status'])) {
                switch ($validated['status']) {
                    case 'checked-in':
                        $validated['checked_in_at'] = now();
                        break;
                    case 'completed':
                        $validated['completed_at'] = now();
                        break;
                    case 'cancelled':
                        $validated['cancelled_at'] = now();
                        break;
                }
            }

            $eventPurchase->update($validated);

            $newDate = optional($eventPurchase->fresh()->purchase_date)->format('Y-m-d');
            $dateChanged = (array_key_exists('purchase_date', $validated) && $newDate !== $originalDate)
                || (array_key_exists('purchase_time', $validated) && (string) $eventPurchase->purchase_time !== (string) $originalTime);
            $nowCancelled = ($validated['status'] ?? null) === 'cancelled' && $originalStatus !== 'cancelled';

            try {
                $service = app(EmailNotificationService::class);
                if ($nowCancelled) {
                    $service->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_CANCELLED);
                } elseif ($dateChanged) {
                    $service->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_RESCHEDULED);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to send event update notification', [
                    'purchase_id' => $eventPurchase->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($addOns !== null) {
                $syncData = [];
                foreach ($addOns as $addOn) {
                    $syncData[$addOn['add_on_id']] = [
                        'quantity' => $addOn['quantity'],
                        'price_at_purchase' => $addOn['price_at_purchase'],
                    ];
                }
                $eventPurchase->addOns()->sync($syncData);
            }

            return response()->json(
                $eventPurchase->fresh()->load(['event:id,name', 'customer:id,first_name,last_name,email', 'location:id,name', 'addOns'])
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update event purchase', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        Log::info('Event purchase delete request received', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $eventPurchase = EventPurchase::find($id);

            if (!$eventPurchase) {
                Log::warning('Event purchase delete failed: not found', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Event purchase not found',
                ], 404);
            }

            $userId = null;
            $user = null;
            try {
                $authUser = auth()->user();
                if ($authUser instanceof \App\Models\User) {
                    $userId = $authUser->id;
                    $user = $authUser;
                }
            } catch (\Exception $e) {
                Log::info('Auth resolution skipped on event purchase delete', ['error' => $e->getMessage()]);
            }

            $deletedByName = $user ? "{$user->first_name} {$user->last_name}" : 'system/public';
            $eventName = $eventPurchase->event->name ?? 'Unknown';
            $purchaseId = $eventPurchase->id;
            $locationId = $eventPurchase->event->location_id ?? null;

            $deleted = $eventPurchase->delete();

            $verify = EventPurchase::withTrashed()->find($purchaseId);
            Log::info('Event purchase delete verification', [
                'id' => $purchaseId,
                'delete_returned' => $deleted,
                'deleted_at' => $verify?->deleted_at,
                'trashed' => $verify?->trashed(),
            ]);

            if (!$deleted || !$verify?->trashed()) {
                Log::warning('Event purchase soft delete did not persist, forcing via query', ['id' => $purchaseId]);
                EventPurchase::where('id', $purchaseId)->update(['deleted_at' => now()]);
            }

            ActivityLog::log(
                action: 'Event Purchase Deleted',
                category: 'delete',
                description: "Event purchase deleted: {$eventName} by {$deletedByName}",
                userId: $userId,
                locationId: $locationId,
                entityType: 'event_purchase',
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
                        'event_name' => $eventName,
                        'location_id' => $locationId,
                    ],
                ]
            );

            Log::info('Event purchase deleted successfully', ['id' => $purchaseId, 'event' => $eventName]);

            return response()->json([
                'success' => true,
                'message' => 'Event purchase deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Event purchase delete failed with exception', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete event purchase: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function cancel(EventPurchase $eventPurchase): JsonResponse
    {
        $wasCancelled = $eventPurchase->status === 'cancelled';

        $eventPurchase->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        if (!$wasCancelled) {
            try {
                app(EmailNotificationService::class)
                    ->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_CANCELLED);
            } catch (\Exception $e) {
                Log::warning('Failed to send event cancellation notification', [
                    'purchase_id' => $eventPurchase->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->recordConversion(
            'event_purchase_cancelled',
            $eventPurchase,
            -1 * (float) ($eventPurchase->total_amount ?? 0),
            ['tracking_id' => 'srv:event_purchase:'.$eventPurchase->id.':cancelled']
        );

        return response()->json($eventPurchase->fresh()->load(['event:id,name', 'addOns']));
    }

    public function updateStatus(Request $request, EventPurchase $eventPurchase): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,checked-in,completed,cancelled',
        ]);

        $updates = ['status' => $validated['status']];

        switch ($validated['status']) {
            case 'checked-in':
                $updates['checked_in_at'] = now();
                break;
            case 'completed':
                $updates['completed_at'] = now();
                break;
            case 'cancelled':
                $updates['cancelled_at'] = now();
                break;
        }

        $originalStatus = $eventPurchase->status;
        $eventPurchase->update($updates);

        if ($validated['status'] === 'cancelled') {
            app(MembershipBenefitService::class)->reverseForRedeemable($eventPurchase, 'purchase_cancelled');
        }

        try {
            $service = app(EmailNotificationService::class);
            if ($validated['status'] === 'cancelled' && $originalStatus !== 'cancelled') {
                $service->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_CANCELLED);
            } elseif ($validated['status'] === 'confirmed' && $originalStatus !== 'confirmed') {
                $service->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_CONFIRMED);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send event status notification', [
                'purchase_id' => $eventPurchase->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($eventPurchase->fresh());
    }

    public function customerPurchases(Request $request): JsonResponse
    {
        $query = EventPurchase::select([
                'id', 'reference_number', 'event_id', 'customer_id', 'location_id',
                'guest_name', 'guest_email', 'guest_phone',
                'purchase_date', 'purchase_time',
                'quantity', 'total_amount', 'amount_paid', 'discount_amount',
                'payment_method', 'payment_status', 'status',
                'transaction_id', 'notes', 'special_requests',
                'created_at', 'updated_at'
            ])
            ->with([
                'event:id,name,description,image,start_date,end_date,time_start,time_end,price',
                'customer:id,first_name,last_name,email,phone',
                'location:id,name',
                'addOns:id,name',
            ]);

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('guest_email')) {
            $guestEmail = $request->guest_email;
            $query->where(function ($q) use ($guestEmail) {
                $q->where('guest_email', $guestEmail)
                  ->orWhereHas('customer', function ($customerQuery) use ($guestEmail) {
                      $customerQuery->where('email', $guestEmail);
                  });
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('event', function ($eventQuery) use ($search) {
                      $eventQuery->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('location', function ($locationQuery) use ($search) {
                      $locationQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->get('sort_by', 'purchase_date');
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        if (in_array($sortBy, ['purchase_date', 'purchase_time', 'total_amount', 'status', 'created_at'])) {
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

    private function sendConfirmationEmail(EventPurchase $purchase, string $recipientEmail): void
    {
        $purchase->loadMissing(['event.location.company', 'customer', 'addOns']);

        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        $mailable = new EventPurchaseConfirmation($purchase);
        $emailBody = $mailable->render();
        $subject = 'Event Purchase Confirmation - ' . $purchase->reference_number;

        if ($useGmailApi) {
            $gmailService = new GmailApiService();
            $gmailService->sendEmail($recipientEmail, $subject, $emailBody);
        } else {
            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $emailBody) {
                $message->to($recipientEmail)
                    ->subject($subject)
                    ->html($emailBody);
            });
        }

        Log::info('Event purchase confirmation email sent', [
            'purchase_id' => $purchase->id,
            'email' => $recipientEmail,
        ]);
    }

    public function publicForceDelete($id): JsonResponse
    {
        Log::info('Event purchase public force delete request', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $eventPurchase = EventPurchase::withTrashed()->find($id);

            if (!$eventPurchase) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event purchase not found',
                ], 404);
            }

            if (!$eventPurchase->trashed() && $eventPurchase->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending event purchases can be force deleted',
                ], 403);
            }

            $eventName = $eventPurchase->event->name ?? 'Unknown';
            $purchaseId = $eventPurchase->id;
            $locationId = $eventPurchase->event->location_id ?? null;

            $eventPurchase->forceDelete();

            ActivityLog::log(
                action: 'Event Purchase Force Deleted (Payment Error)',
                category: 'delete',
                description: "Pending event purchase force deleted: {$eventName}",
                userId: null,
                locationId: $locationId,
                entityType: 'event_purchase',
                entityId: $purchaseId,
                metadata: [
                    'reason' => 'payment_error_cleanup',
                    'event_name' => $eventName,
                    'deleted_at' => now()->toIso8601String(),
                ]
            );

            Log::info('Event purchase force deleted successfully', ['id' => $purchaseId, 'event' => $eventName]);

            return response()->json([
                'success' => true,
                'message' => 'Event purchase permanently deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Event purchase public force delete failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to force delete event purchase: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function trashed(Request $request): JsonResponse
    {
        try {
            $query = EventPurchase::onlyTrashed()->with([
                'event:id,name,start_date,end_date,time_start,time_end,price',
                'customer:id,first_name,last_name,email,phone',
                'location:id,name',
                'addOns:id,name',
            ]);

            if ($request->filled('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->filled('search')) {
                $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $query->where(function ($q) use ($like, $term) {
                        $q->where('reference_number', 'like', $like)
                          ->orWhere('guest_name', 'like', $like)
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
                          ->orWhereHas('event', function ($e) use ($like) {
                              $e->where('name', 'like', $like);
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
            if (!in_array($sortBy, ['deleted_at', 'purchase_date', 'purchase_time', 'total_amount', 'amount_paid', 'status', 'created_at', 'updated_at', 'id'])) {
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
            Log::error('Error fetching trashed event purchases', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trashed event purchases',
            ], 500);
        }
    }

    public function restore(int $id): JsonResponse
    {
        $purchase = EventPurchase::onlyTrashed()->findOrFail($id);
        $purchase->restore();
        $purchase->load(['event', 'customer', 'location:id,name', 'addOns']);

        ActivityLog::log(
            action: 'Event Purchase Restored',
            category: 'update',
            description: "Event purchase {$purchase->reference_number} restored",
            userId: auth()->id(),
            locationId: $purchase->location_id,
            entityType: 'event_purchase',
            entityId: $purchase->id,
            metadata: [
                'restored_at' => now()->toIso8601String(),
                'reference_number' => $purchase->reference_number,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Event purchase restored successfully',
            'data' => $purchase,
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $purchases = EventPurchase::onlyTrashed()->whereIn('id', $validated['ids'])->get();
        $restoredCount = 0;

        foreach ($purchases as $purchase) {
            $purchase->restore();
            $restoredCount++;
        }

        ActivityLog::log(
            action: 'Bulk Event Purchases Restored',
            category: 'update',
            description: "{$restoredCount} event purchases restored in bulk",
            userId: auth()->id(),
            entityType: 'event_purchase',
            metadata: [
                'restored_at' => now()->toIso8601String(),
                'restored_count' => $restoredCount,
                'purchase_ids' => $validated['ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$restoredCount} event purchase(s) restored successfully",
        ]);
    }
}
