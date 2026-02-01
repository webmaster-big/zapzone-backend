<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
// please fix can't store images of the file
class AttractionController extends Controller
{
    /**
     * Display a listing of attractions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Attraction::with(['location', 'packages']);

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            // log the auth user info
            Log::info('Auth User: ', ['user' => $authUser]);
            if ($authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        // Filter by pricing type
        if ($request->has('pricing_type')) {
            $query->byPricingType($request->pricing_type);
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

        if (in_array($sortBy, ['name', 'price', 'rating', 'category', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100); // Max 100 items per page
        $attractions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'attractions' => $attractions->items(),
                'pagination' => [
                    'current_page' => $attractions->currentPage(),
                    'last_page' => $attractions->lastPage(),
                    'per_page' => $attractions->perPage(),
                    'total' => $attractions->total(),
                    'from' => $attractions->firstItem(),
                    'to' => $attractions->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Get public attractions grouped by name with location-based purchase links
     * Groups attractions by name and shows all locations where they're available
     */
    public function attractionsGroupedByName(Request $request): JsonResponse
    {
        // search query
        $search = $request->get('search', null);

        if ($search) {
            $attractions = Attraction::with(['location', 'packages'])
                ->where('is_active', true)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                          ->orWhere('description', 'like', "%{$search}%");
                })
                ->orderBy('name')
                ->get();
        } else {
            // Get all active attractions with their locations
            $attractions = Attraction::with(['location', 'packages'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        // Group attractions by name
        $groupedAttractions = [];

        foreach ($attractions as $attraction) {
            $attractionName = $attraction->name;

            if (!isset($groupedAttractions[$attractionName])) {
                // Initialize the group with the first attraction's data
                $groupedAttractions[$attractionName] = [
                    'name' => $attraction->name,
                    'description' => $attraction->description,
                    'price' => $attraction->price,
                    'pricing_type' => $attraction->pricing_type,
                    'category' => $attraction->category,
                    'max_capacity' => $attraction->max_capacity,
                    'duration' => $attraction->duration,
                    'duration_unit' => $attraction->duration_unit,
                    'image' => $attraction->image,
                    'rating' => $attraction->rating,
                    'min_age' => $attraction->min_age,
                    'availability' => $attraction->availability,
                    'locations' => [],
                    'purchase_links' => [],
                ];
            }

            // Add this location's information
            $locationSlug = str_replace(' ', '', $attraction->location->name); // Remove spaces for URL

            $groupedAttractions[$attractionName]['locations'][] = [
                'location_id' => $attraction->location->id,
                'location_name' => $attraction->location->name,
                'location_slug' => $locationSlug,
                'attraction_id' => $attraction->id,
                'address' => $attraction->location->address,
                'city' => $attraction->location->city,
                'state' => $attraction->location->state,
                'phone' => $attraction->location->phone,
            ];

            // Create purchase link for this location
            $groupedAttractions[$attractionName]['purchase_links'][] = [
                'location' => $attraction->location->name,
                'url' => "/purchase/attraction/{$locationSlug}/{$attraction->id}",
                'attraction_id' => $attraction->id,
                'location_id' => $attraction->location->id,
            ];
        }

        // Convert to indexed array
        $result = array_values($groupedAttractions);

        return response()->json([
            'success' => true,
            'data' => $result,
            'total' => count($result),
        ]);
    }


    /**
     * Store a newly created attraction.
     */
    public function store(Request $request): JsonResponse
    {
        // Convert empty duration to null
        if ($request->has('duration') && $request->duration === '') {
            $request->merge(['duration' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'pricing_type' => 'required|string',
            'max_capacity' => 'required|integer|min:1',
            'category' => 'required|string|max:255',
            'unit' => 'nullable|string|max:50',
            'duration' => 'nullable|numeric|min:0|gte:0',
            'duration_unit' => ['nullable', Rule::in(['hours', 'minutes', 'hours and minutes'])],
            'availability' => 'nullable|array',
            'image' => 'nullable|array',
            'image.*' => 'nullable|string|max:27262976',
            'rating' => 'nullable|numeric|between:0,5',
            'min_age' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'location_id' => 'nullable|exists:locations,id',
        ]);

        $validated['pricing_type'] = $validated['pricing_type'] ?? 'per_person';

        // Handle file uploads from request
        $uploadedImages = [];

        if ($request->hasFile('image')) {
            $files = $request->file('image');

            // Handle single file or array of files
            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $uploadedImages[] = $this->handleFileUpload($file);
                }
            }
        } elseif (isset($validated['image'])) {
            // Handle base64 strings or filenames
            $images = is_array($validated['image']) ? $validated['image'] : [$validated['image']];

            foreach ($images as $image) {
                if (!empty($image)) {
                    // Check if it's a base64 string
                    if (is_string($image) && strpos($image, 'data:image') === 0) {
                        $uploadedImages[] = $this->handleBase64Upload($image);
                    } else {
                        $uploadedImages[] = $this->handleImageUpload($image);
                    }
                }
            }
        }

        $validated['image'] = !empty($uploadedImages) ? $uploadedImages : [];

        $attraction = Attraction::create($validated);
        $attraction->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Attraction created successfully',
            'data' => $attraction,
        ], 201);
    }

    /**
     * Display the specified attraction.
     */
    public function show($id): JsonResponse
    {
        $attraction = Attraction::findOrFail($id);

        $attraction->load(['location', 'packages', 'bookings']);

        return response()->json([
            'success' => true,
            'data' => $attraction,
        ]);
    }

    /**
     * Update the specified attraction.
     */
    public function update(Request $request, Attraction $attraction): JsonResponse
    {
        // Convert empty duration to null
        if ($request->has('duration') && $request->duration === '') {
            $request->merge(['duration' => null]);
        }

        $validated = $request->validate([
            'location_id' => 'sometimes|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric|min:0',
            'pricing_type' => 'required|string',
            'max_capacity' => 'sometimes|integer|min:1',
            'category' => 'sometimes|string|max:255',
            'unit' => 'nullable|string|max:50',
            'duration' => 'nullable|numeric|min:0|gte:0',
            'duration_unit' => ['nullable', Rule::in(['hours', 'minutes', 'hours and minutes'])],
            'availability' => 'nullable|array',
            'image' => 'nullable|max:27262976',
            'image.*' => 'nullable|max:27262976',
            'rating' => 'nullable|numeric|between:0,5',
            'min_age' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['pricing_type'] = $validated['pricing_type'] ?? 'per_person';

        // Handle image upload
        if ($request->hasFile('image') || isset($validated['image'])) {
            // Delete old images if they exist
            if ($attraction->image && is_array($attraction->image)) {
                foreach ($attraction->image as $oldImage) {
                    $imagePath = public_path($oldImage);
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }
            }

            $uploadedImages = [];

            if ($request->hasFile('image')) {
                $files = $request->file('image');

                // Handle single file or array of files
                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $uploadedImages[] = $this->handleFileUpload($file);
                    }
                }
            } elseif (isset($validated['image'])) {
                // Handle base64 strings or filenames
                $images = is_array($validated['image']) ? $validated['image'] : [$validated['image']];

                foreach ($images as $image) {
                    if (!empty($image)) {
                        // Check if it's a base64 string
                        if (is_string($image) && strpos($image, 'data:image') === 0) {
                            $uploadedImages[] = $this->handleBase64Upload($image);
                        } else {
                            $uploadedImages[] = $this->handleImageUpload($image);
                        }
                    }
                }
            }

            $validated['image'] = !empty($uploadedImages) ? $uploadedImages : [];
        }

        $attraction->update($validated);
        $attraction->load(['location', 'packages']);

        return response()->json([
            'success' => true,
            'message' => 'Attraction updated successfully',
            'data' => $attraction,
        ]);
    }

    /**
     * Remove the specified attraction.
     */
    public function destroy($id): JsonResponse
    {
        $attraction = Attraction::findOrFail($id);

        $deletedBy = User::findOrFail(auth()->id());

        // Delete images if they exist
        if ($attraction->image && is_array($attraction->image)) {
            foreach ($attraction->image as $image) {
                $imagePath = public_path($image);
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
        }

        $attractionName = $attraction->name;
        $attractionId = $attraction->id;
        $locationId = $attraction->location_id;

        $attraction->delete();

        // Log attraction deletion
        ActivityLog::log(
            action: 'Attraction Deleted',
            category: 'delete',
            description: "Attraction '{$attractionName}' was deleted by {$deletedBy->first_name} {$deletedBy->last_name}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'attraction',
            entityId: $attractionId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $deletedBy->first_name . ' ' . $deletedBy->last_name,
                    'email' => $deletedBy->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'attraction_details' => [
                    'attraction_id' => $attractionId,
                    'name' => $attractionName,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Attraction deleted successfully',
        ]);
    }

    /**
     * Get attractions by location.
     */
    public function getByLocation(int $locationId): JsonResponse
    {
        $attractions = Attraction::with(['packages'])
            ->byLocation($locationId)
            ->active()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attractions,
        ]);
    }

    /**
     * Get attractions by category.
     */
    public function getByCategory(string $category): JsonResponse
    {
        $attractions = Attraction::with(['location', 'packages'])
            ->byCategory($category)
            ->active()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attractions,
        ]);
    }

    /**
     * Toggle attraction active status.
     */
    public function toggleStatus(Attraction $attraction): JsonResponse
    {
        $attraction->update(['is_active' => !$attraction->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Attraction status updated successfully',
            'data' => $attraction,
        ]);
    }

    /**
     * Activate an attraction.
     */
    public function activate(Attraction $attraction): JsonResponse
    {
        if ($attraction->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Attraction is already active',
                'data' => $attraction,
            ], 400);
        }

        $attraction->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Attraction activated successfully',
            'data' => $attraction,
        ]);
    }

    /**
     * Deactivate an attraction.
     */
    public function deactivate(Attraction $attraction): JsonResponse
    {
        if (!$attraction->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Attraction is already inactive',
                'data' => $attraction,
            ], 400);
        }

        $attraction->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Attraction deactivated successfully',
            'data' => $attraction,
        ]);
    }

    /**
     * Get attraction statistics.
     */
    public function statistics(Attraction $attraction): JsonResponse
    {
        $stats = [
            'total_bookings' => $attraction->bookings()->count(),
            'recent_bookings' => $attraction->bookings()->where('created_at', '>=', now()->subDays(30))->count(),
            'total_revenue' => $attraction->purchases()->where('status', 'completed')->sum('amount'),
            'average_rating' => $attraction->rating,
            'packages_count' => $attraction->packages()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get popular attractions.
     */
    public function getPopular(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $attractions = Attraction::with(['location'])
            ->withCount('bookings')
            ->active()
            ->orderBy('bookings_count', 'desc')
            ->orderBy('rating', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attractions,
        ]);
    }

        /**
         * Bulk import attractions
         */
        public function bulkImport(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'attractions' => 'required|array|min:1',
                // Support both snake_case and camelCase field names
                'attractions.*.location_id' => 'nullable|exists:locations,id',
                'attractions.*.locationId' => 'nullable|exists:locations,id',
                'attractions.*.name' => 'required|string|max:255',
                'attractions.*.description' => 'required|string',
                'attractions.*.price' => 'required|numeric|min:0',
                'attractions.*.pricing_type' => 'nullable|string',
                'attractions.*.pricingType' => 'nullable|string',
                'attractions.*.max_capacity' => 'nullable|integer|min:1',
                'attractions.*.maxCapacity' => 'nullable|integer|min:1',
                'attractions.*.category' => 'required|string|max:255',
                'attractions.*.unit' => 'nullable|string|max:50',
                'attractions.*.duration' => 'nullable|numeric|min:0|gte:0',
                'attractions.*.duration_unit' => 'nullable|string',
                'attractions.*.durationUnit' => 'nullable|string',
                'attractions.*.availability' => 'nullable|array',
                'attractions.*.image' => 'nullable', // Can be string or array
                'attractions.*.images' => 'nullable|array', // Export format uses 'images'
                'attractions.*.rating' => 'nullable|numeric|between:0,5',
                'attractions.*.min_age' => 'nullable|integer|min:0',
                'attractions.*.minAge' => 'nullable|integer|min:0',
                'attractions.*.is_active' => 'nullable|boolean',
                'attractions.*.status' => 'nullable|string', // Export format uses 'status'
            ]);

            $importedAttractions = [];
            $errors = [];

            foreach ($validated['attractions'] as $index => $attractionData) {
                try {
                    // Map camelCase to snake_case fields
                    $mappedData = [
                        'name' => $attractionData['name'],
                        'description' => $attractionData['description'],
                        'price' => $attractionData['price'],
                        'category' => $attractionData['category'],
                        'location_id' => $attractionData['location_id'] ?? $attractionData['locationId'] ?? null,
                        'pricing_type' => $attractionData['pricing_type'] ?? $attractionData['pricingType'] ?? 'per_person',
                        'max_capacity' => $attractionData['max_capacity'] ?? $attractionData['maxCapacity'] ?? 1,
                        'duration' => $attractionData['duration'] ?? null,
                        'duration_unit' => $attractionData['duration_unit'] ?? $attractionData['durationUnit'] ?? null,
                        'availability' => $attractionData['availability'] ?? null,
                        'rating' => $attractionData['rating'] ?? null,
                        'min_age' => $attractionData['min_age'] ?? $attractionData['minAge'] ?? null,
                        'unit' => $attractionData['unit'] ?? null,
                    ];

                    // Handle is_active from status or is_active field
                    if (isset($attractionData['status'])) {
                        $mappedData['is_active'] = $attractionData['status'] === 'active';
                    } elseif (isset($attractionData['is_active'])) {
                        $mappedData['is_active'] = $attractionData['is_active'];
                    } else {
                        $mappedData['is_active'] = true;
                    }

                    // Handle image - support both 'image' and 'images' fields
                    $imageData = $attractionData['images'] ?? $attractionData['image'] ?? null;

                    if ($imageData) {
                        if (is_array($imageData) && count($imageData) > 0) {
                            $uploadedImages = [];
                            foreach ($imageData as $image) {
                                if (!empty($image)) {
                                    // Check if it's a base64 string
                                    if (is_string($image) && strpos($image, 'data:image') === 0) {
                                        $uploadedImages[] = $this->handleBase64Upload($image);
                                    } else {
                                        // Keep existing path as-is
                                        $uploadedImages[] = $image;
                                    }
                                }
                            }
                            $mappedData['image'] = !empty($uploadedImages) ? $uploadedImages : [];
                        } elseif (is_string($imageData) && !empty($imageData)) {
                            // If single image string is provided
                            if (strpos($imageData, 'data:image') === 0) {
                                $uploadedImage = $this->handleBase64Upload($imageData);
                            } else {
                                $uploadedImage = $imageData;
                            }
                            $mappedData['image'] = [$uploadedImage];
                        } else {
                            $mappedData['image'] = [];
                        }
                    } else {
                        $mappedData['image'] = [];
                    }

                    // Create the attraction
                    $attraction = Attraction::create($mappedData);

                    // Load relationships
                    $attraction->load(['location', 'packages']);
                    $importedAttractions[] = $attraction;

                } catch (\Exception $e) {
                    Log::error('Failed to import attraction', [
                        'index' => $index,
                        'name' => $attractionData['name'] ?? 'Unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $errors[] = [
                        'index' => $index,
                        'name' => $attractionData['name'] ?? 'Unknown',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $response = [
                'success' => true,
                'message' => count($importedAttractions) . ' attractions imported successfully',
                'data' => [
                    'imported' => $importedAttractions,
                    'imported_count' => count($importedAttractions),
                    'failed_count' => count($errors),
                ],
            ];

            if (!empty($errors)) {
                $response['errors'] = $errors;
            }

            return response()->json($response, count($errors) > 0 ? 207 : 201);
        }

    /**
     * Handle actual file upload from request
     */
    private function handleFileUpload($file): string
    {
        // Generate unique filename
        $filename = uniqid() . '.' . $file->getClientOriginalExtension();
        $path = 'images/attractions';
        $fullPath = storage_path('app/public/' . $path);

        // Create directory if it doesn't exist
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        // Move the uploaded file
        $file->move($fullPath, $filename);

        // Return the relative path (for storage URL)
        return $path . '/' . $filename;
    }

    /**
     * Handle base64 image upload
     */
    private function handleBase64Upload($base64String): string
    {
        // Check if it's a base64 string
        if (is_string($base64String) && strpos($base64String, 'data:image') === 0) {
            // Extract base64 data
            preg_match('/data:image\/(\w+);base64,/', $base64String, $matches);
            $imageType = $matches[1] ?? 'png';
            $imageData = substr($base64String, strpos($base64String, ',') + 1);
            $imageData = base64_decode($imageData);

            // Generate shorter filename
            $filename = uniqid() . '.' . $imageType;
            $path = 'images/attractions';
            $fullPath = storage_path('app/public/' . $path);

            // Create directory if it doesn't exist
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            // Save the file
            file_put_contents($fullPath . '/' . $filename, $imageData);

            // Return the relative path (for storage URL)
            return $path . '/' . $filename;
        }

        // If it's already a file path or URL, return as is
        return $base64String;
    }

    /**
     * Handle image upload - stores the filename with path (for string filenames)
     */
    private function handleImageUpload($image): string
    {
        // Remove any surrounding quotes from the image filename
        if (is_string($image)) {
            $image = trim($image, '"');
        }

        // Return the clean path: images/attractions/filename.ext
        return 'images/attractions/' . basename($image);
    }

    /**
     * Bulk delete attractions
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:attractions,id',
        ]);

        $attractions = Attraction::whereIn('id', $validated['ids'])->get();
        $deletedCount = 0;
        $locationIds = [];

        foreach ($attractions as $attraction) {
            // Delete images if they exist
            if ($attraction->image && is_array($attraction->image)) {
                foreach ($attraction->image as $imagePath) {
                    if (file_exists(storage_path('app/public/' . $imagePath))) {
                        unlink(storage_path('app/public/' . $imagePath));
                    }
                }
            }

            $locationIds[] = $attraction->location_id;
            $attraction->delete();
            $deletedCount++;
        }

        // Log bulk deletion
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Attractions Deleted',
            category: 'delete',
            description: "{$deletedCount} attractions deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'attraction',
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'attraction_ids' => $validated['ids'],
                'affected_locations' => array_unique($locationIds),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} attractions deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }
}
