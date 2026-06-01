<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AddOn;
use App\Models\User;
use App\Http\Traits\ScopesByAuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AddOnController extends Controller
{
    use ScopesByAuthUser;

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 500);

            $query = AddOn::with(['location:id,name', 'packages:id,name']);

            $this->applyAuthScope($query, $request);

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            } else {
                $query->active();
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

            if (in_array($sortBy, ['name', 'price', 'created_at'])) {
                $query->orderBy($sortBy, $sortOrder);
            }

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
        } catch (\Exception $e) {
            Log::error('Error fetching add-ons', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch add-ons',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'image' => 'nullable|string|max:27262976',
            'is_active' => 'boolean',
            'is_force_add_on' => 'boolean',
            'price_each_packages' => 'nullable|array',
            'price_each_packages.*.package_id' => 'required_with:price_each_packages|integer|exists:packages,id',
            'price_each_packages.*.price' => 'required_with:price_each_packages|numeric|min:0',
            'price_each_packages.*.minimum_quantity' => 'nullable|integer|min:1',
            'min_quantity' => 'sometimes|integer|min:1',
            'max_quantity' => 'sometimes|nullable|integer|min:1|gte:min_quantity',
        ]);

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

    public function show(AddOn $addOn): JsonResponse
    {
        $addOn->load(['location', 'packages', 'bookings']);

        return response()->json([
            'success' => true,
            'data' => $addOn,
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $addOn = AddOn::findOrFail($id);

        $originalName = $addOn->name;

        $validated = $request->validate([
            'location_id' => 'sometimes|nullable|exists:locations,id',
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|nullable|numeric|min:0',
            'description' => 'sometimes|nullable|string',
            'image' => 'nullable|string|max:27262976',
            'is_active' => 'sometimes|boolean',
            'is_force_add_on' => 'sometimes|boolean',
            'price_each_packages' => 'sometimes|nullable|array',
            'price_each_packages.*.package_id' => 'required_with:price_each_packages|integer|exists:packages,id',
            'price_each_packages.*.price' => 'required_with:price_each_packages|numeric|min:0',
            'price_each_packages.*.minimum_quantity' => 'nullable|integer|min:1',
            'min_quantity' => 'sometimes|integer|min:1',
            'max_quantity' => 'sometimes|nullable|integer|min:1|gte:min_quantity',
        ]);

        Log::info('Update request received', [
            'addon_id' => $addOn->id,
            'addon_name' => $addOn->name,
            'validated_data' => $validated,
            'has_image' => isset($validated['image'])
        ]);

        if (isset($validated['image']) && !empty($validated['image'])) {
            if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
                unlink(storage_path('app/public/' . $addOn->image));
            }
            $validated['image'] = $this->handleImageUpload($validated['image']);
            Log::info('Image processed', ['new_path' => $validated['image']]);
        }

        $addOn->update($validated);
        $addOn->refresh();
        $addOn->load(['location', 'packages']);

        Log::info("Add-On '{$addOn->name}' (ID: {$addOn->id}) was updated from '{$originalName}'.");

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Add-On Updated',
            category: 'update',
            description: "Add-on '{$addOn->name}' was updated",
            userId: auth()->id(),
            locationId: $addOn->location_id,
            entityType: 'addon',
            entityId: $addOn->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'changes' => [
                    'original_name' => $originalName,
                    'new_name' => $addOn->name,
                ],
                'addon_details' => [
                    'addon_id' => $addOn->id,
                    'name' => $addOn->name,
                    'price' => $addOn->price,
                    'location_id' => $addOn->location_id,
                    'is_active' => $addOn->is_active,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Add-on updated successfully',
            'data' => $addOn,
        ]);
    }

    public function destroy( $id): JsonResponse
    {
        $addOn = AddOn::findOrFail($id);

        $deletedBy = User::findOrFail(auth()->id());

        if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
            unlink(storage_path('app/public/' . $addOn->image));
        }

        $addOnName = $addOn->name;
        $addOnId = $addOn->id;
        $locationId = $addOn->location_id;

        $addOn->delete();

        ActivityLog::log(
            action: 'Add-On Deleted',
            category: 'delete',
            description: "Add-on '{$addOnName}' was deleted by {$deletedBy->first_name} {$deletedBy->last_name}",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'addon',
            entityId: $addOnId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $deletedBy->first_name . ' ' . $deletedBy->last_name,
                    'email' => $deletedBy->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'addon_details' => [
                    'addon_id' => $addOnId,
                    'name' => $addOnName,
                    'location_id' => $locationId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Add-on deleted successfully',
        ]);
    }

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

    public function toggleStatus(AddOn $addOn): JsonResponse
    {
        $addOn->update(['is_active' => !$addOn->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Add-on status updated successfully',
            'data' => $addOn,
        ]);
    }

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

    private function handleImageUpload($image): string
    {
        if (is_string($image) && strpos($image, 'data:image') === 0) {
            preg_match('/data:image\/(\w+);base64,/', $image, $matches);
            $imageType = $matches[1] ?? 'png';
            $imageData = substr($image, strpos($image, ',') + 1);
            $imageData = base64_decode($imageData);

            $filename = uniqid() . '.' . $imageType;
            $path = 'images/addons';
            $fullPath = storage_path('app/public/' . $path);

            Log::info('AddOn image upload attempt', [
                'filename' => $filename,
                'path' => $path,
                'fullPath' => $fullPath,
                'imageType' => $imageType
            ]);

            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
                Log::info('Created directory', ['path' => $fullPath]);
            }

            file_put_contents($fullPath . '/' . $filename, $imageData);
            Log::info('Image saved successfully', ['file' => $fullPath . '/' . $filename]);

            return $path . '/' . $filename;
        }

        Log::info('Image path returned as-is', ['image' => $image]);
        return $image;
    }

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
            if ($addOn->image && file_exists(storage_path('app/public/' . $addOn->image))) {
                unlink(storage_path('app/public/' . $addOn->image));
            }

            $locationIds[] = $addOn->location_id;
            $addOn->delete();
            $deletedCount++;
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Add-Ons Deleted',
            category: 'delete',
            description: "{$deletedCount} add-ons deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'addon',
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'deleted_count' => $deletedCount,
                'addon_ids' => $validated['ids'],
                'affected_locations' => array_unique($locationIds),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} add-ons deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'add_ons' => 'required|array|min:1',
            'add_ons.*.location_id' => 'nullable|exists:locations,id',
            'add_ons.*.locationId' => 'nullable|exists:locations,id',
            'add_ons.*.name' => 'required|string|max:255',
            'add_ons.*.price' => 'nullable|numeric|min:0',
            'add_ons.*.description' => 'nullable|string',
            'add_ons.*.image' => 'nullable|string|max:27262976',
            'add_ons.*.is_active' => 'nullable|boolean',
            'add_ons.*.isActive' => 'nullable|boolean',
            'add_ons.*.is_force_add_on' => 'nullable|boolean',
            'add_ons.*.isForceAddOn' => 'nullable|boolean',
            'add_ons.*.price_each_packages' => 'nullable|array',
            'add_ons.*.priceEachPackages' => 'nullable|array',
            'add_ons.*.min_quantity' => 'nullable|integer|min:1',
            'add_ons.*.minQuantity' => 'nullable|integer|min:1',
            'add_ons.*.max_quantity' => 'nullable|integer|min:1',
            'add_ons.*.maxQuantity' => 'nullable|integer|min:1',
        ]);

        $importedAddOns = [];
        $errors = [];

        foreach ($validated['add_ons'] as $index => $addOnData) {
            try {
                $mappedData = [
                    'name' => $addOnData['name'],
                    'price' => $addOnData['price'] ?? null,
                    'description' => $addOnData['description'] ?? null,
                    'location_id' => $addOnData['location_id'] ?? $addOnData['locationId'] ?? null,
                    'min_quantity' => $addOnData['min_quantity'] ?? $addOnData['minQuantity'] ?? 1,
                    'max_quantity' => $addOnData['max_quantity'] ?? $addOnData['maxQuantity'] ?? null,
                ];

                if (isset($addOnData['isActive'])) {
                    $mappedData['is_active'] = $addOnData['isActive'];
                } elseif (isset($addOnData['is_active'])) {
                    $mappedData['is_active'] = $addOnData['is_active'];
                } else {
                    $mappedData['is_active'] = true;
                }

                if (isset($addOnData['isForceAddOn'])) {
                    $mappedData['is_force_add_on'] = $addOnData['isForceAddOn'];
                } elseif (isset($addOnData['is_force_add_on'])) {
                    $mappedData['is_force_add_on'] = $addOnData['is_force_add_on'];
                } else {
                    $mappedData['is_force_add_on'] = false;
                }

                if (isset($addOnData['priceEachPackages'])) {
                    $mappedData['price_each_packages'] = $addOnData['priceEachPackages'];
                } elseif (isset($addOnData['price_each_packages'])) {
                    $mappedData['price_each_packages'] = $addOnData['price_each_packages'];
                } else {
                    $mappedData['price_each_packages'] = null;
                }

                if (isset($addOnData['image']) && !empty($addOnData['image'])) {
                    if (is_string($addOnData['image']) && strpos($addOnData['image'], 'data:image') === 0) {
                        $mappedData['image'] = $this->handleImageUpload($addOnData['image']);
                    } else {
                        $mappedData['image'] = $addOnData['image'];
                    }
                } else {
                    $mappedData['image'] = null;
                }

                if ($mappedData['max_quantity'] !== null && $mappedData['max_quantity'] < $mappedData['min_quantity']) {
                    throw new \Exception('Max quantity must be greater than or equal to min quantity');
                }

                $addOn = AddOn::create($mappedData);

                $addOn->load(['location', 'packages']);
                $importedAddOns[] = $addOn;

                Log::info('Add-on imported successfully', [
                    'index' => $index,
                    'name' => $addOn->name,
                    'id' => $addOn->id,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to import add-on', [
                    'index' => $index,
                    'name' => $addOnData['name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $errors[] = [
                    'index' => $index,
                    'name' => $addOnData['name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($importedAddOns) > 0) {
            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Bulk Add-Ons Imported',
                category: 'create',
                description: count($importedAddOns) . ' add-ons imported in bulk operation',
                userId: auth()->id(),
                locationId: $importedAddOns[0]->location_id ?? null,
                entityType: 'addon',
                metadata: [
                    'imported_by' => [
                        'user_id' => auth()->id(),
                        'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                        'email' => $currentUser?->email,
                    ],
                    'imported_at' => now()->toIso8601String(),
                    'import_details' => [
                        'imported_count' => count($importedAddOns),
                        'failed_count' => count($errors),
                        'addon_ids' => array_map(fn($a) => $a->id, $importedAddOns),
                    ],
                ]
            );
        }

        $response = [
            'success' => true,
            'message' => count($importedAddOns) . ' add-ons imported successfully',
            'data' => [
                'imported' => $importedAddOns,
                'imported_count' => count($importedAddOns),
                'failed_count' => count($errors),
            ],
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response);
    }
}
