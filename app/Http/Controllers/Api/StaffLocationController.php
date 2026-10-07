<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\User;
use App\Support\StaffLocationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class StaffLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'This area is for staff accounts only.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->state($user),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->canHoldSeveralLocations()) {
            return response()->json([
                'success' => false,
                'message' => 'Only location managers can switch between locations.',
            ], 403);
        }

        $validated = $request->validate([
            'location_id' => 'required|integer',
        ]);

        if (! User::tracksWorkLocations()) {
            return response()->json([
                'success' => false,
                'message' => 'Switching locations is not available until the database update has run.',
            ], 503);
        }

        $locationId = (int) $validated['location_id'];

        if (! $user->canWorkAt($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned to that location.',
            ], 403);
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'success' => false,
                'message' => 'This session cannot switch locations. Please sign in again.',
            ], 422);
        }

        $previousLocationId = $user->location_id !== null ? (int) $user->location_id : null;

        StaffLocationSession::remember($token, $user, $locationId);
        $user->enterLocation($locationId);

        if ($previousLocationId !== $locationId) {
            $location = Location::find($locationId);

            ActivityLog::log(
                'Location Switched',
                'login',
                ActivityLog::describeActor($user) . ' switched to ' . ($location?->name ?? "location #{$locationId}"),
                $user->getKey(),
                $locationId,
                'user',
                $user->getKey(),
                [
                    'from_location_id' => $previousLocationId,
                    'to_location_id' => $locationId,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Location switched.',
            'data' => $this->state($user),
        ]);
    }

    private function state(User $user): array
    {
        $payload = $user->locationAccessPayload();
        $activeId = $user->location_id !== null ? (int) $user->location_id : null;
        $active = collect($payload['work_locations'])->firstWhere('id', $activeId);

        return [
            'active_location_id' => $activeId,
            'active_location_name' => $active['name'] ?? $user->location?->name,
            'home_location_id' => $payload['home_location_id'],
            'can_switch' => $user->canHoldSeveralLocations() && count($payload['work_locations']) > 1,
            'locations' => $payload['work_locations'],
        ];
    }
}
