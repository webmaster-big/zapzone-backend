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
                            @if(isset($logoUrl) && $logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="max-height: 50px; max-width: 180px; margin-bottom: 16px;" />
                            @endif

                            <h2 style="color: #111827; font-size: 20px; font-weight: 600; margin: 0 0 16px 0; letter-spacing: -0.01em; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">You're Invited!</h2>

                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                Hi {{ $guestName }}, {{ $hostName }} has invited you to a <strong>{{ $packageName }}</strong> event at {{ $companyName }}.
                            </p>

                            @if($guestOfHonor)
                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; color: #4b5563; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                <strong>{{ $guestOfHonor }}</strong>{{ $guestOfHonorAge ? " is turning {$guestOfHonorAge} and" : '' }} would love for you to celebrate with them!
                            </p>
                            @endif

                            <table width="100%" border="0" cellpadding="16" cellspacing="0" style="background-color: #f9fafb; border-radius: 6px; margin: 20px 0; border: 1px solid #e5e7eb;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Event:</strong> {{ $packageName }}
                                        </p>
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Date:</strong> {{ $bookingDate }}
                                        </p>
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Time:</strong> {{ $bookingTime }}
                                        </p>
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Hosted by:</strong> {{ $hostName }}
                                        </p>
                                        @if($locationName)
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Location:</strong> {{ $locationName }}
                                        </p>
                                        @endif
                                        @if($locationAddress)
                                        <p style="margin: 4px 0 0 0; font-size: 14px; line-height: 1.8; color: #374151; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            <strong>Address:</strong> {{ $locationAddress }}
                                        </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <p style="font-size: 14px; line-height: 1.6; margin: 0 0 20px 0; color: #4b5563; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                Please confirm your attendance:
                            </p>

                            <!-- Button with VML for Outlook compatibility -->
                            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 0 0 20px 0;">
                                <tr>
                                    <td align="center" style="padding: 0;">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $rsvpUrl }}" style="height:44px;v-text-anchor:middle;width:200px;" arcsize="14%" stroke="f" fillcolor="#1e40af">
                                            <w:anchorlock/>
                                            <center style="color:#ffffff;font-family:sans-serif;font-size:14px;font-weight:bold;">Confirm Attendance</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <table border="0" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" bgcolor="#1e40af" style="border-radius: 6px;">
                                                    <a href="{{ $rsvpUrl }}" target="_blank" style="font-size: 14px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; color: #ffffff; text-decoration: none; border-radius: 6px; padding: 12px 24px; border: 1px solid #1e40af; display: inline-block; font-weight: 500; background-color: #1e40af;">Confirm Attendance</a>
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
                                            <a href="{{ $rsvpUrl }}" style="color: #1e40af; word-break: break-all;">{{ $rsvpUrl }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="margin: 24px 0 0 0; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td>
                                        <p style="margin: 0; font-size: 12px; color: #9ca3af; line-height: 1.6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
                                            @if($locationPhone)
                                                Questions? Contact us at <a href="tel:{{ $locationPhone }}" style="color: #1e40af; text-decoration: none;">{{ $locationPhone }}</a>.
                                            @endif
                                            Thank you for choosing {{ $companyName }}.
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
