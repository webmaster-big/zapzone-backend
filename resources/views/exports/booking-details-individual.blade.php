<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking Details Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #333;
        }

        .page-break {
            page-break-after: always;
        }

        .booking-page {
            padding: 30px;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #2563eb;
        }

        .header h1 {
            font-size: 24pt;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .header .subtitle {
            font-size: 11pt;
            color: #64748b;
            margin-top: 5px;
        }

        /* Booking Header Section */
        .booking-header {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2563eb;
        }

        .booking-header h2 {
            font-size: 16pt;
            color: #1e40af;
            margin-bottom: 8px;
        }

        .booking-header .ref-number {
            font-size: 14pt;
            font-weight: bold;
            color: #0f172a;
        }

        .booking-header .meta {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 10pt;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-checked-in { background: #e0e7ff; color: #3730a3; }

        /* Details Grid */
        .details-section {
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 13pt;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e2e8f0;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 12px;
        }

        .detail-item {
            display: flex;
            padding: 8px 0;
        }

        .detail-label {
            font-weight: 600;
            color: #64748b;
            min-width: 140px;
            font-size: 10pt;
        }

        .detail-value {
            color: #0f172a;
            font-size: 10pt;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table th {
            background: #f1f5f9;
            padding: 10px;
            text-align: left;
            font-size: 10pt;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #cbd5e1;
        }

        table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10pt;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        /* Payment Summary */
        .payment-summary {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 11pt;
        }

        .payment-row.total {
            font-weight: 700;
            font-size: 13pt;
            color: #1e40af;
            border-top: 2px solid #cbd5e1;
            margin-top: 8px;
            padding-top: 12px;
        }

        .payment-row.balance {
            color: #dc2626;
            font-weight: 600;
        }

        /* Info Box */
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 12px;
            margin: 15px 0;
        }

        .info-box.warning {
            background: #fef3c7;
            border-color: #fde047;
        }

        .info-box p {
            margin: 5px 0;
            font-size: 10pt;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 9pt;
            color: #64748b;
        }
    </style>
</head>
<body>
    @foreach($bookings as $index => $booking)
    <div class="booking-page">
        <!-- Report Header (only on first page) -->
        @if($index === 0)
        <div class="header">
            <h1>{{ $companyName ?? 'Booking Details Report' }}</h1>
            <div class="subtitle">
                <strong>Period:</strong>
                @if($periodType === 'today')
                    Today ({{ \Carbon\Carbon::parse($dateRange['start'])->format('F j, Y') }})
                @elseif($periodType === 'weekly')
                    Week {{ \Carbon\Carbon::parse($dateRange['start'])->format('W, Y') }}
                    ({{ \Carbon\Carbon::parse($dateRange['start'])->format('M j') }} - {{ \Carbon\Carbon::parse($dateRange['end'])->format('M j, Y') }})
                @elseif($periodType === 'monthly')
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('F Y') }}
                @else
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('M j, Y') }} - {{ \Carbon\Carbon::parse($dateRange['end'])->format('M j, Y') }}
                @endif
                <br>
                <strong>Package(s):</strong> {{ $packageNames }}
            </div>
        </div>
        @endif

        <!-- Booking Header -->
        <div class="booking-header">
            <h2>Booking {{ $index + 1 }} of {{ $bookings->count() }}</h2>
            <div class="ref-number">{{ $booking->reference_number }}</div>
            <div class="meta">
                <span>
                    <strong>Date:</strong> {{ \Carbon\Carbon::parse($booking->booking_date)->format('l, F j, Y') }}
                    at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                </span>
                <span class="status-badge status-{{ $booking->status }}">
                    {{ ucfirst($booking->status) }}
                </span>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="details-section">
            <div class="section-title">Customer Information</div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value">
                        @if($booking->customer)
                            {{ $booking->customer->first_name }} {{ $booking->customer->last_name }}
                        @else
                            {{ $booking->guest_name }}
                        @endif
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">
                        {{ $booking->customer?->email ?? $booking->guest_email }}
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">
                        {{ $booking->customer?->phone ?? $booking->guest_phone ?? 'N/A' }}
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Participants:</span>
                    <span class="detail-value">{{ $booking->participants }}</span>
                </div>
            </div>
        </div>

        <!-- Package & Location -->
        <div class="details-section">
            <div class="section-title">Package & Location Details</div>
            <div class="details-grid">
                <div class="detail-item">
                    <span class="detail-label">Package:</span>
                    <span class="detail-value">{{ $booking->package?->name ?? 'N/A' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Duration:</span>
                    <span class="detail-value">{{ $booking->duration }} {{ $booking->duration_unit }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Location:</span>
                    <span class="detail-value">{{ $booking->location?->name ?? 'N/A' }}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Room/Space:</span>
                    <span class="detail-value">{{ $booking->room?->name ?? 'Not assigned' }}</span>
                </div>
            </div>
        </div>

        <!-- Guest of Honor (if applicable) -->
        @if($booking->guest_of_honor_name)
        <div class="info-box">
            <div class="section-title" style="border: none; margin-bottom: 5px;">Guest of Honor</div>
            <p><strong>Name:</strong> {{ $booking->guest_of_honor_name }}</p>
            @if($booking->guest_of_honor_age)
                <p><strong>Age:</strong> {{ $booking->guest_of_honor_age }}</p>
            @endif
        </div>
        @endif

        <!-- Attractions & Add-ons -->
        @if($booking->attractions->count() > 0 || $booking->addOns->count() > 0)
        <div class="details-section">
            <div class="section-title">Attractions & Add-ons</div>

            @if($booking->attractions->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>Attraction</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($booking->attractions as $attraction)
                    <tr>
                        <td>{{ $attraction->name }}</td>
                        <td>{{ $attraction->pivot->quantity }}</td>
                        <td>${{ number_format($attraction->pivot->price_at_booking, 2) }}</td>
                        <td>${{ number_format($attraction->pivot->quantity * $attraction->pivot->price_at_booking, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif

            @if($booking->addOns->count() > 0)
            <table style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th>Add-on</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($booking->addOns as $addon)
                    <tr>
                        <td>{{ $addon->name }}</td>
                        <td>{{ $addon->pivot->quantity }}</td>
                        <td>${{ number_format($addon->pivot->price_at_booking, 2) }}</td>
                        <td>${{ number_format($addon->pivot->quantity * $addon->pivot->price_at_booking, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @endif

        <!-- Payment Summary -->
        <div class="details-section">
            <div class="section-title">Payment Summary</div>
            <div class="payment-summary">
                <div class="payment-row">
                    <span>Total Amount:</span>
                    <span><strong>${{ number_format($booking->total_amount, 2) }}</strong></span>
                </div>
                <div class="payment-row">
                    <span>Amount Paid:</span>
                    <span>${{ number_format($booking->amount_paid ?? 0, 2) }}</span>
                </div>
                @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                <div class="payment-row balance">
                    <span>Balance Due:</span>
                    <span>${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</span>
                </div>
                @endif
                <div class="payment-row">
                    <span>Payment Status:</span>
                    <span><strong>{{ ucfirst($booking->payment_status) }}</strong></span>
                </div>
                <div class="payment-row">
                    <span>Payment Method:</span>
                    <span>{{ ucfirst($booking->payment_method ?? 'N/A') }}</span>
                </div>
            </div>
        </div>

        <!-- Special Requests & Notes -->
        @if($booking->special_requests || $booking->notes)
        <div class="details-section">
            <div class="section-title">Notes & Special Requests</div>
            @if($booking->special_requests)
            <div class="info-box">
                <strong>Special Requests:</strong>
                <p>{{ $booking->special_requests }}</p>
            </div>
            @endif
            @if($booking->notes)
            <div class="info-box">
                <strong>Notes:</strong>
                <p>{{ $booking->notes }}</p>
            </div>
            @endif
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            Generated on {{ \Carbon\Carbon::now()->format('F j, Y \a\t g:i A') }}
            <br>
            Page {{ $index + 1 }} of {{ $bookings->count() }}
        </div>
    </div>

    @if($index < $bookings->count() - 1)
    <div class="page-break"></div>
    @endif
    @endforeach
</body>
</html>
