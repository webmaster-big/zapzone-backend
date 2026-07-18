<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    const CATEGORY_PARTIES = 'Parties';
    const CATEGORY_ATTRACTIONS = 'Attractions';
    const CATEGORY_EVENTS = 'Events';
    const CATEGORY_ADDONS = 'Add-ons';

    const TAX_KEYWORDS = ['tax', 'vat', 'gst', 'hst', 'pst', 'sales tax', 'state tax', 'local tax'];

    public function buildReportData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $partiesData = $this->getPartiesData($locationId, $startDate, $endDate, $viewMode, $filters);
        $attractionsData = $this->getAttractionsData($locationId, $startDate, $endDate, $viewMode, $filters);
        $eventsData = $this->getEventsData($locationId, $startDate, $endDate, $viewMode, $filters);

        $categories = [
            $partiesData,
            $attractionsData,
            $eventsData,
        ];

        if ($filters['include_addons_breakdown'] ?? true) {
            $addOnsData = $this->getAddOnsData($locationId, $startDate, $endDate, $viewMode, $filters);
            $categories[] = $addOnsData;
        }

        $summaryCategories = array_filter($categories, fn($cat) => $cat['name'] !== self::CATEGORY_ADDONS);
        $summary = $this->calculateOverallSummary($summaryCategories);

        return [
            'summary' => $summary,
            'categories' => $categories,
            'note' => 'Add-ons are shown for visibility but their revenue is already included in Parties/Attractions/Events totals.',
        ];
    }

    private function getPartiesData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('bookings')
            ->join('packages', 'bookings.package_id', '=', 'packages.id')
            ->where('bookings.location_id', $locationId)
            ->whereNotIn('bookings.status', ['cancelled'])
            ->whereNull('bookings.deleted_at');

        if ($viewMode === 'booked_on') {
            $query->whereDate('bookings.created_at', '>=', $startDate->toDateString())
                  ->whereDate('bookings.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereDate('bookings.booking_date', '>=', $startDate->toDateString())
                  ->whereDate('bookings.booking_date', '<=', $endDate->toDateString());
        }

        if (isset($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $query->where('bookings.payment_status', $filters['payment_status']);
        }

        if (!empty($filters['category_filter'])) {
            $query->where('packages.category', $filters['category_filter']);
        }

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

        $bookingIds = $bookings->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($bookingIds, Payment::TYPE_BOOKING);

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
            $gatewayAmount = ($booking->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$booking->id] ?? 0);
            $this->accumulateRecordTotals($totals, $booking, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_PARTIES, $grouped);
    }

    private function getAttractionsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('attraction_purchases')
            ->join('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
            ->where('attractions.location_id', $locationId)
            ->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('attraction_purchases.deleted_at');

        if ($viewMode === 'booked_on') {
            $query->whereDate('attraction_purchases.created_at', '>=', $startDate->toDateString())
                  ->whereDate('attraction_purchases.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereRaw(
                'DATE(COALESCE(attraction_purchases.scheduled_date, attraction_purchases.purchase_date)) BETWEEN ? AND ?',
                [$startDate->toDateString(), $endDate->toDateString()]
            );
        }

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

        $purchaseIds = $purchases->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($purchaseIds, Payment::TYPE_ATTRACTION_PURCHASE);

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
            $gatewayAmount = ($purchase->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$purchase->id] ?? 0);
            $this->accumulateRecordTotals($totals, $purchase, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_ATTRACTIONS, $grouped);
    }

    private function getEventsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $query = DB::table('event_purchases')
            ->join('events', 'event_purchases.event_id', '=', 'events.id')
            ->where('event_purchases.location_id', $locationId)
            ->whereNotIn('event_purchases.status', ['cancelled', 'refunded'])
            ->whereNull('event_purchases.deleted_at');

        if ($viewMode === 'booked_on') {
            $query->whereDate('event_purchases.created_at', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.created_at', '<=', $endDate->toDateString());
        } else {
            $query->whereDate('event_purchases.purchase_date', '>=', $startDate->toDateString())
                  ->whereDate('event_purchases.purchase_date', '<=', $endDate->toDateString());
        }

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

        $purchaseIds = $purchases->pluck('id')->all();
        $gatewayAmounts = $this->getGatewayAmounts($purchaseIds, Payment::TYPE_EVENT_PURCHASE);

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
            $gatewayAmount = ($purchase->payment_method === 'in-store') ? 0 : ($gatewayAmounts[$purchase->id] ?? 0);
            $this->accumulateRecordTotals($totals, $purchase, $gatewayAmount);
            unset($totals);
        }

        return $this->buildCategoryResult(self::CATEGORY_EVENTS, $grouped);
    }

    private function getAddOnsData(int $locationId, Carbon $startDate, Carbon $endDate, string $viewMode, array $filters = []): array
    {
        $addOnsSummary = [];

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
            'is_informational' => true,
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

    public function initializeTotals(): array
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

    private function accumulateRecordTotals(array &$totals, object $record, float $gatewayAmount): void
    {
        $quantity = (int) ($record->quantity ?? 1);
        $totalAmount = (float) ($record->total_amount ?? 0);
        $amountPaid = (float) ($record->amount_paid ?? 0);
        $discountAmount = (float) ($record->discount_amount ?? 0);

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

        if ($gatewayAmount > 0 && $amountPaid > 0) {
            $ratio = min($gatewayAmount / $amountPaid, 1.0);
            $totals['collected_via_gateway_net'] += $gatewayAmount - (($feeAmount + $taxAmount) * $ratio);
        }
    }

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

    private function isTaxFee(string $feeName): bool
    {
        foreach (self::TAX_KEYWORDS as $keyword) {
            if (str_contains($feeName, $keyword)) {
                return true;
            }
        }
        return false;
    }

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

    public function formatTotals(array $totals): array
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
}
