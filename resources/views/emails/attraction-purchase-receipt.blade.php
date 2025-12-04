<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Receipt</title>
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
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .header {
            text-align: center;
            background: #1e40af;
            color: white;
            padding: 24px 32px;
            border-radius: 8px 8px 0 0;
        }
        .header h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
            letter-spacing: -0.01em;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        .content {
            padding: 32px;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 12px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 24px 0 12px 0;
        }
        .info-box {
            background-color: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            margin: 16px 0;
            border: 1px solid #e5e7eb;
        }
        .info-row {
            display: flex;
            padding: 8px 0;
            font-size: 14px;
            line-height: 1.6;
        }
        .info-row:not(:last-child) {
            border-bottom: 1px solid #e5e7eb;
        }
        .label {
            font-weight: 500;
            color: #6b7280;
            width: 140px;
            flex-shrink: 0;
        }
        .value {
            color: #111827;
            flex: 1;
        }
        .total-section {
            background-color: #1e40af;
            color: white;
            padding: 16px 20px;
            border-radius: 6px;
            margin: 20px 0;
            text-align: center;
        }
        .total-section h2 {
            font-size: 18px;
            font-weight: 600;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-cancelled {
            background-color: #fee2e2;
            color: #991b1b;
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
        <div class="email-container">
            <div class="header">
                <h1>Purchase Receipt</h1>
                <p>Thank you for your purchase!</p>
            </div>

            <div class="content">
                <!-- Customer Information -->
                <h3 class="section-title" style="margin-top: 0;">Customer Information</h3>
                <div class="info-box">
                    @if($purchase->customer)
                        <div class="info-row">
                            <span class="label">Name:</span>
                            <span class="value">{{ $purchase->customer->first_name }} {{ $purchase->customer->last_name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ $purchase->customer->email }}</span>
                        </div>
                        @if($purchase->customer->phone)
                            <div class="info-row">
                                <span class="label">Phone:</span>
                                <span class="value">{{ $purchase->customer->phone }}</span>
                            </div>
                        @endif
                    @else
                        <div class="info-row">
                            <span class="label">Name:</span>
                            <span class="value">{{ $purchase->guest_name }}</span>
                        </div>
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ $purchase->guest_email }}</span>
                        </div>
                        @if($purchase->guest_phone)
                            <div class="info-row">
                                <span class="label">Phone:</span>
                                <span class="value">{{ $purchase->guest_phone }}</span>
                            </div>
                        @endif
                    @endif
                </div>

                <!-- Receipt Details -->
                <h3 class="section-title">Purchase Details</h3>
                <div class="info-box">
                    <div class="info-row">
                        <span class="label">Order Number:</span>
                        <span class="value">#{{ str_pad($purchase->id, 6, '0', STR_PAD_LEFT) }}</span>
                    </div>

                    <div class="info-row">
                        <span class="label">Purchase Date:</span>
                        <span class="value">{{ $purchase->purchase_date->format('F d, Y') }}</span>
                    </div>

                    @if($purchase->purchase_date->format('H:i:s') != '00:00:00')
                    <div class="info-row">
                        <span class="label">Purchase Time:</span>
                        <span class="value">{{ $purchase->purchase_date->format('g:i A') }}</span>
                    </div>
                    @endif

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

                    @if($purchase->discount_amount > 0)
                    <div class="info-row">
                        <span class="label">Discount:</span>
                        <span class="value">-${{ number_format($purchase->discount_amount, 2) }}</span>
                    </div>
                    @endif

                    @if($purchase->tax_amount > 0)
                    <div class="info-row">
                        <span class="label">Tax:</span>
                        <span class="value">${{ number_format($purchase->tax_amount, 2) }}</span>
                    </div>
                    @endif

                    <div class="info-row">
                        <span class="label">Payment Method:</span>
                        <span class="value">{{ ucfirst(str_replace('_', ' ', $purchase->payment_method)) }}</span>
                    </div>

                    @if($purchase->transaction_id)
                    <div class="info-row">
                        <span class="label">Transaction ID:</span>
                        <span class="value">{{ $purchase->transaction_id }}</span>
                    </div>
                    @endif

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

                <!-- Location Information -->
                @if($purchase->attraction && $purchase->attraction->location)
                <h3 class="section-title">Location Details</h3>
                <div class="info-box">
                    <div class="info-row">
                        <span class="label">Venue:</span>
                        <span class="value">{{ $purchase->attraction->location->name }}</span>
                    </div>
                    @if($purchase->attraction->location->address)
                        <div class="info-row">
                            <span class="label">Address:</span>
                            <span class="value">{{ $purchase->attraction->location->address }}</span>
                        </div>
                    @endif
                    @if($purchase->attraction->location->city)
                        <div class="info-row">
                            <span class="label">City:</span>
                            <span class="value">{{ $purchase->attraction->location->city }}, {{ $purchase->attraction->location->state }} {{ $purchase->attraction->location->zip_code }}</span>
                        </div>
                    @endif
                    @if($purchase->attraction->location->country)
                        <div class="info-row">
                            <span class="label">Country:</span>
                            <span class="value">{{ $purchase->attraction->location->country }}</span>
                        </div>
                    @endif
                    @if($purchase->attraction->location->phone)
                        <div class="info-row">
                            <span class="label">Phone:</span>
                            <span class="value">{{ $purchase->attraction->location->phone }}</span>
                        </div>
                    @endif
                    @if($purchase->attraction->location->email)
                        <div class="info-row">
                            <span class="label">Email:</span>
                            <span class="value">{{ $purchase->attraction->location->email }}</span>
                        </div>
                    @endif
                    @if($purchase->attraction->location->website)
                        <div class="info-row">
                            <span class="label">Website:</span>
                            <span class="value"><a href="{{ $purchase->attraction->location->website }}" style="color: #1e40af; text-decoration: none;">{{ $purchase->attraction->location->website }}</a></span>
                        </div>
                    @endif
                </div>
                @endif

                <!-- Attraction Details -->
                @if($purchase->attraction->description)
                <h3 class="section-title">About {{ $purchase->attraction->name }}</h3>
                <div class="info-box">
                    <p style="margin: 0 0 12px 0; color: #4b5563;">{{ $purchase->attraction->description }}</p>

                    @if($purchase->attraction->duration || $purchase->attraction->min_age || $purchase->attraction->max_capacity || $purchase->attraction->difficulty_level)
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                        @if($purchase->attraction->duration)
                            <div class="info-row">
                                <span class="label">Duration:</span>
                                <span class="value">{{ $purchase->attraction->duration }} {{ $purchase->attraction->duration_unit }}</span>
                            </div>
                        @endif
                        @if($purchase->attraction->min_age)
                            <div class="info-row">
                                <span class="label">Minimum Age:</span>
                                <span class="value">{{ $purchase->attraction->min_age }} years</span>
                            </div>
                        @endif
                        @if($purchase->attraction->max_age)
                            <div class="info-row">
                                <span class="label">Maximum Age:</span>
                                <span class="value">{{ $purchase->attraction->max_age }} years</span>
                            </div>
                        @endif
                        @if($purchase->attraction->max_capacity)
                            <div class="info-row">
                                <span class="label">Max Capacity:</span>
                                <span class="value">{{ $purchase->attraction->max_capacity }} people</span>
                            </div>
                        @endif
                        @if($purchase->attraction->difficulty_level)
                            <div class="info-row">
                                <span class="label">Difficulty Level:</span>
                                <span class="value">{{ ucfirst($purchase->attraction->difficulty_level) }}</span>
                            </div>
                        @endif
                        @if($purchase->attraction->is_indoor !== null)
                            <div class="info-row">
                                <span class="label">Type:</span>
                                <span class="value">{{ $purchase->attraction->is_indoor ? 'Indoor' : 'Outdoor' }}</span>
                            </div>
                        @endif
                    </div>
                    @endif
                </div>
                @endif

                <!-- Footer -->
                <div class="footer">
                    <p>Thank you for choosing our attractions!</p>
                    <p>If you have any questions, please contact our support team.</p>
                    <p style="margin-top: 8px;">
                        This is an automated email. Please do not reply to this message.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
