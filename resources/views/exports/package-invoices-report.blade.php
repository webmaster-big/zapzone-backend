
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $package->name }} Invoices - {{ $companyName }}</title>
    <style>
        @page { margin: 20mm; size: A4; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 9pt; line-height: 1.6; color: #2d3748; }

        .invoice { max-width: 100%; padding: 10px; }

        /* Header */
        .header { display: table; width: 100%; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .header-left { display: table-cell; width: 60%; vertical-align: top; }
        .header-right { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
        .company-logo { max-height: 45px; max-width: 160px; margin-bottom: 8px; }
        .company-name { font-size: 16pt; font-weight: 700; color: #1a202c; letter-spacing: -0.5px; }
        .company-details { font-size: 8pt; color: #718096; margin-top: 4px; line-height: 1.6; }
        .report-label { font-size: 9pt; color: #a0aec0; text-transform: uppercase; letter-spacing: 1.5px; }
        .report-title { font-size: 12pt; font-weight: 600; color: #1a202c; margin-top: 2px; }
        .report-subtitle { font-size: 10pt; font-weight: 600; color: #4299e1; margin-top: 4px; }
        .report-date { font-size: 8pt; color: #718096; margin-top: 4px; }

        /* Summary Box */
        .summary-box {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 18px;
            margin-bottom: 25px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 8px;
        }

        .summary-value {
            font-size: 14pt;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .summary-label {
            font-size: 7pt;
            color: #a0aec0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        thead th {
            font-size: 8pt;
            font-weight: 600;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        thead th.right { text-align: right; }
        tbody td {
            font-size: 9pt;
            padding: 12px 8px;
            border-bottom: 1px solid #edf2f7;
            color: #2d3748;
            vertical-align: top;
        }
        tbody td.right { text-align: right; }
        tbody tr:nth-child(even) { background: #f7fafc; }

        .invoice-number {
            font-weight: 600;
            color: #1a202c;
            font-size: 9pt;
        }

        .booking-ref {
            font-size: 7pt;
            color: #a0aec0;
            margin-top: 2px;
        }

        .customer-name {
            font-weight: 500;
            color: #1a202c;
        }

        .customer-email {
            font-size: 7pt;
            color: #a0aec0;
            margin-top: 2px;
        }

        .amount {
            font-weight: 600;
            font-size: 9pt;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 7pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.completed { background: #c6f6d5; color: #276749; }
        .status-badge.pending { background: #fefcbf; color: #975a16; }
        .status-badge.failed { background: #fed7d7; color: #c53030; }
        .status-badge.refunded { background: #e9d8fd; color: #6b46c1; }

        .method-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 7pt;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .method-badge.card { background: #bee3f8; color: #2c5282; }
        .method-badge.cash { background: #c6f6d5; color: #276749; }

        /* Breakdown Section */
        .breakdown-section {
            background: #f7fafc;
            padding: 18px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .breakdown-title {
            font-size: 9pt;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .breakdown-row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }

        .breakdown-label {
            display: table-cell;
            font-size: 9pt;
            color: #718096;
        }

        .breakdown-value {
            display: table-cell;
            text-align: right;
            font-weight: 600;
            color: #1a202c;
            font-size: 9pt;
        }

        /* Totals Section */
        .totals-section {
            display: table;
            width: 100%;
            margin-top: 30px;
            margin-bottom: 30px;
        }

        .totals-spacer {
            display: table-cell;
            width: 55%;
        }

        .totals-box {
            display: table-cell;
            width: 45%;
            padding: 15px;
            background: #f7fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }

        .total-line {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }

        .total-label {
            display: table-cell;
            font-size: 9pt;
            color: #718096;
            padding: 4px 0;
        }

        .total-value {
            display: table-cell;
            text-align: right;
            font-size: 10pt;
            font-weight: 700;
            color: #1a202c;
            padding: 4px 0;
        }

        /* Footer */
        .footer {
            text-align: center;
            padding-top: 25px;
            margin-top: 30px;
            border-top: 1px solid #e2e8f0;
        }
        .footer-thanks {
            font-size: 10pt;
            color: #4a5568;
            margin-bottom: 6px;
        }
        .footer-meta {
            font-size: 8pt;
            color: #a0aec0;
        }
    </style>
</head>
<body>
    <div class="invoice">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                @if(isset($company) && $company && $company->logo_path)
                    @php
                        $logoPath = $company->logo_path;
                        $logoBase64 = null;

                        // If it's already a data URL, use it directly
                        if (str_starts_with($logoPath, 'data:')) {
                            $logoBase64 = $logoPath;
                        }
                        // If it's a remote URL, try to fetch and encode it
                        elseif (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
                            try {
                                $imageContent = @file_get_contents($logoPath);
                                if ($imageContent) {
                                    $mimeType = 'image/png';
                                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                                    $detectedMime = $finfo->buffer($imageContent);
                                    if ($detectedMime) $mimeType = $detectedMime;
                                    $logoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
                                }
                            } catch (\Exception $e) {
                                $logoBase64 = null;
                            }
                        }
                        // Otherwise, try to load from local storage
                        else {
                            $localPath = storage_path('app/public/' . $logoPath);
                            if (file_exists($localPath)) {
                                $imageContent = file_get_contents($localPath);
                                $mimeType = mime_content_type($localPath);
                                $logoBase64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
                            }
                        }
                    @endphp
                    @if($logoBase64)
                        <img src="{{ $logoBase64 }}" alt="{{ $company->name }}" class="company-logo" />
                    @else
                        <div class="company-name">{{ $companyName ?? 'ZapZone' }}</div>
                    @endif
                @else
                    <div class="company-name">{{ $companyName ?? 'ZapZone' }}</div>
                @endif
                <div class="company-details">
                    {{ $locationName }}
                    @if($locationName !== 'All Locations' && isset($location))
                        <br>
                        @if($location->address){{ $location->address }}<br>@endif
                        @if($location->city || $location->state){{ $location->city }}@if($location->city && $location->state), @endif{{ $location->state }} {{ $location->zip ?? '' }}@endif
                        @if($location->phone)<br>{{ $location->phone }}@endif
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="report-label">Package Report</div>
                <div class="report-title">Invoice Summary</div>
                <div class="report-subtitle">{{ $package->name }}</div>
                @if($dateRange)
                    <div class="report-date">
                        @if($dateRange['start'] === $dateRange['end'])
                            {{ \Carbon\Carbon::parse($dateRange['start'])->format('F j, Y') }}
                        @else
                            {{ \Carbon\Carbon::parse($dateRange['start'])->format('M j') }} - {{ \Carbon\Carbon::parse($dateRange['end'])->format('M j, Y') }}
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Summary Box -->
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

        <!-- Invoice Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 15%;">Invoice #</th>
                    <th style="width: 25%;">Customer</th>
                    <th style="width: 15%;">Date</th>
                    <th style="width: 10%;">Method</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 12%;" class="right">Amount</th>
                    <th style="width: 13%;">Transaction</th>
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
                        <td>
                            {{ $payment->created_at->format('M j, Y') }}<br>
                            <span style="color: #a0aec0; font-size: 7pt;">{{ $payment->created_at->format('g:i A') }}</span>
                        </td>
                        <td>
                            <span class="method-badge {{ $payment->method }}">{{ strtoupper($payment->method) }}</span>
                        </td>
                        <td>
                            <span class="status-badge {{ $payment->status }}">{{ ucfirst($payment->status) }}</span>
                        </td>
                        <td class="right">
                            <span class="amount">${{ number_format($payment->amount, 2) }}</span>
                        </td>
                        <td style="font-size: 7pt; color: #a0aec0;">
                            {{ $payment->transaction_id }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Breakdown Section -->
        <div class="breakdown-section">
            <div class="breakdown-title">Payment Status Breakdown</div>
            <div class="breakdown-row">
                <span class="breakdown-label">Completed Payments:</span>
                <span class="breakdown-value">{{ $summary['completed_count'] }} invoices · ${{ number_format($summary['completed_amount'], 2) }}</span>
            </div>
            <div class="breakdown-row">
                <span class="breakdown-label">Pending Payments:</span>
                <span class="breakdown-value">{{ $summary['pending_count'] }} invoices · ${{ number_format($summary['pending_amount'], 2) }}</span>
            </div>
            @if($summary['refunded_count'] > 0)
                <div class="breakdown-row">
                    <span class="breakdown-label">Refunded Payments:</span>
                    <span class="breakdown-value">{{ $summary['refunded_count'] }} invoices · ${{ number_format($summary['refunded_amount'], 2) }}</span>
                </div>
            @endif
        </div>

        <!-- Totals Section -->
        <div class="totals-section">
            <div class="totals-spacer"></div>
            <div class="totals-box">
                <div class="total-line">
                    <span class="total-label">Total Invoices</span>
                    <span class="total-value">{{ $summary['total_invoices'] }}</span>
                </div>
                <div class="total-line">
                    <span class="total-label">Total Amount</span>
                    <span class="total-value">${{ number_format($summary['total_amount'], 2) }}</span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-thanks">{{ $package->name }} - Invoice Report</div>
            <div class="footer-meta">{{ $companyName }} · Generated {{ now()->format('F j, Y g:i A') }}</div>
        </div>
    </div>
</body>
</html>
