<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Receipt</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <!--[if mso]>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
    <![endif]-->
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
                            @if($purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company && $purchase->attraction->location->company->logo_path)
                                @php
                                    $logoUrl = $purchase->attraction->location->company->logo_path;
                                    if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, 'data:')) {
                                        $logoUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoUrl;
                                    }
                                @endphp
                                <img src="{{ $logoUrl }}" alt="{{ $purchase->attraction->location->company->name }}" style="max-height: 50px; max-width: 180px; margin-bottom: 12px;" />
                            @elseif($purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company)
                                <p style="margin: 0 0 8px 0; padding: 0; font-size: 18px; font-weight: 700; color: #ffffff;">{{ $purchase->attraction->location->company->name }}</p>
                            @endif
                            <h1 style="margin: 0 0 4px 0; padding: 0; font-size: 20px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">Purchase Receipt</h1>
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
                                            <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                        <td style="color: #111827;">{{ $purchase->customer->phone }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                    @if($purchase->customer->address)
                                        <tr>
                                            <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td style="font-weight: 500; color: #6b7280; width: 140px;">Address:</td>
                                                        <td style="color: #111827;">{{ $purchase->customer->address }}</td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                    @if($purchase->customer->city || $purchase->customer->state || $purchase->customer->zip)
                                        <tr>
                                            <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                    <tr>
                                                        <td style="font-weight: 500; color: #6b7280; width: 140px;">City/State/ZIP:</td>
                                                        <td style="color: #111827;">
                                                            {{ $purchase->customer->city }}{{ $purchase->customer->city && ($purchase->customer->state || $purchase->customer->zip) ? ', ' : '' }}{{ $purchase->customer->state }} {{ $purchase->customer->zip }}
                                                        </td>
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
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Order Number:</td>
                                                <td style="color: #111827;">#{{ str_pad($purchase->id, 6, '0', STR_PAD_LEFT) }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Purchase Date:</td>
                                                <td style="color: #111827;">{{ $purchase->purchase_date->format('F d, Y') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->purchase_date->format('H:i:s') != '00:00:00')
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Purchase Time:</td>
                                                <td style="color: #111827;">{{ $purchase->purchase_date->format('g:i A') }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Attraction:</td>
                                                <td style="color: #111827;">{{ $purchase->attraction->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Category:</td>
                                                <td style="color: #111827;">{{ ucfirst($purchase->attraction->category) }}</td>
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
                                                <td style="color: #111827;">${{ number_format($purchase->attraction->price, 2) }}</td>
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
                                @if($purchase->tax_amount > 0)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Tax:</td>
                                                <td style="color: #111827;">${{ number_format($purchase->tax_amount, 2) }}</td>
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
                                                <td style="color: #111827;">{{ ucfirst(str_replace('_', ' ', $purchase->payment_method)) }}</td>
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
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Status:</td>
                                                <td style="color: #111827;">
                                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; text-transform: capitalize; background-color: {{ $purchase->status === 'completed' ? '#d1fae5' : ($purchase->status === 'pending' ? '#fef3c7' : '#fee2e2') }}; color: {{ $purchase->status === 'completed' ? '#065f46' : ($purchase->status === 'pending' ? '#92400e' : '#991b1b') }};">
                                                        {{ $purchase->status === 'pending' ? 'Confirmed' : ucfirst($purchase->status) }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->notes)
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Notes:</td>
                                                <td style="color: #111827;">{{ $purchase->notes }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>

                            <!-- Total Amount -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #1e40af; border-radius: 6px; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 16px 20px; text-align: center;">
                                        <h2 style="margin: 0; padding: 0; font-size: 18px; font-weight: 600; color: #ffffff;">Total Amount: ${{ number_format($purchase->total_amount, 2) }}</h2>
                                    </td>
                                </tr>
                            </table>

                            <!-- Location Information -->
                            @if($purchase->attraction && $purchase->attraction->location)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location Details</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Venue:</td>
                                                <td style="color: #111827;">{{ $purchase->attraction->location->name }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @if($purchase->attraction->location->address)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Address:</td>
                                                    <td style="color: #111827;">{{ $purchase->attraction->location->address }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->attraction->location->city)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">City:</td>
                                                    <td style="color: #111827;">{{ $purchase->attraction->location->city }}, {{ $purchase->attraction->location->state }} {{ $purchase->attraction->location->zip_code }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->attraction->location->country)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Country:</td>
                                                    <td style="color: #111827;">{{ $purchase->attraction->location->country }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->attraction->location->phone)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Phone:</td>
                                                    <td style="color: #111827;">{{ $purchase->attraction->location->phone }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->attraction->location->email)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6; border-bottom: 1px solid #e5e7eb;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Email:</td>
                                                    <td style="color: #111827;">{{ $purchase->attraction->location->email }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                                @if($purchase->attraction->location->website)
                                    <tr>
                                        <td style="padding: 8px 16px; font-size: 14px; line-height: 1.6;">
                                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="font-weight: 500; color: #6b7280; width: 140px;">Website:</td>
                                                    <td style="color: #111827;"><a href="{{ $purchase->attraction->location->website }}" style="color: #1e40af; text-decoration: none;">{{ $purchase->attraction->location->website }}</a></td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            </table>
                            @endif

                            <!-- QR Code Section -->
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Your Entry QR Code</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 20px; text-align: center;">
                                        <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Please present your QR code when visiting the attraction.</p>

                                        <div style="background-color: #ffffff; padding: 20px; border-radius: 8px; display: inline-block; margin: 12px 0;">
                                            <svg width="200" height="200" viewBox="0 0 200 200" style="display: block; margin: 0 auto;">
                                                <rect width="200" height="200" fill="#f3f4f6"/>
                                                <text x="100" y="100" text-anchor="middle" dominant-baseline="middle" fill="#6b7280" font-family="Arial, sans-serif" font-size="14">
                                                    <tspan x="100" dy="-10">📎 QR Code</tspan>
                                                    <tspan x="100" dy="20">Available in</tspan>
                                                    <tspan x="100" dy="20">Attachment</tspan>
                                                </text>
                                            </svg>
                                        </div>

                                        <p style="margin: 16px 0 0 0; padding: 0; font-size: 12px; line-height: 1.6; color: #6b7280;">Order #{{ str_pad($purchase->id, 6, '0', STR_PAD_LEFT) }}</p>
                                        <p style="margin: 4px 0 0 0; padding: 0; font-size: 13px; line-height: 1.6; color: #1e40af; font-weight: 500;">✓ Your QR code is attached to this email</p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Attraction Details -->
                            @if($purchase->attraction->description)
                            <h3 style="margin: 24px 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">About {{ $purchase->attraction->name }}</h3>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 16px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <p style="margin: 0 0 12px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">{{ $purchase->attraction->description }}</p>

                                        @if($purchase->attraction->duration || $purchase->attraction->min_age || $purchase->attraction->max_capacity || $purchase->attraction->difficulty_level)
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb;">
                                            @if($purchase->attraction->duration)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Duration:</td>
                                                                <td style="color: #111827;">{{ $purchase->attraction->duration }} {{ $purchase->attraction->duration_unit }}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if($purchase->attraction->min_age)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Minimum Age:</td>
                                                                <td style="color: #111827;">{{ $purchase->attraction->min_age }} years</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if($purchase->attraction->max_age)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Maximum Age:</td>
                                                                <td style="color: #111827;">{{ $purchase->attraction->max_age }} years</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if($purchase->attraction->max_capacity)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Max Capacity:</td>
                                                                <td style="color: #111827;">{{ $purchase->attraction->max_capacity }} people</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if($purchase->attraction->difficulty_level)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Difficulty Level:</td>
                                                                <td style="color: #111827;">{{ ucfirst($purchase->attraction->difficulty_level) }}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                            @if($purchase->attraction->is_indoor !== null)
                                                <tr>
                                                    <td style="padding: 8px 0; font-size: 14px; line-height: 1.6;">
                                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-weight: 500; color: #6b7280; width: 140px;">Type:</td>
                                                                <td style="color: #111827;">{{ $purchase->attraction->is_indoor ? 'Indoor' : 'Outdoor' }}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endif
                                        </table>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Footer -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                                <tr>
                                    <td style="text-align: center;">
                                        @php
                                            $companyName = $purchase->attraction && $purchase->attraction->location && $purchase->attraction->location->company ? $purchase->attraction->location->company->name : null;
                                            $locationPhone = $purchase->attraction && $purchase->attraction->location ? $purchase->attraction->location->phone : null;
                                        @endphp
                                        <p style="margin: 4px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #9ca3af;">Thank you for choosing {{ $companyName ?? 'our attractions' }}!</p>
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
