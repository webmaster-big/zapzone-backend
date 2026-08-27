<?php

namespace App\Services\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class CheckoutTotalGuard
{
    public const REJECTION_MESSAGE = 'The price of this order has changed. Please refresh the page and try again.';

    public function check(array $expectation, ?float $clientTotal, mixed $actor, array $context = []): ?string
    {
        if ($clientTotal === null) {
            return null;
        }

        $tolerance = (float) config('checkout.total_tolerance', 0.05);
        $expected = (float) $expectation['expected_total'];
        $delta = round($clientTotal - $expected, 2);
        $isStaff = $actor instanceof User;
        $enforce = (bool) config('checkout.enforce_server_total', false)
            && (! $isStaff || (bool) config('checkout.enforce_for_staff', false));
        $underpaid = $delta < -$tolerance;

        $lineViolations = [];
        foreach ($expectation['lines'] as $line) {
            if ($line['client_unit'] !== null && $line['client_unit'] < $line['unit_price'] - $tolerance) {
                $lineViolations[] = [
                    'type' => $line['type'],
                    'id' => $line['id'],
                    'client_unit' => $line['client_unit'],
                    'expected_unit' => $line['unit_price'],
                ];
            }
        }

        if (abs($delta) > $tolerance || $lineViolations !== []) {
            Log::channel(config('checkout.log_channel') ?: config('logging.default'))
                ->warning('checkout.server_total.mismatch', array_merge($context, [
                    'mode' => $enforce ? 'enforce' : 'shadow',
                    'actor_type' => $actor ? class_basename($actor) : 'guest',
                    'actor_id' => $actor?->getKey(),
                    'client_total' => $clientTotal,
                    'expected_total' => $expected,
                    'delta' => $delta,
                    'underpaid' => $underpaid,
                    'line_violations' => $lineViolations,
                    'expectation' => $expectation,
                ]));
        }

        if ($enforce && ($underpaid || $lineViolations !== [])) {
            return self::REJECTION_MESSAGE;
        }

        return null;
    }
}
