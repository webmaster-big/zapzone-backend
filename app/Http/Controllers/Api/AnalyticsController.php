<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\Package;
use App\Models\Attraction;
use App\Models\Location;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Get comprehensive analytics for company-wide view
     * Includes all locations with aggregated data
     */
    public function getCompanyAnalytics(Request $request)
    {
        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'date_range' => 'in:7d,30d,90d,1y',
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'exists:locations,id',
        ]);

        $companyId = $request->company_id;
        $dateRange = $request->date_range ?? '30d';
        $locationIds = $request->location_ids ?? [];

        // Calculate date range
        $startDate = $this->getStartDate($dateRange);
        $endDate = now();

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
            'key_metrics' => $this->getCompanyKeyMetrics($locationIdList, $startDate, $endDate),
            'revenue_trend' => $this->getRevenueTrend($locationIdList, $startDate, $endDate),
            'location_performance' => $this->getLocationPerformance($locationIdList, $startDate, $endDate),
            'package_distribution' => $this->getPackageDistribution($locationIdList, $startDate, $endDate),
            'peak_hours' => $this->getCompanyPeakHours($locationIdList, $startDate, $endDate),
            'daily_performance' => $this->getCompanyDailyPerformance($locationIdList, $startDate, $endDate),
            'booking_status' => $this->getBookingStatus($locationIdList, $startDate, $endDate),
            'top_attractions' => $this->getTopAttractions($locationIdList, $startDate, $endDate),
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
            'date_range' => 'in:7d,30d,90d,1y',
        ]);

        $locationId = $request->location_id;
        $dateRange = $request->date_range ?? '30d';

        // Calculate date range
        $startDate = $this->getStartDate($dateRange);
        $endDate = now();

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
     */
    private function getKeyMetrics($locationId, $startDate, $endDate)
    {
        // Get bookings data
        $bookings = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalBookingRevenue = $bookings->sum('total_amount');
        $totalBookings = $bookings->count();
        $totalParticipants = $bookings->sum('participants');

        // Get attraction purchases data
        $attractionPurchases = AttractionPurchase::byLocation($locationId)
            ->whereBetween('purchase_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalAttractionRevenue = $attractionPurchases->sum('total_amount');
        $totalTicketsSold = $attractionPurchases->sum('quantity');

        // Combined totals
        $totalRevenue = $totalBookingRevenue + $totalAttractionRevenue;

        // Active counts
        $activePackages = Package::where('location_id', $locationId)
            ->where('is_active', true)
            ->count();

        $totalPackages = Package::where('location_id', $locationId)->count();

        $activeAttractions = Attraction::where('location_id', $locationId)
            ->where('is_active', true)
            ->count();

        $totalAttractions = Attraction::where('location_id', $locationId)->count();

        // Calculate previous period for comparison
        $prevStartDate = $startDate->copy()->sub($endDate->diffInDays($startDate), 'days');
        $prevEndDate = $startDate->copy();

        $prevBookings = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevAttractionPurchases = AttractionPurchase::byLocation($locationId)
            ->whereBetween('purchase_date', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevTotalRevenue = $prevBookings->sum('total_amount') + $prevAttractionPurchases->sum('total_amount');
        $prevTotalBookings = $prevBookings->count();
        $prevTotalTickets = $prevAttractionPurchases->sum('quantity');
        $prevTotalParticipants = $prevBookings->sum('participants');

        // Determine operational status messages
        $packageStatus = $activePackages === $totalPackages ? 'All operational' :
                         ($activePackages === 0 ? 'None operational' :
                         ($totalPackages - $activePackages) . ' inactive');

        $attractionStatus = $activeAttractions === $totalAttractions ? 'All operational' :
                            ($activeAttractions === 0 ? 'None operational' :
                            ($totalAttractions - $activeAttractions) . ' inactive');

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
            'total_visitors' => [
                'value' => $totalParticipants + $totalTicketsSold,
                'change' => $this->calculatePercentageChange(
                    $totalParticipants + $totalTicketsSold,
                    $prevTotalParticipants + $prevTotalTickets
                ),
                'trend' => ($totalParticipants + $totalTicketsSold) >= ($prevTotalParticipants + $prevTotalTickets) ? 'up' : 'down',
            ],
            'active_packages' => [
                'value' => $activePackages,
                'info' => $packageStatus,
            ],
            'active_attractions' => [
                'value' => $activeAttractions,
                'info' => $attractionStatus,
            ],
        ];
    }

    /**
     * Get hourly revenue pattern (last 24 hours or average for period)
     */
    private function getHourlyRevenue($locationId, $startDate, $endDate)
    {
        $hourlyData = [];

        // Get bookings grouped by hour
        $bookingsByHour = Booking::where('location_id', $locationId)
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('HOUR(booking_time) as hour'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        // Generate data for each hour (9 AM to 9 PM)
        for ($hour = 9; $hour <= 21; $hour++) {
            $hourData = $bookingsByHour->get($hour);

            $hourlyData[] = [
                'hour' => sprintf('%02d:00', $hour),
                'revenue' => $hourData ? round($hourData->revenue, 2) : 0,
                'bookings' => $hourData ? $hourData->bookings : 0,
            ];
        }

        return $hourlyData;
    }

    /**
     * Get daily revenue for last 7 days
     */
    private function getDailyRevenue($locationId, $startDate, $endDate)
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dailyData = [];

        // Get last 7 days
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dayOfWeek = $days[$date->dayOfWeekIso - 1];

            // Get bookings for this day
            $bookingsRevenue = Booking::where('location_id', $locationId)
                ->whereDate('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount');

            $bookingsParticipants = Booking::where('location_id', $locationId)
                ->whereDate('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('participants');

            // Get attraction purchases for this day
            $attractionsRevenue = AttractionPurchase::byLocation($locationId)
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount');

            $attractionsTickets = AttractionPurchase::byLocation($locationId)
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('quantity');

            $dailyData[] = [
                'day' => $dayOfWeek,
                'date' => $date->toDateString(),
                'revenue' => round($bookingsRevenue + $attractionsRevenue, 2),
                'participants' => $bookingsParticipants + $attractionsTickets,
            ];
        }

        return $dailyData;
    }

    /**
     * Get weekly trend (last 5 weeks)
     */
    private function getWeeklyTrend($locationId, $startDate, $endDate)
    {
        $weeklyData = [];

        for ($week = 4; $week >= 0; $week--) {
            $weekStart = now()->subWeeks($week)->startOfWeek();
            $weekEnd = now()->subWeeks($week)->endOfWeek();

            // Bookings for this week
            $weekBookings = Booking::where('location_id', $locationId)
                ->whereBetween('booking_date', [$weekStart, $weekEnd])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            // Attraction purchases for this week
            $weekAttractions = AttractionPurchase::byLocation($locationId)
                ->whereBetween('purchase_date', [$weekStart, $weekEnd])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            $weeklyData[] = [
                'week' => 'Week ' . (5 - $week),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'revenue' => round($weekBookings->sum('total_amount') + $weekAttractions->sum('total_amount'), 2),
                'bookings' => $weekBookings->count(),
                'tickets' => $weekAttractions->sum('quantity'),
            ];
        }

        return $weeklyData;
    }

    /**
     * Get package performance
     */
    private function getPackagePerformance($locationId, $startDate, $endDate)
    {
        $packages = Package::where('location_id', $locationId)
            ->where('is_active', true)
            ->withCount([
                'bookings as bookings_count' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('booking_date', [$startDate, $endDate])
                          ->whereNotIn('status', ['cancelled']);
                }
            ])
            ->withSum([
                'bookings as total_revenue' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('booking_date', [$startDate, $endDate])
                          ->whereNotIn('status', ['cancelled']);
                }
            ], 'total_amount')
            ->withSum([
                'bookings as total_participants' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('booking_date', [$startDate, $endDate])
                          ->whereNotIn('status', ['cancelled']);
                }
            ], 'participants')
            ->withAvg([
                'bookings as avg_party_size' => function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('booking_date', [$startDate, $endDate])
                          ->whereNotIn('status', ['cancelled']);
                }
            ], 'participants')
            ->having('bookings_count', '>', 0)
            ->orderByDesc('total_revenue')
            ->get();

        return $packages->map(function ($package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'category' => $package->category,
                'bookings' => $package->bookings_count ?? 0,
                'revenue' => round($package->total_revenue ?? 0, 2),
                'participants' => $package->total_participants ?? 0,
                'avg_party_size' => round($package->avg_party_size ?? 0, 1),
                'price' => round($package->price, 2),
            ];
        })->values();
    }

    /**
     * Get attraction performance (ticket sales)
     */
    private function getAttractionPerformance($locationId, $startDate, $endDate)
    {
        $attractions = Attraction::where('location_id', $locationId)
            ->where('is_active', true)
            ->get();

        return $attractions->map(function ($attraction) use ($startDate, $endDate) {
            $purchases = AttractionPurchase::where('attraction_id', $attraction->id)
                ->whereBetween('purchase_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            $sessions = $purchases->count();
            $ticketsSold = $purchases->sum('quantity');
            $revenue = $purchases->sum('total_amount');

            // Calculate utilization as percentage (assuming max_capacity is daily capacity)
            // This is a simplified calculation - adjust based on actual business logic
            $daysInPeriod = now()->diffInDays($startDate) ?: 1;
            $maxPossibleSessions = $attraction->max_capacity * $daysInPeriod;
            $utilization = $maxPossibleSessions > 0
                ? min(100, round(($ticketsSold / $maxPossibleSessions) * 100, 1))
                : 0;

            return [
                'id' => $attraction->id,
                'name' => $attraction->name,
                'category' => $attraction->category,
                'sessions' => $sessions,
                'tickets_sold' => $ticketsSold,
                'revenue' => round($revenue, 2),
                'utilization' => $utilization,
                'price' => round($attraction->price, 2),
                'max_capacity' => $attraction->max_capacity,
            ];
        })->sortByDesc('revenue')->values();
    }

    /**
     * Get time slot performance
     */
    private function getTimeSlotPerformance($locationId, $startDate, $endDate)
    {
        $timeSlots = [
            ['name' => 'Morning (9-12)', 'start' => 9, 'end' => 12],
            ['name' => 'Afternoon (12-6)', 'start' => 12, 'end' => 18],
            ['name' => 'Evening (6-9)', 'start' => 18, 'end' => 21],
        ];

        $slotData = [];

        foreach ($timeSlots as $slot) {
            // Get bookings in this time slot
            $bookings = Booking::where('location_id', $locationId)
                ->whereBetween('booking_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->whereRaw('HOUR(booking_time) >= ?', [$slot['start']])
                ->whereRaw('HOUR(booking_time) < ?', [$slot['end']])
                ->get();

            $bookingsCount = $bookings->count();
            $bookingsRevenue = $bookings->sum('total_amount');

            $slotData[] = [
                'slot' => $slot['name'],
                'bookings' => $bookingsCount,
                'revenue' => round($bookingsRevenue, 2),
                'avg_value' => $bookingsCount > 0 ? round($bookingsRevenue / $bookingsCount, 2) : 0,
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
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalBookingRevenue = $bookings->sum('total_amount');
        $totalBookings = $bookings->count();
        $totalParticipants = $bookings->sum('participants');

        // Get attraction purchases for all locations
        $attractionPurchases = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                $query->whereIn('location_id', $locationIds);
            })
            ->whereBetween('purchase_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalAttractionRevenue = $attractionPurchases->sum('total_amount');
        $totalTicketsSold = $attractionPurchases->sum('quantity');

        // Combined totals
        $totalRevenue = $totalBookingRevenue + $totalAttractionRevenue;
        $totalLocations = count($locationIds);

        // Active counts
        $activePackages = Package::whereIn('location_id', $locationIds)
            ->where('is_active', true)
            ->count();

        // Previous period comparison
        $prevStartDate = $startDate->copy()->sub($endDate->diffInDays($startDate), 'days');
        $prevEndDate = $startDate->copy();

        $prevBookings = Booking::whereIn('location_id', $locationIds)
            ->whereBetween('booking_date', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevAttractionPurchases = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                $query->whereIn('location_id', $locationIds);
            })
            ->whereBetween('purchase_date', [$prevStartDate, $prevEndDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $prevTotalRevenue = $prevBookings->sum('total_amount') + $prevAttractionPurchases->sum('total_amount');
        $prevTotalBookings = $prevBookings->count();
        $prevTotalTickets = $prevAttractionPurchases->sum('quantity');
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
            'total_participants' => [
                'value' => $totalParticipants + $totalTicketsSold,
                'change' => $this->calculatePercentageChange(
                    $totalParticipants + $totalTicketsSold,
                    $prevTotalParticipants + $prevTotalTickets
                ),
                'trend' => ($totalParticipants + $totalTicketsSold) >= ($prevTotalParticipants + $prevTotalTickets) ? 'up' : 'down',
            ],
            'active_packages' => [
                'value' => $activePackages,
                'info' => $newPackages . ' new packages',
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
                    ->whereBetween('booking_date', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount') ?? 0;

                $bookingsCount = Booking::whereIn('location_id', $locationIds)
                    ->whereBetween('booking_date', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->count() ?? 0;

                $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                        $query->whereIn('location_id', $locationIds);
                    })
                    ->whereBetween('purchase_date', [$monthStart, $monthEnd])
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount') ?? 0;

                $trendData[] = [
                    'month' => $monthStart->format('M y'),
                    'revenue' => round($bookingsRevenue + $attractionRevenue, 2),
                    'bookings' => $bookingsCount,
                ];

                $currentMonth->addMonth();
            }
        } else {
            // Show daily data for the specified date range
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                $bookingsRevenue = Booking::whereIn('location_id', $locationIds)
                    ->whereDate('booking_date', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount') ?? 0;

                $bookingsCount = Booking::whereIn('location_id', $locationIds)
                    ->whereDate('booking_date', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->count() ?? 0;

                $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                        $query->whereIn('location_id', $locationIds);
                    })
                    ->whereDate('purchase_date', $currentDate)
                    ->whereNotIn('status', ['cancelled'])
                    ->sum('total_amount') ?? 0;

                $trendData[] = [
                    'month' => $currentDate->format('M d'),
                    'revenue' => round($bookingsRevenue + $attractionRevenue, 2),
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
                ->whereBetween('booking_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount') ?? 0;

            $bookingsCount = Booking::where('location_id', $location->id)
                ->whereBetween('booking_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->count() ?? 0;

            $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($location) {
                    $query->where('location_id', $location->id);
                })
                ->whereBetween('purchase_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount') ?? 0;

            $totalRevenue = $bookingsRevenue + $attractionRevenue;

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
            ->whereBetween('booking_date', [$startDate, $endDate])
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
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->select(
                DB::raw('HOUR(booking_time) as hour'),
                DB::raw('COUNT(*) as bookings')
            )
            ->groupBy('hour')
            ->get()
            ->keyBy('hour');

        for ($hour = 9; $hour <= 21; $hour++) {
            $hourData = $bookingsByHour->get($hour);

            $hourlyData[] = [
                'hour' => sprintf('%02d:00', $hour),
                'bookings' => $hourData ? $hourData->bookings : 0,
            ];
        }

        return $hourlyData;
    }

    /**
     * Get daily performance for last 7 days
     */
    private function getCompanyDailyPerformance($locationIds, $startDate, $endDate)
    {
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $dailyData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dayOfWeek = $days[$date->dayOfWeekIso - 1];

            $bookingsRevenue = Booking::whereIn('location_id', $locationIds)
                ->whereDate('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount');

            $bookingsParticipants = Booking::whereIn('location_id', $locationIds)
                ->whereDate('booking_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('participants');

            $attractionRevenue = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                    $query->whereIn('location_id', $locationIds);
                })
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('total_amount');

            $attractionTickets = AttractionPurchase::whereHas('attraction', function ($query) use ($locationIds) {
                    $query->whereIn('location_id', $locationIds);
                })
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled'])
                ->sum('quantity');

            $dailyData[] = [
                'day' => $dayOfWeek,
                'date' => $date->toDateString(),
                'revenue' => round($bookingsRevenue + $attractionRevenue, 2),
                'participants' => $bookingsParticipants + $attractionTickets,
            ];
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
            ->whereBetween('booking_date', [$startDate, $endDate])
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
                ->whereBetween('purchase_date', [$startDate, $endDate])
                ->whereNotIn('status', ['cancelled'])
                ->get();

            return [
                'id' => $attraction->id,
                'name' => $attraction->name,
                'tickets_sold' => $purchases->sum('quantity') ?? 0,
                'revenue' => round($purchases->sum('total_amount') ?? 0, 2),
            ];
        })->filter(function ($item) {
            // Only include attractions with sales
            return $item['revenue'] > 0 || $item['tickets_sold'] > 0;
        })->sortByDesc('revenue')->take(10)->values();

        return $attractionData;
    }

    /**
     * Export analytics data
     */
    public function exportAnalytics(Request $request)
    {
        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date_range' => 'in:7d,30d,90d,1y',
            'format' => 'in:json,csv',
            'sections' => 'array',
            'sections.*' => 'in:metrics,revenue,packages,attractions,timeslots',
        ]);

        // Get the full analytics data
        $analyticsRequest = new Request([
            'location_id' => $request->location_id,
            'date_range' => $request->date_range ?? '30d',
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
