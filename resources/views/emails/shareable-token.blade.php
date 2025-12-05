<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; background: #ffffff; padding: 32px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <tr>
                        <td>
                            <h2 style="color: #111827; font-size: 20px; font-weight: 600; margin: 0 0 16px 0; letter-spacing: -0.01em;">Welcome to Zap Zone!</h2>
                            
                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563;">
                                You've been invited to register as a <strong>{{ ucwords(str_replace('_', ' ', $role)) }}</strong>.
                            </p>
                            
                            <table width="100%" cellpadding="16" cellspacing="0" style="background: #f9fafb; border-radius: 6px; margin: 20px 0; border: 1px solid #e5e7eb;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; line-height: 1.8; color: #374151;">
                                            <strong>Email:</strong> {{ $email }}
                                        </p>
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151;">
                                            <strong>Role:</strong> {{ ucwords(str_replace('_', ' ', $role)) }}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563;">
                                Click the button below to complete your registration:
                            </p>
                            
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0 16px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $link }}" style="display: inline-block; padding: 12px 24px; background-color: #1e40af; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500;">Register Now</a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="font-size: 14px; color: #6b7280; margin: 12px 0 0 0; text-align: center;">
                                Or copy this link:<br>
                                <a href="{{ $link }}" style="color: #1e40af; text-decoration: none; word-break: break-all; font-size: 14px;">{{ $link }}</a>
                            </p>
                            
                            <p style="margin: 24px 0 0 0; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 14px; color: #9ca3af;">
                                This is a one-time use invitation link. Once you complete registration, this link will expire.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
