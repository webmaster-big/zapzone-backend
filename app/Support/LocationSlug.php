<?php

namespace App\Support;

use Illuminate\Support\Str;

class LocationSlug
{
    public const MAX_LENGTH = 110;

    public const RESERVED = [
        'admin',
        'api',
        'assets',
        'attendant',
        'attractions',
        'book',
        'bookings',
        'browse',
        'company',
        'customer',
        'customers',
        'edit-attraction',
        'events',
        'fee-supports',
        'home',
        'location-change-requests',
        'login',
        'manager',
        'memberships',
        'notifications',
        'packages',
        'payments',
        'photos',
        'purchase',
        'register',
        'rsvp',
        'settings',
        'special-pricings',
        'storage',
        'waiver',
        'waivers',
    ];

    public static function preferredSource(?string $city, ?string $name): string
    {
        return self::make($city) === 'location' ? (string) $name : (string) $city;
    }

    public static function make(?string $name): string
    {
        $slug = Str::slug((string) $name);

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = trim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug === '' ? 'location' : $slug;
    }

    public static function unique(?string $name, array $taken): string
    {
        $base = self::make($name);

        $blocked = array_flip(array_merge(
            self::RESERVED,
            array_map(fn ($slug) => strtolower((string) $slug), $taken)
        ));

        if (!isset($blocked[$base])) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; $suffix++) {
            $candidate = $base . '-' . $suffix;

            if (!isset($blocked[$candidate])) {
                return $candidate;
            }
        }

        return substr($base, 0, 80) . '-' . uniqid();
    }

    public static function isReserved(?string $slug): bool
    {
        return in_array(strtolower(trim((string) $slug)), self::RESERVED, true);
    }
}
