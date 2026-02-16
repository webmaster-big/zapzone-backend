<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SpecialPricing;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SpecialPricingController extends Controller
{
    /**
     * Display a listing of special pricings.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 500);

            $query = SpecialPricing::with(['company:id,name', 'location:id,name']);

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

            // Filter by entity type
            if ($request->has('entity_type')) {
                $entityType = $request->entity_type;
                if ($entityType === 'package') {
                    $query->forPackages();
                } elseif ($entityType === 'attraction') {
                    $query->forAttractions();
                }
            }

            // Filter by recurrence type
            if ($request->has('recurrence_type')) {
                $query->where('recurrence_type', $request->recurrence_type);
            }

            // Filter by discount type
            if ($request->has('discount_type')) {
                $query->where('discount_type', $request->discount_type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter upcoming only (active and within date range)
            if ($request->boolean('upcoming_only')) {
                $query->active()->withinDateRange();
            }

            // Search by name or description
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'priority');
            $sortOrder = $request->get('sort_order', 'desc');

            if (in_array($sortBy, ['name', 'discount_amount', 'recurrence_type', 'priority', 'start_date', 'end_date', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

            $specialPricings = $query->paginate($perPage);

            // Add recurrence display to each item
            $items = collect($specialPricings->items())->map(function ($item) {
                $item->recurrence_display = $item->getRecurrenceDisplay();
                $item->upcoming_dates = $item->getUpcomingDates(3);
                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'special_pricings' => $items,
                    'pagination' => [
                        'current_page' => $specialPricings->currentPage(),
                        'last_page' => $specialPricings->lastPage(),
                        'per_page' => $specialPricings->perPage(),
                        'total' => $specialPricings->total(),
                        'from' => $specialPricings->firstItem(),
                        'to' => $specialPricings->lastItem(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching special pricings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch special pricings',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Store a newly created special pricing.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'discount_amount' => 'required|numeric|min:0',
            'discount_type' => 'required|in:fixed,percentage',
            'recurrence_type' => 'required|in:one_time,weekly,monthly',
            'recurrence_value' => 'nullable|integer|min:0|max:31',
            'specific_date' => 'nullable|date|required_if:recurrence_type,one_time',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'time_start' => 'nullable|date_format:H:i',
            'time_end' => 'nullable|date_format:H:i|after:time_start',
            'entity_type' => 'required|in:package,attraction,all',
            'entity_ids' => 'nullable|array',
            'entity_ids.*' => 'integer',
            'priority' => 'nullable|integer|min:0',
            'is_stackable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        // Validate recurrence_value based on recurrence_type
        if ($validated['recurrence_type'] === 'weekly') {
            if (!isset($validated['recurrence_value']) || $validated['recurrence_value'] < 0 || $validated['recurrence_value'] > 6) {
                return response()->json([
                    'success' => false,
                    'message' => 'For weekly recurrence, recurrence_value must be 0-6 (Sunday-Saturday)',
                ], 422);
            }
        } elseif ($validated['recurrence_type'] === 'monthly') {
            if (!isset($validated['recurrence_value']) || $validated['recurrence_value'] < 1 || $validated['recurrence_value'] > 31) {
                return response()->json([
                    'success' => false,
                    'message' => 'For monthly recurrence, recurrence_value must be 1-31 (day of month)',
                ], 422);
            }
        }

        // Validate percentage doesn't exceed 100
        if ($validated['discount_type'] === 'percentage' && $validated['discount_amount'] > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        $specialPricing = SpecialPricing::create($validated);
        $specialPricing->load(['company:id,name', 'location:id,name']);
        $specialPricing->recurrence_display = $specialPricing->getRecurrenceDisplay();
        $specialPricing->upcoming_dates = $specialPricing->getUpcomingDates(3);

        // Log activity
        ActivityLog::log(
            'special_pricing',
            'created',
            "Special pricing '{$specialPricing->name}' created",
            $request->user()?->id,
            $specialPricing->id,
            null,
            $specialPricing->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Special pricing created successfully',
            'data' => $specialPricing,
        ], 201);
    }

    /**
     * Display the specified special pricing.
     */
    public function show(SpecialPricing $specialPricing): JsonResponse
    {
        $specialPricing->load(['company:id,name', 'location:id,name']);
        $specialPricing->recurrence_display = $specialPricing->getRecurrenceDisplay();
        $specialPricing->upcoming_dates = $specialPricing->getUpcomingDates(10);

        return response()->json([
            'success' => true,
            'data' => $specialPricing,
        ]);
    }

    /**
     * Update the specified special pricing.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $specialPricing = SpecialPricing::findOrFail($id);

        $originalName = $specialPricing->name;
        $originalData = $specialPricing->toArray();

        $validated = $request->validate([
            'company_id' => 'sometimes|exists:companies,id',
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'discount_amount' => 'sometimes|numeric|min:0',
            'discount_type' => 'sometimes|in:fixed,percentage',
            'recurrence_type' => 'sometimes|in:one_time,weekly,monthly',
            'recurrence_value' => 'sometimes|nullable|integer|min:0|max:31',
            'specific_date' => 'sometimes|nullable|date',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'time_start' => 'sometimes|nullable|date_format:H:i',
            'time_end' => 'sometimes|nullable|date_format:H:i',
            'entity_type' => 'sometimes|in:package,attraction,all',
            'entity_ids' => 'sometimes|nullable|array',
            'entity_ids.*' => 'integer',
            'priority' => 'sometimes|integer|min:0',
            'is_stackable' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        // Validate percentage doesn't exceed 100
        $discountType = $validated['discount_type'] ?? $specialPricing->discount_type;
        $discountAmount = $validated['discount_amount'] ?? $specialPricing->discount_amount;
        if ($discountType === 'percentage' && $discountAmount > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%',
            ], 422);
        }

        $specialPricing->update($validated);
        $specialPricing->refresh();
        $specialPricing->load(['company:id,name', 'location:id,name']);
        $specialPricing->recurrence_display = $specialPricing->getRecurrenceDisplay();
        $specialPricing->upcoming_dates = $specialPricing->getUpcomingDates(3);

        // Log activity
        ActivityLog::log(
            'special_pricing',
            'updated',
            "Special pricing '{$originalName}' updated",
            $request->user()?->id,
            $specialPricing->id,
            $originalData,
            $specialPricing->toArray()
        );

        return response()->json([
            'success' => true,
            'message' => 'Special pricing updated successfully',
            'data' => $specialPricing,
        ]);
    }

    /**
     * Remove the specified special pricing.
     */
    public function destroy(Request $request, SpecialPricing $specialPricing): JsonResponse
    {
        $name = $specialPricing->name;

        // Log activity before deletion
        ActivityLog::log(
            'special_pricing',
            'deleted',
            "Special pricing '{$name}' deleted",
            $request->user()?->id,
            $specialPricing->id,
            $specialPricing->toArray(),
            null
        );

        $specialPricing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Special pricing deleted successfully',
        ]);
    }

    /**
     * Toggle active status for a special pricing.
     */
    public function toggleStatus(Request $request, SpecialPricing $specialPricing): JsonResponse
    {
        $specialPricing->is_active = !$specialPricing->is_active;
        $specialPricing->save();

        $status = $specialPricing->is_active ? 'activated' : 'deactivated';

        // Log activity
        ActivityLog::log(
            'special_pricing',
            'status_toggled',
            "Special pricing '{$specialPricing->name}' {$status}",
            $request->user()?->id,
            $specialPricing->id,
            null,
            ['is_active' => $specialPricing->is_active]
        );

        return response()->json([
            'success' => true,
            'message' => "Special pricing {$status} successfully",
            'data' => $specialPricing,
        ]);
    }

    /**
     * Get special pricings by location.
     */
    public function getByLocation($locationId): JsonResponse
    {
        $specialPricings = SpecialPricing::byLocation($locationId)
            ->active()
            ->withinDateRange()
            ->orderBy('priority', 'desc')
            ->get()
            ->map(function ($item) {
                $item->recurrence_display = $item->getRecurrenceDisplay();
                $item->upcoming_dates = $item->getUpcomingDates(3);
                return $item;
            });

        return response()->json([
            'success' => true,
            'data' => $specialPricings,
        ]);
    }

    /**
     * Get applicable special pricing(s) for a specific entity on a date.
     * This is the main endpoint used during booking/purchase flows.
     */
    public function getForEntity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:package,attraction',
            'entity_id' => 'required|integer',
            'base_price' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'time' => 'nullable|date_format:H:i',
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::today();
        $time = $validated['time'] ?? null;

        $breakdown = SpecialPricing::getFullPriceBreakdown(
            $validated['entity_type'],
            $validated['entity_id'],
            (float) $validated['base_price'],
            $date,
            $validated['location_id'] ?? null,
            $time
        );

        return response()->json([
            'success' => true,
            'data' => $breakdown,
        ]);
    }

    /**
     * Check if a date has any active special pricing.
     */
    public function checkDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'location_id' => 'nullable|integer|exists:locations,id',
            'entity_type' => 'nullable|in:package,attraction,all',
        ]);

        $date = Carbon::parse($validated['date']);
        $locationId = $validated['location_id'] ?? null;
        $entityType = $validated['entity_type'] ?? null;

        $query = SpecialPricing::active()->withinDateRange($date);

        if ($locationId) {
            $query->byLocation($locationId);
        }

        if ($entityType === 'package') {
            $query->forPackages();
        } elseif ($entityType === 'attraction') {
            $query->forAttractions();
        }

        $specialPricings = $query->get()->filter(function ($pricing) use ($date) {
            return $pricing->isActiveOnDate($date);
        })->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'discount_label' => $item->discount_type === 'percentage'
                    ? "{$item->discount_amount}%"
                    : '$' . number_format((float) $item->discount_amount, 2),
                'discount_type' => $item->discount_type,
                'discount_amount' => $item->discount_amount,
                'entity_type' => $item->entity_type,
                'recurrence_display' => $item->getRecurrenceDisplay(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date->format('Y-m-d'),
                'has_special_pricing' => $specialPricings->count() > 0,
                'special_pricings' => $specialPricings,
            ],
        ]);
    }

    /**
     * Get upcoming special pricing dates for a location.
     */
    public function getUpcomingDates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'nullable|integer|exists:locations,id',
            'days' => 'nullable|integer|min:1|max:365',
            'entity_type' => 'nullable|in:package,attraction,all',
        ]);

        $locationId = $validated['location_id'] ?? null;
        $days = $validated['days'] ?? 30;
        $entityType = $validated['entity_type'] ?? null;

        $query = SpecialPricing::active()->withinDateRange();

        if ($locationId) {
            $query->byLocation($locationId);
        }

        if ($entityType === 'package') {
            $query->forPackages();
        } elseif ($entityType === 'attraction') {
            $query->forAttractions();
        }

        $specialPricings = $query->get();

        // Build date map
        $dateMap = [];
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addDays($days);

        foreach ($specialPricings as $pricing) {
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                if ($pricing->isActiveOnDate($currentDate)) {
                    $dateKey = $currentDate->format('Y-m-d');

                    if (!isset($dateMap[$dateKey])) {
                        $dateMap[$dateKey] = [];
                    }

                    $dateMap[$dateKey][] = [
                        'id' => $pricing->id,
                        'name' => $pricing->name,
                        'discount_label' => $pricing->discount_type === 'percentage'
                            ? "{$pricing->discount_amount}%"
                            : '$' . number_format((float) $pricing->discount_amount, 2),
                    ];
                }
                $currentDate->addDay();
            }
        }

        // Sort by date
        ksort($dateMap);

        return response()->json([
            'success' => true,
            'data' => [
                'dates' => $dateMap,
                'count' => count($dateMap),
            ],
        ]);
    }

    /**
     * Bulk delete special pricings.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:special_pricings,id',
        ]);

        $count = SpecialPricing::whereIn('id', $validated['ids'])->delete();

        // Log activity
        ActivityLog::log(
            'special_pricing',
            'bulk_deleted',
            "{$count} special pricing(s) deleted",
            $request->user()?->id,
            null,
            ['deleted_ids' => $validated['ids']],
            null
        );

        return response()->json([
            'success' => true,
            'message' => "{$count} special pricing(s) deleted successfully",
        ]);
    }
}
