<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoices Report</title>
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
            font-size: 8pt;
            line-height: 1.3;
            color: #333;
            background: #fff;
        }

        /* Report Header */
        .report-header {
            text-align: center;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .report-header h1 {
            font-size: 16pt;
            color: #1e40af;
            margin-bottom: 5px;
        }

        .report-info {
            font-size: 8pt;
            color: #666;
        }

        /* Summary Section */
        .summary-section {
            background: #f3f4f6;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        .summary-title {
            font-size: 10pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 5px;
        }

        .summary-value {
            font-size: 12pt;
            font-weight: bold;
            color: #1e40af;
        }

        .summary-label {
            font-size: 7pt;
            color: #666;
            text-transform: uppercase;
        }

        /* Payments Table */
        .payments-section {
            margin-bottom: 20px;
        }

        .section-title {
            background: #1e40af;
            color: white;
            padding: 6px 10px;
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7pt;
        }

        table thead {
            background: #e5e7eb;
        }

        table th {
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            color: #1f2937;
            border-bottom: 1px solid #9ca3af;
            font-size: 7pt;
        }

        table td {
            padding: 5px 4px;
            border-bottom: 1px solid #e5e7eb;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 6pt;
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

        /* Type Badges */
        .type-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 2px;
            font-size: 6pt;
            font-weight: bold;
        }

        .type-booking {
            background: #dbeafe;
            color: #1e40af;
        }

        .type-attraction {
            background: #fef3c7;
            color: #92400e;
        }

        /* Column widths */
        .col-id { width: 6%; text-align: center; }
        .col-date { width: 10%; }
        .col-customer { width: 18%; }
        .col-type { width: 10%; text-align: center; }
        .col-description { width: 20%; }
        .col-method { width: 8%; text-align: center; }
        .col-status { width: 10%; text-align: center; }
        .col-amount { width: 10%; text-align: right; }

        /* Totals Row */
        .total-row {
            background: #e5e7eb !important;
            font-weight: bold;
        }

        .total-row td {
            padding: 8px 4px;
            border-top: 2px solid #1e40af;
        }

        /* Footer */
        .report-footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #999;
            font-size: 7pt;
        }

        /* Page Break */
        .page-break {
            page-break-after: always;
        }

        /* Filter Info */
        .filter-info {
            background: #eff6ff;
            padding: 8px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 7pt;
        }

        .filter-item {
            display: inline-block;
            margin-right: 15px;
        }

        .filter-label {
            color: #666;
        }

        .filter-value {
            font-weight: bold;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <!-- Report Header -->
    <div class="report-header">
        <h1>{{ $reportTitle ?? 'Payment Invoices Report' }}</h1>
        <div class="report-info">
            <strong>{{ $companyName ?? 'ZapZone' }}</strong>
            @if($locationName)
                | Location: {{ $locationName }}
            @endif
            | Generated: {{ now()->format('M d, Y h:i A') }}
        </div>
    </div>

    <!-- Filter Info -->
    @if($filters)
    <div class="filter-info">
        @if(isset($filters['date_range']))
            <span class="filter-item">
                <span class="filter-label">Date Range:</span>
                <span class="filter-value">{{ $filters['date_range'] }}</span>
            </span>
        @endif
        @if(isset($filters['status']))
            <span class="filter-item">
                <span class="filter-label">Status:</span>
                <span class="filter-value">{{ ucfirst($filters['status']) }}</span>
            </span>
        @endif
        @if(isset($filters['method']))
            <span class="filter-item">
                <span class="filter-label">Method:</span>
                <span class="filter-value">{{ ucfirst($filters['method']) }}</span>
            </span>
        @endif
        @if(isset($filters['payable_type']))
            <span class="filter-item">
                <span class="filter-label">Type:</span>
                <span class="filter-value">{{ $filters['payable_type'] === 'booking' ? 'Package Bookings' : 'Attraction Purchases' }}</span>
            </span>
        @endif
    </div>
    @endif

    <!-- Summary Section -->
    <div class="summary-section">
        <div class="summary-title">Summary</div>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-value">{{ $summary['total_count'] }}</div>
                <div class="summary-label">Total Payments</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">${{ number_format($summary['total_amount'], 2) }}</div>
                <div class="summary-label">Total Amount</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">{{ $summary['completed_count'] }}</div>
                <div class="summary-label">Completed</div>
            </div>
            <div class="summary-item">
                <div class="summary-value">${{ number_format($summary['refunded_amount'], 2) }}</div>
                <div class="summary-label">Refunded</div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="payments-section">
        <div class="section-title">Payment Details ({{ count($payments) }} Records)</div>
        <table>
            <thead>
                <tr>
                    <th class="col-id">#</th>
                    <th class="col-date">Date</th>
                    <th class="col-customer">Customer</th>
                    <th class="col-type">Type</th>
                    <th class="col-description">Description</th>
                    <th class="col-method">Method</th>
                    <th class="col-status">Status</th>
                    <th class="col-amount">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td class="col-id">{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</td>
                        <td class="col-date">{{ $payment->created_at->format('M d, Y') }}</td>
                        <td class="col-customer">
                            @if($payment->customer)
                                {{ $payment->customer->first_name }} {{ $payment->customer->last_name }}
                            @elseif($payment->payable && isset($payment->payable->guest_name))
                                {{ $payment->payable->guest_name }}
                            @else
                                Guest
                            @endif
                        </td>
                        <td class="col-type">
                            @if($payment->payable_type === 'booking')
                                <span class="type-badge type-booking">Package</span>
                            @elseif($payment->payable_type === 'attraction_purchase')
                                <span class="type-badge type-attraction">Attraction</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="col-description">
                            @if($payment->payable_type === 'booking' && $payment->payable)
                                {{ $payment->payable->package->name ?? 'Package Booking' }}
                            @elseif($payment->payable_type === 'attraction_purchase' && $payment->payable)
                                {{ $payment->payable->attraction->name ?? 'Attraction Ticket' }}
                            @else
                                {{ Str::limit($payment->notes ?? 'Payment', 25) }}
                            @endif
                        </td>
                        <td class="col-method text-center">{{ ucfirst($payment->method) }}</td>
                        <td class="col-status text-center">
                            <span class="status-badge status-{{ $payment->status }}">{{ ucfirst($payment->status) }}</span>
                        </td>
                        <td class="col-amount text-right">${{ number_format($payment->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 20px; color: #666;">
                            No payments found matching the criteria.
                        </td>
                    </tr>
                @endforelse

                @if(count($payments) > 0)
                    <tr class="total-row">
                        <td colspan="7" class="text-right"><strong>Total:</strong></td>
                        <td class="text-right"><strong>${{ number_format($summary['total_amount'], 2) }}</strong></td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    <!-- Footer -->
    <div class="report-footer">
        <div>This report was generated on {{ now()->format('M d, Y h:i A') }}</div>
        <div>© {{ date('Y') }} {{ $companyName ?? 'ZapZone' }}. All rights reserved.</div>
    </div>
</body>
</html>
