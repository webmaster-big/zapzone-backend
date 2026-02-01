<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #{{ $payment->id }}</title>
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
        .invoice-label { font-size: 9pt; color: #a0aec0; text-transform: uppercase; letter-spacing: 1.5px; }
        .invoice-number { font-size: 12pt; font-weight: 600; color: #1a202c; margin-top: 2px; }
        .invoice-date { font-size: 8pt; color: #718096; margin-top: 4px; }

        /* Status */
        .status { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 7pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 8px; }
        .status-completed { background: #c6f6d5; color: #276749; }
        .status-pending { background: #fefcbf; color: #975a16; }
        .status-failed { background: #fed7d7; color: #c53030; }
        .status-refunded { background: #e9d8fd; color: #6b46c1; }

        /* Divider */
        .divider { height: 1px; background: #e2e8f0; margin: 20px 0; }

        /* Party/Event Info */
        .event-banner { background: #f7fafc; border-left: 3px solid #4a5568; padding: 12px 18px; margin-bottom: 20px; }
        .event-title { font-size: 11pt; font-weight: 600; color: #1a202c; }
        .event-details { font-size: 9pt; color: #4a5568; margin-top: 4px; }
        .event-meta { font-size: 8pt; color: #718096; margin-top: 6px; }

        /* Guest of Honor */
        .guest-banner { text-align: center; padding: 12px 18px; margin-bottom: 18px; border: 1px solid #e2e8f0; border-radius: 4px; }
        .guest-label { font-size: 7pt; color: #a0aec0; text-transform: uppercase; letter-spacing: 1px; }
        .guest-name { font-size: 13pt; font-weight: 600; color: #2d3748; margin-top: 3px; }
        .guest-info { font-size: 8pt; color: #718096; margin-top: 3px; }

        /* Two Column */
        .row { display: table; width: 100%; margin-bottom: 25px; }
        .col { display: table-cell; width: 50%; vertical-align: top; }
        .col:first-child { padding-right: 20px; }
        .col:last-child { padding-left: 20px; }

        /* Info Block */
        .info-block { margin-bottom: 20px; }
        .info-title { font-size: 8pt; font-weight: 600; color: #a0aec0; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid #edf2f7; }
        .info-content { font-size: 9pt; color: #2d3748; }
        .info-content strong { font-weight: 600; }
        .info-line { margin-bottom: 5px; }
        .info-muted { color: #718096; font-size: 8pt; }

        /* Table */
        table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        thead th { font-size: 8pt; font-weight: 600; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 8px; border-bottom: 2px solid #e2e8f0; text-align: left; }
        thead th.right { text-align: right; }
        tbody td { font-size: 9pt; padding: 14px 8px; border-bottom: 1px solid #edf2f7; color: #2d3748; vertical-align: top; }
        tbody td.right { text-align: right; }
        tbody td.center { text-align: center; }
        .item-desc { font-size: 8pt; color: #a0aec0; margin-top: 4px; }

        /* Totals */
        .totals { display: table; width: 100%; margin-bottom: 30px; }
        .totals-spacer { display: table-cell; width: 55%; }
        .totals-box { display: table-cell; width: 45%; padding: 15px; background: #f7fafc; border-radius: 4px; }
        .total-line { display: table; width: 100%; margin-bottom: 10px; }
        .total-label { display: table-cell; font-size: 9pt; color: #718096; padding: 4px 0; }
        .total-value { display: table-cell; text-align: right; font-size: 9pt; color: #2d3748; padding: 4px 0; }
        .total-main { border-top: 2px solid #cbd5e0; padding-top: 10px; margin-top: 10px; }
        .total-main .total-label { font-weight: 600; color: #1a202c; font-size: 10pt; }
        .total-main .total-value { font-weight: 700; color: #1a202c; font-size: 11pt; }
        .total-paid { color: #38a169; }
        .total-balance { color: #e53e3e; font-weight: 600; }

        /* Notes */
        .notes { background: #f7fafc; padding: 15px 18px; border-radius: 4px; margin-bottom: 20px; }
        .notes-title { font-size: 8pt; font-weight: 600; color: #a0aec0; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .notes-content { font-size: 9pt; color: #4a5568; white-space: pre-wrap; line-height: 1.6; }

        .internal-notes { background: #fff5f5; border-left: 3px solid #fc8181; }
        .internal-notes .notes-title { color: #c53030; }
        .internal-notes .notes-content { color: #742a2a; }

        /* Footer */
        .footer { text-align: center; padding-top: 25px; margin-top: 30px; border-top: 1px solid #e2e8f0; }
        .footer-thanks { font-size: 11pt; color: #4a5568; margin-bottom: 6px; }
        .footer-meta { font-size: 8pt; color: #a0aec0; }
        .footer-refund { font-size: 9pt; color: #c53030; font-weight: 600; margin-top: 8px; }

        /* Payment Box */
        .payment-summary { background: #f0fff4; border: 1px solid #9ae6b4; border-radius: 4px; padding: 20px 18px; text-align: center; margin: 20px 0; }
        .payment-amount { font-size: 18pt; font-weight: 700; color: #276749; }
        .payment-label { font-size: 8pt; color: #68d391; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
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
                    @if($location)
                        {{ $location->name }}<br>
                        @if($location->address){{ $location->address }}<br>@endif
                        @if($location->city || $location->state){{ $location->city }}@if($location->city && $location->state), @endif{{ $location->state }} {{ $location->zip ?? '' }}@endif
                        @if($location->phone)<br>{{ $location->phone }}@endif
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="invoice-label">Invoice</div>
                <div class="invoice-number">#{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</div>
                <div class="invoice-date">{{ $payment->created_at->format('F j, Y') }}</div>
                <span class="status status-{{ $payment->status }}">{{ $payment->status }}</span>
            </div>
        </div>

        @if($payable && $payment->payable_type === 'booking')
            {{-- BOOKING INVOICE --}}

            @if($payable->guest_of_honor_name)
            <div class="guest-banner">
                <div class="guest-label">Guest of Honor</div>
                <div class="guest-name">{{ $payable->guest_of_honor_name }}</div>
                <div class="guest-info">
                    @if($payable->guest_of_honor_age)Turning {{ $payable->guest_of_honor_age }}@endif
                    @if($payable->guest_of_honor_gender) · {{ ucfirst($payable->guest_of_honor_gender) }}@endif
                </div>
            </div>
            @endif

            <div class="event-banner">
                <div class="event-title">{{ $payable->package->name ?? 'Party Package' }}</div>
                <div class="event-details">
                    {{ $payable->booking_date ? $payable->booking_date->format('l, F j, Y') : 'Date TBD' }}
                    @if($payable->booking_time) at {{ \Carbon\Carbon::parse($payable->booking_time)->format('g:i A') }}@endif
                </div>
                <div class="event-meta">
                    @if($payable->room)Room: {{ $payable->room->name }}@if($payable->room->capacity) ({{ $payable->room->capacity }} capacity)@endif · @endif
                    @if($payable->participants){{ $payable->participants }} guests · @endif
                    Ref: {{ $payable->reference_number ?? 'N/A' }}
                    @if($payable->created_at) · Booked: {{ $payable->created_at->format('M j, Y g:i A') }}@endif
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Contact Information</div>
                        <div class="info-content">
                            <div class="info-line"><strong>{{ $customer ? $customer->first_name . ' ' . $customer->last_name : ($payable->guest_name ?? 'Guest') }}</strong></div>
                            @if($customer && $customer->phone)<div class="info-line">{{ $customer->phone }}</div>@elseif($payable->guest_phone)<div class="info-line">{{ $payable->guest_phone }}</div>@endif
                            @if($customer && $customer->email)<div class="info-line info-muted">{{ $customer->email }}</div>@elseif($payable->guest_email)<div class="info-line info-muted">{{ $payable->guest_email }}</div>@endif
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Payment Details</div>
                        <div class="info-content">
                            <div class="info-line">{{ ucfirst($payment->method) }} · {{ $payment->transaction_id }}</div>
                            @if($payment->paid_at)<div class="info-line info-muted">Paid {{ $payment->paid_at->format('M j, Y g:i A') }}</div>@endif
                        </div>
                    </div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 50%;">Description</th>
                        <th style="width: 15%;" class="right">Qty</th>
                        <th style="width: 17%;" class="right">Price</th>
                        <th style="width: 18%;" class="right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            {{ $payable->package->name ?? 'Party Package' }}
                            <div class="item-desc">{{ $payable->booking_date ? $payable->booking_date->format('M j') : '' }}@if($payable->room) · {{ $payable->room->name }}@endif</div>
                        </td>
                        <td class="right">1</td>
                        <td class="right">${{ number_format($payable->package->price ?? 0, 2) }}</td>
                        <td class="right">${{ number_format($payable->package->price ?? 0, 2) }}</td>
                    </tr>
                    @if($payable->addOns && $payable->addOns->count() > 0)
                        @foreach($payable->addOns as $addOn)
                        <tr>
                            <td>{{ $addOn->name }}</td>
                            <td class="right">{{ $addOn->pivot->quantity ?? 1 }}</td>
                            <td class="right">${{ number_format($addOn->pivot->price_at_booking ?? $addOn->price, 2) }}</td>
                            <td class="right">${{ number_format(($addOn->pivot->price_at_booking ?? $addOn->price) * ($addOn->pivot->quantity ?? 1), 2) }}</td>
                        </tr>
                        @endforeach
                    @endif
                    @if($payable->attractions && $payable->attractions->count() > 0)
                        @foreach($payable->attractions as $attraction)
                        <tr>
                            <td>{{ $attraction->name }}</td>
                            <td class="right">{{ $attraction->pivot->quantity ?? 1 }}</td>
                            <td class="right">@if(isset($attraction->pivot->price_at_booking) && $attraction->pivot->price_at_booking > 0)${{ number_format($attraction->pivot->price_at_booking, 2) }}@else Incl.@endif</td>
                            <td class="right">@if(isset($attraction->pivot->price_at_booking) && $attraction->pivot->price_at_booking > 0)${{ number_format($attraction->pivot->price_at_booking * ($attraction->pivot->quantity ?? 1), 2) }}@else —@endif</td>
                        </tr>
                        @endforeach
                    @endif
                </tbody>
            </table>

            <div class="totals">
                <div class="totals-spacer"></div>
                <div class="totals-box">
                    <div class="total-line">
                        <span class="total-label">Subtotal</span>
                        <span class="total-value">${{ number_format($payable->total_amount + ($payable->discount_amount ?? 0), 2) }}</span>
                    </div>
                    @if($payable->discount_amount > 0)
                    <div class="total-line">
                        <span class="total-label">Discount @if($payable->discount_code)({{ $payable->discount_code }})@endif</span>
                        <span class="total-value">−${{ number_format($payable->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="total-line total-main">
                        <span class="total-label">Total</span>
                        <span class="total-value">${{ number_format($payable->total_amount, 2) }}</span>
                    </div>
                    <div class="total-line">
                        <span class="total-label">Paid</span>
                        <span class="total-value total-paid">${{ number_format($payment->amount, 2) }}</span>
                    </div>
                    @php $balance = $payable->total_amount - ($payable->amount_paid ?? $payment->amount); @endphp
                    @if($balance > 0)
                    <div class="total-line">
                        <span class="total-label">Balance Due</span>
                        <span class="total-value total-balance">${{ number_format($balance, 2) }}</span>
                    </div>
                    @endif
                </div>
            </div>

            @if($payable->special_requests)
            <div class="notes">
                <div class="notes-title">Special Requests</div>
                <div class="notes-content">{{ $payable->special_requests }}</div>
            </div>
            @endif

            @if($payable->internal_notes)
            <div class="notes internal-notes">
                <div class="notes-title">Internal Notes</div>
                <div class="notes-content">{{ $payable->internal_notes }}</div>
            </div>
            @endif

        @elseif($payable && $payment->payable_type === 'attraction_purchase')
            {{-- ATTRACTION PURCHASE INVOICE --}}

            <div class="event-banner">
                <div class="event-title">{{ $payable->attraction->name ?? 'Attraction Ticket' }}</div>
                <div class="event-details">
                    @if($payable->visit_date)Visit: {{ $payable->visit_date->format('l, F j, Y') }}@endif
                </div>
                <div class="event-meta">
                    Qty: {{ $payable->quantity ?? 1 }} · Status: {{ ucfirst($payable->status ?? 'completed') }}
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Customer</div>
                        <div class="info-content">
                            <div class="info-line"><strong>{{ $customer ? $customer->first_name . ' ' . $customer->last_name : ($payable->guest_name ?? 'Guest') }}</strong></div>
                            @if($customer && $customer->phone)<div class="info-line">{{ $customer->phone }}</div>@endif
                            @if($customer && $customer->email)<div class="info-line info-muted">{{ $customer->email }}</div>@endif
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Transaction</div>
                        <div class="info-content">
                            <div class="info-line">{{ $payment->transaction_id }}</div>
                            <div class="info-line info-muted">{{ ucfirst($payment->method) }} · {{ $payment->created_at->format('M j, Y') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="payment-summary">
                <div class="payment-label">Amount Paid</div>
                <div class="payment-amount">${{ number_format($payment->amount, 2) }}</div>
            </div>

        @else
            {{-- GENERIC PAYMENT --}}

            <div class="row">
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Billed To</div>
                        <div class="info-content">
                            @if($customer)
                                <div class="info-line"><strong>{{ $customer->first_name }} {{ $customer->last_name }}</strong></div>
                                @if($customer->email)<div class="info-line info-muted">{{ $customer->email }}</div>@endif
                                @if($customer->phone)<div class="info-line">{{ $customer->phone }}</div>@endif
                            @else
                                <div class="info-line"><strong>Guest</strong></div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="info-block">
                        <div class="info-title">Payment Info</div>
                        <div class="info-content">
                            <div class="info-line">{{ $payment->transaction_id }}</div>
                            <div class="info-line info-muted">{{ ucfirst($payment->method) }}@if($payment->paid_at) · {{ $payment->paid_at->format('M j, Y') }}@endif</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="payment-summary">
                <div class="payment-label">Total Amount</div>
                <div class="payment-amount">${{ number_format($payment->amount, 2) }}</div>
            </div>
        @endif

        @if($payment->notes)
        <div class="notes" style="margin-top: 15px;">
            <div class="notes-title">Notes</div>
            <div class="notes-content">{{ $payment->notes }}</div>
        </div>
        @endif

        <div class="footer">
            <div class="footer-thanks">Thank you for your business</div>
            <div class="footer-meta">{{ $companyName ?? 'ZapZone' }} · {{ now()->format('M j, Y') }}</div>
            @if($payment->status === 'refunded' && $payment->refunded_at)
                <div class="footer-refund">REFUNDED {{ $payment->refunded_at->format('M j, Y') }}</div>
            @endif
        </div>
    </div>
</body>
</html>
