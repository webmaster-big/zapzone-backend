<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePackageRequest;
use App\Http\Requests\UpdatePackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Package;
use Illuminate\Support\Facades\Log;
use App\Models\PackageAddOn;
use App\Models\PackageAttraction;
use App\Models\PackageGiftCard;
use App\Models\PackagePromo;
use App\Models\PackageRoom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PackageController extends Controller
{
    /**
     * Display a listing of packages.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Package::with(['location', 'rooms', 'giftCards', 'promos']);

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

        // Search by name or description
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['id', 'name', 'price', 'created_at', 'category'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = min($request->get('per_page', 15), 50); // Max 50 items per page for better performance
        $packages = $query->paginate($perPage);

        // Load relationships after pagination to reduce memory usage
        $packages->load(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos']);

        return response()->json([
            'success' => true,
            'data' => [
                'packages' => PackageResource::collection($packages),
                'pagination' => [
                    'current_page' => $packages->currentPage(),
                    'last_page' => $packages->lastPage(),
                    'per_page' => $packages->perPage(),
                    'total' => $packages->total(),
                    'from' => $packages->firstItem(),
                    'to' => $packages->lastItem(),
                ],
            ],
        ]);
    }

    /**
     * Get public packages with location-based booking links
     * Groups packages by name and shows all locations where they're available
     */
    public function packagesGroupedByName(Request $request): JsonResponse
    {
        // search
        $search = $request->get('search', null);

        // Use chunk to reduce memory usage and avoid MySQL sort buffer errors
        $groupedPackages = [];
        
        $query = Package::with(['location'])
            ->select(['id', 'name', 'description', 'price', 'category', 'max_participants', 'duration', 'image', 'location_id', 'is_active'])
            ->where('is_active', true);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Process in chunks to avoid memory issues
        $query->orderBy('id')->chunk(100, function ($packages) use (&$groupedPackages) {
            foreach ($packages as $package) {
                $packageName = $package->name;

                if (!isset($groupedPackages[$packageName])) {
                    // Initialize the group with the first package's data
                    $groupedPackages[$packageName] = [
                        'name' => $package->name,
                        'description' => $package->description,
                        'price' => $package->price,
                        'category' => $package->category,
                        'max_guests' => $package->max_participants,
                        'duration' => $package->duration,
                        'image' => $package->image,
                        'locations' => [],
                        'booking_links' => [],
                    ];
                }

                // Add this location's information
                $locationSlug = str_replace(' ', '', $package->location->name);

                $groupedPackages[$packageName]['locations'][] = [
                    'location_id' => $package->location->id,
                    'location_name' => $package->location->name,
                    'location_slug' => $locationSlug,
                    'package_id' => $package->id,
                    'address' => $package->location->address,
                    'city' => $package->location->city,
                    'state' => $package->location->state,
                    'phone' => $package->location->phone,
                ];

                // Create booking link for this location
                $groupedPackages[$packageName]['booking_links'][] = [
                    'location' => $package->location->name,
                    'url' => "/book/package/{$locationSlug}/{$package->id}",
                    'package_id' => $package->id,
                    'location_id' => $package->location->id,
                ];
            }
        });

        // Convert to indexed array
        $result = array_values($groupedPackages);

        return response()->json([
            'success' => true,
            'data' => $result,
            'total' => count($result),
        ]);
    }

    /**
     * Store a newly created package.
     */
    public function store(StorePackageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Handle image upload (array of images)
        if (isset($validated['image']) && is_array($validated['image']) && count($validated['image']) > 0) {
            $uploadedImages = [];
            foreach ($validated['image'] as $image) {
                if (!empty($image)) {
                    $uploadedImages[] = $this->handleImageUpload($image);
                }
            }
            $validated['image'] = !empty($uploadedImages) ? $uploadedImages : null;
        } else {
            $validated['image'] = null;
        }

        $user = $request->user();

        // Set location_id based on user role
        if ($user) {
            if ($user->role === 'company_admin') {
                // Company admin can specify location_id, or default to their first location
                if (!isset($validated['location_id'])) {
                    $validated['location_id'] = \App\Models\Location::where('company_id', $user->company_id)
                        ->first()
                        ->id ?? null;
                }
            } else {
                // Other users can only create packages for their location
                $validated['location_id'] = $user->location_id;
            }
        }

        $package = Package::create($validated);

        // Handle attraction IDs
        if (isset($validated['attraction_ids']) && is_array($validated['attraction_ids'])) {
            foreach ($validated['attraction_ids'] as $attractionId) {
                PackageAttraction::create([
                    'package_id' => $package->id,
                    'attraction_id' => $attractionId,
                ]);
            }
        }

        // Handle add-on IDs
        if (isset($validated['addon_ids']) && is_array($validated['addon_ids'])) {
            foreach ($validated['addon_ids'] as $addonId) {
                PackageAddOn::create([
                    'package_id' => $package->id,
                    'add_on_id' => $addonId,
                ]);
            }
        }

        // Handle gift card IDs
        if (isset($validated['gift_card_ids']) && is_array($validated['gift_card_ids'])) {
            foreach ($validated['gift_card_ids'] as $giftCardId) {
                PackageGiftCard::create([
                    'package_id' => $package->id,
                    'gift_card_id' => $giftCardId,
                ]);
            }
        }

        // Handle promo IDs
        if (isset($validated['promo_ids']) && is_array($validated['promo_ids'])) {
            foreach ($validated['promo_ids'] as $promoId) {
                PackagePromo::create([
                    'package_id' => $package->id,
                    'promo_id' => $promoId,
                ]);
            }
        }

        // Handle room IDs
        if (isset($validated['room_ids']) && is_array($validated['room_ids'])) {
            foreach ($validated['room_ids'] as $roomId) {
                PackageRoom::create([
                    'package_id' => $package->id,
                    'room_id' => $roomId,
                ]);
            }
        }
        $package->load(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos']);

        return response()->json([
            'success' => true,
            'message' => 'Package created successfully',
            'data' => new PackageResource($package),
        ], 201);
    }

    /**
     * Display the specified package.
     */
    public function show($package): JsonResponse
    {
        $package = Package::with(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos'])->findOrFail($package);

        return response()->json([
            'success' => true,
            'data' => new PackageResource($package),
        ]);
    }

    /**
     * Update the specified package.
     */
    public function update(UpdatePackageRequest $request, $package): JsonResponse
    {
        $package = Package::with(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos'])->findOrFail($package);

        $validated = $request->validated();

        // Handle image upload (array of images)
        if (isset($validated['image']) && is_array($validated['image']) && count($validated['image']) > 0) {
            // Delete old images if they exist
            if ($package->image && is_array($package->image)) {
                foreach ($package->image as $oldImage) {
                    if (file_exists(storage_path('app/public/' . $oldImage))) {
                        unlink(storage_path('app/public/' . $oldImage));
                    }
                }
            }

            $uploadedImages = [];
            foreach ($validated['image'] as $image) {
                if (!empty($image)) {
                    $uploadedImages[] = $this->handleImageUpload($image);
                }
            }
            $validated['image'] = !empty($uploadedImages) ? $uploadedImages : null;
        }

        // Update the package basic information
        $package->update($validated);

        // Handle attraction IDs sync
        if (isset($validated['attraction_ids'])) {
            // Delete existing relationships
            PackageAttraction::where('package_id', $package->id)->delete();

            // Create new relationships
            foreach ($validated['attraction_ids'] as $attractionId) {
                PackageAttraction::create([
                    'package_id' => $package->id,
                    'attraction_id' => $attractionId,
                ]);
            }
        }

        // Handle add-on IDs sync
        if (isset($validated['addon_ids'])) {
            // Delete existing relationships
            PackageAddOn::where('package_id', $package->id)->delete();

            // Create new relationships
            foreach ($validated['addon_ids'] as $addonId) {
                PackageAddOn::create([
                    'package_id' => $package->id,
                    'add_on_id' => $addonId,
                ]);
            }
        }

        // Handle gift card IDs sync
        if (isset($validated['gift_card_ids'])) {
            // Delete existing relationships
            PackageGiftCard::where('package_id', $package->id)->delete();

            // Create new relationships
            foreach ($validated['gift_card_ids'] as $giftCardId) {
                PackageGiftCard::create([
                    'package_id' => $package->id,
                    'gift_card_id' => $giftCardId,
                ]);
            }
        }

        // Handle promo IDs sync
        if (isset($validated['promo_ids'])) {
            // Delete existing relationships
            PackagePromo::where('package_id', $package->id)->delete();

            // Create new relationships
            foreach ($validated['promo_ids'] as $promoId) {
                PackagePromo::create([
                    'package_id' => $package->id,
                    'promo_id' => $promoId,
                ]);
            }
        }

        // Handle room IDs sync
        if (isset($validated['room_ids'])) {
            // Delete existing relationships
            PackageRoom::where('package_id', $package->id)->delete();

            // Create new relationships
            foreach ($validated['room_ids'] as $roomId) {
                PackageRoom::create([
                    'package_id' => $package->id,
                    'room_id' => $roomId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Package updated successfully',
            'data' => new PackageResource($package),
        ]);
    }

    /**
     * Remove the specified package.
     */
    public function destroy(Request $request, Package $package): JsonResponse
    {

        // Delete associated images if they exist
        if ($package->image && is_array($package->image)) {
            foreach ($package->image as $image) {
                if (file_exists(storage_path('app/public/' . $image))) {
                    unlink(storage_path('app/public/' . $image));
                }
            }
        }

        $packageName = $package->name;
        $packageId = $package->id;
        $locationId = $package->location_id;

        $package->delete();

        // Log package deletion
        ActivityLog::log(
            action: 'Package Deleted',
            category: 'delete',
            description: "Package '{$packageName}' was deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'package',
            entityId: $packageId
        );

        return response()->json([
            'success' => true,
            'message' => 'Package deleted successfully',
        ]);
    }

    /**
     * Get packages by location.
     */
    public function getByLocation(Request $request, int $locationId): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to this location
        if ($user) {
            if ($user->role === 'company_admin') {
                // Company admin can only view packages from their company's locations
                $companyLocationIds = \App\Models\Location::where('company_id', $user->company_id)
                    ->pluck('id')
                    ->toArray();

                if (!in_array($locationId, $companyLocationIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to view packages from this location',
                    ], 403);
                }
            } else {
                // Other users can only view packages from their location
                if ($locationId !== $user->location_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to view packages from this location',
                    ], 403);
                }
            }
        }

        $packages = Package::with(['attractions', 'addOns', 'rooms'])
            ->byLocation($locationId)
            ->active()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => PackageResource::collection($packages),
        ]);
    }

    /**
     * Get packages by category.
     */
    public function getByCategory(Request $request, string $category): JsonResponse
    {
        $user = $request->user();
        $query = Package::with(['location', 'attractions', 'addOns', 'rooms'])
            ->byCategory($category)
            ->active();

        // Filter by user's accessible locations
        if ($user) {
            if ($user->role === 'company_admin') {
                // Company admin can see packages from their company's locations
                $companyLocationIds = \App\Models\Location::where('company_id', $user->company_id)
                    ->pluck('id')
                    ->toArray();

                $query->whereIn('location_id', $companyLocationIds);
            } else {
                // Other users can only see packages from their location
                $query->where('location_id', $user->location_id);
            }
        }

        $packages = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => PackageResource::collection($packages),
        ]);
    }

    /**
     * Toggle package active status.
     */
    public function toggleStatus(Request $request, Package $package): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to toggle this package
        if ($user) {
            if ($user->role === 'company_admin') {
                // Company admin can only toggle packages from their company's locations
                $companyLocationIds = \App\Models\Location::where('company_id', $user->company_id)
                    ->pluck('id')
                    ->toArray();

                if (!in_array($package->location_id, $companyLocationIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to toggle this package status',
                    ], 403);
                }
            } else {
                // Other users can only toggle packages from their location
                if ($package->location_id !== $user->location_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized to toggle this package status',
                    ], 403);
                }
            }
        }

        $package->update(['is_active' => !$package->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Package status updated successfully',
            'data' => new PackageResource($package),
        ]);
    }

    /**
     * Attach attractions to package.
     */
    public function attachAttractions(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'attraction_ids' => 'required|array',
            'attraction_ids.*' => 'exists:attractions,id',
        ]);

        $package->attractions()->attach($validated['attraction_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Attractions attached successfully',
        ]);
    }

    /**
     * Detach attractions from package.
     */
    public function detachAttractions(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'attraction_ids' => 'required|array',
            'attraction_ids.*' => 'exists:attractions,id',
        ]);

        $package->attractions()->detach($validated['attraction_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Attractions detached successfully',
        ]);
    }

    /**
     * Attach add-ons to package.
     */
    public function attachAddOns(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'addon_ids' => 'required|array',
            'addon_ids.*' => 'exists:add_ons,id',
        ]);

        $package->addOns()->attach($validated['addon_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Add-ons attached successfully',
        ]);
    }

    /**
     * Detach add-ons from package.
     */
    public function detachAddOns(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'addon_ids' => 'required|array',
            'addon_ids.*' => 'exists:add_ons,id',
        ]);

        $package->addOns()->detach($validated['addon_ids']);

        return response()->json([
            'success' => true,
            'message' => 'Add-ons detached successfully',
        ]);
    }

    /**
     * Bulk import packages
     */
    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'packages' => 'required|array|min:1',
            'packages.*.location_id' => 'required|exists:locations,id',
            'packages.*.name' => 'required|string|max:255',
            'packages.*.description' => 'nullable|string',
            'packages.*.price' => 'required|numeric|min:0',
            'packages.*.category' => 'required|string|max:100',
            'packages.*.max_guests' => 'nullable|integer|min:1',
            'packages.*.duration' => 'nullable|integer|min:1',
            'packages.*.image' => 'nullable|string',
            'packages.*.is_active' => 'nullable|boolean',
            'packages.*.time_slot_start' => 'nullable|date_format:H:i:s',
            'packages.*.time_slot_end' => 'nullable|date_format:H:i:s',
            'packages.*.time_slot_interval' => 'nullable|integer|min:1',
            'packages.*.attraction_ids' => 'nullable|array',
            'packages.*.attraction_ids.*' => 'exists:attractions,id',
            'packages.*.addon_ids' => 'nullable|array',
            'packages.*.addon_ids.*' => 'exists:add_ons,id',
            'packages.*.room_ids' => 'nullable|array',
            'packages.*.room_ids.*' => 'exists:rooms,id',
            'packages.*.gift_card_ids' => 'nullable|array',
            'packages.*.gift_card_ids.*' => 'exists:gift_cards,id',
            'packages.*.promo_ids' => 'nullable|array',
            'packages.*.promo_ids.*' => 'exists:promos,id',
        ]);

        $importedPackages = [];
        $errors = [];

        foreach ($validated['packages'] as $index => $packageData) {
            try {
                // Handle image upload if provided (array of images)
                if (isset($packageData['image']) && is_array($packageData['image']) && count($packageData['image']) > 0) {
                    $uploadedImages = [];
                    foreach ($packageData['image'] as $image) {
                        if (!empty($image)) {
                            $uploadedImages[] = $this->handleImageUpload($image);
                        }
                    }
                    $packageData['image'] = !empty($uploadedImages) ? $uploadedImages : null;
                } else {
                    $packageData['image'] = null;
                }

                // Extract relationship IDs
                $attractionIds = $packageData['attraction_ids'] ?? [];
                $addonIds = $packageData['addon_ids'] ?? [];
                $roomIds = $packageData['room_ids'] ?? [];
                $giftCardIds = $packageData['gift_card_ids'] ?? [];
                $promoIds = $packageData['promo_ids'] ?? [];

                // Remove relationship IDs from package data
                unset(
                    $packageData['attraction_ids'],
                    $packageData['addon_ids'],
                    $packageData['room_ids'],
                    $packageData['gift_card_ids'],
                    $packageData['promo_ids']
                );

                // Create the package with a unique ID
                $package = Package::create($packageData);

                // Attach attractions
                if (!empty($attractionIds)) {
                    foreach ($attractionIds as $attractionId) {
                        PackageAttraction::create([
                            'package_id' => $package->id,
                            'attraction_id' => $attractionId,
                        ]);
                    }
                }

                // Attach add-ons
                if (!empty($addonIds)) {
                    foreach ($addonIds as $addonId) {
                        PackageAddOn::create([
                            'package_id' => $package->id,
                            'add_on_id' => $addonId,
                        ]);
                    }
                }

                // Attach rooms
                if (!empty($roomIds)) {
                    foreach ($roomIds as $roomId) {
                        PackageRoom::create([
                            'package_id' => $package->id,
                            'room_id' => $roomId,
                        ]);
                    }
                }

                // Attach gift cards
                if (!empty($giftCardIds)) {
                    foreach ($giftCardIds as $giftCardId) {
                        PackageGiftCard::create([
                            'package_id' => $package->id,
                            'gift_card_id' => $giftCardId,
                        ]);
                    }
                }

                // Attach promos
                if (!empty($promoIds)) {
                    foreach ($promoIds as $promoId) {
                        PackagePromo::create([
                            'package_id' => $package->id,
                            'promo_id' => $promoId,
                        ]);
                    }
                }

                // Load relationships
                $package->load(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos']);
                $importedPackages[] = new PackageResource($package);

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'name' => $packageData['name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $response = [
            'success' => true,
            'message' => count($importedPackages) . ' packages imported successfully',
            'data' => [
                'imported' => $importedPackages,
                'imported_count' => count($importedPackages),
                'failed_count' => count($errors),
            ],
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, count($errors) > 0 ? 207 : 201);
    }

    /**
     * Handle image upload - supports base64 or file upload
     */
    private function handleImageUpload($image): string
    {
        // Check if it's a base64 string
        if (is_string($image) && strpos($image, 'data:image') === 0) {
            try {
                // Extract base64 data
                preg_match('/data:image\/(\w+);base64,/', $image, $matches);
                
                if (empty($matches)) {
                    Log::error('Invalid base64 image format', ['image_start' => substr($image, 0, 100)]);
                    throw new \Exception('Invalid image format');
                }
                
                $imageType = $matches[1] ?? 'png';
                $base64Data = substr($image, strpos($image, ',') + 1);
                
                // Validate base64 data
                if (empty($base64Data)) {
                    Log::error('Empty base64 data');
                    throw new \Exception('Empty image data');
                }
                
                $imageData = base64_decode($base64Data, true);
                
                // Check if decode was successful
                if ($imageData === false) {
                    Log::error('Failed to decode base64 data', [
                        'data_length' => strlen($base64Data),
                        'data_start' => substr($base64Data, 0, 100)
                    ]);
                    throw new \Exception('Failed to decode image data');
                }

                // Generate shorter filename
                $filename = uniqid() . '.' . $imageType;
                $path = 'images/packages';
                $fullPath = storage_path('app/public/' . $path);

                Log::info('Package image upload attempt', [
                    'filename' => $filename,
                    'path' => $path,
                    'fullPath' => $fullPath,
                    'imageType' => $imageType,
                    'imageSize' => strlen($imageData)
                ]);

                // Create directory if it doesn't exist
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                    Log::info('Created directory', ['path' => $fullPath]);
                }

                // Save the file
                $bytesWritten = file_put_contents($fullPath . '/' . $filename, $imageData);
                
                if ($bytesWritten === false) {
                    Log::error('Failed to write image file', ['file' => $fullPath . '/' . $filename]);
                    throw new \Exception('Failed to save image file');
                }
                
                Log::info('Image saved successfully', [
                    'file' => $fullPath . '/' . $filename,
                    'bytes' => $bytesWritten
                ]);

                // Return the relative path (for storage URL)
                return $path . '/' . $filename;
                
            } catch (\Exception $e) {
                Log::error('Error handling image upload', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        }

        // If it's already a file path or URL, return as is
        Log::info('Image path returned as-is', ['image' => substr($image, 0, 100)]);
        return $image;
    }
}
