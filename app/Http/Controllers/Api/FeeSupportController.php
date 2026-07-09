<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FeeSupport;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FeeSupportController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 500);

            $query = FeeSupport::with(['company:id,company_name', 'location:id,name']);

            $authUser = $this->resolveAuthUser($request);
            if ($authUser && $authUser->company_id) {
                $query->where('company_id', $authUser->company_id);
            }
            if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where(function ($q) use ($authUser) {
                    $q->where('location_id', $authUser->location_id)
                      ->orWhereNull('location_id');
                });
            }

            if ($request->has('company_id')) {
                $query->byCompany($request->company_id);
            }

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }

            if ($request->has('fee_calculation_type')) {
                $query->where('fee_calculation_type', $request->fee_calculation_type);
            }

            if ($request->has('fee_application_type')) {
                $query->where('fee_application_type', $request->fee_application_type);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('search')) {
                $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($terms as $term) {
                    $like = '%' . $term . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('fee_name', 'like', $like)
                          ->orWhere('entity_type', 'like', $like);
                    });
                }
            }

            $sortBy = $request->get('sort_by', 'fee_name');
            $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));
            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'asc';
            }

            if (in_array($sortBy, ['fee_name', 'fee_amount', 'fee_calculation_type', 'fee_application_type', 'entity_type', 'is_active', 'created_at', 'updated_at'])) {
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
            'entity_type' => 'required|in:package,attraction,event,membership',
            'is_active' => 'boolean',
        ]);

        $feeSupport = FeeSupport::create($validated);
        $feeSupport->load(['company:id,company_name', 'location:id,name']);

        $currentUser = $request->user();
        ActivityLog::log(
            action: 'Fee Support Created',
            category: 'create',
            description: "Fee support '{$feeSupport->fee_name}' created",
            userId: $currentUser?->id,
            locationId: $feeSupport->location_id,
            entityType: 'fee_support',
            entityId: $feeSupport->id,
            metadata: [
                'created_by' => [
                    'user_id' => $currentUser?->id,
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'fee_support_details' => $feeSupport->toArray(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Fee support created successfully',
            'data' => $feeSupport,
        ], 201);
    }

    public function show(FeeSupport $feeSupport): JsonResponse
    {
        $feeSupport->load(['company:id,company_name', 'location:id,name']);

        return response()->json([
            'success' => true,
            'data' => $feeSupport,
        ]);
    }

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
            'entity_type' => 'sometimes|in:package,attraction,event,membership',
            'is_active' => 'sometimes|boolean',
        ]);

        $feeSupport->update($validated);
        $feeSupport->refresh();
        $feeSupport->load(['company:id,company_name', 'location:id,name']);

        $currentUser = $request->user();
        ActivityLog::log(
            action: 'Fee Support Updated',
            category: 'update',
            description: "Fee support '{$originalName}' updated",
            userId: $currentUser?->id,
            locationId: $feeSupport->location_id,
            entityType: 'fee_support',
            entityId: $feeSupport->id,
            metadata: [
                'updated_by' => [
                    'user_id' => $currentUser?->id,
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'original' => $originalData,
                'updated' => $feeSupport->toArray(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Fee support updated successfully',
            'data' => $feeSupport,
        ]);
    }

    public function destroy(Request $request, FeeSupport $feeSupport): JsonResponse
    {
        $feeName = $feeSupport->fee_name;

        $currentUser = $request->user();
        ActivityLog::log(
            action: 'Fee Support Deleted',
            category: 'delete',
            description: "Fee support '{$feeName}' deleted",
            userId: $currentUser?->id,
            locationId: $feeSupport->location_id,
            entityType: 'fee_support',
            entityId: $feeSupport->id,
            metadata: [
                'deleted_by' => [
                    'user_id' => $currentUser?->id,
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'fee_support_details' => $feeSupport->toArray(),
            ]
        );

        $feeSupport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fee support deleted successfully',
        ]);
    }

    public function toggleStatus(Request $request, FeeSupport $feeSupport): JsonResponse
    {
        $feeSupport->is_active = !$feeSupport->is_active;
        $feeSupport->save();

        $status = $feeSupport->is_active ? 'activated' : 'deactivated';

        $currentUser = $request->user();
        ActivityLog::log(
            action: "Fee Support {$status}",
            category: 'update',
            description: "Fee support '{$feeSupport->fee_name}' {$status}",
            userId: $currentUser?->id,
            locationId: $feeSupport->location_id,
            entityType: 'fee_support',
            entityId: $feeSupport->id,
            metadata: [
                'toggled_by' => [
                    'user_id' => $currentUser?->id,
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'is_active' => $feeSupport->is_active,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "Fee support {$status} successfully",
            'data' => $feeSupport,
        ]);
    }

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

    public function getForEntity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:package,attraction,event,membership',
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

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:fee_supports,id',
        ]);

        $count = FeeSupport::whereIn('id', $validated['ids'])->delete();

        $currentUser = $request->user();
        ActivityLog::log(
            action: 'Fee Supports Bulk Deleted',
            category: 'delete',
            description: "{$count} fee support(s) deleted",
            userId: $currentUser?->id,
            metadata: [
                'deleted_by' => [
                    'user_id' => $currentUser?->id,
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_count' => $count,
                'deleted_ids' => $validated['ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} fee support(s) deleted successfully",
        ]);
    }
}
