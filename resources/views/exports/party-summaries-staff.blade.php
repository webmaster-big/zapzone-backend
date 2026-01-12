<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Party Summaries - {{ $companyName }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #2563eb;
        }
        
        .header h1 {
            font-size: 20px;
            color: #2563eb;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .header .date-range {
            font-size: 11px;
            color: #444;
            font-weight: bold;
        }
        
        .summary-stats {
            background: #f3f4f6;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid #d1d5db;
        }
        
        .summary-stats .stats-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .summary-stats .stat-item {
            flex: 1;
            text-align: center;
        }
        
        .summary-stats .stat-value {
            font-size: 14px;
            font-weight: bold;
            color: #2563eb;
        }
        
        .summary-stats .stat-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
        }
        
        .booking-card {
            border: 1px solid #d1d5db;
            border-radius: 5px;
            padding: 12px;
            margin-bottom: 15px;
            background: #fff;
            page-break-inside: avoid;
        }
        
        .booking-card.compact {
            padding: 8px;
            margin-bottom: 10px;
        }
        
        .booking-header {
            background: #2563eb;
            color: white;
            padding: 8px;
            margin: -12px -12px 10px -12px;
            border-radius: 5px 5px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .booking-card.compact .booking-header {
            padding: 6px 8px;
            margin: -8px -8px 8px -8px;
        }
        
        .booking-header .left {
            flex: 1;
        }
        
        .booking-header .ref-number {
            font-size: 13px;
            font-weight: bold;
        }
        
        .booking-header .package-name {
            font-size: 10px;
            opacity: 0.9;
        }
        
        .booking-header .right {
            text-align: right;
        }
        
        .booking-header .time {
            font-size: 14px;
            font-weight: bold;
        }
        
        .booking-header .duration {
            font-size: 9px;
            opacity: 0.9;
        }
        
        .booking-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .booking-card.compact .booking-details {
            margin-bottom: 6px;
        }
        
        .detail-section {
            flex: 1;
        }
        
        .detail-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-size: 10px;
            color: #333;
            font-weight: 500;
        }
        
        .detail-value.large {
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.confirmed { background: #dbeafe; color: #1e40af; }
        .status-badge.checked-in { background: #d1fae5; color: #065f46; }
        .status-badge.completed { background: #dcfce7; color: #166534; }
        
        .payment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }
        
        .payment-badge.paid { background: #dcfce7; color: #166534; }
        .payment-badge.partial { background: #fef3c7; color: #92400e; }
        .payment-badge.pending { background: #fee2e2; color: #991b1b; }
        
        .notes-section {
            background: #fffbeb;
            border: 1px solid #fde047;
            border-radius: 4px;
            padding: 8px;
            margin-top: 10px;
        }
        
        .booking-card.compact .notes-section {
            padding: 6px;
            margin-top: 6px;
        }
        
        .notes-section .notes-label {
            font-size: 9px;
            font-weight: bold;
            color: #854d0e;
            margin-bottom: 4px;
        }
        
        .notes-section .notes-content {
            font-size: 9px;
            color: #713f12;
            line-height: 1.4;
        }
        
        .special-requests {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 6px;
            margin-top: 8px;
            font-size: 9px;
        }
        
        .add-ons-attractions {
            margin-top: 8px;
            font-size: 9px;
        }
        
        .add-ons-attractions .label {
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 3px;
        }
        
        .add-ons-attractions ul {
            margin-left: 15px;
            margin-top: 3px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Party Summaries - Staff Organization</h1>
        <div class="subtitle">{{ $companyName }}@if($locationName !== 'All Locations') - {{ $locationName }}@endif</div>
        @if($dateRange)
            <div class="date-range">
                @if($dateRange['start'] === $dateRange['end'])
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('l, F j, Y') }}
                @else
                    {{ \Carbon\Carbon::parse($dateRange['start'])->format('M j, Y') }} - {{ \Carbon\Carbon::parse($dateRange['end'])->format('M j, Y') }}
                @endif
            </div>
        @endif
        @if($package)
            <div class="subtitle" style="margin-top: 5px; color: #2563eb; font-weight: bold;">Package: {{ $package->name }}</div>
        @endif
    </div>
    
    <div class="summary-stats">
        <div class="stats-row">
            <div class="stat-item">
                <div class="stat-value">{{ $summary['total_bookings'] }}</div>
                <div class="stat-label">Total Parties</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $summary['total_participants'] }}</div>
                <div class="stat-label">Total Guests</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${{ number_format($summary['total_revenue'], 2) }}</div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">${{ number_format($summary['total_paid'], 2) }}</div>
                <div class="stat-label">Total Paid</div>
            </div>
        </div>
    </div>
    
    @foreach($bookings as $index => $booking)
        <div class="booking-card {{ $viewMode === 'compact' ? 'compact' : '' }}">
            <div class="booking-header">
                <div class="left">
                    <div class="ref-number">{{ $booking->reference_number }}</div>
                    <div class="package-name">{{ $booking->package->name ?? 'N/A' }}</div>
                </div>
                <div class="right">
                    <div class="time">{{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}</div>
                    <div class="duration">{{ $booking->duration }} {{ $booking->duration_unit }}</div>
                </div>
            </div>
            
            <div class="booking-details">
                <div class="detail-section">
                    <div class="detail-label">Customer Name</div>
                    <div class="detail-value large">
                        @if($booking->customer)
                            {{ $booking->customer->first_name }} {{ $booking->customer->last_name }}
                        @else
                            {{ $booking->guest_name }}
                        @endif
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Contact</div>
                    <div class="detail-value">
                        {{ $booking->customer ? $booking->customer->email : $booking->guest_email }}<br>
                        {{ $booking->customer ? $booking->customer->phone : $booking->guest_phone }}
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Participants</div>
                    <div class="detail-value large">{{ $booking->participants }} guests</div>
                </div>
            </div>
            
            <div class="booking-details">
                <div class="detail-section">
                    <div class="detail-label">Room</div>
                    <div class="detail-value">{{ $booking->room->name ?? 'Not Assigned' }}</div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="status-badge {{ $booking->status }}">{{ ucfirst($booking->status) }}</span>
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Payment</div>
                    <div class="detail-value">
                        <span class="payment-badge {{ $booking->payment_status }}">{{ ucfirst($booking->payment_status) }}</span><br>
                        ${{ number_format($booking->amount_paid, 2) }} / ${{ number_format($booking->total_amount, 2) }}
                    </div>
                </div>
            </div>
            
            @if($booking->guest_of_honor_name)
                <div class="booking-details" style="margin-top: 10px; background: #fef3c7; padding: 6px; border-radius: 4px;">
                    <div class="detail-section">
                        <div class="detail-label">Guest of Honor</div>
                        <div class="detail-value">{{ $booking->guest_of_honor_name }}</div>
                    </div>
                    @if($booking->guest_of_honor_age)
                        <div class="detail-section">
                            <div class="detail-label">Age</div>
                            <div class="detail-value">{{ $booking->guest_of_honor_age }} years</div>
                        </div>
                    @endif
                    @if($booking->guest_of_honor_gender)
                        <div class="detail-section">
                            <div class="detail-label">Gender</div>
                            <div class="detail-value">{{ ucfirst($booking->guest_of_honor_gender) }}</div>
                        </div>
                    @endif
                </div>
            @endif
            
            @if($booking->attractions && $booking->attractions->count() > 0)
                <div class="add-ons-attractions">
                    <div class="label">Attractions:</div>
                    <ul>
                        @foreach($booking->attractions as $attraction)
                            <li>{{ $attraction->name }} (Qty: {{ $attraction->pivot->quantity ?? 1 }})</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            
            @if($booking->addOns && $booking->addOns->count() > 0)
                <div class="add-ons-attractions">
                    <div class="label">Add-ons:</div>
                    <ul>
                        @foreach($booking->addOns as $addOn)
                            <li>{{ $addOn->name }} (Qty: {{ $addOn->pivot->quantity ?? 1 }})</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            
            @if($booking->special_requests)
                <div class="special-requests">
                    <strong>Special Requests:</strong> {{ $booking->special_requests }}
                </div>
            @endif
            
            @if($booking->notes || $booking->internal_notes)
                <div class="notes-section">
                    @if($booking->notes)
                        <div class="notes-label">📝 Customer Notes:</div>
                        <div class="notes-content">{{ $booking->notes }}</div>
                    @endif
                    @if($booking->internal_notes)
                        <div class="notes-label" style="margin-top: {{ $booking->notes ? '6px' : '0' }};">🔒 Internal Staff Notes:</div>
                        <div class="notes-content">{{ $booking->internal_notes }}</div>
                    @endif
                </div>
            @endif
        </div>
        
        @if($viewMode === 'detailed' && ($index + 1) % 2 === 0 && $index < count($bookings) - 1)
            <div class="page-break"></div>
        @endif
    @endforeach
    
    <div class="footer">
        Generated on {{ now()->format('F j, Y g:i A') }} | {{ $companyName }} - Party Management System
    </div>
</body>
</html>
