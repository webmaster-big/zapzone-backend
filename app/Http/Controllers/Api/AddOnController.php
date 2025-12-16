<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AddOn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AddOnController extends Controller
{
    /**
     * Display a listing of add-ons.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AddOn::with(['location', 'packages']);

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            // log the auth user info
            if ($authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        } else {
            $query->active();
        }

        // Price range filter
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
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
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['name', 'price', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = $request->get('per_page', 15);
        $addOns = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'add_ons' => $addOns->items(),
                'pagination' => [
                    'current_page' => $addOns->currentPage(),
                    'last_page' => $addOns->lastPage(),
                    'per_page' => $addOns->perPage(),
                    'total' => $addOns->total(),
                    'from' => $addOns->firstItem(),
                    'to' => $addOns->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Store a newly created add-on.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'image' => 'nullable|max:15360',
            'is_active' => 'boolean',
        ]);

        // Handle image upload
        if (isset($validated['image']) && !empty($validated['image'])) {
            $validated['image'] = $this->handleImageUpload($validated['image']);
        } else {
            $validated['image'] = null;
        }

        $addOn = AddOn::create($validated);
        $addOn->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Add-on created successfully',
            'data' => $addOn,
        ], 201);
    }

    /**
     * Display the specified add-on.
     */
    public function show(AddOn $addOn): JsonResponse
    {
        $addOn->load(['location', 'packages', 'bookings']);

        return response()->json([
            'success' => true,
            'data' => $addOn,
        ]);
    }

    /**
     * Update the specified add-on.
     */
    public function update(Request $request, $id): JsonResponse
    {
        // Find the add-on
        $addOn = AddOn::findOrFail($id);

        // Store original name for logging
        $originalName = $addOn->name;

        $validated = $request->validate([
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|nullable|string',
            'image' => 'nullable|max:15360',
            'is_active' => 'sometimes|boolean',
        ]);

        // Log what we're receiving
        Log::info('Update request received', [
            'addon_id' => $addOn->id,
            'addon_name' => $addOn->name,
            'validated_data' => $validated,
            'has_image' => isset($validated['image'])
        ]);

        // Handle image upload
        if (isset($validated['image']) && !empty($validated['image'])) {
            // Delete old image if exists
            if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
                unlink(storage_path('app/public/' . $addOn->image));
            }
            $validated['image'] = $this->handleImageUpload($validated['image']);
            Log::info('Image processed', ['new_path' => $validated['image']]);
        }

        $addOn->update($validated);
        $addOn->refresh();
        $addOn->load(['location', 'packages']);

        // log laravel log
        Log::info("Add-On '{$addOn->name}' (ID: {$addOn->id}) was updated from '{$originalName}'.");

        // Log add-on update
        ActivityLog::log(
            action: 'Add-On Updated',
            category: 'update',
            description: "Add-on '{$addOn->name}' was updated",
            userId: auth()->id(),
            locationId: $addOn->location_id,
            entityType: 'addon',
            entityId: $addOn->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Add-on updated successfully',
            'data' => $addOn,
        ]);
    }

    /**
     * Remove the specified add-on.
     */
    public function destroy( $id): JsonResponse
    {
        $addOn = AddOn::findOrFail($id);

        $deletedBy = User::findOrFail(auth()->id());

        // Delete image if exists
        if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
            unlink(storage_path('app/public/' . $addOn->image));
        }

        $addOnName = $addOn->name;
        $addOnId = $addOn->id;
        $locationId = $addOn->location_id;

        $addOn->delete();

        // Log add-on deletion
        ActivityLog::log(
            action: 'Add-On Deleted',
            category: 'delete',
            description: "Add-on '{$addOnName}' was deleted by {$deletedBy->first_name} {$deletedBy->last_name}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'addon',
            entityId: $addOnId
        );

        return response()->json([
            'success' => true,
            'message' => 'Add-on deleted successfully',
        ]);
    }

    /**
     * Get add-ons by location.
     */
    public function getByLocation(int $locationId): JsonResponse
    {
        $addOns = AddOn::with(['packages'])
            ->byLocation($locationId)
            ->active()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $addOns,
        ]);
    }

    /**
     * Toggle add-on active status.
     */
    public function toggleStatus(AddOn $addOn): JsonResponse
    {
        $addOn->update(['is_active' => !$addOn->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Add-on status updated successfully',
            'data' => $addOn,
        ]);
    }

    /**
     * Get popular add-ons.
     */
    public function getPopular(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $addOns = AddOn::with(['location'])
            ->withCount('bookings')
            ->active()
            ->orderBy('bookings_count', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $addOns,
        ]);
    }

    /**
     * Handle image upload - base64 or file path
     */
    private function handleImageUpload($image): string
    {
        // Check if it's a base64 string
        if (is_string($image) && strpos($image, 'data:image') === 0) {
            // Extract base64 data
            preg_match('/data:image\/(\w+);base64,/', $image, $matches);
            $imageType = $matches[1] ?? 'png';
            $imageData = substr($image, strpos($image, ',') + 1);
            $imageData = base64_decode($imageData);

            // Generate unique filename
            $filename = uniqid() . '.' . $imageType;
            $path = 'images/addons';
            $fullPath = storage_path('app/public/' . $path);

            Log::info('AddOn image upload attempt', [
                'filename' => $filename,
                'path' => $path,
                'fullPath' => $fullPath,
                'imageType' => $imageType
            ]);

            // Create directory if it doesn't exist
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
                Log::info('Created directory', ['path' => $fullPath]);
            }

            // Save the file
            file_put_contents($fullPath . '/' . $filename, $imageData);
            Log::info('Image saved successfully', ['file' => $fullPath . '/' . $filename]);

            // Return the relative path (for storage URL)
            return $path . '/' . $filename;
        }

        // If it's already a file path or URL, return as is
        Log::info('Image path returned as-is', ['image' => $image]);
        return $image;
    }

    /**
     * Bulk delete add-ons
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:add_ons,id',
        ]);

        $addOns = AddOn::whereIn('id', $validated['ids'])->get();
        $deletedCount = 0;
        $locationIds = [];

        foreach ($addOns as $addOn) {
            // Delete image if exists
            if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
                unlink(storage_path('app/public/' . $addOn->image));
            }

            $locationIds[] = $addOn->location_id;
            $addOn->delete();
            $deletedCount++;
        }

        // Log bulk deletion
        ActivityLog::log(
            action: 'Bulk Add-Ons Deleted',
            category: 'delete',
            description: "{$deletedCount} add-ons deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'addon',
            metadata: ['deleted_count' => $deletedCount, 'ids' => $validated['ids']]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} add-ons deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
