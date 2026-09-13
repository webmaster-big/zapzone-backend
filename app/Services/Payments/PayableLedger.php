<?php

namespace App\Services\Payments;

use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\EventPurchase;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PayableLedger
{
    private const CENT = 0.005;

    private const TERMINAL = ['refunded', 'voided'];

    private const REVERSAL_STATUSES = ['refunded', 'partially_refunded', 'voided'];

    public function sync(string $payableType, int $payableId): ?Model
    {
        $payable = $this->resolve($payableType, $payableId);
        if (! $payable) {
            return null;
        }

        $rows = Payment::where('payable_id', $payableId)
            ->where('payable_type', $payableType)
            ->count();

        if ($rows === 0) {
            Log::channel(config('checkout.log_channel') ?: config('logging.default'))
                ->info('ledger.no_payment_rows', [
                    'payable_type' => $payableType,
                    'payable_id' => $payableId,
                    'stored_amount_paid' => (float) $payable->amount_paid,
                ]);

            return $payable;
        }

        $net = $this->netPaid($payableType, $payableId, (float) $payable->total_amount);
        $changes = ['amount_paid' => $net];

        if ($payableType === Payment::TYPE_ATTRACTION_PURCHASE) {
            if (! in_array($payable->status, [AttractionPurchase::STATUS_CANCELLED, AttractionPurchase::STATUS_REFUNDED, AttractionPurchase::STATUS_CHECKED_IN], true)) {
                $changes['status'] = $net + self::CENT >= (float) $payable->total_amount
                    ? AttractionPurchase::STATUS_CONFIRMED
                    : AttractionPurchase::STATUS_PENDING;
            }
        } else {
            $changes['payment_status'] = $this->derive($net, (float) $payable->total_amount, $payable->payment_status);
        }

        $payable->update($changes);

        return $payable;
    }

    public function netPaid(string $payableType, int $payableId, float $total): float
    {
        $completed = (float) Payment::where('payable_id', $payableId)
            ->where('payable_type', $payableType)
            ->where('status', 'completed')
            ->sum('amount');

        $refunded = (float) Payment::where('payable_id', $payableId)
            ->where('payable_type', $payableType)
            ->whereIn('status', self::REVERSAL_STATUSES)
            ->sum('amount');

        return round(min(max(0, $completed - $refunded), max(0, $total)), 2);
    }

    public function derive(float $amountPaid, float $total, ?string $current = null): string
    {
        if (in_array($current, self::TERMINAL, true)) {
            return $current;
        }

        if (round($total - $amountPaid, 2) <= self::CENT) {
            return 'paid';
        }

        return $amountPaid > 0 ? 'partial' : 'pending';
    }

    private function resolve(string $payableType, int $payableId): ?Model
    {
        return match ($payableType) {
            Payment::TYPE_BOOKING => Booking::find($payableId),
            Payment::TYPE_ATTRACTION_PURCHASE => AttractionPurchase::find($payableId),
            Payment::TYPE_EVENT_PURCHASE => EventPurchase::find($payableId),
            default => null,
        };
    }
}
