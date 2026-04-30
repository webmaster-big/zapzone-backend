<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    use ScopesByAuthUser;

    public function index(): JsonResponse
    {
        $query = Location::with(['company', 'packages'])->active();

        // Multi-tenant + role-based scoping (driven by Sanctum auth user)
        $authUser = auth()->user();
        if ($authUser) {
            if ($authUser->company_id) {
                $query->where('company_id', $authUser->company_id);
            }
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where('id', $authUser->location_id);
            }
        }

        $locations = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $locations,
        ]);
    }

    /**
     * Store a newly created location.
     */
    public function store(Request $request): JsonResponse
    {
        $authUser = $request->user();

        // Only company_admin may create locations.
        if (!$authUser || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: only company admins may create locations',
            ], 403);
        }

        $validated = $request->validate([
            'company_id' => 'sometimes|exists:companies,id',
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zip_code' => 'required|string|max:20',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|unique:locations',
            'timezone' => 'string|max:50',
            'is_active' => 'boolean',
        ]);

        // Force company_id from the auth user (cannot create for another company).
        if ($authUser->company_id) {
            if (isset($validated['company_id']) && (int) $validated['company_id'] !== (int) $authUser->company_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: cannot create a location for another company',
                ], 403);
            }
            $validated['company_id'] = $authUser->company_id;
        } elseif (empty($validated['company_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'company_id is required',
                'errors'  => ['company_id' => ['Required.']],
            ], 422);
        }

        $location = Location::create($validated);
        $location->load(['company', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Location created successfully',
            'data' => $location,
        ], 201);
    }

    /**
     * Display the specified location.
     */
    public function show(Location $location): JsonResponse
    {
        if (!$this->authorizeRecordScope($location, locationCol: 'id', companyCol: 'company_id')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $location->load(['company', 'packages', 'users']);

        return response()->json([
            'success' => true,
            'data' => $location,
        ]);
    }

    /**
     * Update the specified location.
     */
    public function update(Request $request, Location $location): JsonResponse
    {
        if (!$this->authorizeRecordScope($location, locationCol: 'id', companyCol: 'company_id')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'company_id' => 'sometimes|exists:companies,id',
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string|max:255',
            'state' => 'sometimes|string|max:255',
            'zip_code' => 'sometimes|string|max:20',
            'phone' => 'sometimes|string|max:20',
            'email' => 'sometimes|email|unique:locations,email,' . $location->id,
            'timezone' => 'sometimes|string|max:50',
            'is_active' => 'boolean',
        ]);

        $location->update($validated);
        $location->load(['company', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
            'data' => $location,
        ]);
    }

    /**
     * Remove the specified location.
     */
    public function destroy(Location $location): JsonResponse
    {
        if (!$this->authorizeRecordScope($location, locationCol: 'id', companyCol: 'company_id')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $locationName = $location->name;
        $locationId = $location->id;
        $companyId = $location->company_id;

        $location->delete();

        // Log location deletion
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Location Deleted',
            category: 'delete',
            description: "Location '{$locationName}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'location',
            entityId: $locationId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'location_details' => [
                    'location_id' => $locationId,
                    'name' => $locationName,
                    'company_id' => $companyId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully',
        ]);
    }

    /**
     * Get locations by company.
     */
    public function getByCompany(int $companyId): JsonResponse
    {
        if ($scopeError = $this->guardCompanyAccess(null, $companyId)) {
            return $scopeError;
        }

        $query = Location::with(['packages'])
            ->byCompany($companyId)
            ->active();

        // location_manager/attendant must only see their own location
        $authUser = auth()->user();
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $query->where('id', $authUser->location_id);
        }

        $locations = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $locations,
        ]);
    }

    /**
     * Toggle location active status.
     */
    public function toggleStatus(Location $location): JsonResponse
    {
        if (!$this->authorizeRecordScope($location, locationCol: 'id', companyCol: 'company_id')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $location->update(['is_active' => !$location->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Location status updated successfully',
            'data' => $location,
        ]);
    }

    /**
     * Get location statistics.
     */
    public function statistics(Location $location): JsonResponse
    {
        if (!$this->authorizeRecordScope($location, locationCol: 'id', companyCol: 'company_id')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $stats = [
            'total_packages' => $location->packages()->count(),
            'active_packages' => $location->packages()->where('is_active', true)->count(),
            'total_bookings' => $location->packages()->withCount('bookings')->get()->sum('bookings_count'),
            'recent_bookings' => $location->packages()->whereHas('bookings', function ($query) {
                $query->where('created_at', '>=', now()->subDays(30));
            })->withCount('bookings')->get()->sum('bookings_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
