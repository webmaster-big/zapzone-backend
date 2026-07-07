<?php

namespace App\Support;

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

class CacheGroups
{
    public const PACKAGES = 'packages';
    public const ATTRACTIONS = 'attractions';
    public const EVENTS = 'events';
    public const MEMBERSHIP_PLANS = 'membership-plans';
    public const LOCATIONS = 'locations';
    public const DASHBOARDS = 'dashboards';

    public const TTL_CATALOG = 600;
    public const TTL_DASHBOARD = 300;

    public static function supported(): bool
    {
        try {
            return Cache::getStore() instanceof TaggableStore;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function remember(array $tags, string $key, int $ttl, \Closure $callback): mixed
    {
        if (!self::supported()) {
            return $callback();
        }

        try {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            return $callback();
        }
    }

    public static function get(array $tags, string $key): mixed
    {
        if (!self::supported()) {
            return null;
        }

        try {
            return Cache::tags($tags)->get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function put(array $tags, string $key, mixed $value, int $ttl): void
    {
        if (!self::supported()) {
            return;
        }

        try {
            Cache::tags($tags)->put($key, $value, $ttl);
        } catch (\Throwable $e) {
        }
    }

    public static function flush(array $tags): void
    {
        if (!self::supported()) {
            return;
        }

        try {
            Cache::tags($tags)->flush();
        } catch (\Throwable $e) {
        }
    }
}
