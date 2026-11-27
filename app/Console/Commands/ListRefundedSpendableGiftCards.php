<?php

namespace App\Console\Commands;

use App\Models\GiftCard;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListRefundedSpendableGiftCards extends Command
{
    protected $signature = 'giftcards:refunded-but-spendable';

    protected $description = 'List gift cards whose sale was refunded or voided but which still hold that value (read only, changes nothing)';

    public function handle(): int
    {
        $sales = Payment::query()
            ->where('payable_type', Payment::TYPE_GIFT_CARD)
            ->whereNotNull('payable_id')
            ->whereNull('reverses_payment_id')
            ->whereIn('status', ['completed', 'refunded', 'voided'])
            ->where(fn ($query) => $query->whereNull('notes')->orWhere(fn ($notes) => $notes->where('notes', 'not like', 'Refund from Payment #%')->where('notes', 'not like', 'Void of Payment #%')))
            ->where(fn ($query) => $query->whereNull('transaction_id')->orWhere(fn ($txn) => $txn->where('transaction_id', 'not like', 'VOID-%')->where('transaction_id', 'not like', 'REFUND-%')))
            ->orderBy('id')
            ->get()
            ->groupBy('payable_id');

        $rows = [];

        foreach ($sales as $cardId => $cardSales) {
            $refunded = 0.0;

            foreach ($cardSales as $sale) {
                $reversed = (float) Payment::query()
                    ->where('reverses_payment_id', $sale->id)
                    ->whereIn('status', ['refunded', 'voided'])
                    ->sum('amount');

                if ($reversed <= 0 && in_array($sale->status, ['refunded', 'voided'], true)) {
                    $reversed = (float) $sale->amount;
                }

                $refunded += $reversed;
            }

            if ($refunded <= 0) {
                continue;
            }

            $card = GiftCard::find($cardId);

            if (! $card || $card->deleted || $card->status === 'deleted') {
                continue;
            }

            $spent = (float) DB::table('gift_card_redemptions')->where('gift_card_id', $card->id)->sum('amount')
                - (float) DB::table('gift_card_reversals')->where('gift_card_id', $card->id)->sum('credited');
            $expected = max(0, round((float) $card->initial_value - $spent - $refunded, 2));
            $balance = round((float) $card->balance, 2);

            if ($balance <= $expected + 0.005) {
                continue;
            }

            $rows[] = [
                $card->id,
                '...' . substr((string) $card->code, -4),
                $card->status,
                number_format($balance, 2),
                number_format($refunded, 2),
                number_format($balance - $expected, 2),
                $cardSales->pluck('id')->implode(', '),
            ];
        }

        if ($rows === []) {
            $this->info('No refunded or voided gift card sale still has spendable value on its card.');

            return self::SUCCESS;
        }

        $this->table(['Card', 'Code', 'Status', 'Balance', 'Refunded', 'Should not be on the card', 'Sale payment'], $rows);
        $this->line('Nothing was changed. For each card, lower its balance or deactivate it from Gift Cards if the refund should have taken the value back.');

        return self::SUCCESS;
    }
}
