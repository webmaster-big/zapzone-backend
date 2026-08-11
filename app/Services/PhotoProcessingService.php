<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoOverlay;
use App\Support\OperatingDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoProcessingService
{
    /**
     * Photo media lives on a PRIVATE disk. It must never be reachable at a guessable
     * URL, because the 12-hour QR window, the 30-day page expiry, the kiosk contact
     * gate and "remove from slideshow" are all enforced in the application layer.
     */
    public const DISK = 'photos';

    /** Venue branding, safe to serve publicly. */
    public const OVERLAY_DISK = 'public';

    public const DELIVERY_MAX_EDGE = 1600;
    public const SLIDESHOW_MAX_EDGE = 1920;
    public const THUMBNAIL_MAX_EDGE = 420;
    public const DELIVERY_QUALITY = 88;
    public const SLIDESHOW_QUALITY = 84;
    public const THUMBNAIL_QUALITY = 72;
    public const MAX_SOURCE_PIXELS = 60000000;

    protected const FONT_CANDIDATES = [
        'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ];

    public function storeSource(string $rawImage, Photo $photo): string
    {
        $path = $this->pathFor($photo, 'original', 'jpg');
        Storage::disk(self::DISK)->put($path, $rawImage);

        return $path;
    }

    public function process(Photo $photo): Photo
    {
        $photo->loadMissing('location', 'session');
        $location = $photo->location;

        $photo->update(['processing_status' => Photo::PROCESSING_RUNNING]);

        try {
            $absolute = Storage::disk(self::DISK)->path($photo->original_path);

            if (!is_file($absolute)) {
                throw new \RuntimeException('Source image is missing from storage.');
            }

            $source = $this->loadImage($absolute);
            $source = $this->correctOrientation($source, $absolute);

            $setting = LocationPhotoSetting::forLocation($location);
            $overlay = $this->resolveOverlay($location, $photo->captured_at ?: now());

            $delivery = $this->render($source, self::DELIVERY_MAX_EDGE, $overlay, $setting, $photo, $location);
            $deliveryPath = $this->pathFor($photo, 'delivery', 'jpg');
            $this->writeJpeg($delivery, $deliveryPath, self::DELIVERY_QUALITY);

            $slideshow = $this->render($source, self::SLIDESHOW_MAX_EDGE, $overlay, $setting, $photo, $location);
            $slideshowPath = $this->pathFor($photo, 'slideshow', 'jpg');
            $this->writeJpeg($slideshow, $slideshowPath, self::SLIDESHOW_QUALITY);

            $thumbnail = $this->resize($delivery, self::THUMBNAIL_MAX_EDGE);
            $thumbnailPath = $this->pathFor($photo, 'thumb', 'jpg');
            $this->writeJpeg($thumbnail, $thumbnailPath, self::THUMBNAIL_QUALITY);

            $width = imagesx($delivery);
            $height = imagesy($delivery);

            imagedestroy($source);
            imagedestroy($delivery);
            imagedestroy($slideshow);
            imagedestroy($thumbnail);

            $photo->update([
                'delivery_path' => $deliveryPath,
                'slideshow_path' => $slideshowPath,
                'thumbnail_path' => $thumbnailPath,
                'photo_overlay_id' => $overlay?->id,
                'width' => $width,
                'height' => $height,
                'bytes' => Storage::disk(self::DISK)->size($deliveryPath),
                'processing_status' => Photo::PROCESSING_READY,
                'processing_error' => null,
            ]);

            if (!$overlay) {
                Log::info('Photo processed with the date layer only; no overlay was active.', [
                    'photo_id' => $photo->id,
                    'location_id' => $location?->id,
                ]);
            }
        } catch (\Throwable $e) {
            $photo->update([
                'processing_status' => Photo::PROCESSING_FAILED,
                'processing_error' => $e->getMessage(),
            ]);

            Log::error('Photo processing failed', [
                'photo_id' => $photo->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $photo->fresh();
    }

    public function resolveOverlay(?Location $location, ?Carbon $at = null): ?PhotoOverlay
    {
        if (!$location) {
            return null;
        }

        $at = $at ?: now();
        $candidates = PhotoOverlay::candidatesFor($location->id, $at)->get();

        if ($candidates->count() > 1) {
            Log::warning('Overlapping photo overlays are scheduled; highest priority wins.', [
                'location_id' => $location->id,
                'overlay_ids' => $candidates->pluck('id')->all(),
            ]);
        }

        return $candidates->first();
    }

    public function overlayConflicts(int $locationId): array
    {
        $overlays = PhotoOverlay::where('location_id', $locationId)
            ->where('is_enabled', true)
            ->orderBy('starts_at')
            ->get();

        $conflicts = [];

        foreach ($overlays as $i => $a) {
            foreach ($overlays->slice($i + 1) as $b) {
                if ($this->windowsOverlap($a, $b)) {
                    $conflicts[] = [
                        'overlay_id' => $a->id,
                        'overlay_name' => $a->name,
                        'conflicts_with_id' => $b->id,
                        'conflicts_with_name' => $b->name,
                        'winner_id' => $a->priority >= $b->priority ? $a->id : $b->id,
                    ];
                }
            }
        }

        return $conflicts;
    }

    protected function windowsOverlap(PhotoOverlay $a, PhotoOverlay $b): bool
    {
        $aStart = $a->starts_at?->timestamp ?? PHP_INT_MIN;
        $aEnd = $a->ends_at?->timestamp ?? PHP_INT_MAX;
        $bStart = $b->starts_at?->timestamp ?? PHP_INT_MIN;
        $bEnd = $b->ends_at?->timestamp ?? PHP_INT_MAX;

        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    protected function render(
        \GdImage $source,
        int $maxEdge,
        ?PhotoOverlay $overlay,
        LocationPhotoSetting $setting,
        Photo $photo,
        ?Location $location
    ): \GdImage {
        $canvas = $this->resize($source, $maxEdge);

        if ($overlay) {
            $this->applyOverlay($canvas, $overlay);
        }

        $this->applyDateLayer($canvas, $setting, $photo, $location);

        return $canvas;
    }

    protected function applyOverlay(\GdImage $canvas, PhotoOverlay $overlay): void
    {
        // Overlays are venue branding, not customer media: they are uploaded to and read
        // from the public disk. Only the photos themselves live on the private disk.
        $path = Storage::disk(self::OVERLAY_DISK)->path($overlay->image_path);

        if (!is_file($path)) {
            Log::warning('Overlay image is missing from storage; continuing with the date layer only.', [
                'overlay_id' => $overlay->id,
                'path' => $overlay->image_path,
            ]);

            return;
        }

        $layer = $this->loadImage($path);
        $canvasW = imagesx($canvas);
        $canvasH = imagesy($canvas);
        $layerW = imagesx($layer);
        $layerH = imagesy($layer);

        $scale = max($canvasW / $layerW, $canvasH / $layerH);
        $targetW = (int) round($layerW * $scale);
        $targetH = (int) round($layerH * $scale);
        $dstX = (int) round(($canvasW - $targetW) / 2);
        $dstY = (int) round(($canvasH - $targetH) / 2);

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $layer, $dstX, $dstY, 0, 0, $targetW, $targetH, $layerW, $layerH);
        imagedestroy($layer);
    }

    protected function applyDateLayer(
        \GdImage $canvas,
        LocationPhotoSetting $setting,
        Photo $photo,
        ?Location $location
    ): void {
        $text = $this->captureDateText($setting, $photo, $location);
        $canvasW = imagesx($canvas);
        $canvasH = imagesy($canvas);

        $fontSize = (int) round($setting->date_font_size * ($canvasW / self::DELIVERY_MAX_EDGE));
        $fontSize = max(14, min(96, $fontSize));
        $margin = (int) round($setting->date_margin * ($canvasW / self::DELIVERY_MAX_EDGE));
        $margin = max(10, $margin);

        $font = $this->resolveFontPath();
        [$textW, $textH] = $font
            ? $this->measureTtf($font, $fontSize, $text)
            : [(int) round(imagefontwidth(5) * strlen($text) * ($fontSize / 15)), $fontSize];

        $padX = (int) round($fontSize * 0.55);
        $padY = (int) round($fontSize * 0.38);
        $boxW = $textW + ($padX * 2);
        $boxH = $textH + ($padY * 2);

        $position = in_array($setting->date_position, LocationPhotoSetting::DATE_POSITIONS, true)
            ? $setting->date_position
            : 'bottom_right';

        $boxX = str_contains($position, 'left') ? $margin : $canvasW - $boxW - $margin;
        $boxY = str_contains($position, 'top') ? $margin : $canvasH - $boxH - $margin;
        $boxX = max(0, $boxX);
        $boxY = max(0, $boxY);

        imagealphablending($canvas, true);

        if ($setting->date_background === 'solid') {
            $panel = imagecolorallocatealpha($canvas, 0, 0, 0, 62);
            imagefilledrectangle($canvas, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $panel);
        }

        $textX = $boxX + $padX;
        $textY = $boxY + $padY;
        $white = imagecolorallocate($canvas, 255, 255, 255);

        if ($setting->date_background === 'shadow') {
            $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 55);
            $this->drawText($canvas, $font, $fontSize, $textX + 2, $textY + 2, $shadow, $text, $textH);
        }

        $this->drawText($canvas, $font, $fontSize, $textX, $textY, $white, $text, $textH);
    }

    protected function captureDateText(LocationPhotoSetting $setting, Photo $photo, ?Location $location): string
    {
        $tz = OperatingDay::timezoneFor($location);
        $captured = $photo->captured_at ? $photo->captured_at->copy() : now();
        $format = in_array($setting->date_format, LocationPhotoSetting::DATE_FORMATS, true)
            ? $setting->date_format
            : 'M j, Y';

        return $captured->setTimezone($tz)->format($format);
    }

    protected function drawText(
        \GdImage $canvas,
        ?string $font,
        int $fontSize,
        int $x,
        int $y,
        int $color,
        string $text,
        int $textH
    ): void {
        if ($font) {
            imagettftext($canvas, $fontSize, 0, $x, $y + $textH, $color, $font, $text);

            return;
        }

        $scratchW = max(1, imagefontwidth(5) * strlen($text));
        $scratchH = imagefontheight(5);
        $scratch = imagecreatetruecolor($scratchW, $scratchH);
        $transparent = imagecolorallocatealpha($scratch, 0, 0, 0, 127);
        imagefill($scratch, 0, 0, $transparent);
        imagesavealpha($scratch, true);
        $ink = imagecolorallocate($scratch, 255, 255, 255);
        imagestring($scratch, 5, 0, 0, $text, $ink);

        $targetH = max(1, $textH);
        $targetW = max(1, (int) round($scratchW * ($targetH / $scratchH)));
        imagecopyresampled($canvas, $scratch, $x, $y, 0, 0, $targetW, $targetH, $scratchW, $scratchH);
        imagedestroy($scratch);
    }

    protected function measureTtf(string $font, int $size, string $text): array
    {
        $box = imagettfbbox($size, 0, $font, $text);

        if (!$box) {
            return [strlen($text) * $size, $size];
        }

        return [
            (int) abs($box[2] - $box[0]),
            (int) abs($box[7] - $box[1]),
        ];
    }

    public function resolveFontPath(): ?string
    {
        if (!function_exists('imagettftext')) {
            return null;
        }

        $configured = config('photos.font_path');
        $candidates = array_merge(
            $configured ? [$configured] : [],
            [resource_path('fonts/DejaVuSans-Bold.ttf')],
            array_map(
                fn ($candidate) => str_starts_with($candidate, '/') ? $candidate : base_path($candidate),
                self::FONT_CANDIDATES
            )
        );

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resize(\GdImage $source, int $maxEdge): \GdImage
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $scale = min(1.0, $maxEdge / max($srcW, $srcH));
        $dstW = max(1, (int) round($srcW * $scale));
        $dstH = max(1, (int) round($srcH * $scale));

        $canvas = imagecreatetruecolor($dstW, $dstH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, false);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    protected function loadImage(string $absolutePath): \GdImage
    {
        $info = @getimagesize($absolutePath);

        if (!$info) {
            throw new \RuntimeException('The uploaded file is not a readable image.');
        }

        [$width, $height, $type] = $info;

        if (($width * $height) > self::MAX_SOURCE_PIXELS) {
            throw new \RuntimeException('The image resolution is too large to process.');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolutePath),
            IMAGETYPE_PNG => @imagecreatefrompng($absolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($absolutePath),
            default => false,
        };

        if (!$image) {
            throw new \RuntimeException('Only JPEG, PNG, WebP and GIF images are supported.');
        }

        imagealphablending($image, true);

        return $image;
    }

    protected function correctOrientation(\GdImage $image, string $absolutePath): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $info = @getimagesize($absolutePath);
        if (!$info || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return $image;
        }

        $exif = @exif_read_data($absolutePath);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    protected function writeJpeg(\GdImage $image, string $path, int $quality): void
    {
        $absolute = Storage::disk(self::DISK)->path($path);
        $dir = dirname($absolute);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        imagejpeg($image, $absolute, $quality);
    }

    /**
     * Paths carry a random per-photo segment as well as living on a private disk, so a
     * leaked path never lets anyone walk the archive by incrementing an id.
     */
    protected function pathFor(Photo $photo, string $variant, string $extension): string
    {
        $day = $photo->operating_day
            ? $photo->operating_day->format('Y-m-d')
            : now()->toDateString();

        return sprintf(
            'photos/%d/%s/%d/%d-%s-%s.%s',
            $photo->location_id,
            $day,
            $photo->photo_session_id,
            $photo->id,
            $variant,
            $this->mediaSalt($photo),
            $extension
        );
    }

    protected function mediaSalt(Photo $photo): string
    {
        return substr(hash_hmac('sha256', 'photo-media:' . $photo->id, (string) config('app.key')), 0, 20);
    }
}
