<?php

namespace App\Console\Commands;

use App\Services\VisitFollowUpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendVisitFollowUps extends Command
{
    protected $signature = 'visits:send-follow-ups {--limit=200}';

    protected $description = 'Send the review-request emails that are due after completed visits, and retry Thanks for Playing emails that failed';

    public function handle(VisitFollowUpService $followUps): int
    {
        if (!$followUps->isAvailable()) {
            $this->warn('The visit_follow_ups table does not exist yet. Run the migrations first.');

            return Command::SUCCESS;
        }

        $counts = $followUps->sendDue(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Visit follow-ups: %d sent, %d failed, %d skipped, %d held until morning, %d interrupted sends released.',
            $counts['sent'],
            $counts['failed'],
            $counts['skipped'],
            $counts['held'],
            $counts['released']
        ));

        Log::info('Visit follow-ups processed', $counts);

        return Command::SUCCESS;
    }
}
