<?php

namespace App\Support;

class VenueCategory
{
    public static function normalize(?string $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }

        $key = mb_strtolower((string) preg_replace('/\s+/', ' ', $trimmed));

        return config('venue_categories.aliases')[$key] ?? $trimmed;
    }

    public static function isEscapeRoom(?string $value): bool
    {
        return self::normalize($value) === config('venue_categories.canonical.escape_room');
    }

    /**
     * Lowercased raw values that should match a requested category, so filtering by
     * "Escape Room" also finds the rows stored as "Advanced" or "Beginner".
     *
     * @return array<int, string>
     */
    public static function matchValues(?string $requested): array
    {
        $canonical = self::normalize($requested);
        if ($canonical === '') {
            return [];
        }

        $values = array_merge([(string) $requested, $canonical], self::aliasesFor($canonical));

        return array_values(array_unique(array_map(
            fn ($value) => mb_strtolower(trim((string) $value)),
            array_filter($values, fn ($value) => trim((string) $value) !== '')
        )));
    }

    /** @return array<int, string> every raw value that normalizes to $canonical */
    public static function aliasesFor(string $canonical): array
    {
        return array_keys(array_filter(
            config('venue_categories.aliases'),
            fn ($mapped) => $mapped === $canonical
        ));
    }
}
