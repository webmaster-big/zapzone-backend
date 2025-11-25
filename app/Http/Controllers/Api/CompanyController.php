<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            'email' => 'required|email|unique:companies',
            'phone' => 'required|string|max:20',
            'address' => 'required|string',
            'total_locations' => 'integer|min:0',
            'total_employees' => 'integer|min:0',
        ]);

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
            'email' => 'sometimes|email|unique:companies,email,' . $company->id,
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'total_locations' => 'integer|min:0',
            'total_employees' => 'integer|min:0',
        ]);

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
        $company->delete();

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
}
