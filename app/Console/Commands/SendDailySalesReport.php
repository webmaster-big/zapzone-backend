<?php

namespace App\Console\Commands;

use App\Models\EmailNotification;
use App\Models\Location;
use App\Services\AccountingReportService;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailySalesReport extends Command
{
    protected $signature = 'reports:send-daily-sales {--date= : Business day to report on (Y-m-d, Michigan time). Defaults to today.}';

    protected $description = 'Email the End of Day Sales Report summarizing the day\'s sales across all locations (Michigan time)';

    private const SUMMARY_KEYS = [
        'quantity_sold', 'gross_sales', 'net_sales', 'fee_amount', 'discount_amount',
        'tax_amount', 'total_billed', 'grand_total', 'balance_due',
        'collected_via_gateway', 'collected_via_gateway_net',
    ];

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

        $grand = array_fill_keys(self::SUMMARY_KEYS, 0);
        $categoryTotals = [
            AccountingReportService::CATEGORY_PARTIES => ['gross_sales' => 0, 'net_sales' => 0, 'grand_total' => 0],
            AccountingReportService::CATEGORY_ATTRACTIONS => ['gross_sales' => 0, 'net_sales' => 0, 'grand_total' => 0],
            AccountingReportService::CATEGORY_EVENTS => ['gross_sales' => 0, 'net_sales' => 0, 'grand_total' => 0],
        ];
        $locationRows = '';
        $includedLocations = 0;

        $locations = Location::where('is_active', true)->orderBy('name')->get();

        foreach ($locations as $location) {
            $report = $reportService->buildReportData($location->id, $date, $date, 'booked_on');
            $summary = $report['summary'];

            foreach (self::SUMMARY_KEYS as $key) {
                $grand[$key] += $summary[$key] ?? 0;
            }

            foreach ($report['categories'] as $category) {
                $name = $category['name'] ?? '';
                if (!isset($categoryTotals[$name])) {
                    continue;
                }
                $categoryTotals[$name]['gross_sales'] += $category['summary']['gross_sales'] ?? 0;
                $categoryTotals[$name]['net_sales'] += $category['summary']['net_sales'] ?? 0;
                $categoryTotals[$name]['grand_total'] += $category['summary']['grand_total'] ?? 0;
            }

            $hasActivity = ($summary['quantity_sold'] ?? 0) > 0
                || ($summary['gross_sales'] ?? 0) != 0
                || ($summary['grand_total'] ?? 0) != 0;

            if ($hasActivity) {
                $includedLocations++;
                $locationRows .= $this->row([
                    [$location->name, 'left'],
                    [$this->money($summary['net_sales']), 'right'],
                    [$this->money($summary['grand_total']), 'right'],
                    [number_format($summary['quantity_sold']), 'right'],
                ]);
            }
        }

        $categoryRows = '';
        foreach ($categoryTotals as $name => $totals) {
            $categoryRows .= $this->row([
                [$name, 'left'],
                [$this->money($totals['gross_sales']), 'right'],
                [$this->money($totals['net_sales']), 'right'],
                [$this->money($totals['grand_total']), 'right'],
            ]);
        }

        $collectedCash = max(0, $grand['grand_total'] - $grand['collected_via_gateway']);

        $variables = [
            'report_date' => $date->format('l, F j, Y'),
            'report_scope' => $includedLocations === 1 ? '1 location' : 'All Locations (' . $includedLocations . ')',
            'generated_at' => Carbon::now($tz)->format('F j, Y g:i A'),
            'total_locations' => (string) $includedLocations,
            'items_sold' => number_format($grand['quantity_sold']),
            'gross_sales' => $this->money($grand['gross_sales']),
            'discount_total' => $this->money($grand['discount_amount']),
            'net_sales' => $this->money($grand['net_sales']),
            'tax_total' => $this->money($grand['tax_amount']),
            'fee_total' => $this->money($grand['fee_amount']),
            'total_billed' => $this->money($grand['total_billed']),
            'total_collected' => $this->money($grand['grand_total']),
            'balance_due' => $this->money($grand['balance_due']),
            'collected_card' => $this->money($grand['collected_via_gateway']),
            'collected_cash' => $this->money($collectedCash),
            'location_breakdown_rows' => $locationRows !== '' ? $locationRows : $this->emptyRow(),
            'category_breakdown_rows' => $categoryRows,
            'company_name' => $notification->company?->company_name ?? config('mail.from.name', 'Zap Zone'),
            'current_year' => (string) Carbon::now($tz)->year,
            'current_date' => Carbon::now($tz)->format('F j, Y'),
        ];

        $emailService->sendDailySalesReport($notification, $variables);

        $this->info(sprintf(
            'End of Day Sales Report sent for %s: %s collected across %d location(s).',
            $dateString,
            $variables['total_collected'],
            $includedLocations
        ));

        Log::info('Daily sales report command completed', [
            'date' => $dateString,
            'notification_id' => $notification->id,
            'included_locations' => $includedLocations,
            'total_collected' => $grand['grand_total'],
        ]);

        return Command::SUCCESS;
    }

    private function money($value): string
    {
        return '$' . number_format((float) $value, 2);
    }

    private function row(array $cells): string
    {
        $html = '<tr>';
        foreach ($cells as [$value, $align]) {
            $html .= '<td style="padding: 10px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px; color: #111827; text-align: ' . $align . ';">' . $value . '</td>';
        }
        return $html . '</tr>';
    }

    private function emptyRow(): string
    {
        return '<tr><td colspan="4" style="padding: 14px 16px; text-align: center; color: #9ca3af; font-size: 13px;">No sales recorded for this day.</td></tr>';
    }
}
