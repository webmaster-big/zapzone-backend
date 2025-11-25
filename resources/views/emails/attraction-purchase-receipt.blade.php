<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Receipt</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #4CAF50;
            margin: 0;
            font-size: 28px;
        }
        .receipt-info {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #555;
        }
        .value {
            color: #333;
            text-align: right;
        }
        .total-section {
            background-color: #4CAF50;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            margin: 20px 0;
            text-align: center;
        }
        .total-section h2 {
            margin: 0;
            font-size: 24px;
        }
        .qr-code-section {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background-color: #f0f8ff;
            border-radius: 5px;
        }
        .qr-code-section img {
            max-width: 200px;
            height: auto;
            border: 3px solid #4CAF50;
            border-radius: 5px;
            padding: 10px;
            background-color: white;
        }
        .qr-instructions {
            font-size: 14px;
            color: #666;
            margin-top: 15px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pending {
            background-color: #FFF3CD;
            color: #856404;
        }
        .status-completed {
            background-color: #D4EDDA;
            color: #155724;
        }
        .status-cancelled {
            background-color: #F8D7DA;
            color: #721C24;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #666;
        }
        .customer-info {
            background-color: #e8f5e9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🎟️ Purchase Receipt</h1>
            <p>Thank you for your purchase!</p>
        </div>

        <!-- Customer Information -->
        <div class="customer-info">
            <h3 style="margin-top: 0; color: #4CAF50;">Customer Information</h3>
            @if($purchase->customer)
                <p style="margin: 5px 0;"><strong>Name:</strong> {{ $purchase->customer->first_name }} {{ $purchase->customer->last_name }}</p>
                <p style="margin: 5px 0;"><strong>Email:</strong> {{ $purchase->customer->email }}</p>
                @if($purchase->customer->phone)
                    <p style="margin: 5px 0;"><strong>Phone:</strong> {{ $purchase->customer->phone }}</p>
                @endif
            @else
                <p style="margin: 5px 0;"><strong>Name:</strong> {{ $purchase->guest_name }}</p>
                <p style="margin: 5px 0;"><strong>Email:</strong> {{ $purchase->guest_email }}</p>
                @if($purchase->guest_phone)
                    <p style="margin: 5px 0;"><strong>Phone:</strong> {{ $purchase->guest_phone }}</p>
                @endif
            @endif
        </div>

        <!-- Receipt Details -->
        <div class="receipt-info">
            <h3 style="margin-top: 0; color: #4CAF50;">Purchase Details</h3>

            <div class="info-row">
                <span class="label">Order Number:</span>
                <span class="value">#{{ str_pad($purchase->id, 6, '0', STR_PAD_LEFT) }}</span>
            </div>

            <div class="info-row">
                <span class="label">Purchase Date:</span>
                <span class="value">{{ $purchase->purchase_date->format('F d, Y') }}</span>
            </div>

            <div class="info-row">
                <span class="label">Attraction:</span>
                <span class="value">{{ $purchase->attraction->name }}</span>
            </div>

            <div class="info-row">
                <span class="label">Category:</span>
                <span class="value">{{ ucfirst($purchase->attraction->category) }}</span>
            </div>

            <div class="info-row">
                <span class="label">Quantity:</span>
                <span class="value">{{ $purchase->quantity }} {{ $purchase->quantity > 1 ? 'tickets' : 'ticket' }}</span>
            </div>

            <div class="info-row">
                <span class="label">Price per ticket:</span>
                <span class="value">${{ number_format($purchase->attraction->price, 2) }}</span>
            </div>

            <div class="info-row">
                <span class="label">Payment Method:</span>
                <span class="value">{{ ucfirst(str_replace('_', ' ', $purchase->payment_method)) }}</span>
            </div>

            <div class="info-row">
                <span class="label">Status:</span>
                <span class="value">
                    <span class="status-badge status-{{ $purchase->status }}">
                        {{ ucfirst($purchase->status) }}
                    </span>
                </span>
            </div>

            @if($purchase->notes)
            <div class="info-row">
                <span class="label">Notes:</span>
                <span class="value">{{ $purchase->notes }}</span>
            </div>
            @endif
        </div>

        <!-- Total Amount -->
        <div class="total-section">
            <h2>Total Amount: ${{ number_format($purchase->total_amount, 2) }}</h2>
        </div>

        <!-- QR Code Section -->
        @if($qrCodePath && file_exists($qrCodePath))
        <div class="qr-code-section">
            <h3 style="color: #4CAF50; margin-top: 0;">Your Ticket QR Code</h3>
            <img src="{{ $message->embed($qrCodePath) }}" alt="Ticket QR Code">
            <p class="qr-instructions">
                📱 Show this QR code at the entrance<br>
                or present it from the attachment
            </p>
        </div>
        @endif

        <!-- Attraction Details -->
        @if($purchase->attraction->description)
        <div style="margin: 20px 0; padding: 15px; background-color: #f9f9f9; border-radius: 5px;">
            <h3 style="color: #4CAF50; margin-top: 0;">About {{ $purchase->attraction->name }}</h3>
            <p style="margin: 0;">{{ $purchase->attraction->description }}</p>

            @if($purchase->attraction->duration)
                <p style="margin: 10px 0 0 0;">
                    <strong>Duration:</strong> {{ $purchase->attraction->duration }} {{ $purchase->attraction->duration_unit }}
                </p>
            @endif
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Thank you for choosing our attractions!</p>
            <p>If you have any questions, please contact our support team.</p>
            <p style="margin-top: 15px; color: #999;">
                This is an automated email. Please do not reply to this message.
            </p>
        </div>
    </div>
</body>
</html>
