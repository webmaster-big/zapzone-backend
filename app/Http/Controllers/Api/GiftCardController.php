<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\GiftCard;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Services\DiscountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
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

    public function show(GiftCard $giftCard): JsonResponse
    {
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
            'type' => ['sometimes', Rule::in(['fixed', 'percentage'])],
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
