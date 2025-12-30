<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #{{ $payment->id }}</title>
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

        .invoice-container {
            max-width: 100%;
            padding: 15px;
        }

        /* Header */
        .invoice-header {
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
            font-size: 18pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 3px;
        }

        .company-details {
            font-size: 8pt;
            color: #666;
            line-height: 1.5;
        }

        .invoice-title {
            display: table-cell;
            width: 45%;
            text-align: right;
            vertical-align: top;
        }

        .invoice-title h1 {
            font-size: 22pt;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-size: 11pt;
            font-weight: bold;
            color: #333;
            background: #e0e7ff;
            padding: 3px 10px;
            display: inline-block;
            border-radius: 3px;
        }

        .invoice-date {
            font-size: 9pt;
            color: #666;
            margin-top: 5px;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-completed { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .status-refunded { background: #e0e7ff; color: #3730a3; }

        /* Guest of Honor Section - Prominent */
        .guest-of-honor-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 15px;
            text-align: center;
        }

        .guest-of-honor-label {
            font-size: 8pt;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }

        .guest-of-honor-name {
            font-size: 16pt;
            font-weight: bold;
            color: #92400e;
            margin-bottom: 2px;
        }

        .guest-of-honor-details {
            font-size: 10pt;
            color: #b45309;
        }

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
            padding: 10px;
            border-radius: 4px;
            border-left: 3px solid #1e40af;
        }

        /* Info Rows */
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 5px;
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
            font-size: 9pt;
            font-weight: 600;
            color: #333;
        }

        /* Party Details Box */
        .party-details-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
        }

        .party-package {
            font-size: 14pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }

        .party-datetime {
            font-size: 11pt;
            color: #333;
            margin-bottom: 5px;
        }

        .party-room {
            font-size: 10pt;
            color: #666;
        }

        /* Items Table */
        .items-section {
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }

        table thead {
            background: #1e40af;
            color: white;
        }

        table th {
            padding: 8px 6px;
            text-align: left;
            font-weight: bold;
            font-size: 8pt;
        }

        table th.text-right {
            text-align: right;
        }

        table td {
            padding: 8px 6px;
            border-bottom: 1px solid #e5e7eb;
        }

        table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .item-description {
            font-size: 7pt;
            color: #666;
            margin-top: 2px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Totals */
        .totals-section {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .totals-spacer {
            display: table-cell;
            width: 55%;
        }

        .totals-box {
            display: table-cell;
            width: 45%;
        }

        .totals-table {
            width: 100%;
        }

        .totals-table td {
            padding: 5px 6px;
            border-bottom: none;
            font-size: 9pt;
        }

        .totals-table .subtotal-row {
            background: #f9fafb;
        }

        .totals-table .discount-row {
            background: #fef3c7;
            color: #15803d;
        }

        .totals-table .total-row {
            font-size: 12pt;
            font-weight: bold;
            background: #1e40af;
            color: white;
        }

        .totals-table .total-row td {
            padding: 10px 6px;
        }

        .totals-table .paid-row {
            background: #dcfce7;
        }

        .totals-table .balance-row {
            background: #fee2e2;
            font-weight: bold;
        }

        /* Internal Notes - Staff Only */
        .internal-notes-section {
            background: #fef2f2;
            border: 2px solid #fca5a5;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 12px;
        }

        .internal-notes-title {
            font-size: 8pt;
            font-weight: bold;
            color: #b91c1c;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .internal-notes-content {
            font-size: 9pt;
            color: #7f1d1d;
            white-space: pre-wrap;
            line-height: 1.5;
        }

        /* Notes */
        .notes-section {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 12px;
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
        }

        /* Payment Info Box */
        .payment-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 4px;
            padding: 10px;
        }

        .payment-row {
            display: table;
            width: 100%;
            margin-bottom: 4px;
        }

        .payment-label {
            display: table-cell;
            width: 50%;
            font-size: 8pt;
            color: #166534;
        }

        .payment-value {
            display: table-cell;
            width: 50%;
            font-size: 8pt;
            font-weight: bold;
            color: #166534;
            text-align: right;
        }

        /* Footer */
        .invoice-footer {
            text-align: center;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            color: #999;
            font-size: 7pt;
        }

        .thank-you {
            font-size: 11pt;
            color: #1e40af;
            font-weight: bold;
            margin-bottom: 5px;
        }

        /* Page Break for multiple invoices */
        .page-break {
            page-break-after: always;
        }

        /* Attraction Purchase Specific */
        .attraction-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
            text-align: center;
        }

        .attraction-name {
            font-size: 16pt;
            font-weight: bold;
            color: #92400e;
            margin-bottom: 5px;
        }

        .attraction-details {
            font-size: 10pt;
            color: #b45309;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="invoice-header">
            <div class="company-info">
                <div class="company-name">{{ $companyName ?? 'ZapZone' }}</div>
                <div class="company-details">
                    @if($location)
                        {{ $location->name }}<br>
                        @if($location->address){{ $location->address }}<br>@endif
                        @if($location->city || $location->state || $location->zip)
                            {{ $location->city }}@if($location->city && $location->state), @endif{{ $location->state }} {{ $location->zip }}<br>
                        @endif
                        @if($location->phone)Phone: {{ $location->phone }}<br>@endif
                        @if($location->email)Email: {{ $location->email }}@endif
                    @endif
                </div>
            </div>
            <div class="invoice-title">
                <h1>INVOICE</h1>
                <div class="invoice-number">#{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</div>
                <div class="invoice-date">{{ $payment->created_at->format('M d, Y') }}</div>
                <span class="status-badge status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span>
            </div>
        </div>

        @if($payable && $payment->payable_type === 'booking')
            {{-- ========== BOOKING INVOICE ========== --}}
            
            <!-- Guest of Honor (Child) -->
            @if($payable->guest_of_honor_name)
            <div class="guest-of-honor-box">
                <div class="guest-of-honor-label">Guest of Honor</div>
                <div class="guest-of-honor-name">{{ $payable->guest_of_honor_name }}</div>
                <div class="guest-of-honor-details">
                    @if($payable->guest_of_honor_age)
                        Turning {{ $payable->guest_of_honor_age }} years old
                    @endif
                    @if($payable->guest_of_honor_gender)
                        | {{ ucfirst($payable->guest_of_honor_gender) }}
                    @endif
                </div>
            </div>
            @endif

            <!-- Party Details Box -->
            <div class="party-details-box">
                <div class="party-package">{{ $payable->package->name ?? 'Party Package' }}</div>
                <div class="party-datetime">
                    {{ $payable->booking_date ? $payable->booking_date->format('l, F j, Y') : 'Date TBD' }}
                    @if($payable->booking_time)
                        at {{ \Carbon\Carbon::parse($payable->booking_time)->format('g:i A') }}
                    @endif
                    @if($payable->duration)
                        | Duration: {{ $payable->duration }} {{ $payable->duration_unit ?? 'hours' }}
                    @endif
                </div>
                <div class="party-room">
                    @if($payable->room)
                        Room: <strong>{{ $payable->room->name }}</strong>
                        @if($payable->room->capacity)
                            (Capacity: {{ $payable->room->capacity }})
                        @endif
                    @endif
                    @if($payable->participants)
                        | Expected Guests: <strong>{{ $payable->participants }}</strong>
                    @endif
                </div>
                <div style="margin-top: 5px; font-size: 9pt; color: #1e40af;">
                    Reference: <strong>{{ $payable->reference_number }}</strong>
                </div>
            </div>

            <!-- Two Column Layout: Parent Info & Payment Info -->
            <div class="two-column">
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
                                        {{ $payable->guest_name ?? 'N/A' }}
                                    @endif
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">
                                    @if($customer && $customer->phone)
                                        {{ $customer->phone }}
                                    @else
                                        {{ $payable->guest_phone ?? 'N/A' }}
                                    @endif
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value" style="font-size: 8pt;">
                                    @if($customer && $customer->email)
                                        {{ $customer->email }}
                                    @else
                                        {{ $payable->guest_email ?? 'N/A' }}
                                    @endif
                                </span>
                            </div>
                            @if(($customer && $customer->address) || $payable->guest_address)
                            <div class="info-row">
                                <span class="info-label">Address:</span>
                                <span class="info-value" style="font-size: 7pt;">
                                    @if($customer && $customer->address)
                                        {{ $customer->address }}
                                        @if($customer->city), {{ $customer->city }}@endif
                                        @if($customer->state), {{ $customer->state }}@endif
                                        {{ $customer->zip }}
                                    @else
                                        {{ $payable->guest_address }}
                                        @if($payable->guest_city), {{ $payable->guest_city }}@endif
                                        @if($payable->guest_state), {{ $payable->guest_state }}@endif
                                        {{ $payable->guest_zip ?? '' }}
                                    @endif
                                </span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="column-right">
                    <div class="section">
                        <div class="section-title">Payment Information</div>
                        <div class="payment-box">
                            <div class="payment-row">
                                <span class="payment-label">Transaction ID:</span>
                                <span class="payment-value">{{ $payment->transaction_id }}</span>
                            </div>
                            <div class="payment-row">
                                <span class="payment-label">Payment Method:</span>
                                <span class="payment-value">{{ ucfirst($payment->method) }}</span>
                            </div>
                            <div class="payment-row">
                                <span class="payment-label">Payment Status:</span>
                                <span class="payment-value">{{ ucfirst($payment->status) }}</span>
                            </div>
                            @if($payment->paid_at)
                            <div class="payment-row">
                                <span class="payment-label">Paid On:</span>
                                <span class="payment-value">{{ $payment->paid_at->format('M d, Y g:i A') }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="items-section">
                <div class="section-title">Invoice Items</div>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 55%;">Item</th>
                            <th style="width: 15%;" class="text-center">Qty</th>
                            <th style="width: 15%;" class="text-right">Unit Price</th>
                            <th style="width: 15%;" class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Package -->
                        <tr>
                            <td>
                                <strong>{{ $payable->package->name ?? 'Party Package' }}</strong>
                                <div class="item-description">
                                    {{ $payable->booking_date ? $payable->booking_date->format('M d, Y') : '' }}
                                    @if($payable->booking_time) at {{ \Carbon\Carbon::parse($payable->booking_time)->format('g:i A') }}@endif
                                    @if($payable->room) | Room: {{ $payable->room->name }}@endif
                                </div>
                            </td>
                            <td class="text-center">{{ $payable->participants ?? 1 }}</td>
                            <td class="text-right">${{ number_format($payable->package->price ?? 0, 2) }}</td>
                            <td class="text-right">${{ number_format($payable->package->price ?? 0, 2) }}</td>
                        </tr>

                        <!-- Add-ons -->
                        @if($payable->addOns && $payable->addOns->count() > 0)
                            @foreach($payable->addOns as $addOn)
                            <tr>
                                <td>
                                    <span style="color: #666;">+</span> {{ $addOn->name }}
                                    @if($addOn->description)
                                        <div class="item-description">{{ Str::limit($addOn->description, 60) }}</div>
                                    @endif
                                </td>
                                <td class="text-center">{{ $addOn->pivot->quantity ?? 1 }}</td>
                                <td class="text-right">${{ number_format($addOn->pivot->price_at_booking ?? $addOn->price, 2) }}</td>
                                <td class="text-right">${{ number_format(($addOn->pivot->price_at_booking ?? $addOn->price) * ($addOn->pivot->quantity ?? 1), 2) }}</td>
                            </tr>
                            @endforeach
                        @endif

                        <!-- Attractions -->
                        @if($payable->attractions && $payable->attractions->count() > 0)
                            @foreach($payable->attractions as $attraction)
                            <tr>
                                <td>
                                    <span style="color: #666;">+</span> {{ $attraction->name }}
                                    @if($attraction->description)
                                        <div class="item-description">{{ Str::limit($attraction->description, 60) }}</div>
                                    @endif
                                </td>
                                <td class="text-center">{{ $attraction->pivot->quantity ?? 1 }}</td>
                                <td class="text-right">
                                    @if(isset($attraction->pivot->price_at_booking) && $attraction->pivot->price_at_booking > 0)
                                        ${{ number_format($attraction->pivot->price_at_booking, 2) }}
                                    @else
                                        Included
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if(isset($attraction->pivot->price_at_booking) && $attraction->pivot->price_at_booking > 0)
                                        ${{ number_format($attraction->pivot->price_at_booking * ($attraction->pivot->quantity ?? 1), 2) }}
                                    @else
                                        $0.00
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="totals-section">
                <div class="totals-spacer"></div>
                <div class="totals-box">
                    <table class="totals-table">
                        <tr class="subtotal-row">
                            <td>Subtotal</td>
                            <td class="text-right">${{ number_format($payable->total_amount + ($payable->discount_amount ?? 0), 2) }}</td>
                        </tr>
                        @if($payable->discount_amount > 0)
                        <tr class="discount-row">
                            <td>
                                Discount
                                @if($payable->discount_code)
                                    <span style="font-size: 7pt;">({{ $payable->discount_code }})</span>
                                @endif
                            </td>
                            <td class="text-right">-${{ number_format($payable->discount_amount, 2) }}</td>
                        </tr>
                        @endif
                        <tr class="total-row">
                            <td><strong>Total</strong></td>
                            <td class="text-right"><strong>${{ number_format($payable->total_amount, 2) }}</strong></td>
                        </tr>
                        <tr class="paid-row">
                            <td>Amount Paid</td>
                            <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                        </tr>
                        @php
                            $balance = $payable->total_amount - ($payable->amount_paid ?? $payment->amount);
                        @endphp
                        @if($balance > 0)
                        <tr class="balance-row">
                            <td>Balance Due</td>
                            <td class="text-right" style="color: #dc2626;">${{ number_format($balance, 2) }}</td>
                        </tr>
                        @endif
                    </table>
                </div>
            </div>

            <!-- Special Requests -->
            @if($payable->special_requests)
            <div class="notes-section">
                <div class="notes-title">Special Requests</div>
                <div class="notes-content">{{ $payable->special_requests }}</div>
            </div>
            @endif

            <!-- Internal Notes (Staff Only) -->
            @if($payable->internal_notes)
            <div class="internal-notes-section">
                <div class="internal-notes-title">Internal Notes (Staff Only)</div>
                <div class="internal-notes-content">{{ $payable->internal_notes }}</div>
            </div>
            @endif

        @elseif($payable && $payment->payable_type === 'attraction_purchase')
            {{-- ========== ATTRACTION PURCHASE INVOICE ========== --}}
            
            <!-- Attraction Details -->
            <div class="attraction-box">
                <div class="attraction-name">{{ $payable->attraction->name ?? 'Attraction Ticket' }}</div>
                <div class="attraction-details">
                    @if($payable->attraction && $payable->attraction->description)
                        {{ Str::limit($payable->attraction->description, 150) }}
                    @endif
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="two-column">
                <div class="column-left">
                    <div class="section">
                        <div class="section-title">Purchase Details</div>
                        <div class="section-content">
                            <div class="info-row">
                                <span class="info-label">Transaction ID:</span>
                                <span class="info-value">{{ $payable->transaction_id ?? $payment->transaction_id }}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Purchase Date:</span>
                                <span class="info-value">{{ $payable->purchase_date ? $payable->purchase_date->format('M d, Y') : $payment->created_at->format('M d, Y') }}</span>
                            </div>
                            @if($payable->visit_date)
                            <div class="info-row">
                                <span class="info-label">Visit Date:</span>
                                <span class="info-value">{{ $payable->visit_date->format('l, F j, Y') }}</span>
                            </div>
                            @endif
                            <div class="info-row">
                                <span class="info-label">Quantity:</span>
                                <span class="info-value">{{ $payable->quantity ?? 1 }} ticket(s)</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="info-value">{{ ucfirst($payable->status ?? 'completed') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="column-right">
                    <div class="section">
                        <div class="section-title">Customer Information</div>
                        <div class="section-content">
                            <div class="info-row">
                                <span class="info-label">Name:</span>
                                <span class="info-value">
                                    @if($customer)
                                        {{ $customer->first_name }} {{ $customer->last_name }}
                                    @elseif($payable->guest_name ?? null)
                                        {{ $payable->guest_name }}
                                    @else
                                        Guest
                                    @endif
                                </span>
                            </div>
                            @if($customer && $customer->phone)
                            <div class="info-row">
                                <span class="info-label">Phone:</span>
                                <span class="info-value">{{ $customer->phone }}</span>
                            </div>
                            @endif
                            @if($customer && $customer->email)
                            <div class="info-row">
                                <span class="info-label">Email:</span>
                                <span class="info-value" style="font-size: 8pt;">{{ $customer->email }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Summary -->
            <div class="section">
                <div class="section-title">Payment Summary</div>
                <div class="payment-box">
                    <div class="payment-row">
                        <span class="payment-label">{{ $payable->attraction->name ?? 'Attraction' }} x {{ $payable->quantity ?? 1 }}</span>
                        <span class="payment-value">${{ number_format($payable->total_amount ?? $payment->amount, 2) }}</span>
                    </div>
                    <div class="payment-row" style="border-top: 1px solid #86efac; padding-top: 8px; margin-top: 8px;">
                        <span class="payment-label" style="font-weight: bold; font-size: 10pt;">Total Paid</span>
                        <span class="payment-value" style="font-size: 12pt;">${{ number_format($payment->amount, 2) }}</span>
                    </div>
                </div>
            </div>

        @else
            {{-- ========== GENERIC PAYMENT INVOICE ========== --}}
            
            <!-- Billing Section -->
            <div class="two-column">
                <div class="column-left">
                    <div class="section">
                        <div class="section-title">Bill To</div>
                        <div class="section-content">
                            @if($customer)
                                <div style="font-weight: bold; margin-bottom: 5px;">{{ $customer->first_name }} {{ $customer->last_name }}</div>
                                @if($customer->email){{ $customer->email }}<br>@endif
                                @if($customer->phone){{ $customer->phone }}@endif
                            @else
                                <div style="font-weight: bold;">Guest</div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="column-right">
                    <div class="section">
                        <div class="section-title">Payment Details</div>
                        <div class="payment-box">
                            <div class="payment-row">
                                <span class="payment-label">Transaction ID:</span>
                                <span class="payment-value">{{ $payment->transaction_id }}</span>
                            </div>
                            <div class="payment-row">
                                <span class="payment-label">Method:</span>
                                <span class="payment-value">{{ ucfirst($payment->method) }}</span>
                            </div>
                            @if($payment->paid_at)
                            <div class="payment-row">
                                <span class="payment-label">Paid On:</span>
                                <span class="payment-value">{{ $payment->paid_at->format('M d, Y') }}</span>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Amount -->
            <div class="section">
                <div class="section-title">Payment Amount</div>
                <div class="payment-box" style="text-align: center; padding: 20px;">
                    <div style="font-size: 24pt; font-weight: bold; color: #166534;">
                        ${{ number_format($payment->amount, 2) }}
                    </div>
                    <div style="font-size: 10pt; color: #666; margin-top: 5px;">{{ strtoupper($payment->currency) }}</div>
                </div>
            </div>
        @endif

        <!-- Payment Notes -->
        @if($payment->notes)
        <div class="notes-section">
            <div class="notes-title">Payment Notes</div>
            <div class="notes-content">{{ $payment->notes }}</div>
        </div>
        @endif

        <!-- Footer -->
        <div class="invoice-footer">
            <div class="thank-you">Thank you for choosing {{ $companyName ?? 'ZapZone' }}!</div>
            <div>Invoice generated on {{ now()->format('M d, Y g:i A') }}</div>
            @if($payment->status === 'refunded' && $payment->refunded_at)
                <div style="color: #991b1b; margin-top: 5px; font-weight: bold;">
                    REFUNDED on {{ $payment->refunded_at->format('M d, Y g:i A') }}
                </div>
            @endif
        </div>
    </div>
</body>
</html>
