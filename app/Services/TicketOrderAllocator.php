<?php

namespace App\Services;

use InvalidArgumentException;

class TicketOrderAllocator
{
    public static function toCents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function toAmount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /**
     * Split $amountCents across $weights so the parts sum to exactly $amountCents.
     * Uses the largest-remainder method, so no part is ever a cent short of its
     * fair share by accident and the total never drifts.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    public static function allocate(int $amountCents, array $weights): array
    {
        if ($weights === []) {
            if ($amountCents !== 0) {
                throw new InvalidArgumentException('Cannot allocate a non-zero amount across zero lines.');
            }

            return [];
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Allocation weights cannot be negative.');
            }
        }

        $keys = array_keys($weights);
        $totalWeight = array_sum($weights);

        if ($totalWeight === 0) {
            return self::allocateEvenly($amountCents, $keys);
        }

        $allocated = [];
        $remainders = [];
        $running = 0;

        foreach ($weights as $key => $weight) {
            $exact = ($amountCents * $weight) / $totalWeight;
            $floor = (int) floor($exact);
            $allocated[$key] = $floor;
            $remainders[$key] = $exact - $floor;
            $running += $floor;
        }

        $leftover = $amountCents - $running;

        if ($leftover !== 0) {
            $order = $keys;
            usort($order, function ($a, $b) use ($remainders, $weights, $keys) {
                if ($remainders[$b] <=> $remainders[$a]) {
                    return $remainders[$b] <=> $remainders[$a];
                }
                if ($weights[$b] <=> $weights[$a]) {
                    return $weights[$b] <=> $weights[$a];
                }

                return array_search($a, $keys, true) <=> array_search($b, $keys, true);
            });

            $step = $leftover > 0 ? 1 : -1;
            $count = abs($leftover);

            for ($i = 0; $i < $count; $i++) {
                $allocated[$order[$i % count($order)]] += $step;
            }
        }

        return $allocated;
    }

    /**
     * @param  array<int, int|string>  $keys
     * @return array<int|string, int>
     */
    private static function allocateEvenly(int $amountCents, array $keys): array
    {
        $count = count($keys);
        $base = intdiv($amountCents, $count);
        $leftover = $amountCents - ($base * $count);

        $allocated = [];
        foreach ($keys as $index => $key) {
            $allocated[$key] = $base + ($index < abs($leftover) ? ($leftover > 0 ? 1 : -1) : 0);
        }

        return $allocated;
    }
}
