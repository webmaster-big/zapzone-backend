<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        if (isset($validated['logo_path']) && Str::startsWith($validated['logo_path'], 'data:image/')) {
            $imageData = $validated['logo_path'];
            $imageName = 'company-logos/' . Str::uuid() . '.png';
            Storage::disk('public')->put($imageName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData)));
            $validated['logo_path'] = '/storage/' . $imageName;
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
            if (Str::startsWith($validated['logo_path'], 'data:image/')) {
                // Delete old logo if it exists
                if ($company->logo_path && Str::startsWith($company->logo_path, '/storage/company-logos/')) {
                    $oldLogoPath = str_replace('/storage/', '', $company->logo_path);
                    if (Storage::disk('public')->exists($oldLogoPath)) {
                        Storage::disk('public')->delete($oldLogoPath);
                    }
                }

                // Upload new logo
                $imageData = $validated['logo_path'];
                $imageName = 'company-logos/' . Str::uuid() . '.png';
                Storage::disk('public')->put($imageName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData)));
                $validated['logo_path'] = '/storage/' . $imageName;
            }
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
        if ($company->logo_path && Str::startsWith($company->logo_path, '/storage/company-logos/')) {
            $logoPath = str_replace('/storage/', '', $company->logo_path);
            if (Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
        }

        $company->delete();

        // Log company deletion
        ActivityLog::log(
            action: 'Company Deleted',
            category: 'delete',
            description: "Company '{$companyName}' was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'company',
            entityId: $companyId
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
        if ($company->logo_path && Str::startsWith($company->logo_path, '/storage/company-logos/')) {
            $oldLogoPath = str_replace('/storage/', '', $company->logo_path);
            if (Storage::disk('public')->exists($oldLogoPath)) {
                Storage::disk('public')->delete($oldLogoPath);
            }
        }

        // Upload new logo
        if (Str::startsWith($validated['logo_path'], 'data:image/')) {
            $imageData = $validated['logo_path'];
            $imageName = 'company-logos/' . Str::uuid() . '.png';
            Storage::disk('public')->put($imageName, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData)));
            $validated['logo_path'] = '/storage/' . $imageName;
        }

        $company->update(['logo_path' => $validated['logo_path']]);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company logo updated successfully',
            'data' => $company,
        ]);
    }
}
