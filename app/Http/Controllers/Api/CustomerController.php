<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EventPurchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['bookings', 'giftCards']);

        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where(function ($q) use ($authUser) {
                    $q->whereHas('bookings', fn($b) => $b->where('location_id', $authUser->location_id))
                      ->orWhereHas('attractionPurchases.attraction', fn($a) => $a->where('location_id', $authUser->location_id))
                      ->orWhereHas('eventPurchases', fn($e) => $e->where('location_id', $authUser->location_id));
                });
            } elseif ($authUser->company_id) {
                $companyId = $authUser->company_id;
                $query->where(function ($q) use ($companyId) {
                    $q->whereHas('bookings.location', fn($l) => $l->where('company_id', $companyId))
                      ->orWhereHas('attractionPurchases.attraction.location', fn($l) => $l->where('company_id', $companyId))
                      ->orWhereHas('eventPurchases.location', fn($l) => $l->where('company_id', $companyId));
                });
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('first_name', 'like', $like)
                      ->orWhere('last_name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('address', 'like', $like)
                      ->orWhere('city', 'like', $like)
                      ->orWhere('state', 'like', $like)
                      ->orWhere('zip', 'like', $like)
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }

        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

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

    public function fetchCustomerList(Request $request, $userId): JsonResponse
    {
        $user = User::find($userId);

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

        $eventPurchaseCustomers = EventPurchase::query()
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

        $allRecords = $bookingCustomers->concat($purchaseCustomers)->concat($eventPurchaseCustomers);

        $allCustomers = collect();
        $processedEmails = [];

        foreach ($allRecords as $record) {
            $emailToCheck = strtolower(trim($record->guest_email));

            if (in_array($emailToCheck, $processedEmails)) {
                continue;
            }

            $processedEmails[] = $emailToCheck;

            $registeredCustomer = Customer::where('email', $record->guest_email)->first();

            if ($registeredCustomer) {
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
                $nameParts = explode(' ', trim($record->guest_name ?? ''), 2);

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

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', strtolower(trim((string) $request->search)), -1, PREG_SPLIT_NO_EMPTY);
            $allCustomers = $allCustomers->filter(function ($customer) use ($terms) {
                $fields = [
                    strtolower($customer->first_name ?? ''),
                    strtolower($customer->last_name ?? ''),
                    strtolower(trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))),
                    strtolower($customer->email ?? ''),
                    strtolower($customer->phone ?? ''),
                ];

                foreach ($terms as $term) {
                    $matched = false;
                    foreach ($fields as $field) {
                        if ($field !== '' && str_contains($field, $term)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        return false;
                    }
                }

                return true;
            });
        }

        $customersWithTotals = $allCustomers->map(function ($customer) use ($user) {
            $totalBookings = Booking::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->count();

            $totalSpentBookings = Booking::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->sum('amount_paid');

            $totalPurchaseTickets = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->count();

            $totalSpentPurchases = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->sum('amount_paid');

            $totalTicketQuantity = AttractionPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->whereHas('attraction', function ($q) use ($user) {
                        $q->where('location_id', $user->location_id);
                    });
                })
                ->sum('quantity');

            $totalEventPurchases = EventPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->count();

            $totalSpentEventPurchases = EventPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->sum('amount_paid');

            $totalEventTicketQuantity = EventPurchase::where('guest_email', $customer->email)
                ->when($user && $user->role !== 'company_admin', function ($query) use ($user) {
                    $query->where('location_id', $user->location_id);
                })
                ->sum('quantity');

            $customer->total_bookings = $totalBookings;
            $customer->total_spent = $totalSpentBookings + $totalSpentPurchases + $totalSpentEventPurchases;
            $customer->total_purchase_tickets = $totalPurchaseTickets;
            $customer->total_ticket_quantity = $totalTicketQuantity;
            $customer->total_event_purchases = $totalEventPurchases;
            $customer->total_event_ticket_quantity = (int) $totalEventTicketQuantity;

            return $customer;
        });

        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        if (in_array($sortBy, ['first_name', 'last_name', 'email', 'total_spent', 'total_bookings', 'created_at', 'last_visit'])) {
            $customersWithTotals = $sortOrder === 'desc'
                ? $customersWithTotals->sortByDesc($sortBy)
                : $customersWithTotals->sortBy($sortBy);
        }

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers',
            'phone' => 'required|string|max:20',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',
            'password' => 'nullable|string|min:8|confirmed',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (!isset($validated['status'])) {
            $validated['status'] = 'active';
        }

        $customer = Customer::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['bookings.package', 'giftCards', 'payments']);

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:customers,email,' . $customer->id,
            'phone' => 'sometimes|string|max:20',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:2',
            'password' => 'sometimes|string|min:8|confirmed',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $customer->update($validated);
        $customer->load(['bookings', 'giftCards']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Customer Updated',
            category: 'update',
            description: "Customer {$customer->first_name} {$customer->last_name} information updated",
            userId: auth()->id(),
            locationId: null,
            entityType: 'customer',
            entityId: $customer->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($validated),
                'customer_details' => [
                    'customer_id' => $customer->id,
                    'name' => $customer->first_name . ' ' . $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
            'data' => $customer,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $user = User::findOrFail(auth()->id());

        $customerName = $customer->first_name . ' ' . $customer->last_name;
        $customerId = $customer->id;

        $customer->delete();

        ActivityLog::log(
            action: 'Customer Deleted',
            category: 'delete',
            description: "Customer {$customerName} was deleted by {$user->first_name} {$user->last_name}",
            userId: auth()->id(),
            locationId: null,
            entityType: 'customer',
            entityId: $customerId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'customer_details' => [
                    'customer_id' => $customerId,
                    'name' => $customerName,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully',
        ]);
    }

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
            'total_attraction_purchases' => $customer->attractionPurchases()->count(),
            'total_event_purchases' => $customer->eventPurchases()->count(),
            'total_event_tickets' => (int) $customer->eventPurchases()->sum('quantity'),
            'event_purchase_spent' => round((float) $customer->eventPurchases()
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('amount_paid'), 2),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }



    public function updateLastVisit(Customer $customer): JsonResponse
    {
        $customer->update(['last_visit' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Last visit updated successfully',
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q') ?? $request->get('query');

        if (!$query) {
            return response()->json([
                'success' => false,
                'message' => 'Search query is required',
            ], 400);
        }

        $terms = preg_split('/\s+/', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($terms)) {
            return response()->json([
                'success' => false,
                'message' => 'Search query is required',
            ], 400);
        }

        $customersQuery = Customer::query();

        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $customersQuery->where(function ($q) use ($like) {
                $q->where('email', 'like', $like)
                  ->orWhere('first_name', 'like', $like)
                  ->orWhere('last_name', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
            });
        }

        $customers = $customersQuery
            ->active()
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $userId = $request->get('user_id') ?? auth()->id();
        $user = User::find($userId);

        $dateRange = $request->get('date_range', '30d');

        if ($dateRange === 'custom' && $request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
        } elseif ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
            $dateRange = 'custom';
        } else {
            $startDate = match($dateRange) {
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                '90d' => now()->subDays(90),
                '1y' => now()->subYear(),
                'all' => null,
                default => now()->subDays(30),
            };
            $endDate = now();
        }

        $isCompanyAdmin = $user && $user->role === 'company_admin';

        $locationId = null;
        if ($isCompanyAdmin) {
            $locationId = $request->get('location_id') ? (int)$request->get('location_id') : null;
        } else {
            $locationId = $user ? $user->location_id : null;
        }

        $bookingEmails = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->distinct()
            ->pluck('guest_email');

        $purchaseEmails = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->whereNotNull('guest_email')
            ->distinct()
            ->pluck('guest_email');

        $eventPurchaseEmails = EventPurchase::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->distinct()
            ->pluck('guest_email');

        $totalCustomers = $bookingEmails->merge($purchaseEmails)->merge($eventPurchaseEmails)->unique()->count();

        $activeCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $totalRevenue = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotIn('status', ['cancelled'])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->sum('amount_paid');

        $totalPurchaseRevenue = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->whereNotIn('status', ['cancelled'])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->sum('amount_paid');

        $totalEventPurchaseRevenue = EventPurchase::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->sum('amount_paid');

        $totalRevenueSum = $totalRevenue + $totalPurchaseRevenue + $totalEventPurchaseRevenue;
        $avgRevenuePerCustomer = $totalCustomers > 0 ? round($totalRevenueSum / $totalCustomers, 2) : 0;

        $newCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        if ($dateRange === 'custom') {
            $periodDays = $startDate->diffInDays($endDate);
            $previousPeriodStart = $startDate->copy()->subDays($periodDays + 1);
            $previousPeriodEnd = $startDate->copy()->subDay();
        } else {
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
        }

        $prevTotalCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $prevActiveCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

        $prevTotalRevenue = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotIn('status', ['cancelled'])
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->sum('amount_paid');

        $prevPurchaseRevenue = AttractionPurchase::query()
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->whereNotIn('status', ['cancelled'])
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->sum('amount_paid');

        $prevEventPurchaseRevenue = EventPurchase::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->when($previousPeriodStart && $previousPeriodEnd, fn($q) => $q->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]))
            ->sum('amount_paid');

        $prevTotalRevenueSum = $prevTotalRevenue + $prevPurchaseRevenue + $prevEventPurchaseRevenue;

        $prevAvgRevenuePerCustomer = $prevTotalCustomers > 0 ? round($prevTotalRevenueSum / $prevTotalCustomers, 2) : 0;

        $prevNewCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])
            ->whereNotNull('guest_email')
            ->distinct('guest_email')
            ->count();

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

            $eventPurchaseCount = EventPurchase::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('guest_email')
                ->distinct('guest_email')
                ->count();

            $totalCount = $bookingCount + $purchaseCount + $eventPurchaseCount;
            $growth = $i < 8 ? ($totalCount - ($customerGrowth[$i-1]['customers'] ?? 0)) : 0;

            $customerGrowth[] = [
                'month' => $monthStart->format('M'),
                'customers' => $totalCount,
                'growth' => $growth,
            ];
        }

        $revenueTrend = [];
        for ($i = 8; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $bookingRevenue = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount_paid');

            $purchaseRevenue = AttractionPurchase::query()
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount_paid');

            $eventPurchaseRevenue = EventPurchase::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->sum('amount_paid');

            $bookingCount = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $revenueTrend[] = [
                'month' => $monthStart->format('M'),
                'revenue' => round($bookingRevenue + $purchaseRevenue + $eventPurchaseRevenue, 2),
                'bookings' => $bookingCount,
            ];
        }

        $bookingTimeDistribution = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->selectRaw('HOUR(booking_date) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'time' => date('g A', strtotime($item->hour . ':00')),
                'count' => $item->count,
            ]);

        $topBookingCustomers = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
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

        $activityHours = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as activity')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'hour' => date('g A', strtotime($item->hour . ':00')),
                'activity' => $item->activity,
            ]);

        $allCustomerEmails = Booking::query()
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->whereNotNull('guest_email')
            ->distinct()
            ->pluck('guest_email');

        $customerValues = [];
        foreach ($allCustomerEmails as $email) {
            $totalSpent = Booking::where('guest_email', $email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->sum('amount_paid');

            $purchaseSpent = AttractionPurchase::where('guest_email', $email)
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->sum('amount_paid');

            $eventPurchaseSpent = EventPurchase::where('guest_email', $email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('amount_paid');

            $customerValues[] = $totalSpent + $purchaseSpent + $eventPurchaseSpent;
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

        $repeatCustomers = [];
        for ($i = 8; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $monthCustomerEmails = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $allCustomers = $monthCustomerEmails->count();

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

        $topActivities = AttractionPurchase::query()
            ->with('attraction')
            ->when($locationId, function($q) use ($locationId) {
                $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
            })
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
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

        $topEvents = EventPurchase::query()
            ->with('event')
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
            ->whereNotNull('guest_email')
            ->selectRaw('guest_email, guest_name, event_id, COUNT(*) as purchases')
            ->groupBy('guest_email', 'guest_name', 'event_id')
            ->orderByDesc('purchases')
            ->limit(5)
            ->get()
            ->map(fn($item) => [
                'customer' => $item->guest_name,
                'event' => $item->event->name ?? 'N/A',
                'purchases' => $item->purchases,
            ]);

        $topPackages = Booking::query()
            ->with('package')
            ->when($locationId, fn($q) => $q->where('location_id', $locationId))
            ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
            ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
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
                ->sum('amount_paid');

            $purchaseSpent = AttractionPurchase::where('guest_email', $customer->guest_email)
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->sum('amount_paid');

            $eventPurchaseSpent = EventPurchase::where('guest_email', $customer->guest_email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('amount_paid');

            $bookingCount = Booking::where('guest_email', $customer->guest_email)
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->count();

            $isActive = $customer->last_activity >= now()->subDays(30);

            return [
                'id' => md5($customer->guest_email),
                'name' => $customer->guest_name,
                'email' => $customer->guest_email,
                'joinDate' => $customer->join_date,
                'totalSpent' => round($totalSpent + $purchaseSpent + $eventPurchaseSpent, 2),
                'bookings' => $bookingCount,
                'lastActivity' => $customer->last_activity,
                'status' => $isActive ? 'active' : 'inactive',
            ];
        });

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
                'topEvents' => $topEvents,
                'topPackages' => $topPackages,
                'recentCustomers' => $recentCustomers,
            ],
        ]);
    }

    public function exportAnalytics(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'location_id' => 'nullable|exists:locations,id',
            'date_range' => 'nullable|in:7d,30d,90d,1y,all,custom',
            'start_date' => 'nullable|date|required_if:date_range,custom',
            'end_date' => 'nullable|date|required_if:date_range,custom|after_or_equal:start_date',
            'format' => 'required|in:csv,pdf,receipt',
            'include_sections' => 'nullable|array',
            'include_sections.*' => 'in:customers,revenue,bookings,activities,packages,events',
        ]);

        $userId = $request->get('user_id') ?? auth()->id();
        $user = User::find($userId);
        $format = $validated['format'];
        $includeSections = $validated['include_sections'] ?? ['customers', 'revenue', 'bookings', 'activities', 'packages', 'events'];

        $dateRange = $request->get('date_range', '30d');
        $dateRangeDisplay = $dateRange;

        if ($dateRange === 'custom' && $request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
            $dateRangeDisplay = 'custom: ' . $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
        } elseif ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->get('start_date'))->startOfDay();
            $endDate = Carbon::parse($request->get('end_date'))->endOfDay();
            $dateRangeDisplay = 'custom: ' . $startDate->format('M d, Y') . ' - ' . $endDate->format('M d, Y');
        } else {
            $startDate = match($dateRange) {
                '7d' => now()->subDays(7),
                '30d' => now()->subDays(30),
                '90d' => now()->subDays(90),
                '1y' => now()->subYear(),
                'all' => null,
                default => now()->subDays(30),
            };
            $endDate = now();
        }

        $isCompanyAdmin = $user && $user->role === 'company_admin';
        $locationId = null;
        if ($isCompanyAdmin) {
            $locationId = $request->get('location_id') ? (int)$request->get('location_id') : null;
        } else {
            $locationId = $user ? $user->location_id : null;
        }

        $locationName = 'All Locations';
        if ($locationId) {
            $location = \App\Models\Location::find($locationId);
            $locationName = $location ? $location->name : 'Unknown Location';
        }

        $exportData = [];

        if (in_array('customers', $includeSections)) {
            $bookingEmails = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $purchaseEmails = AttractionPurchase::query()
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $eventPurchaseEmails = EventPurchase::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->distinct()
                ->pluck('guest_email');

            $allEmails = $bookingEmails->merge($purchaseEmails)->merge($eventPurchaseEmails)->unique();

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
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->count();

                $totalSpent = Booking::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->sum('amount_paid');

                $purchaseSpent = AttractionPurchase::where('guest_email', $email)
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->sum('amount_paid');

                $totalPurchases = AttractionPurchase::where('guest_email', $email)
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->count();

                $totalEventPurchases = EventPurchase::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->count();

                $eventPurchaseSpent = EventPurchase::where('guest_email', $email)
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                    ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->sum('amount_paid');

                $customerName = $firstBooking ? $firstBooking->guest_name :
                               ($lastBooking ? $lastBooking->guest_name : 'Unknown');

                $exportData['customers'][] = [
                    'name' => $customerName,
                    'email' => $email,
                    'total_bookings' => $totalBookings,
                    'total_purchases' => $totalPurchases,
                    'total_event_purchases' => $totalEventPurchases,
                    'total_spent' => round($totalSpent + $purchaseSpent + $eventPurchaseSpent, 2),
                    'first_visit' => $firstBooking ? $firstBooking->created_at->format('Y-m-d') : 'N/A',
                    'last_visit' => $lastBooking ? $lastBooking->created_at->format('Y-m-d') : 'N/A',
                ];
            }
        }

        if (in_array('revenue', $includeSections)) {
            $exportData['revenue_by_month'] = [];
            for ($i = 8; $i >= 0; $i--) {
                $monthStart = now()->subMonths($i)->startOfMonth();
                $monthEnd = now()->subMonths($i)->endOfMonth();

                $bookingRevenue = Booking::query()
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum('amount_paid');

                $purchaseRevenue = AttractionPurchase::query()
                    ->when($locationId, function($q) use ($locationId) {
                        $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                    })
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum('amount_paid');

                $eventPurchaseRevenue = EventPurchase::query()
                    ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                    ->whereNotIn('status', ['cancelled', 'refunded'])
                    ->whereBetween('created_at', [$monthStart, $monthEnd])
                    ->sum('amount_paid');

                $exportData['revenue_by_month'][] = [
                    'month' => $monthStart->format('M Y'),
                    'bookings_revenue' => round($bookingRevenue, 2),
                    'purchases_revenue' => round($purchaseRevenue, 2),
                    'event_purchases_revenue' => round($eventPurchaseRevenue, 2),
                    'total_revenue' => round($bookingRevenue + $purchaseRevenue + $eventPurchaseRevenue, 2),
                ];
            }
        }

        if (in_array('bookings', $includeSections)) {
            $exportData['top_customers'] = Booking::query()
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->selectRaw('guest_email, guest_name, COUNT(*) as booking_count, SUM(amount_paid) as total_spent')
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
            $exportData['top_activities'] = AttractionPurchase::query()
                ->with('attraction')
                ->when($locationId, function($q) use ($locationId) {
                    $q->whereHas('attraction', fn($query) => $query->where('location_id', $locationId));
                })
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->selectRaw('attraction_id, COUNT(*) as purchase_count, SUM(amount_paid) as total_revenue')
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
            $exportData['top_packages'] = Booking::query()
                ->with('package')
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotNull('guest_email')
                ->whereNotNull('package_id')
                ->selectRaw('package_id, COUNT(*) as booking_count, SUM(amount_paid) as total_revenue')
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

        if (in_array('events', $includeSections)) {
            $exportData['top_events'] = EventPurchase::query()
                ->with('event')
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->when($startDate && $endDate, fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]))
                ->when($startDate && !$endDate, fn($q) => $q->where('created_at', '>=', $startDate))
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->selectRaw('event_id, COUNT(*) as purchase_count, SUM(amount_paid) as total_revenue, SUM(quantity) as total_tickets')
                ->groupBy('event_id')
                ->orderByDesc('purchase_count')
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'event' => $item->event->name ?? 'N/A',
                    'purchases' => $item->purchase_count,
                    'tickets' => (int) $item->total_tickets,
                    'revenue' => round($item->total_revenue, 2),
                ])
                ->toArray();
        }

        if ($format === 'csv') {
            return $this->generateCSVExport($exportData, $locationName, $dateRangeDisplay);
        } elseif ($format === 'receipt') {
            return $this->generateReceiptExport($exportData, $locationName, $dateRangeDisplay, $user);
        } else {
            return $this->generatePDFExport($exportData, $locationName, $dateRangeDisplay, $user);
        }
    }

    private function generateCSVExport($data, $locationName, $dateRange)
    {
        $filename = 'customer_analytics_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($data, $locationName, $dateRange) {
            $file = fopen('php://output', 'w');

            fputcsv($file, ['Customer Analytics Report']);
            fputcsv($file, ['Location:', $locationName]);
            fputcsv($file, ['Date Range:', $dateRange]);
            fputcsv($file, ['Generated:', now()->format('Y-m-d H:i:s')]);
            fputcsv($file, []);

            if (isset($data['customers'])) {
                fputcsv($file, ['CUSTOMER LIST']);
                fputcsv($file, ['Name', 'Email', 'Total Bookings', 'Total Purchases', 'Total Event Purchases', 'Total Spent', 'First Visit', 'Last Visit']);
                foreach ($data['customers'] as $customer) {
                    fputcsv($file, [
                        $customer['name'],
                        $customer['email'],
                        $customer['total_bookings'],
                        $customer['total_purchases'],
                        $customer['total_event_purchases'] ?? 0,
                        '$' . number_format($customer['total_spent'], 2),
                        $customer['first_visit'],
                        $customer['last_visit'],
                    ]);
                }
                fputcsv($file, []);
            }

            if (isset($data['revenue_by_month'])) {
                fputcsv($file, ['REVENUE BY MONTH']);
                fputcsv($file, ['Month', 'Bookings Revenue', 'Purchases Revenue', 'Event Purchases Revenue', 'Total Revenue']);
                foreach ($data['revenue_by_month'] as $revenue) {
                    fputcsv($file, [
                        $revenue['month'],
                        '$' . number_format($revenue['bookings_revenue'], 2),
                        '$' . number_format($revenue['purchases_revenue'], 2),
                        '$' . number_format($revenue['event_purchases_revenue'] ?? 0, 2),
                        '$' . number_format($revenue['total_revenue'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

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

            if (isset($data['top_events'])) {
                fputcsv($file, ['TOP EVENTS']);
                fputcsv($file, ['Event', 'Purchases', 'Tickets', 'Revenue']);
                foreach ($data['top_events'] as $event) {
                    fputcsv($file, [
                        $event['event'],
                        $event['purchases'],
                        $event['tickets'],
                        '$' . number_format($event['revenue'], 2),
                    ]);
                }
                fputcsv($file, []);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

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

    private function generateReceiptExport($data, $locationName, $dateRange, $user)
    {
        $filename = 'customer_analytics_receipt_' . date('Y-m-d_His') . '.png';

        $width = 600;
        $lineHeight = 20;
        $padding = 20;

        $contentLines = 15; // Header lines
        if (isset($data['customers'])) $contentLines += min(count($data['customers']), 10) + 3;
        if (isset($data['revenue_by_month'])) $contentLines += min(count($data['revenue_by_month']), 9) + 3;
        if (isset($data['top_customers'])) $contentLines += min(count($data['top_customers']), 5) + 3;
        if (isset($data['top_activities'])) $contentLines += min(count($data['top_activities']), 5) + 3;
        if (isset($data['top_packages'])) $contentLines += min(count($data['top_packages']), 5) + 3;

        $height = ($contentLines * $lineHeight) + ($padding * 2);

        $image = imagecreate($width, $height);

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 100, 100, 100);

        imagefill($image, 0, 0, $white);

        $y = $padding;
        $font = 3; // Built-in font

        $drawText = function($text, $isBold = false) use ($image, &$y, $padding, $black, $lineHeight, $font) {
            imagestring($image, $font, $padding, $y, $text, $black);
            $y += $lineHeight;
        };

        $drawDivider = function() use ($image, &$y, $width, $gray, $lineHeight) {
            imageline($image, 10, $y + 5, $width - 10, $y + 5, $gray);
            $y += $lineHeight;
        };

        $drawText('CUSTOMER ANALYTICS REPORT', true);
        $drawText('Location: ' . $locationName);
        $drawText('Date Range: ' . $dateRange);
        $drawText('Generated: ' . now()->format('M d, Y - h:i A'));
        $generatedBy = $user ? $user->first_name . ' ' . $user->last_name : 'System';
        $drawText('By: ' . $generatedBy);
        $drawDivider();

        if (isset($data['customers']) && count($data['customers']) > 0) {
            $drawText('CUSTOMER LIST', true);
            $customers = array_slice($data['customers'], 0, 10);
            foreach ($customers as $customer) {
                $drawText(substr($customer['name'], 0, 30) . ' - $' . number_format($customer['total_spent'], 2));
            }
            $drawDivider();
        }

        if (isset($data['revenue_by_month']) && count($data['revenue_by_month']) > 0) {
            $drawText('REVENUE BY MONTH', true);
            $revenues = array_slice($data['revenue_by_month'], 0, 9);
            foreach ($revenues as $revenue) {
                $drawText($revenue['month'] . ': $' . number_format($revenue['total_revenue'], 2));
            }
            $drawDivider();
        }

        if (isset($data['top_customers']) && count($data['top_customers']) > 0) {
            $drawText('TOP CUSTOMERS', true);
            $topCustomers = array_slice($data['top_customers'], 0, 5);
            foreach ($topCustomers as $customer) {
                $drawText(substr($customer['name'], 0, 25) . ' - ' . $customer['bookings'] . ' bookings');
            }
            $drawDivider();
        }

        if (isset($data['top_activities']) && count($data['top_activities']) > 0) {
            $drawText('TOP ACTIVITIES', true);
            $topActivities = array_slice($data['top_activities'], 0, 5);
            foreach ($topActivities as $activity) {
                $drawText(substr($activity['activity'], 0, 30) . ' - ' . $activity['purchases'] . ' sales');
            }
            $drawDivider();
        }

        if (isset($data['top_packages']) && count($data['top_packages']) > 0) {
            $drawText('TOP PACKAGES', true);
            $topPackages = array_slice($data['top_packages'], 0, 5);
            foreach ($topPackages as $package) {
                $drawText(substr($package['package'], 0, 30) . ' - ' . $package['bookings'] . ' bookings');
            }
        }

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
