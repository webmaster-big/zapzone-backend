<?php

namespace App\Console\Commands;

use App\Models\EmailNotification;
use App\Services\AccountingReportService;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailySalesReport extends Command
{
    protected $signature = 'reports:send-daily-sales {--date= : Business day to report on (Y-m-d, Michigan time). Defaults to today.}';

    protected $description = 'Email the End of Day Sales Report summarizing the day\'s sales across all locations (Michigan time)';

    public function handle(AccountingReportService $reportService, EmailNotificationService $emailService): int
    {
        $tz = config('app.timezone', 'America/Detroit');
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)->startOfDay()
            : Carbon::today($tz);
        $dateString = $date->toDateString();

        $this->info("Building End of Day Sales Report for {$dateString} ({$tz})...");

        $notification = EmailNotification::where('default_key', EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$notification) {
            $this->warn('No active End of Day Sales Report template found. Nothing sent.');
            Log::info('Daily sales report skipped: no active template', ['date' => $dateString]);
            return Command::SUCCESS;
        }

        $activeCount = EmailNotification::where('default_key', EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT)
            ->where('is_active', true)
            ->count();
        if ($activeCount > 1) {
            Log::warning('Multiple active End of Day Sales Report templates; using the lowest id', [
                'used_notification_id' => $notification->id,
                'active_count' => $activeCount,
            ]);
        }

        $companyName = $notification->company?->company_name ?? config('mail.from.name', 'Zap Zone');
        $variables = $reportService->buildDailyReportVariables($date, $companyName);

        $emailService->sendDailySalesReport($notification, $variables);

        $this->info(sprintf(
            'End of Day Sales Report sent for %s: %s collected across %s location(s).',
            $dateString,
            $variables['total_collected'],
            $variables['total_locations']
        ));

        Log::info('Daily sales report command completed', [
            'date' => $dateString,
            'notification_id' => $notification->id,
            'included_locations' => $variables['total_locations'],
            'total_collected' => $variables['total_collected'],
        ]);

        return Command::SUCCESS;
    }
}
