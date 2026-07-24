<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\CustomerGiftCard;
use App\Models\CustomerNotification;
use App\Models\EventPurchase;
use App\Models\GiftCard;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DiscountService
{
    public function __construct(private PageAnalyticsRecorder $recorder)
    {
    }

    public function validatePromo(string $code, array $context = []): array
    {
        $promo = Promo::byCode($code)->first();

        if (!$promo) {
            return $this->fail('Promo code not found');
        }

        if (!$promo->isValid()) {
            return $this->fail('Promo code is not valid', ['promo' => $promo]);
        }

        if (!$this->isEligible($promo, $context)) {
            return $this->fail('Promo code is not applicable to the selected location or items', ['promo' => $promo]);
        }

        if (!empty($context['customer_id']) && $promo->usage_limit_per_user) {
            $used = $this->customerPromoUsage($promo->id, (int) $context['customer_id']);
            if ($used >= $promo->usage_limit_per_user) {
                return $this->fail('Promo usage limit reached for this customer', ['promo' => $promo]);
            }
        }

        $eligibleSubtotal = $this->eligibleSubtotal($promo, $context);
        $discount = $this->computePromoDiscount($promo, $eligibleSubtotal);

        if ($discount <= 0) {
            return $this->fail('Promo code produces no discount for this order', ['promo' => $promo]);
        }

        return [
            'valid' => true,
            'reason' => null,
            'promo' => $promo,
            'gift_card' => null,
            'discount_amount' => $discount,
            'discount_type' => $promo->type,
            'eligible_subtotal' => $eligibleSubtotal,
            'entry' => $this->promoEntry($promo, $discount, $eligibleSubtotal),
        ];
    }

    public function validateGiftCard(string $code, array $context = []): array
    {
        $giftCard = GiftCard::byCode($code)->first();

        if (!$giftCard) {
            return $this->fail('Gift card not found');
        }

        if (!$giftCard->isValid()) {
            return $this->fail('Gift card is not valid for redemption', ['gift_card' => $giftCard]);
        }

        if (!$this->isEligible($giftCard, $context)) {
            return $this->fail('Gift card is not applicable to the selected location or items', ['gift_card' => $giftCard]);
        }

        $eligibleSubtotal = $this->eligibleSubtotal($giftCard, $context);
        $redeemable = round(min((float) $giftCard->balance, $eligibleSubtotal), 2);

        if ($redeemable <= 0) {
            return $this->fail('Gift card cannot be applied to this order', ['gift_card' => $giftCard]);
        }

        return [
            'valid' => true,
            'reason' => null,
            'promo' => null,
            'gift_card' => $giftCard,
            'discount_amount' => $redeemable,
            'discount_type' => 'fixed',
            'eligible_subtotal' => $eligibleSubtotal,
            'balance' => (float) $giftCard->balance,
            'entry' => $this->giftCardEntry($giftCard, $redeemable, $eligibleSubtotal),
        ];
    }

    public function applyToCheckout(array $params, ?Request $request = null): array
    {
        $running = round((float) ($params['subtotal'] ?? 0), 2);
        $trackingPrefix = $params['tracking_prefix'] ?? ('srv:checkout:' . (string) Str::uuid());

        $context = [
            'location_id' => $params['location_id'] ?? null,
            'items' => $params['items'] ?? [],
            'customer_id' => $params['customer_id'] ?? null,
        ];

        $result = [
            'promo_discount' => 0.0,
            'gift_card_discount' => 0.0,
            'discount_amount' => 0.0,
            'applied_discounts' => [],
            'promo_id' => null,
            'gift_card_id' => null,
            'final_total' => $running,
            'errors' => [],
        ];

        $promo = null;
        if (!empty($params['promo_id'])) {
            $promo = Promo::find($params['promo_id']);
        } elseif (!empty($params['promo_code'])) {
            $promo = Promo::byCode($params['promo_code'])->first();
        }

        if ($promo) {
            $check = $this->validatePromo($promo->code, array_merge($context, ['subtotal' => $running]));
            if ($check['valid']) {
                $this->applyPromo($promo, $check['discount_amount'], $request, [
                    'tracking_id' => $trackingPrefix . ':promo:' . $promo->id,
                ]);
                $result['applied_discounts'][] = $check['entry'];
                $result['promo_discount'] = $check['discount_amount'];
                $result['promo_id'] = $promo->id;
                $running = max(0, round($running - $check['discount_amount'], 2));
            } else {
                $result['errors'][] = $check['reason'];
            }
        }

        $giftCard = null;
        if (!empty($params['gift_card_id'])) {
            $giftCard = GiftCard::find($params['gift_card_id']);
        } elseif (!empty($params['gift_card_code'])) {
            $giftCard = GiftCard::byCode($params['gift_card_code'])->first();
        }

        if ($giftCard) {
            $check = $this->validateGiftCard($giftCard->code, array_merge($context, ['subtotal' => $running]));
            if ($check['valid']) {
                $redeemed = $this->redeemGiftCard(
                    $giftCard,
                    $check['discount_amount'],
                    $params['customer_id'] ?? null,
                    $request,
                    ['tracking_id' => $trackingPrefix . ':gift_card:' . $giftCard->id]
                );
                if ($redeemed > 0) {
                    $result['applied_discounts'][] = $this->giftCardEntry($giftCard, $redeemed, $running);
                    $result['gift_card_discount'] = $redeemed;
                    $result['gift_card_id'] = $giftCard->id;
                    $running = max(0, round($running - $redeemed, 2));
                }
            } else {
                $result['errors'][] = $check['reason'];
            }
        }

        $result['discount_amount'] = round($result['promo_discount'] + $result['gift_card_discount'], 2);
        $result['final_total'] = $running;

        return $result;
    }

    public function applyPromo(Promo $promo, float $discountAmount, ?Request $request = null, array $extra = []): void
    {
        $promo->increment('current_usage');

        if ($promo->usage_limit_total && $promo->fresh()->current_usage >= $promo->usage_limit_total) {
            $promo->update(['status' => 'exhausted']);
        }

        $this->recorder->recordConversion(
            'promo_applied',
            $promo->fresh(),
            round($discountAmount, 2),
            $request,
            [
                'event_type' => 'engagement',
                'metadata' => [
                    'discount_type' => $promo->type,
                    'value' => $promo->value,
                    'discount_amount' => round($discountAmount, 2),
                ],
                'tracking_id' => $extra['tracking_id'] ?? ('srv:promo:' . $promo->id . ':promo_applied:' . (string) Str::uuid()),
            ]
        );
    }

    public function redeemGiftCard(GiftCard $giftCard, float $amount, ?int $customerId = null, ?Request $request = null, array $extra = []): float
    {
        $amount = round(min($amount, (float) $giftCard->balance), 2);

        if ($amount <= 0) {
            return 0.0;
        }

        $previousBalance = (float) $giftCard->balance;
        $newBalance = round($previousBalance - $amount, 2);

        $giftCard->update([
            'balance' => $newBalance,
            'status' => $newBalance <= 0 ? 'redeemed' : 'active',
        ]);

        if ($customerId) {
            CustomerGiftCard::create([
                'customer_id' => $customerId,
                'gift_card_id' => $giftCard->id,
                'redeemed' => true,
                'redeemed_at' => now(),
                'amount' => $amount,
            ]);

            CustomerNotification::create([
                'customer_id' => $customerId,
                'location_id' => $giftCard->location_id,
                'type' => 'gift_card',
                'priority' => 'medium',
                'title' => 'Gift Card Redeemed',
                'message' => 'You have redeemed $' . number_format($amount, 2) . ' from your gift card. Remaining balance: $' . number_format($newBalance, 2),
                'status' => 'unread',
                'action_url' => "/gift-cards/{$giftCard->id}",
                'action_text' => 'View Gift Card',
                'metadata' => [
                    'gift_card_id' => $giftCard->id,
                    'gift_card_code' => $giftCard->code,
                    'redeemed_amount' => $amount,
                    'remaining_balance' => $newBalance,
                ],
            ]);
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Gift Card Redeemed',
            category: 'update',
            description: "Gift card {$giftCard->code} redeemed for $" . number_format($amount, 2),
            userId: auth()->id(),
            locationId: $giftCard->location_id,
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
                    'customer_id' => $customerId,
                    'amount_redeemed' => $amount,
                    'previous_balance' => $previousBalance,
                    'remaining_balance' => $newBalance,
                ],
            ]
        );

        $this->recorder->recordConversion(
            'gift_card_redeemed',
            $giftCard->fresh(),
            $amount,
            $request,
            [
                'event_type' => 'engagement',
                'metadata' => ['remaining_balance' => $newBalance],
                'tracking_id' => $extra['tracking_id'] ?? ('srv:gift_card:' . $giftCard->id . ':gift_card_redeemed:' . (string) Str::uuid()),
            ]
        );

        return $amount;
    }

    protected function isEligible($code, array $context): bool
    {
        $locationId = isset($context['location_id']) ? (int) $context['location_id'] : null;

        if (!$code->appliesToLocation($locationId)) {
            return false;
        }

        if ($code->isItemWide()) {
            return true;
        }

        foreach (($context['items'] ?? []) as $item) {
            $type = $item['type'] ?? null;
            $id = (int) ($item['id'] ?? 0);
            if ($type && $id && $code->appliesToItem($type, $id)) {
                return true;
            }
        }

        return false;
    }

    protected function eligibleSubtotal($code, array $context): float
    {
        return $this->isEligible($code, $context) ? round((float) ($context['subtotal'] ?? 0), 2) : 0.0;
    }

    protected function computePromoDiscount(Promo $promo, float $eligibleSubtotal): float
    {
        if ($eligibleSubtotal <= 0) {
            return 0.0;
        }

        if ($promo->type === 'percentage') {
            return round($eligibleSubtotal * ((float) $promo->value / 100), 2);
        }

        return round(min((float) $promo->value, $eligibleSubtotal), 2);
    }

    protected function customerPromoUsage(int $promoId, int $customerId): int
    {
        $count = Booking::where('promo_id', $promoId)->where('customer_id', $customerId)->count();

        if (Schema::hasColumn('attraction_purchases', 'promo_id')) {
            $count += AttractionPurchase::where('promo_id', $promoId)->where('customer_id', $customerId)->count();
        }

        if (Schema::hasColumn('event_purchases', 'promo_id')) {
            $count += EventPurchase::where('promo_id', $promoId)->where('customer_id', $customerId)->count();
        }

        return $count;
    }

    protected function promoEntry(Promo $promo, float $discount, float $original): array
    {
        return [
            'discount_name' => $promo->name ?: $promo->code,
            'discount_amount' => round($discount, 2),
            'discount_type' => $promo->type,
            'original_price' => round($original, 2),
            'source' => 'promo',
            'promo_id' => $promo->id,
            'code' => $promo->code,
        ];
    }

    protected function giftCardEntry(GiftCard $giftCard, float $amount, float $original): array
    {
        return [
            'discount_name' => 'Gift Card ' . $giftCard->code,
            'discount_amount' => round($amount, 2),
            'discount_type' => 'fixed',
            'original_price' => round($original, 2),
            'source' => 'gift_card',
            'gift_card_id' => $giftCard->id,
            'code' => $giftCard->code,
        ];
    }

    protected function fail(string $reason, array $extra = []): array
    {
        return array_merge([
            'valid' => false,
            'reason' => $reason,
            'promo' => null,
            'gift_card' => null,
            'discount_amount' => 0.0,
            'discount_type' => null,
            'eligible_subtotal' => 0.0,
            'entry' => null,
        ], $extra);
    }
}
