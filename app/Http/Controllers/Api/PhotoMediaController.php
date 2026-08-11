<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Services\PhotoProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The single read path for photo media.
 *
 * Media is stored on a private disk, so the ONLY way to see a photo is a signed URL
 * minted by code that has already checked the caller's right to it — the customer page
 * after the contact gate, the staff library after location scoping, or the slideshow
 * after the passcode. The signature expires, so revoking access (QR expiry, 30-day
 * expiry, hide-from-slideshow, retention purge) becomes effective rather than advisory.
 */
class PhotoMediaController extends Controller
{
    public const VARIANTS = ['delivery', 'slideshow', 'thumb'];

    public const URL_TTL_MINUTES = 30;

    public static function signedUrl(Photo $photo, string $variant = 'delivery'): ?string
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            return null;
        }
        if (!$photo->pathForVariant($variant)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'photos.media',
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['photo' => $photo->id, 'variant' => $variant]
        );
    }

    public function show(Request $request, Photo $photo, string $variant): SymfonyResponse
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            abort(404);
        }

        $path = $photo->pathForVariant($variant);

        if ($photo->purged_at !== null || !$path) {
            abort(404);
        }

        $disk = Storage::disk(PhotoProcessingService::DISK);

        if (!$disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
