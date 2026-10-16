<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_orders') || ! Schema::hasTable('payments')) {
            return;
        }

        $orders = DB::table('ticket_orders as o')
            ->join('payments as p', function ($join) {
                $join->on('p.payable_id', '=', 'o.id')
                    ->where('p.payable_type', 'ticket_order')
                    ->where('p.status', 'completed')
                    ->whereNull('p.deleted_at')
                    ->whereNotNull('p.customer_id');
            })
            ->whereNull('o.customer_id')
            ->where('o.created_at', '>=', '2026-10-07 00:00:00')
            ->groupBy('o.id')
            ->havingRaw('COUNT(DISTINCT p.customer_id) = 1')
            ->selectRaw('o.id as order_id, MIN(p.customer_id) as customer_id')
            ->get();

        $linked = [];

        foreach ($orders as $row) {
            if (! DB::table('customers')->where('id', $row->customer_id)->exists()) {
                continue;
            }

            DB::transaction(function () use ($row) {
                $now = now();

                DB::table('ticket_orders')->where('id', $row->order_id)->whereNull('customer_id')
                    ->update(['customer_id' => $row->customer_id, 'updated_at' => $now]);

                foreach (['attraction_purchases', 'event_purchases'] as $lines) {
                    DB::table($lines)->where('ticket_order_id', $row->order_id)->whereNull('customer_id')
                        ->update(['customer_id' => $row->customer_id, 'updated_at' => $now]);
                }
            });

            $linked[] = (int) $row->order_id;
        }

        if ($linked !== []) {
            Log::info('Cart orders linked to the customer who paid for them', ['ticket_order_ids' => $linked]);
        }
    }

    public function down(): void
    {
    }
};
