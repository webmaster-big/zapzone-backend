<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" border="0" cellpadding="0" cellspacing="0" style="max-width: 480px; background-color: #ffffff; padding: 32px; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <tr>
                        <td>
                            <h2 style="color: #111827; font-size: 20px; font-weight: 600; margin: 0 0 16px 0; letter-spacing: -0.01em; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">Welcome to Zap Zone!</h2>

                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                You've been invited to register as a <strong>{{ ucwords(str_replace('_', ' ', $role)) }}</strong>.
                            </p>

                            <table width="100%" border="0" cellpadding="16" cellspacing="0" style="background-color: #f9fafb; border-radius: 6px; margin: 20px 0; border: 1px solid #e5e7eb;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Email:</strong> {{ $email }}
                                        </p>
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Role:</strong> {{ ucwords(str_replace('_', ' ', $role)) }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 20px 0; color: #4b5563; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                Click the button below to complete your registration:
                            </p>

                            <!-- Button with VML for Outlook compatibility -->
                            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 0 20px 0;">
                                <tr>
                                    <td align="center" style="padding: 0;">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $link }}" style="height:44px;v-text-anchor:middle;width:200px;" arcsize="14%" stroke="f" fillcolor="#1e40af">
                                            <w:anchorlock/>
                                            <center style="color:#ffffff;font-family:sans-serif;font-size:14px;font-weight:bold;">Register Now</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" bgcolor="#1e40af" style="border-radius: 6px;">
                                                    <a href="{{ $link }}" target="_blank" style="font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #ffffff; text-decoration: none; border-radius: 6px; padding: 12px 24px; border: 1px solid #1e40af; display: inline-block; font-weight: 500; background-color: #1e40af;">Register Now</a>
                                                </td>
                                            </tr>
                                        </table>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 20px 0 0 0;">
                                <tr>
                                    <td align="center">
                                        <p style="font-size: 12px; color: #6b7280; margin: 0 0 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            Or copy and paste this link in your browser:
                                        </p>
                                        <p style="margin: 0; font-size: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <a href="{{ $link }}" style="color: #1e40af; word-break: break-all;">{{ $link }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 24px 0 0 0; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 12px; color: #9ca3af; line-height: 1.6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            This is a one-time use invitation link. Once you complete registration, this link will expire.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
