<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FeeSupport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FeeSupportController extends Controller
{
    /**
     * Display a listing of fee supports.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 500);

            $query = FeeSupport::with(['company:id,name', 'location:id,name']);

            // Role-based filtering
            if ($request->has('user_id')) {
                $authUser = User::where('id', $request->user_id)->first();
                if ($authUser && $authUser->role === 'location_manager') {
                    $query->where(function ($q) use ($authUser) {
                        $q->where('location_id', $authUser->location_id)
                          ->orWhereNull('location_id');
                    });
                }
            }

            // Filter by company
            if ($request->has('company_id')) {
                $query->byCompany($request->company_id);
            }

            // Filter by location
            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            // Filter by entity type (package or attraction)
            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }

            // Filter by fee calculation type
            if ($request->has('fee_calculation_type')) {
                $query->where('fee_calculation_type', $request->fee_calculation_type);
            }

            // Filter by fee application type
            if ($request->has('fee_application_type')) {
                $query->where('fee_application_type', $request->fee_application_type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by fee name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where('fee_name', 'like', "%{$search}%");
            }

            // Sort
            $sortBy = $request->get('sort_by', 'fee_name');
            $sortOrder = $request->get('sort_order', 'asc');

            if (in_array($sortBy, ['fee_name', 'fee_amount', 'fee_calculation_type', 'fee_application_type', 'entity_type', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

            $feeSupports = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'fee_supports' => $feeSupports->items(),
                    'pagination' => [
                        'current_page' => $feeSupports->currentPage(),
                        'last_page' => $feeSupports->lastPage(),
                        'per_page' => $feeSupports->perPage(),
                        'total' => $feeSupports->total(),
                        'from' => $feeSupports->firstItem(),
                        'to' => $feeSupports->lastItem(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching fee supports', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch fee supports',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Store a newly created fee support.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'fee_name' => 'required|string|max:255',
            'fee_amount' => 'required|numeric|min:0',
            'fee_calculation_type' => 'required|in:fixed,percentage',
            'fee_application_type' => 'required|in:additive,inclusive',
            'entity_ids' => 'required|array|min:1',
            'entity_ids.*' => 'integer',
            'entity_type' => 'required|in:package,attraction',
            'is_active' => 'boolean',
        ]);

        $feeSupport = FeeSupport::create($validated);
        $feeSupport->load(['company:id,name', 'location:id,name']);

        // Log activity
        ActivityLog::log(
            'fee_support',
            'created',
            "Fee support '{$feeSupport->fee_name}' created",
            $request->user()?->id,
            $feeSupport->id,
            null,
            $feeSupport->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Fee support created successfully',
            'data' => $feeSupport,
        ], 201);
    }

    /**
     * Display the specified fee support.
     */
    public function show(FeeSupport $feeSupport): JsonResponse
    {
        $feeSupport->load(['company:id,name', 'location:id,name']);

        return response()->json([
            'success' => true,
            'data' => $feeSupport,
        ]);
    }

    /**
     * Update the specified fee support.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $feeSupport = FeeSupport::findOrFail($id);

        $originalName = $feeSupport->fee_name;
        $originalData = $feeSupport->toArray();

        $validated = $request->validate([
            'company_id' => 'sometimes|exists:companies,id',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'fee_name' => 'sometimes|string|max:255',
            'fee_amount' => 'sometimes|numeric|min:0',
            'fee_calculation_type' => 'sometimes|in:fixed,percentage',
            'fee_application_type' => 'sometimes|in:additive,inclusive',
            'entity_ids' => 'sometimes|array|min:1',
            'entity_ids.*' => 'integer',
            'entity_type' => 'sometimes|in:package,attraction',
            'is_active' => 'sometimes|boolean',
        ]);

        $feeSupport->update($validated);
        $feeSupport->refresh();
        $feeSupport->load(['company:id,name', 'location:id,name']);

        // Log activity
        ActivityLog::log(
            'fee_support',
            'updated',
            "Fee support '{$originalName}' updated",
            $request->user()?->id,
            $feeSupport->id,
            $originalData,
            $feeSupport->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Fee support updated successfully',
            'data' => $feeSupport,
        ]);
    }

    /**
     * Remove the specified fee support.
     */
    public function destroy(Request $request, FeeSupport $feeSupport): JsonResponse
    {
        $feeName = $feeSupport->fee_name;

        // Log activity before deletion
        ActivityLog::log(
            'fee_support',
            'deleted',
            "Fee support '{$feeName}' deleted",
            $request->user()?->id,
            $feeSupport->id,
            $feeSupport->toArray(),
            null
        );

        $feeSupport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fee support deleted successfully',
        ]);
    }

    /**
     * Toggle active status for a fee support.
     */
    public function toggleStatus(Request $request, FeeSupport $feeSupport): JsonResponse
    {
        $feeSupport->is_active = !$feeSupport->is_active;
        $feeSupport->save();

        $status = $feeSupport->is_active ? 'activated' : 'deactivated';

        // Log activity
        ActivityLog::log(
            'fee_support',
            'status_toggled',
            "Fee support '{$feeSupport->fee_name}' {$status}",
            $request->user()?->id,
            $feeSupport->id,
            null,
            ['is_active' => $feeSupport->is_active]
        );

        return response()->json([
            'success' => true,
            'message' => "Fee support {$status} successfully",
            'data' => $feeSupport,
        ]);
    }

    /**
     * Get fee supports by location.
     */
    public function getByLocation($locationId): JsonResponse
    {
        $feeSupports = FeeSupport::where(function ($q) use ($locationId) {
            $q->where('location_id', $locationId)
              ->orWhereNull('location_id');
        })
        ->active()
        ->get();

        return response()->json([
            'success' => true,
            'data' => $feeSupports,
        ]);
    }

    /**
     * Get applicable fees for a specific entity (package or attraction).
     * This is the main endpoint used during booking/purchase flows.
     */
    public function getForEntity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:package,attraction',
            'entity_id' => 'required|integer',
            'base_price' => 'required|numeric|min:0',
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        $fees = FeeSupport::getFeesForEntity(
            $validated['entity_type'],
            $validated['entity_id'],
            $validated['location_id'] ?? null
        );

        $breakdown = FeeSupport::getFullPriceBreakdown(
            $validated['entity_type'],
            $validated['entity_id'],
            (float) $validated['base_price'],
            $validated['location_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $breakdown,
        ]);
    }

    /**
     * Bulk delete fee supports.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:fee_supports,id',
        ]);

        $count = FeeSupport::whereIn('id', $validated['ids'])->delete();

        // Log activity
        ActivityLog::log(
            'fee_support',
            'bulk_deleted',
            "{$count} fee support(s) deleted",
            $request->user()?->id,
            null,
            ['deleted_ids' => $validated['ids']],
            null
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} fee support(s) deleted successfully",
        ]);
    }
}
