<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Location;
use App\Services\AccountingReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AccountingAnalyticsController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(private AccountingReportService $reportService)
    {
    }

    public function getReport(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'pending', 'all'])],
            'include_addons_breakdown' => 'nullable|boolean',
            'category_filter' => 'nullable|string', // Filter by specific package/attraction category
        ]);

        $locationId = $request->location_id;

        if ($scopeError = $this->guardLocationAccess($request, $locationId)) {
            return $scopeError;
        }

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->startOfDay()
            : $startDate->copy();
        $compareStartDate = $request->filled('compare_start_date')
            ? Carbon::parse($request->compare_start_date)->startOfDay()
            : null;
        $compareEndDate = $request->filled('compare_end_date')
            ? Carbon::parse($request->compare_end_date)->startOfDay()
            : $compareStartDate?->copy();

        $viewMode = $request->get('view_mode', 'booked_for');
        $paymentStatus = $request->get('payment_status', 'all');
        $includeAddonsBreakdown = $request->boolean('include_addons_breakdown', true);
        $categoryFilter = $request->get('category_filter');

        $location = Location::with('company')->findOrFail($locationId);

        $cacheKey = 'dashboards:accounting:' . $locationId . ':' . md5(json_encode([
            $request->start_date, $request->end_date, $request->compare_start_date, $request->compare_end_date,
            $viewMode, $paymentStatus, $includeAddonsBreakdown, $categoryFilter,
        ]));
        if (($cached = \App\Support\CacheGroups::get([\App\Support\CacheGroups::DASHBOARDS], $cacheKey)) !== null) {
            return response()->json(['success' => true, 'data' => $cached]);
        }

        try {
            $filters = [
                'payment_status' => $paymentStatus,
                'include_addons_breakdown' => $includeAddonsBreakdown,
                'category_filter' => $categoryFilter,
            ];

            $primaryData = $this->reportService->buildReportData($locationId, $startDate, $endDate, $viewMode, $filters);

            $comparisonData = null;
            if ($compareStartDate) {
                $comparisonData = $this->reportService->buildReportData($locationId, $compareStartDate, $compareEndDate, $viewMode, $filters);
            }

            $data = [
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'company_name' => $location->company?->name,
                    'timezone' => $location->timezone ?? 'UTC',
                ],
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'compare_start_date' => $compareStartDate?->toDateString(),
                'compare_end_date' => $compareEndDate?->toDateString(),
                'view_mode' => $viewMode,
                'view_mode_label' => $viewMode === 'booked_on' ? 'Created On' : 'Booked For',
                'filters_applied' => $filters,
                'primary' => $primaryData,
                'comparison' => $comparisonData,
                'generated_at' => now()->toIso8601String(),
            ];

            \App\Support\CacheGroups::put([\App\Support\CacheGroups::DASHBOARDS], $cacheKey, $data, \App\Support\CacheGroups::TTL_DASHBOARD);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Error generating accounting analytics report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'location_id' => $locationId,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate accounting report',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function getSummaryTrend(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
        ]);

        $locationId = $request->location_id;
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->startOfDay();
        $viewMode = $request->get('view_mode', 'booked_for');

        if ($scopeError = $this->guardLocationAccess($request, $locationId)) {
            return $scopeError;
        }

        $location = Location::with('company')->findOrFail($locationId);

        try {
            $dailyData = [];
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $dayReport = $this->reportService->buildReportData($locationId, $currentDate, $currentDate, $viewMode);

                $dailyData[] = [
                    'date' => $currentDate->toDateString(),
                    'day_of_week' => $currentDate->format('l'),
                    'summary' => $dayReport['summary'],
                ];

                $currentDate->addDay();
            }

            $rangeTotals = $this->reportService->initializeTotals();
            foreach ($dailyData as $day) {
                $summary = $day['summary'];
                $rangeTotals['quantity'] += $summary['quantity_sold'];
                $rangeTotals['gross_sales'] += $summary['gross_sales'];
                $rangeTotals['net_sales'] += $summary['net_sales'];
                $rangeTotals['fee_amount'] += $summary['fee_amount'];
                $rangeTotals['discount_amount'] += $summary['discount_amount'];
                $rangeTotals['tax_amount'] += $summary['tax_amount'];
                $rangeTotals['total_billed'] += $summary['total_billed'];
                $rangeTotals['grand_total'] += $summary['grand_total'];
                $rangeTotals['balance_due'] += $summary['balance_due'];
                $rangeTotals['collected_via_gateway'] += $summary['collected_via_gateway'];
                $rangeTotals['collected_via_gateway_net'] += $summary['collected_via_gateway_net'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'location' => [
                        'id' => $location->id,
                        'name' => $location->name,
                        'company_name' => $location->company?->name,
                    ],
                    'date_range' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'total_days' => $startDate->diffInDays($endDate) + 1,
                    ],
                    'view_mode' => $viewMode,
                    'daily_data' => $dailyData,
                    'range_totals' => $this->reportService->formatTotals($rangeTotals),
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating accounting summary trend', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate summary trend',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function exportReport(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
            'format' => ['required', Rule::in(['json', 'csv'])],
        ]);

        $locationId = $request->location_id;
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->startOfDay()
            : $startDate->copy();
        $viewMode = $request->get('view_mode', 'booked_for');
        $format = $request->format;

        if ($scopeError = $this->guardLocationAccess($request, $locationId)) {
            return $scopeError;
        }

        $location = Location::findOrFail($locationId);
        $reportData = $this->reportService->buildReportData($locationId, $startDate, $endDate, $viewMode);

        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'data' => [
                    'location' => $location->name,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'view_mode' => $viewMode,
                    'report' => $reportData,
                ],
            ]);
        }

        $dateLabel = $startDate->eq($endDate)
            ? $startDate->format('Y-m-d')
            : $startDate->format('Y-m-d') . '_to_' . $endDate->format('Y-m-d');
        $filename = 'accounting_report_' . $location->name . '_' . $dateLabel . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($reportData, $location, $startDate, $endDate, $viewMode) {
            $file = fopen('php://output', 'w');

            fputcsv($file, ['Accounting Report']);
            fputcsv($file, ['Location:', $location->name]);
            fputcsv($file, ['Start Date:', $startDate->toDateString()]);
            fputcsv($file, ['End Date:', $endDate->toDateString()]);
            fputcsv($file, ['View Mode:', $viewMode === 'booked_on' ? 'Created On' : 'Booked For']);
            fputcsv($file, ['Generated:', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            fputcsv($file, ['OVERALL SUMMARY']);
            fputcsv($file, ['Metric', 'Value']);
            fputcsv($file, ['Quantity Sold', $reportData['summary']['quantity_sold']]);
            fputcsv($file, ['Gross Sales', '$' . number_format($reportData['summary']['gross_sales'], 2)]);
            fputcsv($file, ['Discounts', '$' . number_format($reportData['summary']['discount_amount'], 2)]);
            fputcsv($file, ['Net Sales', '$' . number_format($reportData['summary']['net_sales'], 2)]);
            fputcsv($file, ['Fees', '$' . number_format($reportData['summary']['fee_amount'], 2)]);
            fputcsv($file, ['Tax', '$' . number_format($reportData['summary']['tax_amount'], 2)]);
            fputcsv($file, ['Amount Due', '$' . number_format($reportData['summary']['total_billed'], 2)]);
            fputcsv($file, ['Amount Collected', '$' . number_format($reportData['summary']['grand_total'], 2)]);
            fputcsv($file, ['Balance Due', '$' . number_format($reportData['summary']['balance_due'], 2)]);
            fputcsv($file, []);

            foreach ($reportData['categories'] as $category) {
                fputcsv($file, [strtoupper($category['name'])]);
                fputcsv($file, ['Item', 'Sub-Category', 'Qty', 'Gross Sales', 'Discounts', 'Net Sales', 'Fees', 'Tax', 'Amount Due', 'Collected', 'Balance Due']);

                foreach ($category['items'] as $item) {
                    fputcsv($file, [
                        $item['name'],
                        $item['sub_category'],
                        $item['quantity_sold'],
                        '$' . number_format($item['gross_sales'], 2),
                        '$' . number_format($item['discount_amount'], 2),
                        '$' . number_format($item['net_sales'], 2),
                        '$' . number_format($item['fee_amount'], 2),
                        '$' . number_format($item['tax_amount'], 2),
                        '$' . number_format($item['total_billed'], 2),
                        '$' . number_format($item['grand_total'], 2),
                        '$' . number_format($item['balance_due'], 2),
                    ]);
                }

                fputcsv($file, [
                    'SUBTOTAL',
                    '',
                    $category['summary']['quantity_sold'],
                    '$' . number_format($category['summary']['gross_sales'], 2),
                    '$' . number_format($category['summary']['discount_amount'], 2),
                    '$' . number_format($category['summary']['net_sales'], 2),
                    '$' . number_format($category['summary']['fee_amount'], 2),
                    '$' . number_format($category['summary']['tax_amount'], 2),
                    '$' . number_format($category['summary']['total_billed'], 2),
                    '$' . number_format($category['summary']['grand_total'], 2),
                    '$' . number_format($category['summary']['balance_due'], 2),
                ]);

                fputcsv($file, []);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
