<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            'image' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Handle image upload
        if (isset($validated['image'])) {
            $validated['image'] = $this->handleImageUpload($validated['image']);
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
    public function update(Request $request, AddOn $addOn): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|nullable|string',
            'image' => 'sometimes|nullable|string',
            'is_active' => 'boolean',
        ]);

        // Handle image upload
        if (isset($validated['image'])) {
            // Delete old image if exists
            if ($addOn->image && file_exists(public_path($addOn->image))) {
                unlink(public_path($addOn->image));
            }
            $validated['image'] = $this->handleImageUpload($validated['image']);
        }

        $addOn->update($validated);
        $addOn->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Add-on updated successfully',
            'data' => $addOn,
        ]);
    }

    /**
     * Remove the specified add-on.
     */
    public function destroy(AddOn $addOn): JsonResponse
    {
        // Delete image if exists
        if ($addOn->image && file_exists(public_path($addOn->image))) {
            unlink(public_path($addOn->image));
        }

        $addOn->delete();

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
     * Handle image upload - converts base64 to file
     */
    private function handleImageUpload(string $imageData): string
    {
        // Check if it's a base64 image
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
            // Extract base64 data
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif, etc.

            // Decode base64
            $imageData = base64_decode($imageData);

            if ($imageData === false) {
                throw new \Exception('Base64 decode failed');
            }

            // Create directory if it doesn't exist
            $directory = public_path('images/addons');
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            // Generate unique filename
            $filename = uniqid() . '.' . $type;
            $filepath = $directory . '/' . $filename;

            // Save the file
            file_put_contents($filepath, $imageData);

            // Return the relative path
            return 'images/addons/' . $filename;
        }

        // If not base64, return as is (might be a URL or existing path)
        return $imageData;
    }
}
