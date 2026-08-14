<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MobilePushDevice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobilePushDeviceController extends Controller
{
   
    private const ALLOWED_ROLES = ['company_admin', 'admin', 'location_manager'];

    public function store(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        if (!$user) {
            return $this->denied();
        }

        if ($request->has('platform')) {
            $request->merge(['platform' => strtolower(trim((string) $request->input('platform')))]);
        }

        $validated = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:' . MobilePushDevice::TOKEN_PATTERN],
            'platform' => ['required', Rule::in(MobilePushDevice::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        $token = $validated['expo_push_token'];

        $attributes = [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'platform' => $validated['platform'],
            'is_active' => true,
            'last_used_at' => now(),
        ];

        foreach (['device_name', 'app_version'] as $optional) {
            if (array_key_exists($optional, $validated)) {
                $attributes[$optional] = $validated[$optional];
            }
        }

        $device = MobilePushDevice::forToken($token)->first();
        $previousOwnerId = $device ? (int) $device->user_id : null;

        if ($device) {
            $device->fill($attributes)->save();
        } else {
            try {
                $device = MobilePushDevice::create($attributes + ['expo_push_token' => $token]);
            } catch (QueryException $e) {
              
                $device = MobilePushDevice::forToken($token)->firstOrFail();
                $previousOwnerId = (int) $device->user_id;
                $device->fill($attributes)->save();
            }
        }

        $created = $device->wasRecentlyCreated;

        if ($previousOwnerId !== null && $previousOwnerId !== (int) $user->id) {
            ActivityLog::log(
                action: 'Push Device Reassigned',
                category: 'update',
                description: "Push device reassigned from user #{$previousOwnerId} to {$user->first_name} {$user->last_name}",
                userId: $user->id,
                locationId: $user->location_id,
                entityType: 'mobile_push_device',
                entityId: $device->id,
                metadata: [
                    'mobile_push_device_id' => $device->id,
                    'previous_user_id' => $previousOwnerId,
                    'new_user_id' => $user->id,
                    'platform' => $device->platform,
                    'reassigned_at' => now()->toIso8601String(),
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => $created
                ? 'Device registered for push notifications.'
                : 'Device registration updated.',
            'data' => $device->fresh(),
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->staffUser($request);
        if (!$user) {
            return $this->denied();
        }

        $validated = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
        ]);

        $device = MobilePushDevice::forUser($user->id)
            ->forToken($validated['expo_push_token'])
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'That device is not registered to your account.',
            ], 404);
        }

        $device->deactivate();

        return response()->json([
            'success' => true,
            'message' => 'Device deactivated.',
            'data' => $device->fresh(),
        ]);
    }

  
    private function staffUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && in_array((string) $user->role, self::ALLOWED_ROLES, true)
            ? $user
            : null;
    }

    private function denied(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only admins and location managers can manage push devices.',
        ], 403);
    }
}
