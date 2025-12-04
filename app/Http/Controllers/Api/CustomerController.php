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
    public function destroy(Customer $customer): JsonResponse
    {
        $customerName = $customer->first_name . ' ' . $customer->last_name;
        $customerId = $customer->id;

        $customer->delete();

        // Log customer deletion
        ActivityLog::log(
            action: 'Customer Deleted',
            category: 'delete',
            description: "Customer {$customerName} was deleted",
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
}
