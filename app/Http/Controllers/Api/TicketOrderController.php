<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\AttractionPurchase;
use App\Models\TicketOrder;
use App\Models\Notification;
use App\Models\Payment;
use App\Services\EmailNotificationService;
use App\Services\PurchaseCompletionService;
use App\Services\PushNotificationService;
use App\Services\TicketOrderService;
use App\Services\WaiverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class TicketOrderController extends Controller
{
    use \App\Http\Traits\ScopesByAuthUser;

    public function __construct(private TicketOrderService $orders)
    {
    }

    private function cartRules(): array
    {
        return [
            'items' => 'required|array|min:1|max:50',
            'items.*.type' => ['required', Rule::in(['attraction', 'event'])],
            'items.*.id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:500',
            'items.*.scheduled_date' => 'nullable|date',
            'items.*.scheduled_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'items.*.add_ons' => 'nullable|array|max:20',
            'items.*.add_ons.*.id' => 'required_with:items.*.add_ons|integer|min:1',
            'items.*.add_ons.*.quantity' => 'required_with:items.*.add_ons|integer|min:1|max:100',
        ];
    }

    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate($this->cartRules());

        try {
            return response()->json(['success' => true, 'data' => $this->orders->quote($validated['items'])]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Order quote failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'We could not price this order. Please try again.'], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->cartRules() + [
            'customer_id' => 'nullable|integer|exists:customers,id',
            'membership_id' => 'nullable|integer|exists:memberships,id',
            'guest_name' => 'nullable|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'guest_address' => 'nullable|string|max:255',
            'guest_city' => 'nullable|string|max:100',
            'guest_state' => 'nullable|string|max:50',
            'guest_zip' => 'nullable|string|max:20',
            'guest_country' => 'nullable|string|max:100',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater', 'authorize.net'])],
            'sms_consent' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        if (empty($validated['customer_id']) && empty($validated['guest_name']) && empty($validated['guest_email'])) {
            return response()->json([
                'success' => false,
                'message' => 'We need a name or an email to attach this order to.',
            ], 422);
        }

        $staff = $request->user('sanctum');
        $method = $validated['payment_method'] ?? 'authorize.net';

        if (!$staff && $method !== 'authorize.net') {
            return response()->json([
                'success' => false,
                'message' => 'Online orders are paid by card.',
            ], 422);
        }

        try {
            $order = $this->orders->create($validated['items'], [
                'customer_id' => $validated['customer_id'] ?? null,
                'membership_id' => $validated['membership_id'] ?? null,
                'created_by' => $staff?->id,
                'guest_name' => $validated['guest_name'] ?? null,
                'guest_email' => $validated['guest_email'] ?? null,
                'guest_phone' => $validated['guest_phone'] ?? null,
                'guest_address' => $validated['guest_address'] ?? null,
                'guest_city' => $validated['guest_city'] ?? null,
                'guest_state' => $validated['guest_state'] ?? null,
                'guest_zip' => $validated['guest_zip'] ?? null,
                'guest_country' => $validated['guest_country'] ?? null,
                'payment_method' => $method,
                'notes' => $validated['notes'] ?? null,
            ]);

            $lineInput = array_key_exists('sms_consent', $validated) && $validated['sms_consent'] !== null
                ? ['sms_consent' => (bool) $validated['sms_consent']]
                : [];

            app()->terminating(function () use ($order, $lineInput) {
                try {
                    $this->notify($order->fresh(['attractionPurchases.attraction', 'eventPurchases.event', 'location', 'customer']), $lineInput);
                } catch (Throwable $e) {
                    Log::error('Order post-create notify failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            });

            $qrToken = hash_hmac('sha256', $order->id . '|' . $order->reference_number, (string) config('app.key'));

            return response()->json([
                'success' => true,
                'qr_token' => $qrToken,
                'data' => $this->present($order->load(['attractionPurchases.attraction', 'attractionPurchases.addOns', 'eventPurchases.event', 'eventPurchases.addOns', 'location'])),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Ticket order creation failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json(['success' => false, 'message' => 'We could not create this order. Please try again.'], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = TicketOrder::query()
            ->with(['location', 'customer', 'attractionPurchases.attraction', 'attractionPurchases.addOns', 'eventPurchases.event', 'eventPurchases.addOns']);

        $this->applyAuthScope($query, $request);

        if ($locationId = $request->input('location_id')) {
            $query->where('location_id', $locationId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->input('search')) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('reference_number', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('guest_email', 'like', $like)
                    ->orWhere('guest_phone', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like);
            });
        }

        if ($from = $request->input('start_date')) {
            $query->where('purchase_date', '>=', $from);
        }

        if ($to = $request->input('end_date')) {
            $query->where('purchase_date', '<=', $to);
        }

        $orders = $query->orderByDesc('id')->paginate(max(1, min(200, (int) $request->input('per_page', 25))));

        return response()->json([
            'success' => true,
            'data' => collect($orders->items())->map(fn (TicketOrder $o) => $this->present($o))->all(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, TicketOrder $ticketOrder): JsonResponse
    {
        if ($guard = $this->deniesAccess($request, $ticketOrder)) {
            return $guard;
        }

        $ticketOrder->load(['location', 'customer', 'attractionPurchases.attraction', 'attractionPurchases.addOns', 'eventPurchases.event', 'eventPurchases.addOns']);

        return response()->json(['success' => true, 'data' => $this->present($ticketOrder)]);
    }

    public function checkIn(Request $request, TicketOrder $ticketOrder): JsonResponse
    {
        if ($guard = $this->deniesAccess($request, $ticketOrder)) {
            return $guard;
        }

        $validated = $request->validate([
            'line_ids' => 'nullable|array',
            'line_ids.*' => 'integer',
        ]);

        if ($ticketOrder->status === TicketOrder::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'That order was cancelled.'], 422);
        }

        if ($ticketOrder->status === TicketOrder::STATUS_REFUNDED) {
            return response()->json(['success' => false, 'message' => 'That order was refunded, so its tickets are no longer valid.'], 422);
        }

        $only = isset($validated['line_ids']) ? array_map('intval', $validated['line_ids']) : null;
        $checkedIn = 0;
        $skipped = [];

        DB::transaction(function () use ($ticketOrder, $only, &$checkedIn, &$skipped, $request) {
            foreach ($ticketOrder->lines() as $line) {
                $model = $line['model'];

                if ($only !== null && !in_array($model->id, $only, true)) {
                    continue;
                }

                if ((float) $model->amount_paid < (float) $model->total_amount) {
                    $skipped[] = ['id' => $model->id, 'reason' => 'not fully paid'];
                    continue;
                }

                if ($model->checked_in_at !== null) {
                    $skipped[] = ['id' => $model->id, 'reason' => 'already checked in'];
                    continue;
                }

                $model->checked_in_at = now();

                if ($line['type'] === 'attraction') {
                    $model->status = AttractionPurchase::STATUS_CHECKED_IN;
                    $model->checked_in_by = $request->user()?->id;
                } else {
                    $model->status = 'checked-in';
                }

                $model->save();
                $checkedIn++;
            }

            $ticketOrder->refresh();

            $allIn = $ticketOrder->lines()->every(fn ($l) => $l['model']->checked_in_at !== null);

            if ($allIn) {
                $ticketOrder->status = TicketOrder::STATUS_CHECKED_IN;
                $ticketOrder->save();
            }
        });

                if ($checkedIn > 0) {
            ActivityLog::log(
                action: 'Order Check-In',
                category: 'update',
                description: "Order {$ticketOrder->reference_number}: {$checkedIn} ticket line(s) checked in",
                userId: $request->user()?->id,
                locationId: $ticketOrder->location_id,
                entityType: 'ticket_order',
                entityId: $ticketOrder->id,
                metadata: [
                    'reference_number' => $ticketOrder->reference_number,
                    'checked_in' => $checkedIn,
                    'skipped' => $skipped,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'checked_in' => $checkedIn,
                'skipped' => $skipped,
                'order' => $this->present($ticketOrder->fresh(['attractionPurchases.attraction', 'eventPurchases.event'])),
            ],
        ]);
    }

    /**
     * Roll a just-created order back when the card is declined. Mirrors the existing
     * publicForceDelete path the single-item checkout already relies on, but refuses to
     * touch anything that has taken money or been checked in.
     */
    public function publicRollback(Request $request, int $id): JsonResponse
    {
        $order = TicketOrder::with(['attractionPurchases', 'eventPurchases'])->find($id);

        if (!$order) {
            return response()->json(['success' => true, 'message' => 'Already gone.']);
        }

        if ((float) $order->amount_paid > 0 || $order->status === TicketOrder::STATUS_CHECKED_IN) {
            return response()->json([
                'success' => false,
                'message' => 'That order has taken payment and cannot be rolled back.',
            ], 422);
        }

        if (!$request->user('sanctum')
            && ($order->payment_method !== 'authorize.net' || $order->created_at?->lt(now()->subDay()))) {
            return response()->json([
                'success' => false,
                'message' => 'This order can no longer be rolled back from checkout.',
            ], 422);
        }

        if (Payment::where('payable_type', Payment::TYPE_TICKET_ORDER)
            ->where('payable_id', $order->id)
            ->where('status', 'completed')
            ->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'That order has a completed payment and cannot be rolled back.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $attractionLineIds = $order->attractionPurchases->pluck('id')->all();

            if ($attractionLineIds !== []) {
                \App\Models\Waiver::whereIn('attraction_purchase_id', $attractionLineIds)
                    ->where('status', \App\Models\Waiver::STATUS_PENDING)
                    ->delete();
            }

            foreach ($order->eventPurchases as $eventLine) {
                \App\Models\Waiver::where('event_id', $eventLine->event_id)
                    ->where('customer_id', $order->customer_id)
                    ->whereDate('selected_date', $eventLine->purchase_date)
                    ->where('status', \App\Models\Waiver::STATUS_PENDING)
                    ->delete();
            }

            foreach ($order->lines() as $line) {
                $line['model']->delete();
            }

            $order->status = TicketOrder::STATUS_CANCELLED;
            $order->cancelled_at = now();
            $order->save();
            $order->delete();
        });

        return response()->json(['success' => true, 'message' => 'Order rolled back.']);
    }

    public function storeQrCode(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'qr_code' => 'required|string|max:200000',
            'qr_token' => 'nullable|string|max:64',
        ]);

        $order = TicketOrder::find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (!$request->user('sanctum')) {
            $expected = hash_hmac('sha256', $order->id . '|' . $order->reference_number, (string) config('app.key'));

            if (!hash_equals($expected, (string) ($validated['qr_token'] ?? ''))) {
                return response()->json(['success' => false, 'message' => 'This link has expired.'], 403);
            }
        }

        if ($order->qr_code !== null) {
            return response()->json(['success' => false, 'message' => 'This order already has its code.'], 422);
        }

        $qr = preg_replace('/^data:image\/[a-z]+;base64,/', '', $validated['qr_code']);
        $binary = base64_decode($qr, true);

        if ($binary === false || @getimagesizefromstring($binary) === false) {
            return response()->json(['success' => false, 'message' => 'That is not a valid image.'], 422);
        }

        $order->forceFill(['qr_code' => $qr])->save();

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, TicketOrder $ticketOrder): JsonResponse
    {
        if ($guard = $this->deniesAccess($request, $ticketOrder)) {
            return $guard;
        }

        try {
            $order = $this->orders->cancelOrder($ticketOrder);

            ActivityLog::log(
                action: 'Order Cancelled',
                category: 'update',
                description: "Order {$order->reference_number} cancelled",
                userId: $request->user()?->id,
                locationId: $order->location_id,
                entityType: 'ticket_order',
                entityId: $order->id,
                metadata: [
                    'reference_number' => $order->reference_number,
                    'item_count' => $order->item_count,
                    'ticket_count' => $order->ticket_count,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $this->present($order->load(['attractionPurchases.attraction', 'attractionPurchases.addOns', 'eventPurchases.event', 'eventPurchases.addOns', 'location'])),
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function deniesAccess(Request $request, TicketOrder $order): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($user->company_id && $order->company_id && (int) $order->company_id !== (int) $user->company_id) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id
            && (int) $order->location_id !== (int) $user->location_id) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return null;
    }

    private function notify(TicketOrder $order, array $lineInput = []): void
    {
        foreach ($order->attractionPurchases as $line) {
            app(PurchaseCompletionService::class)->completeAttractionPurchase($line, $lineInput, [
                'waiver' => false,
                'customer_notification' => false,
                'staff_notification' => false,
                'email' => false,
                'conversion' => false,
            ]);
        }

        try {
            app(WaiverService::class)->ensureForTicketOrder($order);
        } catch (Throwable $e) {
            Log::warning('Order waiver creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($order->eventPurchases as $line) {
            try {
                $contactEmail = $order->customer?->email ?? $order->guest_email;

                if ($contactEmail && $order->location && $order->location->company_id) {
                    Contact::createOrUpdateFromSource(
                        companyId: $order->location->company_id,
                        data: [
                            'email' => $contactEmail,
                            'name' => $order->customer
                                ? trim($order->customer->first_name . ' ' . $order->customer->last_name)
                                : $order->guest_name,
                            'phone' => $order->customer?->phone ?? $order->guest_phone,
                            'sms_consent' => array_key_exists('sms_consent', $lineInput) ? (bool) $lineInput['sms_consent'] : null,
                        ],
                        source: 'ticket_order',
                        tags: ['ticket_order', 'event_purchase', 'customer'],
                        locationId: $order->location_id,
                        createdBy: auth()->id()
                    );
                }
            } catch (Throwable $e) {
                Log::warning('Order event-line contact creation failed', [
                    'order_id' => $order->id,
                    'line_id' => $line->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        ActivityLog::log(
            action: 'Order Created',
            category: 'create',
            description: "Order {$order->reference_number}: {$order->item_count} items, {$order->ticket_count} tickets for " . ($order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : $order->guest_name),
            userId: auth()->id(),
            locationId: $order->location_id,
            entityType: 'ticket_order',
            entityId: $order->id,
            metadata: [
                'reference_number' => $order->reference_number,
                'item_count' => $order->item_count,
                'ticket_count' => $order->ticket_count,
                'total_amount' => $order->total_amount,
                'payment_method' => $order->payment_method,
            ]
        );

        // Card orders are notified when the charge settles (TicketOrderService::applyPayment),
        // never at creation — a declined card must not produce a receipt.
        if ($order->payment_method !== 'authorize.net') {
            if ($order->qr_code === null) {
                for ($attempt = 0; $attempt < 6; $attempt++) {
                    usleep(500000);
                    $order->refresh();

                    if ($order->qr_code !== null) {
                        break;
                    }
                }
            }

            $this->orders->sendOrderNotifications($order);
        }
    }

    private function present(TicketOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference_number' => $order->reference_number,
            'status' => $order->status,
            'location_id' => $order->location_id,
            'location_name' => $order->location?->name,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'purchase_date' => $order->purchase_date?->toDateString(),
            'item_count' => (int) $order->item_count,
            'ticket_count' => (int) $order->ticket_count,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'fee_total' => (float) $order->fee_total,
            'total_amount' => (float) $order->total_amount,
            'amount_paid' => (float) $order->amount_paid,
            'remaining_balance' => $order->remaining_balance,
            'payment_method' => $order->payment_method,
            'transaction_id' => $order->transaction_id,
            'notes' => $order->notes,
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'lines' => $order->lines()->map(function (array $line) {
                $model = $line['model'];

                return [
                    'id' => $model->id,
                    'type' => $line['type'],
                    'position' => (int) $model->line_position,
                    'name' => $line['type'] === 'attraction'
                        ? ($model->attraction?->name ?? 'Attraction')
                        : ($model->event?->name ?? 'Event'),
                    'entity_id' => $line['type'] === 'attraction' ? $model->attraction_id : $model->event_id,
                    'quantity' => (int) $model->quantity,
                    'unit_price' => $model->unit_price !== null ? (float) $model->unit_price : null,
                    'unit_price_after_discount' => $model->unit_price_after_discount !== null ? (float) $model->unit_price_after_discount : null,
                    'total_amount' => (float) $model->total_amount,
                    'amount_paid' => (float) $model->amount_paid,
                    'discount_amount' => (float) ($model->discount_amount ?? 0),
                    'applied_discounts' => $model->applied_discounts ?? [],
                    'applied_fees' => $model->applied_fees ?? [],
                    'add_ons' => $model->relationLoaded('addOns')
                        ? $model->addOns->map(fn ($addOn) => [
                            'id' => $addOn->id,
                            'name' => $addOn->name,
                            'quantity' => (int) $addOn->pivot->quantity,
                            'price_at_purchase' => (float) $addOn->pivot->price_at_purchase,
                            'line_total' => round((float) $addOn->pivot->price_at_purchase * (int) $addOn->pivot->quantity, 2),
                        ])->values()->all()
                        : [],
                    'status' => $model->status,
                    'checked_in_at' => $model->checked_in_at?->toIso8601String(),
                    'scheduled_date' => $line['type'] === 'attraction'
                        ? $model->scheduled_date?->toDateString()
                        : $model->purchase_date?->toDateString(),
                    'scheduled_time' => $line['type'] === 'attraction'
                        ? $model->scheduled_time?->format('H:i')
                        : $model->purchase_time?->format('H:i'),
                    'reference_number' => $line['type'] === 'event' ? $model->reference_number : null,
                ];
            })->all(),
        ];
    }
}
