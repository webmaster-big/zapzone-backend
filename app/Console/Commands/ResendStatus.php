<?php

namespace App\Console\Commands;

use App\Services\GmailApiService;
use App\Services\ResendService;
use Illuminate\Console\Command;

class ResendStatus extends Command
{
    protected $signature = 'resend:status {--days=7 : How many days of counts to show}';

    protected $description = 'Show whether email is going out through Resend, and how many emails Resend sent, refused or handed to Gmail each day';

    public function handle(): int
    {
        $describe = fn (string $key) => $key === '' ? 'not set' : strlen($key) . ' characters, starting ' . substr($key, 0, 3) . ', ending ' . substr($key, -4);

        $this->line('Resend:        ' . (ResendService::isConfigured() ? 'ON, every email goes out through Resend' : 'OFF, email goes out through ' . GmailApiService::transport()));
        $this->line('API key:       ' . $describe(ResendService::apiKey()));
        $this->line('Bulk API key:  ' . (filled(config('services.resend.marketing_key')) ? $describe(ResendService::apiKey(false)) . ' (RESEND_MARKETING_KEY)' : 'same key'));
        $this->line('From:          ' . ResendService::fromAddress());
        $this->line('Bulk From:     ' . ResendService::fromAddress(false));
        $this->line('Bulk pace:     ' . ((float) config('services.resend.bulk_per_second', 4) > 0 ? config('services.resend.bulk_per_second', 4) . ' emails a second at most' : 'no limit'));
        $this->line('Gmail backup:  ' . (GmailApiService::gmailConfigured() ? 'set up: receipts go through Gmail if Resend fails' : 'not set up: nothing falls back if Resend fails'));

        foreach (ResendService::campaignLimits() as $window) {
            $this->line(sprintf('%-15s%s', 'Campaigns ' . $window['period'] . ': ', $window['limit'] > 0
                ? ResendService::campaignUsed($window) . " of {$window['limit']} sent ({$window['env']})"
                : "no warm-up limit ({$window['env']} not set)"));
        }

        if (($reason = ResendService::unavailableReason()) !== null) {
            $this->warn("Right now:     Resend is being skipped for up to a minute because {$reason}.");
        }

        $rows = [];
        $fellBack = 0;

        foreach (ResendService::stats(max(1, min(8, (int) $this->option('days')))) as $date => $counts) {
            $rows[] = [$date, $counts['sent'], $counts['failed'], $counts['gmail']];
            $fellBack += $counts['gmail'];
        }

        $this->table(['Day', 'Sent by Resend', 'Refused by Resend', 'Sent by Gmail instead'], $rows);

        foreach (['failed' => 'Last refusal', 'gmail' => 'Last Gmail send'] as $outcome => $label) {
            if ($last = ResendService::lastOutcome($outcome)) {
                $this->line("{$label}: {$last['at']}: {$last['detail']}");
            }
        }

        if ($fellBack > 0) {
            $this->warn('Some emails went out through Gmail instead of Resend. The last refusal above says why.');
        }

        return self::SUCCESS;
    }
}
