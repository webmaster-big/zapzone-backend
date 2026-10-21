<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class RegistrationHeaderImage
{
    public const DIRECTORY = 'registration-events';

    private const MAX_WIDTH = 1600;

    private const MAX_SOURCE_PIXELS = 40000000;

    private const JPEG_QUALITY = 82;

    public static function process(UploadedFile $file): ?array
    {
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'imagejpeg', 'getimagesizefromstring'] as $function) {
            if (!function_exists($function)) {
                return null;
            }
        }

        $binary = $file->get();
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return null;
        }

        [$width, $height] = $info;
        if (($width * $height) > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $targetPixels = min($width, self::MAX_WIDTH) * max(1, (int) round($height * min($width, self::MAX_WIDTH) / $width));
        if (!self::hasMemoryFor(($width * $height) + $targetPixels)) {
            return null;
        }

        $obLevel = ob_get_level();

        try {
            $source = @imagecreatefromstring($binary);
            if (!$source instanceof \GdImage) {
                return null;
            }

            if (($info[2] ?? null) === IMAGETYPE_JPEG) {
                $source = self::applyJpegOrientation($source, $binary);
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $targetWidth = min($sourceWidth, self::MAX_WIDTH);
            $targetHeight = max(1, (int) round($sourceHeight * $targetWidth / $sourceWidth));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            imagedestroy($source);

            ob_start();
            $written = imagejpeg($canvas, null, self::JPEG_QUALITY);
            $output = ob_get_clean();
            imagedestroy($canvas);

            if ($written !== true || !is_string($output) || $output === '') {
                return null;
            }

            return ['binary' => $output, 'width' => $targetWidth, 'height' => $targetHeight];
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            Log::warning('Registration header image could not be processed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public static function store(string $binary): string
    {
        $path = self::DIRECTORY . '/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if (!is_string($path) || $path === '' || !str_starts_with($path, self::DIRECTORY . '/') || str_contains($path, '..')) {
            return;
        }

        Storage::disk('public')->delete($path);
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

    private static function applyJpegOrientation(\GdImage $image, string $binary): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return $image;
        }

        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
