<?php

namespace App\Http\Traits;

use App\Services\DiscountService;
use Illuminate\Support\Facades\Log;

trait ReversesGiftCards
{
    protected function giftCardWasReversedFor(string $payableType, int $payableId): bool
    {
        return \Illuminate\Support\Facades\DB::table('gift_card_reversals')
            ->where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->exists();
    }

    protected function reverseGiftCardFor(?object $payable, string $payableType, string $reason): void
    {
        if (!$payable) {
            return;
        }

        try {
            app(DiscountService::class)->reverseGiftCardForPayable($payable, $payableType, $reason);
        } catch (\Throwable $e) {
            Log::critical('Gift card reversal FAILED and needs manual recovery', [
                'gift_card_id' => $payable->gift_card_id ?? null,
                'payable_type' => $payableType,
                'payable_id' => $payable->id,
                'applied_discounts' => $payable->applied_discounts,
                'reason' => $reason,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
