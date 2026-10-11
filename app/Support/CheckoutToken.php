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
        $given = (string) $request->input('checkout_token', '');

        return $given !== '' && hash_equals(self::for($type, $id), $given);
    }

    public static function forOrder(TicketOrder $order): string
    {
        return hash_hmac('sha256', $order->id . '|' . $order->reference_number, (string) config('app.key'));
    }
}
