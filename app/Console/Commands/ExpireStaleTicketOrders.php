<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\TicketOrder;
use App\Services\TicketOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireStaleTicketOrders extends Command
{
    protected $signature = 'orders:expire-stale {--days=7} {--dry-run}';

    protected $description = 'Cancel unpaid pay-on-arrival ticket orders whose last visit day passed more than the grace period ago';

    public function handle(TicketOrderService $orders): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->toDateString();
        $cancelled = 0;

        $candidates = TicketOrder::where('status', TicketOrder::STATUS_PENDING)
            ->where('amount_paid', 0)
            ->where(function ($query) {
                $query->whereIn('payment_method', ['in-store', 'paylater'])
                    ->orWhere(function ($card) {
                        $card->where('payment_method', 'authorize.net')
                            ->where('created_at', '<', now()->subDay());
                    });
            })
            ->with(['attractionPurchases', 'eventPurchases'])
            ->get();

        foreach ($candidates as $order) {
            $lines = $order->lines();

            if ($lines->isEmpty()) {
                continue;
            }

            if ($lines->contains(fn (array $line) => $line['model']->checked_in_at !== null)) {
                continue;
            }

            $lastVisitDay = $lines->map(function (array $line) {
                $model = $line['model'];
                $day = $line['type'] === 'attraction'
                    ? ($model->scheduled_date ?? $model->purchase_date)
                    : $model->purchase_date;

                return $day instanceof \DateTimeInterface ? $day->format('Y-m-d') : (string) $day;
            })->filter()->max();

            $isAbandonedCardOrder = $order->payment_method === 'authorize.net';

            if (!$isAbandonedCardOrder && (!$lastVisitDay || $lastVisitDay >= $cutoff)) {
                continue;
            }

            if ($dryRun) {
                $this->line("would expire {$order->reference_number} (last visit {$lastVisitDay})");
                $cancelled++;
                continue;
            }

            try {
                $orders->cancelOrder($order);

                ActivityLog::log(
                    action: 'Order Expired',
                    category: 'update',
                    description: "Order {$order->reference_number} auto-cancelled: unpaid pay-on-arrival, last visit day {$lastVisitDay} passed over {$days} days ago",
                    locationId: $order->location_id,
                    entityType: 'ticket_order',
                    entityId: $order->id,
                );

                $cancelled++;
            } catch (\Throwable $e) {
                Log::warning('Stale order expiry skipped', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(($dryRun ? 'Would expire ' : 'Expired ') . $cancelled . ' stale order(s).');

        return self::SUCCESS;
    }
}
