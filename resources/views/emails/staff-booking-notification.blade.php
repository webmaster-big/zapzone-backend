<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Alert</title>
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
                        <td style="text-align: center; background-color: #059669; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
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
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">New Booking Alert</h1>
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
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Hi {{ $recipientName }},</p>

                            <p style="margin: 0 0 24px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">A new booking has just been placed at <strong>{{ $locationName }}</strong>. Here are the details:</p>

                            <!-- Customer Information -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Customer Information</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Name:</td>
                                                <td style="color: #111827;">{{ $customerName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($customerEmail)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                <td style="color: #111827;">{{ $customerEmail }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($customerPhone)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                <td style="color: #111827;">{{ $customerPhone }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Booking Details -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Booking Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Package:</td>
                                                <td style="color: #111827;">{{ $packageName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Date & Time:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_date)->format('F j, Y') }} at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Duration:</td>
                                                <td style="color: #111827;">{{ $booking->duration }} {{ $booking->duration_unit }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Participants:</td>
                                                <td style="color: #111827;">{{ $booking->participants }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($roomName && $roomName !== 'Not assigned')
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Space:</td>
                                                <td style="color: #111827;">{{ $roomName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Location:</td>
                                                <td style="color: #111827;">{{ $locationName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Guest of Honor -->
                            @if($booking->guest_of_honor_name)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Guest of Honor</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Name:</td>
                                                <td style="color: #111827;">{{ $booking->guest_of_honor_name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($booking->guest_of_honor_age)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Age:</td>
                                                <td style="color: #111827;">Turning {{ $booking->guest_of_honor_age }} years old</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Add-ons & Attractions -->
                            @if($booking->addOns->count() > 0 || $booking->attractions->count() > 0)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Add-ons & Extras</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                @foreach($booking->addOns as $addon)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #111827;">{{ $addon->name }}</td>
                                                <td style="color: #111827; text-align: right;">x{{ $addon->pivot->quantity }} &mdash; ${{ number_format($addon->pivot->price_at_booking * $addon->pivot->quantity, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                                @foreach($booking->attractions as $attraction)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #111827;">{{ $attraction->name }}</td>
                                                <td style="color: #111827; text-align: right;">x{{ $attraction->pivot->quantity }} &mdash; ${{ number_format($attraction->pivot->price_at_booking * $attraction->pivot->quantity, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <!-- Payment Summary -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Payment Summary</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Total Amount:</td>
                                                <td style="color: #111827; font-weight: 600;">${{ number_format($booking->total_amount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Amount Paid:</td>
                                                <td style="color: #111827;">${{ number_format($booking->amount_paid ?? 0, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #dc2626; width: 140px;">Balance Due:</td>
                                                <td style="color: #dc2626; font-weight: 600;">${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Payment Status:</td>
                                                <td style="color: #111827; text-transform: capitalize;">{{ $booking->payment_status }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Special Requests -->
                            @if($booking->special_requests || $booking->notes)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Notes & Requests</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                @if($booking->special_requests)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; color: #374151; border-bottom: 1px solid #e5e7eb;">
                                        <strong style="color: #111827;">Special Requests:</strong><br>
                                        {{ $booking->special_requests }}
                                    </td>
                                </tr>
                                @endif
                                @if($booking->notes)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; color: #374151;">
                                        <strong style="color: #111827;">Notes:</strong><br>
                                        {{ $booking->notes }}
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Footer -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 0 0 8px 0; padding: 0; font-size: 12px; color: #6b7280;">
                                            This is an automated notification from {{ $booking->location->company->name ?? config('app.name', 'ZapZone') }}
                                        </p>
                                        <p style="margin: 0; padding: 0; font-size: 11px; color: #9ca3af;">
                                            Booking created on {{ $booking->created_at->format('M j, Y \a\t g:i A') }}
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
