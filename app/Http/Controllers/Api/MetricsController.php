<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\EventPurchase;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetricsController extends Controller
{
    use ScopesByAuthUser;

    public function dashboard(Request $request, $id)
    {
        try {
            $authUser = auth()->user();
            if (!$authUser) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }
            $user = $authUser;

            $cacheKey = 'dashboards:metrics:' . $user->id . ':' . $user->role . ':' . ($user->location_id ?? 'all')
                . ':' . md5(json_encode($request->query()));
            if (($cached = \App\Support\CacheGroups::get([\App\Support\CacheGroups::DASHBOARDS], $cacheKey)) !== null) {
                return response()->json($cached);
            }

            Log::info('=== Dashboard Metrics API Called ===', [
                'user_role' => $user->role,
                'user_location_id' => $user->location_id,
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
                'timeframe' => $request->query('timeframe'),
                'timestamp' => now()->toDateTimeString(),
            ]);

        $timeframe = $request->query('timeframe', 'all_time');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $useDateTime = false; // Flag to determine if we should use datetime or date-only comparison

        $timezone = $request->query('timezone', config('app.timezone', 'UTC'));
        try { new \DateTimeZone($timezone); } catch (\Exception $e) { $timezone = 'UTC'; }

        if ($dateFrom || $dateTo) {
            $timeframe = 'custom';
        } else {
            switch ($timeframe) {
                case 'today':
                    $dateFrom = \Carbon\Carbon::today($timezone)->setTimezone(config('app.timezone'));
                    $dateTo = \Carbon\Carbon::tomorrow($timezone)->subSecond()->setTimezone(config('app.timezone'));
                    $useDateTime = true;
                    break;
                case 'last_24h':
                    $dateFrom = now()->subHours(24);
                    $dateTo = now();
                    $useDateTime = true;
                    break;
                case 'last_7d':
                    $dateFrom = now()->subDays(7);
                    $dateTo = now();
                    $useDateTime = true;
                    break;
                case 'last_30d':
                    $dateFrom = now()->subDays(30);
                    $dateTo = now();
                    $useDateTime = true;
                    break;
                case 'custom':
                case 'all_time':
                default:
                    $dateFrom = null;
                    $dateTo = null;
                    break;
            }
        }

        $locationId = null;
        if (in_array($user->role, ['location_manager', 'attendant'])) {
            $locationId = $user->location_id;
        } else {
            // company_admin (or higher) may filter to a specific location; omitted/"all" = company-wide
            $requestedLocation = $request->query('location_id');
            if ($requestedLocation !== null && $requestedLocation !== '' && $requestedLocation !== 'all') {
                $locationId = (int) $requestedLocation;
            }
        }

        $bookingQuery = Booking::query();
        $purchaseQuery = AttractionPurchase::query();
        $eventPurchaseQuery = EventPurchase::query();

        if ($locationId) {
            $bookingQuery->where('bookings.location_id', $locationId);
            $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
            $eventPurchaseQuery->where('event_purchases.location_id', $locationId);
        }

        if ($dateFrom) {
            if ($useDateTime) {
                $bookingQuery->where('bookings.created_at', '>=', $dateFrom);
                $purchaseQuery->where('attraction_purchases.created_at', '>=', $dateFrom);
                $eventPurchaseQuery->where('event_purchases.created_at', '>=', $dateFrom);
            } else {
                $bookingQuery->whereDate('bookings.created_at', '>=', $dateFrom);
                $purchaseQuery->whereDate('attraction_purchases.created_at', '>=', $dateFrom);
                $eventPurchaseQuery->whereDate('event_purchases.created_at', '>=', $dateFrom);
            }
        }

        if ($dateTo) {
            if ($useDateTime) {
                $bookingQuery->where('bookings.created_at', '<=', $dateTo);
                $purchaseQuery->where('attraction_purchases.created_at', '<=', $dateTo);
                $eventPurchaseQuery->where('event_purchases.created_at', '<=', $dateTo);
            } else {
                $bookingQuery->whereDate('bookings.created_at', '<=', $dateTo);
                $purchaseQuery->whereDate('attraction_purchases.created_at', '<=', $dateTo);
                $eventPurchaseQuery->whereDate('event_purchases.created_at', '<=', $dateTo);
            }
        }

        $totalBookings = (clone $bookingQuery)->whereNotIn('bookings.status', ['cancelled'])->count();
        $confirmedBookings = (clone $bookingQuery)->where('bookings.status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('bookings.status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('bookings.status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('bookings.status', 'cancelled')->count();
        $checkedInBookings = (clone $bookingQuery)->where('bookings.status', 'checked-in')->count();
        $totalParticipants = (clone $bookingQuery)->whereNotIn('bookings.status', ['cancelled'])->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereNotIn('bookings.status', ['cancelled'])->sum('amount_paid') ?? 0;

        Log::info('Booking metrics calculated', [
            'total' => $totalBookings,
            'confirmed' => $confirmedBookings,
            'pending' => $pendingBookings,
            'completed' => $completedBookings,
            'cancelled' => $cancelledBookings,
            'participants' => $totalParticipants,
            'revenue' => $bookingRevenue,
        ]);

        $soldPurchaseQuery = (clone $purchaseQuery)->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded']);
        $totalPurchases = (clone $soldPurchaseQuery)->count();
        $totalAttractionTickets = (int) ((clone $soldPurchaseQuery)->sum('quantity') ?? 0);
        $purchaseRevenue = (clone $soldPurchaseQuery)->sum('amount_paid') ?? 0;

        Log::info('Purchase metrics calculated', [
            'total_purchases' => $totalPurchases,
            'total_tickets' => $totalAttractionTickets,
            'collected_revenue' => $purchaseRevenue,
        ]);

        $soldEventQuery = (clone $eventPurchaseQuery)->whereNotIn('event_purchases.status', ['cancelled', 'refunded']);
        $totalEventPurchases = (clone $soldEventQuery)->count();
        $eventPurchaseRevenue = (clone $soldEventQuery)->sum('amount_paid') ?? 0;
        $totalEventTickets = (int) ((clone $soldEventQuery)->sum('quantity') ?? 0);

        $totalRevenue = $bookingRevenue + $purchaseRevenue + $eventPurchaseRevenue;

        Log::info('Total revenue calculated', [
            'booking_revenue' => $bookingRevenue,
            'purchase_revenue' => $purchaseRevenue,
            'event_purchase_revenue' => $eventPurchaseRevenue,
            'total_revenue' => $totalRevenue,
        ]);

        // --- Membership metrics (graceful fallback if feature incomplete) ---
        $totalMemberships = 0;
        $activeMemberships = 0;
        $newMemberships = 0;
        $membershipBreakdownData = [];
        try {
            $membershipQuery = Membership::query();
            if ($locationId) {
                $membershipQuery->where(function ($q) use ($locationId) {
                    $q->where('home_location_id', $locationId)
                      ->orWhere('sold_at_location_id', $locationId);
                });
            }
            $newMembershipQuery = (clone $membershipQuery);
            if ($dateFrom) {
                $useDateTime
                    ? $newMembershipQuery->where('created_at', '>=', $dateFrom)
                    : $newMembershipQuery->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $useDateTime
                    ? $newMembershipQuery->where('created_at', '<=', $dateTo)
                    : $newMembershipQuery->whereDate('created_at', '<=', $dateTo);
            }
            $newMemberships    = $newMembershipQuery->count();
            $activeMemberships = (clone $membershipQuery)->where('status', 'active')->count();
            $totalMemberships  = $membershipQuery->count();

            $planCounts = [];
            foreach ($newMembershipQuery->with('plan:id,name,tier')->get() as $m) {
                $label = $m->plan?->name ?: ($m->plan?->tier ? ucfirst($m->plan->tier) : 'No Plan');
                $planCounts[$label] = ($planCounts[$label] ?? 0) + 1;
            }
            $mTotal = array_sum($planCounts);
            foreach ($planCounts as $label => $cnt) {
                $membershipBreakdownData[] = [
                    'label'      => $label,
                    'count'      => $cnt,
                    'percentage' => $mTotal > 0 ? round(($cnt / $mTotal) * 100) : 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Membership metrics unavailable', ['error' => $e->getMessage()]);
        }

        // --- Package (booking) breakdown by specific package ---
        $packageBreakdownData = [];
        try {
            $pkgRows = (clone $bookingQuery)
                ->leftJoin('packages', 'bookings.package_id', '=', 'packages.id')
                ->whereNotIn('bookings.status', ['cancelled'])
                ->select('packages.name', DB::raw('COUNT(*) as cnt'))
                ->groupBy('packages.name')
                ->orderByDesc('cnt')
                ->get();
            $pkgTotal = $pkgRows->sum('cnt');
            foreach ($pkgRows as $row) {
                $packageBreakdownData[] = [
                    'label'      => $row->name ?? 'Other',
                    'count'      => (int) $row->cnt,
                    'percentage' => $pkgTotal > 0 ? round(($row->cnt / $pkgTotal) * 100) : 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Package breakdown unavailable', ['error' => $e->getMessage()]);
        }

        // --- Party participants breakdown by package ---
        $participantBreakdownData = [];
        try {
            $partRows = (clone $bookingQuery)
                ->leftJoin('packages', 'bookings.package_id', '=', 'packages.id')
                ->whereNotIn('bookings.status', ['cancelled'])
                ->select('packages.name', DB::raw('SUM(bookings.participants) as cnt'))
                ->groupBy('packages.name')
                ->orderByDesc('cnt')
                ->get();
            $partTotal = $partRows->sum('cnt');
            foreach ($partRows as $row) {
                if ((int) $row->cnt === 0) {
                    continue;
                }
                $participantBreakdownData[] = [
                    'label'      => $row->name ?? 'Other',
                    'count'      => (int) $row->cnt,
                    'percentage' => $partTotal > 0 ? round(($row->cnt / $partTotal) * 100) : 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Participant breakdown unavailable', ['error' => $e->getMessage()]);
        }

        // --- Attraction breakdown by category ---
        $attractionBreakdownData = [];
        try {
            $attrRows = (clone $purchaseQuery)
                ->join('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
                ->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded'])
                ->select('attractions.category', DB::raw('SUM(attraction_purchases.quantity) as cnt'))
                ->groupBy('attractions.category')
                ->get();
            $attrTotal = $attrRows->sum('cnt');
            foreach ($attrRows as $row) {
                $attractionBreakdownData[] = [
                    'label'      => $row->category ?? 'Other',
                    'count'      => (int) $row->cnt,
                    'percentage' => $attrTotal > 0 ? round(($row->cnt / $attrTotal) * 100) : 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Attraction breakdown unavailable', ['error' => $e->getMessage()]);
        }

        // --- Event breakdown by event name ---
        $eventBreakdownData = [];
        try {
            $evtRows = (clone $eventPurchaseQuery)
                ->join('events', 'event_purchases.event_id', '=', 'events.id')
                ->whereNotIn('event_purchases.status', ['cancelled', 'refunded'])
                ->select('events.name', DB::raw('SUM(event_purchases.quantity) as cnt'))
                ->groupBy('events.name')
                ->get();
            $evtTotal = $evtRows->sum('cnt');
            foreach ($evtRows as $row) {
                $eventBreakdownData[] = [
                    'label'      => $row->name ?? 'Other',
                    'count'      => (int) $row->cnt,
                    'percentage' => $evtTotal > 0 ? round(($row->cnt / $evtTotal) * 100) : 0,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Event breakdown unavailable', ['error' => $e->getMessage()]);
        }

        // --- Confirmed bookings breakdown: packages vs events vs attractions ---
        $confirmedPackages = (clone $bookingQuery)->whereIn('bookings.status', ['confirmed', 'checked-in', 'completed'])->count();
        $confirmedAttractions = (clone $purchaseQuery)->whereIn('attraction_purchases.status', ['confirmed', 'checked-in'])->count();
        $confirmedEvents = (clone $eventPurchaseQuery)->whereIn('event_purchases.status', ['confirmed', 'checked-in', 'completed'])->count();
        $confirmedTotal = $confirmedPackages + $confirmedEvents + $confirmedAttractions;
        $confirmedBreakdownData = [
            ['label' => 'Packages',    'count' => $confirmedPackages,    'percentage' => $confirmedTotal > 0 ? round(($confirmedPackages    / $confirmedTotal) * 100) : 0],
            ['label' => 'Events',      'count' => $confirmedEvents,      'percentage' => $confirmedTotal > 0 ? round(($confirmedEvents      / $confirmedTotal) * 100) : 0],
            ['label' => 'Attractions', 'count' => $confirmedAttractions, 'percentage' => $confirmedTotal > 0 ? round(($confirmedAttractions / $confirmedTotal) * 100) : 0],
        ];

        $customerQuery = Customer::query();
        if ($locationId || $dateFrom || $dateTo) {
            $customerQuery->whereHas('bookings', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            })->orWhereHas('attractionPurchases', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->whereHas('attraction', function ($aq) use ($locationId) {
                        $aq->where('location_id', $locationId);
                    });
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            })->orWhereHas('eventPurchases', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            });
        }
        $totalCustomers = $customerQuery->count();

        // New vs returning: customers first created within the timeframe = new; rest = returning
        $newCustomers = 0;
        $returningCustomers = 0;
        if ($dateFrom) {
            $newCustomerQuery = Customer::query();
            if ($useDateTime) {
                $newCustomerQuery->where('created_at', '>=', $dateFrom);
            } else {
                $newCustomerQuery->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                if ($useDateTime) {
                    $newCustomerQuery->where('created_at', '<=', $dateTo);
                } else {
                    $newCustomerQuery->whereDate('created_at', '<=', $dateTo);
                }
            }
            if ($locationId) {
                $newCustomerQuery->where(function ($q) use ($locationId) {
                    $q->whereHas('bookings', fn($b) => $b->where('location_id', $locationId))
                      ->orWhereHas('attractionPurchases', fn($p) => $p->whereHas('attraction', fn($a) => $a->where('location_id', $locationId)))
                      ->orWhereHas('eventPurchases', fn($e) => $e->where('location_id', $locationId));
                });
            }
            $newCustomers = $newCustomerQuery->count();
            $returningCustomers = max(0, $totalCustomers - $newCustomers);
        } else {
            $returningCustomers = $totalCustomers;
        }
        $customerBreakdownData = [
            ['label' => 'New Customers',       'count' => $newCustomers,       'percentage' => $totalCustomers > 0 ? round(($newCustomers       / $totalCustomers) * 100) : 0],
            ['label' => 'Returning Customers', 'count' => $returningCustomers, 'percentage' => $totalCustomers > 0 ? round(($returningCustomers / $totalCustomers) * 100) : 0],
        ];

        Log::info('Customer count calculated', ['total_customers' => $totalCustomers, 'new' => $newCustomers, 'returning' => $returningCustomers]);

        $recentEventPurchasesQuery = EventPurchase::with(['customer', 'event']);
        if ($locationId) {
            $recentEventPurchasesQuery->where('location_id', $locationId);
        }
        if ($dateFrom) {
            if ($useDateTime) {
                $recentEventPurchasesQuery->where('created_at', '>=', $dateFrom);
            } else {
                $recentEventPurchasesQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }
        if ($dateTo) {
            if ($useDateTime) {
                $recentEventPurchasesQuery->where('created_at', '<=', $dateTo);
            } else {
                $recentEventPurchasesQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $recentEventPurchases = $recentEventPurchasesQuery
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'customer_name' => $purchase->customer
                        ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                        : $purchase->guest_name,
                    'event_name' => $purchase->event->name ?? null,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'amount_paid' => $purchase->amount_paid,
                    'status' => $purchase->status,
                    'purchase_date' => $purchase->purchase_date,
                    'created_at' => $purchase->created_at->toIso8601String(),
                ];
            });

        $recentPurchasesQuery = AttractionPurchase::with(['customer', 'attraction.location', 'createdBy']);
        if ($locationId) {
            $recentPurchasesQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
        }
        if ($dateFrom) {
            if ($useDateTime) {
                $recentPurchasesQuery->where('created_at', '>=', $dateFrom);
            } else {
                $recentPurchasesQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }
        if ($dateTo) {
            if ($useDateTime) {
                $recentPurchasesQuery->where('created_at', '<=', $dateTo);
            } else {
                $recentPurchasesQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $recentPurchases = $recentPurchasesQuery
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'customer_name' => $purchase->customer
                        ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                        : $purchase->guest_name,
                    'attraction_name' => $purchase->attraction->name ?? null,
                    'location_name' => $purchase->attraction->location->name ?? null,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'status' => $purchase->status,
                    'payment_method' => $purchase->payment_method,
                    'purchase_date' => $purchase->purchase_date,
                    'created_at' => $purchase->created_at->toIso8601String(),
                ];
            });

        $response = [
            'timeframe' => [
                'type' => $timeframe,
                'date_from' => $dateFrom ? ($useDateTime ? $dateFrom->toDateTimeString() : $dateFrom) : null,
                'date_to' => $dateTo ? ($useDateTime ? $dateTo->toDateTimeString() : $dateTo) : null,
                'description' => match($timeframe) {
                    'today'   => 'Today',
                    'last_24h' => 'Last 24 Hours',
                    'last_7d' => 'Last 7 Days',
                    'last_30d' => 'Last 30 Days',
                    'custom' => 'Custom Range',
                    default => 'All Time',
                },
            ],
            'metrics' => [
                'totalBookings' => $totalBookings,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCustomers' => $totalCustomers,
                'newCustomers' => $newCustomers,
                'returningCustomers' => $returningCustomers,
                'confirmedBookings' => $confirmedBookings,
                'pendingBookings' => $pendingBookings,
                'completedBookings' => $completedBookings,
                'cancelledBookings' => $cancelledBookings,
                'checkedInBookings' => $checkedInBookings,
                'totalParticipants' => (int) $totalParticipants,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($purchaseRevenue, 2),
                'purchaseRevenueCompleted' => round($purchaseRevenue, 2),
                'totalPurchases' => $totalPurchases,
                'totalAttractionTickets' => $totalAttractionTickets,
                'eventPurchaseRevenue' => round($eventPurchaseRevenue, 2),
                'totalEventPurchases' => $totalEventPurchases,
                'totalEventTickets' => (int) $totalEventTickets,
                'confirmedTotal' => $confirmedTotal,
                'totalMemberships' => $totalMemberships,
                'activeMemberships' => $activeMemberships,
                'newMemberships' => $newMemberships,
            ],
            'breakdowns' => [
                'packageBreakdown'   => $packageBreakdownData,
                'participantBreakdown' => $participantBreakdownData,
                'attractionBreakdown' => $attractionBreakdownData,
                'eventBreakdown'     => $eventBreakdownData,
                'membershipBreakdown' => $membershipBreakdownData,
                'customerBreakdown'  => $customerBreakdownData,
                'confirmedBreakdown' => $confirmedBreakdownData,
            ],
            'recentPurchases' => $recentPurchases,
            'recentEventPurchases' => $recentEventPurchases,
        ];

        if ($user->role === 'company_admin') {
            $locationStats = $this->getLocationStats($dateFrom, $dateTo, $useDateTime);
            $response['locationStats'] = $locationStats;
            Log::info('Added location stats for company_admin', ['locations_count' => count($locationStats)]);
        }

        if (in_array($user->role, ['location_manager', 'attendant']) && $locationId) {
            $location = Location::find($locationId);
            if ($location) {
                $response['locationDetails'] = [
                    'id' => $location->id,
                    'name' => $location->name,
                    'address' => $location->address,
                    'city' => $location->city,
                    'state' => $location->state,
                    'phone' => $location->phone,
                    'email' => $location->email,
                ];
                Log::info('Added location details for role', [
                    'role' => $user->role,
                    'location_name' => $location->name,
                ]);
            }
        }

        Log::info('=== Dashboard Metrics Response ===', [
            'user_role' => $user->role,
            'metrics_summary' => [
                'total_bookings' => $totalBookings,
                'total_revenue' => $totalRevenue,
                'total_customers' => $totalCustomers,
            ],
            'has_location_stats' => isset($response['locationStats']),
            'has_location_details' => isset($response['locationDetails']),
            'timestamp' => now()->toDateTimeString(),
        ]);

        \App\Support\CacheGroups::put([\App\Support\CacheGroups::DASHBOARDS], $cacheKey, $response, \App\Support\CacheGroups::TTL_DASHBOARD);

        return response()->json($response);

        } catch (\PDOException $e) {
            Log::error('Database error in dashboard metrics', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database connection limit exceeded. Please try again in a few minutes.',
                'error' => config('app.debug') ? $e->getMessage() : 'Database error'
            ], 503);
        } catch (\Exception $e) {
            Log::error('Error in dashboard metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    public function attendant(Request $request)
    {
        try {
            $locationId = $request->query('location_id');

            $cacheKey = 'dashboards:attendant:' . (auth()->id() ?? 'x') . ':' . md5(json_encode($request->query()));
            if (($cached = \App\Support\CacheGroups::get([\App\Support\CacheGroups::DASHBOARDS], $cacheKey)) !== null) {
                return response()->json($cached);
            }

            $timeframe = $request->query('timeframe', 'all_time');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $useDateTime = false; // Flag to determine if we should use datetime or date-only comparison

            $timezone = $request->query('timezone', config('app.timezone', 'UTC'));
            try { new \DateTimeZone($timezone); } catch (\Exception $e) { $timezone = 'UTC'; }

            if ($dateFrom || $dateTo) {
                $timeframe = 'custom';
            } else {
                switch ($timeframe) {
                    case 'today':
                        $dateFrom = \Carbon\Carbon::today($timezone)->setTimezone(config('app.timezone'));
                        $dateTo = \Carbon\Carbon::tomorrow($timezone)->subSecond()->setTimezone(config('app.timezone'));
                        $useDateTime = true;
                        break;
                    case 'last_24h':
                        $dateFrom = now()->subHours(24);
                        $dateTo = now();
                        $useDateTime = true;
                        break;
                    case 'last_7d':
                        $dateFrom = now()->subDays(7);
                        $dateTo = now();
                        $useDateTime = true;
                        break;
                    case 'last_30d':
                        $dateFrom = now()->subDays(30);
                        $dateTo = now();
                        $useDateTime = true;
                        break;
                    case 'custom':
                    case 'all_time':
                    default:
                        $dateFrom = null;
                        $dateTo = null;
                        break;
                }
            }

            Log::info('=== Attendant Metrics API Called ===', [
                'location_id' => $locationId,
                'timeframe' => $timeframe,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timestamp' => now()->toDateTimeString(),
            ]);

        $bookingQuery = Booking::query();
        $purchaseQuery = AttractionPurchase::query();
        $eventPurchaseQuery = EventPurchase::query();

        if ($locationId) {
            $bookingQuery->where('location_id', $locationId);
            $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
            $eventPurchaseQuery->where('location_id', $locationId);
            Log::info('Applied location filter', ['location_id' => $locationId]);
        }

        if ($dateFrom) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '>=', $dateFrom);
                $purchaseQuery->where('created_at', '>=', $dateFrom);
                $eventPurchaseQuery->where('created_at', '>=', $dateFrom);
            } else {
                $bookingQuery->whereDate('created_at', '>=', $dateFrom);
                $purchaseQuery->whereDate('created_at', '>=', $dateFrom);
                $eventPurchaseQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }

        if ($dateTo) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '<=', $dateTo);
                $purchaseQuery->where('created_at', '<=', $dateTo);
                $eventPurchaseQuery->where('created_at', '<=', $dateTo);
            } else {
                $bookingQuery->whereDate('created_at', '<=', $dateTo);
                $purchaseQuery->whereDate('created_at', '<=', $dateTo);
                $eventPurchaseQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $totalBookings = (clone $bookingQuery)->whereNotIn('status', ['cancelled'])->count();
        $confirmedBookings = (clone $bookingQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'cancelled')->count();
        $totalParticipants = (clone $bookingQuery)->whereNotIn('status', ['cancelled'])->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereNotIn('status', ['cancelled'])->sum('amount_paid') ?? 0;

        $soldPurchaseQuery = (clone $purchaseQuery)->whereNotIn('status', ['cancelled', 'refunded']);
        $totalPurchases = (clone $soldPurchaseQuery)->count();
        $totalAttractionTickets = (int) ((clone $soldPurchaseQuery)->sum('quantity') ?? 0);
        $purchaseRevenue = (clone $soldPurchaseQuery)->sum('amount_paid') ?? 0;

        $soldEventQuery = (clone $eventPurchaseQuery)->whereNotIn('status', ['cancelled', 'refunded']);
        $totalEventPurchases = (clone $soldEventQuery)->count();
        $eventPurchaseRevenue = (clone $soldEventQuery)->sum('amount_paid') ?? 0;
        $totalEventTickets = (int) ((clone $soldEventQuery)->sum('quantity') ?? 0);

        $totalRevenue = $bookingRevenue + $purchaseRevenue + $eventPurchaseRevenue;

        Log::info('Attendant purchase metrics calculated', [
            'total_purchases' => $totalPurchases,
            'total_tickets' => $totalAttractionTickets,
            'collected_revenue' => $purchaseRevenue,
            'event_purchase_revenue' => $eventPurchaseRevenue,
            'total_event_purchases' => $totalEventPurchases,
            'total_revenue' => $totalRevenue,
        ]);

        $customerQuery = Customer::query();
        if ($locationId || $dateFrom || $dateTo) {
            $customerQuery->whereHas('bookings', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            })->orWhereHas('attractionPurchases', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->whereHas('attraction', function ($aq) use ($locationId) {
                        $aq->where('location_id', $locationId);
                    });
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            })->orWhereHas('eventPurchases', function ($q) use ($locationId, $dateFrom, $dateTo, $useDateTime) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    if ($useDateTime) {
                        $q->where('created_at', '>=', $dateFrom);
                    } else {
                        $q->whereDate('created_at', '>=', $dateFrom);
                    }
                }
                if ($dateTo) {
                    if ($useDateTime) {
                        $q->where('created_at', '<=', $dateTo);
                    } else {
                        $q->whereDate('created_at', '<=', $dateTo);
                    }
                }
            });
        }
        $totalCustomers = $customerQuery->count();

        Log::info('Attendant metrics calculated', [
            'bookings' => $totalBookings,
            'purchases' => $totalPurchases,
            'event_purchases' => $totalEventPurchases,
            'revenue' => $totalRevenue,
            'customers' => $totalCustomers,
        ]);

        $recentEventPurchasesQuery = EventPurchase::with(['customer', 'event']);
        if ($locationId) {
            $recentEventPurchasesQuery->where('location_id', $locationId);
        }
        if ($dateFrom) {
            if ($useDateTime) {
                $recentEventPurchasesQuery->where('created_at', '>=', $dateFrom);
            } else {
                $recentEventPurchasesQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }
        if ($dateTo) {
            if ($useDateTime) {
                $recentEventPurchasesQuery->where('created_at', '<=', $dateTo);
            } else {
                $recentEventPurchasesQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $recentEventPurchases = $recentEventPurchasesQuery
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'customer_name' => $purchase->customer
                        ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                        : $purchase->guest_name,
                    'customer_email' => $purchase->customer
                        ? $purchase->customer->email
                        : $purchase->guest_email,
                    'event_name' => $purchase->event->name ?? null,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'amount_paid' => $purchase->amount_paid,
                    'status' => $purchase->status,
                    'purchase_date' => $purchase->purchase_date,
                    'created_at' => $purchase->created_at->toIso8601String(),
                ];
            });

        $recentPurchasesQuery = AttractionPurchase::with(['customer', 'attraction.location', 'createdBy']);
        if ($locationId) {
            $recentPurchasesQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
        }
        if ($dateFrom) {
            if ($useDateTime) {
                $recentPurchasesQuery->where('created_at', '>=', $dateFrom);
            } else {
                $recentPurchasesQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }
        if ($dateTo) {
            if ($useDateTime) {
                $recentPurchasesQuery->where('created_at', '<=', $dateTo);
            } else {
                $recentPurchasesQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $recentPurchases = $recentPurchasesQuery
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'customer_name' => $purchase->customer
                        ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                        : $purchase->guest_name,
                    'customer_email' => $purchase->customer
                        ? $purchase->customer->email
                        : $purchase->guest_email,
                    'attraction_name' => $purchase->attraction->name ?? null,
                    'location_name' => $purchase->attraction->location->name ?? null,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'status' => $purchase->status,
                    'payment_method' => $purchase->payment_method,
                    'purchase_date' => $purchase->purchase_date,
                    'created_at' => $purchase->created_at->toIso8601String(),
                ];
            });

        $recentBookingsQuery = Booking::with(['customer', 'package', 'location', 'room']);
        if ($locationId) {
            $recentBookingsQuery->where('location_id', $locationId);
        }
        if ($dateFrom) {
            if ($useDateTime) {
                $recentBookingsQuery->where('created_at', '>=', $dateFrom);
            } else {
                $recentBookingsQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }
        if ($dateTo) {
            if ($useDateTime) {
                $recentBookingsQuery->where('created_at', '<=', $dateTo);
            } else {
                $recentBookingsQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        $recentBookings = $recentBookingsQuery
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'customer_name' => $booking->customer
                        ? $booking->customer->first_name . ' ' . $booking->customer->last_name
                        : $booking->guest_name,
                    'customer_email' => $booking->customer
                        ? $booking->customer->email
                        : $booking->guest_email,
                    'package_name' => $booking->package->name ?? null,
                    'location_name' => $booking->location->name ?? null,
                    'room_name' => $booking->room->name ?? null,
                    'booking_date' => $booking->booking_date,
                    'booking_time' => $booking->booking_time,
                    'participants' => $booking->participants,
                    'total_amount' => $booking->total_amount,
                    'status' => $booking->status,
                    'payment_status' => $booking->payment_status,
                    'payment_method' => $booking->payment_method,
                    'created_at' => $booking->created_at->toIso8601String(),
                ];
            });

        Log::info('Recent transactions fetched', [
            'purchases_count' => $recentPurchases->count(),
            'bookings_count' => $recentBookings->count(),
            'event_purchases_count' => $recentEventPurchases->count(),
        ]);

        $response = [
            'timeframe' => [
                'type' => $timeframe,
                'date_from' => $dateFrom ? ($useDateTime ? $dateFrom->toDateTimeString() : $dateFrom) : null,
                'date_to' => $dateTo ? ($useDateTime ? $dateTo->toDateTimeString() : $dateTo) : null,
                'description' => match($timeframe) {
                    'today'   => 'Today',
                    'last_24h' => 'Last 24 Hours',
                    'last_7d' => 'Last 7 Days',
                    'last_30d' => 'Last 30 Days',
                    'custom' => 'Custom Range',
                    default => 'All Time',
                },
            ],
            'metrics' => [
                'totalBookings' => $totalBookings,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCustomers' => $totalCustomers,
                'confirmedBookings' => $confirmedBookings,
                'pendingBookings' => $pendingBookings,
                'completedBookings' => $completedBookings,
                'cancelledBookings' => $cancelledBookings,
                'totalParticipants' => (int) $totalParticipants,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($purchaseRevenue, 2),
                'purchaseRevenueCompleted' => round($purchaseRevenue, 2),
                'totalPurchases' => $totalPurchases,
                'totalAttractionTickets' => $totalAttractionTickets,
                'eventPurchaseRevenue' => round($eventPurchaseRevenue, 2),
                'totalEventPurchases' => $totalEventPurchases,
                'totalEventTickets' => (int) $totalEventTickets,
            ],
            'recentPurchases' => $recentPurchases,
            'recentEventPurchases' => $recentEventPurchases,
            'recentBookings' => $recentBookings,
        ];

        Log::info('=== Attendant Metrics Response ===', [
            'metrics_summary' => [
                'total_bookings' => $totalBookings,
                'total_revenue' => $totalRevenue,
                'total_customers' => $totalCustomers,
            ],
            'recent_items' => [
                'purchases' => $recentPurchases->count(),
                'bookings' => $recentBookings->count(),
            ],
            'timestamp' => now()->toDateTimeString(),
        ]);

        \App\Support\CacheGroups::put([\App\Support\CacheGroups::DASHBOARDS], $cacheKey, $response, \App\Support\CacheGroups::TTL_DASHBOARD);

        return response()->json($response);

        } catch (\PDOException $e) {
            Log::error('Database error in attendant metrics', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database connection limit exceeded. Please try again in a few minutes.',
                'error' => config('app.debug') ? $e->getMessage() : 'Database error'
            ], 503);
        } catch (\Exception $e) {
            Log::error('Error in attendant metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attendant metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    private function getLocationStats($dateFrom = null, $dateTo = null, $useDateTime = false)
    {
        $applyRange = function ($query, $column) use ($dateFrom, $dateTo, $useDateTime) {
            if ($dateFrom) {
                $useDateTime
                    ? $query->where($column, '>=', $dateFrom)
                    : $query->whereDate($column, '>=', is_string($dateFrom) ? $dateFrom : $dateFrom->toDateString());
            }
            if ($dateTo) {
                $useDateTime
                    ? $query->where($column, '<=', $dateTo)
                    : $query->whereDate($column, '<=', is_string($dateTo) ? $dateTo : $dateTo->toDateString());
            }
            return $query;
        };

        $bookingRows = $applyRange(Booking::query(), 'created_at')
            ->whereNotIn('status', ['cancelled'])
            ->select(
                'location_id',
                DB::raw('COUNT(*) as bookings_count'),
                DB::raw('COALESCE(SUM(participants), 0) as participants_sum'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as revenue_sum')
            )
            ->groupBy('location_id')
            ->get()
            ->keyBy('location_id');

        $purchaseRows = $applyRange(AttractionPurchase::query(), 'attraction_purchases.created_at')
            ->join('attractions', 'attraction_purchases.attraction_id', '=', 'attractions.id')
            ->whereNotIn('attraction_purchases.status', ['cancelled', 'refunded'])
            ->select(
                'attractions.location_id',
                DB::raw('COUNT(*) as purchases_count'),
                DB::raw('COALESCE(SUM(attraction_purchases.quantity), 0) as tickets_sum'),
                DB::raw('COALESCE(SUM(attraction_purchases.amount_paid), 0) as revenue_sum')
            )
            ->groupBy('attractions.location_id')
            ->get()
            ->keyBy('location_id');

        $eventRows = $applyRange(EventPurchase::query(), 'created_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->select(
                'location_id',
                DB::raw('COUNT(*) as purchases_count'),
                DB::raw('COALESCE(SUM(quantity), 0) as tickets_sum'),
                DB::raw('COALESCE(SUM(amount_paid), 0) as revenue_sum')
            )
            ->groupBy('location_id')
            ->get()
            ->keyBy('location_id');

        if ($dateFrom && $dateTo) {
            $daysInRange = max(1, (int) \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) + 1);
        } elseif ($dateFrom) {
            $daysInRange = max(1, (int) \Carbon\Carbon::parse($dateFrom)->diffInDays(now()) + 1);
        } else {
            $daysInRange = 30;
        }

        $locationStats = [];
        $locations = Location::where('is_active', true)->get();

        Log::info('Processing location stats', ['locations_count' => $locations->count()]);

        foreach ($locations as $location) {
            $booking = $bookingRows->get($location->id);
            $purchase = $purchaseRows->get($location->id);
            $event = $eventRows->get($location->id);

            $bookingRevenue = (float) ($booking->revenue_sum ?? 0);
            $purchaseRevenue = (float) ($purchase->revenue_sum ?? 0);
            $eventRevenue = (float) ($event->revenue_sum ?? 0);

            $totalParticipants = (int) ($booking->participants_sum ?? 0)
                + (int) ($purchase->tickets_sum ?? 0)
                + (int) ($event->tickets_sum ?? 0);

            $estimatedCapacity = $daysInRange * 100;
            $utilization = min(100, $estimatedCapacity > 0 ? round(($totalParticipants / $estimatedCapacity) * 100) : 0);

            $locationStats[$location->id] = [
                'name' => $location->name,
                'bookings' => (int) ($booking->bookings_count ?? 0),
                'purchases' => (int) ($purchase->purchases_count ?? 0),
                'attractionTickets' => (int) ($purchase->tickets_sum ?? 0),
                'eventPurchases' => (int) ($event->purchases_count ?? 0),
                'eventTickets' => (int) ($event->tickets_sum ?? 0),
                'revenue' => round($bookingRevenue + $purchaseRevenue + $eventRevenue, 2),
                'participants' => $totalParticipants,
                'utilization' => $utilization,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($purchaseRevenue, 2),
                'eventPurchaseRevenue' => round($eventRevenue, 2),
            ];
        }

        return $locationStats;
    }
}
