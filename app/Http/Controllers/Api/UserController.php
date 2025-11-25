<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['company', 'location']);
        $authUser = $request->user();

        // Role-based filtering
        if ($authUser) {
            if ($authUser->role === 'company_admin') {
                // Company admin can only see users from their company
                $query->byCompany($authUser->company_id);
            } elseif ($authUser->role === 'location_manager') {
                // Location manager can only see users from their location
                $query->byCompany($authUser->company_id)
                      ->byLocation($authUser->location_id);
            }
            // super_admin or other roles can see all users
        }

        // Filter by company (only if company_admin or above)
        if ($request->has('company_id') && (!$authUser || in_array($authUser->role, ['super_admin']))) {
            $query->byCompany($request->company_id);
        }

        // Filter by location (company_admin can filter by location)
        if ($request->has('location_id')) {
            if (!$authUser || $authUser->role !== 'location_manager') {
                $query->byLocation($request->location_id);
            }
        }

        // Filter by role
        if ($request->has('role')) {
            $query->byRole($request->role);
        }

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
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'first_name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['first_name', 'last_name', 'email', 'role', 'created_at', 'last_login'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users->items(),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', Rule::in(['company_admin', 'location_manager', 'attendant'])],
            'employee_id' => 'nullable|string|unique:users',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'shift' => 'nullable|string|max:255',
            'assigned_areas' => 'nullable|array',
            'hire_date' => 'nullable|date',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'sometimes|nullable|exists:companies,id',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|string|min:8|confirmed',
            'role' => ['sometimes', Rule::in(['company_admin', 'location_manager', 'attendant'])],
            'employee_id' => 'sometimes|nullable|string|unique:users,employee_id,' . $user->id,
            'department' => 'sometimes|nullable|string|max:255',
            'position' => 'sometimes|nullable|string|max:255',
            'shift' => 'sometimes|nullable|string|max:255',
            'assigned_areas' => 'sometimes|nullable|array',
            'hire_date' => 'sometimes|nullable|date',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        $user->load(['company', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Get users by company.
     */
    public function getByCompany(int $companyId): JsonResponse
    {
        $users = User::with(['location'])
            ->byCompany($companyId)
            ->active()
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get users by location.
     */
    public function getByLocation(int $locationId): JsonResponse
    {
        $users = User::with(['company'])
            ->byLocation($locationId)
            ->active()
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get users by role.
     */
    public function getByRole(string $role): JsonResponse
    {
        $users = User::with(['company', 'location'])
            ->byRole($role)
            ->active()
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Toggle user status.
     */
    public function toggleStatus(User $user): JsonResponse
    {
        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'User status updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Update last login timestamp.
     */
    public function updateLastLogin(User $user): JsonResponse
    {
        $user->update(['last_login' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Last login updated successfully',
        ]);
    }
}
