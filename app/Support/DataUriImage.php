<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class DataUriImage
{
    public static function isDataUri(mixed $value): bool
    {
        return is_string($value) && stripos($value, 'data:') === 0;
    }

    public static function contains(mixed $images): bool
    {
        if (self::isDataUri($images)) {
            return true;
        }

        if (is_array($images)) {
            foreach ($images as $image) {
                if (self::isDataUri($image)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function store(string $dataUri, string $directory): string
    {
        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,/i', $dataUri, $matches)) {
            throw new \InvalidArgumentException('Only base64 image data URIs are accepted');
        }

        $extension = strtolower($matches[1]);
        if ($extension === 'svg+xml') {
            $extension = 'svg';
        } elseif ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $binary = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Image data could not be decoded');
        }

        $binary = self::downscale($binary, $extension);

        $directory = trim($directory, '/');
        $fullDirectory = storage_path('app/public/' . $directory);
        if (!is_dir($fullDirectory) && !mkdir($fullDirectory, 0755, true) && !is_dir($fullDirectory)) {
            throw new \RuntimeException("Could not create {$fullDirectory}");
        }

        $filename = uniqid() . '.' . $extension;
        if (file_put_contents($fullDirectory . '/' . $filename, $binary) === false) {
            throw new \RuntimeException("Could not write {$fullDirectory}/{$filename}");
        }

        return $directory . '/' . $filename;
    }

    public static function externalize(mixed $images, string $directory): mixed
    {
        if (is_string($images)) {
            return self::isDataUri($images) ? self::store($images, $directory) : $images;
        }

        if (is_array($images)) {
            foreach ($images as $key => $image) {
                if (self::isDataUri($image)) {
                    $images[$key] = self::store($image, $directory);
                }
            }
        }

        return $images;
    }

    public static function downscale(string $binary, string $extension): string
    {
        $config = (array) config('media.downscale', []);
        if (!($config['enabled'] ?? true) || !in_array($extension, ['png', 'jpg', 'webp'], true)) {
            return $binary;
        }

        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'getimagesizefromstring'] as $function) {
            if (!function_exists($function)) {
                return $binary;
            }
        }

        if ($extension === 'webp' && !function_exists('imagewebp')) {
            return $binary;
        }

        if ($extension === 'jpg' && !function_exists('exif_read_data')) {
            return $binary;
        }

        $maxWidth = max(1, (int) ($config['max_width'] ?? 1920));
        $maxBytes = max(1, (int) ($config['max_bytes'] ?? 1048576));
        $maxPixels = max(1, (int) ($config['max_source_pixels'] ?? 40000000));

        $info = @getimagesizefromstring($binary);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return $binary;
        }

        [$width, $height] = $info;
        if ($width <= $maxWidth && strlen($binary) <= $maxBytes) {
            return $binary;
        }
        if (($width * $height) > $maxPixels) {
            return $binary;
        }

        $targetWidth = min($width, $maxWidth);
        $targetHeight = max(1, (int) round($height * $targetWidth / $width));
        if (!self::hasMemoryFor(($width * $height) + ($targetWidth * $targetHeight))) {
            return $binary;
        }

        $obLevel = ob_get_level();

        try {
            $source = @imagecreatefromstring($binary);
            if (!$source instanceof \GdImage) {
                return $binary;
            }

            if (!imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($source);

            if ($extension === 'jpg') {
                $canvas = self::applyJpegOrientation($canvas, $binary);
            }

            ob_start();
            $written = match ($extension) {
                'png' => imagepng($canvas, null, 9),
                'jpg' => imagejpeg($canvas, null, max(1, min(100, (int) ($config['jpeg_quality'] ?? 85)))),
                'webp' => imagewebp($canvas, null, max(1, min(100, (int) ($config['jpeg_quality'] ?? 85)))),
            };
            $output = ob_get_clean();
            imagedestroy($canvas);

            if ($written !== true || !is_string($output) || $output === '' || strlen($output) >= strlen($binary)) {
                return $binary;
            }

            return $output;
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            Log::warning('Image downscale skipped', ['extension' => $extension, 'error' => $e->getMessage()]);

            return $binary;
        }
    }

    private static function applyJpegOrientation(\GdImage $image, string $binary): \GdImage
    {
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
