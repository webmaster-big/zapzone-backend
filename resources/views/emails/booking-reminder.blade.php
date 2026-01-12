<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Reminder</title>
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
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Booking Reminder</h1>
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
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Dear {{ $customerName }},</p>

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">This is a friendly reminder that your booking is scheduled for <strong>tomorrow</strong>. We look forward to seeing you!</p>

                            <!-- Reminder Alert Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef3c7; border-radius: 6px; border: 1px solid #f59e0b; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <p style="margin: 0 0 4px 0; font-size: 15px; font-weight: 600; color: #92400e;">Your appointment is tomorrow</p>
                                        <p style="margin: 0; font-size: 14px; color: #92400e;">
                                            {{ \Carbon\Carbon::parse($booking->booking_date)->format('l, F j, Y') }} at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Booking Details -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Booking Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Reference:</td>
                                                <td style="color: #111827; font-weight: 600;">{{ $booking->reference_number }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($booking->package)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Package:</td>
                                                <td style="color: #111827;">{{ $booking->package->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Date:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_date)->format('l, F j, Y') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Time:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Duration:</td>
                                                <td style="color: #111827;">{{ $booking->duration }} {{ $booking->duration_unit }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Participants:</td>
                                                <td style="color: #111827;">{{ $booking->participants }} {{ $booking->participants > 1 ? 'people' : 'person' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Guest of Honor (if applicable) -->
                            @if($booking->guest_of_honor_name)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Guest of Honor</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fdf2f8; border-radius: 6px; border: 1px solid #f9a8d4; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <p style="margin: 0; color: #9d174d;">
                                            <strong>{{ $booking->guest_of_honor_name }}</strong>
                                            @if($booking->guest_of_honor_age)
                                                ({{ $booking->guest_of_honor_age }} years old)
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Location Details -->
                            @if($booking->location)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f9ff; border-radius: 6px; border: 1px solid #0ea5e9; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #0369a1;">{{ $booking->location->name }}</p>
                                        @if($booking->location->address)
                                        <p style="margin: 0; font-size: 14px; color: #0369a1;">
                                            {{ $booking->location->address }}
                                            @if($booking->location->city || $booking->location->state || $booking->location->zip)
                                                <br>{{ $booking->location->city }}{{ $booking->location->city && $booking->location->state ? ', ' : '' }}{{ $booking->location->state }} {{ $booking->location->zip }}
                                            @endif
                                        </p>
                                        @endif
                                        @if($booking->location->phone)
                                        <p style="margin: 8px 0 0 0; font-size: 14px; color: #0369a1;">
                                            Phone: {{ $booking->location->phone }}
                                        </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Space Assignment -->
                            @if($booking->room)
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Space:</td>
                                                <td style="color: #111827; font-weight: 600;">{{ $booking->room->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Payment Summary -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Payment Summary</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280;">Total Amount:</td>
                                                <td style="color: #111827; text-align: right; font-weight: 600;">${{ number_format($booking->total_amount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280;">Amount Paid:</td>
                                                <td style="color: #10b981; text-align: right; font-weight: 600;">${{ number_format($booking->amount_paid ?? 0, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280;">Balance Due:</td>
                                                <td style="color: #ef4444; text-align: right; font-weight: 600;">${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @else
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td colspan="2" style="color: #10b981; text-align: center; font-weight: 600;">Fully Paid</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Special Requests -->
                            @if($booking->special_requests)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Special Requests</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                        {{ $booking->special_requests }}
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Tips Section -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #ecfdf5; border-radius: 6px; border: 1px solid #10b981; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <p style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #059669;">Tips for Your Visit:</p>
                                        <ul style="margin: 0; padding: 0 0 0 20px; color: #047857; font-size: 13px;">
                                            <li style="margin-bottom: 4px;">Please arrive 15 minutes before your scheduled time</li>
                                            <li style="margin-bottom: 4px;">Bring your booking confirmation or reference number</li>
                                            <li style="margin-bottom: 4px;">Wear comfortable clothing and closed-toe shoes</li>
                                            <li>All participants must sign a waiver upon arrival</li>
                                        </ul>
                                    </td>
                                </tr>
                            </table>

                            <!-- Footer Message -->
                            <p style="margin: 24px 0 0 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">We can't wait to see you tomorrow! If you have any questions or need to make changes to your booking, please contact us.</p>

                            <p style="margin: 16px 0 0 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">
                                Best regards,<br>
                                <strong>{{ $booking->location->company->name ?? 'The Team' }}</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="text-align: center; padding: 24px 16px;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                This is an automated reminder for your booking.<br>
                                Reference: {{ $booking->reference_number }}
                            </p>
                            @if($booking->location)
                            <p style="margin: 8px 0 0 0; font-size: 12px; color: #9ca3af;">
                                {{ $booking->location->name }}
                                @if($booking->location->phone) | {{ $booking->location->phone }}@endif
                                @if($booking->location->email) | {{ $booking->location->email }}@endif
                            </p>
                            @endif
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
