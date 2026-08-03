<?php

namespace App\Support;

class UserAgentParser
{
    public static function browser(?string $ua): ?string
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return null;
        }

        return match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'Edge') => 'Microsoft Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS') || (str_contains($ua, 'Chrome') && !str_contains($ua, 'Chromium')) => 'Chrome',
            str_contains($ua, 'Chromium') => 'Chromium',
            str_contains($ua, 'Safari') && str_contains($ua, 'Version/') => 'Safari',
            str_contains($ua, 'MSIE') || str_contains($ua, 'Trident') => 'Internet Explorer',
            default => 'Unknown',
        };
    }

    public static function os(?string $ua): ?string
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return null;
        }

        return match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows NT') || str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };
    }
}
