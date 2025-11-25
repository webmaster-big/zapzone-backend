<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PromoController extends Controller
{
    /**
     * Display a listing of promos.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Promo::with(['creator', 'packages']);

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
        $promo->update(['deleted' => true, 'status' => 'inactive']);

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
}
