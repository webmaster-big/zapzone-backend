<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unfinished Checkout</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%;">
                    <tr>
                        <td style="text-align: center; background-color: #1d4ed8; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            @if($location && $location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; color: #ffffff;">A customer left checkout unfinished</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">{{ $location->name ?? '' }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 24px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                They filled in their details and then closed the page before paying. Nothing was charged and
                                <strong>they have not been told we saved this</strong> &mdash; treat a call as a fresh offer of help, not a follow-up.
                            </p>

                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Who to contact</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Name</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; font-weight: 600; border-bottom: 1px solid #e5e7eb;">{{ $concern->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; {{ $concern->email ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">Phone</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; font-weight: 600; {{ $concern->email ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">
                                        <a href="tel:{{ $concern->phone }}" style="color: #1d4ed8; text-decoration: none;">{{ $concern->phone }}</a>
                                    </td>
                                </tr>
                                @if($concern->email)
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280;">Email</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827;">
                                        <a href="mailto:{{ $concern->email }}" style="color: #1d4ed8; text-decoration: none;">{{ $concern->email }}</a>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">How far they got</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Item</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">{{ $concern->entity_name ?: 'Not chosen yet' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Date</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">{{ $concern->preferred_date ? $concern->preferred_date->format('l, F j, Y') : 'None selected' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Time</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">{{ $concern->preferred_time_label ?: 'None selected' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; {{ data_get($concern->context, 'estimated_total') !== null ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">Last step reached</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; {{ data_get($concern->context, 'estimated_total') !== null ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">{{ data_get($concern->context, 'step_label', 'Details') }}</td>
                                </tr>
                                @if(data_get($concern->context, 'estimated_total') !== null)
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280;">Cart value</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; font-weight: 600;">${{ number_format((float) data_get($concern->context, 'estimated_total'), 2) }}</td>
                                </tr>
                                @endif
                            </table>

                            @if(is_array(data_get($concern->context, 'items')) && count(data_get($concern->context, 'items')))
                                <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">What was in the cart</h3>
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                    @foreach(data_get($concern->context, 'items') as $item)
                                        <tr>
                                            <td style="padding: 10px 16px; font-size: 14px; color: #111827;">{{ data_get($item, 'name', 'Item') }}</td>
                                            <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; text-align: right;">&times; {{ data_get($item, 'quantity', 1) }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding: 8px 0 0 0;">
                                        <a href="{{ $reviewUrl }}" style="display: inline-block; background-color: #1d4ed8; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-size: 14px; font-weight: 600;">Open in ZapZone</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 24px 0 0 0; padding: 0; font-size: 12px; line-height: 1.6; color: #9ca3af; text-align: center;">
                                Sent to every active staff account at {{ $location->name ?? 'this venue' }} · {{ $concern->created_at->format('M j, Y g:i A') }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
