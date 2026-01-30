<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    /**
     * Display a listing of gift cards.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GiftCard::with(['creator', 'packages', 'customers']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Filter by expiry
        if ($request->has('include_expired')) {
            if (!$request->boolean('include_expired')) {
                $query->notExpired();
            }
        } else {
            $query->notExpired();
        }

        // Search by code or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
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

    /**
     * Store a newly created gift card.
     */
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
        ]);

        // Generate unique code if not provided
        if (!isset($validated['code'])) {
            do {
                $validated['code'] = 'GC' . strtoupper(Str::random(8));
            } while (GiftCard::where('code', $validated['code'])->exists());
        }

        // Set balance to initial value
        $validated['balance'] = $validated['initial_value'];

        $giftCard = GiftCard::create($validated);
        $giftCard->load(['creator', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Gift card created successfully',
            'data' => $giftCard,
        ], 201);
    }

    /**
     * Display the specified gift card.
     */
    public function show(GiftCard $giftCard): JsonResponse
    {
        $giftCard->load(['creator', 'packages', 'customers']);

        return response()->json([
            'success' => true,
            'data' => $giftCard,
        ]);
    }

    /**
     * Update the specified gift card.
     */
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
        ]);

        $giftCard->update($validated);
        $giftCard->load(['creator', 'packages']);

        // Log gift card update activity
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Updated',
            category: 'update',
            description: "Gift card {$giftCard->code} updated",
            userId: auth()->id(),
            locationId: null,
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

    /**
     * Remove the specified gift card.
     */
    public function destroy(GiftCard $giftCard): JsonResponse
    {
        $giftCardCode = $giftCard->code;
        $giftCardId = $giftCard->id;

        $giftCard->update(['deleted' => true, 'status' => 'deleted']);

        // Log gift card deletion activity
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Deleted',
            category: 'delete',
            description: "Gift card {$giftCardCode} deleted",
            userId: auth()->id(),
            locationId: null,
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

    /**
     * Validate gift card by code.
     */
    public function validateByCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $giftCard = GiftCard::byCode($request->code)->first();

        if (!$giftCard) {
            return response()->json([
                'success' => false,
                'message' => 'Gift card not found',
            ], 404);
        }

        $isValid = $giftCard->isValid();

        return response()->json([
            'success' => true,
            'data' => [
                'gift_card' => $giftCard,
                'is_valid' => $isValid,
                'balance' => $giftCard->balance,
                'expired' => $giftCard->isExpired(),
            ],
        ]);
    }

    /**
     * Redeem gift card.
     */
    public function redeem(Request $request, GiftCard $giftCard): JsonResponse
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

        $newBalance = $giftCard->balance - $validated['amount'];
        $giftCard->update([
            'balance' => $newBalance,
            'status' => $newBalance <= 0 ? 'redeemed' : 'active',
        ]);

        // Create notification for customer if provided
        if (isset($validated['customer_id'])) {
            CustomerNotification::create([
                'customer_id' => $validated['customer_id'],
                'location_id' => null,
                'type' => 'gift_card',
                'priority' => 'medium',
                'title' => 'Gift Card Redeemed',
                'message' => "You have redeemed $" . number_format($validated['amount'], 2) . " from your gift card. Remaining balance: $" . number_format($newBalance, 2),
                'status' => 'unread',
                'action_url' => "/gift-cards/{$giftCard->id}",
                'action_text' => 'View Gift Card',
                'metadata' => [
                    'gift_card_id' => $giftCard->id,
                    'gift_card_code' => $giftCard->code,
                    'redeemed_amount' => $validated['amount'],
                    'remaining_balance' => $newBalance,
                ],
            ]);
        }

        // Log gift card redemption activity
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Redeemed',
            category: 'update',
            description: "Gift card {$giftCard->code} redeemed for $" . number_format($validated['amount'], 2),
            userId: auth()->id(),
            locationId: null,
            entityType: 'gift_card',
            entityId: $giftCard->id,
            metadata: [
                'redeemed_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'redeemed_at' => now()->toIso8601String(),
                'gift_card_details' => [
                    'gift_card_id' => $giftCard->id,
                    'code' => $giftCard->code,
                ],
                'redemption_details' => [
                    'customer_id' => $validated['customer_id'] ?? null,
                    'amount_redeemed' => $validated['amount'],
                    'previous_balance' => $giftCard->balance + $validated['amount'],
                    'remaining_balance' => $newBalance,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Gift card redeemed successfully',
            'data' => [
                'redeemed_amount' => $validated['amount'],
                'remaining_balance' => $newBalance,
                'gift_card' => $giftCard,
            ],
        ]);
    }

    /**
     * Deactivate gift card.
     */
    public function deactivate(GiftCard $giftCard): JsonResponse
    {
        $giftCard->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Gift card deactivated successfully',
            'data' => $giftCard,
        ]);
    }

    /**
     * Reactivate gift card.
     */
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
