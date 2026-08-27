<?php

namespace App\Support;

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
}
