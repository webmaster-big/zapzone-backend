<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentChannelBackfill
{
    private const PAYABLE_TABLES = [
        'booking' => 'bookings',
        'attraction_purchase' => 'attraction_purchases',
        'event_purchase' => 'event_purchases',
        'ticket_order' => 'ticket_orders',
        'gift_card' => 'gift_cards',
    ];

    private const CHECKOUT_MINUTES = 60;

    private const ATTRACTION_STAFF_RECORDED_FROM = '2026-10-14';

    public function run(): array
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'channel') || ! Schema::hasColumn('payments', 'reverses_payment_id')) {
            return ['reversals_linked' => 0, 'captures' => [], 'reversals' => 0];
        }

        return [
            'reversals_linked' => $this->linkReversals(),
            'captures' => $this->classifyCaptures(),
            'reversals' => $this->classifyReversals(),
        ];
    }

    private function linkReversals(): int
    {
        $linked = 0;

        DB::table('payments')
            ->whereNull('reverses_payment_id')
            ->whereIn('status', ['refunded', 'voided'])
            ->where(fn ($query) => $query->where('notes', 'like', 'Refund from Payment #%')->orWhere('notes', 'like', 'Void of Payment #%'))
            ->select(['id', 'notes'])
            ->chunkById(500, function ($rows) use (&$linked) {
                foreach ($rows as $row) {
                    if (! preg_match('/^(?:Refund from|Void of) Payment #(\d+)/', (string) $row->notes, $match)) {
                        continue;
                    }

                    $originalId = (int) $match[1];

                    if ($originalId === (int) $row->id || ! DB::table('payments')->where('id', $originalId)->exists()) {
                        continue;
                    }

                    DB::table('payments')->where('id', $row->id)->update(['reverses_payment_id' => $originalId]);
                    $linked++;
                }
            });

        return $linked;
    }

    private function classifyCaptures(): array
    {
        $counts = ['in_store' => 0, 'online' => 0, 'staff_card' => 0, 'unverified' => 0];

        DB::table('payments')
            ->whereNull('channel')
            ->whereNull('reverses_payment_id')
            ->where(fn ($query) => $query->whereNull('notes')->orWhere(fn ($notes) => $notes->where('notes', 'not like', 'Refund from Payment #%')->where('notes', 'not like', 'Void of Payment #%')))
            ->where(fn ($query) => $query->whereNull('transaction_id')->orWhere(fn ($txn) => $txn->where('transaction_id', 'not like', 'VOID-%')->where('transaction_id', 'not like', 'REFUND-%')))
            ->select(['id', 'method', 'payable_type', 'payable_id', 'created_at'])
            ->chunkById(500, function ($rows) use (&$counts) {
                $payables = $this->payablesFor($rows);

                foreach ($rows as $row) {
                    $channel = $this->captureChannel($row, $payables);
                    DB::table('payments')->where('id', $row->id)->update(['channel' => $channel]);
                    $counts[$channel]++;
                }
            });

        return $counts;
    }

    private function classifyReversals(): int
    {
        $updated = 0;

        DB::table('payments')
            ->whereNull('channel')
            ->where(fn ($query) => $query->whereNotNull('reverses_payment_id')
                ->orWhere('notes', 'like', 'Refund from Payment #%')
                ->orWhere('notes', 'like', 'Void of Payment #%')
                ->orWhere('transaction_id', 'like', 'VOID-%')
                ->orWhere('transaction_id', 'like', 'REFUND-%'))
            ->select(['id', 'method', 'reverses_payment_id'])
            ->chunkById(500, function ($rows) use (&$updated) {
                $originals = DB::table('payments')
                    ->whereIn('id', $rows->pluck('reverses_payment_id')->filter()->unique()->all())
                    ->pluck('channel', 'id');

                foreach ($rows as $row) {
                    $channel = $row->reverses_payment_id ? ($originals[$row->reverses_payment_id] ?? null) : null;
                    $channel ??= in_array($row->method, ['cash', 'card', 'in-store'], true) ? 'in_store' : 'unverified';

                    DB::table('payments')->where('id', $row->id)->update(['channel' => $channel]);
                    $updated++;
                }
            });

        return $updated;
    }

    private function payablesFor($rows): array
    {
        $payables = [];

        foreach (self::PAYABLE_TABLES as $type => $table) {
            $ids = $rows->where('method', 'authorize.net')->where('payable_type', $type)->pluck('payable_id')->filter()->unique()->all();

            if ($ids === [] || ! Schema::hasTable($table)) {
                continue;
            }

            $columns = ['id', 'created_at'];

            if (Schema::hasColumn($table, 'created_by')) {
                $columns[] = 'created_by';
            }

            $payables[$type] = DB::table($table)->whereIn('id', $ids)->get($columns)->keyBy('id');
        }

        return $payables;
    }

    private function captureChannel(object $row, array $payables): string
    {
        if (in_array($row->method, ['cash', 'card', 'in-store'], true)) {
            return 'in_store';
        }

        if ($row->method !== 'authorize.net') {
            return 'unverified';
        }

        $payable = $payables[$row->payable_type][$row->payable_id] ?? null;

        if (! $payable) {
            return 'unverified';
        }

        if (! empty($payable->created_by)) {
            return 'staff_card';
        }

        if ($row->payable_type === 'attraction_purchase' && (! $payable->created_at || Carbon::parse($payable->created_at)->lt(Carbon::parse(self::ATTRACTION_STAFF_RECORDED_FROM)))) {
            return 'unverified';
        }

        if ($row->payable_type === 'gift_card' && property_exists($payable, 'created_by')) {
            return 'online';
        }

        if (! property_exists($payable, 'created_by') || ! $payable->created_at || ! $row->created_at) {
            return 'unverified';
        }

        $created = Carbon::parse($payable->created_at);
        $paid = Carbon::parse($row->created_at);

        return $paid->greaterThanOrEqualTo($created->copy()->subMinute()) && $paid->lessThanOrEqualTo($created->copy()->addMinutes(self::CHECKOUT_MINUTES))
            ? 'online'
            : 'unverified';
    }
}
