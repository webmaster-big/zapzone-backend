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
            margin-bottom: 20px;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 15px;
        }

        .company-info {
            display: table-cell;
            width: 60%;
            vertical-align: top;
        }

        .company-name {
            font-size: 16pt;
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
            width: 40%;
            text-align: right;
            vertical-align: top;
        }

        .invoice-title h1 {
            font-size: 20pt;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .invoice-number {
            font-size: 10pt;
            color: #666;
        }

        .invoice-date {
            font-size: 8pt;
            color: #666;
            margin-top: 3px;
        }

        /* Billing Info */
        .billing-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .bill-to, .payment-info {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .section-label {
            font-size: 8pt;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .customer-name {
            font-size: 10pt;
            font-weight: bold;
            color: #333;
            margin-bottom: 3px;
        }

        .customer-details {
            font-size: 8pt;
            color: #666;
            line-height: 1.5;
        }

        .payment-info {
            text-align: right;
        }

        .payment-detail {
            font-size: 8pt;
            margin-bottom: 3px;
        }

        .payment-detail strong {
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

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-refunded {
            background: #e0e7ff;
            color: #3730a3;
        }

        /* Items Table */
        .items-section {
            margin-bottom: 20px;
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
            margin-bottom: 20px;
        }

        .totals-spacer {
            display: table-cell;
            width: 60%;
        }

        .totals-box {
            display: table-cell;
            width: 40%;
        }

        .totals-table {
            width: 100%;
        }

        .totals-table td {
            padding: 5px 6px;
            border-bottom: none;
        }

        .totals-table .total-row {
            font-size: 11pt;
            font-weight: bold;
            background: #f3f4f6;
        }

        .totals-table .total-row td {
            padding: 10px 6px;
            border-top: 2px solid #1e40af;
        }

        /* Notes */
        .notes-section {
            background: #f9fafb;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .notes-title {
            font-size: 8pt;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .notes-content {
            font-size: 8pt;
            color: #666;
        }

        /* Footer */
        .invoice-footer {
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            color: #999;
            font-size: 7pt;
        }

        .thank-you {
            font-size: 10pt;
            color: #1e40af;
            font-weight: bold;
            margin-bottom: 5px;
        }

        /* Page Break for multiple invoices */
        .page-break {
            page-break-after: always;
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
                <div class="invoice-date">Date: {{ $payment->created_at->format('M d, Y') }}</div>
            </div>
        </div>

        <!-- Billing Section -->
        <div class="billing-section">
            <div class="bill-to">
                <div class="section-label">Bill To</div>
                @if($customer)
                    <div class="customer-name">{{ $customer->first_name }} {{ $customer->last_name }}</div>
                    <div class="customer-details">
                        @if($customer->email){{ $customer->email }}<br>@endif
                        @if($customer->phone){{ $customer->phone }}<br>@endif
                        @if($customer->address){{ $customer->address }}<br>@endif
                        @if($customer->city || $customer->state || $customer->zip)
                            {{ $customer->city }}@if($customer->city && $customer->state), @endif{{ $customer->state }} {{ $customer->zip }}
                        @endif
                    </div>
                @elseif($payable && isset($payable->guest_name))
                    <div class="customer-name">{{ $payable->guest_name }}</div>
                    <div class="customer-details">
                        @if(isset($payable->guest_email)){{ $payable->guest_email }}<br>@endif
                        @if(isset($payable->guest_phone)){{ $payable->guest_phone }}<br>@endif
                        @if(isset($payable->guest_address)){{ $payable->guest_address }}<br>@endif
                        @if(isset($payable->guest_city) || isset($payable->guest_state) || isset($payable->guest_zip))
                            {{ $payable->guest_city ?? '' }}@if(isset($payable->guest_city) && isset($payable->guest_state)), @endif{{ $payable->guest_state ?? '' }} {{ $payable->guest_zip ?? '' }}
                        @endif
                    </div>
                @else
                    <div class="customer-name">Guest</div>
                @endif
            </div>
            <div class="payment-info">
                <div class="section-label">Payment Details</div>
                <div class="payment-detail">
                    <strong>Transaction ID:</strong> {{ $payment->transaction_id }}
                </div>
                <div class="payment-detail">
                    <strong>Method:</strong> {{ ucfirst($payment->method) }}
                </div>
                <div class="payment-detail">
                    <strong>Status:</strong>
                    <span class="status-badge status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span>
                </div>
                @if($payment->paid_at)
                <div class="payment-detail">
                    <strong>Paid:</strong> {{ $payment->paid_at->format('M d, Y h:i A') }}
                </div>
                @endif
            </div>
        </div>

        <!-- Items Table -->
        <div class="items-section">
            <table>
                <thead>
                    <tr>
                        <th style="width: 45%;">Description</th>
                        <th style="width: 15%;" class="text-center">Type</th>
                        <th style="width: 15%;" class="text-center">Qty</th>
                        <th style="width: 25%;" class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @if($payable)
                        @if($payment->payable_type === 'booking')
                            <tr>
                                <td>
                                    <strong>{{ $payable->package->name ?? 'Package Booking' }}</strong>
                                    <div class="item-description">
                                        Booking Date: {{ $payable->booking_date ? $payable->booking_date->format('M d, Y') : 'N/A' }}
                                        @if($payable->booking_time)
                                            at {{ \Carbon\Carbon::parse($payable->booking_time)->format('h:i A') }}
                                        @endif
                                        <br>
                                        Reference: {{ $payable->reference_number ?? 'N/A' }}
                                    </div>
                                </td>
                                <td class="text-center">Package</td>
                                <td class="text-center">{{ $payable->participants ?? 1 }}</td>
                                <td class="text-right">${{ number_format($payable->total_amount ?? $payment->amount, 2) }}</td>
                            </tr>
                        @elseif($payment->payable_type === 'attraction_purchase')
                            <tr>
                                <td>
                                    <strong>{{ $payable->attraction->name ?? 'Attraction Ticket' }}</strong>
                                    <div class="item-description">
                                        Purchase Date: {{ $payable->purchase_date ? $payable->purchase_date->format('M d, Y') : $payment->created_at->format('M d, Y') }}
                                        <br>
                                        Transaction: {{ $payable->transaction_id ?? 'N/A' }}
                                    </div>
                                </td>
                                <td class="text-center">Attraction</td>
                                <td class="text-center">{{ $payable->quantity ?? 1 }}</td>
                                <td class="text-right">${{ number_format($payable->total_amount ?? $payment->amount, 2) }}</td>
                            </tr>
                        @endif
                    @else
                        <tr>
                            <td>
                                <strong>Payment</strong>
                                <div class="item-description">{{ $payment->notes ?? 'Payment transaction' }}</div>
                            </td>
                            <td class="text-center">-</td>
                            <td class="text-center">1</td>
                            <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="totals-section">
            <div class="totals-spacer"></div>
            <div class="totals-box">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal</td>
                        <td class="text-right">${{ number_format($payment->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Tax</td>
                        <td class="text-right">$0.00</td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>Total ({{ $payment->currency }})</strong></td>
                        <td class="text-right"><strong>${{ number_format($payment->amount, 2) }}</strong></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Notes -->
        @if($payment->notes)
        <div class="notes-section">
            <div class="notes-title">Notes</div>
            <div class="notes-content">{{ $payment->notes }}</div>
        </div>
        @endif

        <!-- Footer -->
        <div class="invoice-footer">
            <div class="thank-you">Thank you for your business!</div>
            <div>This invoice was generated on {{ now()->format('M d, Y h:i A') }}</div>
            @if($payment->status === 'refunded' && $payment->refunded_at)
                <div style="color: #991b1b; margin-top: 5px;">
                    Refunded on {{ $payment->refunded_at->format('M d, Y h:i A') }}
                </div>
            @endif
        </div>
    </div>
</body>
</html>
