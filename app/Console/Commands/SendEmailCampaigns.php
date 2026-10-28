<?php

namespace App\Console\Commands;

use App\Services\EmailCampaignSender;
use Illuminate\Console\Command;

class SendEmailCampaigns extends Command
{
    protected $signature = 'email-campaigns:send {--seconds=240 : Stop starting new emails after this many seconds}';

    protected $description = 'Send the campaign emails that are waiting to go out, a few minutes at a time';

    public function handle(EmailCampaignSender $sender): int
    {
        $sent = $sender->sendWaiting(microtime(true) + max(1, (int) $this->option('seconds')));

        $this->info("{$sent} campaign email(s) handled.");

        return self::SUCCESS;
    }
}
