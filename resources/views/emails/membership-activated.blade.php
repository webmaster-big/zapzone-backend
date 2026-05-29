@php
    $customer = $membership->customer;
    $plan = $membership->plan;
    $company = $membership->homeLocation?->company;
    $appUrl = config('app.url', 'https://zapzone-backend-yt1lm2w5.on-forge.com');
    $logoUrl = $company?->logo_path;
    if ($logoUrl && !str_starts_with($logoUrl, 'http')) {
        $logoUrl = rtrim($appUrl, '/') . '/storage/' . $logoUrl;
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Activated</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1.5;color:#374151;background-color:#f9fafb;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;padding:40px 20px;">
    <tr>
        <td align="center">
            <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;">
                <tr>
                    <td style="text-align:center;background-color:#1e40af;color:#fff;padding:24px 32px;border-radius:8px 8px 0 0;">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $company?->company_name }}" style="max-height:50px;max-width:180px;margin-bottom:12px;" />
                        @elseif($company)
                            <p style="margin:0 0 8px 0;font-size:18px;font-weight:700;color:#fff;">{{ $company->company_name }}</p>
                        @endif
                        <h1 style="margin:0 0 8px 0;font-size:22px;font-weight:600;color:#fff;">Welcome to {{ $plan?->name }}!</h1>
                        <p style="margin:0;font-size:14px;opacity:0.9;color:#fff;">Your membership is now active</p>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#fff;padding:32px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb;border-top:none;">
                        <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">Hi {{ $customer?->first_name }},</p>
                        <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">Thanks for joining! Your <strong>{{ $plan?->name }}</strong> membership has been activated and you can start enjoying member benefits right away.</p>

                        <h3 style="margin:24px 0 12px 0;font-size:16px;font-weight:600;color:#111827;">Membership Details</h3>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;margin:0 0 20px 0;">
                            <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Plan:</span><span style="color:#111827;font-weight:500;">{{ $plan?->name }}</span></td></tr>
                            <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Started:</span><span style="color:#111827;">{{ optional($membership->started_at)->format('M j, Y') }}</span></td></tr>
                            <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Current Term:</span><span style="color:#111827;">{{ optional($membership->current_term_start)->format('M j') }} – {{ optional($membership->current_term_end)->format('M j, Y') }}</span></td></tr>
                            @if($membership->next_billing_at)
                            <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Next Billing:</span><span style="color:#111827;">{{ $membership->next_billing_at->format('M j, Y') }}</span></td></tr>
                            @endif
                            @if($membership->homeLocation)
                            <tr><td style="padding:10px 16px;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Home Location:</span><span style="color:#111827;">{{ $membership->homeLocation->name }}</span></td></tr>
                            @endif
                        </table>

                        <h3 style="margin:24px 0 12px 0;font-size:16px;font-weight:600;color:#111827;">Your Check-In QR Code</h3>
                        <p style="margin:0 0 12px 0;font-size:13px;color:#6b7280;">Show this QR code at any approved location to check in. You can also access it any time in the app under "My Membership".</p>
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;text-align:center;padding:16px;margin:0 0 12px 0;">
                            <tr><td style="text-align:center;">
                                @if($qrCodeBase64)
                                    <img src="data:image/png;base64,{{ $qrCodeBase64 }}" alt="Membership QR" style="width:180px;height:180px;" />
                                @endif
                                <p style="margin:8px 0 0 0;font-family:monospace;font-size:11px;color:#6b7280;word-break:break-all;">{{ $membership->qr_token }}</p>
                            </td></tr>
                        </table>

                        <p style="margin:24px 0 8px 0;font-size:13px;color:#6b7280;">Questions? Reply to this email or contact us at your home location. We're here to help.</p>
                        <p style="margin:0;font-size:14px;color:#374151;">See you soon!<br/>The {{ $company?->company_name ?? 'ZapZone' }} Team</p>
                    </td>
                </tr>
                <tr><td style="padding:16px;text-align:center;font-size:11px;color:#9ca3af;">This is an automated email about your membership.</td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
