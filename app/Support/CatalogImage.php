<?php

namespace App\Support;

class CatalogImage
{
    public static function forCatalog(mixed $image): mixed
    {
        if (is_array($image)) {
            $usable = array_values(array_filter($image, fn ($item) => self::isUsable($item)));

            return $usable === [] ? null : $usable;
        }

        return self::isUsable($image) ? $image : null;
    }

    private static function isUsable(mixed $item): bool
    {
        return is_string($item) && trim($item) !== '' && !DataUriImage::isDataUri($item);
    }
}
