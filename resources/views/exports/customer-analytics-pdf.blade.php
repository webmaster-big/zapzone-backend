<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Analytics Report</title>
    <style>
        @page {
            margin: 15mm;
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
            line-height: 1.3;
            color: #333;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .header h1 {
            color: #1e40af;
            font-size: 18pt;
            margin-bottom: 5px;
        }

        .header-info {
            font-size: 8pt;
            color: #666;
            margin-top: 8px;
        }

        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .section-title {
            background: #1e40af;
            color: white;
            padding: 5px 10px;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 8pt;
        }

        table thead {
            background: #e5e7eb;
        }

        table th {
            padding: 5px 4px;
            text-align: left;
            font-weight: bold;
            color: #1f2937;
            border-bottom: 1px solid #9ca3af;
            font-size: 8pt;
        }

        table td {
            padding: 4px;
            border-bottom: 1px solid #e5e7eb;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 7pt;
        }

        /* Column width adjustments */
        .col-rank { width: 5%; text-align: center; }
        .col-name { width: 20%; }
        .col-email { width: 25%; font-size: 7pt; }
        .col-num { width: 8%; text-align: center; }
        .col-amount { width: 12%; text-align: right; }
        .col-date { width: 10%; font-size: 7pt; }
        .col-month { width: 15%; }

        .total-row {
            background: #e5e7eb !important;
            font-weight: bold;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-small { font-size: 7pt; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Customer Analytics Report</h1>
        <div class="header-info">
            <strong>Location:</strong> {{ $locationName }} | 
            <strong>Date Range:</strong> {{ strtoupper($dateRange) }} | 
            <strong>Generated:</strong> {{ $generatedAt }} by {{ $generatedBy }}
        </div>
    </div>

        <!-- Customers Section -->
        @if(isset($data['customers']) && count($data['customers']) > 0)
        <div class="section">
            <div class="section-title">Customer List ({{ count($data['customers']) }} Total)</div>
    <!-- Customers Section -->
    @if(isset($data['customers']) && count($data['customers']) > 0)
    <div class="section">
        <div class="section-title">Customer List ({{ count($data['customers']) }} Total)</div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-name">Name</th>
                    <th class="col-email">Email</th>
                    <th class="col-num">Book</th>
                    <th class="col-num">Purch</th>
                    <th class="col-amount">Total Spent</th>
                    <th class="col-date">First Visit</th>
                    <th class="col-date">Last Visit</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['customers'] as $customer)
                <tr>
                    <td class="col-name">{{ Str::limit($customer['name'], 30) }}</td>
                    <td class="col-email text-small">{{ Str::limit($customer['email'], 35) }}</td>
                    <td class="col-num">{{ $customer['total_bookings'] }}</td>
                    <td class="col-num">{{ $customer['total_purchases'] }}</td>
                    <td class="col-amount">${{ number_format($customer['total_spent'], 2) }}</td>
                    <td class="col-date text-small">{{ $customer['first_visit'] }}</td>
                    <td class="col-date text-small">{{ $customer['last_visit'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endiflass="section">
            <div class="section-title">Revenue Trend (Last 9 Months)</div>
    <!-- Revenue by Month Section -->
    @if(isset($data['revenue_by_month']) && count($data['revenue_by_month']) > 0)
    <div class="section">
        <div class="section-title">Revenue Trend (Last 9 Months)</div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-month">Month</th>
                    <th class="col-amount">Bookings</th>
                    <th class="col-amount">Purchases</th>
                    <th class="col-amount">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['revenue_by_month'] as $revenue)
                <tr>
                    <td class="col-month"><strong>{{ $revenue['month'] }}</strong></td>
                    <td class="col-amount">${{ number_format($revenue['bookings_revenue'], 2) }}</td>
                    <td class="col-amount">${{ number_format($revenue['purchases_revenue'], 2) }}</td>
                    <td class="col-amount"><strong>${{ number_format($revenue['total_revenue'], 2) }}</strong></td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td>TOTAL</td>
                    <td class="text-right">${{ number_format(collect($data['revenue_by_month'])->sum('bookings_revenue'), 2) }}</td>
                    <td class="text-right">${{ number_format(collect($data['revenue_by_month'])->sum('purchases_revenue'), 2) }}</td>
                    <td class="text-right">${{ number_format(collect($data['revenue_by_month'])->sum('total_revenue'), 2) }}</td>
                </tr>
            </tbody>
    <!-- Top Customers Section -->
    @if(isset($data['top_customers']) && count($data['top_customers']) > 0)
    <div class="section">
        <div class="section-title">Top 20 Customers by Bookings</div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th class="col-name">Name</th>
                    <th class="col-email">Email</th>
                    <th class="col-num">Bookings</th>
                    <th class="col-amount">Total Spent</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_customers'] as $index => $customer)
                <tr>
                    <td class="col-rank"><strong>{{ $index + 1 }}</strong></td>
                    <td class="col-name">{{ Str::limit($customer['name'], 30) }}</td>
                    <td class="col-email text-small">{{ Str::limit($customer['email'], 35) }}</td>
                    <td class="col-num">{{ $customer['bookings'] }}</td>
                    <td class="col-amount">${{ number_format($customer['total_spent'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Top Activities Section -->
    @if(isset($data['top_activities']) && count($data['top_activities']) > 0)
    <div class="section">
        <div class="section-title">Top 10 Activities by Purchases</div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th>Activity Name</th>
                    <th class="col-num">Purchases</th>
                    <th class="col-amount">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_activities'] as $index => $activity)
                <tr>
                    <td class="col-rank"><strong>{{ $index + 1 }}</strong></td>
                    <td>{{ Str::limit($activity['activity'], 50) }}</td>
                    <td class="col-num">{{ $activity['purchases'] }}</td>
                    <td class="col-amount">${{ number_format($activity['revenue'], 2) }}</td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td class="text-center">{{ collect($data['top_activities'])->sum('purchases') }}</td>
                    <td class="text-right">${{ number_format(collect($data['top_activities'])->sum('revenue'), 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif

    <!-- Top Packages Section -->
    @if(isset($data['top_packages']) && count($data['top_packages']) > 0)
    <div class="section">
        <div class="section-title">Top 10 Packages by Bookings</div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-rank">#</th>
                    <th>Package Name</th>
                    <th class="col-num">Bookings</th>
                    <th class="col-amount">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['top_packages'] as $index => $package)
                <tr>
                    <td class="col-rank"><strong>{{ $index + 1 }}</strong></td>
                    <td>{{ Str::limit($package['package'], 50) }}</td>
                    <td class="col-num">{{ $package['bookings'] }}</td>
                    <td class="col-amount">${{ number_format($package['revenue'], 2) }}</td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">TOTAL</td>
                    <td class="text-center">{{ collect($data['top_packages'])->sum('bookings') }}</td>
                    <td class="text-right">${{ number_format(collect($data['top_packages'])->sum('revenue'), 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>This is an automated report generated by ZapZone Management System</p>
        <p>For questions or concerns, please contact your system administrator</p>
    </div>
</body>
</html>
