<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking Details Report - List View</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.3;
            color: #1f2937;
            padding: 20px 25px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-left {
            flex: 1;
        }

        .header-right {
            text-align: right;
        }

        .logo {
            max-height: 35px;
            max-width: 140px;
            margin-bottom: 5px;
        }

        .header h1 {
            font-size: 14pt;
            color: #374151;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .header .subtitle {
            font-size: 7pt;
            color: #6b7280;
            line-height: 1.4;
        }

        .summary-stats {
            display: flex;
            justify-content: space-around;
            margin: 12px 0;
            padding: 10px;
            background: #f9fafb;
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            font-size: 7pt;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 12pt;
            color: #374151;
            font-weight: 700;
            margin-top: 3px;
        }

        /* Booking Card */
        .booking-card {
            border: 1px solid #e5e7eb;
            padding: 10px;
            margin-bottom: 10px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .booking-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 8px;
            border-bottom: 1px solid #f3f4f6;
            margin-bottom: 8px;
        }

        .booking-ref {
            font-size: 10pt;
            font-weight: 700;
            color: #111827;
        }

        .booking-date {
            font-size: 7pt;
            color: #6b7280;
            margin-top: 2px;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 6pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-confirmed { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-checked-in { background: #e0e7ff; color: #3730a3; }

        .booking-body {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            font-size: 7pt;
        }

        .info-group {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            color: #6b7280;
            font-weight: 600;
            font-size: 6pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .info-value {
            color: #111827;
            font-size: 7pt;
        }

        .booking-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #f3f4f6;
            font-size: 7pt;
        }

        .payment-info {
            font-weight: 600;
        }

        .payment-paid {
            color: #16a34a;
        }

        .payment-partial {
            color: #ea580c;
        }

        .payment-pending {
            color: #dc2626;
        }

        .extras-badge {
            display: inline-block;
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 6pt;
            color: #6b7280;
            margin-right: 4px;
        }

        .guest-honor-box {
            margin-top: 8px;
            padding: 6px;
            background: #fef3c7;
            font-size: 7pt;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }

        /* Footer */
        .report-footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 7pt;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <!-- Report Header -->
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
            <div style="font-size: 7pt; color: #9ca3af;">
                Generated on<br>{{ \Carbon\Carbon::now()->format('M j, Y g:i A') }}
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="summary-stats">
        <div class="stat-item">
            <div class="stat-label">Bookings</div>
            <div class="stat-value">{{ $bookings->count() }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Revenue</div>
            <div class="stat-value">${{ number_format($bookings->sum('total_amount'), 2) }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Paid</div>
            <div class="stat-value">${{ number_format($bookings->sum('amount_paid'), 2) }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Participants</div>
            <div class="stat-value">{{ $bookings->sum('participants') }}</div>
        </div>
    </div>

    <!-- Bookings List -->
    @php
        $bookingsPerPage = 6;
        $chunks = $bookings->chunk($bookingsPerPage);
    @endphp

    @foreach($chunks as $chunkIndex => $chunk)
        @foreach($chunk as $booking)
        <div class="booking-card">
            <!-- Card Header -->
            <div class="booking-card-header">
                <div>
                    <div class="booking-ref">{{ $booking->reference_number }}</div>
                    <div class="booking-date">
                        {{ \Carbon\Carbon::parse($booking->booking_date)->format('l, M j, Y') }}
                        at {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                    </div>
                    <div style="font-size: 6pt; color: #9ca3af; margin-top: 1px;">
                        Created: {{ $booking->created_at ? $booking->created_at->format('M j, Y g:i A') : 'N/A' }}
                    </div>
                </div>
                <span class="status-badge status-{{ $booking->status }}">
                    {{ ucfirst($booking->status) }}
                </span>
            </div>

            <!-- Card Body -->
            <div class="booking-body">
                <div class="info-group">
                    <span class="info-label">Customer</span>
                    <span class="info-value">
                        @if($booking->customer)
                            {{ $booking->customer->first_name }} {{ $booking->customer->last_name }}
                        @else
                            {{ $booking->guest_name }}
                        @endif
                    </span>
                </div>

                <div class="info-group">
                    <span class="info-label">Contact</span>
                    <span class="info-value">
                        {{ $booking->customer?->email ?? $booking->guest_email }}
                        <br>
                        {{ $booking->customer?->phone ?? $booking->guest_phone ?? 'N/A' }}
                    </span>
                </div>

                <div class="info-group">
                    <span class="info-label">Package</span>
                    <span class="info-value">{{ $booking->package?->name ?? 'N/A' }}</span>
                </div>

                <div class="info-group">
                    <span class="info-label">Location</span>
                    <span class="info-value">{{ $booking->location?->name ?? 'N/A' }}</span>
                </div>

                <div class="info-group">
                    <span class="info-label">Space</span>
                    <span class="info-value">{{ $booking->room?->name ?? 'Not assigned' }}</span>
                </div>

                <div class="info-group">
                    <span class="info-label">Participants</span>
                    <span class="info-value">{{ $booking->participants }}</span>
                </div>
            </div>

            <!-- Guest of Honor -->
            @if($booking->guest_of_honor_name)
            <div class="guest-honor-box">
                <strong>Guest of Honor:</strong> {{ $booking->guest_of_honor_name }}@if($booking->guest_of_honor_age), Age: {{ $booking->guest_of_honor_age }}@endif
            </div>
            @endif

            <!-- Card Footer -->
            <div class="booking-footer">
                <div>
                    @if($booking->attractions->count() > 0)
                        <span class="extras-badge">{{ $booking->attractions->count() }} Attractions</span>
                    @endif
                    @if($booking->addOns->count() > 0)
                        <span class="extras-badge">{{ $booking->addOns->count() }} Add-ons</span>
                    @endif
                </div>

                <div class="payment-info payment-{{ $booking->payment_status }}">
                    ${{ number_format($booking->amount_paid ?? 0, 2) }} / ${{ number_format($booking->total_amount, 2) }}
                    @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                        <span style="color: #dc2626;">
                            (Bal: ${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }})
                        </span>
                    @else
                        <span style="color: #16a34a;">✓ Paid</span>
                    @endif
                </div>
            </div>
        </div>
        @endforeach

        @if($chunkIndex < $chunks->count() - 1)
        <div class="page-break"></div>
        @endif
    @endforeach

    <!-- Report Footer -->
    <div class="report-footer">
        Total: {{ $bookings->count() }} bookings
    </div>
</body>
</html>
