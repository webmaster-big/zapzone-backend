<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class PreviewImage
{
    public const DISK = 'public';

    public const DIRECTORY = 'images/previews';

    public const MAX_EDGE = 800;

    public const QUALITY = 80;

    public const MAX_SOURCE_PIXELS = 40000000;

    public const MAX_PER_REQUEST = 6;

    public const FAILURE_TTL_MINUTES = 360;

    private const VERSION = 1;

    private const QUEUE = 'preview-image.queue';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public static function primary(mixed $image): ?string
    {
        $usable = CatalogImage::forCatalog($image);

        if (is_array($usable)) {
            $usable = $usable[0] ?? null;
        }

        if (!is_string($usable) || trim($usable) === '') {
            return null;
        }

        $usable = trim($usable);

        return preg_match('#^https?://#i', $usable) || self::isSafeRelativePath($usable) ? $usable : null;
    }

    public static function forDisplay(mixed $image): array
    {
        $source = self::primary($image);

        if ($source === null) {
            return ['source' => null, 'image' => null, 'preview' => null, 'pending' => false];
        }

        if (preg_match('#^https?://#i', $source)) {
            return ['source' => $source, 'image' => $source, 'preview' => null, 'pending' => false];
        }

        $size = self::sizeOf($source);

        if ($size === null) {
            return ['source' => $source, 'image' => null, 'preview' => null, 'pending' => false];
        }

        $original = $size <= (int) config('media.preview_fallback_max_bytes', 1572864) ? $source : null;
        $preview = self::pathFor($source);

        if ($preview === null) {
            return ['source' => $source, 'image' => $original, 'preview' => null, 'pending' => false];
        }

        if (Storage::disk(self::DISK)->exists($preview)) {
            return ['source' => $source, 'image' => $original, 'preview' => $preview, 'pending' => false];
        }

        return ['source' => $source, 'image' => $original, 'preview' => null, 'pending' => !self::recentlyFailed($preview)];
    }

    public static function generateLater(array $sources): void
    {
        $sources = array_values(array_filter($sources, fn ($source) => is_string($source) && $source !== ''));

        if ($sources === []) {
            return;
        }

        $app = app();

        if (!$app->bound(self::QUEUE)) {
            $queue = new \ArrayObject();
            $app->instance(self::QUEUE, $queue);
            $app->terminating(function () use ($app, $queue) {
                $app->forgetInstance(self::QUEUE);
                $sources = array_values(array_unique($queue->getArrayCopy()));
                $queue->exchangeArray([]);
                self::runQueued($sources);
            });
        }

        $queue = $app->make(self::QUEUE);

        foreach ($sources as $source) {
            $queue[] = $source;
        }
    }

    private static function runQueued(array $sources): void
    {
        $batch = array_slice(array_values(array_filter($sources, fn (string $source) => self::needsPreview($source))), 0, self::MAX_PER_REQUEST);

        if ($batch === []) {
            return;
        }

        $runner = null;

        try {
            $runner = Cache::lock('preview-image:runner', 300);

            if (!$runner->get()) {
                $runner = null;

                return;
            }

            foreach ($batch as $source) {
                self::generate($source);
            }
        } catch (\Throwable $e) {
            Log::warning('Preview images skipped', ['error' => $e->getMessage()]);
        } finally {
            try {
                $runner?->release();
            } catch (\Throwable) {
            }
        }
    }

    private static function needsPreview(string $source): bool
    {
        $preview = self::pathFor($source);

        return $preview !== null && !Storage::disk(self::DISK)->exists($preview) && !self::recentlyFailed($preview);
    }

    private static function sizeOf(string $source): ?int
    {
        if (!self::isSafeRelativePath($source)) {
            return null;
        }

        $absolute = Storage::disk(self::DISK)->path($source);
        $size = is_file($absolute) ? @filesize($absolute) : false;

        return is_int($size) ? $size : null;
    }

    public static function pathFor(string $source): ?string
    {
        $relative = self::localPath($source);

        if ($relative === null) {
            return null;
        }

        $absolute = Storage::disk(self::DISK)->path($relative);
        $size = is_file($absolute) ? @filesize($absolute) : false;
        $modified = $size ? @filemtime($absolute) : false;

        if (!$size || !$modified) {
            return null;
        }

        return self::DIRECTORY . '/' . sha1(implode('|', [$relative, $size, $modified, self::MAX_EDGE, self::QUALITY, self::VERSION])) . '.jpg';
    }

    public static function forget(string $source): void
    {
        $preview = self::pathFor($source);

        if ($preview === null) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($preview);
        } catch (\Throwable) {
        }
    }

    public static function generate(string $source): ?string
    {
        $preview = self::pathFor($source);

        if ($preview === null) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($preview)) {
            return $preview;
        }

        $lock = null;
        $temporary = null;

        try {
            $lock = Cache::lock('preview-image:' . sha1($preview), 120);

            if (!$lock->get()) {
                $lock = null;

                return null;
            }

            if ($disk->exists($preview)) {
                return $preview;
            }

            $target = $disk->path($preview);
            $directory = dirname($target);

            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                self::markFailed($preview);

                return null;
            }

            $canvas = self::render($disk->path(self::localPath($source)));

            if ($canvas === false) {
                return null;
            }

            if (!$canvas instanceof \GdImage) {
                self::markFailed($preview);

                return null;
            }

            $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
            imageinterlace($canvas, true);
            $written = imagejpeg($canvas, $temporary, self::QUALITY);
            unset($canvas);

            if (!$written || !@rename($temporary, $target)) {
                self::markFailed($preview);

                return null;
            }

            $temporary = null;

            return $preview;
        } catch (\Throwable $e) {
            Log::warning('Preview image skipped', ['source' => $source, 'error' => $e->getMessage()]);
            self::markFailed($preview);

            return null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }

            try {
                $lock?->release();
            } catch (\Throwable) {
            }
        }
    }

    private static function isSafeRelativePath(string $source): bool
    {
        if ($source === '' || strlen($source) > 1024 || str_starts_with($source, '/') || preg_match('#[\\\\:\x00-\x1f]#', $source)) {
            return false;
        }

        $segments = explode('/', $source);

        return !in_array('..', $segments, true) && !in_array('.', $segments, true) && !in_array('', $segments, true);
    }

    private static function localPath(string $source): ?string
    {
        $source = trim($source);

        if (!self::isSafeRelativePath($source) || str_starts_with($source, self::DIRECTORY . '/')) {
            return null;
        }

        return in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), self::EXTENSIONS, true) ? $source : null;
    }

    private static function render(string $absolute): \GdImage|false|null
    {
        $info = @getimagesize($absolute);

        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }

        [$width, $height, $type] = $info;

        if (($width * $height) > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        if ($type === IMAGETYPE_JPEG && !function_exists('exif_read_data')) {
            return null;
        }

        $orientation = $type === IMAGETYPE_JPEG ? self::orientation($absolute) : 1;
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $copies = in_array($orientation, [3, 5, 6, 7, 8], true) ? 2 : 1;

        if (!self::hasMemoryFor(($width * $height * $copies) + (int) ceil($width * $scale) * (int) ceil($height * $scale))) {
            return false;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($absolute),
            default => false,
        };

        if (!$source instanceof \GdImage) {
            return null;
        }

        if ($orientation !== 1) {
            $source = self::orient($source, $orientation);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, 0, 0, $targetWidth - 1, $targetHeight - 1, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        unset($source);

        return $canvas;
    }

    private static function orientation(string $absolute): int
    {
        $exif = @exif_read_data($absolute);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private static function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (!$rotated instanceof \GdImage) {
            return $image;
        }

        unset($image);

        return $rotated;
    }

    private static function recentlyFailed(string $preview): bool
    {
        try {
            return (bool) Cache::get('preview-image-failed:' . sha1($preview), false);
        } catch (\Throwable) {
            return true;
        }
    }

    private static function markFailed(string $preview): void
    {
        try {
            Cache::put('preview-image-failed:' . sha1($preview), true, now()->addMinutes(self::FAILURE_TTL_MINUTES));
        } catch (\Throwable) {
        }
    }

    private static function hasMemoryFor(int $pixels): bool
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return true;
        }

        $multiplier = match (strtolower(substr($limit, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };

        return (memory_get_usage(true) + ($pixels * 5) + 8388608) < ((int) $limit * $multiplier);
    }
}
