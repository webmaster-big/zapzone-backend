<?php

namespace App\Services\Checkout;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartLineSpecialBackfill
{
    private const LINE_TABLES = ['attraction_purchases', 'event_purchases'];

    public function run(): array
    {
        $stats = ['lines' => 0, 'skipped' => [], 'orders' => 0];

        foreach (self::LINE_TABLES as $table) {
            DB::table($table)
                ->whereNotNull('ticket_order_id')
                ->whereNotNull('applied_discounts')
                ->select(['id', 'quantity', 'discount_amount', 'applied_discounts'])
                ->chunkById(200, function ($rows) use ($table, &$stats) {
                    foreach ($rows as $row) {
                        $quantity = max(1, (int) $row->quantity);

                        $entries = json_decode((string) $row->applied_discounts, true);
                        if (! is_array($entries) || ! $this->hasPerTicketEntries($entries)) {
                            continue;
                        }

                        $expected = 0.0;
                        foreach ($entries as $entry) {
                            $amount = (float) ($entry['discount_amount'] ?? 0);
                            $expected += $this->isPerTicketEntry($entry) ? $amount * $quantity : $amount;
                        }

                        if (abs(round($expected, 2) - (float) $row->discount_amount) > 0.02) {
                            $stats['skipped'][] = "{$table}#{$row->id}";
                            Log::warning('special_pricing.backfill.skipped', [
                                'table' => $table,
                                'id' => $row->id,
                                'discount_amount' => (float) $row->discount_amount,
                                'scaled_entries' => round($expected, 2),
                            ]);
                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update([
                            'applied_discounts' => json_encode($this->scale($entries, fn () => $quantity)),
                        ]);
                        $stats['lines']++;
                    }
                });
        }

        DB::table('ticket_orders')
            ->whereNotNull('applied_discounts')
            ->select(['id', 'applied_discounts'])
            ->chunkById(200, function ($orders) use (&$stats) {
                foreach ($orders as $order) {
                    $entries = json_decode((string) $order->applied_discounts, true);
                    if (! is_array($entries) || ! $this->hasPerTicketEntries($entries)) {
                        continue;
                    }

                    $converted = $this->convertedLines((int) $order->id);
                    $scaled = $this->scale(
                        $entries,
                        fn (array $entry) => $converted[(int) ($entry['line_position'] ?? 0)][(int) $entry['special_pricing_id']] ?? null
                    );

                    if ($scaled !== $entries) {
                        DB::table('ticket_orders')->where('id', $order->id)->update([
                            'applied_discounts' => json_encode($scaled),
                        ]);
                        $stats['orders']++;
                    }
                }
            });

        return $stats;
    }

    private function convertedLines(int $orderId): array
    {
        $converted = [];

        foreach (self::LINE_TABLES as $table) {
            $lines = DB::table($table)
                ->where('ticket_order_id', $orderId)
                ->get(['line_position', 'quantity', 'applied_discounts']);

            foreach ($lines as $line) {
                foreach ((array) json_decode((string) $line->applied_discounts, true) as $entry) {
                    if (is_array($entry) && ! empty($entry['special_pricing_id']) && array_key_exists('unit_discount_amount', $entry)) {
                        $converted[(int) $line->line_position][(int) $entry['special_pricing_id']] = max(1, (int) $line->quantity);
                    }
                }
            }
        }

        return $converted;
    }

    private function isPerTicketEntry(mixed $entry): bool
    {
        return is_array($entry)
            && ! empty($entry['special_pricing_id'])
            && ! array_key_exists('unit_discount_amount', $entry);
    }

    private function hasPerTicketEntries(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($this->isPerTicketEntry($entry)) {
                return true;
            }
        }

        return false;
    }

    private function scale(array $entries, callable $quantityFor): array
    {
        return array_map(function ($entry) use ($quantityFor) {
            if (! $this->isPerTicketEntry($entry)) {
                return $entry;
            }

            $quantity = $quantityFor($entry);
            if ($quantity === null) {
                return $entry;
            }

            $unit = (float) ($entry['discount_amount'] ?? 0);

            return array_merge($entry, [
                'source' => 'special_pricing',
                'unit_discount_amount' => $unit,
                'quantity' => $quantity,
                'discount_amount' => round($unit * $quantity, 2),
            ]);
        }, $entries);
    }
}
