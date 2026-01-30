<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{

    public function index(): JsonResponse
    {
        $companies = Company::orderBy('company_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $companies,
        ]);
    }

    /**
     * Store a newly created company.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255|unique:companies',
            'logo_path' => 'nullable|string|max:27262976', // 20MB in base64 is ~27MB
            'email' => 'required|email|unique:companies',
            'website' => 'nullable|url|max:255',
            'phone' => 'required|string|max:20',
            'tax_id' => 'nullable|string|max:100',
            'registration_number' => 'nullable|string|max:100',
            'address' => 'required|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|in:1-10,11-50,51-200,201-500,501-1000,1000+',
            'founded_date' => 'nullable|date',
            'description' => 'nullable|string',
            'total_locations' => 'integer|min:0',
            'total_employees' => 'integer|min:0',
            'status' => 'nullable|in:active,inactive,suspended',
        ]);

        // Handle logo upload if base64 image provided
        if (isset($validated['logo_path'])) {
            $validated['logo_path'] = $this->handleImageUpload($validated['logo_path']);
        }

        $company = Company::create($validated);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company created successfully',
            'data' => $company,
        ], 201);
    }

    /**
     * Display the specified company.
     */
    public function show(Company $company): JsonResponse
    {
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'data' => $company,
        ]);
    }

    /**
     * Update the specified company.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'sometimes|string|max:255|unique:companies,company_name,' . $company->id,
            'logo_path' => 'sometimes|nullable|max:27262976', // 20MB in base64 is ~27MB
            'email' => 'sometimes|email|unique:companies,email,' . $company->id,
            'website' => 'sometimes|nullable|url|max:255',
            'phone' => 'sometimes|string|max:20',
            'tax_id' => 'sometimes|nullable|string|max:100',
            'registration_number' => 'sometimes|nullable|string|max:100',
            'address' => 'sometimes|string',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:100',
            'zip_code' => 'sometimes|nullable|string|max:20',
            'industry' => 'sometimes|nullable|string|max:100',
            'company_size' => 'sometimes|nullable|in:1-10,11-50,51-200,201-500,501-1000,1000+',
            'founded_date' => 'sometimes|nullable|date',
            'description' => 'sometimes|nullable|string',
            'total_locations' => 'sometimes|integer|min:0',
            'total_employees' => 'sometimes|integer|min:0',
            'status' => 'sometimes|in:active,inactive,suspended',
        ]);

        // Handle logo upload if base64 image provided
        if (isset($validated['logo_path'])) {
            // Delete old logo if it exists
            if ($company->logo_path && file_exists(storage_path('app/public/' . $company->logo_path))) {
                unlink(storage_path('app/public/' . $company->logo_path));
            }

            // Upload new logo
            $validated['logo_path'] = $this->handleImageUpload($validated['logo_path']);
        }

        $company->update($validated);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company updated successfully',
            'data' => $company,
        ]);
    }

    /**
     * Remove the specified company.
     */
    public function destroy(Company $company): JsonResponse
    {
        $companyName = $company->company_name;
        $companyId = $company->id;

        // Delete company logo if it exists
        if ($company->logo_path && file_exists(storage_path('app/public/' . $company->logo_path))) {
            unlink(storage_path('app/public/' . $company->logo_path));
        }

        $company->delete();

        // Log company deletion
        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Company Deleted',
            category: 'delete',
            description: "Company '{$companyName}' was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'company',
            entityId: $companyId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'company_details' => [
                    'company_id' => $companyId,
                    'name' => $companyName,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Company deleted successfully',
        ]);
    }

    /**
     * Get company statistics.
     */
    public function statistics(Company $company): JsonResponse
    {
        $stats = [
            'total_locations' => $company->locations()->count(),
            'total_users' => $company->users()->count(),
            'active_users' => $company->users()->where('is_active', true)->count(),
            'recent_bookings' => $company->locations()->withCount(['packages' => function ($query) {
                $query->whereHas('bookings', function ($q) {
                    $q->where('created_at', '>=', now()->subDays(30));
                });
            }])->get()->sum('packages_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Update company logo.
     */
    public function updateLogo(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'logo_path' => 'required|string|max:27262976', // 20MB in base64 is ~27MB
        ]);

        // Delete old logo if it exists
        if ($company->logo_path && file_exists(storage_path('app/public/' . $company->logo_path))) {
            unlink(storage_path('app/public/' . $company->logo_path));
        }

        // Upload new logo
        $newLogoPath = $this->handleImageUpload($validated['logo_path']);

        $company->update(['logo_path' => $newLogoPath]);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company logo updated successfully',
            'data' => $company,
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
            $path = 'images/company-logos';
            $fullPath = storage_path('app/public/' . $path);

            Log::info('Company logo upload attempt', [
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
            Log::info('Company logo saved successfully', ['file' => $fullPath . '/' . $filename]);

            // Return the relative path (for storage URL)
            return $path . '/' . $filename;
        }

        // If it's already a file path or URL, return as is
        Log::info('Company logo path returned as-is', ['image' => $image]);
        return $image;
    }
}
