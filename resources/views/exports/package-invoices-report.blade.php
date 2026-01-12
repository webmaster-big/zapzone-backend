<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $package->name }} Invoices - {{ $companyName }}</title>
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
        
        .header .package-name {
            font-size: 16px;
            color: #1e40af;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 11px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .header .date-range {
            font-size: 11px;
            color: #444;
            font-weight: bold;
        }
        
        .summary-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .summary-grid {
            display: flex;
            justify-content: space-between;
        }
        
        .summary-item {
            flex: 1;
            text-align: center;
        }
        
        .summary-value {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .summary-label {
            font-size: 9px;
            opacity: 0.9;
            text-transform: uppercase;
        }
        
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .invoice-table thead {
            background: #f3f4f6;
        }
        
        .invoice-table th {
            padding: 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #374151;
            border-bottom: 2px solid #d1d5db;
        }
        
        .invoice-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9px;
        }
        
        .invoice-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .invoice-table tbody tr:nth-child(even) {
            background: #fafafa;
        }
        
        .invoice-number {
            font-weight: bold;
            color: #2563eb;
            font-size: 10px;
        }
        
        .booking-ref {
            font-size: 8px;
            color: #666;
        }
        
        .customer-name {
            font-weight: 500;
            color: #111827;
        }
        
        .customer-email {
            font-size: 8px;
            color: #6b7280;
        }
        
        .amount {
            font-weight: bold;
            font-size: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-badge.completed { background: #dcfce7; color: #166534; }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.failed { background: #fee2e2; color: #991b1b; }
        .status-badge.refunded { background: #f3f4f6; color: #374151; }
        
        .method-badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 500;
        }
        
        .method-badge.card { background: #dbeafe; color: #1e40af; }
        .method-badge.cash { background: #d1fae5; color: #065f46; }
        
        .totals-section {
            margin-top: 30px;
            border-top: 2px solid #2563eb;
            padding-top: 15px;
        }
        
        .totals-grid {
            display: flex;
            justify-content: flex-end;
            gap: 40px;
        }
        
        .total-item {
            text-align: right;
        }
        
        .total-label {
            font-size: 10px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .total-value {
            font-size: 14px;
            font-weight: bold;
            color: #2563eb;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .breakdown-section {
            margin-top: 20px;
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .breakdown-title {
            font-size: 12px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 10px;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 5px;
        }
        
        .breakdown-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 10px;
        }
        
        .breakdown-label {
            color: #6b7280;
        }
        
        .breakdown-value {
            font-weight: 600;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Package Invoices Report</h1>
        <div class="package-name">{{ $package->name }}</div>
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
    </div>
    
    <div class="summary-box">
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $summary['total_invoices'] }}</div>
                <div class="summary-label">Total Invoices</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $summary['total_bookings'] }}</div>
                <div class="summary-label">Unique Bookings</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">${{ number_format($summary['total_amount'], 2) }}</div>
                <div class="summary-label">Total Amount</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">${{ number_format($summary['completed_amount'], 2) }}</div>
                <div class="summary-label">Collected</div>
            </div>
        </div>
    </div>
    
    <table class="invoice-table">
        <thead>
            <tr>
                <th style="width: 15%;">Invoice #</th>
                <th style="width: 25%;">Customer</th>
                <th style="width: 15%;">Date</th>
                <th style="width: 10%;">Method</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 12%; text-align: right;">Amount</th>
                <th style="width: 13%;">Transaction ID</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $payment)
                <tr>
                    <td>
                        <div class="invoice-number">INV-{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</div>
                        @if($payment->payable)
                            <div class="booking-ref">{{ $payment->payable->reference_number }}</div>
                        @endif
                    </td>
                    <td>
                        <div class="customer-name">
                            @if($payment->customer)
                                {{ $payment->customer->first_name }} {{ $payment->customer->last_name }}
                            @elseif($payment->payable && $payment->payable->guest_name)
                                {{ $payment->payable->guest_name }}
                            @else
                                Guest Customer
                            @endif
                        </div>
                        <div class="customer-email">
                            @if($payment->customer)
                                {{ $payment->customer->email }}
                            @elseif($payment->payable)
                                {{ $payment->payable->guest_email }}
                            @endif
                        </div>
                    </td>
                    <td>{{ $payment->created_at->format('M j, Y') }}<br><span style="color: #999; font-size: 8px;">{{ $payment->created_at->format('g:i A') }}</span></td>
                    <td><span class="method-badge {{ $payment->method }}">{{ strtoupper($payment->method) }}</span></td>
                    <td><span class="status-badge {{ $payment->status }}">{{ ucfirst($payment->status) }}</span></td>
                    <td style="text-align: right;"><span class="amount">${{ number_format($payment->amount, 2) }}</span></td>
                    <td style="font-size: 8px; color: #666;">{{ $payment->transaction_id }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    
    <div class="breakdown-section">
        <div class="breakdown-title">Payment Status Breakdown</div>
        <div class="breakdown-row">
            <span class="breakdown-label">Completed Payments:</span>
            <span class="breakdown-value">{{ $summary['completed_count'] }} invoices - ${{ number_format($summary['completed_amount'], 2) }}</span>
        </div>
        <div class="breakdown-row">
            <span class="breakdown-label">Pending Payments:</span>
            <span class="breakdown-value">{{ $summary['pending_count'] }} invoices - ${{ number_format($summary['pending_amount'], 2) }}</span>
        </div>
        @if($summary['refunded_count'] > 0)
            <div class="breakdown-row">
                <span class="breakdown-label">Refunded Payments:</span>
                <span class="breakdown-value">{{ $summary['refunded_count'] }} invoices - ${{ number_format($summary['refunded_amount'], 2) }}</span>
            </div>
        @endif
    </div>
    
    <div class="totals-section">
        <div class="totals-grid">
            <div class="total-item">
                <div class="total-label">Total Invoices</div>
                <div class="total-value">{{ $summary['total_invoices'] }}</div>
            </div>
            <div class="total-item">
                <div class="total-label">Total Amount</div>
                <div class="total-value">${{ number_format($summary['total_amount'], 2) }}</div>
            </div>
        </div>
    </div>
    
    <div class="footer">
        Generated on {{ now()->format('F j, Y g:i A') }} | {{ $companyName }} - Package Invoice Report
    </div>
</body>
</html>
