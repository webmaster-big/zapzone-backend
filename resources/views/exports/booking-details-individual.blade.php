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
            font-size: 9pt;
            line-height: 1.3;
            color: #1f2937;
        }

        .page-break {
            page-break-after: always;
        }

        .booking-page {
            padding: 25px 30px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-left {
            flex: 1;
        }

        .header-right {
            text-align: right;
        }

        .logo {
            max-height: 40px;
            max-width: 150px;
            margin-bottom: 5px;
        }

        .header h1 {
            font-size: 16pt;
            color: #374151;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .header .subtitle {
            font-size: 8pt;
            color: #6b7280;
            line-height: 1.4;
        }

        /* Booking Header Section */
        .booking-header {
            background: #f9fafb;
            padding: 12px 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .booking-header .ref-number {
            font-size: 11pt;
            font-weight: 700;
            color: #111827;
        }

        .booking-header .booking-date {
            font-size: 8pt;
            color: #6b7280;
            margin-top: 2px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 7pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-confirmed { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-checked-in { background: #e0e7ff; color: #3730a3; }

        /* Two Column Layout */
        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Details Section */
        .details-section {
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 10pt;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
        }

        .detail-item {
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 10px;
            font-size: 8pt;
        }

        .detail-label {
            font-weight: 600;
            color: #6b7280;
        }

        .detail-value {
            color: #111827;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        table th {
            background: #f9fafb;
            padding: 6px 8px;
            text-align: left;
            font-size: 7pt;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 8pt;
            color: #374151;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        /* Payment Summary */
        .payment-summary {
            background: #f9fafb;
            padding: 10px 12px;
            margin-top: 8px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 8pt;
        }

        .payment-row.balance {
            color: #dc2626;
            font-weight: 600;
        }

        .payment-row.total {
            font-weight: 700;
            border-top: 1px solid #d1d5db;
            margin-top: 5px;
            padding-top: 6px;
        }

        /* Info Box */
        .info-box {
            background: #f9fafb;
            padding: 8px 10px;
            margin: 8px 0;
            font-size: 8pt;
        }

        .info-box strong {
            color: #374151;
            display: block;
            margin-bottom: 3px;
        }

        .info-box p {
            color: #6b7280;
            margin: 0;
        }

        /* Footer */
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 7pt;
            color: #9ca3af;
        }

        /* Full Width Sections */
        .full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body>
    @foreach($bookings as $index => $booking)
    <div class="booking-page">
        <!-- Report Header (only on first page) -->
        @if($index === 0)
        <div class="header">
            <div class="header-left">
                @if(isset($companyLogo))
                    <img src="{{ $companyLogo }}" alt="Company Logo" class="logo">
                @endif
                <h1>Booking Details Report</h1>
                <div class="subtitle">
                    <strong>Period:</strong>
                    @if($periodType === 'today')
                        Today ({{ \Carbon\Carbon::parse($dateRange['start'])->format('M j, Y') }})
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
            <div class="header-right">
                <div style="font-size: 8pt; color: #9ca3af;">
                    Generated on<br>{{ \Carbon\Carbon::now()->format('M j, Y g:i A') }}
                </div>
            </div>
        </div>
        @endif

        <!-- Booking Header -->
        <div class="booking-header">
            <div>
                <div class="ref-number">{{ $booking->reference_number }}</div>
                <div class="booking-date">
                    {{ \Carbon\Carbon::parse($booking->booking_date)->format('l, M j, Y') }}
                    at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                </div>
                <div style="font-size: 7pt; color: #9ca3af; margin-top: 2px;">
                    Created: {{ $booking->created_at ? $booking->created_at->format('M j, Y g:i A') : 'N/A' }}
                </div>
            </div>
            <span class="status-badge status-{{ $booking->status }}">
                {{ ucfirst($booking->status) }}
            </span>
        </div>

        <!-- Two Column Content -->
        <div class="content-wrapper">
            <!-- Left Column -->
            <div>
                <!-- Customer Information -->
                <div class="details-section">
                    <div class="section-title">Customer Information</div>
                    <div class="details-grid">
                        <div class="detail-item">
                            <span class="detail-label">Name</span>
                            <span class="detail-value">
                                @if($booking->customer)
                                    {{ $booking->customer->first_name }} {{ $booking->customer->last_name }}
                                @else
                                    {{ $booking->guest_name }}
                                @endif
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email</span>
                            <span class="detail-value">
                                {{ $booking->customer?->email ?? $booking->guest_email }}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value">
                                {{ $booking->customer?->phone ?? $booking->guest_phone ?? 'N/A' }}
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Participants</span>
                            <span class="detail-value">{{ $booking->participants }}</span>
                        </div>
                    </div>
                </div>

                <!-- Package & Location -->
                <div class="details-section">
                    <div class="section-title">Package & Location</div>
                    <div class="details-grid">
                        <div class="detail-item">
                            <span class="detail-label">Package</span>
                            <span class="detail-value">{{ $booking->package?->name ?? 'N/A' }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Duration</span>
                            <span class="detail-value">{{ $booking->duration }} {{ $booking->duration_unit }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Location</span>
                            <span class="detail-value">{{ $booking->location?->name ?? 'N/A' }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Space</span>
                            <span class="detail-value">{{ $booking->room?->name ?? 'Not assigned' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Guest of Honor -->
                @if($booking->guest_of_honor_name)
                <div class="info-box">
                    <strong>Guest of Honor</strong>
                    <p>{{ $booking->guest_of_honor_name }}@if($booking->guest_of_honor_age), Age: {{ $booking->guest_of_honor_age }}@endif</p>
                </div>
                @endif

                <!-- Payment Summary -->
                <div class="details-section">
                    <div class="section-title">Payment</div>
                    <div class="payment-summary">
                        <div class="payment-row">
                            <span>Total Amount</span>
                            <span><strong>${{ number_format($booking->total_amount, 2) }}</strong></span>
                        </div>
                        <div class="payment-row">
                            <span>Amount Paid</span>
                            <span>${{ number_format($booking->amount_paid ?? 0, 2) }}</span>
                        </div>
                        @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                        <div class="payment-row balance">
                            <span>Balance Due</span>
                            <span>${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</span>
                        </div>
                        @endif
                        <div class="payment-row total">
                            <span>Status</span>
                            <span>{{ ucfirst($booking->payment_status) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div>
                <!-- Attractions -->
                @if($booking->attractions->count() > 0)
                <div class="details-section">
                    <div class="section-title">Attractions</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
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
                </div>
                @endif

                <!-- Add-ons -->
                @if($booking->addOns->count() > 0)
                <div class="details-section">
                    <div class="section-title">Add-ons</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
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
                </div>
                @endif

                <!-- Special Requests & Notes -->
                @if($booking->special_requests || $booking->notes)
                <div class="details-section">
                    <div class="section-title">Notes</div>
                    @if($booking->special_requests)
                    <div class="info-box">
                        <strong>Special Requests</strong>
                        <p>{{ $booking->special_requests }}</p>
                    </div>
                    @endif
                    @if($booking->notes)
                    <div class="info-box">
                        <strong>Internal Notes</strong>
                        <p>{{ $booking->notes }}</p>
                    </div>
                    @endif
                </div>
                @endif
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            Booking {{ $index + 1 }} of {{ $bookings->count() }}
        </div>
    </div>

    @if($index < $bookings->count() - 1)
    <div class="page-break"></div>
    @endif
    @endforeach
</body>
</html>
