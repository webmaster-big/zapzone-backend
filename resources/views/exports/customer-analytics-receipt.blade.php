<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Analytics Receipt</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            background: #f5f5f5;
            padding: 20px;
        }

        .receipt {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .header {
            text-align: center;
            border-bottom: 2px dashed #000;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }

        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .header-info {
            font-size: 11px;
            margin-top: 10px;
        }

        .divider {
            border-bottom: 1px dashed #000;
            margin: 15px 0;
        }

        .divider-thick {
            border-bottom: 2px solid #000;
            margin: 15px 0;
        }

        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            margin: 15px 0 10px 0;
            font-size: 13px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }

        .row-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
        }

        .row-item .label {
            flex: 1;
            padding-right: 10px;
        }

        .row-item .value {
            text-align: right;
            white-space: nowrap;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-weight: bold;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-weight: bold;
            font-size: 14px;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            margin-top: 10px;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 2px dashed #000;
            padding-top: 15px;
        }

        .list-item {
            padding: 5px 0;
            border-bottom: 1px dotted #ccc;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .small-text {
            font-size: 10px;
            color: #666;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .receipt {
                box-shadow: none;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Header -->
        <div class="header">
            <h1>★ CUSTOMER ANALYTICS ★</h1>
            <div style="font-size: 14px; margin: 10px 0;">{{ $locationName }}</div>
            <div class="header-info">
                <div>PERIOD: {{ strtoupper($dateRange) }}</div>
                <div>{{ $generatedAt }}</div>
                <div class="small-text">BY: {{ $generatedBy }}</div>
            </div>
        </div>

        <!-- Summary Section -->
        @if(isset($data['customers']) || isset($data['revenue_by_month']))
        <div class="section-title">═══ SUMMARY ═══</div>

        @if(isset($data['customers']))
        <div class="row-item">
            <span class="label">Total Customers:</span>
            <span class="value bold">{{ count($data['customers']) }}</span>
        </div>
        <div class="row-item">
            <span class="label">Total Spent:</span>
            <span class="value bold">${{ number_format(collect($data['customers'])->sum('total_spent'), 2) }}</span>
        </div>
        <div class="row-item">
            <span class="label">Total Bookings:</span>
            <span class="value">{{ collect($data['customers'])->sum('total_bookings') }}</span>
        </div>
        <div class="row-item">
            <span class="label">Total Purchases:</span>
            <span class="value">{{ collect($data['customers'])->sum('total_purchases') }}</span>
        </div>
        @endif

        @if(isset($data['revenue_by_month']))
        <div class="divider"></div>
        <div class="row-item">
            <span class="label">Period Revenue:</span>
            <span class="value bold">${{ number_format(collect($data['revenue_by_month'])->sum('total_revenue'), 2) }}</span>
        </div>
        @endif
        <div class="divider-thick"></div>
        @endif

        <!-- Revenue by Month Section -->
        @if(isset($data['revenue_by_month']) && count($data['revenue_by_month']) > 0)
        <div class="section-title">═══ REVENUE TREND ═══</div>
        @foreach($data['revenue_by_month'] as $revenue)
        <div class="row-item">
            <span class="label">{{ $revenue['month'] }}</span>
            <span class="value">${{ number_format($revenue['total_revenue'], 2) }}</span>
        </div>
        @endforeach
        <div class="total-row">
            <span>TOTAL REVENUE:</span>
            <span>${{ number_format(collect($data['revenue_by_month'])->sum('total_revenue'), 2) }}</span>
        </div>
        @endif

        <!-- Top Customers Section -->
        @if(isset($data['top_customers']) && count($data['top_customers']) > 0)
        <div class="section-title">═══ TOP CUSTOMERS ═══</div>
        @foreach(array_slice($data['top_customers'], 0, 10) as $index => $customer)
        <div class="list-item">
            <div class="bold">{{ $index + 1 }}. {{ $customer['name'] }}</div>
            <div class="row-item small-text">
                <span class="label">{{ $customer['email'] }}</span>
                <span class="value">${{ number_format($customer['total_spent'], 2) }}</span>
            </div>
            <div class="small-text">Bookings: {{ $customer['bookings'] }}</div>
        </div>
        @endforeach
        <div class="divider"></div>
        @endif

        <!-- Top Activities Section -->
        @if(isset($data['top_activities']) && count($data['top_activities']) > 0)
        <div class="section-title">═══ TOP ACTIVITIES ═══</div>
        @foreach($data['top_activities'] as $index => $activity)
        <div class="list-item">
            <div class="row-item">
                <span class="label bold">{{ $index + 1 }}. {{ $activity['activity'] }}</span>
                <span class="value bold">${{ number_format($activity['revenue'], 2) }}</span>
            </div>
            <div class="small-text">Purchases: {{ $activity['purchases'] }}</div>
        </div>
        @endforeach
        <div class="summary-row">
            <span>TOTAL:</span>
            <span>${{ number_format(collect($data['top_activities'])->sum('revenue'), 2) }}</span>
        </div>
        <div class="divider"></div>
        @endif

        <!-- Top Packages Section -->
        @if(isset($data['top_packages']) && count($data['top_packages']) > 0)
        <div class="section-title">═══ TOP PACKAGES ═══</div>
        @foreach($data['top_packages'] as $index => $package)
        <div class="list-item">
            <div class="row-item">
                <span class="label bold">{{ $index + 1 }}. {{ $package['package'] }}</span>
                <span class="value bold">${{ number_format($package['revenue'], 2) }}</span>
            </div>
            <div class="small-text">Bookings: {{ $package['bookings'] }}</div>
        </div>
        @endforeach
        <div class="summary-row">
            <span>TOTAL:</span>
            <span>${{ number_format(collect($data['top_packages'])->sum('revenue'), 2) }}</span>
        </div>
        <div class="divider"></div>
        @endif

        <!-- All Customers List (Compact) -->
        @if(isset($data['customers']) && count($data['customers']) > 0)
        <div class="section-title">═══ ALL CUSTOMERS ({{ count($data['customers']) }}) ═══</div>
        @foreach($data['customers'] as $index => $customer)
        <div class="list-item">
            <div class="row-item">
                <span class="label bold">{{ $customer['name'] }}</span>
                <span class="value">${{ number_format($customer['total_spent'], 2) }}</span>
            </div>
            <div class="small-text">{{ $customer['email'] }}</div>
            <div class="row-item small-text">
                <span>B:{{ $customer['total_bookings'] }} P:{{ $customer['total_purchases'] }}</span>
                <span>{{ $customer['first_visit'] }} - {{ $customer['last_visit'] }}</span>
            </div>
        </div>
        @endforeach
        @endif

        <!-- Footer -->
        <div class="footer">
            <div style="margin-bottom: 10px;">═══════════════════════</div>
            <div>ZAPZONE MANAGEMENT SYSTEM</div>
            <div>AUTOMATED REPORT</div>
            <div style="margin-top: 10px;">Thank you for using our system!</div>
            <div style="margin-top: 5px; font-size: 9px;">
                Report ID: {{ md5($generatedAt . $locationName) }}
            </div>
        </div>
    </div>

    <script>
        // Auto-print option (can be triggered via query parameter)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('print') === 'true') {
            window.onload = function() {
                window.print();
            };
        }
    </script>
</body>
</html>
