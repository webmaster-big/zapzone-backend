<?php

namespace App\Support;

use App\Models\TicketOrder;
use Illuminate\Http\Request;

class CheckoutToken
{
    public static function for(string $type, int $id): string
    {
        return hash_hmac('sha256', $type . '|' . $id, (string) config('app.key'));
    }

    public static function presented(Request $request, string $type, int $id): bool
    {
        return self::matches($request, self::for($type, $id));
    }

    public static function presentedForOrder(Request $request, TicketOrder $order): bool
    {
        return self::matches($request, self::forOrder($order));
    }

    public static function forOrder(TicketOrder $order): string
    {
        return hash_hmac('sha256', $order->id . '|' . $order->reference_number, (string) config('app.key'));
    }

    private static function matches(Request $request, string $expected): bool
    {
        $given = $request->input('checkout_token');

        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
