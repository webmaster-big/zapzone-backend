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
                'timestamp' => now()->toDateTimeString(),
            ]);

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

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
            $bookingQuery->where('booking_date', '>=', $dateFrom);
            $purchaseQuery->where('purchase_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $bookingQuery->where('booking_date', '<=', $dateTo);
            $purchaseQuery->where('purchase_date', '<=', $dateTo);
        }

        // Calculate booking metrics
        $totalBookings = $bookingQuery->count();
        $confirmedBookings = (clone $bookingQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'cancelled')->count();
        $checkedInBookings = (clone $bookingQuery)->where('status', 'checked-in')->count();
        $totalParticipants = (clone $bookingQuery)->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereIn('status', ['confirmed', 'completed', 'checked-in'])->sum('total_amount') ?? 0;

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
        $purchaseRevenue = (clone $purchaseQuery)->where('status', 'completed')->sum('total_amount') ?? 0;

        Log::info('Purchase metrics calculated', [
            'total' => $totalPurchases,
            'revenue' => $purchaseRevenue,
        ]);

        // Calculate total revenue
        $totalRevenue = $bookingRevenue + $purchaseRevenue;

        // Get unique customers count
        $customerQuery = Customer::query();
        if ($locationId || $dateFrom || $dateTo) {
            $customerQuery->whereHas('bookings', function ($q) use ($locationId, $dateFrom, $dateTo) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    $q->where('booking_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('booking_date', '<=', $dateTo);
                }
            })->orWhereHas('attractionPurchases', function ($q) use ($locationId, $dateFrom, $dateTo) {
                if ($locationId) {
                    $q->whereHas('attraction', function ($aq) use ($locationId) {
                        $aq->where('location_id', $locationId);
                    });
                }
                if ($dateFrom) {
                    $q->where('purchase_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('purchase_date', '<=', $dateTo);
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
            $recentPurchasesQuery->where('purchase_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $recentPurchasesQuery->where('purchase_date', '<=', $dateTo);
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
            'metrics' => [
                'totalBookings' => $totalBookings,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCustomers' => $totalCustomers,
                'confirmedBookings' => $confirmedBookings,
                'pendingBookings' => $pendingBookings,
                'completedBookings' => $completedBookings,
                'cancelledBookings' => $cancelledBookings,
                'checkedInBookings' => $checkedInBookings,
                'totalParticipants' => (int) $totalParticipants,
                'bookingRevenue' => round($bookingRevenue, 2),
                'purchaseRevenue' => round($purchaseRevenue, 2),
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
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            // Log incoming request for debugging
            Log::info('=== Attendant Metrics API Called ===', [
                'location_id' => $locationId,
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
            $bookingQuery->where('booking_date', '>=', $dateFrom);
            $purchaseQuery->where('purchase_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $bookingQuery->where('booking_date', '<=', $dateTo);
            $purchaseQuery->where('purchase_date', '<=', $dateTo);
        }

        // Calculate booking metrics
        $totalBookings = $bookingQuery->count();
        $confirmedBookings = (clone $bookingQuery)->where('status', 'confirmed')->count();
        $pendingBookings = (clone $bookingQuery)->where('status', 'pending')->count();
        $completedBookings = (clone $bookingQuery)->where('status', 'completed')->count();
        $cancelledBookings = (clone $bookingQuery)->where('status', 'cancelled')->count();
        $totalParticipants = (clone $bookingQuery)->sum('participants') ?? 0;
        $bookingRevenue = (clone $bookingQuery)->whereIn('status', ['confirmed', 'completed', 'checked-in'])->sum('total_amount') ?? 0;

        // Calculate purchase metrics
        $totalPurchases = $purchaseQuery->count();
        $purchaseRevenue = (clone $purchaseQuery)->where('status', 'completed')->sum('total_amount') ?? 0;

        // Calculate total revenue
        $totalRevenue = $bookingRevenue + $purchaseRevenue;

        // Get unique customers count
        $customerQuery = Customer::query();
        if ($locationId || $dateFrom || $dateTo) {
            $customerQuery->whereHas('bookings', function ($q) use ($locationId, $dateFrom, $dateTo) {
                if ($locationId) {
                    $q->where('location_id', $locationId);
                }
                if ($dateFrom) {
                    $q->where('booking_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('booking_date', '<=', $dateTo);
                }
            })->orWhereHas('attractionPurchases', function ($q) use ($locationId, $dateFrom, $dateTo) {
                if ($locationId) {
                    $q->whereHas('attraction', function ($aq) use ($locationId) {
                        $aq->where('location_id', $locationId);
                    });
                }
                if ($dateFrom) {
                    $q->where('purchase_date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('purchase_date', '<=', $dateTo);
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
            $recentPurchasesQuery->where('purchase_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $recentPurchasesQuery->where('purchase_date', '<=', $dateTo);
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
            $recentBookingsQuery->where('booking_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $recentBookingsQuery->where('booking_date', '<=', $dateTo);
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
                $locationBookingQuery->where('booking_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $locationBookingQuery->where('booking_date', '<=', $dateTo);
            }

            $locationBookings = $locationBookingQuery->count();
            $locationParticipants = (clone $locationBookingQuery)->sum('participants') ?? 0;
            $locationBookingRevenue = (clone $locationBookingQuery)
                ->whereIn('status', ['confirmed', 'completed', 'checked-in'])
                ->sum('total_amount') ?? 0;

            // Purchase stats for this location
            $locationPurchaseQuery = AttractionPurchase::whereHas('attraction', function ($q) use ($location) {
                $q->where('location_id', $location->id);
            });
            if ($dateFrom) {
                $locationPurchaseQuery->where('purchase_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $locationPurchaseQuery->where('purchase_date', '<=', $dateTo);
            }

            $locationPurchases = $locationPurchaseQuery->count();
            $locationPurchaseRevenue = (clone $locationPurchaseQuery)
                ->where('status', 'completed')
                ->sum('total_amount') ?? 0;

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

            Log::info("Location stats for {$location->name}", $locationStats[$location->id]);
        }

        return $locationStats;
    }
}
