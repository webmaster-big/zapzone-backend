<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Help Requested</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%;">
                    <tr>
                        <td style="text-align: center; background-color: #b45309; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            @if($location && $location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; color: #ffffff;">A customer needs help with the schedule</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">{{ $location->name ?? '' }}</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background-color: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 24px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                They could not find a time that works while booking online and asked to be contacted.
                                <strong>They are expecting a call back.</strong>
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
                                        <a href="tel:{{ $concern->phone }}" style="color: #b45309; text-decoration: none;">{{ $concern->phone }}</a>
                                    </td>
                                </tr>
                                @if($concern->email)
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280;">Email</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827;">
                                        <a href="mailto:{{ $concern->email }}" style="color: #b45309; text-decoration: none;">{{ $concern->email }}</a>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">What they were booking</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Item</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">{{ $concern->entity_name ?: 'Not chosen yet' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Date they wanted</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb;">{{ $concern->preferred_date ? $concern->preferred_date->format('l, F j, Y') : 'None selected' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #6b7280;">Time they wanted</td>
                                    <td style="padding: 10px 16px; font-size: 14px; color: #111827;">{{ $concern->preferred_time_label ?: 'None selected' }}</td>
                                </tr>
                            </table>

                            @if($concern->message)
                                <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">In their words</h3>
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fffbeb; border-radius: 6px; border: 1px solid #fcd34d; margin: 0 0 24px 0;">
                                    <tr>
                                        <td style="padding: 16px; font-size: 14px; line-height: 1.6; color: #78350f;">{{ $concern->message }}</td>
                                    </tr>
                                </table>
                            @endif

                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding: 8px 0 0 0;">
                                        <a href="{{ $reviewUrl }}" style="display: inline-block; background-color: #b45309; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-size: 14px; font-weight: 600;">Open in ZapZone</a>
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
