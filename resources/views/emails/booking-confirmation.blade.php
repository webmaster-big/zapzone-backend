<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #4CAF50;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .container {
            background: #f4f4f4;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .booking-details {
            background: white;
            padding: 20px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .detail-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: bold;
            width: 150px;
        }
        .detail-value {
            flex: 1;
        }
        .qr-section {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: white;
            border-radius: 5px;
        }
        .amount {
            font-size: 24px;
            color: #4CAF50;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Booking Confirmation</h1>
        <p>Reference: {{ $booking->reference_number }}</p>
    </div>

    <div class="container">
        <p>Dear {{ $customerName }},</p>

        <p>Thank you for your booking! Your reservation has been confirmed.</p>

        <div class="booking-details">
            <h3>Booking Details</h3>

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
</body>
</html>
