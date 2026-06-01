<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\AttractionAddOn;
use App\Models\SpecialPricing;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
class AttractionController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        $query = Attraction::with(['location', 'packages', 'addOns']);

        $this->applyAuthScope($query, $request);

        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('pricing_type')) {
            $query->byPricingType($request->pricing_type);
        }


        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');

        if (in_array($sortBy, ['name', 'price', 'rating', 'category', 'created_at', 'display_order'])) {
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

    public function attractionsGroupedByName(Request $request): JsonResponse
    {
        $search = $request->get('search', null);
        $date = $request->get('date') ? Carbon::parse($request->get('date')) : Carbon::today();

        $query = Attraction::with(['location', 'packages', 'addOns'])
            ->select(['id', 'name', 'description', 'price', 'pricing_type', 'category', 'max_capacity', 'display_capacity_to_customers', 'duration', 'duration_unit', 'rating', 'min_age', 'availability', 'display_order', 'location_id', 'is_active'])
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $attractions = $query->orderBy('display_order', 'asc')
            ->orderBy('name')
            ->get();

        $attractionImages = Attraction::whereIn('id', $attractions->pluck('id'))
            ->pluck('image', 'id');

        $groupedAttractions = [];

        foreach ($attractions as $attraction) {
            $attractionName = $attraction->name;

            $priceBreakdown = SpecialPricing::getFullPriceBreakdown(
                'attraction',
                $attraction->id,
                (float) $attraction->price,
                $date,
                $attraction->location_id
            );

            if (!isset($groupedAttractions[$attractionName])) {
                $groupedAttractions[$attractionName] = [
                    'name' => $attraction->name,
                    'description' => $attraction->description,
                    'price' => $attraction->price,
                    'pricing_type' => $attraction->pricing_type,
                    'category' => $attraction->category,
                    'max_capacity' => $attraction->max_capacity,
                    'display_capacity_to_customers' => $attraction->display_capacity_to_customers,
                    'duration' => $attraction->duration,
                    'duration_unit' => $attraction->duration_unit,
                    'image' => $attractionImages[$attraction->id] ?? null,
                    'rating' => $attraction->rating,
                    'min_age' => $attraction->min_age,
                    'availability' => $attraction->availability,
                    'display_order' => $attraction->display_order,
                    'special_pricing' => $priceBreakdown,
                    'locations' => [],
                    'purchase_links' => [],
                ];
            }

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
                'special_pricing' => $priceBreakdown,
            ];

            $groupedAttractions[$attractionName]['purchase_links'][] = [
                'location' => $attraction->location->name,
                'url' => "/purchase/attraction/{$locationSlug}/{$attraction->id}",
                'attraction_id' => $attraction->id,
                'location_id' => $attraction->location->id,
            ];
        }

        $result = array_values($groupedAttractions);

        return response()->json([
            'success' => true,
            'data' => $result,
            'total' => count($result),
        ]);
    }


    public function store(Request $request): JsonResponse
    {
        if ($request->has('duration') && $request->duration === '') {
            $request->merge(['duration' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'pricing_type' => 'required|string',
            'max_capacity' => 'required|integer|min:1',
            'display_capacity_to_customers' => 'boolean',
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
            'addon_ids' => 'nullable|array',
            'addon_ids.*' => 'exists:add_ons,id',
            'add_ons_order' => 'nullable|array',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $validated['pricing_type'] = $validated['pricing_type'] ?? 'per_person';

        $uploadedImages = [];

        if ($request->hasFile('image')) {
            $files = $request->file('image');

            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file && $file->isValid()) {
                    $uploadedImages[] = $this->handleFileUpload($file);
                }
            }
        } elseif (isset($validated['image'])) {
            $images = is_array($validated['image']) ? $validated['image'] : [$validated['image']];

            foreach ($images as $image) {
                if (!empty($image)) {
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

        if (isset($validated['addon_ids']) && is_array($validated['addon_ids'])) {
            foreach ($validated['addon_ids'] as $addonId) {
                AttractionAddOn::create([
                    'attraction_id' => $attraction->id,
                    'add_on_id' => $addonId,
                ]);
            }
        }

        $attraction->load(['location', 'packages', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Attraction created successfully',
            'data' => $attraction,
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $attraction = Attraction::findOrFail($id);

        $attraction->load(['location', 'packages', 'bookings', 'addOns']);

        return response()->json([
            'success' => true,
            'data' => $attraction,
        ]);
    }

    public function update(Request $request, Attraction $attraction): JsonResponse
    {
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
            'display_capacity_to_customers' => 'boolean',
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
            'addon_ids' => 'sometimes|array',
            'addon_ids.*' => 'exists:add_ons,id',
            'add_ons_order' => 'nullable|array',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $validated['pricing_type'] = $validated['pricing_type'] ?? 'per_person';

        if ($request->hasFile('image') || isset($validated['image'])) {
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

                if (!is_array($files)) {
                    $files = [$files];
                }

                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $uploadedImages[] = $this->handleFileUpload($file);
                    }
                }
            } elseif (isset($validated['image'])) {
                $images = is_array($validated['image']) ? $validated['image'] : [$validated['image']];

                foreach ($images as $image) {
                    if (!empty($image)) {
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

        if (isset($validated['addon_ids'])) {
            AttractionAddOn::where('attraction_id', $attraction->id)->delete();

            foreach ($validated['addon_ids'] as $addonId) {
                AttractionAddOn::create([
                    'attraction_id' => $attraction->id,
                    'add_on_id' => $addonId,
                ]);
            }
        }

        $attraction->load(['location', 'packages', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Attraction updated successfully',
            'data' => $attraction,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $attraction = Attraction::findOrFail($id);

        $deletedBy = User::findOrFail(auth()->id());

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

    public function getByLocation(int $locationId): JsonResponse
    {
        $attractions = Attraction::with(['packages', 'addOns'])
            ->byLocation($locationId)
            ->active()
            ->orderBy('display_order', 'asc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attractions,
        ]);
    }

    public function getByCategory(string $category): JsonResponse
    {
        $attractions = Attraction::with(['location', 'packages', 'addOns'])
            ->byCategory($category)
            ->active()
            ->orderBy('display_order', 'asc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attractions,
        ]);
    }

    public function toggleStatus(Attraction $attraction): JsonResponse
    {
        $attraction->update(['is_active' => !$attraction->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Attraction status updated successfully',
            'data' => $attraction,
        ]);
    }

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

        public function bulkImport(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'attractions' => 'required|array|min:1',
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

                    if (isset($attractionData['status'])) {
                        $mappedData['is_active'] = $attractionData['status'] === 'active';
                    } elseif (isset($attractionData['is_active'])) {
                        $mappedData['is_active'] = $attractionData['is_active'];
                    } else {
                        $mappedData['is_active'] = true;
                    }

                    $imageData = $attractionData['images'] ?? $attractionData['image'] ?? null;

                    if ($imageData) {
                        if (is_array($imageData) && count($imageData) > 0) {
                            $uploadedImages = [];
                            foreach ($imageData as $image) {
                                if (!empty($image)) {
                                    if (is_string($image) && strpos($image, 'data:image') === 0) {
                                        $uploadedImages[] = $this->handleBase64Upload($image);
                                    } else {
                                        $uploadedImages[] = $image;
                                    }
                                }
                            }
                            $mappedData['image'] = !empty($uploadedImages) ? $uploadedImages : [];
                        } elseif (is_string($imageData) && !empty($imageData)) {
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

                    $attraction = Attraction::create($mappedData);

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

    private function handleFileUpload($file): string
    {
        $filename = uniqid() . '.' . $file->getClientOriginalExtension();
        $path = 'images/attractions';
        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $file->move($fullPath, $filename);

        return $path . '/' . $filename;
    }

    private function handleBase64Upload($base64String): string
    {
        if (is_string($base64String) && strpos($base64String, 'data:image') === 0) {
            preg_match('/data:image\/(\w+);base64,/', $base64String, $matches);
            $imageType = $matches[1] ?? 'png';
            $imageData = substr($base64String, strpos($base64String, ',') + 1);
            $imageData = base64_decode($imageData);

            $filename = uniqid() . '.' . $imageType;
            $path = 'images/attractions';
            $fullPath = storage_path('app/public/' . $path);

            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            file_put_contents($fullPath . '/' . $filename, $imageData);

            return $path . '/' . $filename;
        }

        return $base64String;
    }

    private function handleImageUpload($image): string
    {
        if (is_string($image)) {
            $image = trim($image, '"');
        }

        return 'images/attractions/' . basename($image);
    }

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

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:attractions,id',
            'items.*.display_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['items'] as $item) {
            Attraction::where('id', $item['id'])
                ->update(['display_order' => $item['display_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Attractions reordered successfully',
        ]);
    }
}
