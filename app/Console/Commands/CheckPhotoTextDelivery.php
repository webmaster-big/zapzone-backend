<?php

namespace App\Console\Commands;

use App\Models\PhotoDelivery;
use App\Services\PhotoDeliveryService;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPhotoTextDelivery extends Command
{
    protected $signature = 'photos:check-text-delivery
        {--limit=100 : Most texts to look up in one run}
        {--seconds=120 : Stop starting new lookups after this long}';

    protected $description = 'Ask the text provider whether recent photo texts reached the phone, and record the answer';

    public function handle(PhotoDeliveryService $deliveries): int
    {
        if (!SmsService::isConfigured()) {
            $this->info('Text messaging is not configured, so there is nothing to check.');

            return Command::SUCCESS;
        }

        $limit = max(1, min((int) $this->option('limit'), 500));
        $budget = max(5, (int) $this->option('seconds'));
        $startedAt = microtime(true);
        $waiting = PhotoDelivery::awaitingCarrier()
            ->real()
            ->orderByRaw('provider_checked_at IS NOT NULL, provider_checked_at ASC')
            ->limit($limit * 3)
            ->get()
            ->filter(fn (PhotoDelivery $delivery) => $this->isDue($delivery))
            ->take($limit);

        $tally = [];

        foreach ($waiting as $delivery) {
            if (microtime(true) - $startedAt > $budget) {
                $tally['left for the next run'] = ($tally['left for the next run'] ?? 0) + 1;
                continue;
            }

            $checked = $deliveries->refreshCarrierStatus($delivery);
            $status = $checked->provider_status ?: 'unknown';
            $tally[$status] = ($tally[$status] ?? 0) + 1;
        }

        $settled = $deliveries->settleUnansweredReceipts($startedAt + $budget);

        if ($settled > 0) {
            $tally['confirmed after no carrier answer'] = $settled;
        }

        $summary = collect($tally)->map(fn ($count, $status) => "{$status} {$count}")->implode(', ');
        $this->info("Checked {$waiting->count()} photo text(s)" . ($summary !== '' ? ": {$summary}." : '.'));

        if ($waiting->isNotEmpty()) {
            Log::info('Photo text delivery checked', ['checked' => $waiting->count(), 'statuses' => $tally]);
        }

        return Command::SUCCESS;
    }

    protected function isDue(PhotoDelivery $delivery): bool
    {
        if ($delivery->provider_checked_at === null || $delivery->sent_at === null) {
            return true;
        }

        $age = $delivery->sent_at->diffInMinutes(now());
        $interval = $age < 10 ? 1 : ($age < 360 ? 15 : 60);

        return $delivery->provider_checked_at->lessThanOrEqualTo(now()->subMinutes($interval));
    }
}
