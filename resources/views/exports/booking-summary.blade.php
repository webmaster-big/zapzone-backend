<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Party Summary - {{ $booking->reference_number }}</title>
    <style>
        @page {
            margin: 12mm;
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

        .summary-container {
            max-width: 100%;
            padding: 10px;
        }

        /* Header */
        .summary-header {
            display: table;
            width: 100%;
            margin-bottom: 15px;
            border-bottom: 3px solid #1e40af;
            padding-bottom: 12px;
        }

        .company-info {
            display: table-cell;
            width: 55%;
            vertical-align: top;
        }

        .company-name {
            font-size: 14pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 2px;
        }

        .company-details {
            font-size: 7pt;
            color: #666;
            line-height: 1.4;
        }

        .booking-title {
            display: table-cell;
            width: 45%;
            text-align: right;
            vertical-align: top;
        }

        .booking-title h1 {
            font-size: 16pt;
            color: #1e40af;
            margin-bottom: 3px;
        }

        .reference-number {
            font-size: 11pt;
            font-weight: bold;
            color: #333;
            background: #e0e7ff;
            padding: 3px 10px;
            display: inline-block;
            border-radius: 3px;
        }

        .booking-date-header {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-confirmed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-completed { background: #dbeafe; color: #1e40af; }
        .status-checked_in { background: #f0fdf4; color: #15803d; }

        /* Two Column Layout */
        .two-column {
            display: table;
            width: 100%;
            margin-bottom: 15px;
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

        /* Section Styling */
        .section {
            margin-bottom: 12px;
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

        /* Info Rows */
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 4px;
        }

        .info-label {
            display: table-cell;
            width: 40%;
            font-size: 8pt;
            color: #666;
            padding-right: 5px;
        }

        .info-value {
            display: table-cell;
            width: 60%;
            font-size: 8pt;
            font-weight: 600;
            color: #333;
        }

        /* Guest of Honor Highlight */
        .guest-of-honor-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 12px;
            text-align: center;
        }

        .guest-of-honor-label {
            font-size: 7pt;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .guest-of-honor-name {
            font-size: 14pt;
            font-weight: bold;
            color: #92400e;
            margin-bottom: 2px;
        }

        .guest-of-honor-details {
            font-size: 9pt;
            color: #b45309;
        }

        /* Party Details Box */
        .party-details-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 12px;
        }

        .party-package {
            font-size: 12pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .party-datetime {
            font-size: 10pt;
            color: #333;
            margin-bottom: 3px;
        }

        .party-room {
            font-size: 9pt;
            color: #666;
        }

        /* Notes Section */
        .notes-section {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 10px;
        }

        .notes-title {
            font-size: 8pt;
            font-weight: bold;
            color: #c2410c;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .notes-content {
            font-size: 8pt;
            color: #7c2d12;
            white-space: pre-wrap;
            line-height: 1.4;
        }

        /* Internal Notes - More prominent */
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
            line-height: 1.5;
        }

        /* Payment Info */
        .payment-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 4px;
            padding: 8px 10px;
        }

        .payment-row {
            display: table;
            width: 100%;
            margin-bottom: 3px;
        }

        .payment-label {
            display: table-cell;
            width: 60%;
            font-size: 8pt;
            color: #166534;
        }

        .payment-value {
            display: table-cell;
            width: 40%;
            font-size: 8pt;
            font-weight: bold;
            color: #166534;
            text-align: right;
        }

        .payment-total {
            border-top: 1px solid #86efac;
            padding-top: 5px;
            margin-top: 5px;
        }

        .payment-total .payment-label,
        .payment-total .payment-value {
            font-size: 10pt;
            font-weight: bold;
        }

        /* Footer */
        .summary-footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #999;
            font-size: 7pt;
        }

        /* Checklist Section */
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

        /* Add-ons/Attractions Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-top: 5px;
        }

        .items-table th {
            background: #e5e7eb;
            padding: 4px 6px;
            text-align: left;
            font-weight: bold;
            font-size: 7pt;
            color: #374151;
        }

        .items-table td {
            padding: 4px 6px;
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table .text-right {
            text-align: right;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="summary-container">
        <!-- Header -->
        <div class="summary-header">
            <div class="company-info">
                <div class="company-name">{{ $companyName ?? 'ZapZone' }}</div>
                <div class="company-details">
                    @if($location)
                        {{ $location->name }}<br>
                        @if($location->address){{ $location->address }}<br>@endif
                        @if($location->city || $location->state || $location->zip)
                            {{ $location->city }}@if($location->city && $location->state), @endif{{ $location->state }} {{ $location->zip }}<br>
                        @endif
                        @if($location->phone)Phone: {{ $location->phone }}@endif
                    @endif
                </div>
            </div>
            <div class="booking-title">
                <h1>PARTY SUMMARY</h1>
                <div class="reference-number">{{ $booking->reference_number }}</div>
                <div class="booking-date-header">
                    {{ $booking->booking_date ? $booking->booking_date->format('l, F j, Y') : 'Date TBD' }}
                </div>
                <div style="font-size: 7pt; color: #888; margin-top: 3px;">
                    Created: {{ $booking->created_at ? $booking->created_at->format('M j, Y g:i A') : 'N/A' }}
                </div>
                <span class="status-badge status-{{ strtolower($booking->status) }}">
                    {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                </span>
            </div>
        </div>

        <!-- Guest of Honor (if available) -->
        @if($booking->guest_of_honor_name)
        <div class="guest-of-honor-box">
            <div class="guest-of-honor-label">🎂 Guest of Honor 🎂</div>
            <div class="guest-of-honor-name">{{ $booking->guest_of_honor_name }}</div>
            <div class="guest-of-honor-details">
                @if($booking->guest_of_honor_age)
                    Turning {{ $booking->guest_of_honor_age }} years old
                @endif
                @if($booking->guest_of_honor_gender)
                    • {{ ucfirst($booking->guest_of_honor_gender) }}
                @endif
            </div>
        </div>
        @endif

        <!-- Party Details Box -->
        <div class="party-details-box">
            <div class="party-package">
                {{ $booking->package->name ?? 'Package Details' }}
            </div>
            <div class="party-datetime">
                📅 {{ $booking->booking_date ? $booking->booking_date->format('M d, Y') : 'TBD' }}
                @if($booking->booking_time)
                    &nbsp;&nbsp;⏰ {{ \Carbon\Carbon::parse($booking->booking_time)->format('g:i A') }}
                @endif
                @if($booking->duration)
                    &nbsp;&nbsp;⏱️ {{ $booking->duration }} {{ $booking->duration_unit ?? 'hours' }}
                @endif
            </div>
            <div class="party-room">
                @if($booking->room)
                    🚪 Room: <strong>{{ $booking->room->name }}</strong>
                    @if($booking->room->capacity)
                        (Capacity: {{ $booking->room->capacity }})
                    @endif
                @endif
                @if($booking->participants)
                    &nbsp;&nbsp;👥 Guests: <strong>{{ $booking->participants }}</strong>
                @endif
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="two-column">
            <!-- Left Column: Contact Info -->
            <div class="column-left">
                <div class="section">
                    <div class="section-title">Parent / Contact Information</div>
                    <div class="section-content">
                        <div class="info-row">
                            <span class="info-label">Name:</span>
                            <span class="info-value">
                                @if($customer)
                                    {{ $customer->first_name }} {{ $customer->last_name }}
                                @else
                                    {{ $booking->guest_name ?? 'N/A' }}
                                @endif
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Phone:</span>
                            <span class="info-value">
                                @if($customer)
                                    {{ $customer->phone ?? 'N/A' }}
                                @else
                                    {{ $booking->guest_phone ?? 'N/A' }}
                                @endif
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value" style="font-size: 7pt;">
                                @if($customer)
                                    {{ $customer->email ?? 'N/A' }}
                                @else
                                    {{ $booking->guest_email ?? 'N/A' }}
                                @endif
                            </span>
                        </div>
                        @if($booking->guest_address || ($customer && $customer->address))
                        <div class="info-row">
                            <span class="info-label">Address:</span>
                            <span class="info-value" style="font-size: 7pt;">
                                @if($customer && $customer->address)
                                    {{ $customer->address }}
                                @else
                                    {{ $booking->guest_address }}
                                @endif
                            </span>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Add-ons if any -->
                @if($booking->addOns && $booking->addOns->count() > 0)
                <div class="section">
                    <div class="section-title">Add-Ons</div>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($booking->addOns as $addOn)
                            <tr>
                                <td>{{ $addOn->name }}</td>
                                <td class="text-right">{{ $addOn->pivot->quantity ?? 1 }}</td>
                                <td class="text-right">${{ number_format($addOn->pivot->price_at_booking ?? $addOn->price, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            <!-- Right Column: Payment Info -->
            <div class="column-right">
                <div class="section">
                    <div class="section-title">Payment Information</div>
                    <div class="payment-box">
                        <div class="payment-row">
                            <span class="payment-label">Payment Method:</span>
                            <span class="payment-value">{{ ucfirst($booking->payment_method ?? 'N/A') }}</span>
                        </div>
                        <div class="payment-row">
                            <span class="payment-label">Payment Status:</span>
                            <span class="payment-value">{{ ucfirst(str_replace('_', ' ', $booking->payment_status ?? 'N/A')) }}</span>
                        </div>
                        @if($booking->discount_amount > 0)
                        <div class="payment-row">
                            <span class="payment-label">Discount:</span>
                            <span class="payment-value">-${{ number_format($booking->discount_amount, 2) }}</span>
                        </div>
                        @endif
                        <div class="payment-row">
                            <span class="payment-label">Amount Paid:</span>
                            <span class="payment-value">${{ number_format($booking->amount_paid ?? 0, 2) }}</span>
                        </div>
                        @if(($booking->total_amount - ($booking->amount_paid ?? 0)) > 0)
                        <div class="payment-row">
                            <span class="payment-label">Balance Due:</span>
                            <span class="payment-value" style="color: #dc2626;">
                                ${{ number_format($booking->total_amount - ($booking->amount_paid ?? 0), 2) }}
                            </span>
                        </div>
                        @endif
                        <div class="payment-row payment-total">
                            <span class="payment-label">Total Amount:</span>
                            <span class="payment-value">${{ number_format($booking->total_amount, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Attractions if any -->
                @if($booking->attractions && $booking->attractions->count() > 0)
                <div class="section">
                    <div class="section-title">Attractions Included</div>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Attraction</th>
                                <th class="text-right">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($booking->attractions as $attraction)
                            <tr>
                                <td>{{ $attraction->name }}</td>
                                <td class="text-right">{{ $attraction->pivot->quantity ?? 1 }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>

        <!-- Special Requests -->
        @if($booking->special_requests)
        <div class="notes-section">
            <div class="notes-title">📝 Special Requests</div>
            <div class="notes-content">{{ $booking->special_requests }}</div>
        </div>
        @endif

        <!-- Customer Notes -->
        @if($booking->notes)
        <div class="notes-section">
            <div class="notes-title">📋 Notes</div>
            <div class="notes-content">{{ $booking->notes }}</div>
        </div>
        @endif

        <!-- Internal Notes (Staff Only) -->
        @if($booking->internal_notes)
        <div class="internal-notes-section">
            <div class="internal-notes-title">⚠️ Internal Notes (Staff Only)</div>
            <div class="internal-notes-content">{{ $booking->internal_notes }}</div>
        </div>
        @endif

        <!-- Staff Checklist -->
        <div class="checklist-section">
            <div class="checklist-title">Staff Checklist</div>
            <div class="checklist-item">Room prepared and cleaned</div>
            <div class="checklist-item">Decorations set up</div>
            <div class="checklist-item">Food/Cake area ready</div>
            <div class="checklist-item">Party supplies stocked</div>
            <div class="checklist-item">Guest check-in completed</div>
            <div class="checklist-item">Payment collected (if balance due)</div>
        </div>

        <!-- Footer -->
        <div class="summary-footer">
            <div>Printed on {{ now()->format('M d, Y g:i A') }}</div>
            <div>© {{ date('Y') }} {{ $companyName ?? 'ZapZone' }} - For internal use only</div>
        </div>
    </div>
</body>
</html>
