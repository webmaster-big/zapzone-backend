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
                <table width="560" cellpadding="0" cellspacing="0" border="0" style="max-width: 560px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff; padding: 28px 32px; border-radius: 8px 8px 0 0;">
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
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">🎉 New Booking Alert!</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">A new booking has been placed</p>
                            <!--[if mso]>
                                    </td>
                                </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>

                    <!-- Alert Banner -->
                    <tr>
                        <td style="background-color: #ecfdf5; padding: 16px 24px; border-left: 4px solid #059669;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td>
                                        <p style="margin: 0; padding: 0; font-size: 16px; font-weight: 600; color: #047857;">Reference: {{ $booking->reference_number }}</p>
                                        <p style="margin: 4px 0 0 0; padding: 0; font-size: 14px; color: #059669;">
                                            {{ \Carbon\Carbon::parse($booking->booking_date)->format('l, F j, Y') }} at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                                        </p>
                                    </td>
                                    <td style="text-align: right; vertical-align: middle;">
                                        <span style="display: inline-block; background-color: #059669; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                                            ${{ number_format($booking->total_amount, 2) }}
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 28px 32px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 20px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Hi {{ $recipientName }},</p>

                            <p style="margin: 0 0 24px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">A new booking has just been placed at <strong>{{ $locationName }}</strong>. Here are the details:</p>

                            <!-- Customer Information -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">👤 Customer Information</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Name:</td>
                                                <td style="color: #111827; font-weight: 600;">{{ $customerName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($customerEmail)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Email:</td>
                                                <td style="color: #111827;">{{ $customerEmail }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($customerPhone)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Phone:</td>
                                                <td style="color: #111827;">{{ $customerPhone }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Booking Details -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">📅 Booking Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Package:</td>
                                                <td style="color: #111827; font-weight: 600;">{{ $packageName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Date & Time:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_date)->format('M j, Y') }} at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Duration:</td>
                                                <td style="color: #111827;">{{ $booking->duration }} {{ $booking->duration_unit }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Participants:</td>
                                                <td style="color: #111827;">{{ $booking->participants }} guests</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Room:</td>
                                                <td style="color: #111827;">{{ $roomName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 120px;">Location:</td>
                                                <td style="color: #111827;">{{ $locationName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Guest of Honor -->
                            @if($booking->guest_of_honor_name)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">🎂 Guest of Honor</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef3c7; border-radius: 6px; border: 1px solid #fcd34d; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 16px; font-size: 14px; text-align: center;">
                                        <p style="margin: 0; font-size: 16px; font-weight: 600; color: #92400e;">{{ $booking->guest_of_honor_name }}</p>
                                        @if($booking->guest_of_honor_age)
                                        <p style="margin: 4px 0 0 0; font-size: 14px; color: #b45309;">Turning {{ $booking->guest_of_honor_age }} years old</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Add-ons & Attractions -->
                            @if($booking->addOns->count() > 0 || $booking->attractions->count() > 0)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">🎁 Add-ons & Extras</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
                                @foreach($booking->addOns as $addon)
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                                        <span style="color: #111827;">{{ $addon->name }}</span>
                                        <span style="color: #6b7280; float: right;">x{{ $addon->pivot->quantity }} - ${{ number_format($addon->pivot->price_at_booking * $addon->pivot->quantity, 2) }}</span>
                                    </td>
                                </tr>
                                @endforeach
                                @foreach($booking->attractions as $attraction)
                                <tr>
                                    <td style="padding: 10px 16px; font-size: 14px; border-bottom: 1px solid #e5e7eb;">
                                        <span style="color: #111827;">{{ $attraction->name }}</span>
                                        <span style="color: #6b7280; float: right;">x{{ $attraction->pivot->quantity }} - ${{ number_format($attraction->pivot->price_at_booking * $attraction->pivot->quantity, 2) }}</span>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <!-- Payment Summary -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">💰 Payment Summary</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0fdf4; border-radius: 6px; border: 1px solid #bbf7d0; margin: 0 0 24px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #bbf7d0;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #166534;">Total Amount:</td>
                                                <td style="text-align: right; font-weight: 700; color: #166534;">${{ number_format($booking->total_amount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #bbf7d0;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #166534;">Amount Paid:</td>
                                                <td style="text-align: right; color: #166534;">${{ number_format($booking->amount_paid ?? 0, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #dc2626; font-weight: 600;">Balance Due:</td>
                                                <td style="text-align: right; font-weight: 700; color: #dc2626;">${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #166534;">Payment Status:</td>
                                                <td style="text-align: right;">
                                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;
                                                        @if($booking->payment_status === 'paid')
                                                            background-color: #dcfce7; color: #166534;
                                                        @elseif($booking->payment_status === 'partial')
                                                            background-color: #fef3c7; color: #92400e;
                                                        @else
                                                            background-color: #fee2e2; color: #991b1b;
                                                        @endif
                                                    ">
                                                        {{ ucfirst($booking->payment_status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Special Requests -->
                            @if($booking->special_requests || $booking->notes)
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 14px; font-weight: 600; color: #111827; text-transform: uppercase; letter-spacing: 0.5px;">📝 Notes & Requests</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef3c7; border-radius: 6px; border: 1px solid #fcd34d; margin: 0 0 24px 0;">
                                @if($booking->special_requests)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; color: #92400e;">
                                        <strong>Special Requests:</strong><br>
                                        {{ $booking->special_requests }}
                                    </td>
                                </tr>
                                @endif
                                @if($booking->notes)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; color: #92400e; border-top: 1px solid #fcd34d;">
                                        <strong>Notes:</strong><br>
                                        {{ $booking->notes }}
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Booking ID & Reference -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f9ff; border-radius: 6px; border: 1px solid #bfdbfe; padding: 16px; margin: 0 0 0 0;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 0 0 4px 0; font-size: 12px; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Confirmation ID</p>
                                        <p style="margin: 0 0 8px 0; font-size: 18px; color: #1e3a8a; font-weight: 700;">{{ $booking->id }}</p>
                                        <p style="margin: 0 0 4px 0; font-size: 12px; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">Reference Number</p>
                                        <p style="margin: 0; font-size: 16px; color: #1e3a8a; font-weight: 700;">{{ $booking->reference_number }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f3f4f6; padding: 20px 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
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
