<!DOCTYPE html>
<html>
<head>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.5;
            color: #374151;
            background-color: #f9fafb;
            padding: 40px 20px;
        }
        .email-wrapper {
            max-width: 520px;
            margin: 0 auto;
        }
        .header {
            background: #1e40af;
            color: white;
            padding: 24px 32px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: -0.01em;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        .container {
            background: #ffffff;
            padding: 32px;
            border-radius: 0 0 8px 8px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 16px;
            color: #4b5563;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 24px 0 12px 0;
        }
        .booking-details {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            margin: 16px 0;
            border: 1px solid #e5e7eb;
        }
        .detail-row {
            display: flex;
            padding: 8px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        .detail-row:not(:last-child) {
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-label {
            font-weight: 500;
            width: 140px;
            color: #6b7280;
            flex-shrink: 0;
        }
        .detail-value {
            flex: 1;
            color: #111827;
        }
        .qr-section {
            text-align: center;
            margin: 24px 0;
            padding: 20px;
            background: #f9fafb;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .qr-section h3 {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }
        .qr-section p {
            margin: 8px 0 0 0;
        }
        .amount {
            font-size: 20px;
            color: #1e40af;
            font-weight: 600;
        }
        .footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }
        .footer p {
            font-size: 14px;
            color: #9ca3af;
            margin: 4px 0;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="header">
            <h1>Booking Confirmation</h1>
            <p>Reference: {{ $booking->reference_number }}</p>
        </div>

        <div class="container">
            <p>Dear {{ $customerName }},</p>

            <p>Thank you for your booking! Your reservation has been confirmed.</p>

            <div class="booking-details">
            <h3 class="section-title" style="margin-top: 0;">Booking Details</h3>

            <div class="detail-row">
                <div class="detail-label">Reference Number:</div>
                <div class="detail-value">{{ $booking->reference_number }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Package:</div>
                <div class="detail-value">{{ $packageName }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Location:</div>
                <div class="detail-value">{{ $locationName }}</div>
            </div>

            @if($booking->room)
            <div class="detail-row">
                <div class="detail-label">Room:</div>
                <div class="detail-value">{{ $roomName }}</div>
            </div>
            @endif

            <div class="detail-row">
                <div class="detail-label">Date:</div>
                <div class="detail-value">{{ \Carbon\Carbon::parse($booking->booking_date)->format('F j, Y') }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Time:</div>
                <div class="detail-value">{{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Participants:</div>
                <div class="detail-value">{{ $booking->participants }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Duration:</div>
                <div class="detail-value">{{ $booking->duration }} {{ $booking->duration_unit }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value" style="text-transform: capitalize;">{{ $booking->status }}</div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Total Amount:</div>
                <div class="detail-value">
                    <span class="amount">${{ number_format($booking->total_amount, 2) }}</span>
                </div>
            </div>

            @if($booking->amount_paid > 0)
            <div class="detail-row">
                <div class="detail-label">Amount Paid:</div>
                <div class="detail-value">${{ number_format($booking->amount_paid, 2) }}</div>
            </div>
            @endif

            @if($booking->notes)
            <div class="detail-row">
                <div class="detail-label">Notes:</div>
                <div class="detail-value">{{ $booking->notes }}</div>
            </div>
            @endif
        </div>

        <div class="qr-section">
            <h3>Your Booking QR Code</h3>
            <p>Please present this QR code at check-in:</p>
            <p style="font-size: 12px; color: #666; margin-top: 10px;">
                The QR code is attached to this email as an image file.
            </p>
        </div>

        <div class="footer">
            <p>If you have any questions, please contact us.</p>
            <p>Thank you for choosing Zap Zone!</p>
        </div>
        </div>
    </div>
</body>
</html>
