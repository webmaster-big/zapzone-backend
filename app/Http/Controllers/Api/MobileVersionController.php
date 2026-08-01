<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\MobileAppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileVersionController extends Controller
{
    use ScopesByAuthUser;

    private const SUPPORTED_PLATFORMS = ['android', 'ios'];

    public function index(Request $request): JsonResponse
    {
        $platform = strtolower(trim($request->get('platform', 'android')));

        if (! in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            return response()->json([
                'message' => 'Unsupported platform.',
            ], 400);
        }

        $version = MobileAppVersion::where('platform', $platform)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        if (! $version) {
            return response()->json([
                'message' => "No mobile version configured for {$platform}.",
            ], 404);
        }

        return response()->json([
            'platform' => $version->platform,
            'latest_version' => $version->latest_version,
            'minimum_version' => $version->minimum_version,
            'force_update' => $version->force_update,
            'apk_url' => $version->apk_url,
            'update_message' => $version->update_message,
            'release_notes' => $version->release_notes ?? [],
        ]);
    }

    /**
     * Admin listing of every configured mobile app version, including inactive
     * ones. Used by the Web Admin to pick a version record to edit.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardAdmin($request)) {
            return $denied;
        }

        $query = MobileAppVersion::query();

        if ($request->filled('platform')) {
            $query->where('platform', strtolower(trim((string) $request->platform)));
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('platform')->latest('created_at')->get(),
        ]);
    }

    public function update(Request $request, MobileAppVersion $mobileAppVersion): JsonResponse
    {
        if ($denied = $this->guardAdmin($request)) {
            return $denied;
        }

        $validated = $request->validate([
            'latest_version' => 'required|string|max:20',
            'minimum_version' => 'required|string|max:20',
            'force_update' => 'sometimes|boolean',
            'apk_url' => 'nullable|url|max:2048',
            'update_message' => 'nullable|string|max:1000',
            'release_notes' => 'nullable|array',
            'release_notes.*' => 'string|max:255',
        ]);

        if (array_key_exists('force_update', $validated)) {
            $validated['force_update'] = $request->boolean('force_update');
        }

        // Re-index so the json column always stores a list, never an object.
        if (array_key_exists('release_notes', $validated)) {
            $validated['release_notes'] = array_values($validated['release_notes'] ?? []);
        }

        $mobileAppVersion->update($validated);
        $mobileAppVersion->refresh();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Mobile App Version Updated',
            category: 'update',
            description: "Mobile app version for {$mobileAppVersion->platform} updated to {$mobileAppVersion->latest_version} (minimum {$mobileAppVersion->minimum_version})",
            userId: auth()->id(),
            entityType: 'mobile_app_version',
            entityId: $mobileAppVersion->id,
            metadata: [
                'updated_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'updated_at' => now()->toIso8601String(),
                'updated_fields' => array_keys($validated),
                'version_details' => [
                    'mobile_app_version_id' => $mobileAppVersion->id,
                    'platform' => $mobileAppVersion->platform,
                    'latest_version' => $mobileAppVersion->latest_version,
                    'minimum_version' => $mobileAppVersion->minimum_version,
                    'force_update' => $mobileAppVersion->force_update,
                    'apk_url' => $mobileAppVersion->apk_url,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Mobile app version updated successfully',
            'data' => $mobileAppVersion,
        ]);
    }

    private function guardAdmin(Request $request): ?JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);

        if (!$authUser || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can manage mobile app versions.',
            ], 403);
        }

        return null;
    }
}
