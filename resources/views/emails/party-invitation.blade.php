<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Invitation</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <!--[if mso]>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
    <![endif]-->

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%;">
                    <!-- Header — same as booking-confirmation -->
                    <tr>
                        <td style="text-align: center; background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            <!--[if mso]>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center">
                            <![endif]-->
                            @if(isset($logoDataUri) && $logoDataUri)
                                <img src="{{ $logoDataUri }}" alt="{{ $booking->location->company->company_name }}" style="max-height: 50px; max-width: 180px; margin-bottom: 12px;" />
                            @elseif($booking->location && $booking->location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $booking->location->company->company_name }}</p>
                            @endif
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Event Invitation</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">Reference: {{ $booking->reference_number }}</p>
                            <!--[if mso]>
                                    </td>
                                </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Dear {{ $guestName }},</p>

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $hostName }} has invited you to a <strong>{{ $packageName }}</strong> event. We would love for you to join us for a fun celebration!</p>

                            @if($guestOfHonor)
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                <strong>{{ $guestOfHonor }}</strong>{{ $guestOfHonorAge ? " is turning {$guestOfHonorAge} and" : '' }} would love for you to celebrate with them!
                            </p>
                            @endif

                            <!-- Event Details — same table style as booking-confirmation -->
                            <h3 style="margin: 16px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Event Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Event:</td>
                                                <td style="color: #111827;">{{ $packageName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Date:</td>
                                                <td style="color: #111827;">{{ $bookingDate }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Time:</td>
                                                <td style="color: #111827;">{{ $bookingTime }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Hosted by:</td>
                                                <td style="color: #111827;">{{ $hostName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Location & Contact — same table style as booking-confirmation -->
                            @if($locationName)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location &amp; Contact</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
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
                                @if($locationAddress)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Address:</td>
                                                <td style="color: #111827;">{{ $locationAddress }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($locationPhone)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                <td style="color: #111827;"><a href="tel:{{ $locationPhone }}" style="color: #1e40af; text-decoration: none;">{{ $locationPhone }}</a></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- RSVP Section — same button style as booking-confirmation QR section -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 20px; text-align: center;">
                                        <h3 style="margin: 0 0 8px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Please Confirm Your Attendance</h3>
                                        <p style="margin: 8px 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Click the button below to let {{ $hostName }} know if you can make it.</p>

                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $rsvpUrl }}" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="13%" strokecolor="#1e40af" fillcolor="#1e40af">
                                        <w:anchorlock/>
                                        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Confirm Attendance</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <a href="{{ $rsvpUrl }}" style="display: inline-block; background-color: #1e40af; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 6px; font-size: 16px; font-weight: 600;">
                                            Confirm Attendance
                                        </a>
                                        <!--<![endif]-->

                                        <p style="margin: 16px 0 0 0; padding: 0; font-size: 12px; line-height: 1.6; color: #6b7280;">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $rsvpUrl }}" style="color: #1e40af; word-break: break-all;">{{ $rsvpUrl }}</a></p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Footer — same as booking-confirmation -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">
                                            If you have any questions, please contact us
                                            @if($booking->location && $booking->location->phone)
                                                at <a href="tel:{{ $booking->location->phone }}" style="color: #1e40af; text-decoration: none;">{{ $booking->location->phone }}</a>
                                            @endif.
                                        </p>
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">Thank you for choosing {{ $booking->location && $booking->location->company ? $booking->location->company->company_name : 'Zap Zone' }}!</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!--[if mso]>
            </td>
        </tr>
    </table>
    <![endif]-->
</body>
</html>
