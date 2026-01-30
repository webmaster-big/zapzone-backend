<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{

    public function index(): JsonResponse
    {
        $locations = Location::with(['company', 'packages'])
            ->active()
            ->orderBy('name')
            ->get();

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
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
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
        $locations = Location::with(['packages'])
            ->byCompany($companyId)
            ->active()
            ->orderBy('name')
            ->get();

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
