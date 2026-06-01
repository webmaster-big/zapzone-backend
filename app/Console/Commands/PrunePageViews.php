<?php

namespace App\Console\Commands;

use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PrunePageViews extends Command
{
    protected $signature = 'analytics:prune
                            {--days=365 : Delete page_view/engagement rows older than this many days}
                            {--keep-conversions=true : Whether to keep conversion rows forever}';

    protected $description = 'Delete old page_views rows to bound table growth.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $keep   = filter_var($this->option('keep-conversions'), FILTER_VALIDATE_BOOLEAN);
        $cutoff = Carbon::now()->subDays($days);

        $q = PageView::where('created_at', '<', $cutoff);
        if ($keep) {
            $q->where('event_type', '!=', 'conversion');
        }

        $count = $q->count();
        if ($count === 0) {
            $this->info("Nothing to prune (cutoff: {$cutoff->toDateTimeString()}).");
            return self::SUCCESS;
        }

        $this->info("Pruning {$count} page_views rows older than {$cutoff->toDateString()}".($keep ? ' (keeping conversions)' : '').'…');
        $deleted = 0;
        do {
            $batch = $q->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Done. Deleted {$deleted} rows.");
        return self::SUCCESS;
    }
}
