<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetricsController extends Controller
{
    /**
     * Get dashboard metrics based on authenticated user's role and location
     * - company_admin: All locations (no location filter)
     * - location_manager: Their specific location only
     * - attendant: Their specific location only
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboard(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Log incoming request for debugging
            Log::info('=== Dashboard Metrics API Called ===', [
                'user_role' => $user->role,
                'user_location_id' => $user->location_id,
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
                'timeframe' => $request->query('timeframe'),
                'timestamp' => now()->toDateTimeString(),
            ]);

        // Timeframe selector: supports 'last_24h', 'last_7d', 'last_30d', 'all_time', or 'custom'
        // Custom range uses date_from and date_to parameters
        $timeframe = $request->query('timeframe', 'all_time');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $useDateTime = false; // Flag to determine if we should use datetime or date-only comparison

        // If custom dates are provided, use them and set timeframe to 'custom'
        if ($dateFrom || $dateTo) {
            $timeframe = 'custom';
        } else {
            // Apply timeframe preset
            switch ($timeframe) {
                case 'last_24h':
                    $dateFrom = now()->subHours(24);
                    $dateTo = now();
                    $useDateTime = true; // Use datetime comparison for precise 24-hour window
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
                    // No date filter - all time metrics
                    $dateFrom = null;
                    $dateTo = null;
                    break;
            }
        }

        // Determine location filter based on user role
        $locationId = null;
        if (in_array($user->role, ['location_manager', 'attendant'])) {
            $locationId = $user->location_id;
        }

        // Build booking query
        $bookingQuery = Booking::query();
        $purchaseQuery = AttractionPurchase::query();

        // Apply location filter based on role
        if ($locationId) {
            $bookingQuery->where('location_id', $locationId);
            $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
        }

        // Apply date range filter
        if ($dateFrom) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '>=', $dateFrom);
                $purchaseQuery->where('created_at', '>=', $dateFrom);
            } else {
                $bookingQuery->whereDate('created_at', '>=', $dateFrom);
                $purchaseQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }

        if ($dateTo) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '<=', $dateTo);
                $purchaseQuery->where('created_at', '<=', $dateTo);
            } else {
                $bookingQuery->whereDate('created_at', '<=', $dateTo);
                $purchaseQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        // Calculate booking metrics
        $totalBookings = $bookingQuery->count();
        $confirmedBookings = (clone $bookingQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'cancelled')->count();
        $checkedInBookings = (clone $bookingQuery)->where('status', 'checked-in')->count();
        $totalParticipants = (clone $bookingQuery)->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereNotIn('status', ['cancelled'])->sum('amount_paid') ?? 0;

        Log::info('Booking metrics calculated', [
            'total' => $totalBookings,
            'confirmed' => $confirmedBookings,
            'pending' => $pendingBookings,
            'completed' => $completedBookings,
            'cancelled' => $cancelledBookings,
            'participants' => $totalParticipants,
            'revenue' => $bookingRevenue,
        ]);

        // Calculate purchase metrics
        $totalPurchases = $purchaseQuery->count();

        // Get all purchases for debugging
        $allPurchaseStatuses = (clone $purchaseQuery)->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as revenue'))
            ->groupBy('status')
            ->get();

        // Revenue from completed purchases only
        $purchaseRevenue = (clone $purchaseQuery)->where('status', 'completed')->sum('amount_paid') ?? 0;

        // All non-cancelled purchases revenue
        $allPurchaseRevenue = (clone $purchaseQuery)->whereNotIn('status', ['cancelled'])->sum('amount_paid') ?? 0;

        Log::info('Purchase metrics calculated', [
            'total_purchases' => $totalPurchases,
            'completed_revenue' => $purchaseRevenue,
            'all_non_cancelled_revenue' => $allPurchaseRevenue,
            'status_breakdown' => $allPurchaseStatuses->toArray(),
        ]);

        // Calculate total revenue (using all non-cancelled purchases)
        $totalRevenue = $bookingRevenue + $allPurchaseRevenue;

        Log::info('Total revenue calculated', [
            'booking_revenue' => $bookingRevenue,
            'purchase_revenue' => $allPurchaseRevenue,
            'total_revenue' => $totalRevenue,
        ]);

        // Get unique customers count
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
            });
        }
        $totalCustomers = $customerQuery->count();

        Log::info('Customer count calculated', ['total_customers' => $totalCustomers]);

        // Get recent purchases (last 5-10)
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
                    'last_24h' => 'Last 24 Hours',
                    'last_7d' => 'Last 7 Days',
                    'last_30d' => 'Last 30 Days',
                    'custom' => 'Custom Range',
                    default => 'All Time',
                },
            ],
            'metrics' => [
                // All counts below are filtered by the selected timeframe
                // If timeframe is 'all_time', counts represent all historical data
                'totalBookings' => $totalBookings,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCustomers' => $totalCustomers,
                'confirmedBookings' => $confirmedBookings,  // Filtered by timeframe
                'pendingBookings' => $pendingBookings,      // Filtered by timeframe
                'completedBookings' => $completedBookings,  // Filtered by timeframe
                'cancelledBookings' => $cancelledBookings,  // Filtered by timeframe
                'checkedInBookings' => $checkedInBookings,  // Filtered by timeframe
                'totalParticipants' => (int) $totalParticipants,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($allPurchaseRevenue, 2),
                'purchaseRevenueCompleted' => round($purchaseRevenue, 2),
                'totalPurchases' => $totalPurchases,
            ],
            'recentPurchases' => $recentPurchases,
        ];

        // Add location stats for company_admin
        if ($user->role === 'company_admin') {
            $locationStats = $this->getLocationStats($dateFrom, $dateTo);
            $response['locationStats'] = $locationStats;
            Log::info('Added location stats for company_admin', ['locations_count' => count($locationStats)]);
        }

        // Add location details for manager/attendant
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

    /**
     * Get metrics for attendant view with recent transactions
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function attendant(Request $request)
    {
        try {
            $locationId = $request->query('location_id');

            // Timeframe selector: supports 'last_24h', 'last_7d', 'last_30d', 'all_time', or 'custom'
            $timeframe = $request->query('timeframe', 'all_time');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $useDateTime = false; // Flag to determine if we should use datetime or date-only comparison

            // If custom dates are provided, use them and set timeframe to 'custom'
            if ($dateFrom || $dateTo) {
                $timeframe = 'custom';
            } else {
                // Apply timeframe preset
                switch ($timeframe) {
                    case 'last_24h':
                        $dateFrom = now()->subHours(24);
                        $dateTo = now();
                        $useDateTime = true; // Use datetime comparison for precise 24-hour window
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

            // Log incoming request for debugging
            Log::info('=== Attendant Metrics API Called ===', [
                'location_id' => $locationId,
                'timeframe' => $timeframe,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timestamp' => now()->toDateTimeString(),
            ]);

        // Build booking query
        $bookingQuery = Booking::query();
        $purchaseQuery = AttractionPurchase::query();

        // Apply location filter
        if ($locationId) {
            $bookingQuery->where('location_id', $locationId);
            $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                $q->where('location_id', $locationId);
            });
            Log::info('Applied location filter', ['location_id' => $locationId]);
        }

        // Apply date range filter
        if ($dateFrom) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '>=', $dateFrom);
                $purchaseQuery->where('created_at', '>=', $dateFrom);
            } else {
                $bookingQuery->whereDate('created_at', '>=', $dateFrom);
                $purchaseQuery->whereDate('created_at', '>=', $dateFrom);
            }
        }

        if ($dateTo) {
            if ($useDateTime) {
                $bookingQuery->where('created_at', '<=', $dateTo);
                $purchaseQuery->where('created_at', '<=', $dateTo);
            } else {
                $bookingQuery->whereDate('created_at', '<=', $dateTo);
                $purchaseQuery->whereDate('created_at', '<=', $dateTo);
            }
        }

        // Calculate booking metrics
        $totalBookings = $bookingQuery->count();
        $confirmedBookings = (clone $bookingQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'cancelled')->count();
        $totalParticipants = (clone $bookingQuery)->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereNotIn('status', ['cancelled'])->sum('amount_paid') ?? 0;

        // Calculate purchase metrics
        $totalPurchases = $purchaseQuery->count();

        // Get all purchases for debugging
        $allPurchaseStatuses = (clone $purchaseQuery)->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as revenue'))
            ->groupBy('status')
            ->get();

        // Revenue from completed purchases only
        $purchaseRevenue = (clone $purchaseQuery)->where('status', 'completed')->sum('amount_paid') ?? 0;

        // All non-cancelled purchases revenue
        $allPurchaseRevenue = (clone $purchaseQuery)->whereNotIn('status', ['cancelled'])->sum('amount_paid') ?? 0;

        // Calculate total revenue (using all non-cancelled purchases)
        $totalRevenue = $bookingRevenue + $allPurchaseRevenue;

        Log::info('Attendant purchase metrics calculated', [
            'total_purchases' => $totalPurchases,
            'completed_revenue' => $purchaseRevenue,
            'all_non_cancelled_revenue' => $allPurchaseRevenue,
            'total_revenue' => $totalRevenue,
            'status_breakdown' => $allPurchaseStatuses->toArray(),
        ]);

        // Get unique customers count
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
            });
        }
        $totalCustomers = $customerQuery->count();

        Log::info('Attendant metrics calculated', [
            'bookings' => $totalBookings,
            'purchases' => $totalPurchases,
            'revenue' => $totalRevenue,
            'customers' => $totalCustomers,
        ]);

        // Get recent purchases (last 5)
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

        // Get recent bookings (last 5)
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
        ]);

        $response = [
            'timeframe' => [
                'type' => $timeframe,
                'date_from' => $dateFrom ? ($useDateTime ? $dateFrom->toDateTimeString() : $dateFrom) : null,
                'date_to' => $dateTo ? ($useDateTime ? $dateTo->toDateTimeString() : $dateTo) : null,
                'description' => match($timeframe) {
                    'last_24h' => 'Last 24 Hours',
                    'last_7d' => 'Last 7 Days',
                    'last_30d' => 'Last 30 Days',
                    'custom' => 'Custom Range',
                    default => 'All Time',
                },
            ],
            'metrics' => [
                // All counts below are filtered by the selected timeframe
                'totalBookings' => $totalBookings,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCustomers' => $totalCustomers,
                'confirmedBookings' => $confirmedBookings,  // Filtered by timeframe
                'pendingBookings' => $pendingBookings,      // Filtered by timeframe
                'completedBookings' => $completedBookings,  // Filtered by timeframe
                'cancelledBookings' => $cancelledBookings,  // Filtered by timeframe
                'totalParticipants' => (int) $totalParticipants,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($allPurchaseRevenue, 2),
                'purchaseRevenueCompleted' => round($purchaseRevenue, 2),
                'totalPurchases' => $totalPurchases,
            ],
            'recentPurchases' => $recentPurchases,
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

    /**
     * Get location statistics (for company_admin)
     *
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return array
     */
    private function getLocationStats($dateFrom = null, $dateTo = null)
    {
        $locationStats = [];

        // Get all active locations
        $locations = Location::where('is_active', true)->get();

        Log::info('Processing location stats', ['locations_count' => $locations->count()]);

        foreach ($locations as $location) {
            // Booking stats for this location
            $locationBookingQuery = Booking::where('location_id', $location->id);
            if ($dateFrom) {
                // For location stats, always use date-only comparison for consistent reporting
                $locationBookingQuery->whereDate('created_at', '>=', is_string($dateFrom) ? $dateFrom : $dateFrom->toDateString());
            }
            if ($dateTo) {
                $locationBookingQuery->whereDate('created_at', '<=', is_string($dateTo) ? $dateTo : $dateTo->toDateString());
            }

            $locationBookings = $locationBookingQuery->count();
            $locationParticipants = (clone $locationBookingQuery)->sum('participants') ?? 0;
            $locationBookingRevenue = (clone $locationBookingQuery)
                ->whereIn('status', ['confirmed', 'completed', 'checked-in'])
                ->sum('amount_paid') ?? 0;

            // Purchase stats for this location
            $locationPurchaseQuery = AttractionPurchase::whereHas('attraction', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            });
            if ($dateFrom) {
                // For location stats, always use date-only comparison for consistent reporting
                $locationPurchaseQuery->whereDate('created_at', '>=', is_string($dateFrom) ? $dateFrom : $dateFrom->toDateString());
            }
            if ($dateTo) {
                $locationPurchaseQuery->whereDate('created_at', '<=', is_string($dateTo) ? $dateTo : $dateTo->toDateString());
            }

            $locationPurchases = $locationPurchaseQuery->count();

            // Get purchase status breakdown for debugging
            $locationPurchaseStatuses = (clone $locationPurchaseQuery)
                ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as revenue'))
                ->groupBy('status')
                ->get();

            // Revenue from completed purchases only
            $locationPurchaseRevenueCompleted = (clone $locationPurchaseQuery)
                ->where('status', 'completed')
                ->sum('amount_paid') ?? 0;

            // Include pending purchases (all non-cancelled)
            $locationPurchaseRevenue = (clone $locationPurchaseQuery)
                ->whereIn('status', ['completed', 'pending'])
                ->sum('amount_paid') ?? 0;

            // Calculate utilization (simplified: based on bookings vs capacity)
            $daysInRange = 1;
            if ($dateFrom && $dateTo) {
                $daysInRange = max(1, \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) + 1);
            } elseif ($dateFrom) {
                $daysInRange = max(1, \Carbon\Carbon::parse($dateFrom)->diffInDays(now()) + 1);
            } else {
                $daysInRange = 30; // default to 30 days
            }

            $estimatedCapacity = $daysInRange * 100; // 100 participants per day capacity
            $utilization = $estimatedCapacity > 0 ? round(($locationParticipants / $estimatedCapacity) * 100) : 0;

            $locationStats[$location->id] = [
                'name' => $location->name,
                'bookings' => $locationBookings,
                'purchases' => $locationPurchases,
                'revenue' => round($locationBookingRevenue + $locationPurchaseRevenue, 2),
                'participants' => $locationParticipants,
                'utilization' => $utilization,
            ];

            Log::info("Location stats for {$location->name}", [
                'stats' => $locationStats[$location->id],
                'purchase_breakdown' => $locationPurchaseStatuses->toArray(),
            ]);
        }

        return $locationStats;
    }
}
