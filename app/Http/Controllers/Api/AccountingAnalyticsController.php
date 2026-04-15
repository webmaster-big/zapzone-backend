<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AccountingAnalyticsController extends Controller
{
    /**
     * Category constants for grouping
     */
    const CATEGORY_PARTIES = 'Parties';
    const CATEGORY_ATTRACTIONS = 'Attractions';
    const CATEGORY_EVENTS = 'Events';
    const CATEGORY_ADDONS = 'Add-ons';

    /**
     * Tax-related keywords for fee identification (case-insensitive)
     */
    const TAX_KEYWORDS = ['tax', 'vat', 'gst', 'hst', 'pst', 'sales tax', 'state tax', 'local tax'];

    /**
     * Get comprehensive accounting analytics report.
     *
     * This report provides detailed, categorized sales data for accounting purposes.
     * It supports filtering by date and toggling between "Booked On" (created_at) vs "Booked For" (event date).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getReport(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
            'view_mode' => ['nullable', Rule::in(['booked_on', 'booked_for'])],
            // Note: payment_status only exists on Bookings and EventPurchases, not AttractionPurchases
            'payment_status' => ['nullable', Rule::in(['paid', 'partial', 'pending', 'all'])],
            'include_addons_breakdown' => 'nullable|boolean',
            'category_filter' => 'nullable|string', // Filter by specific package/attraction category
        ]);

        $locationId = $request->location_id;
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

        // Default to 'booked_for' (shows events scheduled for this date)
        $viewMode = $request->get('view_mode', 'booked_for');
        $paymentStatus = $request->get('payment_status', 'all');
        $includeAddonsBreakdown = $request->boolean('include_addons_breakdown', true);
        $categoryFilter = $request->get('category_filter');

        // Get location details
        $location = Location::with('company')->findOrFail($locationId);

        try {
            // Build filter options
            $filters = [
                'payment_status' => $paymentStatus,
                'include_addons_breakdown' => $includeAddonsBreakdown,
                'category_filter' => $categoryFilter,
            ];

            // Get primary date range data
            $primaryData = $this->buildReportData($locationId, $startDate, $endDate, $viewMode, $filters);

            // Get comparison data if requested
            $comparisonData = null;
            if ($compareStartDate) {
                $comparisonData = $this->buildReportData($locationId, $compareStartDate, $compareEndDate, $viewMode, $filters);
            }

            return response()->json([
                'success' => true,
                'data' => [
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
                ],
            ]);
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

    /**
     * Build comprehensive report data for a date range.
     *
     * @param int $locationId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $viewMode 'booked_on' or 'booked_for'
     * @param array $filters Additional filter options
     * @return array
     */
    private function buildReportData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        // Get categorized data using memory-efficient queries
        $partiesData = $this->getPartiesData($locationId, $startDate, $endDate, $viewMode, $filters);
        $attractionsData = $this->getAttractionsData($locationId, $startDate, $endDate, $viewMode, $filters);
        $eventsData = $this->getEventsData($locationId, $startDate, $endDate, $viewMode, $filters);

        // Build categories array
        $categories = [
            $partiesData,
            $attractionsData,
            $eventsData,
        ];

        // Add-ons breakdown is separate (they're already counted in parent totals)
        // This provides visibility into what add-ons were sold
        if ($filters['include_addons_breakdown'] ?? true) {
            $addOnsData = $this->getAddOnsData($locationId, $startDate, $endDate, $viewMode, $filters);
            $categories[] = $addOnsData;
        }

        // Calculate overall summary (excluding add-ons to avoid double-counting)
        // Add-ons revenue is already included in Parties/Attractions/Events totals
        $summaryCategories = array_filter($categories, fn($cat) => $cat['name'] !== self::CATEGORY_ADDONS);
        $summary = $this->calculateOverallSummary($summaryCategories);

        return [
            'summary' => $summary,
            'categories' => $categories,
            'note' => 'Add-ons are shown for visibility but their revenue is already included in Parties/Attractions/Events totals.',
        ];
    }

    /**
     * Get parties (package bookings) data grouped by package category.
     * Uses DB::table() for lightweight queries and payments table for accurate gateway tracking.
     *
     * @param int $locationId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $viewMode
     * @param array $filters
     * @return array
     */
    private function getPartiesData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('bookings')
            ->join('packages', 'bookings.package_id', '=', 'packages.id')
            ->where('bookings.location_id', $locationId)
            ->whereNotIn('bookings.status', ['cancelled'])
            ->whereNull('bookings.deleted_at');

        // Apply date filter based on view mode
        if ($viewMode === 'booked_on') {
            $query->whereDate('bookings.created_at', '>=', $startDate->toDateString())
                  ->whereDate('bookings.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereDate('bookings.booking_date', '>=', $startDate->toDateString())
                  ->whereDate('bookings.booking_date', '<=', $endDate->toDateString());
        }

        // Apply payment status filter
        if (isset($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $query->where('bookings.payment_status', $filters['payment_status']);
        }

        // Apply category filter if specified
        if (!empty($filters['category_filter'])) {
            $query->where('packages.category', $filters['category_filter']);
        }

        // Select only needed columns (lightweight stdClass, no Eloquent overhead)
        $bookings = $query->select(
            'bookings.id',
            'packages.category as sub_category',
            'packages.name as package_name',
            'bookings.total_amount',
            'bookings.amount_paid',
            'bookings.discount_amount',
            'bookings.applied_fees',
            'bookings.payment_method'
        )->get();

        // Get accurate gateway amounts from the payments table
        $bookingIds = $bookings->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($bookingIds, Payment::TYPE_BOOKING);

        // Group and calculate in a single pass
        $grouped = [];
        foreach ($bookings as $booking) {
            $key = ($booking->sub_category ?? 'Uncategorized') . '|||' . ($booking->package_name ?? 'Unknown Package');

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'name' => $booking->package_name ?? 'Unknown Package',
                    'sub_category' => $booking->sub_category ?? 'Uncategorized',
                    'totals' => $this->initializeTotals(),
                ];
            }

            $totals = &$grouped[$key]['totals'];
            // In-store payments should not count as gateway collected
            $gatewayAmount = ($booking->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$booking->id] ?? 0);
            $this->accumulateRecordTotals($totals, $booking, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_PARTIES, $grouped);
    }

    /**
     * Get attractions (ticket purchases) data grouped by attraction category.
     * Uses DB::table() for lightweight queries and payments table for accurate gateway tracking.
     *
     * @param int $locationId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $viewMode
     * @param array $filters
     * @return array
     */
    private function getAttractionsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('attraction_purchases')
            ->join('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
            ->where('attractions.location_id', $locationId)
            ->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('attraction_purchases.deleted_at');

        // Apply date filter based on view mode
        if ($viewMode === 'booked_on') {
            $query->whereDate('attraction_purchases.created_at', '>=', $startDate->toDateString())
                  ->whereDate('attraction_purchases.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereRaw(
                'DATE(COALESCE(attraction_purchases.scheduled_date, attraction_purchases.purchase_date)) BETWEEN ? AND ?',
                [$startDate->toDateString(), $endDate->toDateString()]
            );
        }

        // Apply category filter if specified
        if (!empty($filters['category_filter'])) {
            $query->where('attractions.category', $filters['category_filter']);
        }

        $purchases = $query->select(
            'attraction_purchases.id',
            'attractions.category as sub_category',
            'attractions.name as attraction_name',
            'attraction_purchases.quantity',
            'attraction_purchases.total_amount',
            'attraction_purchases.amount_paid',
            'attraction_purchases.discount_amount',
            'attraction_purchases.applied_fees',
            'attraction_purchases.payment_method'
        )->get();

        // Get accurate gateway amounts from the payments table
        $purchaseIds = $purchases->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($purchaseIds, Payment::TYPE_ATTRACTION_PURCHASE);

        // Group and calculate in a single pass
        $grouped = [];
        foreach ($purchases as $purchase) {
            $key = ($purchase->sub_category ?? 'Uncategorized') . '|||' . ($purchase->attraction_name ?? 'Unknown Attraction');

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'name' => $purchase->attraction_name ?? 'Unknown Attraction',
                    'sub_category' => $purchase->sub_category ?? 'Uncategorized',
                    'totals' => $this->initializeTotals(),
                ];
            }

            $totals = &$grouped[$key]['totals'];
            // In-store payments should not count as gateway collected
            $gatewayAmount = ($purchase->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$purchase->id] ?? 0);
            $this->accumulateRecordTotals($totals, $purchase, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_ATTRACTIONS, $grouped);
    }

    /**
     * Get events data grouped by event name.
     * Uses DB::table() for lightweight queries and payments table for accurate gateway tracking.
     *
     * @param int $locationId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $viewMode
     * @param array $filters
     * @return array
     */
    private function getEventsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('event_purchases')
            ->join('events', 'event_purchases.event_id', '=', 'events.id')
            ->where('event_purchases.location_id', $locationId)
            ->whereNotIn('event_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('event_purchases.deleted_at');

        // Apply date filter based on view mode
        if ($viewMode === 'booked_on') {
            $query->whereDate('event_purchases.created_at', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereDate('event_purchases.purchase_date', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.purchase_date', '<=', $endDate->toDateString());
        }

        // Apply payment status filter (EventPurchase has payment_status field)
        if (isset($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $query->where('event_purchases.payment_status', $filters['payment_status']);
        }

        $purchases = $query->select(
            'event_purchases.id',
            'events.name as event_name',
            'event_purchases.quantity',
            'event_purchases.total_amount',
            'event_purchases.amount_paid',
            'event_purchases.discount_amount',
            'event_purchases.applied_fees',
            'event_purchases.payment_method'
        )->get();

        // Get accurate gateway amounts from the payments table
        $purchaseIds = $purchases->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($purchaseIds, Payment::TYPE_EVENT_PURCHASE);

        // Group and calculate in a single pass
        $grouped = [];
        foreach ($purchases as $purchase) {
            $eventName = $purchase->event_name ?? 'Unknown Event';

            if (!isset($grouped[$eventName])) {
                $grouped[$eventName] = [
                    'name' => $eventName,
                    'sub_category' => 'Events',
                    'totals' => $this->initializeTotals(),
                ];
            }

            $totals = &$grouped[$eventName]['totals'];
            // In-store payments should not count as gateway collected
            $gatewayAmount = ($purchase->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$purchase->id] ?? 0);
            $this->accumulateRecordTotals($totals, $purchase, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_EVENTS, $grouped);
    }

    /**
     * Get add-ons data aggregated from all purchase types.
     * NOTE: Add-on revenue is already included in parent booking/purchase totals.
     * This section provides VISIBILITY into add-on sales, not additional revenue counting.
     *
     * @param int $locationId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string $viewMode
     * @param array $filters
     * @return array
     */
    private function getAddOnsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $addOnsSummary = [];

        // Collect add-ons from bookings using efficient join query
        // collected_revenue: only sum revenue from bookings that are fully paid (amount_paid >= total_amount)
        $bookingAddOns = DB::table('booking_add_ons')
            ->join('bookings', 'booking_add_ons.booking_id', '=', 'bookings.id')
            ->join('add_ons', 'booking_add_ons.add_on_id', '=', 'add_ons.id')
            ->where('bookings.location_id', $locationId)
            ->whereNotIn('bookings.status', ['cancelled'])
            ->whereNull('bookings.deleted_at')
            ->when($viewMode === 'booked_on', function ($q) use ($startDate, $endDate) {
                $q->whereDate('bookings.created_at', '>=', $startDate->toDateString())
                  ->whereDate('bookings.created_at', '<=', $endDate->toDateString());
            }, function ($q) use ($startDate, $endDate) {
                $q->whereDate('bookings.booking_date', '>=', $startDate->toDateString())
                  ->whereDate('bookings.booking_date', '<=', $endDate->toDateString());
            })
            ->select(
                'add_ons.id',
                'add_ons.name',
                DB::raw('SUM(booking_add_ons.quantity) as total_quantity'),
                DB::raw('SUM(booking_add_ons.quantity * booking_add_ons.price_at_booking) as total_revenue'),
                DB::raw('SUM(CASE WHEN bookings.amount_paid >= bookings.total_amount THEN booking_add_ons.quantity * booking_add_ons.price_at_booking ELSE 0 END) as collected_revenue')
            )
            ->groupBy('add_ons.id', 'add_ons.name')
            ->get();

        foreach ($bookingAddOns as $addOn) {
            if (!isset($addOnsSummary[$addOn->id])) {
                $addOnsSummary[$addOn->id] = [
                    'name' => $addOn->name,
                    'quantity' => 0,
                    'gross_sales' => 0,
                    'collected' => 0,
                ];
            }
            $addOnsSummary[$addOn->id]['quantity'] += (int) $addOn->total_quantity;
            $addOnsSummary[$addOn->id]['gross_sales'] += (float) $addOn->total_revenue;
            $addOnsSummary[$addOn->id]['collected'] += (float) $addOn->collected_revenue;
        }

        // Collect add-ons from attraction purchases
        // collected_revenue: only sum revenue from purchases that are fully paid
        $attractionAddOnsQuery = DB::table('attraction_purchase_add_ons')
            ->join('attraction_purchases', 'attraction_purchase_add_ons.attraction_purchase_id', '=', 'attraction_purchases.id')
            ->join('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
            ->join('add_ons', 'attraction_purchase_add_ons.add_on_id', '=', 'add_ons.id')
            ->where('attractions.location_id', $locationId)
            ->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('attraction_purchases.deleted_at');

        if ($viewMode === 'booked_on') {
            $attractionAddOnsQuery->whereDate('attraction_purchases.created_at', '>=', $startDate->toDateString())
                ->whereDate('attraction_purchases.created_at', '<=', $endDate->toDateString());
        } else {
            $attractionAddOnsQuery->whereRaw(
                'DATE(COALESCE(attraction_purchases.scheduled_date, attraction_purchases.purchase_date)) BETWEEN ? AND ?',
                [$startDate->toDateString(), $endDate->toDateString()]
            );
        }

        $attractionAddOns = $attractionAddOnsQuery
            ->select(
                'add_ons.id',
                'add_ons.name',
                DB::raw('SUM(attraction_purchase_add_ons.quantity) as total_quantity'),
                DB::raw('SUM(attraction_purchase_add_ons.quantity * attraction_purchase_add_ons.price_at_purchase) as total_revenue'),
                DB::raw('SUM(CASE WHEN attraction_purchases.amount_paid >= attraction_purchases.total_amount THEN attraction_purchase_add_ons.quantity * attraction_purchase_add_ons.price_at_purchase ELSE 0 END) as collected_revenue')
            )
            ->groupBy('add_ons.id', 'add_ons.name')
            ->get();

        foreach ($attractionAddOns as $addOn) {
            if (!isset($addOnsSummary[$addOn->id])) {
                $addOnsSummary[$addOn->id] = [
                    'name' => $addOn->name,
                    'quantity' => 0,
                    'gross_sales' => 0,
                    'collected' => 0,
                ];
            }
            $addOnsSummary[$addOn->id]['quantity'] += (int) $addOn->total_quantity;
            $addOnsSummary[$addOn->id]['gross_sales'] += (float) $addOn->total_revenue;
            $addOnsSummary[$addOn->id]['collected'] += (float) $addOn->collected_revenue;
        }

        // Collect add-ons from event purchases
        // collected_revenue: only sum revenue from purchases that are fully paid
        $eventAddOns = DB::table('event_purchase_add_ons')
            ->join('event_purchases', 'event_purchase_add_ons.event_purchase_id', '=', 'event_purchases.id')
            ->join('add_ons', 'event_purchase_add_ons.add_on_id', '=', 'add_ons.id')
            ->where('event_purchases.location_id', $locationId)
            ->whereNotIn('event_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('event_purchases.deleted_at')
            ->when($viewMode === 'booked_on', function ($q) use ($startDate, $endDate) {
                $q->whereDate('event_purchases.created_at', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.created_at', '<=', $endDate->toDateString());
            }, function ($q) use ($startDate, $endDate) {
                $q->whereDate('event_purchases.purchase_date', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.purchase_date', '<=', $endDate->toDateString());
            })
            ->select(
                'add_ons.id',
                'add_ons.name',
                DB::raw('SUM(event_purchase_add_ons.quantity) as total_quantity'),
                DB::raw('SUM(event_purchase_add_ons.quantity * event_purchase_add_ons.price_at_purchase) as total_revenue'),
                DB::raw('SUM(CASE WHEN event_purchases.amount_paid >= event_purchases.total_amount THEN event_purchase_add_ons.quantity * event_purchase_add_ons.price_at_purchase ELSE 0 END) as collected_revenue')
            )
            ->groupBy('add_ons.id', 'add_ons.name')
            ->get();

        foreach ($eventAddOns as $addOn) {
            if (!isset($addOnsSummary[$addOn->id])) {
                $addOnsSummary[$addOn->id] = [
                    'name' => $addOn->name,
                    'quantity' => 0,
                    'gross_sales' => 0,
                    'collected' => 0,
                ];
            }
            $addOnsSummary[$addOn->id]['quantity'] += (int) $addOn->total_quantity;
            $addOnsSummary[$addOn->id]['gross_sales'] += (float) $addOn->total_revenue;
            $addOnsSummary[$addOn->id]['collected'] += (float) $addOn->collected_revenue;
        }

        // Format items
        $items = [];
        $categoryTotals = [
            'quantity' => 0,
            'gross_sales' => 0,
            'collected' => 0,
        ];

        foreach ($addOnsSummary as $addOnData) {
            $collected = $addOnData['collected'];
            $grossSales = $addOnData['gross_sales'];

            $items[] = [
                'name' => $addOnData['name'],
                'sub_category' => 'Add-ons',
                'quantity_sold' => $addOnData['quantity'],
                'gross_sales' => round($grossSales, 2),
                'net_sales' => round($grossSales, 2),
                'fee_amount' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_billed' => round($grossSales, 2),
                'grand_total' => round($collected, 2),
                'balance_due' => round($grossSales - $collected, 2),
                'collected_via_gateway' => 0,
                'collected_via_gateway_net' => 0,
            ];

            $categoryTotals['quantity'] += $addOnData['quantity'];
            $categoryTotals['gross_sales'] += $grossSales;
            $categoryTotals['collected'] += $collected;
        }

        usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'name' => self::CATEGORY_ADDONS,
            'is_informational' => true, // Flag to indicate this is for visibility, not counted in totals
            'items' => $items,
            'summary' => [
                'quantity_sold' => $categoryTotals['quantity'],
                'gross_sales' => round($categoryTotals['gross_sales'], 2),
                'net_sales' => round($categoryTotals['gross_sales'], 2),
                'fee_amount' => 0,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_billed' => round($categoryTotals['gross_sales'], 2),
                'grand_total' => round($categoryTotals['collected'], 2),
                'balance_due' => round($categoryTotals['gross_sales'] - $categoryTotals['collected'], 2),
                'collected_via_gateway' => 0,
                'collected_via_gateway_net' => 0,
            ],
        ];
    }

    /**
     * Initialize totals array.
     *
     * @return array
     */
    private function initializeTotals(): array
    {
        return [
            'quantity' => 0,
            'gross_sales' => 0,
            'net_sales' => 0,
            'fee_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_billed' => 0,
            'grand_total' => 0,
            'balance_due' => 0,
            'collected_via_gateway' => 0,
            'collected_via_gateway_net' => 0,
        ];
    }

    /**
     * Accumulate totals from a single record (booking or purchase) into group totals.
     * Uses the payments table gateway amount for accurate gateway tracking.
     *
     * ACCOUNTING DEFINITIONS:
     * - Gross Sales = total_amount + discount_amount (original price before discount)
     * - Net Sales = total_amount (price after discount but before separating fees)
     * - Fee Amount = sum of all applied_fees, excluding taxes
     * - Tax Amount = sum of applied_fees matching TAX_KEYWORDS
     * - Discount = discount_amount field
     * - Total Billed = total_amount (the full expected amount to be collected)
     * - Grand Total = amount_paid (what customer actually paid so far)
     * - Balance Due = total_amount - amount_paid (outstanding amount still owed)
     * - Collected Via Gateway = actual payments from Authorize.Net (from payments table)
     * - Collected Via Gateway Net = gateway minus proportional fees/taxes
     *
     * @param array &$totals Reference to group totals array
     * @param object $record A stdClass row from DB::table()
     * @param float $gatewayAmount Actual gateway amount from the payments table
     */
    private function accumulateRecordTotals(array &$totals, object $record, float $gatewayAmount): void
    {
        $quantity = (int) ($record->quantity ?? 1);
        $totalAmount = (float) ($record->total_amount ?? 0);
        $amountPaid = (float) ($record->amount_paid ?? 0);
        $discountAmount = (float) ($record->discount_amount ?? 0);

        // Parse applied_fees JSON and separate taxes from other fees
        $appliedFees = $this->ensureArray($record->applied_fees);
        $feeAmount = 0;
        $taxAmount = 0;
        foreach ($appliedFees as $fee) {
            $feeValue = (float) ($fee['fee_amount'] ?? 0);
            if ($this->isTaxFee(strtolower(trim($fee['fee_name'] ?? '')))) {
                $taxAmount += $feeValue;
            } else {
                $feeAmount += $feeValue;
            }
        }

        $totals['quantity'] += $quantity;
        $totals['gross_sales'] += $totalAmount + $discountAmount;
        $totals['net_sales'] += $totalAmount;
        $totals['fee_amount'] += $feeAmount;
        $totals['discount_amount'] += $discountAmount;
        $totals['tax_amount'] += $taxAmount;
        $totals['total_billed'] += $totalAmount;
        $totals['grand_total'] += $amountPaid;
        $totals['balance_due'] += ($totalAmount - $amountPaid);
        $totals['collected_via_gateway'] += $gatewayAmount;

        // Gateway net: proportionally attribute fees/taxes to gateway portion
        if ($gatewayAmount > 0 && $amountPaid > 0) {
            $ratio = min($gatewayAmount / $amountPaid, 1.0);
            $totals['collected_via_gateway_net'] += $gatewayAmount - (($feeAmount + $taxAmount) * $ratio);
        }
    }

    /**
     * Get actual gateway payment amounts from the payments table.
     * This is the authoritative source for money collected through Authorize.Net.
     *
     * @param array $payableIds IDs of the payable records
     * @param string $payableType Payment::TYPE_BOOKING, TYPE_ATTRACTION_PURCHASE, or TYPE_EVENT_PURCHASE
     * @return array Keyed by payable_id => gateway_amount
     */
    private function getGatewayAmounts(array $payableIds, string $payableType): array
    {
        if (empty($payableIds)) {
            return [];
        }

        return DB::table('payments')
            ->where('payable_type', $payableType)
            ->where('method', 'authorize.net')
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->whereIn('payable_id', $payableIds)
            ->groupBy('payable_id')
            ->pluck(DB::raw('SUM(amount) as gateway_amount'), 'payable_id')
            ->map(fn($v) => (float) $v)
            ->all();
    }

    /**
     * Build the final category result array from grouped data.
     *
     * @param string $categoryName
     * @param array $grouped
     * @return array
     */
    private function buildCategoryResult(string $categoryName, array $grouped): array
    {
        $items = [];
        $categoryTotals = $this->initializeTotals();

        foreach ($grouped as $data) {
            $t = $data['totals'];
            $items[] = [
                'name' => $data['name'],
                'sub_category' => $data['sub_category'],
                'quantity_sold' => $t['quantity'],
                'gross_sales' => round($t['gross_sales'], 2),
                'net_sales' => round($t['net_sales'], 2),
                'fee_amount' => round($t['fee_amount'], 2),
                'discount_amount' => round($t['discount_amount'], 2),
                'tax_amount' => round($t['tax_amount'], 2),
                'total_billed' => round($t['total_billed'], 2),
                'grand_total' => round($t['grand_total'], 2),
                'balance_due' => round($t['balance_due'], 2),
                'collected_via_gateway' => round($t['collected_via_gateway'], 2),
                'collected_via_gateway_net' => round($t['collected_via_gateway_net'], 2),
            ];
            $this->accumulateTotals($categoryTotals, $t);
        }

        usort($items, function ($a, $b) {
            $catCompare = strcmp($a['sub_category'], $b['sub_category']);
            return $catCompare !== 0 ? $catCompare : strcmp($a['name'], $b['name']);
        });

        return [
            'name' => $categoryName,
            'items' => $items,
            'summary' => $this->formatTotals($categoryTotals),
        ];
    }

    /**
     * Check if a fee name indicates it's a tax.
     *
     * @param string $feeName
     * @return bool
     */
    private function isTaxFee(string $feeName): bool
    {
        foreach (self::TAX_KEYWORDS as $keyword) {
            if (str_contains($feeName, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure value is an array (handles JSON strings and null).
     *
     * @param mixed $value
     * @return array
     */
    private function ensureArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Accumulate totals into category totals.
     *
     * @param array &$categoryTotals
     * @param array $itemTotals
     */
    private function accumulateTotals(array &$categoryTotals, array $itemTotals): void
    {
        $categoryTotals['quantity'] += $itemTotals['quantity'];
        $categoryTotals['gross_sales'] += $itemTotals['gross_sales'];
        $categoryTotals['net_sales'] += $itemTotals['net_sales'];
        $categoryTotals['fee_amount'] += $itemTotals['fee_amount'];
        $categoryTotals['discount_amount'] += $itemTotals['discount_amount'];
        $categoryTotals['tax_amount'] += $itemTotals['tax_amount'];
        $categoryTotals['total_billed'] += $itemTotals['total_billed'];
        $categoryTotals['grand_total'] += $itemTotals['grand_total'];
        $categoryTotals['balance_due'] += $itemTotals['balance_due'];
        $categoryTotals['collected_via_gateway'] += $itemTotals['collected_via_gateway'];
        $categoryTotals['collected_via_gateway_net'] += $itemTotals['collected_via_gateway_net'];
    }

    /**
     * Format totals with proper rounding.
     *
     * @param array $totals
     * @return array
     */
    private function formatTotals(array $totals): array
    {
        return [
            'quantity_sold' => $totals['quantity'],
            'gross_sales' => round($totals['gross_sales'], 2),
            'net_sales' => round($totals['net_sales'], 2),
            'fee_amount' => round($totals['fee_amount'], 2),
            'discount_amount' => round($totals['discount_amount'], 2),
            'tax_amount' => round($totals['tax_amount'], 2),
            'total_billed' => round($totals['total_billed'], 2),
            'grand_total' => round($totals['grand_total'], 2),
            'balance_due' => round($totals['balance_due'], 2),
            'collected_via_gateway' => round($totals['collected_via_gateway'], 2),
            'collected_via_gateway_net' => round($totals['collected_via_gateway_net'], 2),
        ];
    }

    /**
     * Calculate overall summary from all categories.
     *
     * @param array $categories
     * @return array
     */
    private function calculateOverallSummary(array $categories): array
    {
        $totals = $this->initializeTotals();

        foreach ($categories as $category) {
            $summary = $category['summary'];
            $totals['quantity'] += $summary['quantity_sold'];
            $totals['gross_sales'] += $summary['gross_sales'];
            $totals['net_sales'] += $summary['net_sales'];
            $totals['fee_amount'] += $summary['fee_amount'];
            $totals['discount_amount'] += $summary['discount_amount'];
            $totals['tax_amount'] += $summary['tax_amount'];
            $totals['total_billed'] += $summary['total_billed'];
            $totals['grand_total'] += $summary['grand_total'];
            $totals['balance_due'] += $summary['balance_due'];
            $totals['collected_via_gateway'] += $summary['collected_via_gateway'];
            $totals['collected_via_gateway_net'] += $summary['collected_via_gateway_net'];
        }

        return $this->formatTotals($totals);
    }

    /**
     * Get summary statistics for a date range.
     * Useful for trend analysis.
     *
     * @param Request $request
     * @return JsonResponse
     */
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

        $location = Location::with('company')->findOrFail($locationId);

        try {
            $dailyData = [];
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $dayReport = $this->buildReportData($locationId, $currentDate, $currentDate, $viewMode);

                $dailyData[] = [
                    'date' => $currentDate->toDateString(),
                    'day_of_week' => $currentDate->format('l'),
                    'summary' => $dayReport['summary'],
                ];

                $currentDate->addDay();
            }

            // Calculate overall totals for the range
            $rangeTotals = $this->initializeTotals();
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
                    'range_totals' => $this->formatTotals($rangeTotals),
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

    /**
     * Export accounting report data in various formats.
     *
     * @param Request $request
     * @return JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
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

        $location = Location::findOrFail($locationId);
        $reportData = $this->buildReportData($locationId, $startDate, $endDate, $viewMode);

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

        // CSV export
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

            // Header info
            fputcsv($file, ['Accounting Report']);
            fputcsv($file, ['Location:', $location->name]);
            fputcsv($file, ['Start Date:', $startDate->toDateString()]);
            fputcsv($file, ['End Date:', $endDate->toDateString()]);
            fputcsv($file, ['View Mode:', $viewMode === 'booked_on' ? 'Created On' : 'Booked For']);
            fputcsv($file, ['Generated:', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            // Overall summary
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

            // Category breakdown
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

                // Category subtotal
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
