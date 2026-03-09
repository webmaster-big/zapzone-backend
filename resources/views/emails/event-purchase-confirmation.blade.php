<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Purchase Confirmation</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <!--[if mso]>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
    <![endif]-->

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%; background-color: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            <!--[if mso]>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center">
                            <![endif]-->
                            @if($purchase->event && $purchase->event->location && $purchase->event->location->company && $purchase->event->location->company->logo_path)
                                @php
                                    $logoUrl = $purchase->event->location->company->logo_path;
                                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, 'data:')) {
                                        $logoUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoUrl;
                                    }
                                @endphp
                                <img src="{{ $logoUrl }}" alt="{{ $purchase->event->location->company->name }}" style="max-height: 50px; max-width: 180px; margin-bottom: 12px;" />
                            @elseif($purchase->event && $purchase->event->location && $purchase->event->location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $purchase->event->location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 4px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Event Purchase Confirmation</h1>
                            <p style="margin: 0; padding: 0; font-size: 13px; opacity: 0.9; color: #ffffff;">Thank you for your purchase!</p>
                            <!--[if mso]>
                                    </td>
                                </tr>
                            </table>
                            <![endif]-->
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 32px;">
                            <!-- Customer Information -->
                            <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Customer Information</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                @if($purchase->customer)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Name:</td>
                                                    <td style="color: #111827;">{{ $purchase->customer->first_name }} {{ $purchase->customer->last_name }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                    <td style="color: #111827;">{{ $purchase->customer->email }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    @if($purchase->customer->phone)
                                        <tr>
                                            <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                        <td style="color: #111827;">{{ $purchase->customer->phone }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                @else
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Name:</td>
                                                    <td style="color: #111827;">{{ $purchase->guest_name }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                    <td style="color: #111827;">{{ $purchase->guest_email }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    @if($purchase->guest_phone)
                                        <tr>
                                            <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                        <td style="color: #111827;">{{ $purchase->guest_phone }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                @endif
                            </table>

                            <!-- Purchase Details -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Purchase Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Reference #:</td>
                                                <td style="color: #111827;">{{ $purchase->reference_number }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Event:</td>
                                                <td style="color: #111827;">{{ $purchase->event->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Date:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($purchase->purchase_date)->format('l, F j, Y') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Time:</td>
                                                <td style="color: #111827;">{{ \Carbon\Carbon::parse($purchase->purchase_time)->format('g:i A') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Quantity:</td>
                                                <td style="color: #111827;">{{ $purchase->quantity }} {{ $purchase->quantity > 1 ? 'tickets' : 'ticket' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Price per ticket:</td>
                                                <td style="color: #111827;">${{ number_format($purchase->event->price, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->discount_amount > 0)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Discount:</td>
                                                <td style="color: #111827;">-${{ number_format($purchase->discount_amount, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Payment Method:</td>
                                                <td style="color: #111827;">{{ $purchase->payment_method === 'in-store' ? 'In-Store' : ucfirst(str_replace('_', ' ', $purchase->payment_method)) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->transaction_id)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Transaction ID:</td>
                                                <td style="color: #111827;">{{ $purchase->transaction_id }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Status:</td>
                                                <td style="color: #111827;">
                                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; text-transform: capitalize; background-color: {{ $purchase->status === 'confirmed' ? '#d1fae5' : ($purchase->status === 'pending' ? '#fef3c7' : '#fee2e2') }}; color: {{ $purchase->status === 'confirmed' ? '#065f46' : ($purchase->status === 'pending' ? '#92400e' : '#991b1b') }};">
                                                        {{ ucfirst($purchase->status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Add-Ons -->
                            @if($purchase->addOns && $purchase->addOns->count() > 0)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Add-Ons</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                @foreach($purchase->addOns as $addOn)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; {{ !$loop->last ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="color: #111827;">{{ $addOn->name }} x{{ $addOn->pivot->quantity }}</td>
                                                <td style="color: #111827; text-align: right;">${{ number_format($addOn->pivot->price_at_purchase * $addOn->pivot->quantity, 2) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <!-- Total Amount -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #1e40af; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 16px 20px; text-align: center;">
                                        <h2 style="margin: 0; padding: 0; font-size: 18px; font-weight: 600; color: #ffffff;">Total Amount: ${{ number_format($purchase->total_amount, 2) }}</h2>
                                    </td>
                                </tr>
                            </table>

                            @if($purchase->amount_paid > 0 && $purchase->amount_paid < $purchase->total_amount)
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #fef3c7; border-radius: 6px; border: 1px solid #f59e0b; margin: 0 0 20px 0;">
                                <tr>
                                    <td style="padding: 12px 20px; text-align: center;">
                                        <p style="margin: 0; padding: 0; font-size: 14px; color: #92400e; font-weight: 500;">Amount Paid: ${{ number_format($purchase->amount_paid, 2) }} &mdash; Balance Due: ${{ number_format($purchase->total_amount - $purchase->amount_paid, 2) }}</p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Event Description -->
                            @if($purchase->event->description)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">About {{ $purchase->event->name }}</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $purchase->event->description }}</p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Event Features -->
                            @if($purchase->event->features && count($purchase->event->features) > 0)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">What's Included</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                @foreach($purchase->event->features as $feature)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; color: #111827; {{ !$loop->last ? 'border-bottom: 1px solid #e5e7eb;' : '' }}">
                                        &#8226; {{ $feature }}
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endif

                            <!-- Special Requests -->
                            @if($purchase->special_requests)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Special Requests</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $purchase->special_requests }}</p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Location Information -->
                            @if($purchase->event && $purchase->event->location)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Venue:</td>
                                                <td style="color: #111827;">{{ $purchase->event->location->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->event->location->address)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Address:</td>
                                                    <td style="color: #111827;">{{ $purchase->event->location->address }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->event->location->city)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">City:</td>
                                                    <td style="color: #111827;">{{ $purchase->event->location->city }}, {{ $purchase->event->location->state }} {{ $purchase->event->location->zip_code }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->event->location->phone)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                    <td style="color: #111827;">{{ $purchase->event->location->phone }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->event->location->email)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                    <td style="color: #111827;">{{ $purchase->event->location->email }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            </table>
                            @endif

                            <!-- Footer -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        @php
                                            $companyName = $purchase->event && $purchase->event->location && $purchase->event->location->company ? $purchase->event->location->company->name : null;
                                            $locationPhone = $purchase->event && $purchase->event->location ? $purchase->event->location->phone : null;
                                        @endphp
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">Thank you for choosing {{ $companyName ?? 'our venue' }}!</p>
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">
                                            If you have any questions, please contact us
                                            @if($locationPhone)
                                                at <a href="tel:{{ $locationPhone }}" style="color: #1e40af; text-decoration: none;">{{ $locationPhone }}</a>
                                            @endif.
                                        </p>
                                        <p style="margin: 8px 0 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">This is an automated email. Please do not reply to this message.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!--[if mso]>
            </td>
        </tr>
    </table>
    <![endif]-->
</body>
</html>
