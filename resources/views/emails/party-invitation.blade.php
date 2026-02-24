<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Party Invitation</title>
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
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            <!--[if mso]>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center">
                            <![endif]-->
                            @if($booking->location && $booking->location->company && $booking->location->company->logo_path)
                                @php
                                    $logoUrl = $booking->location->company->logo_path;
                                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, 'data:')) {
                                        $logoUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoUrl;
                                    }
                                @endphp
                                <img src="{{ $logoUrl }}" alt="{{ $booking->location->company->name }}" style="max-height: 50px; max-width: 180px; margin-bottom: 12px;" />
                            @elseif($booking->location && $booking->location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $booking->location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">You're Invited</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">{{ $hostName }} has invited you to a party</p>
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
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Hi {{ $guestName }},</p>

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">You have been personally invited by <strong>{{ $hostName }}</strong> to attend a <strong>{{ $packageName }}</strong> at {{ $companyName }}.</p>

                            @if($guestOfHonor)
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                <strong>{{ $guestOfHonor }}</strong>{{ $guestOfHonorAge ? " is turning {$guestOfHonorAge} and" : '' }} would love for you to celebrate with them!
                            </p>
                            @endif

                            <!-- Party Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Party Details</h3>
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280; width: 100px;">Event</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{{ $packageName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280;">Date</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{{ $bookingDate }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280;">Time</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{{ $bookingTime }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Location -->
                            @if($locationName)
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #eff6ff; border-radius: 6px; border: 1px solid #dbeafe; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <h3 style="margin: 0 0 8px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location</h3>
                                        <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 500; color: #1e40af;">{{ $locationName }}</p>
                                        @if($locationAddress)
                                        <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{ $locationAddress }}</p>
                                        @endif
                                        @if($locationPhone)
                                        <p style="margin: 0; font-size: 14px; color: #4b5563;">{{ $locationPhone }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- RSVP Section -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 0 0 6px 0; padding: 0; font-size: 15px; font-weight: 600; color: #111827;">Please let us know if you can make it</p>
                                        <p style="margin: 0 0 16px 0; padding: 0; font-size: 13px; line-height: 1.5; color: #6b7280;">
                                            Click below to confirm your attendance, let us know how many people are coming with you, and share any dietary needs or special requests.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding: 8px 0;">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $rsvpUrl }}" style="height:48px;v-text-anchor:middle;width:200px;" arcsize="13%" strokecolor="#1e40af" fillcolor="#1e40af">
                                        <w:anchorlock/>
                                        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Respond Now</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <a href="{{ $rsvpUrl }}" style="display: inline-block; background-color: #1e40af; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 6px; font-size: 16px; font-weight: 600;">
                                            Respond Now
                                        </a>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 16px 0 0 0; padding: 0; font-size: 12px; line-height: 1.6; color: #9ca3af; text-align: center;">
                                If the button does not work, copy and paste this link into your browser:<br>{{ $rsvpUrl }}
                            </p>

                            <!-- Footer -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">
                                            If you have any questions, please contact us
                                            @if($locationPhone)
                                                at <a href="tel:{{ $locationPhone }}" style="color: #1e40af; text-decoration: none;">{{ $locationPhone }}</a>
                                            @endif.
                                        </p>
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">Thank you for choosing {{ $companyName }}!</p>
                                        <p style="margin: 8px 0 0 0; padding: 0; font-size: 11px; color: #d1d5db;">
                                            You received this email because {{ $hostName }} invited you to a party at {{ $companyName }}.
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

    <!--[if mso]>
            </td>
        </tr>
    </table>
    <![endif]-->
</body>
</html>
