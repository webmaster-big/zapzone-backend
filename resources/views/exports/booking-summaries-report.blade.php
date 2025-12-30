<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Party Summaries Report</title>
    <style>
        @page {
            margin: 10mm;
            size: A4;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #333;
            background: #fff;
        }

        .report-container {
            max-width: 100%;
        }

        /* Cover Page */
        .cover-page {
            text-align: center;
            padding: 60px 20px;
            page-break-after: always;
        }

        .cover-title {
            font-size: 28pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 10px;
        }

        .cover-subtitle {
            font-size: 14pt;
            color: #64748b;
            margin-bottom: 40px;
        }

        .cover-date-range {
            font-size: 18pt;
            color: #333;
            background: #e0e7ff;
            padding: 15px 30px;
            display: inline-block;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .cover-stats {
            margin-top: 50px;
        }

        .cover-stat-row {
            display: inline-block;
            margin: 0 20px;
            text-align: center;
        }

        .cover-stat-value {
            font-size: 36pt;
            font-weight: bold;
            color: #1e40af;
        }

        .cover-stat-label {
            font-size: 10pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cover-location {
            margin-top: 50px;
            font-size: 12pt;
            color: #666;
        }

        .cover-footer {
            margin-top: 80px;
            font-size: 9pt;
            color: #999;
        }

        /* Day Header */
        .day-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 6px;
        }

        .day-header h2 {
            font-size: 14pt;
            margin-bottom: 3px;
        }

        .day-header .party-count {
            font-size: 10pt;
            opacity: 0.9;
        }

        /* Summary Card - One per booking */
        .summary-card {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            margin-bottom: 15px;
            page-break-inside: avoid;
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 10px 15px;
            border-bottom: 1px solid #e5e7eb;
            display: table;
            width: 100%;
        }

        .card-header-left {
            display: table-cell;
            width: 50%;
            vertical-align: middle;
        }

        .card-header-right {
            display: table-cell;
            width: 50%;
            vertical-align: middle;
            text-align: right;
        }

        .booking-ref {
            font-size: 11pt;
            font-weight: bold;
            color: #1e40af;
        }

        .booking-time {
            font-size: 12pt;
            font-weight: bold;
            color: #333;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-checked_in { background: #f0fdf4; color: #15803d; }

        .card-body {
            padding: 12px 15px;
        }

        .card-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .card-col {
            display: table-cell;
            vertical-align: top;
        }

        .card-col-1 { width: 35%; }
        .card-col-2 { width: 35%; }
        .card-col-3 { width: 30%; }

        /* Guest of Honor Highlight */
        .goh-highlight {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 6px 10px;
            border-radius: 0 4px 4px 0;
            margin-bottom: 8px;
        }

        .goh-label {
            font-size: 7pt;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .goh-name {
            font-size: 12pt;
            font-weight: bold;
            color: #92400e;
        }

        .goh-age {
            font-size: 9pt;
            color: #b45309;
        }

        /* Info Block */
        .info-block {
            margin-bottom: 5px;
        }

        .info-block-title {
            font-size: 7pt;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .info-block-value {
            font-size: 9pt;
            color: #333;
        }

        .info-block-value strong {
            color: #1e40af;
        }

        /* Notes Preview */
        .notes-preview {
            background: #fef2f2;
            border-left: 3px solid #f87171;
            padding: 5px 10px;
            font-size: 8pt;
            color: #991b1b;
            margin-top: 8px;
            border-radius: 0 4px 4px 0;
        }

        .notes-preview-label {
            font-weight: bold;
            font-size: 7pt;
            text-transform: uppercase;
        }

        /* Payment Summary */
        .payment-summary {
            background: #f0fdf4;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 8pt;
        }

        .payment-total {
            font-weight: bold;
            color: #166534;
        }

        .payment-balance {
            color: #dc2626;
            font-weight: bold;
        }

        /* Page Break for each booking option */
        .page-break-booking {
            page-break-after: always;
        }

        /* Day Divider */
        .day-divider {
            page-break-before: always;
        }

        /* Daily Summary Table */
        .daily-summary {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-top: 10px;
        }

        .daily-summary th {
            background: #1e40af;
            color: white;
            padding: 6px 8px;
            text-align: left;
            font-weight: bold;
        }

        .daily-summary td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .daily-summary tr:nth-child(even) {
            background: #f9fafb;
        }

        .daily-summary .text-right {
            text-align: right;
        }

        .daily-summary .text-center {
            text-align: center;
        }

        /* Footer */
        .report-footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #999;
            font-size: 7pt;
        }

        /* Full page per booking style */
        .full-page-summary {
            padding: 15px;
        }

        .section {
            margin-bottom: 15px;
        }

        .section-title {
            font-size: 9pt;
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 3px;
            margin-bottom: 8px;
        }

        .section-content {
            background: #f9fafb;
            padding: 8px 10px;
            border-radius: 4px;
            border-left: 3px solid #1e40af;
        }

        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 4px;
        }

        .info-label {
            display: table-cell;
            width: 35%;
            font-size: 8pt;
            color: #666;
        }

        .info-value {
            display: table-cell;
            width: 65%;
            font-size: 8pt;
            font-weight: 600;
            color: #333;
        }

        .two-column {
            display: table;
            width: 100%;
        }

        .column-left, .column-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
        }

        .column-right {
            padding-right: 0;
            padding-left: 10px;
        }

        /* Internal Notes */
        .internal-notes-section {
            background: #fef2f2;
            border: 2px solid #fca5a5;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .internal-notes-title {
            font-size: 8pt;
            font-weight: bold;
            color: #b91c1c;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .internal-notes-content {
            font-size: 9pt;
            color: #7f1d1d;
            white-space: pre-wrap;
        }

        /* Checklist */
        .checklist-section {
            margin-top: 15px;
            border: 1px dashed #d1d5db;
            border-radius: 4px;
            padding: 10px;
        }

        .checklist-title {
            font-size: 8pt;
            font-weight: bold;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .checklist-item {
            font-size: 8pt;
            color: #374151;
            margin-bottom: 5px;
            padding-left: 18px;
            position: relative;
        }

        .checklist-item:before {
            content: "☐";
            position: absolute;
            left: 0;
            font-size: 10pt;
        }
    </style>
</head>
<body>
    <div class="report-container">
        <!-- Cover Page -->
        <div class="cover-page">
            <div class="cover-title">{{ $companyName ?? 'ZapZone' }}</div>
            <div class="cover-subtitle">Party Booking Summaries</div>

            @if($dateRange)
            <div class="cover-date-range">
                @if($dateRange['start'] === $dateRange['end'])
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('l, F j, Y') }}
                @else
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('M j, Y') }} — {{ \Carbon\Carbon::parse($dateRange['end'])->format('M j, Y') }}
                @endif
            </div>
            @endif

            <div class="cover-stats">
                <div class="cover-stat-row">
                    <div class="cover-stat-value">{{ $bookings->count() }}</div>
                    <div class="cover-stat-label">Total Parties</div>
                </div>
                <div class="cover-stat-row">
                    <div class="cover-stat-value">${{ number_format($bookings->sum('total_amount'), 0) }}</div>
                    <div class="cover-stat-label">Total Revenue</div>
                </div>
                <div class="cover-stat-row">
                    <div class="cover-stat-value">{{ $bookings->sum('participants') }}</div>
                    <div class="cover-stat-label">Total Guests</div>
                </div>
            </div>

            @if($location)
            <div class="cover-location">
                {{ $location->name }}<br>
                @if($location->address){{ $location->address }}, @endif
                {{ $location->city }}, {{ $location->state }} {{ $location->zip }}
            </div>
            @endif

            <div class="cover-footer">
                Generated on {{ now()->format('M d, Y g:i A') }}<br>
                For internal use only
            </div>
        </div>

        <!-- Daily Schedule Overview (Optional - compact mode) -->
        @if($viewMode === 'compact')
            @php
                $groupedByDate = $bookings->groupBy(function($booking) {
                    return $booking->booking_date ? $booking->booking_date->format('Y-m-d') : 'unscheduled';
                })->sortKeys();
            @endphp

            @foreach($groupedByDate as $date => $dayBookings)
                @if(!$loop->first)
                    <div class="day-divider"></div>
                @endif

                <div class="day-header">
                    <h2>
                        @if($date === 'unscheduled')
                            Unscheduled Parties
                        @else
                            {{ \Carbon\Carbon::parse($date)->format('l, F j, Y') }}
                        @endif
                    </h2>
                    <div class="party-count">{{ $dayBookings->count() }} {{ Str::plural('party', $dayBookings->count()) }} scheduled</div>
                </div>

                @foreach($dayBookings->sortBy('booking_time') as $booking)
                    <div class="summary-card">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span class="booking-ref">{{ $booking->reference_number }}</span>
                                <span class="status-badge status-{{ strtolower($booking->status) }}">
                                    {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                                </span>
                            </div>
                            <div class="card-header-right">
                                <span class="booking-time">
                                    @if($booking->booking_time)
                                        {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                                    @else
                                        Time TBD
                                    @endif
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            @if($booking->guest_of_honor_name)
                            <div class="goh-highlight">
                                <div class="goh-label">🎂 Guest of Honor</div>
                                <span class="goh-name">{{ $booking->guest_of_honor_name }}</span>
                                @if($booking->guest_of_honor_age)
                                    <span class="goh-age">- Turning {{ $booking->guest_of_honor_age }}</span>
                                @endif
                            </div>
                            @endif

                            <div class="card-row">
                                <div class="card-col card-col-1">
                                    <div class="info-block">
                                        <div class="info-block-title">Contact</div>
                                        <div class="info-block-value">
                                            @if($booking->customer)
                                                {{ $booking->customer->first_name }} {{ $booking->customer->last_name }}<br>
                                                {{ $booking->customer->phone ?? $booking->guest_phone ?? 'N/A' }}
                                            @else
                                                {{ $booking->guest_name ?? 'N/A' }}<br>
                                                {{ $booking->guest_phone ?? 'N/A' }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="card-col card-col-2">
                                    <div class="info-block">
                                        <div class="info-block-title">Package & Room</div>
                                        <div class="info-block-value">
                                            <strong>{{ $booking->package->name ?? 'N/A' }}</strong><br>
                                            🚪 {{ $booking->room->name ?? 'TBD' }}
                                            @if($booking->participants) · 👥 {{ $booking->participants }}@endif
                                        </div>
                                    </div>
                                </div>
                                <div class="card-col card-col-3">
                                    <div class="payment-summary">
                                        <div><span class="payment-total">${{ number_format($booking->total_amount, 2) }}</span></div>
                                        @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                                            <div>Balance: <span class="payment-balance">${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</span></div>
                                        @else
                                            <div style="color: #166534;">✓ Paid</div>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if($booking->internal_notes)
                            <div class="notes-preview">
                                <span class="notes-preview-label">⚠️ Internal Notes:</span>
                                {{ Str::limit($booking->internal_notes, 200) }}
                            </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endforeach

        @else
            <!-- Full Page Mode - One booking per page -->
            @foreach($bookings->sortBy(['booking_date', 'booking_time']) as $booking)
                @if(!$loop->first)
                    <div class="page-break-booking"></div>
                @endif

                @php
                    $customer = $booking->customer;
                    $location = $booking->location;
                @endphp

                <div class="full-page-summary">
                    <!-- Header -->
                    <div style="display: table; width: 100%; margin-bottom: 15px; border-bottom: 3px solid #1e40af; padding-bottom: 12px;">
                        <div style="display: table-cell; width: 55%; vertical-align: top;">
                            <div style="font-size: 14pt; font-weight: bold; color: #1e40af; margin-bottom: 2px;">
                                {{ $companyName ?? 'ZapZone' }}
                            </div>
                            <div style="font-size: 7pt; color: #666; line-height: 1.4;">
                                @if($location)
                                    {{ $location->name }}<br>
                                    @if($location->address){{ $location->address }}<br>@endif
                                    @if($location->phone)Phone: {{ $location->phone }}@endif
                                @endif
                            </div>
                        </div>
                        <div style="display: table-cell; width: 45%; text-align: right; vertical-align: top;">
                            <div style="font-size: 16pt; color: #1e40af; margin-bottom: 3px; font-weight: bold;">PARTY SUMMARY</div>
                            <div style="font-size: 11pt; font-weight: bold; color: #333; background: #e0e7ff; padding: 3px 10px; display: inline-block; border-radius: 3px;">
                                {{ $booking->reference_number }}
                            </div>
                            <div style="font-size: 9pt; color: #666; margin-top: 5px;">
                                {{ $booking->booking_date ? $booking->booking_date->format('l, F j, Y') : 'Date TBD' }}
                            </div>
                            <span class="status-badge status-{{ strtolower($booking->status) }}" style="margin-top: 5px;">
                                {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                            </span>
                        </div>
                    </div>

                    <!-- Guest of Honor -->
                    @if($booking->guest_of_honor_name)
                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; border-radius: 6px; padding: 10px; margin-bottom: 12px; text-align: center;">
                        <div style="font-size: 7pt; color: #92400e; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px;">🎂 Guest of Honor 🎂</div>
                        <div style="font-size: 14pt; font-weight: bold; color: #92400e; margin-bottom: 2px;">{{ $booking->guest_of_honor_name }}</div>
                        <div style="font-size: 9pt; color: #b45309;">
                            @if($booking->guest_of_honor_age)Turning {{ $booking->guest_of_honor_age }} years old@endif
                            @if($booking->guest_of_honor_gender) • {{ ucfirst($booking->guest_of_honor_gender) }}@endif
                        </div>
                    </div>
                    @endif

                    <!-- Party Details -->
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 10px; margin-bottom: 12px;">
                        <div style="font-size: 12pt; font-weight: bold; color: #1e40af; margin-bottom: 5px;">
                            {{ $booking->package->name ?? 'Package Details' }}
                        </div>
                        <div style="font-size: 10pt; color: #333; margin-bottom: 3px;">
                            📅 {{ $booking->booking_date ? $booking->booking_date->format('M d, Y') : 'TBD' }}
                            @if($booking->booking_time) ⏰ {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}@endif
                            @if($booking->duration) ⏱️ {{ $booking->duration }} {{ $booking->duration_unit ?? 'hours' }}@endif
                        </div>
                        <div style="font-size: 9pt; color: #666;">
                            @if($booking->room)🚪 Room: <strong>{{ $booking->room->name }}</strong>@endif
                            @if($booking->participants) 👥 Guests: <strong>{{ $booking->participants }}</strong>@endif
                        </div>
                    </div>

                    <div class="two-column">
                        <div class="column-left">
                            <div class="section">
                                <div class="section-title">Parent / Contact Information</div>
                                <div class="section-content">
                                    <div class="info-row">
                                        <span class="info-label">Name:</span>
                                        <span class="info-value">
                                            @if($customer){{ $customer->first_name }} {{ $customer->last_name }}@else{{ $booking->guest_name ?? 'N/A' }}@endif
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Phone:</span>
                                        <span class="info-value">{{ $customer->phone ?? $booking->guest_phone ?? 'N/A' }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Email:</span>
                                        <span class="info-value" style="font-size: 7pt;">{{ $customer->email ?? $booking->guest_email ?? 'N/A' }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column-right">
                            <div class="section">
                                <div class="section-title">Payment Information</div>
                                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; padding: 8px 10px;">
                                    <div class="info-row">
                                        <span class="info-label" style="color: #166534;">Status:</span>
                                        <span class="info-value" style="color: #166534;">{{ ucfirst(str_replace('_', ' ', $booking->payment_status ?? 'N/A')) }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label" style="color: #166534;">Paid:</span>
                                        <span class="info-value" style="color: #166534;">${{ number_format($booking->amount_paid ?? 0, 2) }}</span>
                                    </div>
                                    @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                                    <div class="info-row">
                                        <span class="info-label" style="color: #dc2626;">Balance:</span>
                                        <span class="info-value" style="color: #dc2626;">${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}</span>
                                    </div>
                                    @endif
                                    <div class="info-row" style="border-top: 1px solid #86efac; padding-top: 5px; margin-top: 5px;">
                                        <span class="info-label" style="color: #166534; font-weight: bold;">Total:</span>
                                        <span class="info-value" style="color: #166534; font-weight: bold; font-size: 10pt;">${{ number_format($booking->total_amount, 2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($booking->special_requests)
                    <div style="background: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; padding: 8px 10px; margin-bottom: 10px;">
                        <div style="font-size: 8pt; font-weight: bold; color: #c2410c; text-transform: uppercase; margin-bottom: 4px;">📝 Special Requests</div>
                        <div style="font-size: 8pt; color: #7c2d12; white-space: pre-wrap;">{{ $booking->special_requests }}</div>
                    </div>
                    @endif

                    @if($booking->internal_notes)
                    <div class="internal-notes-section">
                        <div class="internal-notes-title">⚠️ Internal Notes (Staff Only)</div>
                        <div class="internal-notes-content">{{ $booking->internal_notes }}</div>
                    </div>
                    @endif

                    <div class="checklist-section">
                        <div class="checklist-title">Staff Checklist</div>
                        <div class="checklist-item">Room prepared and cleaned</div>
                        <div class="checklist-item">Decorations set up</div>
                        <div class="checklist-item">Food/Cake area ready</div>
                        <div class="checklist-item">Party supplies stocked</div>
                        <div class="checklist-item">Guest check-in completed</div>
                        <div class="checklist-item">Payment collected (if balance due)</div>
                    </div>

                    <div class="report-footer">
                        <div>Printed on {{ now()->format('M d, Y g:i A') }} • Page {{ $loop->iteration }} of {{ $bookings->count() }}</div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</body>
</html>
