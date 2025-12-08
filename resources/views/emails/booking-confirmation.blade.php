<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation</title>
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
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Booking Confirmation</h1>
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

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Thank you for your booking! Your reservation has been confirmed.</p>

                            <!-- Customer Information -->
                            @if($booking->customer)
                            <h3 style="margin: 16px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Customer Information</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Name:</td>
                                                <td style="color: #111827;">{{ $booking->customer->first_name }} {{ $booking->customer->last_name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                <td style="color: #111827;">{{ $booking->customer->email }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($booking->customer->phone)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                <td style="color: #111827;">{{ $booking->customer->phone }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($booking->customer->address)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Address:</td>
                                                <td style="color: #111827;">{{ $booking->customer->address }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($booking->customer->city || $booking->customer->state || $booking->customer->zip)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">City/State/ZIP:</td>
                                                <td style="color: #111827;">
                                                    {{ $booking->customer->city }}{{ $booking->customer->city && ($booking->customer->state || $booking->customer->zip) ? ', ' : '' }}{{ $booking->customer->state }} {{ $booking->customer->zip }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Booking Details -->
                            <h3 style="margin: 16px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Booking Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Reference Number:</td>
                                                <td style="color: #111827;">{{ $booking->reference_number }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
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
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Location:</td>
                                                <td style="color: #111827;">{{ $locationName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($booking->room)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Room:</td>
                                                <td style="color: #111827;">{{ $roomName }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Date:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_date)->format('F j, Y') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Time:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</td>
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
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Status:</td>
                                                <td style="color: #111827; text-transform: capitalize;">{{ $booking->status }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Total Amount:</td>
                                                <td style="color: #111827;">
                                                    <span style="font-size: 20px; color: #1e40af; font-weight: 600;">${{ number_format($booking->total_amount, 2) }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($booking->amount_paid > 0)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Amount Paid:</td>
                                                <td style="color: #111827;">${{ number_format($booking->amount_paid, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                @if($booking->notes)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Notes:</td>
                                                <td style="color: #111827;">{{ $booking->notes }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- QR Section -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 24px 0;">
                                <tr>
                                    <td style="padding: 20px; text-align: center;">
                                        <h3 style="margin: 0 0 8px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Your Booking QR Code</h3>
                                        <p style="margin: 8px 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Please present this QR code at check-in:</p>

                                        <!-- Inline QR Code Image -->
                                        @if(isset($qrCodeCid))
                                        <div style="background-color: #ffffff; padding: 20px; border-radius: 8px; display: inline-block; margin: 12px 0;">
                                            <img src="cid:{{ $qrCodeCid }}" alt="Booking QR Code" style="max-width: 250px; width: 100%; height: auto; display: block; margin: 0 auto;" />
                                        </div>
                                        @endif

                                        <p style="margin: 16px 0 0 0; padding: 0; font-size: 12px; line-height: 1.6; color: #6b7280;">Reference: {{ $booking->reference_number }}</p>
                                        <p style="margin: 4px 0 0 0; padding: 0; font-size: 11px; line-height: 1.6; color: #9ca3af;">The QR code is also attached as a separate file for your convenience.</p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Footer -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">If you have any questions, please contact us.</p>
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">Thank you for choosing Zap Zone!</p>
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
