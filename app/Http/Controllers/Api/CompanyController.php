<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DayOffChangeLog;
use App\Models\User;
use App\Support\CacheGroups;
use App\Support\DataUriImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    use ScopesByAuthUser;

    public function index(): JsonResponse
    {
        $query = Company::query();

        $authUser = auth()->user();
        if ($authUser && $authUser->company_id) {
            $query->where('id', $authUser->company_id);
        }

        $companies = $query->orderBy('company_name')->get();

        return response()->json([
            'success' => true,
            'data' => $companies,
        ]);
    }

    public function storefront(): JsonResponse
    {
        $brand = CacheGroups::remember(
            [CacheGroups::BRAND],
            'companies:storefront-brand',
            CacheGroups::TTL_CATALOG,
            function () {
                $company = Company::query()
                    ->orderBy('id')
                    ->first(['id', 'company_name', 'logo_path']);

                return $company ? [
                    'name' => $company->company_name,
                    'logo_path' => $company->logo_path,
                ] : null;
            }
        );

        return response()->json([
            'success' => true,
            'data' => $brand,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255|unique:companies',
            'logo_path' => 'nullable|string|max:28311552',
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

        if (isset($validated['logo_path'])) {
            try {
                $validated['logo_path'] = $this->handleImageUpload($validated['logo_path']);
            } catch (\Throwable $e) {
                Log::error('Company logo upload failed', ['error' => $e->getMessage()]);

                return response()->json([
                    'success' => false,
                    'message' => 'Logo upload failed: ' . $e->getMessage(),
                ], 422);
            }
        }

        $company = Company::create($validated);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company created successfully',
            'data' => $company,
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        if ($scopeError = $this->guardCompanyAccess(null, $company->id)) {
            return $scopeError;
        }
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'data' => $company,
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        if ($scopeError = $this->guardCompanyAccess($request, $company->id)) {
            return $scopeError;
        }
        $validated = $request->validate([
            'company_name' => 'sometimes|string|max:255|unique:companies,company_name,' . $company->id,
            'logo_path' => 'sometimes|nullable|string|max:28311552',
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

        if (isset($validated['logo_path'])) {
            try {
                $newLogoPath = $this->handleImageUpload($validated['logo_path']);
            } catch (\Throwable $e) {
                Log::error('Company logo upload failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

                return response()->json([
                    'success' => false,
                    'message' => 'Logo upload failed: ' . $e->getMessage(),
                ], 422);
            }

            if ($company->logo_path && $company->logo_path !== $newLogoPath && file_exists(storage_path('app/public/' . $company->logo_path))) {
                unlink(storage_path('app/public/' . $company->logo_path));
            }

            $validated['logo_path'] = $newLogoPath;
        }

        $company->update($validated);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company updated successfully',
            'data' => $company,
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $actor = request()->user();

        if (! $actor instanceof User
            || ! in_array(\App\Http\Middleware\EnsureStaff::effectiveRole($actor), ['company_admin', 'admin'], true)
            || (int) $actor->company_id !== (int) $company->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only a company admin of this company can delete it.',
            ], 403);
        }

        $companyName = $company->company_name;
        $companyId = $company->id;

        if ($company->logo_path && file_exists(storage_path('app/public/' . $company->logo_path))) {
            unlink(storage_path('app/public/' . $company->logo_path));
        }

        $currentUser = auth()->user();

        DB::transaction(function () use ($company, $companyName, $currentUser) {
            DayOffChangeLog::recordLocationRemoval(
                $company->locations()->pluck('id')->map(fn ($id) => (int) $id)->all(),
                "the company {$companyName} was deleted",
                $currentUser instanceof User ? $currentUser : null,
                request()->ip(),
                ['location_deleted' => true, 'company_deleted' => true],
            );

            $company->delete();
        });

        ActivityLog::create([
            'user_id' => User::whereKey(auth()->id())->exists() ? auth()->id() : null,
            'location_id' => null,
            'action' => 'Company Deleted',
            'category' => 'delete',
            'entity_type' => 'company',
            'entity_id' => $companyId,
            'description' => "Company '{$companyName}' was deleted",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => [
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
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Company deleted successfully',
        ]);
    }

    public function statistics(Company $company): JsonResponse
    {
        $stats = [
            'total_locations' => $company->locations()->count(),
            'total_users' => $company->users()->count(),
            'active_users' => $company->users()->where('status', 'active')->count(),
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

    public function updateLogo(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'logo_path' => 'required|string|max:28311552',
        ]);

        try {
            $newLogoPath = $this->handleImageUpload($validated['logo_path']);
        } catch (\Throwable $e) {
            Log::error('Company logo upload failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Logo upload failed: ' . $e->getMessage(),
            ], 422);
        }

        if ($company->logo_path && $company->logo_path !== $newLogoPath && file_exists(storage_path('app/public/' . $company->logo_path))) {
            unlink(storage_path('app/public/' . $company->logo_path));
        }

        $company->update(['logo_path' => $newLogoPath]);
        $company->load(['locations', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Company logo updated successfully',
            'data' => $company,
        ]);
    }

    private function handleImageUpload($image): string
    {
        if (DataUriImage::isDataUri($image)) {
            return DataUriImage::store($image, 'images/company-logos');
        }

        if (!is_string($image) || strlen($image) > (int) config('media.max_path_length', 2048)) {
            throw new \InvalidArgumentException('Logo must be a base64 image data URI or a stored file path');
        }

        return $image;
    }
}
