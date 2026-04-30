<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PromoController extends Controller
{
    use ScopesByAuthUser;

    /**
     * Display a listing of promos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Promo::with(['creator', 'packages']);

        // Multi-tenant + role-based scoping. Promos do not have a direct
        // location_id/company_id, so we scope by creator and/or attached package's location.
        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where(function ($q) use ($authUser) {
                    $q->whereHas('packages', fn($p) => $p->where('location_id', $authUser->location_id))
                      ->orWhereHas('creator', fn($u) => $u->where('location_id', $authUser->location_id));
                });
            } elseif ($authUser->company_id) {
                $companyId = $authUser->company_id;
                $query->where(function ($q) use ($companyId) {
                    $q->whereHas('packages.location', fn($l) => $l->where('company_id', $companyId))
                      ->orWhereHas('creator', fn($u) => $u->where('company_id', $companyId));
                });
            }
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->active();
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by validity
        if ($request->has('only_valid')) {
            if ($request->boolean('only_valid')) {
                $query->valid();
            }
        }

        // Search by code, name, or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['code', 'name', 'type', 'value', 'start_date', 'end_date', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $promos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'promos' => $promos->items(),
                'pagination' => [
                    'current_page' => $promos->currentPage(),
                    'last_page' => $promos->lastPage(),
                    'per_page' => $promos->perPage(),
                    'total' => $promos->total(),
                    'from' => $promos->firstItem(),
                    'to' => $promos->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created promo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|unique:promos',
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'usage_limit_total' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'integer|min:1',
            'description' => 'nullable|string',
            'created_by' => 'required|exists:users,id',
        ]);

        // Generate unique code if not provided
        if (!isset($validated['code'])) {
            do {
                $validated['code'] = 'PROMO' . strtoupper(Str::random(6));
            } while (Promo::where('code', $validated['code'])->exists());
        }

        $promo = Promo::create($validated);
        $promo->load(['creator', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Promo created successfully',
            'data' => $promo,
        ], 201);
    }

    /**
     * Display the specified promo.
     */
    public function show(Promo $promo): JsonResponse
    {
        $promo->load(['creator', 'packages']);

        return response()->json([
            'success' => true,
            'data' => $promo,
        ]);
    }

    /**
     * Update the specified promo.
     */
    public function update(Request $request, Promo $promo): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'sometimes|string|unique:promos,code,' . $promo->id,
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'value' => 'sometimes|numeric|min:0',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'usage_limit_total' => 'sometimes|nullable|integer|min:1',
            'usage_limit_per_user' => 'sometimes|integer|min:1',
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'expired', 'exhausted'])],
            'description' => 'sometimes|nullable|string',
        ]);

        $promo->update($validated);
        $promo->load(['creator', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Promo updated successfully',
            'data' => $promo,
        ]);
    }

    /**
     * Remove the specified promo.
     */
    public function destroy(Promo $promo): JsonResponse
    {
        $promoCode = $promo->code;
        $promoId = $promo->id;

        $promo->update(['deleted' => true, 'status' => 'inactive']);

        // Log promo deletion
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Promo Deleted',
            category: 'delete',
            description: "Promo code '{$promoCode}' was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: $promoId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'promo_details' => [
                    'promo_id' => $promoId,
                    'code' => $promoCode,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Promo deleted successfully',
        ]);
    }

    /**
     * Validate promo by code.
     */
    public function validateByCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $promo = Promo::byCode($request->code)->first();

        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found',
            ], 404);
        }

        $isValid = $promo->isValid();

        return response()->json([
            'success' => true,
            'data' => [
                'promo' => $promo,
                'is_valid' => $isValid,
                'expired' => $promo->isExpired(),
                'started' => $promo->hasStarted(),
                'usage_remaining' => $promo->usage_limit_total ?
                    max(0, $promo->usage_limit_total - $promo->current_usage) :
                    null,
            ],
        ]);
    }

    /**
     * Apply promo code.
     */
    public function apply(Request $request, Promo $promo): JsonResponse
    {
        if (!$promo->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is not valid',
            ], 400);
        }

        // Increment usage
        $promo->increment('current_usage');

        // Check if exhausted
        if ($promo->usage_limit_total && $promo->current_usage >= $promo->usage_limit_total) {
            $promo->update(['status' => 'exhausted']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Promo code applied successfully',
            'data' => [
                'promo' => $promo->fresh(),
                'discount_amount' => $promo->value,
                'discount_type' => $promo->type,
            ],
        ]);
    }

    /**
     * Get valid promos.
     */
    public function getValid(Request $request): JsonResponse
    {
        $promos = Promo::with(['packages'])
            ->valid()
            ->orderBy('end_date', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promos,
        ]);
    }

    /**
     * Toggle promo status.
     */
    public function toggleStatus(Promo $promo): JsonResponse
    {
        $newStatus = $promo->status === 'active' ? 'inactive' : 'active';
        $promo->update(['status' => $newStatus]);

        return response()->json([
            'success' => true,
            'message' => 'Promo status updated successfully',
            'data' => $promo,
        ]);
    }

    /**
     * Generate unique promo codes in bulk.
     */
    public function generateBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['fixed', 'percentage'])],
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
            'created_by' => 'required|exists:users,id',
            'quantity' => 'required|integer|min:1|max:1000',
            'code_prefix' => 'nullable|string|max:10|alpha_num',
            'code_length' => 'nullable|integer|min:4|max:16',
            'usage_limit_per_code' => 'nullable|integer|min:1',
        ]);

        $quantity = $validated['quantity'];
        $prefix = strtoupper($validated['code_prefix'] ?? 'ZAP');
        $codeLength = $validated['code_length'] ?? 8;
        $batchId = (string) Str::uuid();

        $codes = [];
        $existingCodes = Promo::pluck('code')->flip();
        $attempts = 0;
        $maxAttempts = $quantity * 10;

        while (count($codes) < $quantity && $attempts < $maxAttempts) {
            $attempts++;
            $randomPart = strtoupper(Str::random(max(1, $codeLength - strlen($prefix))));
            $code = $prefix . $randomPart;

            if (!isset($existingCodes[$code]) && !isset($codes[$code])) {
                $codes[$code] = true;
            }
        }

        if (count($codes) < $quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Could not generate enough unique codes. Try a longer code length or different prefix.',
            ], 422);
        }

        $promos = [];
        $now = now();

        foreach (array_keys($codes) as $code) {
            $promos[] = [
                'code' => $code,
                'code_mode' => 'unique',
                'batch_id' => $batchId,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'usage_limit_total' => $validated['usage_limit_per_code'] ?? 1,
                'usage_limit_per_user' => 1,
                'current_usage' => 0,
                'status' => 'active',
                'description' => $validated['description'] ?? null,
                'created_by' => $validated['created_by'],
                'deleted' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insert in chunks for performance
        foreach (array_chunk($promos, 100) as $chunk) {
            Promo::insert($chunk);
        }

        $createdPromos = Promo::where('batch_id', $batchId)->get();

        // Log bulk generation
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Promo Generated',
            category: 'create',
            description: "Generated {$quantity} unique promo codes (batch: {$batchId})",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: null,
            metadata: [
                'generated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'prefix' => $prefix,
                'promo_name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$quantity} unique promo codes generated successfully",
            'data' => [
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'prefix' => $prefix,
                'sample_codes' => $createdPromos->take(5)->pluck('code'),
                'promo_name' => $validated['name'],
                'type' => $validated['type'],
                'value' => $validated['value'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
        ], 201);
    }

    /**
     * List all batches of bulk-generated promo codes.
     */
    public function listBatches(Request $request): JsonResponse
    {
        $batches = Promo::whereNotNull('batch_id')
            ->where('deleted', false)
            ->selectRaw('batch_id, name, type, value, start_date, end_date, MIN(created_at) as created_at, COUNT(*) as total_codes, SUM(current_usage) as total_used, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_codes, SUM(CASE WHEN status = "exhausted" THEN 1 ELSE 0 END) as exhausted_codes')
            ->groupBy('batch_id', 'name', 'type', 'value', 'start_date', 'end_date')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    /**
     * Show details of a specific batch.
     */
    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        $promos = Promo::where('batch_id', $batchId)
            ->where('deleted', false);

        // Filter by status within batch
        if ($request->has('status')) {
            $promos->where('status', $request->status);
        }

        // Filter used/unused
        if ($request->has('used')) {
            if ($request->boolean('used')) {
                $promos->where('current_usage', '>', 0);
            } else {
                $promos->where('current_usage', 0);
            }
        }

        $sortBy = $request->get('sort_by', 'code');
        $sortOrder = $request->get('sort_order', 'asc');
        if (in_array($sortBy, ['code', 'status', 'current_usage', 'created_at'])) {
            $promos->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 50);
        $result = $promos->paginate($perPage);

        // Get batch summary
        $summary = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->selectRaw('COUNT(*) as total_codes, SUM(current_usage) as total_used, SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_codes, SUM(CASE WHEN status = "exhausted" THEN 1 ELSE 0 END) as exhausted_codes, SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive_codes')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => $batchId,
                'summary' => $summary,
                'promos' => $result->items(),
                'pagination' => [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'from' => $result->firstItem(),
                    'to' => $result->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Export batch codes to CSV.
     */
    public function exportBatchCsv(Request $request, string $batchId): StreamedResponse
    {
        $promos = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->orderBy('code')
            ->get();

        if ($promos->isEmpty()) {
            abort(404, 'Batch not found or has no codes');
        }

        $first = $promos->first();
        $fileName = 'promo_codes_' . Str::slug($first->name) . '_' . $batchId . '.csv';

        return response()->streamDownload(function () use ($promos) {
            $handle = fopen('php://output', 'w');

            // CSV header row
            fputcsv($handle, [
                'Code',
                'Name',
                'Type',
                'Value',
                'Start Date',
                'End Date',
                'Status',
                'Usage Limit',
                'Current Usage',
                'Created At',
            ]);

            foreach ($promos as $promo) {
                fputcsv($handle, [
                    $promo->code,
                    $promo->name,
                    $promo->type,
                    $promo->value,
                    $promo->start_date->format('Y-m-d'),
                    $promo->end_date->format('Y-m-d'),
                    $promo->status,
                    $promo->usage_limit_total,
                    $promo->current_usage,
                    $promo->created_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Deactivate all codes in a batch.
     */
    public function deactivateBatch(string $batchId): JsonResponse
    {
        $count = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => "{$count} promo codes deactivated",
            'data' => ['deactivated_count' => $count],
        ]);
    }

    /**
     * Delete (soft) all codes in a batch.
     */
    public function destroyBatch(string $batchId): JsonResponse
    {
        $count = Promo::where('batch_id', $batchId)
            ->where('deleted', false)
            ->update(['deleted' => true, 'status' => 'inactive']);

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Promo Batch Deleted',
            category: 'delete',
            description: "Deleted batch {$batchId} ({$count} codes)",
            userId: auth()->id(),
            locationId: null,
            entityType: 'promo',
            entityId: null,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'batch_id' => $batchId,
                'deleted_count' => $count,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} promo codes deleted",
            'data' => ['deleted_count' => $count],
        ]);
    }
}
