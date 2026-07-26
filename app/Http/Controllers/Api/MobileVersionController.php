<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileAppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileVersionController extends Controller
{
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
}
