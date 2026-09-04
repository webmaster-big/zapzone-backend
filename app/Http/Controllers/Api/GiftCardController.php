<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\GiftCard;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Payment;
use App\Services\DiscountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\CatalogRules;

class GiftCardController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    public function index(Request $request): JsonResponse
    {
        $query = GiftCard::with(['creator', 'customers', 'location']);

        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $locationId = $authUser->location_id;
                $query->where(function ($q) use ($locationId) {
                    $q->whereNull('location_ids')
                      ->orWhereJsonContains('location_ids', (int) $locationId)
                      ->orWhereJsonContains('location_ids', (string) $locationId)
                      ->orWhere('location_id', $locationId);
                });
            } elseif ($authUser->company_id) {
                $companyId = $authUser->company_id;
                $companyLocationIds = Location::where('company_id', $companyId)->pluck('id')->all();
                $query->where(function ($q) use ($companyId, $companyLocationIds) {
                    $q->whereNull('location_ids')
                      ->orWhereHas('creator', fn($u) => $u->where('company_id', $companyId))
                      ->orWhereHas('location', fn($l) => $l->where('company_id', $companyId));
                    foreach ($companyLocationIds as $locationId) {
                        $q->orWhereJsonContains('location_ids', (int) $locationId)
                          ->orWhereJsonContains('location_ids', (string) $locationId);
                    }
                });
            }
        } else {
            $customer = $request->user();

            if (!$customer instanceof \App\Models\Customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gift cards are only visible to staff and to the customer who owns them.',
                ], 403);
            }

            $query->whereHas('customers', fn ($q) => $q->where('customers.id', $customer->id));
        }

        if ($request->has('location_id')) {
            $query->forLocation($request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        if ($request->filled('customer_id')) {
            $query->whereHas('customers', fn($q) => $q->where('customers.id', $request->customer_id));
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('include_expired')) {
            if (!$request->boolean('include_expired')) {
                $query->notExpired();
            }
        } else {
            $query->notExpired();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['code', 'initial_value', 'balance', 'status', 'created_at', 'expiry_date'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $giftCards = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'gift_cards' => $giftCards->items(),
                'pagination' => [
                    'current_page' => $giftCards->currentPage(),
                    'last_page' => $giftCards->lastPage(),
                    'per_page' => $giftCards->perPage(),
                    'total' => $giftCards->total(),
                    'from' => $giftCards->firstItem(),
                    'to' => $giftCards->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|unique:gift_cards',
            'type' => ['required', Rule::in(['fixed'])],
            'initial_value' => 'required|numeric|min:0',
            'max_usage' => 'integer|min:1',
            'description' => 'nullable|string',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'expiry_date' => 'nullable|date|after:today',
            'created_by' => 'required|exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        if ($validated['type'] === 'percentage' && (float) $validated['initial_value'] > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage gift cards cannot exceed 100%',
            ], 422);
        }

        foreach (['location_ids', 'package_ids', 'attraction_ids', 'event_ids'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = GiftCard::normalizeIds($validated[$field]);
            }
        }

        if (!empty($validated['location_ids'])) {
            $validated['location_id'] = $validated['location_ids'][0];
        } elseif (!empty($validated['location_id'])) {
            $validated['location_ids'] = [(int) $validated['location_id']];
        }

        if (!isset($validated['code'])) {
            do {
                $validated['code'] = 'GC' . strtoupper(Str::random(8));
            } while (GiftCard::where('code', $validated['code'])->exists());
        }

        $validated['balance'] = $validated['initial_value'];

        $giftCard = GiftCard::create($validated);
        $giftCard->load(['creator', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Gift card created successfully',
            'data' => $giftCard,
        ], 201);
    }

    public function show(Request $request, GiftCard $giftCard): JsonResponse
    {
        if (!$this->resolveAuthUser($request)) {
            $customer = $request->user();
            $owns = $customer instanceof \App\Models\Customer
                && $giftCard->customers()->where('customers.id', $customer->id)->exists();

            if (!$owns) {
                return response()->json([
                    'success' => false,
                    'message' => 'That gift card is not on your account.',
                ], 403);
            }
        }

        $giftCard->load(['creator', 'customers', 'location']);

        return response()->json([
            'success' => true,
            'data' => $giftCard,
        ]);
    }

    public function update(Request $request, GiftCard $giftCard): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|unique:gift_cards,code,' . $giftCard->id,
            'type' => ['sometimes', Rule::in(['fixed'])],
            'initial_value' => 'sometimes|numeric|min:0',
            'balance' => 'sometimes|numeric|min:0',
            'max_usage' => 'sometimes|integer|min:1',
            'description' => 'sometimes|nullable|string',
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'expired', 'redeemed', 'cancelled'])],
            'expiry_date' => 'sometimes|nullable|date',
            'location_id' => 'nullable|exists:locations,id',
            'location_ids' => 'sometimes|nullable|array',
            'location_ids.*' => 'integer|exists:locations,id',
            'package_ids' => 'sometimes|nullable|array',
            'package_ids.*' => 'integer|exists:packages,id',
            'attraction_ids' => 'sometimes|nullable|array',
            'attraction_ids.*' => 'integer|exists:attractions,id',
            'event_ids' => 'sometimes|nullable|array',
            'event_ids.*' => 'integer|exists:events,id',
        ]);

        if (($validated['type'] ?? $giftCard->type) === 'percentage' && isset($validated['initial_value']) && (float) $validated['initial_value'] > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage gift cards cannot exceed 100%',
            ], 422);
        }

        if (array_key_exists('expiry_date', $validated) && $validated['expiry_date'] !== null) {
            $incoming = \Carbon\Carbon::parse($validated['expiry_date'])->toDateString();
            $stored = $giftCard->expiry_date?->toDateString();

            if ($incoming !== $stored && $incoming < now()->toDateString()) {
                $denied = CatalogRules::reject('gift_cards', 'expiry_date', 'Expiry date cannot be in the past. Leave it unchanged or pick a future date.', ['gift_card_id' => $giftCard->id, 'incoming' => $incoming, 'stored' => $stored, 'user_id' => auth()->id()]);

                if ($denied) {
                    return $denied;
                }
            }
        }

        foreach (['location_ids', 'package_ids', 'attraction_ids', 'event_ids'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = GiftCard::normalizeIds($validated[$field]);
            }
        }

        if (array_key_exists('location_ids', $validated)) {
            $validated['location_id'] = !empty($validated['location_ids']) ? $validated['location_ids'][0] : null;
        } elseif (!empty($validated['location_id'])) {
            $validated['location_ids'] = [(int) $validated['location_id']];
        }

        $giftCard->update($validated);
        $giftCard->load(['creator', 'location']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Updated',
            category: 'update',
            description: "Gift card {$giftCard->code} updated",
            userId: auth()->id(),
            locationId: $giftCard->location_id,
            entityType: 'gift_card',
            entityId: $giftCard->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'gift_card_details' => [
                    'gift_card_id' => $giftCard->id,
                    'code' => $giftCard->code,
                    'type' => $giftCard->type,
                    'balance' => $giftCard->balance,
                    'status' => $giftCard->status,
                    'location_id' => $giftCard->location_id,
                ],
                'updated_fields' => array_keys($validated),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Gift card updated successfully',
            'data' => $giftCard,
        ]);
    }

    public function destroy(GiftCard $giftCard): JsonResponse
    {
        $giftCardCode = $giftCard->code;
        $giftCardId = $giftCard->id;

        $giftCard->update(['deleted' => true, 'status' => 'deleted']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Deleted',
            category: 'delete',
            description: "Gift card {$giftCardCode} deleted",
            userId: auth()->id(),
            locationId: $giftCard->location_id,
            entityType: 'gift_card',
            entityId: $giftCardId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'gift_card_details' => [
                    'gift_card_id' => $giftCardId,
                    'code' => $giftCardCode,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Gift card deleted successfully',
        ]);
    }

    public function purchase(Request $request, \App\Services\AuthorizeNetCharger $charger): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'amount' => ['required', 'numeric'],
            'payment_method' => ['required', Rule::in(['authorize.net', 'cash', 'in-store'])],
            'purchaser_name' => ['required', 'string', 'max:255'],
            'purchaser_email' => ['required', 'email', 'max:255'],
            'purchaser_phone' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'opaque_data.dataDescriptor' => ['required_if:payment_method,authorize.net', 'string', 'max:255'],
            'opaque_data.dataValue' => ['required_if:payment_method,authorize.net', 'string'],
        ]);

        $principal = $request->user() ?: \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        $staff = $principal instanceof \App\Models\User ? $principal : null;
        $method = $validated['payment_method'];

        if ($method !== 'authorize.net' && !$staff) {
            return response()->json([
                'success' => false,
                'message' => 'Only staff can record a cash or in-store gift card sale.',
            ], 403);
        }

        $customerId = $validated['customer_id'] ?? null;

        if ($customerId && !$staff) {
            $customerId = ($principal instanceof \App\Models\Customer && (int) $principal->id === (int) $customerId)
                ? $customerId
                : null;
        }

        $amount = $this->resolvePurchaseAmount((float) $validated['amount']);

        if ($amount === null) {
            return response()->json([
                'success' => false,
                'message' => 'Choose one of the offered amounts, or a custom amount between $'
                    . number_format((float) config('gift_cards.min_custom'), 2) . ' and $'
                    . number_format((float) config('gift_cards.max_custom'), 2) . '.',
            ], 422);
        }

        $location = \App\Models\Location::find($validated['location_id']);

        if ($staff && in_array((string) $staff->role, ['location_manager', 'attendant'], true)
            && $staff->location_id && (int) $staff->location_id !== (int) $location->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only sell gift cards for your own location.',
            ], 403);
        }

        $fingerprint = 'gc-buy:' . sha1(implode('|', [
            $method,
            (string) $amount,
            (string) $location->id,
            mb_strtolower($validated['purchaser_email']),
            (string) ($staff?->id ?? 'guest'),
        ]));

        if ($existingId = \Illuminate\Support\Facades\Cache::get($fingerprint)) {
            $existing = GiftCard::find($existingId);

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Gift card already purchased.',
                    'data' => $this->purchasePayload($existing, $location, null, $validated['purchaser_email']),
                    'duplicate' => true,
                ], 200);
            }
        }

        $lock = \Illuminate\Support\Facades\Cache::lock($fingerprint . ':lock', 30);

        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'That purchase is already being processed. Please wait a moment.',
            ], 409);
        }

        $card = GiftCard::create([
            'code' => $this->generateUniqueCode(),
            'type' => 'fixed',
            'initial_value' => $amount,
            'balance' => $amount,
            'status' => 'inactive',
            'max_usage' => 1,
            'description' => 'Purchased gift card',
            'location_id' => $location->id,
            'created_by' => $staff?->id,
            'purchased_by_customer_id' => $customerId,
            'purchaser_name' => $validated['purchaser_name'],
            'purchaser_email' => $validated['purchaser_email'],
            'purchaser_phone' => $validated['purchaser_phone'] ?? null,
            'purchase_amount' => $amount,
            'deleted' => false,
        ]);

        $transactionId = 'GC-' . strtoupper(Str::random(10));

        if ($method === 'authorize.net') {
            $account = \App\Models\AuthorizeNetAccount::where('location_id', $location->id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                $card->forceDelete();
                $lock->release();

                return response()->json([
                    'success' => false,
                    'message' => 'Card payments are not set up for this location yet.',
                ], 422);
            }

            [$first, $last] = $this->splitName($validated['purchaser_name']);

            $result = $charger->charge(
                $account,
                $amount,
                $request->input('opaque_data', []),
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $validated['purchaser_email'],
                    'phone' => $validated['purchaser_phone'] ?? null,
                ],
                'GC' . $card->id,
                'GC' . $card->id,
                'Zap Zone gift card'
            );

            if (!$result['success']) {
                $card->forceDelete();
                $lock->release();

                return response()->json([
                    'success' => false,
                    'message' => $result['error'] ?? 'Payment declined.',
                ], 402);
            }

            $transactionId = $result['transaction_id'];
        }

        $card->update([
            'status' => 'active',
            'purchased_at' => now(),
        ]);

        $payment = Payment::create([
            'payable_id' => $card->id,
            'payable_type' => Payment::TYPE_GIFT_CARD,
            'customer_id' => $validated['customer_id'] ?? null,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => $method,
            'status' => 'completed',
            'paid_at' => now(),
            'location_id' => $location->id,
            'notes' => 'Gift card ' . $card->code,
        ]);

        if ($customerId) {
            $card->customers()->syncWithoutDetaching([
                $customerId => ['amount' => 0, 'redeemed' => false],
            ]);
        }

        \Illuminate\Support\Facades\Cache::put($fingerprint, $card->id, now()->addMinutes(2));
        $lock->release();

        if ($customerId) {
            try {
                \App\Models\CustomerNotification::create([
                    'customer_id' => $customerId,
                    'location_id' => $location->id,
                    'type' => 'gift_card',
                    'priority' => 'medium',
                    'title' => 'Gift Card Purchased',
                    'message' => 'Your $' . number_format($amount, 2) . ' gift card is ready. Code: ' . $card->code,
                    'status' => 'unread',
                    'action_url' => '/customer/gift-cards',
                    'action_text' => 'View Gift Cards',
                    'metadata' => [
                        'gift_card_id' => $card->id,
                        'gift_card_code' => $card->code,
                        'amount' => $amount,
                    ],
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Gift card customer notification failed', [
                    'gift_card_id' => $card->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            \App\Models\Notification::create([
                'location_id' => $location->id,
                'type' => 'gift_card',
                'priority' => 'low',
                'title' => 'Gift card sold',
                'message' => sprintf('A $%s gift card (%s) was sold at %s.', number_format($amount, 2), $card->code, $location->name),
                'status' => 'unread',
                'action_url' => '/packages/gift-cards',
                'action_text' => 'View Gift Cards',
                'metadata' => [
                    'gift_card_id' => $card->id,
                    'code' => $card->code,
                    'amount' => $amount,
                    'method' => $method,
                    'payment_id' => $payment->id,
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gift card staff notification failed', [
                'gift_card_id' => $card->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            \Illuminate\Support\Facades\Mail::to($validated['purchaser_email'])
                ->send(new \App\Mail\GiftCardIssued($card->fresh(), $location));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Gift card issued but the email failed', [
                'gift_card_id' => $card->id,
                'error' => $e->getMessage(),
            ]);
        }

        ActivityLog::log(
            'gift_card_purchased',
            'create',
            sprintf('Gift card %s sold for $%s (%s)', $card->code, number_format($amount, 2), $method),
            $staff?->id,
            $location->id,
            'gift_card',
            $card->id,
            ['amount' => $amount, 'payment_id' => $payment->id, 'method' => $method]
        );

        return response()->json([
            'success' => true,
            'message' => 'Gift card purchased.',
            'data' => $this->purchasePayload($card->fresh(), $location, $transactionId, $validated['purchaser_email']),
        ], 201);
    }

    protected function purchasePayload(GiftCard $card, Location $location, ?string $transactionId, string $email): array
    {
        return [
            'id' => $card->id,
            'code' => $card->code,
            'balance' => (float) $card->balance,
            'initial_value' => (float) $card->initial_value,
            'location' => $location->name,
            'expiry_date' => $card->expiry_date?->toDateString(),
            'transaction_id' => $transactionId,
            'emailed_to' => $email,
        ];
    }

    protected function resolvePurchaseAmount(float $requested): ?float
    {
        $amount = round($requested, 2);
        $offered = array_map('floatval', (array) config('gift_cards.denominations', []));

        if (in_array($amount, $offered, true)) {
            return $amount;
        }

        $min = (float) config('gift_cards.min_custom', 10);
        $max = (float) config('gift_cards.max_custom', 500);

        return $amount >= $min && $amount <= $max ? $amount : null;
    }

    protected function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $first = array_shift($parts) ?? '';

        return [$first, implode(' ', $parts)];
    }

    protected function generateUniqueCode(): string
    {
        $prefix = (string) config('gift_cards.code_prefix', 'GC');

        do {
            $code = $prefix . strtoupper(Str::random(8));
        } while (GiftCard::where('code', $code)->exists());

        return $code;
    }

    public function validateByCode(Request $request, DiscountService $discounts): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'location_id' => 'nullable|integer',
            'subtotal' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.type' => 'required_with:items|string',
            'items.*.id' => 'required_with:items|integer',
        ]);

        $result = $discounts->validateGiftCard($request->code, $this->buildContext($request));

        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['reason'],
                'data' => [
                    'is_valid' => false,
                    'gift_card' => $result['gift_card'] ?? null,
                ],
            ]);
        }

        $giftCard = $result['gift_card'];

        return response()->json([
            'success' => true,
            'data' => [
                'is_valid' => true,
                'gift_card' => $giftCard,
                'balance' => $result['balance'],
                'discount_amount' => $result['discount_amount'],
                'eligible_subtotal' => $result['eligible_subtotal'],
                'applied_discount' => $result['entry'],
                'expired' => $giftCard->isExpired(),
            ],
        ]);
    }

    public function redeem(Request $request, GiftCard $giftCard, DiscountService $discounts): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        if (!$giftCard->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Gift card is not valid for redemption',
            ], 400);
        }

        if ($validated['amount'] > $giftCard->balance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance on gift card',
            ], 400);
        }

        $redeemed = $discounts->redeemGiftCard(
            $giftCard,
            (float) $validated['amount'],
            $validated['customer_id'] ?? null,
            $request
        );

        return response()->json([
            'success' => true,
            'message' => 'Gift card redeemed successfully',
            'data' => [
                'redeemed_amount' => $redeemed,
                'remaining_balance' => $giftCard->fresh()->balance,
                'gift_card' => $giftCard->fresh(),
            ],
        ]);
    }

    private function buildContext(Request $request): array
    {
        return [
            'location_id' => $request->input('location_id'),
            'subtotal' => (float) $request->input('subtotal', 0),
            'items' => $request->input('items', []),
            'customer_id' => $request->input('customer_id'),
        ];
    }

    public function deactivate(GiftCard $giftCard): JsonResponse
    {
        $giftCard->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Gift card deactivated successfully',
            'data' => $giftCard,
        ]);
    }

    public function reactivate(GiftCard $giftCard): JsonResponse
    {
        if ($giftCard->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot reactivate expired gift card',
            ], 400);
        }

        $giftCard->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Gift card reactivated successfully',
            'data' => $giftCard,
        ]);
    }
}
