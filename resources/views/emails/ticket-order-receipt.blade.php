<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your ZapZone order {{ $order->reference_number }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#12161d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e3e7ee;">

                <tr>
                    <td style="background:#1e40af;padding:24px;color:#ffffff;">
                        <p style="margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Order confirmed</p>
                        <p style="margin:6px 0 0;font-size:24px;font-weight:700;">{{ $order->reference_number }}</p>
                        @if($order->location)
                            <p style="margin:8px 0 0;font-size:14px;opacity:.9;">{{ $order->location->name }}</p>
                        @endif
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 24px 8px;">
                        <p style="margin:0 0 4px;font-size:16px;">Hi {{ $order->customer_name }},</p>
                        <p style="margin:0;font-size:14px;color:#5a6472;line-height:1.6;">
                            Here is everything in your order — {{ $order->item_count }}
                            {{ $order->item_count === 1 ? 'item' : 'items' }},
                            {{ $order->ticket_count }} {{ $order->ticket_count === 1 ? 'ticket' : 'tickets' }} in total.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 24px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e7ee;border-radius:8px;border-collapse:separate;">
                            @foreach($lines as $line)
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid #eef1f5;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="font-size:15px;font-weight:600;">
                                                    {{ $line['position'] }}. {{ $line['name'] }}
                                                </td>
                                                <td align="right" style="font-size:15px;font-weight:700;white-space:nowrap;">
                                                    ${{ number_format($line['total_amount'], 2) }}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="padding-top:4px;font-size:13px;color:#67707f;">
                                                    {{ $line['quantity'] }} {{ $line['quantity'] === 1 ? 'ticket' : 'tickets' }}
                                                    @if($line['scheduled_date'])
                                                        &middot; {{ $line['scheduled_date'] }}
                                                    @endif
                                                    @if($line['scheduled_time'])
                                                        at {{ $line['scheduled_time'] }}
                                                    @endif
                                                    @if($line['reference_number'])
                                                        &middot; {{ $line['reference_number'] }}
                                                    @endif
                                                </td>
                                            </tr>
                                            @if($line['discount_amount'] > 0)
                                                <tr>
                                                    <td colspan="2" style="padding-top:4px;font-size:13px;color:#14653f;font-weight:600;">
                                                        ${{ number_format($line['discount_amount'], 2) }} saved on this item
                                                        @if(!empty($line['applied_discounts']))
                                                            ({{ collect($line['applied_discounts'])->map(fn ($d) => ($d['name'] ?? 'Special pricing') . (isset($d['discount_label']) ? ' ' . $d['discount_label'] : ''))->implode(', ') }})
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                            @foreach($line['add_ons'] as $addOn)
                                                <tr>
                                                    <td colspan="2" style="padding-top:4px;font-size:13px;color:#67707f;">
                                                        + {{ $addOn['quantity'] }} &times; {{ $addOn['name'] }}
                                                        &middot; ${{ number_format($addOn['price'] * $addOn['quantity'], 2) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                            @foreach($line['applied_fees'] ?? [] as $fee)
                                                @if(($fee['fee_application_type'] ?? 'additive') === 'additive' && ($fee['fee_amount'] ?? 0) > 0)
                                                    <tr>
                                                        <td colspan="2" style="padding-top:4px;font-size:13px;color:#67707f;">
                                                            + {{ $fee['fee_name'] ?? 'Fee' }} &middot; ${{ number_format($fee['fee_amount'], 2) }}
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            @endforeach

                            <tr>
                                <td style="padding:14px 16px;background:#f6f7f9;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td style="font-size:14px;color:#67707f;">Subtotal</td>
                                            <td align="right" style="font-size:14px;">${{ number_format($order->subtotal, 2) }}</td>
                                        </tr>
                                        @if($order->discount_amount > 0)
                                            <tr>
                                                <td style="font-size:14px;color:#14653f;">Total savings</td>
                                                <td align="right" style="font-size:14px;color:#14653f;">
                                                    &minus;${{ number_format($order->discount_amount, 2) }}
                                                </td>
                                            </tr>
                                        @endif
                                        @if($order->fee_total > 0)
                                            <tr>
                                                <td style="font-size:14px;color:#67707f;">Fees</td>
                                                <td align="right" style="font-size:14px;">${{ number_format($order->fee_total, 2) }}</td>
                                            </tr>
                                        @endif
                                        <tr>
                                            <td style="font-size:16px;font-weight:700;padding-top:6px;">Order total</td>
                                            <td align="right" style="font-size:20px;font-weight:800;padding-top:6px;">
                                                ${{ number_format($order->total_amount, 2) }}
                                            </td>
                                        </tr>
                                        @if($order->remaining_balance > 0)
                                            <tr>
                                                <td colspan="2" style="padding-top:6px;font-size:13px;color:#8a4b00;">
                                                    Balance due on arrival: ${{ number_format($order->remaining_balance, 2) }}
                                                </td>
                                            </tr>
                                        @endif
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @if($qrCodeBase64)
                    <tr>
                        <td align="center" style="padding:8px 24px 24px;">
                            <p style="margin:0 0 10px;font-size:13px;color:#67707f;">Show this at the door to check in your whole order.</p>
                            <img src="data:image/png;base64,{{ $qrCodeBase64 }}" alt="Order check-in code" width="180" height="180" style="display:block;margin:0 auto;border:1px solid #e3e7ee;border-radius:8px;">
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:0 24px 28px;font-size:13px;color:#8b93a1;line-height:1.6;">
                        Keep this email — {{ $order->reference_number }} is how we find your order.
                        @if($order->location && $order->location->phone)
                            Questions? Call {{ $order->location->phone }}.
                        @endif
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
