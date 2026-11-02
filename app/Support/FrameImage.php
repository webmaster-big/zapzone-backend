<?php

namespace App\Support;

use App\Services\PhotoProcessingService;

class FrameImage
{
    public const LANDSCAPE = 'landscape';
    public const PORTRAIT = 'portrait';
    public const SQUARE = 'square';

    public const MIN_SEE_THROUGH_SHARE = 0.1;

    public const MAX_FRAME_PIXELS = 12500000;

    protected const SAMPLES_PER_SIDE = 64;
    protected const SEE_THROUGH_ALPHA = 64;

    public static function size(?string $absolutePath): ?array
    {
        if (!$absolutePath || !is_file($absolutePath)) {
            return null;
        }

        $info = @getimagesize($absolutePath);

        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        [$width, $height] = $info;

        if (($info[2] ?? null) === IMAGETYPE_JPEG && in_array(self::jpegOrientation($absolutePath), [5, 6, 7, 8], true)) {
            [$width, $height] = [$height, $width];
        }

        return [(int) $width, (int) $height];
    }

    public static function shape(int $width, int $height): string
    {
        if ($width === $height) {
            return self::SQUARE;
        }

        return $width > $height ? self::LANDSCAPE : self::PORTRAIT;
    }

    public static function otherShape(?string $shape): ?string
    {
        return match ($shape) {
            self::LANDSCAPE => self::PORTRAIT,
            self::PORTRAIT => self::LANDSCAPE,
            default => null,
        };
    }

    public static function seeThroughShare(string $absolutePath): ?float
    {
        $info = @getimagesize($absolutePath);

        if (!$info) {
            return null;
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || ($width * $height) > PhotoProcessingService::MAX_SOURCE_PIXELS) {
            return null;
        }

        if ($type === IMAGETYPE_JPEG) {
            return 0.0;
        }

        $image = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($absolutePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($absolutePath),
            default => false,
        };

        if (!$image) {
            return null;
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $clear = 0;
        $total = 0;

        for ($row = 0; $row < self::SAMPLES_PER_SIDE; $row++) {
            $y = min($height - 1, (int) floor(($row + 0.5) * $height / self::SAMPLES_PER_SIDE));

            for ($column = 0; $column < self::SAMPLES_PER_SIDE; $column++) {
                $x = min($width - 1, (int) floor(($column + 0.5) * $width / self::SAMPLES_PER_SIDE));
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                $total++;

                if ($alpha >= self::SEE_THROUGH_ALPHA) {
                    $clear++;
                }
            }
        }

        imagedestroy($image);

        return $total > 0 ? $clear / $total : 0.0;
    }

    public static function hasMemoryFor(int $pixels): bool
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

    protected static function jpegOrientation(string $absolutePath): int
    {
        if (!function_exists('exif_read_data')) {
            return 0;
        }

        $exif = @exif_read_data($absolutePath);

        return (int) ($exif['Orientation'] ?? 0);
    }
}
