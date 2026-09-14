<?php

namespace App\Support;

class CardBrand
{
    public const MAX_LENGTH = 20;

    private const CANONICAL = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'mc' => 'Mastercard',
        'master card' => 'Mastercard',
        'americanexpress' => 'American Express',
        'american express' => 'American Express',
        'amex' => 'American Express',
        'discover' => 'Discover',
        'disc' => 'Discover',
        'jcb' => 'JCB',
        'dinersclub' => 'Diners Club',
        'diners club' => 'Diners Club',
        'diners' => 'Diners Club',
        'enroute' => 'enRoute',
        'echeck' => 'eCheck',
        'unionpay' => 'UnionPay',
        'maestro' => 'Maestro',
    ];

    public static function normalize(?string $raw): ?string
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '') {
            return null;
        }

        $key = strtolower(preg_replace('/[^a-z ]/i', '', $trimmed));
        $key = trim(preg_replace('/\s+/', ' ', $key));

        if (isset(self::CANONICAL[$key])) {
            return self::CANONICAL[$key];
        }

        $squashed = str_replace(' ', '', $key);

        if (isset(self::CANONICAL[$squashed])) {
            return self::CANONICAL[$squashed];
        }

        if ($key === '') {
            return null;
        }

        return mb_substr(ucwords($key), 0, self::MAX_LENGTH);
    }

    public static function lastFour(?string $accountNumber): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $accountNumber);

        if (strlen((string) $digits) < 4) {
            return null;
        }

        return substr((string) $digits, -4);
    }

    public static function label(?string $type, ?string $lastFour): ?string
    {
        $four = self::lastFour($lastFour);

        if ($four === null) {
            return null;
        }

        $brand = self::normalize($type);

        return ($brand ?? 'Card').' ending in '.$four;
    }

    public static function fromPayments($payments): ?string
    {
        if (! is_iterable($payments)) {
            return null;
        }

        $candidates = [];

        foreach ($payments as $payment) {
            $lastFour = self::lastFour(data_get($payment, 'card_last_four'));

            if ($lastFour === null) {
                continue;
            }

            $stamp = data_get($payment, 'paid_at') ?: data_get($payment, 'created_at');

            $candidates[] = [
                'rank' => data_get($payment, 'status') === 'completed' ? 0 : 1,
                'when' => $stamp ? strtotime((string) $stamp) : 0,
                'label' => self::label(data_get($payment, 'card_type'), $lastFour),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => [$a['rank'], -$a['when']] <=> [$b['rank'], -$b['when']]);

        return $candidates[0]['label'];
    }

    public static function redactPan(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback('/\d(?:[ -]?\d){11,18}/', function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]);

            return 'ending in '.substr((string) $digits, -4);
        }, $text);
    }
}
