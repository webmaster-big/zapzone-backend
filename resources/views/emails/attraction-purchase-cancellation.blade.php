<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Cancellation</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; background-color: #dc2626; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            @if($purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company && $purchase->attraction->location->company->logo_path)
                                @php
                                    $logoUrl = $purchase->attraction->location->company->logo_path;
                                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, 'data:')) {
                                        $logoUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoUrl;
                                    }
                                @endphp
                                <img src="{{ $logoUrl }}" alt="{{ $purchase->attraction->location->company->name }}" style="max-height: 50px; max-width: 180px; margin-bottom: 12px;" />
                            @elseif($purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $purchase->attraction->location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Purchase Cancelled</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">Order #{{ $purchase->id }}</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Dear {{ $customerName }},</p>

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                Your attraction purchase has been cancelled.
                                @if($type === 'void')
                                    The payment of <strong>${{ number_format($refundAmount, 2) }}</strong> has been voided and will not be charged to your account.
                                @else
                                    A refund of <strong>${{ number_format($refundAmount, 2) }}</strong> has been processed to your original payment method. Please allow 5-10 business days for the refund to appear on your statement.
                                @endif
                            </p>

                            <!-- Purchase Details -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Purchase Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Attraction:</td>
                                                <td style="color: #111827;">{{ $attractionName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Location:</td>
                                                <td style="color: #111827;">{{ $locationName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Quantity:</td>
                                                <td style="color: #111827;">{{ $purchase->quantity }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->purchase_date)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Purchase Date:</td>
                                                <td style="color: #111827;">{{ $purchase->purchase_date->format('l, F j, Y') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Refund Details -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">{{ $type === 'void' ? 'Void' : 'Refund' }} Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef2f2; border-radius: 6px; border: 1px solid #fecaca; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #fecaca;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Original Amount:</td>
                                                <td style="color: #111827;">${{ number_format($payment->amount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #fecaca;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">{{ $type === 'void' ? 'Voided' : 'Refunded' }} Amount:</td>
                                                <td style="color: #dc2626; font-weight: 600;">${{ number_format($refundAmount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Transaction ID:</td>
                                                <td style="color: #111827;">{{ $payment->transaction_id }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 24px 0 0 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">If you have any questions about this cancellation, please don't hesitate to contact us.</p>

                            <!-- Footer -->
                            <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
                            <p style="margin: 0; padding: 0; font-size: 12px; color: #9ca3af; text-align: center;">
                                @if($purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company)
                                    {{ $purchase->attraction->location->company->name }}
                                @endif
                                @if($purchase->attraction && $purchase->attraction->location)
                                    @if($purchase->attraction->location->company) &bull; @endif
                                    {{ $purchase->attraction->location->name }}
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
