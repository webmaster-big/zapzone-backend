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

    /**
     * Resolve which locations a report covers. A blank or "all" location_id means every
     * location the caller may see, so the sidebar's All Locations gives company-wide
     * figures instead of an empty page. Returns the scope, or a JsonResponse to return.
     */
    private function resolveReportScope(Request $request)
    {
        $requested = $request->get('location_id');
        $authUser = $this->resolveAuthUser($request);

        if ($requested !== null && $requested !== '' && $requested !== 'all') {
            if ($denied = $this->guardLocationAccess($request, $requested)) {
                return $denied;
            }

            $location = Location::with('company')->find((int) $requested);

            if (!$location) {
                return response()->json(['success' => false, 'message' => 'Location not found'], 404);
            }

            if ($authUser && $authUser->company_id && (int) $location->company_id !== (int) $authUser->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: cannot access another company\'s data',
                ], 403);
            }

            return [
                'ids' => [$location->id],
                'id' => $location->id,
                'name' => $location->name,
                'company_name' => $location->company?->name,
                'timezone' => $location->timezone ?? 'UTC',
            ];
        }

        $query = Location::with('company');

        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $query->where('id', $authUser->location_id);
        } elseif ($authUser && $authUser->company_id) {
            $query->where('company_id', $authUser->company_id);
        }

        $locations = $query->get();

        if ($locations->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No locations available'], 404);
        }

        $single = $locations->count() === 1 ? $locations->first() : null;

        return [
            'ids' => $locations->pluck('id')->all(),
            'id' => $single?->id,
            'name' => $single?->name ?? 'All Locations',
            'company_name' => $locations->first()->company?->name,
            'timezone' => $locations->first()->timezone ?? 'UTC',
        ];
    }

    public function getReport(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'nullable',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'pending', 'all'])],
            'include_addons_breakdown' => 'nullable|boolean',
            'category_filter' => 'nullable|string', // Filter by specific package/attraction category
        ]);

        $scope = $this->resolveReportScope($request);

        if ($scope instanceof JsonResponse) {
            return $scope;
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

        $cacheKey = 'dashboards:accounting:' . implode('-', $scope['ids']) . ':' . md5(json_encode([
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

            $primaryData = $this->reportService->buildReportData($scope['ids'], $startDate, $endDate, $viewMode, $filters);

            $comparisonData = null;
            if ($compareStartDate) {
                $comparisonData = $this->reportService->buildReportData($scope['ids'], $compareStartDate, $compareEndDate, $viewMode, $filters);
            }

            $data = [
                'location' => [
                    'id' => $scope['id'],
                    'name' => $scope['name'],
                    'company_name' => $scope['company_name'],
                    'timezone' => $scope['timezone'],
                    'location_count' => count($scope['ids']),
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
                'location_ids' => $scope['ids'],
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
            'location_id' => 'nullable',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
        ]);

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = Carbon::parse($request->end_date)->startOfDay();
        $viewMode = $request->get('view_mode', 'booked_for');

        $scope = $this->resolveReportScope($request);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        try {
            $dailyData = [];
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $dayReport = $this->reportService->buildReportData($scope['ids'], $currentDate, $currentDate, $viewMode);

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
                        'id' => $scope['id'],
                        'name' => $scope['name'],
                        'company_name' => $scope['company_name'],
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
            'location_id' => 'nullable',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
            'format' => ['required', Rule::in(['json', 'csv'])],
        ]);

        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->startOfDay()
            : $startDate->copy();
        $viewMode = $request->get('view_mode', 'booked_for');
        $format = $request->format;

        $scope = $this->resolveReportScope($request);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $reportData = $this->reportService->buildReportData($scope['ids'], $startDate, $endDate, $viewMode);

        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'data' => [
                    'location' => $scope['name'],
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
        $filename = 'accounting_report_' . str_replace(['/', '\\', ' '], '_', $scope['name']) . '_' . $dateLabel . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($reportData, $scope, $startDate, $endDate, $viewMode) {
            $file = fopen('php://output', 'w');

            fputcsv($file, ['Accounting Report']);
            fputcsv($file, ['Location:', $scope['name']]);
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

                $byLabel = [];

                foreach ($category['items'] as $item) {
                    $byLabel[$item['sub_category'] ?: 'Uncategorized'][] = $item;
                }

                ksort($byLabel);

                foreach ($byLabel as $label => $labelItems) {
                    foreach ($labelItems as $item) {
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

                    if (count($byLabel) > 1) {
                        $sum = fn (string $key) => array_sum(array_column($labelItems, $key));

                        fputcsv($file, [
                            $label . ' TOTAL',
                            '',
                            $sum('quantity_sold'),
                            '$' . number_format($sum('gross_sales'), 2),
                            '$' . number_format($sum('discount_amount'), 2),
                            '$' . number_format($sum('net_sales'), 2),
                            '$' . number_format($sum('fee_amount'), 2),
                            '$' . number_format($sum('tax_amount'), 2),
                            '$' . number_format($sum('total_billed'), 2),
                            '$' . number_format($sum('grand_total'), 2),
                            '$' . number_format($sum('balance_due'), 2),
                        ]);
                    }
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
