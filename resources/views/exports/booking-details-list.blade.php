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
            font-size: 10pt;
            line-height: 1.3;
            color: #333;
            padding: 20px;
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #2563eb;
        }

        .header h1 {
            font-size: 22pt;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .header .subtitle {
            font-size: 10pt;
            color: #64748b;
            margin-top: 5px;
        }

        .summary-stats {
            display: flex;
            justify-content: space-around;
            margin: 15px 0;
            padding: 12px;
            background: #f8fafc;
            border-radius: 6px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-label {
            font-size: 9pt;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-value {
            font-size: 16pt;
            color: #1e40af;
            font-weight: 700;
            margin-top: 3px;
        }

        /* Booking Card */
        .booking-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .booking-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 12px;
        }

        .booking-ref {
            font-size: 12pt;
            font-weight: 700;
            color: #1e40af;
        }

        .booking-date {
            font-size: 10pt;
            color: #475569;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 8pt;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-checked-in { background: #e0e7ff; color: #3730a3; }

        .booking-body {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            font-size: 9pt;
        }

        .info-group {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            color: #64748b;
            font-weight: 600;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .info-value {
            color: #0f172a;
            font-size: 9pt;
        }

        .booking-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
            font-size: 9pt;
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
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 8pt;
            color: #475569;
            margin-right: 5px;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }

        /* Footer */
        .report-footer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px solid #e2e8f0;
            text-align: center;
            font-size: 9pt;
            color: #64748b;
        }
    </style>
</head>
<body>
    <!-- Report Header -->
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

    <!-- Summary Statistics -->
    <div class="summary-stats">
        <div class="stat-item">
            <div class="stat-label">Total Bookings</div>
            <div class="stat-value">{{ $bookings->count() }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">${{ number_format($bookings->sum('total_amount'), 2) }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Total Paid</div>
            <div class="stat-value">${{ number_format($bookings->sum('amount_paid'), 2) }}</div>
        </div>
        <div class="stat-item">
            <div class="stat-label">Total Participants</div>
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
                    <span class="info-label">Room/Space</span>
                    <span class="info-value">{{ $booking->room?->name ?? 'Not assigned' }}</span>
                </div>

                <div class="info-group">
                    <span class="info-label">Participants</span>
                    <span class="info-value">{{ $booking->participants }} people</span>
                </div>
            </div>

            <!-- Guest of Honor -->
            @if($booking->guest_of_honor_name)
            <div style="margin-top: 10px; padding: 8px; background: #fef3c7; border-radius: 4px; font-size: 9pt;">
                <strong>Guest of Honor:</strong> {{ $booking->guest_of_honor_name }}
                @if($booking->guest_of_honor_age)
                    (Age: {{ $booking->guest_of_honor_age }})
                @endif
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
                            (Balance: ${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }})
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
        Generated on {{ \Carbon\Carbon::now()->format('F j, Y \a\t g:i A') }}
        <br>
        Total: {{ $bookings->count() }} bookings
    </div>
</body>
</html>
