<?php

namespace App\Console\Commands;

use App\Support\PaymentChannelBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ClassifyPaymentChannels extends Command
{
    protected $signature = 'payments:classify-channels';

    protected $description = 'Sort any payment saved without its collection channel into online, in-store or unclassified';

    public function handle(PaymentChannelBackfill $backfill): int
    {
        $result = $backfill->run();
        $captures = array_sum($result['captures'] ?? []);

        if ($captures > 0 || ($result['reversals'] ?? 0) > 0 || ($result['reversals_linked'] ?? 0) > 0) {
            Log::info('Payment channels filled in after the fact', $result);
        }

        $this->info("Captures sorted: {$captures}. Refunds and voids sorted: " . ($result['reversals'] ?? 0) . '. Refunds linked: ' . ($result['reversals_linked'] ?? 0) . '.');

        return self::SUCCESS;
    }
}
