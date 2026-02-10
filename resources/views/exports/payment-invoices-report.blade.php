<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportTitle ?? 'Payment Report' }}</title>
    <style>
        @page { margin: 18mm; size: A4; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 8pt; line-height: 1.5; color: #2d3748; padding: 10px; }

        /* Header */
        .header { display: table; width: 100%; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0; }
        .header-left { display: table-cell; width: 60%; vertical-align: top; }
        .header-right { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
        .company-logo { max-height: 40px; max-width: 140px; margin-bottom: 6px; }
        .report-title { font-size: 14pt; font-weight: 700; color: #1a202c; }
        .report-subtitle { font-size: 9pt; color: #718096; margin-top: 4px; }
        .company-name { font-size: 10pt; font-weight: 600; color: #4a5568; }
        .report-meta { font-size: 8pt; color: #a0aec0; margin-top: 4px; }

        /* Filters */
        .filters { background: #f7fafc; padding: 14px 18px; border-radius: 4px; margin-bottom: 20px; font-size: 9pt; }
        .filter-item { display: inline-block; margin-right: 25px; }
        .filter-label { color: #718096; }
        .filter-value { font-weight: 600; color: #2d3748; }

        /* Summary */
        .summary { display: table; width: 100%; margin-bottom: 20px; }
        .summary-item { display: table-cell; width: 25%; text-align: center; padding: 14px 10px; background: #f7fafc; }
        .summary-item:first-child { border-radius: 4px 0 0 4px; }
        .summary-item:last-child { border-radius: 0 4px 4px 0; }
        .summary-value { font-size: 13pt; font-weight: 700; color: #1a202c; }
        .summary-label { font-size: 7pt; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        thead th { font-size: 8pt; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 10px; border-bottom: 2px solid #e2e8f0; text-align: left; }
        thead th.right { text-align: right; }
        thead th.center { text-align: center; }
        tbody td { font-size: 9pt; padding: 14px 10px; border-bottom: 1px solid #edf2f7; color: #2d3748; }
        tbody td.right { text-align: right; }
        tbody td.center { text-align: center; }
        tbody tr:nth-child(even) { background: #fafafa; }

        /* Status */
        .status { display: inline-block; padding: 4px 10px; border-radius: 10px; font-size: 7pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .status-completed { background: #c6f6d5; color: #276749; }
        .status-pending { background: #fefcbf; color: #975a16; }
        .status-failed { background: #fed7d7; color: #c53030; }
        .status-refunded { background: #e9d8fd; color: #6b46c1; }

        /* Type */
        .type { display: inline-block; padding: 4px 8px; border-radius: 3px; font-size: 7pt; font-weight: 600; }
        .type-booking { background: #e2e8f0; color: #4a5568; }
        .type-attraction { background: #fef3c7; color: #92400e; }

        /* Total Row */
        .total-row { background: #edf2f7 !important; }
        .total-row td { font-weight: 600; padding: 16px 10px; border-top: 2px solid #cbd5e0; font-size: 10pt; }

        /* Footer */
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
        .footer-text { font-size: 8pt; color: #a0aec0; }

        /* Empty */
        .empty { text-align: center; padding: 40px; color: #a0aec0; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="report-title">{{ $reportTitle ?? 'Payment Report' }}</div>
            <div class="report-subtitle">{{ $locationName ?? 'All Locations' }}</div>
        </div>
        <div class="header-right">
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
            <div class="report-meta">Generated {{ now()->timezone($timezone)->format('M j, Y g:i A') }}</div>
        </div>
    </div>

    @if($filters)
    <div class="filters">
        @if(isset($filters['date_range']))
            <span class="filter-item"><span class="filter-label">Period:</span> <span class="filter-value">{{ $filters['date_range'] }}</span></span>
        @endif
        @if(isset($filters['status']))
            <span class="filter-item"><span class="filter-label">Status:</span> <span class="filter-value">{{ ucfirst($filters['status']) }}</span></span>
        @endif
        @if(isset($filters['method']))
            <span class="filter-item"><span class="filter-label">Method:</span> <span class="filter-value">{{ ucfirst($filters['method']) }}</span></span>
        @endif
        @if(isset($filters['payable_type']))
            <span class="filter-item"><span class="filter-label">Type:</span> <span class="filter-value">{{ $filters['payable_type'] === 'booking' ? 'Packages' : 'Attractions' }}</span></span>
        @endif
    </div>
    @endif

    <div class="summary">
        <div class="summary-item">
            <div class="summary-value">{{ $summary['total_count'] }}</div>
            <div class="summary-label">Transactions</div>
        </div>
        <div class="summary-item">
            <div class="summary-value">${{ number_format($summary['total_amount'], 2) }}</div>
            <div class="summary-label">Total</div>
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

    <table>
        <thead>
            <tr>
                <th style="width: 7%;">#</th>
                <th style="width: 12%;">Date</th>
                <th style="width: 20%;">Customer</th>
                <th style="width: 8%;" class="center">Type</th>
                <th style="width: 21%;">Description</th>
                <th style="width: 8%;" class="center">Method</th>
                <th style="width: 10%;" class="center">Status</th>
                <th style="width: 14%;" class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $payment)
            <tr>
                <td>{{ str_pad($payment->id, 5, '0', STR_PAD_LEFT) }}</td>
                <td>{{ $payment->created_at->timezone($timezone)->format('M j, Y') }}</td>
                <td>
                    @if($payment->customer)
                        {{ $payment->customer->first_name }} {{ $payment->customer->last_name }}
                    @elseif($payment->payable && isset($payment->payable->guest_name))
                        {{ $payment->payable->guest_name }}
                    @else
                        Guest
                    @endif
                </td>
                <td class="center">
                    @if($payment->payable_type === 'booking')
                        <span class="type type-booking">PKG</span>
                    @elseif($payment->payable_type === 'attraction_purchase')
                        <span class="type type-attraction">ATR</span>
                    @else
                        —
                    @endif
                </td>
                <td>
                    @if($payment->payable_type === 'booking' && $payment->payable)
                        {{ Str::limit($payment->payable->package->name ?? 'Package', 28) }}
                    @elseif($payment->payable_type === 'attraction_purchase' && $payment->payable)
                        {{ Str::limit($payment->payable->attraction->name ?? 'Attraction', 28) }}
                    @else
                        {{ Str::limit($payment->notes ?? 'Payment', 28) }}
                    @endif
                </td>
                <td class="center">{{ ucfirst($payment->method) }}</td>
                <td class="center"><span class="status status-{{ $payment->status }}">{{ $payment->status }}</span></td>
                <td class="right">${{ number_format($payment->amount, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="empty">No payments found</td>
            </tr>
            @endforelse

            @if(count($payments) > 0)
            <tr class="total-row">
                <td colspan="7" class="right">Total ({{ count($payments) }} payments)</td>
                <td class="right">${{ number_format($summary['total_amount'], 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        <div class="footer-text">{{ $companyName ?? 'ZapZone' }} · Report generated {{ now()->timezone($timezone)->format('M j, Y g:i A') }}</div>
    </div>
</body>
</html>
