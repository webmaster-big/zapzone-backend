<?php

namespace App\Console\Commands;

use App\Services\Checkout\CartLineSpecialBackfill;
use Illuminate\Console\Command;

class BackfillCartSpecialPricing extends Command
{
    protected $signature = 'special-pricing:backfill-cart-lines';

    protected $description = 'Store cart-order special discounts as line totals (safe to run again; rows that do not add up are skipped and listed)';

    public function handle(CartLineSpecialBackfill $backfill): int
    {
        $stats = $backfill->run();

        $this->info("Cart lines converted: {$stats['lines']}. Orders converted: {$stats['orders']}.");

        if ($stats['skipped'] !== []) {
            $this->warn('Skipped because their amounts do not add up (left unchanged): ' . implode(', ', $stats['skipped']));
        }

        return self::SUCCESS;
    }
}
