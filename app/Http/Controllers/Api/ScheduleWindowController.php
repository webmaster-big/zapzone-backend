<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Services\Schedule\ScheduleDayWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleWindowController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(private ScheduleDayWindow $windows) {}

    public function dayWindow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        $authUser = $this->resolveAuthUser($request);
        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;

        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $locationId = (int) $authUser->location_id;
        }

        if ($locationId !== null) {
            $location = $this->scopedLocation($request, $locationId);
            if ($location instanceof JsonResponse) {
                return $location;
            }
        }

        $allowedLocationIds = $locationId === null && $authUser?->company_id
            ? \App\Models\Location::where('company_id', $authUser->company_id)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : null;

        return response()->json([
            'success' => true,
            'data' => $this->windows->forDate($locationId, $validated['date'], $allowedLocationIds),
        ]);
    }
}
