<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\EventPurchase;
use App\Models\Package;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\Location;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    use ScopesByAuthUser;

    /**
     * Get comprehensive analytics for company-wide view
     * Includes all locations with aggregated data
     */
    public function getCompanyAnalytics(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'date_range' => 'in:7d,30d,90d,1y,custom',
            'start_date' => 'nullable|date|required_if:date_range,custom',
            'end_date' => 'nullable|date|required_if:date_range,custom|after_or_equal:start_date',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);

        $companyId = $request->company_id;
        $dateRange = $request->date_range ?? '30d';
        $locationIds = $request->location_ids ?? [];

        // Enforce multi-tenant + role scope
        $authUser = $this->resolveAuthUser($request);
        if ($authUser && $authUser->company_id && (int) $authUser->company_id !== (int) $companyId) {
            return response()->json(['message' => 'Forbidden: cannot access another company analytics'], 403);
        }
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            // Location managers are forced to their own location only
            $locationIds = [$authUser->location_id];
        }

        // Calculate date range - use custom dates if provided, otherwise use preset
        if ($dateRange === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
        } else {
            $startDate = $this->getStartDate($dateRange);
            $endDate = now();
        }

        // Get company details
        $company = Company::with('locations')->findOrFail($companyId);

        // Filter locations if specific ones are selected
        $locations = $company->locations;
        if (!empty($locationIds)) {
            $locations = $locations->whereIn('id', $locationIds);
        }

        $locationIdList = $locations->pluck('id')->toArray();

        // If no locations after filtering, return empty response
        if (empty($locationIdList)) {
            return response()->json([
                'message' => 'No locations found for the specified criteria',
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'total_locations' => 0,
                ],
            ], 404);
        }

        // Get all company locations for the filter dropdown (unfiltered)
        $allCompanyLocations = $company->locations->map(function ($location) {
            return [
                'id' => $location->id,
                'name' => $location->name,
            ];
        })->values();

        // Compile all analytics data
        $analytics = [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'total_locations' => $locations->count(),
            ],
            'date_range' => [
                'period' => $dateRange,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'selected_locations' => $locationIds,
            'available_locations' => $allCompanyLocations,
            'key_metrics' => $this->getCompanyKeyMetrics($locationIdList, $startDate, $endDate),
            'revenue_trend' => $this->getRevenueTrend($locationIdList, $startDate, $endDate),
            'location_performance' => $this->getLocationPerformance($locationIdList, $startDate, $endDate),
            'package_distribution' => $this->getPackageDistribution($locationIdList, $startDate, $endDate),
            'peak_hours' => $this->getCompanyPeakHours($locationIdList, $startDate, $endDate),
            'daily_performance' => $this->getCompanyDailyPerformance($locationIdList, $startDate, $endDate),
            'booking_status' => $this->getBookingStatus($locationIdList, $startDate, $endDate),
            'top_attractions' => $this->getTopAttractions($locationIdList, $startDate, $endDate),
            'top_events' => $this->getTopEvents($locationIdList, $startDate, $endDate),
        ];

        return response()->json($analytics);
    }

    /**
     * Get comprehensive analytics for a location manager
     * Includes package bookings and attraction ticket sales
     */
    public function getLocationAnalytics(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date_range' => 'in:7d,30d,90d,1y,custom',
            'start_date' => 'nullable|date|required_if:date_range,custom',
            'end_date' => 'nullable|date|required_if:date_range,custom|after_or_equal:start_date',
        ]);

        $locationId = $request->location_id;
        $dateRange = $request->date_range ?? '30d';

        // Enforce: a location_manager/attendant can only view their own location.
        $authUser = $this->resolveAuthUser($request);
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true)
            && $authUser->location_id
            && (int) $authUser->location_id !== (int) $locationId) {
            return response()->json(['message' => 'Forbidden: cannot access another location analytics'], 403);
        }

        // Calculate date range - use custom dates if provided, otherwise use preset
        if ($dateRange === 'custom' && $request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
        } else {
            $startDate = $this->getStartDate($dateRange);
            $endDate = now();
        }

        // Get location details
        $location = Location::with('company')->findOrFail($locationId);

        // Compile all analytics data
        $analytics = [
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'state' => $location->state,
                'zip_code' => $location->zip_code,
                'full_address' => "{$location->address}, {$location->city}, {$location->state} {$location->zip_code}",
            ],
            'date_range' => [
                'period' => $dateRange,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
            'key_metrics' => $this->getKeyMetrics($locationId, $startDate, $endDate),
            'hourly_revenue' => $this->getHourlyRevenue($locationId, $startDate, $endDate),
            'daily_revenue' => $this->getDailyRevenue($locationId, $startDate, $endDate),
            'weekly_trend' => $this->getWeeklyTrend($locationId, $startDate, $endDate),
            'package_performance' => $this->getPackagePerformance($locationId, $startDate, $endDate),
            'attraction_performance' => $this->getAttractionPerformance($locationId, $startDate, $endDate),
            'event_performance' => $this->getEventPerformance($locationId, $startDate, $endDate),
            'time_slot_performance' => $this->getTimeSlotPerformance($locationId, $startDate, $endDate),
        ];

        return response()->json($analytics);
    }

    /**
     * Calculate start date based on date range
     */
    private function getStartDate($dateRange)
    {
        return match($dateRange) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };
    }

    /**
     * Get key metrics summary
     * Uses booking_date for bookings and scheduled_date/purchase_date for attractions
     */
    private function getKeyMetrics($locationId, $startDate, $endDate)
    {
        // Get bookings data - filter by booking_date (actual event date, not creation date)
        $bookings = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalBookingRevenue = $bookings->sum('amount_paid');
        $totalBookings = $bookings->count();
        $totalParticipants = $bookings->sum('participants');

        // Get attraction purchases data - filter by scheduled_date (fallback to purchase_date)
        $attractionPurchases = AttractionPurchase::byLocation($locationId)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $totalAttractionRevenue = $attractionPurchases->sum('amount_paid');
        $totalTicketsSold = $attractionPurchases->sum('quantity');

        // Get event purchases data - filter by purchase_date
        $eventPurchases = EventPurchase::where('location_id', $locationId)
            ->whereBetween('purchase_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $totalEventRevenue = $eventPurchases->sum('amount_paid');
        $totalEventTicketsSold = $eventPurchases->sum('quantity');

        // Combined totals
        $totalRevenue = $totalBookingRevenue + $totalAttractionRevenue + $totalEventRevenue;

        // Active counts
        $activePackages = Package::where('location_id', $locationId)
            ->where('is_active', true)
            ->count();

        $totalPackages = Package::where('location_id', $locationId)->count();

        $activeAttractions = Attraction::where('location_id', $locationId)
            ->where('is_active', true)
            ->count();

        $totalAttractions = Attraction::where('location_id', $locationId)->count();

        $activeEvents = Event::where('location_id', $locationId)
            ->where('is_active', true)
            ->count();

        $totalEvents = Event::where('location_id', $locationId)->count();

        // Calculate previous period for comparison
        $periodDays = $endDate->diffInDays($startDate);
        $prevStartDate = $startDate->copy()->subDays($periodDays);
        $prevEndDate = $startDate->copy()->subDay();

        $prevBookings = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$prevStartDate->toDateString(), $prevEndDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevAttractionPurchases = AttractionPurchase::byLocation($locationId)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$prevStartDate->toDateString(), $prevEndDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $prevEventPurchases = EventPurchase::where('location_id', $locationId)
            ->whereBetween('purchase_date', [$prevStartDate->toDateString(), $prevEndDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $prevTotalRevenue = $prevBookings->sum('amount_paid') + $prevAttractionPurchases->sum('amount_paid') + $prevEventPurchases->sum('amount_paid');
        $prevTotalBookings = $prevBookings->count();
        $prevTotalTickets = $prevAttractionPurchases->sum('quantity');
        $prevTotalEventTickets = $prevEventPurchases->sum('quantity');
        $prevTotalParticipants = $prevBookings->sum('participants');

        // Determine operational status messages
        $packageStatus = $activePackages === $totalPackages ? 'All operational' :
                         ($activePackages === 0 ? 'None operational' :
                         ($totalPackages - $activePackages) . ' inactive');

        $attractionStatus = $activeAttractions === $totalAttractions ? 'All operational' :
                            ($activeAttractions === 0 ? 'None operational' :
                            ($totalAttractions - $activeAttractions) . ' inactive');

        $eventStatus = $activeEvents === $totalEvents ? 'All operational' :
                       ($activeEvents === 0 ? 'None operational' :
                       ($totalEvents - $activeEvents) . ' inactive');

        return [
            'location_revenue' => [
                'value' => round($totalRevenue, 2),
                'change' => $this->calculatePercentageChange($totalRevenue, $prevTotalRevenue),
                'trend' => $totalRevenue >= $prevTotalRevenue ? 'up' : 'down',
            ],
            'package_bookings' => [
                'value' => $totalBookings,
                'change' => $this->calculatePercentageChange($totalBookings, $prevTotalBookings),
                'trend' => $totalBookings >= $prevTotalBookings ? 'up' : 'down',
            ],
            'ticket_sales' => [
                'value' => $totalTicketsSold,
                'change' => $this->calculatePercentageChange($totalTicketsSold, $prevTotalTickets),
                'trend' => $totalTicketsSold >= $prevTotalTickets ? 'up' : 'down',
            ],
            'event_ticket_sales' => [
                'value' => $totalEventTicketsSold,
                'change' => $this->calculatePercentageChange($totalEventTicketsSold, $prevTotalEventTickets),
                'trend' => $totalEventTicketsSold >= $prevTotalEventTickets ? 'up' : 'down',
            ],
            'total_visitors' => [
                'value' => $totalParticipants + $totalTicketsSold + $totalEventTicketsSold,
                'change' => $this->calculatePercentageChange(
                    $totalParticipants + $totalTicketsSold + $totalEventTicketsSold,
                    $prevTotalParticipants + $prevTotalTickets + $prevTotalEventTickets
                ),
                'trend' => ($totalParticipants + $totalTicketsSold + $totalEventTicketsSold) >= ($prevTotalParticipants + $prevTotalTickets + $prevTotalEventTickets) ? 'up' : 'down',
            ],
            'active_packages' => [
                'value' => $activePackages,
                'total' => $totalPackages,
                'info' => $packageStatus,
            ],
            'active_attractions' => [
                'value' => $activeAttractions,
                'total' => $totalAttractions,
                'info' => $attractionStatus,
            ],
            'active_events' => [
                'value' => $activeEvents,
                'total' => $totalEvents,
                'info' => $eventStatus,
            ],
        ];
    }

    /**
     * Get hourly revenue pattern based on actual booking_time / scheduled_time
     */
    private function getHourlyRevenue($locationId, $startDate, $endDate)
    {
        $hourlyData = [];

        // Get bookings grouped by actual booking hour (not creation time)
        $bookingsByHour = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('HOUR(booking_time) as hour'),
                DB::raw('SUM(amount_paid) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Get attraction purchases grouped by scheduled_time
        $attractionsByHour = AttractionPurchase::byLocation($locationId)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('scheduled_time')
            ->select(
                DB::raw('HOUR(scheduled_time) as hour'),
                DB::raw('SUM(amount_paid) as revenue'),
                DB::raw('COUNT(*) as purchases')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Get event purchases grouped by purchase_time
        $eventsByHour = EventPurchase::where('location_id', $locationId)
            ->whereBetween('purchase_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('purchase_time')
            ->select(
                DB::raw('HOUR(purchase_time) as hour'),
                DB::raw('SUM(amount_paid) as revenue'),
                DB::raw('COUNT(*) as purchases')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Generate data for each hour (9 AM to 9 PM)
        for ($hour = 9; $hour <= 21; $hour++) {
            $bookingData = $bookingsByHour->get($hour);
            $attractionData = $attractionsByHour->get($hour);
            $eventData = $eventsByHour->get($hour);

            $bookingRev = $bookingData ? round($bookingData->revenue, 2) : 0;
            $attractionRev = $attractionData ? round($attractionData->revenue, 2) : 0;
            $eventRev = $eventData ? round($eventData->revenue, 2) : 0;

            // Format hour label
            $period = $hour < 12 ? 'AM' : 'PM';
            $displayHour = $hour <= 12 ? $hour : $hour - 12;

            $hourlyData[] = [
                'hour' => $hour,
                'label' => "{$displayHour} {$period}",
                'revenue' => round($bookingRev + $attractionRev + $eventRev, 2),
                'bookings' => $bookingData ? (int) $bookingData->bookings : 0,
                'attraction_purchases' => $attractionData ? (int) $attractionData->purchases : 0,
                'event_purchases' => $eventData ? (int) $eventData->purchases : 0,
            ];
        }

        return $hourlyData;
    }

    /**
     * Get daily revenue within the specified date range
     * Optimized: uses grouped queries instead of per-day iteration
     */
    private function getDailyRevenue($locationId, $startDate, $endDate)
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        // Get all bookings grouped by booking_date in a single query
        $bookingsByDate = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                'booking_date',
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(participants) as total_participants'),
                DB::raw('COUNT(*) as total_bookings')
            )
            ->groupBy('booking_date')
            ->get()
            ->keyBy(fn($item) => $item->booking_date->toDateString());

        // Get all attraction purchases grouped by visit date in a single query
        $attractionsByDate = AttractionPurchase::byLocation($locationId)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                DB::raw('COALESCE(scheduled_date, purchase_date) as visit_date'),
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(quantity) as total_tickets'),
                DB::raw('COUNT(*) as total_purchases')
            )
            ->groupBy('visit_date')
            ->get()
            ->keyBy('visit_date');

        // Get all event purchases grouped by purchase_date in a single query
        $eventsByDate = EventPurchase::where('location_id', $locationId)
            ->whereBetween('purchase_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                'purchase_date',
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(quantity) as total_tickets'),
                DB::raw('COUNT(*) as total_purchases')
            )
            ->groupBy('purchase_date')
            ->get()
            ->keyBy(fn($item) => $item->purchase_date->toDateString());

        $dailyData = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->toDateString();
            $dayOfWeek = $days[$currentDate->dayOfWeekIso - 1];

            $bookingData = $bookingsByDate->get($dateStr);
            $attractionData = $attractionsByDate->get($dateStr);
            $eventData = $eventsByDate->get($dateStr);

            $bookingRevenue = $bookingData ? round((float) $bookingData->total_revenue, 2) : 0;
            $attractionRevenue = $attractionData ? round((float) $attractionData->total_revenue, 2) : 0;
            $eventRevenue = $eventData ? round((float) $eventData->total_revenue, 2) : 0;
            $participants = ($bookingData ? (int) $bookingData->total_participants : 0)
                         + ($attractionData ? (int) $attractionData->total_tickets : 0)
                         + ($eventData ? (int) $eventData->total_tickets : 0);

            $dailyData[] = [
                'day' => $dayOfWeek,
                'date' => $dateStr,
                'revenue' => round($bookingRevenue + $attractionRevenue + $eventRevenue, 2),
                'bookings' => $bookingData ? (int) $bookingData->total_bookings : 0,
                'attraction_purchases' => $attractionData ? (int) $attractionData->total_purchases : 0,
                'event_purchases' => $eventData ? (int) $eventData->total_purchases : 0,
                'participants' => $participants,
            ];

            $currentDate->addDay();
        }

        return $dailyData;
    }

    /**
     * Get weekly trend within the specified date range
     * Uses booking_date for bookings, scheduled_date/purchase_date for attractions
     */
    private function getWeeklyTrend($locationId, $startDate, $endDate)
    {
        $weeklyData = [];
        $weekNumber = 1;

        // Start from the beginning of the first week in the range
        $currentWeekStart = $startDate->copy()->startOfWeek();

        while ($currentWeekStart->lte($endDate)) {
            $currentWeekEnd = $currentWeekStart->copy()->endOfWeek();

            // Adjust week boundaries to stay within the date range
            $effectiveStart = $currentWeekStart->lt($startDate) ? $startDate->copy() : $currentWeekStart->copy();
            $effectiveEnd = $currentWeekEnd->gt($endDate) ? $endDate->copy() : $currentWeekEnd->copy();

            // Bookings for this week - use booking_date
            $weekBookings = Booking::where('location_id', $locationId)
                ->whereBetween('booking_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            // Attraction purchases for this week - use scheduled_date/purchase_date
            $weekAttractions = AttractionPurchase::byLocation($locationId)
                ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->get();

            // Event purchases for this week - use purchase_date
            $weekEvents = EventPurchase::where('location_id', $locationId)
                ->whereBetween('purchase_date', [$effectiveStart->toDateString(), $effectiveEnd->toDateString()])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->get();

            $weeklyData[] = [
                'week' => 'Week ' . $weekNumber,
                'week_start' => $effectiveStart->toDateString(),
                'week_end' => $effectiveEnd->toDateString(),
                'revenue' => round($weekBookings->sum('amount_paid') + $weekAttractions->sum('amount_paid') + $weekEvents->sum('amount_paid'), 2),
                'bookings' => $weekBookings->count(),
                'tickets' => $weekAttractions->sum('quantity'),
                'event_tickets' => $weekEvents->sum('quantity'),
            ];

            $currentWeekStart->addWeek();
            $weekNumber++;
        }

        return $weeklyData;
    }

    /**
     * Get package performance
     */
    private function getPackagePerformance($locationId, $startDate, $endDate)
    {
        // Get active packages for this location
        $packages = Package::where('location_id', $locationId)
            ->where('is_active', true)
            ->get();

        if ($packages->isEmpty()) {
            return collect([]);
        }

        $packageIds = $packages->pluck('id')->toArray();

        // Get aggregated booking stats in a single efficient query
        $bookingStats = Booking::whereIn('package_id', $packageIds)
            ->whereBetween('booking_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                'package_id',
                DB::raw('COUNT(*) as bookings_count'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as total_revenue'),
                DB::raw('COALESCE(SUM(participants), 0) as total_participants'),
                DB::raw('COALESCE(AVG(participants), 0) as avg_party_size')
            )
            ->groupBy('package_id')
            ->get()
            ->keyBy('package_id');

        // Combine package data with booking stats
        return $packages->map(function ($package) use ($bookingStats) {
            $stats = $bookingStats->get($package->id);

            // Skip packages with no bookings
            if (!$stats || $stats->bookings_count == 0) {
                return null;
            }

            return [
                'id' => $package->id,
                'name' => $package->name,
                'category' => $package->category,
                'bookings' => (int) $stats->bookings_count,
                'revenue' => round((float) $stats->total_revenue, 2),
                'participants' => (int) $stats->total_participants,
                'avg_party_size' => round((float) $stats->avg_party_size, 1),
                'price' => round($package->price, 2),
            ];
        })->filter()->sortByDesc('revenue')->values();
    }

    /**
     * Get attraction performance (ticket sales)
     * Uses scheduled_date/purchase_date for date filtering, excludes refunded
     */
    private function getAttractionPerformance($locationId, $startDate, $endDate)
    {
        $attractions = Attraction::where('location_id', $locationId)
            ->where('is_active', true)
            ->get();

        if ($attractions->isEmpty()) {
            return collect([]);
        }

        $attractionIds = $attractions->pluck('id')->toArray();

        // Get aggregated purchase stats in a single efficient query
        $purchaseStats = AttractionPurchase::whereIn('attraction_id', $attractionIds)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                'attraction_id',
                DB::raw('COUNT(*) as purchase_count'),
                DB::raw('COALESCE(SUM(quantity), 0) as total_tickets'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as total_revenue')
            )
            ->groupBy('attraction_id')
            ->get()
            ->keyBy('attraction_id');

        $daysInPeriod = $endDate->diffInDays($startDate) ?: 1;

        return $attractions->map(function ($attraction) use ($purchaseStats, $daysInPeriod) {
            $stats = $purchaseStats->get($attraction->id);

            $sessions = $stats ? (int) $stats->purchase_count : 0;
            $ticketsSold = $stats ? (int) $stats->total_tickets : 0;
            $revenue = $stats ? round((float) $stats->total_revenue, 2) : 0;

            // Calculate utilization: tickets sold vs max_capacity per day over the period
            $maxPossible = ($attraction->max_capacity ?? 0) * $daysInPeriod;
            $utilization = $maxPossible > 0
                ? min(100, round(($ticketsSold / $maxPossible) * 100, 1))
                : 0;

            return [
                'id' => $attraction->id,
                'name' => $attraction->name,
                'category' => $attraction->category,
                'sessions' => $sessions,
                'tickets_sold' => $ticketsSold,
                'revenue' => $revenue,
                'utilization' => $utilization,
                'price' => round($attraction->price, 2),
                'max_capacity' => $attraction->max_capacity,
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Get time slot performance (hourly breakdown)
     * Uses booking_time for bookings, scheduled_time for attractions
     */
    private function getTimeSlotPerformance($locationId, $startDate, $endDate)
    {
        $slotData = [];

        // Get bookings grouped by actual booking hour (not creation time)
        $bookingsByHour = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('HOUR(booking_time) as hour'),
                DB::raw('COUNT(*) as bookings_count'),
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(participants) as total_participants')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Get attraction purchases grouped by scheduled_time
        $attractionsByHour = AttractionPurchase::byLocation($locationId)
            ->whereRaw('COALESCE(scheduled_date, purchase_date) BETWEEN ? AND ?', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('scheduled_time')
            ->select(
                DB::raw('HOUR(scheduled_time) as hour'),
                DB::raw('COUNT(*) as purchases_count'),
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(quantity) as total_tickets')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Get event purchases grouped by purchase_time
        $eventsByHour = EventPurchase::where('location_id', $locationId)
            ->whereBetween('purchase_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereNotNull('purchase_time')
            ->select(
                DB::raw('HOUR(purchase_time) as hour'),
                DB::raw('COUNT(*) as purchases_count'),
                DB::raw('SUM(amount_paid) as total_revenue'),
                DB::raw('SUM(quantity) as total_tickets')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Generate data for each hour (9 AM to 9 PM)
        for ($hour = 9; $hour <= 21; $hour++) {
            $bookingData = $bookingsByHour->get($hour);
            $attractionData = $attractionsByHour->get($hour);
            $eventData = $eventsByHour->get($hour);

            $bookingsCount = $bookingData ? (int) $bookingData->bookings_count : 0;
            $bookingsRevenue = $bookingData ? round((float) $bookingData->total_revenue, 2) : 0;
            $participants = $bookingData ? (int) $bookingData->total_participants : 0;

            $ticketsSold = $attractionData ? (int) $attractionData->total_tickets : 0;
            $attractionRevenue = $attractionData ? round((float) $attractionData->total_revenue, 2) : 0;

            $eventTicketsSold = $eventData ? (int) $eventData->total_tickets : 0;
            $eventRevenue = $eventData ? round((float) $eventData->total_revenue, 2) : 0;

            $totalRevenue = round($bookingsRevenue + $attractionRevenue + $eventRevenue, 2);
            $totalTransactions = $bookingsCount + ($attractionData ? (int) $attractionData->purchases_count : 0) + ($eventData ? (int) $eventData->purchases_count : 0);

            // Format hour label (e.g., "9 AM", "12 PM", "5 PM")
            $period = $hour < 12 ? 'AM' : 'PM';
            $displayHour = $hour <= 12 ? $hour : $hour - 12;
            $label = "{$displayHour} {$period}";

            $slotData[] = [
                'hour' => $hour,
                'label' => $label,
                'bookings' => $bookingsCount,
                'tickets_sold' => $ticketsSold,
                'event_tickets_sold' => $eventTicketsSold,
                'participants' => $participants,
                'booking_revenue' => $bookingsRevenue,
                'attraction_revenue' => $attractionRevenue,
                'event_revenue' => $eventRevenue,
                'total_revenue' => $totalRevenue,
                'total_transactions' => $totalTransactions,
                'avg_value' => $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0,
            ];
        }

        return $slotData;
    }

    /**
     * Calculate percentage change between current and previous values
     */
    private function calculatePercentageChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $change = (($current - $previous) / $previous) * 100;
        $sign = $change >= 0 ? '+' : '';

        return $sign . number_format($change, 1) . '%';
    }

    /**
     * Get company-wide key metrics
     */
    private function getCompanyKeyMetrics($locationIds, $startDate, $endDate)
    {
        // Get bookings data for all locations
        $bookings = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalBookingRevenue = $bookings->sum('amount_paid');
        $totalBookings = $bookings->count();
        $totalParticipants = $bookings->sum('participants');

        // Get attraction purchases for all locations
        $attractionPurchases = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                $query->whereIn('location_id', $locationIds);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalAttractionRevenue = $attractionPurchases->sum('amount_paid');
        $totalTicketsSold = $attractionPurchases->sum('quantity');

        // Get event purchases for all locations
        $eventPurchases = EventPurchase::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $totalEventRevenue = $eventPurchases->sum('amount_paid');
        $totalEventTicketsSold = $eventPurchases->sum('quantity');

        // Combined totals
        $totalRevenue = $totalBookingRevenue + $totalAttractionRevenue + $totalEventRevenue;
        $totalLocations = count($locationIds);

        // Active counts
        $activePackages = Package::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->count();

        $activeEvents = Event::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->count();

        // Previous period comparison
        $periodDays = $endDate->diffInDays($startDate);
        $prevStartDate = $startDate->copy()->subDays($periodDays);
        $prevEndDate = $startDate->copy();

        $prevBookings = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevAttractionPurchases = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                $query->whereIn('location_id', $locationIds);
            })
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevEventPurchases = EventPurchase::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->get();

        $prevTotalRevenue = $prevBookings->sum('amount_paid') + $prevAttractionPurchases->sum('amount_paid') + $prevEventPurchases->sum('amount_paid');
        $prevTotalBookings = $prevBookings->count();
        $prevTotalTickets = $prevAttractionPurchases->sum('quantity');
        $prevTotalEventTickets = $prevEventPurchases->sum('quantity');
        $prevTotalParticipants = $prevBookings->sum('participants');

        // New locations/packages in period
        $newPackages = Package::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        return [
            'total_revenue' => [
                'value' => round($totalRevenue, 2),
                'change' => $this->calculatePercentageChange($totalRevenue, $prevTotalRevenue),
                'trend' => $totalRevenue >= $prevTotalRevenue ? 'up' : 'down',
            ],
            'total_locations' => [
                'value' => $totalLocations,
                'info' => Location::whereIn('id', $locationIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count() . ' new locations',
            ],
            'package_bookings' => [
                'value' => $totalBookings,
                'change' => $this->calculatePercentageChange($totalBookings, $prevTotalBookings),
                'trend' => $totalBookings >= $prevTotalBookings ? 'up' : 'down',
            ],
            'ticket_purchases' => [
                'value' => $totalTicketsSold,
                'change' => $this->calculatePercentageChange($totalTicketsSold, $prevTotalTickets),
                'trend' => $totalTicketsSold >= $prevTotalTickets ? 'up' : 'down',
            ],
            'event_ticket_purchases' => [
                'value' => $totalEventTicketsSold,
                'change' => $this->calculatePercentageChange($totalEventTicketsSold, $prevTotalEventTickets),
                'trend' => $totalEventTicketsSold >= $prevTotalEventTickets ? 'up' : 'down',
            ],
            'total_participants' => [
                'value' => $totalParticipants + $totalTicketsSold + $totalEventTicketsSold,
                'change' => $this->calculatePercentageChange(
                    $totalParticipants + $totalTicketsSold + $totalEventTicketsSold,
                    $prevTotalParticipants + $prevTotalTickets + $prevTotalEventTickets
                ),
                'trend' => ($totalParticipants + $totalTicketsSold + $totalEventTicketsSold) >= ($prevTotalParticipants + $prevTotalTickets + $prevTotalEventTickets) ? 'up' : 'down',
            ],
            'active_packages' => [
                'value' => $activePackages,
                'info' => $newPackages . ' new packages',
            ],
            'active_events' => [
                'value' => $activeEvents,
                'info' => Event::whereIn('location_id', $locationIds)
                    ->where('is_active', true)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count() . ' new events',
            ],
        ];
    }

    /**
     * Get revenue trend over time (monthly for long periods, daily for short)
     */
    private function getRevenueTrend($locationIds, $startDate, $endDate)
    {
        // Handle empty location IDs
        if (empty($locationIds)) {
            return [];
        }

        $daysDiff = $endDate->diffInDays($startDate);
        $trendData = [];

        // If more than 60 days, show monthly data within the specified range
        if ($daysDiff > 60) {
            $currentMonth = $startDate->copy()->startOfMonth();
            $endMonth = $endDate->copy()->endOfMonth();

            while ($currentMonth->lte($endMonth)) {
                $monthStart = $currentMonth->copy();
                $monthEnd = $currentMonth->copy()->endOfMonth();

                // Don't go beyond the specified end date
                if ($monthEnd->gt($endDate)) {
                    $monthEnd = $endDate->copy();
                }

                // Don't start before the specified start date
                if ($monthStart->lt($startDate)) {
                    $monthStart = $startDate->copy();
                }

                $bookingsRevenue = Booking::whereIn('location_id', $locationIds)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('amount_paid') ?? 0;

                $bookingsCount = Booking::whereIn('location_id', $locationIds)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->count() ?? 0;

                $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                        $query->whereIn('location_id', $locationIds);
                    })
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('amount_paid') ?? 0;

                $eventRevenue = EventPurchase::whereIn('location_id', $locationIds)
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->sum('amount_paid') ?? 0;

                $trendData[] = [
                    'month' => $monthStart->format('M y'),
                    'revenue' => round($bookingsRevenue + $attractionRevenue + $eventRevenue, 2),
                    'bookings' => $bookingsCount,
                ];

                $currentMonth->addMonth();
            }
        } else {
            // Show daily data for the specified date range
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $bookingsRevenue = Booking::whereIn('location_id', $locationIds)
                    ->whereDate('created_at', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('amount_paid') ?? 0;

                $bookingsCount = Booking::whereIn('location_id', $locationIds)
                    ->whereDate('created_at', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->count() ?? 0;

                $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                        $query->whereIn('location_id', $locationIds);
                    })
                    ->whereDate('created_at', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('amount_paid') ?? 0;

                $eventRevenue = EventPurchase::whereIn('location_id', $locationIds)
                    ->whereDate('created_at', $currentDate)
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->sum('amount_paid') ?? 0;

                $trendData[] = [
                    'month' => $currentDate->format('M d'),
                    'revenue' => round($bookingsRevenue + $attractionRevenue + $eventRevenue, 2),
                    'bookings' => $bookingsCount,
                ];

                $currentDate->addDay();
            }
        }

        return $trendData;
    }

    /**
     * Get performance comparison across locations
     */
    private function getLocationPerformance($locationIds, $startDate, $endDate)
    {
        // Handle empty location IDs
        if (empty($locationIds)) {
            return [];
        }

        $locations = Location::whereIn('id', $locationIds)->get();

        return $locations->map(function ($location) use ($startDate, $endDate) {
            $bookingsRevenue = Booking::where('location_id', $location->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->sum('amount_paid') ?? 0;

            $bookingsCount = Booking::where('location_id', $location->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->count() ?? 0;

            $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($location) {
                    $query->where('location_id', $location->id);
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->sum('amount_paid') ?? 0;

            $eventRevenue = EventPurchase::where('location_id', $location->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('amount_paid') ?? 0;

            $totalRevenue = $bookingsRevenue + $attractionRevenue + $eventRevenue;

            return [
                'location' => $location->name,
                'location_id' => $location->id,
                'revenue' => round($totalRevenue, 2),
                'bookings' => $bookingsCount,
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Get package distribution (percentage breakdown by category)
     */
    private function getPackageDistribution($locationIds, $startDate, $endDate)
    {
        $bookings = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->with('package')
            ->get();

        // If no bookings, return empty array
        if ($bookings->isEmpty()) {
            return [];
        }

        $categoryGroups = $bookings->groupBy(function ($booking) {
            // Handle null package or category
            if (!$booking->package) {
                return 'Other';
            }
            return $booking->package->category ?? 'Other';
        });

        $total = $bookings->count();
        $colors = ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6'];
        $colorIndex = 0;

        $distribution = $categoryGroups->map(function ($group, $category) use ($total, $colors, &$colorIndex) {
            $percentage = $total > 0 ? round(($group->count() / $total) * 100, 1) : 0;
            $color = $colors[$colorIndex % count($colors)];
            $colorIndex++;

            return [
                'name' => ucfirst($category) . ' Package',
                'value' => $percentage,
                'count' => $group->count(),
                'color' => $color,
            ];
        })->sortByDesc('value')->values();

        return $distribution;
    }

    /**
     * Get peak hours across all locations
     */
    private function getCompanyPeakHours($locationIds, $startDate, $endDate)
    {
        $hourlyData = [];

        $bookingsByHour = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        $eventsByHour = EventPurchase::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('COUNT(*) as purchases')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        for ($hour = 9; $hour <= 21; $hour++) {
            $hourData = $bookingsByHour->get($hour);
            $eventData = $eventsByHour->get($hour);

            $hourlyData[] = [
                'hour' => sprintf('%02d:00', $hour),
                'bookings' => $hourData ? $hourData->bookings : 0,
                'event_purchases' => $eventData ? $eventData->purchases : 0,
            ];
        }

        return $hourlyData;
    }

    /**
     * Get daily performance within the specified date range
     */
    private function getCompanyDailyPerformance($locationIds, $startDate, $endDate)
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dailyData = [];

        // Iterate through each day in the date range
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dayOfWeek = $days[$currentDate->dayOfWeekIso - 1];

            $bookingsRevenue = Booking::whereIn('location_id', $locationIds)
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled'])
                ->sum('amount_paid');

            $bookingsParticipants = Booking::whereIn('location_id', $locationIds)
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled'])
                ->sum('participants');

            $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                    $query->whereIn('location_id', $locationIds);
                })
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled'])
                ->sum('amount_paid');

            $attractionTickets = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                    $query->whereIn('location_id', $locationIds);
                })
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled'])
                ->sum('quantity');

            $eventRevenue = EventPurchase::whereIn('location_id', $locationIds)
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('amount_paid');

            $eventTickets = EventPurchase::whereIn('location_id', $locationIds)
                ->whereDate('created_at', $currentDate)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('quantity');

            $dailyData[] = [
                'day' => $dayOfWeek,
                'date' => $currentDate->toDateString(),
                'revenue' => round($bookingsRevenue + $attractionRevenue + $eventRevenue, 2),
                'participants' => $bookingsParticipants + $attractionTickets + $eventTickets,
            ];

            $currentDate->addDay();
        }

        return $dailyData;
    }

    /**
     * Get booking status distribution
     */
    private function getBookingStatus($locationIds, $startDate, $endDate)
    {
        // Handle empty location IDs
        if (empty($locationIds)) {
            return [];
        }

        $bookings = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        // If no bookings, return empty array
        if ($bookings->isEmpty()) {
            return [];
        }

        $statusColors = [
            'confirmed' => '#10b981',
            'pending' => '#f59e0b',
            'cancelled' => '#ef4444',
            'checked-in' => '#06b6d4',
            'completed' => '#8b5cf6',
        ];

        return $bookings->map(function ($booking) use ($statusColors) {
            return [
                'status' => ucfirst($booking->status),
                'count' => $booking->count,
                'color' => $statusColors[$booking->status] ?? '#6b7280',
            ];
        })->values();
    }

    /**
     * Get top attractions by ticket sales across all locations
     */
    private function getTopAttractions($locationIds, $startDate, $endDate)
    {
        $attractions = Attraction::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->get();

        $attractionData = $attractions->map(function ($attraction) use ($startDate, $endDate) {
            $purchases = AttractionPurchase::where('attraction_id', $attraction->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            return [
                'id' => $attraction->id,
                'name' => $attraction->name,
                'tickets_sold' => $purchases->sum('quantity') ?? 0,
                'revenue' => round($purchases->sum('amount_paid') ?? 0, 2),
            ];
        })->filter(function ($item) {
            // Only include attractions with sales
            return $item['revenue'] > 0 || $item['tickets_sold'] > 0;
        })->sortByDesc('revenue')->take(10)->values();

        return $attractionData;
    }

    /**
     * Get top events by ticket sales across all locations
     */
    private function getTopEvents($locationIds, $startDate, $endDate)
    {
        $events = Event::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->get();

        $eventData = $events->map(function ($event) use ($startDate, $endDate) {
            $purchases = EventPurchase::where('event_id', $event->id)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->get();

            return [
                'id' => $event->id,
                'name' => $event->name,
                'tickets_sold' => $purchases->sum('quantity') ?? 0,
                'revenue' => round($purchases->sum('amount_paid') ?? 0, 2),
            ];
        })->filter(function ($item) {
            return $item['revenue'] > 0 || $item['tickets_sold'] > 0;
        })->sortByDesc('revenue')->take(10)->values();

        return $eventData;
    }

    /**
     * Get event performance (ticket sales per event)
     * Uses purchase_date for date filtering
     */
    private function getEventPerformance($locationId, $startDate, $endDate)
    {
        $events = Event::where('location_id', $locationId)
            ->where('is_active', true)
            ->get();

        if ($events->isEmpty()) {
            return collect([]);
        }

        $eventIds = $events->pluck('id')->toArray();

        // Get aggregated purchase stats in a single efficient query
        $purchaseStats = EventPurchase::whereIn('event_id', $eventIds)
            ->whereBetween('purchase_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                'event_id',
                DB::raw('COUNT(*) as purchase_count'),
                DB::raw('COALESCE(SUM(quantity), 0) as total_tickets'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as total_revenue')
            )
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        return $events->map(function ($event) use ($purchaseStats) {
            $stats = $purchaseStats->get($event->id);

            $purchaseCount = $stats ? (int) $stats->purchase_count : 0;
            $ticketsSold = $stats ? (int) $stats->total_tickets : 0;
            $revenue = $stats ? round((float) $stats->total_revenue, 2) : 0;

            return [
                'id' => $event->id,
                'name' => $event->name,
                'date_type' => $event->date_type,
                'purchases' => $purchaseCount,
                'tickets_sold' => $ticketsSold,
                'revenue' => $revenue,
                'price' => round($event->price, 2),
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Export analytics data
     */
    public function exportAnalytics(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date_range' => 'in:7d,30d,90d,1y,custom',
            'start_date' => 'nullable|date|required_if:date_range,custom',
            'end_date' => 'nullable|date|required_if:date_range,custom|after_or_equal:start_date',
            'format' => 'in:json,csv',
            'sections' => 'array',
            'sections.*' => 'in:metrics,revenue,packages,attractions,events,timeslots',
        ]);

        // Get the full analytics data
        $analyticsRequest = new Request([
            'location_id' => $request->location_id,
            'date_range' => $request->date_range ?? '30d',
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        $analytics = json_decode($this->getLocationAnalytics($analyticsRequest)->getContent(), true);

        // Filter sections if specified
        if ($request->has('sections') && !empty($request->sections)) {
            $filteredAnalytics = [
                'location' => $analytics['location'],
                'date_range' => $analytics['date_range'],
                'generated_at' => now()->toIso8601String(),
            ];

            $sectionMapping = [
                'metrics' => 'key_metrics',
                'revenue' => ['hourly_revenue', 'daily_revenue', 'weekly_trend'],
                'packages' => 'package_performance',
                'attractions' => 'attraction_performance',
                'events' => 'event_performance',
                'timeslots' => 'time_slot_performance',
            ];

            foreach ($request->sections as $section) {
                $keys = $sectionMapping[$section];
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        $filteredAnalytics[$key] = $analytics[$key];
                    }
                } else {
                    $filteredAnalytics[$keys] = $analytics[$keys];
                }
            }

            $analytics = $filteredAnalytics;
        } else {
            $analytics['generated_at'] = now()->toIso8601String();
        }

        $format = $request->format ?? 'json';

        if ($format === 'csv') {
            return $this->exportAsCsv($analytics);
        }

        return response()->json($analytics);
    }

    /**
     * Export analytics as CSV
     */
    private function exportAsCsv($analytics)
    {
        $csv = [];

        // Add header
        $csv[] = ['Location Analytics Export'];
        $csv[] = ['Generated At', $analytics['generated_at']];
        $csv[] = ['Location', $analytics['location']['name']];
        $csv[] = ['Address', $analytics['location']['full_address']];
        $csv[] = ['Period', $analytics['date_range']['period']];
        $csv[] = ['Start Date', $analytics['date_range']['start_date']];
        $csv[] = ['End Date', $analytics['date_range']['end_date']];
        $csv[] = [];

        // Key Metrics
        if (isset($analytics['key_metrics'])) {
            $csv[] = ['Key Metrics'];
            $csv[] = ['Metric', 'Value', 'Change', 'Trend'];
            foreach ($analytics['key_metrics'] as $key => $metric) {
                $csv[] = [
                    ucwords(str_replace('_', ' ', $key)),
                    $metric['value'] ?? ($metric['info'] ?? ''),
                    $metric['change'] ?? '',
                    $metric['trend'] ?? '',
                ];
            }
            $csv[] = [];
        }

        // Package Performance
        if (isset($analytics['package_performance'])) {
            $csv[] = ['Package Performance'];
            $csv[] = ['Package', 'Category', 'Bookings', 'Revenue', 'Participants', 'Avg Party Size'];
            foreach ($analytics['package_performance'] as $package) {
                $csv[] = [
                    $package['name'],
                    $package['category'],
                    $package['bookings'],
                    $package['revenue'],
                    $package['participants'],
                    $package['avg_party_size'],
                ];
            }
            $csv[] = [];
        }

        // Attraction Performance
        if (isset($analytics['attraction_performance'])) {
            $csv[] = ['Attraction Performance'];
            $csv[] = ['Attraction', 'Category', 'Sessions', 'Tickets Sold', 'Revenue', 'Utilization %'];
            foreach ($analytics['attraction_performance'] as $attraction) {
                $csv[] = [
                    $attraction['name'],
                    $attraction['category'],
                    $attraction['sessions'],
                    $attraction['tickets_sold'],
                    $attraction['revenue'],
                    $attraction['utilization'],
                ];
            }
            $csv[] = [];
        }

        // Event Performance
        if (isset($analytics['event_performance'])) {
            $csv[] = ['Event Performance'];
            $csv[] = ['Event', 'Date Type', 'Purchases', 'Tickets Sold', 'Revenue', 'Price'];
            foreach ($analytics['event_performance'] as $event) {
                $csv[] = [
                    $event['name'],
                    $event['date_type'],
                    $event['purchases'],
                    $event['tickets_sold'],
                    $event['revenue'],
                    $event['price'],
                ];
            }
            $csv[] = [];
        }

        // Convert to CSV string
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvString = stream_get_contents($output);
        fclose($output);

        $location = $analytics['location']['name'];
        $date = now()->format('Y-m-d');
        $filename = strtolower(str_replace(' ', '-', $location)) . "-analytics-{$date}.csv";

        return response($csvString, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
