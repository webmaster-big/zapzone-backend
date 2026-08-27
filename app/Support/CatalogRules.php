<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CatalogRules
{
    public const ALL_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function enforces(string $group): bool
    {
        $mode = config("catalog_rules.groups.{$group}") ?? config('catalog_rules.default', 'log');

        return $mode === 'enforce';
    }

    public static function flag(Validator $validator, string $group, string $field, string $message, array $context = []): void
    {
        if (self::enforces($group)) {
            $validator->errors()->add($field, $message);

            return;
        }

        Log::warning('catalog rule violated (log-only)', ['group' => $group, 'field' => $field, 'message' => $message] + $context);
    }

    public static function reject(string $group, string $field, string $message, array $context = []): ?JsonResponse
    {
        if (!self::enforces($group)) {
            Log::warning('catalog rule violated (log-only)', ['group' => $group, 'field' => $field, 'message' => $message] + $context);

            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    public static function sameClock(?string $a, ?string $b): bool
    {
        if (!$a || !$b) {
            return false;
        }

        $x = strtotime($a);
        $y = strtotime($b);

        return $x !== false && $y !== false && date('H:i', $x) === date('H:i', $y);
    }

    public static function windowMinutes(?string $start, ?string $end): ?int
    {
        if (!$start || !$end) {
            return null;
        }

        $s = strtotime($start);
        $e = strtotime($end);

        if ($s === false || $e === false) {
            return null;
        }

        $minutes = (int) round(($e - $s) / 60);

        if ($minutes <= 0) {
            $minutes += 1440;
        }

        return $minutes;
    }

    public static function scheduleDays(array $schedule): array
    {
        $config = (array) ($schedule['day_configuration'] ?? []);

        return match ($schedule['availability_type'] ?? null) {
            'daily' => self::ALL_DAYS,
            'weekly' => array_map('strtolower', $config),
            'monthly' => array_values(array_unique(array_map(fn ($c) => strtolower(substr((string) $c, strrpos((string) $c, '-') + 1)), $config))),
            default => [],
        };
    }
}
