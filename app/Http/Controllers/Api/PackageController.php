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
     * Note: Soft-deleted packages are automatically excluded by Laravel's SoftDeletes trait.
     */
    public function index(Request $request): JsonResponse
    {
        // SoftDeletes trait automatically excludes deleted packages
        $query = Package::with(['location', 'rooms', 'giftCards', 'promos', 'availabilitySchedules']);

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

        // Filter by package type
        if ($request->has('package_type')) {
            $query->byPackageType($request->package_type);
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
        $packages->load(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos', 'availabilitySchedules']);

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
     * Note: Only active, non-deleted packages are shown to public.
     */
    public function packagesGroupedByName(Request $request): JsonResponse
    {
        // search
        $search = $request->get('search', null);

        // Use chunk to reduce memory usage and avoid MySQL sort buffer errors
        $groupedPackages = [];

        // SoftDeletes trait automatically excludes deleted packages
        $query = Package::with(['location', 'availabilitySchedules'])
            ->select(['id', 'name', 'description', 'price', 'category', 'min_participants', 'max_participants', 'duration', 'image', 'location_id', 'is_active', 'package_type', 'duration_unit', 'price_per_additional'])
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
                        'duration_unit' => $package->duration_unit,
                        'image' => $package->image,
                        'locations' => [],
                        'booking_links' => [],
                        'package_type' => $package->package_type,
                        'min_participants' => $package->min_participants,
                        "price_per_additional" => $package->price_per_additional,
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

        // Handle invitation_file upload
        if (isset($validated['invitation_file']) && !empty($validated['invitation_file'])) {
            try {
                $validated['invitation_file'] = $this->handleFileUpload($validated['invitation_file'], 'invitations');
            } catch (\Exception $e) {
                Log::error('Failed to upload invitation file during package creation', [
                    'error' => $e->getMessage()
                ]);
                $validated['invitation_file'] = null;
            }
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
     * Note: Soft-deleted packages will return 404 (excluded by SoftDeletes trait).
     */
    public function show($package): JsonResponse
    {
        // findOrFail automatically excludes soft-deleted packages
        $package = Package::with(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos', 'availabilitySchedules'])->findOrFail($package);

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
        $package = Package::with(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos', 'availabilitySchedules'])->findOrFail($package);

        $validated = $request->validated();

        // Handle image upload (array of images)
        if (isset($validated['image']) && is_array($validated['image']) && count($validated['image']) > 0) {
            // Delete old images if they exist
            if ($package->image && is_array($package->image)) {
                foreach ($package->image as $oldImage) {
                    $oldImagePath = storage_path('app/public/' . $oldImage);
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                        Log::info('Deleted old image', ['path' => $oldImagePath]);
                    }
                }
            }

            $uploadedImages = [];
            foreach ($validated['image'] as $image) {
                if (!empty($image)) {
                    try {
                        $uploadedImages[] = $this->handleImageUpload($image);
                    } catch (\Exception $e) {
                        Log::error('Failed to upload image during package update', [
                            'package_id' => $package->id,
                            'error' => $e->getMessage()
                        ]);
                        // Continue with other images
                    }
                }
            }
            $validated['image'] = !empty($uploadedImages) ? $uploadedImages : null;
        }

        // Handle invitation_file upload
        if (isset($validated['invitation_file']) && !empty($validated['invitation_file'])) {
            // Delete old invitation file if it exists and is a local file
            if ($package->invitation_file && 
                !filter_var($package->invitation_file, FILTER_VALIDATE_URL) &&
                strpos($package->invitation_file, 'data:') !== 0) {
                
                $oldFilePath = storage_path('app/public/' . $package->invitation_file);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                    Log::info('Deleted old invitation file', ['path' => $oldFilePath]);
                }
            }

            try {
                $validated['invitation_file'] = $this->handleFileUpload($validated['invitation_file'], 'invitations');
            } catch (\Exception $e) {
                Log::error('Failed to upload invitation file during package update', [
                    'package_id' => $package->id,
                    'error' => $e->getMessage()
                ]);
                // Keep the old file if new upload fails
                unset($validated['invitation_file']);
            }
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
     * Soft delete the specified package.
     * Images and relationships are preserved for historical bookings.
     */
    public function destroy(Request $request, Package $package): JsonResponse
    {
        $packageName = $package->name;
        $packageId = $package->id;
        $locationId = $package->location_id;

        // Soft delete the package (sets deleted_at timestamp)
        $package->delete();

        // Log package deletion
        ActivityLog::log(
            action: 'Package Deleted',
            category: 'delete',
            description: "Package '{$packageName}' was soft deleted",
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

    // delete package add ons
    public function deletePackageAddOns( Package $package): JsonResponse
    {
        // Delete all add-ons associated with the package
        PackageAddOn::where('package_id', $package->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'All add-ons for the package have been deleted successfully',
        ]);
    }

    /**
     * Restore a soft-deleted package.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $package = Package::withTrashed()->findOrFail($id);

        // UPDATE DELETE_AT TO NULL
        $package->deleted_at = null;
        $package->save();

        if (!$package->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Package is not deleted',
            ], 400);
        }

        $package->restore();

        // Log package restoration
        ActivityLog::log(
            action: 'Package Restored',
            category: 'update',
            description: "Package '{$package->name}' was restored",
            userId: auth()->id(),
            locationId: $package->location_id,
            entityType: 'package',
            entityId: $package->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Package restored successfully',
            'data' => new PackageResource($package),
        ]);
    }

    /**
     * Permanently delete a soft-deleted package.
     * This action cannot be undone.
     */
    public function forceDelete(Request $request, int $id): JsonResponse
    {
        $package = Package::withTrashed()->findOrFail($id);

        if (!$package->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Package must be soft deleted first',
            ], 400);
        }

        $packageName = $package->name;
        $packageId = $package->id;
        $locationId = $package->location_id;

        // Delete associated images if they exist
        if ($package->image && is_array($package->image)) {
            foreach ($package->image as $image) {
                if (file_exists(storage_path('app/public/' . $image))) {
                    unlink(storage_path('app/public/' . $image));
                }
            }
        }

        // Permanently delete the package
        $package->forceDelete();

        // Log package permanent deletion
        ActivityLog::log(
            action: 'Package Permanently Deleted',
            category: 'delete',
            description: "Package '{$packageName}' was permanently deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'package',
            entityId: $packageId
        );

        return response()->json([
            'success' => true,
            'message' => 'Package permanently deleted',
        ]);
    }

    /**
     * Get packages by location.
     * Note: Only active, non-deleted packages are returned.
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

        // SoftDeletes trait automatically excludes deleted packages
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
     * Note: Only active, non-deleted packages are returned.
     */
    public function getByCategory(Request $request, string $category): JsonResponse
    {
        $user = $request->user();
        // SoftDeletes trait automatically excludes deleted packages
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

    public function toggleIsActiveStatus($id): JsonResponse
    {
        $package = Package::findOrFail($id);
        $package->is_active = !$package->is_active;
        $package->save();

        return response()->json([
            'success' => true,
            'message' => 'Package status updated successfully',
            'data' => [
                'package_id' => $package->id,
                'is_active' => $package->is_active,
            ],
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
            'packages.*.features' => 'nullable|array',
            'packages.*.features.*' => 'nullable|string',
            'packages.*.price_per_additional' => 'nullable|numeric|min:0',
            'packages.*.min_participants' => 'nullable|integer|min:1',
            'packages.*.max_participants' => 'nullable|integer|min:1',
            'packages.*.duration' => 'nullable|numeric|min:0.01',
            'packages.*.duration_unit' => 'nullable|string',
            'packages.*.price_per_additional_30min' => 'nullable|numeric|min:0',
            'packages.*.price_per_additional_1hr' => 'nullable|numeric|min:0',
            'packages.*.image' => 'nullable|max:30000000',
            'packages.*.is_active' => 'nullable|boolean',
            'packages.*.has_guest_of_honor' => 'nullable|boolean',
            'packages.*.package_type' => 'nullable|string',
            'packages.*.partial_payment_percentage' => 'nullable|numeric|min:0|max:100',
            'packages.*.partial_payment_fixed' => 'nullable|numeric|min:0',
            // Support both ID arrays and full object arrays
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
            // Full object arrays from export
            'packages.*.attractions' => 'nullable|array',
            'packages.*.attractions.*.id' => 'nullable|exists:attractions,id',
            'packages.*.add_ons' => 'nullable|array',
            'packages.*.add_ons.*.id' => 'nullable|exists:add_ons,id',
            'packages.*.rooms' => 'nullable|array',
            'packages.*.rooms.*.id' => 'nullable|exists:rooms,id',
            'packages.*.gift_cards' => 'nullable|array',
            'packages.*.gift_cards.*.id' => 'nullable|exists:gift_cards,id',
            'packages.*.promos' => 'nullable|array',
            'packages.*.promos.*.id' => 'nullable|exists:promos,id',
            // Availability schedules
            'packages.*.availability_schedules' => 'nullable|array',
            'packages.*.availability_schedules.*.availability_type' => 'nullable|in:daily,weekly,monthly',
            'packages.*.availability_schedules.*.day_configuration' => 'nullable|array',
            'packages.*.availability_schedules.*.time_slot_start' => 'nullable|string',
            'packages.*.availability_schedules.*.time_slot_end' => 'nullable|string',
            'packages.*.availability_schedules.*.time_slot_interval' => 'nullable|integer|min:15',
            'packages.*.availability_schedules.*.priority' => 'nullable|integer|min:0',
            'packages.*.availability_schedules.*.is_active' => 'nullable|boolean',
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

                // Extract relationship IDs - support both formats
                // 1. Direct ID arrays (attraction_ids, addon_ids, etc.)
                // 2. Full object arrays from export (attractions, add_ons, etc.)

                $attractionIds = $packageData['attraction_ids'] ?? [];
                if (empty($attractionIds) && isset($packageData['attractions']) && is_array($packageData['attractions'])) {
                    $attractionIds = array_filter(array_column($packageData['attractions'], 'id'));
                }

                $addonIds = $packageData['addon_ids'] ?? [];
                if (empty($addonIds) && isset($packageData['add_ons']) && is_array($packageData['add_ons'])) {
                    $addonIds = array_filter(array_column($packageData['add_ons'], 'id'));
                }

                $roomIds = $packageData['room_ids'] ?? [];
                if (empty($roomIds) && isset($packageData['rooms']) && is_array($packageData['rooms'])) {
                    $roomIds = array_filter(array_column($packageData['rooms'], 'id'));
                }

                $giftCardIds = $packageData['gift_card_ids'] ?? [];
                if (empty($giftCardIds) && isset($packageData['gift_cards']) && is_array($packageData['gift_cards'])) {
                    $giftCardIds = array_filter(array_column($packageData['gift_cards'], 'id'));
                }

                $promoIds = $packageData['promo_ids'] ?? [];
                if (empty($promoIds) && isset($packageData['promos']) && is_array($packageData['promos'])) {
                    $promoIds = array_filter(array_column($packageData['promos'], 'id'));
                }

                // Extract availability schedules
                $availabilitySchedules = $packageData['availability_schedules'] ?? [];

                // Remove relationship data from package data
                unset(
                    $packageData['attraction_ids'],
                    $packageData['addon_ids'],
                    $packageData['room_ids'],
                    $packageData['gift_card_ids'],
                    $packageData['promo_ids'],
                    $packageData['attractions'],
                    $packageData['add_ons'],
                    $packageData['rooms'],
                    $packageData['gift_cards'],
                    $packageData['promos'],
                    $packageData['availability_schedules'],
                    $packageData['location'] // Remove nested location object
                );

                // Map max_guests to max_participants if present
                if (isset($packageData['max_guests'])) {
                    $packageData['max_participants'] = $packageData['max_guests'];
                    unset($packageData['max_guests']);
                }

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

                // Create availability schedules
                if (!empty($availabilitySchedules)) {
                    foreach ($availabilitySchedules as $scheduleData) {
                        // Skip if missing required fields
                        if (empty($scheduleData['availability_type']) ||
                            empty($scheduleData['time_slot_start']) ||
                            empty($scheduleData['time_slot_end']) ||
                            empty($scheduleData['time_slot_interval'])) {
                            continue;
                        }

                        \App\Models\PackageAvailabilitySchedule::create([
                            'package_id' => $package->id,
                            'availability_type' => $scheduleData['availability_type'],
                            'day_configuration' => $scheduleData['day_configuration'] ?? null,
                            'time_slot_start' => $scheduleData['time_slot_start'],
                            'time_slot_end' => $scheduleData['time_slot_end'],
                            'time_slot_interval' => $scheduleData['time_slot_interval'],
                            'priority' => $scheduleData['priority'] ?? 0,
                            'is_active' => $scheduleData['is_active'] ?? true,
                        ]);
                    }
                }

                // Load relationships
                $package->load(['location', 'attractions', 'addOns', 'rooms', 'giftCards', 'promos', 'availabilitySchedules']);
                $importedPackages[] = new PackageResource($package);

            } catch (\Exception $e) {
                Log::error('Failed to import package', [
                    'index' => $index,
                    'name' => $packageData['name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

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

    /**
     * Handle file upload (PDFs, documents, etc.) - supports base64, URL, or file upload
     */
    private function handleFileUpload($file, string $subDirectory = 'files'): string
    {
        // If it's a URL (http/https), return as is
        if (filter_var($file, FILTER_VALIDATE_URL)) {
            Log::info('File is URL, returned as-is', ['url' => substr($file, 0, 100)]);
            return $file;
        }

        // Check if it's a base64 string (data URI)
        if (is_string($file) && strpos($file, 'data:') === 0) {
            try {
                // Extract MIME type and base64 data
                preg_match('/data:([^;]+);base64,/', $file, $matches);

                if (empty($matches)) {
                    Log::error('Invalid base64 file format', ['file_start' => substr($file, 0, 100)]);
                    throw new \Exception('Invalid file format');
                }

                $mimeType = $matches[1] ?? 'application/octet-stream';
                $base64Data = substr($file, strpos($file, ',') + 1);

                // Validate base64 data
                if (empty($base64Data)) {
                    Log::error('Empty base64 data');
                    throw new \Exception('Empty file data');
                }

                $fileData = base64_decode($base64Data, true);

                if ($fileData === false) {
                    Log::error('Failed to decode base64 data');
                    throw new \Exception('Failed to decode file data');
                }

                // Determine file extension from MIME type
                $extension = 'pdf'; // default
                $mimeToExt = [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    'text/plain' => 'txt',
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/gif' => 'gif',
                ];
                
                if (isset($mimeToExt[$mimeType])) {
                    $extension = $mimeToExt[$mimeType];
                }

                // Generate filename
                $filename = uniqid() . '.' . $extension;
                $path = $subDirectory;
                $fullPath = storage_path('app/public/' . $path);

                Log::info('File upload attempt', [
                    'filename' => $filename,
                    'path' => $path,
                    'fullPath' => $fullPath,
                    'mimeType' => $mimeType,
                    'fileSize' => strlen($fileData)
                ]);

                // Create directory if it doesn't exist
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0755, true);
                    Log::info('Created directory', ['path' => $fullPath]);
                }

                // Save the file
                $bytesWritten = file_put_contents($fullPath . '/' . $filename, $fileData);

                if ($bytesWritten === false) {
                    Log::error('Failed to write file', ['file' => $fullPath . '/' . $filename]);
                    throw new \Exception('Failed to save file');
                }

                Log::info('File saved successfully', [
                    'file' => $fullPath . '/' . $filename,
                    'bytes' => $bytesWritten
                ]);

                // Return the relative path (for storage URL)
                return $path . '/' . $filename;

            } catch (\Exception $e) {
                Log::error('Error handling file upload', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        }

        // If it's already a file path, return as is
        Log::info('File path returned as-is', ['file' => substr($file, 0, 100)]);
        return $file;
    }

    // store package room
    public function storePackageRoom(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'room_id' => 'required|exists:rooms,id',
        ]);

        $packageRoom = PackageRoom::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Package room created successfully',
            'data' => $packageRoom,
        ], 201);
    }

    /**
     * Get availability schedules for a package.
     */
    public function getAvailabilitySchedules(Package $package): JsonResponse
    {
        $schedules = $package->availabilitySchedules()
            ->orderBy('priority', 'desc')
            ->orderBy('availability_type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'package_id' => $package->id,
                'package_name' => $package->name,
                'schedules' => $schedules,
            ],
        ]);
    }

    /**
     * Store a new availability schedule for a package.
     */
    public function storeAvailabilitySchedule(Request $request, Package $package): JsonResponse
    {
        Log::info('Creating new availability schedule', [
            'package_id' => $package->id,
            'package_name' => $package->name,
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
        ]);

        $validated = $request->validate([
            'availability_type' => 'required|in:daily,weekly,monthly',
            'day_configuration' => 'nullable|array',
            'day_configuration.*' => [
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('availability_type');

                    if ($type === 'weekly' && $value) {
                        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                        if (!in_array(strtolower($value), $validDays)) {
                            $fail('Day configuration must be a valid day name (e.g., monday, tuesday).');
                        }
                    }

                    if ($type === 'monthly' && $value) {
                        $pattern = '/^(first|second|third|fourth|last)-(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i';
                        if (!preg_match($pattern, $value)) {
                            $fail('Day configuration must follow the pattern: occurrence-day (e.g., last-sunday, first-monday).');
                        }
                    }
                },
            ],
            'time_slot_start' => 'required|date_format:H:i',
            'time_slot_end' => 'required|date_format:H:i',
            'time_slot_interval' => 'required|integer|min:15|max:240',
            'priority' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        Log::info('Validation passed for availability schedule', [
            'package_id' => $package->id,
            'validated_data' => $validated,
        ]);

        $validated['package_id'] = $package->id;
        $schedule = \App\Models\PackageAvailabilitySchedule::create($validated);

        Log::info('Availability schedule created successfully', [
            'schedule_id' => $schedule->id,
            'package_id' => $package->id,
            'availability_type' => $schedule->availability_type,
            'day_configuration' => $schedule->day_configuration,
            'time_range' => $schedule->time_slot_start . ' - ' . $schedule->time_slot_end,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Availability schedule created successfully',
            'data' => [
                'package_id' => $package->id,
                'package_name' => $package->name,
                'schedule' => $schedule,
            ],
        ], 201);
    }

    /**
     * Update availability schedules for a package (bulk replace).
     */
    public function updateAvailabilitySchedules(\App\Http\Requests\StorePackageAvailabilityScheduleRequest $request, Package $package): JsonResponse
    {
        Log::info('Bulk updating availability schedules', [
            'package_id' => $package->id,
            'package_name' => $package->name,
            'schedules_count' => count($request->input('schedules', [])),
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
        ]);

        $validated = $request->validated();

        Log::info('Validation passed for bulk schedule update', [
            'package_id' => $package->id,
            'schedules_to_create' => count($validated['schedules']),
        ]);

        // Get existing schedules count before deletion
        $existingSchedulesCount = $package->availabilitySchedules()->count();
        Log::info('Deleting existing schedules', [
            'package_id' => $package->id,
            'existing_count' => $existingSchedulesCount,
        ]);

        // Delete existing schedules
        $package->availabilitySchedules()->delete();

        // Create new schedules
        $createdSchedules = [];
        foreach ($validated['schedules'] as $index => $scheduleData) {
            $scheduleData['package_id'] = $package->id;

            Log::info('Creating schedule', [
                'index' => $index,
                'package_id' => $package->id,
                'availability_type' => $scheduleData['availability_type'],
                'day_configuration' => $scheduleData['day_configuration'] ?? null,
            ]);

            $createdSchedules[] = \App\Models\PackageAvailabilitySchedule::create($scheduleData);
        }

        Log::info('All schedules created successfully', [
            'package_id' => $package->id,
            'deleted_count' => $existingSchedulesCount,
            'created_count' => count($createdSchedules),
        ]);

        // Log the activity
        ActivityLog::log(
            action: 'Availability Schedules Updated',
            category: 'update',
            description: "Updated availability schedules for package '{$package->name}'",
            userId: auth()->id() ?? null,
            locationId: $package->location_id,
            entityType: 'package',
            entityId: $package->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Availability schedules updated successfully',
            'data' => [
                'package_id' => $package->id,
                'package_name' => $package->name,
                'schedules' => $createdSchedules,
            ],
        ]);
    }

    /**
     * Delete a specific availability schedule.
     */
    public function deleteAvailabilitySchedule(Package $package, int $scheduleId): JsonResponse
    {
        Log::info('Deleting availability schedule', [
            'package_id' => $package->id,
            'package_name' => $package->name,
            'schedule_id' => $scheduleId,
            'user_id' => auth()->id(),
        ]);

        $schedule = $package->availabilitySchedules()->findOrFail($scheduleId);

        Log::info('Schedule found, proceeding with deletion', [
            'schedule_id' => $schedule->id,
            'package_id' => $package->id,
            'availability_type' => $schedule->availability_type,
            'day_configuration' => $schedule->day_configuration,
            'time_range' => $schedule->time_slot_start . ' - ' . $schedule->time_slot_end,
        ]);

        $schedule->delete();

        Log::info('Availability schedule deleted successfully', [
            'schedule_id' => $scheduleId,
            'package_id' => $package->id,
        ]);

        // Log the activity
        ActivityLog::log(
            action: 'Availability Schedule Deleted',
            category: 'delete',
            description: "Deleted {$schedule->availability_type} schedule for package '{$package->name}'",
            userId: auth()->id() ?? null,
            locationId: $package->location_id,
            entityType: 'package',
            entityId: $package->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Availability schedule deleted successfully',
        ]);
    }
}
