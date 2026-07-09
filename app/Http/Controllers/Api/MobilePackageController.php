<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MobilePackageResource;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Location;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dedicated, lightweight Packages API for the ZapZone Admin Mobile App
class MobilePackageController extends Controller
{
    use ScopesByAuthUser;

    private const LIGHTWEIGHT_COLUMNS = [
        'id',
        'location_id',
        'name',
        'description',
        'category',
        'package_type',
        'price',
        'price_per_additional',
        'price_per_additional_30min',
        'price_per_additional_1hr',
        'duration',
        'duration_unit',
        'min_participants',
        'max_participants',
        'min_booking_notice_hours',
        'booking_window_days',
        'has_guest_of_honor',
        'partial_payment_percentage',
        'partial_payment_fixed',
        'display_order',
        'is_active',
        'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Package::query()
            ->select(self::LIGHTWEIGHT_COLUMNS)
            ->with(['location:id,name']);

        $this->applyMobileScope($query, $request);

        if ($request->filled('location_id')) {
            $query->byLocation($request->location_id);
        }

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('package_type')) {
            $query->byPackageType($request->package_type);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $packages = $query->orderBy('display_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'packages' => MobilePackageResource::collection($packages),
            ],
        ]);
    }

    // Scope packages to what the authenticated user is allowed to see.
    private function applyMobileScope($query, Request $request): void
    {
        $user = $this->resolveAuthUser($request);

        if (!$user) {
            return;
        }

        if ($user->role === 'company_admin') {
            if ($user->company_id) {
                $companyLocationIds = Location::where('company_id', $user->company_id)
                    ->pluck('id')
                    ->all();

                $query->whereIn('location_id', $companyLocationIds);
            }

            return;
        }

        // location_manager, attendant, and any other location-bound role
        if ($user->location_id) {
            $query->where('location_id', $user->location_id);
        }
    }
}
