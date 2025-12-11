<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['bookings', 'giftCards']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['first_name', 'last_name', 'email', 'created_at', 'last_visit', 'total_spent', 'total_bookings'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $customers = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $customers->items(),
                'pagination' => [
                    'current_page' => $customers->currentPage(),
                    'last_page' => $customers->lastPage(),
                    'per_page' => $customers->perPage(),
                    'total' => $customers->total(),
                    'from' => $customers->firstItem(),
                    'to' => $customers->lastItem(),
                ],
            ],
        ]);
    }

    // customer fetch list using booking data and purchase data, including both registered and guest customers
    public function fetchCustomerList(Request $request, $userId): JsonResponse
    {
        // Get the user by ID
        $user = User::find($userId);

        // Collect all unique customer identifiers from bookings
        $bookingCustomers = Booking::query()
            ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                $query->where('location_id', $user->location_id);
            })
            ->select('guest_email', 'guest_name', 'guest_phone')
            ->whereNotNull('guest_email')
            ->get()
            ->map(fn($record) => (object)[
                'guest_email' => $record->guest_email,
                'guest_name' => $record->guest_name,
                'guest_phone' => $record->guest_phone,
            ]);

        // Collect all unique customer identifiers from purchases
        $purchaseCustomers = AttractionPurchase::query()
            ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                $query->whereHas('attraction', function ($q) use ($user) {
                    $q->where('location_id', $user->location_id);
                });
            })
            ->select('guest_email', 'guest_name', 'guest_phone')
            ->whereNotNull('guest_email')
            ->get()
            ->map(fn($record) => (object)[
                'guest_email' => $record->guest_email,
                'guest_name' => $record->guest_name,
                'guest_phone' => $record->guest_phone,
            ]);

        // Merge all records
        $allRecords = $bookingCustomers->concat($purchaseCustomers);

        // Build customer list
        $allCustomers = collect();
        $processedEmails = [];

        foreach ($allRecords as $record) {
            $emailToCheck = strtolower(trim($record->guest_email));

            // Skip if we already processed this email
            if (in_array($emailToCheck, $processedEmails)) {
                continue;
            }

            $processedEmails[] = $emailToCheck;

            // Check if this email exists in the customers table
            $registeredCustomer = Customer::where('email', $record->guest_email)->first();

            if ($registeredCustomer) {
                // Use registered customer data
                $customer = (object) [
                    'id' => $registeredCustomer->id,
                    'first_name' => $registeredCustomer->first_name,
                    'last_name' => $registeredCustomer->last_name,
                    'email' => $registeredCustomer->email,
                    'phone' => $registeredCustomer->phone,
                    'status' => $registeredCustomer->status,
                    'created_at' => $registeredCustomer->created_at,
                    'last_visit' => $registeredCustomer->last_visit,
                ];
            } else {
                // Parse guest_name into first and last name
                $nameParts = explode(' ', trim($record->guest_name ?? ''), 2);

                // Create customer object from booking/purchase data
                $customer = (object) [
                    'id' => null,
                    'first_name' => $nameParts[0] ?? 'Guest',
                    'last_name' => $nameParts[1] ?? '',
                    'email' => $record->guest_email,
                    'phone' => $record->guest_phone,
                    'status' => 'guest',
                    'created_at' => null,
                    'last_visit' => null,
                ];
            }

            $allCustomers->push($customer);
        }

        // Apply search filter
        if ($request->has('search')) {
            $search = strtolower($request->search);
            $allCustomers = $allCustomers->filter(function ($customer) use ($search) {
                return str_contains(strtolower($customer->first_name ?? ''), $search) ||
                       str_contains(strtolower($customer->last_name ?? ''), $search) ||
                       str_contains(strtolower($customer->email ?? ''), $search) ||
                       str_contains(strtolower($customer->phone ?? ''), $search);
            });
        }

        // Calculate totals for each customer
        $customersWithTotals = $allCustomers->map(function ($customer) use ($user) {
            // Calculate total bookings
            $totalBookings = Booking::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->count();

            // Calculate total spent from bookings
            $totalSpentBookings = Booking::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->sum('total_amount');

            // Calculate total purchase tickets
            $totalPurchaseTickets = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->count();

            // Calculate total spent from purchases
            $totalSpentPurchases = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->sum('total_amount');

            // Calculate total quantity of tickets purchased
            $totalTicketQuantity = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->sum('quantity');

            // Add calculated fields to customer object
            $customer->total_bookings = $totalBookings;
            $customer->total_spent = $totalSpentBookings + $totalSpentPurchases;
            $customer->total_purchase_tickets = $totalPurchaseTickets;
            $customer->total_ticket_quantity = $totalTicketQuantity;

            return $customer;
        });

        // Sort
        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['first_name', 'last_name', 'email', 'total_spent', 'total_bookings'])) {
            $customersWithTotals = $sortOrder === 'desc'
                ? $customersWithTotals->sortByDesc($sortBy)
                : $customersWithTotals->sortBy($sortBy);
        }

        // Paginate manually
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $total = $customersWithTotals->count();
        $lastPage = (int) ceil($total / $perPage);

        $paginatedCustomers = $customersWithTotals
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $paginatedCustomers,
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:2',
            'password' => 'required|string|min:8|confirmed',
            'date_of_birth' => 'nullable|date|before:today',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $customer = Customer::create($validated);

        // Log customer creation
        ActivityLog::log(
            action: 'Customer Created',
            category: 'create',
            description: "New customer {$customer->first_name} {$customer->last_name} registered",
            userId: auth()->id(),
            locationId: null,
            entityType: 'customer',
            entityId: $customer->id,
            metadata: ['email' => $customer->email, 'phone' => $customer->phone]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer,
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['bookings.package', 'giftCards', 'payments']);

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:customers,email,' . $customer->id,
            'phone' => 'sometimes|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:2',
            'password' => 'sometimes|string|min:8|confirmed',
            'date_of_birth' => 'sometimes|nullable|date|before:today',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $customer->update($validated);
        $customer->load(['bookings', 'giftCards']);

        // Log customer update
        ActivityLog::log(
            action: 'Customer Updated',
            category: 'update',
            description: "Customer {$customer->first_name} {$customer->last_name} information updated",
            userId: auth()->id(),
            locationId: null,
            entityType: 'customer',
            entityId: $customer->id,
            metadata: array_keys($validated)
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer,
        ]);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $user = User::findOrFail(auth()->id());

        $customerName = $customer->first_name . ' ' . $customer->last_name;
        $customerId = $customer->id;

        $customer->delete();

        // Log customer deletion
        ActivityLog::log(
            action: 'Customer Deleted',
            category: 'delete',
            description: "Customer {$customerName} was deleted by {$user->first_name} {$user->last_name}",
            userId: auth()->id(),
            locationId: null,
            entityType: 'customer',
            entityId: $customerId
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * Toggle customer status.
     */
    public function toggleStatus(Customer $customer): JsonResponse
    {
        $newStatus = $customer->status === 'active' ? 'inactive' : 'active';
        $customer->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'Customer status updated successfully',
            'data' => $customer,
        ]);
    }

    /**
     * Get customer statistics.
     */
    public function statistics(Customer $customer): JsonResponse
    {
        $stats = [
            'total_bookings' => $customer->bookings()->count(),
            'total_spent' => $customer->payments()->where('status', 'completed')->sum('amount'),
            'favorite_package' => $customer->bookings()
                ->with('package')
                ->selectRaw('package_id, COUNT(*) as booking_count')
                ->groupBy('package_id')
                ->orderBy('booking_count', 'desc')
                ->first()?->package,
            'recent_bookings' => $customer->bookings()
                ->with('package')
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'last_visit' => $customer->last_visit,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }



    /**
     * Update customer's last visit.
     */
    public function updateLastVisit(Customer $customer): JsonResponse
    {
        $customer->update(['last_visit' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Last visit updated successfully',
        ]);
    }

    /**
     * Search customers by email or name.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q') ?? $request->get('query');

        if (!$query) {
            return response()->json([
                'success' => false,
                'message' => 'Search query is required',
            ], 400);
        }

        $customers = Customer::where(function ($q) use ($query) {
            $q->where('email', 'like', "%{$query}%");
            $q->orWhere('first_name', 'like', "%{$query}%");
            $q->orWhere('last_name', 'like', "%{$query}%");
        })
        ->active()
        ->limit(10)
        ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * Get comprehensive customer analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        // Get user from frontend parameter or auth
        $userId = $request->get('user_id') ?? auth()->id();
        $user = User::find($userId);

        // Get date range filter
        $dateRange = $request->get('date_range', '30d');
        $startDate = match($dateRange) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            'all' => null,
            default => now()->subDays(30),
        };

        // Determine if we should filter by location
        $isCompanyAdmin = $user && $user->role === 'company_admin';

        // If company admin and location_id is provided in request, use it
        // If not company admin, always use their assigned location
        $locationId = null;
        if ($isCompanyAdmin) {
            $locationId = $request->get('location_id') ? (int)$request->get('location_id') : null;
        } else {
            $locationId = $user ? $user->location_id : null;
        }

        // 1. Key Metrics
        $totalCustomersQuery = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->distinct('guest_email');

        $purchaseCustomersQuery = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->whereNotNull('guest_email')
            ->distinct('guest_email');

        $totalCustomers = $totalCustomersQuery->count() + $purchaseCustomersQuery->count();

        $activeCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $totalRevenue = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->sum('total_amount');

        $totalPurchaseRevenue = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->sum('total_amount');

        $totalRevenueSum = $totalRevenue + $totalPurchaseRevenue;
        $avgRevenuePerCustomer = $totalCustomers > 0 ? round($totalRevenueSum / $totalCustomers, 2) : 0;

        $newCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        // Calculate previous period metrics for comparison
        $previousPeriodStart = match($dateRange) {
            '7d' => now()->subDays(14),
            '30d' => now()->subDays(60),
            '90d' => now()->subDays(180),
            '1y' => now()->subYears(2),
            'all' => null,
            default => now()->subDays(60),
        };

        $previousPeriodEnd = match($dateRange) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            'all' => null,
            default => now()->subDays(30),
        };

        // Previous period total customers
        $prevTotalCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        // Previous period active customers
        $prevActiveCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        // Previous period revenue
        $prevTotalRevenue = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->sum('total_amount');

        $prevPurchaseRevenue = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->sum('total_amount');

        $prevTotalRevenueSum = $prevTotalRevenue + $prevPurchaseRevenue;

        // Previous period avg revenue per customer
        $prevAvgRevenuePerCustomer = $prevTotalCustomers > 0 ? round($prevTotalRevenueSum / $prevTotalCustomers, 2) : 0;

        // Previous period new customers
        $prevNewCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        // Calculate percentage changes
        $totalCustomersChange = $prevTotalCustomers > 0
            ? round((($totalCustomers - $prevTotalCustomers) / $prevTotalCustomers) * 100, 1)
            : 0;

        $activeCustomersChange = $prevActiveCustomers > 0
            ? round((($activeCustomers - $prevActiveCustomers) / $prevActiveCustomers) * 100, 1)
            : 0;

        $revenueChange = $prevTotalRevenueSum > 0
            ? round((($totalRevenueSum - $prevTotalRevenueSum) / $prevTotalRevenueSum) * 100, 1)
            : 0;

        $avgRevenueChange = $prevAvgRevenuePerCustomer > 0
            ? round((($avgRevenuePerCustomer - $prevAvgRevenuePerCustomer) / $prevAvgRevenuePerCustomer) * 100, 1)
            : 0;

        $newCustomersChange = $prevNewCustomers > 0
            ? round((($newCustomers - $prevNewCustomers) / $prevNewCustomers) * 100, 1)
            : 0;

        // 2. Customer Growth (last 9 months)
        $customerGrowth = [];
        for ($i = 8; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $bookingCount = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('guest_email')
                ->distinct('guest_email')
                ->count();

            $purchaseCount = AttractionPurchase::query()
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('guest_email')
                ->distinct('guest_email')
                ->count();

            $totalCount = $bookingCount + $purchaseCount;
            $growth = $i < 8 ? ($totalCount - ($customerGrowth[$i-1]['customers'] ?? 0)) : 0;

            $customerGrowth[] = [
                'month' => $monthStart->format('M'),
                'customers' => $totalCount,
                'growth' => $growth,
            ];
        }

        // 3. Revenue Trend (last 9 months)
        $revenueTrend = [];
        for ($i = 8; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $bookingRevenue = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('total_amount');

            $purchaseRevenue = AttractionPurchase::query()
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('total_amount');

            $bookingCount = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $revenueTrend[] = [
                'month' => $monthStart->format('M'),
                'revenue' => round($bookingRevenue + $purchaseRevenue, 2),
                'bookings' => $bookingCount,
            ];
        }

        // 4. Booking Time Distribution
        $bookingTimeDistribution = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->selectRaw('HOUR(booking_date) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'time' => date('g A', strtotime($item->hour . ':00')),
                'count' => $item->count,
            ]);

        // 5. Top Bookings per Customer
        $topBookingCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->whereNotNull('guest_email')
            ->selectRaw('guest_email, guest_name, COUNT(*) as bookings')
            ->groupBy('guest_email', 'guest_name')
            ->orderByDesc('bookings')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'name' => $item->guest_name,
                'bookings' => $item->bookings,
            ]);

        // 6. Customer Status Distribution
        $activeCount = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $inactiveCount = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '<', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $statusDistribution = [
            ['status' => 'active', 'count' => $activeCount, 'color' => '#10b981'],
            ['status' => 'inactive', 'count' => $inactiveCount, 'color' => '#ef4444'],
            ['status' => 'new', 'count' => $newCustomers, 'color' => '#3b82f6'],
        ];

        // 7. Activity Hours (hourly distribution)
        $activityHours = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as activity')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'hour' => date('g A', strtotime($item->hour . ':00')),
                'activity' => $item->activity,
            ]);

        // 8. Customer Lifetime Value Segments
        $allCustomerEmails = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->distinct()
            ->pluck('guest_email');

        $customerValues = [];
        foreach ($allCustomerEmails as $email) {
            $totalSpent = Booking::where('guest_email', $email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->sum('total_amount');

            $purchaseSpent = AttractionPurchase::where('guest_email', $email)
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->sum('total_amount');

            $customerValues[] = $totalSpent + $purchaseSpent;
        }

        $highValue = count(array_filter($customerValues, fn($v) => $v >= 1000));
        $mediumValue = count(array_filter($customerValues, fn($v) => $v >= 500 && $v < 1000));
        $lowValue = count(array_filter($customerValues, fn($v) => $v < 500));
        $totalValues = count($customerValues) ?: 1;

        $customerLifetimeValue = [
            ['segment' => 'High Value', 'value' => round(($highValue / $totalValues) * 100), 'color' => '#10b981'],
            ['segment' => 'Medium Value', 'value' => round(($mediumValue / $totalValues) * 100), 'color' => '#3b82f6'],
            ['segment' => 'Low Value', 'value' => round(($lowValue / $totalValues) * 100), 'color' => '#ef4444'],
        ];

        // 9. Repeat Customers Rate (last 9 months)
        $repeatCustomers = [];
        for ($i = 8; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            // Get all customers who made bookings in this month
            $monthCustomerEmails = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $allCustomers = $monthCustomerEmails->count();

            // Count how many of these customers had previous bookings (before this month)
            $repeaters = 0;
            foreach ($monthCustomerEmails as $email) {
                $previousBookings = Booking::query()
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->where('guest_email', $email)
                    ->where('created_at', '<', $monthStart)
                    ->count();
                
                if ($previousBookings > 0) {
                    $repeaters++;
                }
            }

            $repeatRate = $allCustomers > 0 ? round(($repeaters / $allCustomers) * 100) : 0;

            $repeatCustomers[] = [
                'month' => $monthStart->format('M'),
                'repeatRate' => $repeatRate,
            ];
        }

        // 10. Top 5 Most Purchased Activities by Customer
        $topActivities = AttractionPurchase::query()
            ->with('attraction')
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->whereNotNull('guest_email')
            ->selectRaw('guest_email, guest_name, attraction_id, COUNT(*) as purchases')
            ->groupBy('guest_email', 'guest_name', 'attraction_id')
            ->orderByDesc('purchases')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'customer' => $item->guest_name,
                'activity' => $item->attraction->name ?? 'N/A',
                'purchases' => $item->purchases,
            ]);

        // 11. Top 5 Most Booked Packages by Customer
        $topPackages = Booking::query()
            ->with('package')
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->whereNotNull('guest_email')
            ->whereNotNull('package_id')
            ->selectRaw('guest_email, guest_name, package_id, COUNT(*) as bookings')
            ->groupBy('guest_email', 'guest_name', 'package_id')
            ->orderByDesc('bookings')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'customer' => $item->guest_name,
                'package' => $item->package->name ?? 'N/A',
                'bookings' => $item->bookings,
            ]);

        // 12. Recent Customers (from bookings and purchases)
        $recentBookings = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->selectRaw('guest_email, guest_name, guest_phone, MIN(created_at) as join_date, MAX(created_at) as last_activity')
            ->groupBy('guest_email', 'guest_name', 'guest_phone')
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get();

        $recentCustomers = $recentBookings->map(function($customer) use ($locationId) {
            $totalSpent = Booking::where('guest_email', $customer->guest_email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->sum('total_amount');

            $purchaseSpent = AttractionPurchase::where('guest_email', $customer->guest_email)
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->sum('total_amount');

            $bookingCount = Booking::where('guest_email', $customer->guest_email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->count();

            $isActive = $customer->last_activity >= now()->subDays(30);

            return [
                'id' => md5($customer->guest_email),
                'name' => $customer->guest_name,
                'email' => $customer->guest_email,
                'joinDate' => $customer->join_date,
                'totalSpent' => round($totalSpent + $purchaseSpent, 2),
                'bookings' => $bookingCount,
                'lastActivity' => $customer->last_activity,
                'status' => $isActive ? 'active' : 'inactive',
            ];
        });

        // Get previous repeat rate for comparison
        $prevRepeatRate = count($repeatCustomers) > 1 ? $repeatCustomers[count($repeatCustomers) - 2]['repeatRate'] : 0;
        $currentRepeatRate = $repeatCustomers[count($repeatCustomers) - 1]['repeatRate'];
        $repeatRateChange = $prevRepeatRate > 0
            ? round((($currentRepeatRate - $prevRepeatRate) / $prevRepeatRate) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'keyMetrics' => [
                    [
                        'label' => 'Total Customers',
                        'value' => (string)$totalCustomers,
                        'change' => ($totalCustomersChange >= 0 ? '+' : '') . $totalCustomersChange . '%',
                        'trend' => $totalCustomersChange >= 0 ? 'up' : 'down'
                    ],
                    [
                        'label' => 'Active Customers',
                        'value' => (string)$activeCustomers,
                        'change' => ($activeCustomersChange >= 0 ? '+' : '') . $activeCustomersChange . '%',
                        'trend' => $activeCustomersChange >= 0 ? 'up' : 'down'
                    ],
                    [
                        'label' => 'Total Revenue',
                        'value' => '$' . number_format($totalRevenueSum, 2),
                        'change' => ($revenueChange >= 0 ? '+' : '') . $revenueChange . '%',
                        'trend' => $revenueChange >= 0 ? 'up' : 'down'
                    ],
                    [
                        'label' => 'Repeat Rate',
                        'value' => $currentRepeatRate . '%',
                        'change' => ($repeatRateChange >= 0 ? '+' : '') . $repeatRateChange . '%',
                        'trend' => $repeatRateChange >= 0 ? 'up' : 'down'
                    ],
                    [
                        'label' => 'Avg. Revenue/Customer',
                        'value' => '$' . $avgRevenuePerCustomer,
                        'change' => ($avgRevenueChange >= 0 ? '+' : '') . $avgRevenueChange . '%',
                        'trend' => $avgRevenueChange >= 0 ? 'up' : 'down'
                    ],
                    [
                        'label' => 'New Customers (30d)',
                        'value' => (string)$newCustomers,
                        'change' => ($newCustomersChange >= 0 ? '+' : '') . $newCustomersChange . '%',
                        'trend' => $newCustomersChange >= 0 ? 'up' : 'down'
                    ],
                ],
                'analyticsData' => [
                    'customerGrowth' => $customerGrowth,
                    'revenueTrend' => $revenueTrend,
                    'bookingTimeDistribution' => $bookingTimeDistribution,
                    'bookingsPerCustomer' => $topBookingCustomers,
                    'statusDistribution' => $statusDistribution,
                    'activityHours' => $activityHours,
                    'customerLifetimeValue' => $customerLifetimeValue,
                    'repeatCustomers' => $repeatCustomers,
                ],
                'topActivities' => $topActivities,
                'topPackages' => $topPackages,
                'recentCustomers' => $recentCustomers,
            ],
        ]);
    }

    /**
     * Export customer analytics data in various formats (CSV, PDF).
     */
    public function exportAnalytics(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'date_range' => 'nullable|in:7d,30d,90d,1y,all',
            'format' => 'required|in:csv,pdf,receipt',
            'include_sections' => 'nullable|array',
            'include_sections.*' => 'in:customers,revenue,bookings,activities,packages',
        ]);

        $userId = $request->get('user_id') ?? auth()->id();
        $user = User::find($userId);
        $format = $validated['format'];
        $includeSections = $validated['include_sections'] ?? ['customers', 'revenue', 'bookings', 'activities', 'packages'];

        // Get date range filter
        $dateRange = $request->get('date_range', '30d');
        $startDate = match($dateRange) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            'all' => null,
            default => now()->subDays(30),
        };

        // Location filtering
        $isCompanyAdmin = $user && $user->role === 'company_admin';
        $locationId = null;
        if ($isCompanyAdmin) {
            $locationId = $request->get('location_id') ? (int)$request->get('location_id') : null;
        } else {
            $locationId = $user ? $user->location_id : null;
        }

        // Get location name for report
        $locationName = 'All Locations';
        if ($locationId) {
            $location = \App\Models\Location::find($locationId);
            $locationName = $location ? $location->name : 'Unknown Location';
        }

        // Collect export data
        $exportData = [];

        if (in_array('customers', $includeSections)) {
            // Get all customers with their details
            $bookingEmails = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $purchaseEmails = AttractionPurchase::query()
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $allEmails = $bookingEmails->merge($purchaseEmails)->unique();

            $exportData['customers'] = [];
            foreach ($allEmails as $email) {
                $firstBooking = Booking::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->orderBy('created_at')
                    ->first();

                $lastBooking = Booking::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->orderByDesc('created_at')
                    ->first();

                $totalBookings = Booking::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->count();

                $totalSpent = Booking::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->sum('total_amount');

                $purchaseSpent = AttractionPurchase::where('guest_email', $email)
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->sum('total_amount');

                $totalPurchases = AttractionPurchase::where('guest_email', $email)
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->count();

                $customerName = $firstBooking ? $firstBooking->guest_name : 
                               ($lastBooking ? $lastBooking->guest_name : 'Unknown');

                $exportData['customers'][] = [
                    'name' => $customerName,
                    'email' => $email,
                    'total_bookings' => $totalBookings,
                    'total_purchases' => $totalPurchases,
                    'total_spent' => round($totalSpent + $purchaseSpent, 2),
                    'first_visit' => $firstBooking ? $firstBooking->created_at->format('Y-m-d') : 'N/A',
                    'last_visit' => $lastBooking ? $lastBooking->created_at->format('Y-m-d') : 'N/A',
                ];
            }
        }

        if (in_array('revenue', $includeSections)) {
            // Revenue by month
            $exportData['revenue_by_month'] = [];
            for ($i = 8; $i >= 0; $i--) {
                $monthStart = now()->subMonths($i)->startOfMonth();
                $monthEnd = now()->subMonths($i)->endOfMonth();

                $bookingRevenue = Booking::query()
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum('total_amount');

                $purchaseRevenue = AttractionPurchase::query()
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum('total_amount');

                $exportData['revenue_by_month'][] = [
                    'month' => $monthStart->format('M Y'),
                    'bookings_revenue' => round($bookingRevenue, 2),
                    'purchases_revenue' => round($purchaseRevenue, 2),
                    'total_revenue' => round($bookingRevenue + $purchaseRevenue, 2),
                ];
            }
        }

        if (in_array('bookings', $includeSections)) {
            // Top booking customers
            $exportData['top_customers'] = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->selectRaw('guest_email, guest_name, COUNT(*) as booking_count, SUM(total_amount) as total_spent')
                ->groupBy('guest_email', 'guest_name')
                ->orderByDesc('booking_count')
                ->limit(20)
                ->get()
                ->map(fn($item) => [
                    'name' => $item->guest_name,
                    'email' => $item->guest_email,
                    'bookings' => $item->booking_count,
                    'total_spent' => round($item->total_spent, 2),
                ])
                ->toArray();
        }

        if (in_array('activities', $includeSections)) {
            // Top activities
            $exportData['top_activities'] = AttractionPurchase::query()
                ->with('attraction')
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->selectRaw('attraction_id, COUNT(*) as purchase_count, SUM(total_amount) as total_revenue')
                ->groupBy('attraction_id')
                ->orderByDesc('purchase_count')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'activity' => $item->attraction->name ?? 'N/A',
                    'purchases' => $item->purchase_count,
                    'revenue' => round($item->total_revenue, 2),
                ])
                ->toArray();
        }

        if (in_array('packages', $includeSections)) {
            // Top packages
            $exportData['top_packages'] = Booking::query()
                ->with('package')
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->whereNotNull('package_id')
                ->selectRaw('package_id, COUNT(*) as booking_count, SUM(total_amount) as total_revenue')
                ->groupBy('package_id')
                ->orderByDesc('booking_count')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'package' => $item->package->name ?? 'N/A',
                    'bookings' => $item->booking_count,
                    'revenue' => round($item->total_revenue, 2),
                ])
                ->toArray();
        }

        // Generate export based on format
        if ($format === 'csv') {
            return $this->generateCSVExport($exportData, $locationName, $dateRange);
        } elseif ($format === 'receipt') {
            return $this->generateReceiptExport($exportData, $locationName, $dateRange, $user);
        } else {
            return $this->generatePDFExport($exportData, $locationName, $dateRange, $user);
        }
    }

    /**
     * Generate CSV export.
     */
    private function generateCSVExport($data, $locationName, $dateRange)
    {
        $filename = 'customer_analytics_' . date('Y-m-d_His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($data, $locationName, $dateRange) {
            $file = fopen('php://output', 'w');

            // Header information
            fputcsv($file, ['Customer Analytics Report']);
            fputcsv($file, ['Location:', $locationName]);
            fputcsv($file, ['Date Range:', $dateRange]);
            fputcsv($file, ['Generated:', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            // Customers section
            if (isset($data['customers'])) {
                fputcsv($file, ['CUSTOMER LIST']);
                fputcsv($file, ['Name', 'Email', 'Total Bookings', 'Total Purchases', 'Total Spent', 'First Visit', 'Last Visit']);
                foreach ($data['customers'] as $customer) {
                    fputcsv($file, [
                        $customer['name'],
                        $customer['email'],
                        $customer['total_bookings'],
                        $customer['total_purchases'],
                        '$' . number_format($customer['total_spent'], 2),
                        $customer['first_visit'],
                        $customer['last_visit'],
                    ]);
                }
                fputcsv($file, []);
            }

            // Revenue by month section
            if (isset($data['revenue_by_month'])) {
                fputcsv($file, ['REVENUE BY MONTH']);
                fputcsv($file, ['Month', 'Bookings Revenue', 'Purchases Revenue', 'Total Revenue']);
                foreach ($data['revenue_by_month'] as $revenue) {
                    fputcsv($file, [
                        $revenue['month'],
                        '$' . number_format($revenue['bookings_revenue'], 2),
                        '$' . number_format($revenue['purchases_revenue'], 2),
                        '$' . number_format($revenue['total_revenue'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

            // Top customers section
            if (isset($data['top_customers'])) {
                fputcsv($file, ['TOP CUSTOMERS']);
                fputcsv($file, ['Name', 'Email', 'Bookings', 'Total Spent']);
                foreach ($data['top_customers'] as $customer) {
                    fputcsv($file, [
                        $customer['name'],
                        $customer['email'],
                        $customer['bookings'],
                        '$' . number_format($customer['total_spent'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

            // Top activities section
            if (isset($data['top_activities'])) {
                fputcsv($file, ['TOP ACTIVITIES']);
                fputcsv($file, ['Activity', 'Purchases', 'Revenue']);
                foreach ($data['top_activities'] as $activity) {
                    fputcsv($file, [
                        $activity['activity'],
                        $activity['purchases'],
                        '$' . number_format($activity['revenue'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

            // Top packages section
            if (isset($data['top_packages'])) {
                fputcsv($file, ['TOP PACKAGES']);
                fputcsv($file, ['Package', 'Bookings', 'Revenue']);
                foreach ($data['top_packages'] as $package) {
                    fputcsv($file, [
                        $package['package'],
                        $package['bookings'],
                        '$' . number_format($package['revenue'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Generate PDF export using DomPDF.
     */
    private function generatePDFExport($data, $locationName, $dateRange, $user)
    {
        $filename = 'customer_analytics_' . date('Y-m-d_His') . '.pdf';
        
        $pdf = \PDF::loadView('exports.customer-analytics-pdf', [
            'data' => $data,
            'locationName' => $locationName,
            'dateRange' => $dateRange,
            'generatedBy' => $user ? $user->first_name . ' ' . $user->last_name : 'System',
            'generatedAt' => now()->format('F d, Y - h:i A'),
        ]);

        return $pdf->download($filename);
    }

    /**
     * Generate Receipt-style export as PNG image.
     */
    private function generateReceiptExport($data, $locationName, $dateRange, $user)
    {
        $filename = 'customer_analytics_receipt_' . date('Y-m-d_His') . '.png';
        
        // Create image with GD
        $width = 600;
        $lineHeight = 20;
        $padding = 20;
        
        // Calculate height based on content
        $contentLines = 15; // Header lines
        if (isset($data['customers'])) $contentLines += min(count($data['customers']), 10) + 3;
        if (isset($data['revenue_by_month'])) $contentLines += min(count($data['revenue_by_month']), 9) + 3;
        if (isset($data['top_customers'])) $contentLines += min(count($data['top_customers']), 5) + 3;
        if (isset($data['top_activities'])) $contentLines += min(count($data['top_activities']), 5) + 3;
        if (isset($data['top_packages'])) $contentLines += min(count($data['top_packages']), 5) + 3;
        
        $height = ($contentLines * $lineHeight) + ($padding * 2);
        
        // Create image
        $image = imagecreate($width, $height);
        
        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 100, 100, 100);
        
        // Fill background
        imagefill($image, 0, 0, $white);
        
        $y = $padding;
        $font = 3; // Built-in font
        
        // Helper function to draw text
        $drawText = function($text, $isBold = false) use ($image, &$y, $padding, $black, $lineHeight, $font) {
            imagestring($image, $font, $padding, $y, $text, $black);
            $y += $lineHeight;
        };
        
        $drawDivider = function() use ($image, &$y, $width, $gray, $lineHeight) {
            imageline($image, 10, $y + 5, $width - 10, $y + 5, $gray);
            $y += $lineHeight;
        };
        
        // Header
        $drawText('CUSTOMER ANALYTICS REPORT', true);
        $drawText('Location: ' . $locationName);
        $drawText('Date Range: ' . $dateRange);
        $drawText('Generated: ' . now()->format('M d, Y - h:i A'));
        $generatedBy = $user ? $user->first_name . ' ' . $user->last_name : 'System';
        $drawText('By: ' . $generatedBy);
        $drawDivider();
        
        // Customers section
        if (isset($data['customers']) && count($data['customers']) > 0) {
            $drawText('CUSTOMER LIST', true);
            $customers = array_slice($data['customers'], 0, 10);
            foreach ($customers as $customer) {
                $drawText(substr($customer['name'], 0, 30) . ' - $' . number_format($customer['total_spent'], 2));
            }
            $drawDivider();
        }
        
        // Revenue by month section
        if (isset($data['revenue_by_month']) && count($data['revenue_by_month']) > 0) {
            $drawText('REVENUE BY MONTH', true);
            $revenues = array_slice($data['revenue_by_month'], 0, 9);
            foreach ($revenues as $revenue) {
                $drawText($revenue['month'] . ': $' . number_format($revenue['total_revenue'], 2));
            }
            $drawDivider();
        }
        
        // Top customers section
        if (isset($data['top_customers']) && count($data['top_customers']) > 0) {
            $drawText('TOP CUSTOMERS', true);
            $topCustomers = array_slice($data['top_customers'], 0, 5);
            foreach ($topCustomers as $customer) {
                $drawText(substr($customer['name'], 0, 25) . ' - ' . $customer['bookings'] . ' bookings');
            }
            $drawDivider();
        }
        
        // Top activities section
        if (isset($data['top_activities']) && count($data['top_activities']) > 0) {
            $drawText('TOP ACTIVITIES', true);
            $topActivities = array_slice($data['top_activities'], 0, 5);
            foreach ($topActivities as $activity) {
                $drawText(substr($activity['activity'], 0, 30) . ' - ' . $activity['purchases'] . ' sales');
            }
            $drawDivider();
        }
        
        // Top packages section
        if (isset($data['top_packages']) && count($data['top_packages']) > 0) {
            $drawText('TOP PACKAGES', true);
            $topPackages = array_slice($data['top_packages'], 0, 5);
            foreach ($topPackages as $package) {
                $drawText(substr($package['package'], 0, 30) . ' - ' . $package['bookings'] . ' bookings');
            }
        }
        
        // Output image
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);
        
        return response($imageData, 200)
            ->header('Content-Type', 'image/png')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Content-Length', strlen($imageData))
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
