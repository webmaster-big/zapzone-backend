@php
    $customer = $membership->customer;
    $plan = $membership->plan;
    $graceEnds = $membership->grace_period_ends_at;
@endphp
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Payment Failed</title></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#374151;background:#f9fafb;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;padding:40px 20px;"><tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;">
<tr><td style="text-align:center;background:#dc2626;color:#fff;padding:24px 32px;border-radius:8px 8px 0 0;">
    <h1 style="margin:0;font-size:20px;font-weight:600;color:#fff;">Membership Payment Failed</h1>
    <p style="margin:6px 0 0 0;font-size:13px;opacity:0.9;color:#fff;">Action required to keep your benefits</p>
</td></tr>
<tr><td style="background:#fff;padding:32px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb;border-top:none;">
    <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">Hi {{ $customer?->first_name }},</p>
    <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">We weren't able to charge your saved payment method for your <strong>{{ $plan?->name }}</strong> membership.</p>
    @if($failureReason)
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px 16px;margin:0 0 16px 0;font-size:13px;color:#991b1b;">
        <strong>Reason:</strong> {{ $failureReason }}
    </div>
    @endif
    @if($graceEnds)
    <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">You're in a grace period until <strong>{{ $graceEnds->format('M j, Y') }}</strong>. Update your payment method before then to avoid an interruption in service.</p>
    @endif
    <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">Please sign in and update your payment method, or contact your home location for help.</p>
    <p style="margin:0;font-size:14px;color:#374151;">Thanks,<br/>The ZapZone Team</p>
</td></tr>
<tr><td style="padding:16px;text-align:center;font-size:11px;color:#9ca3af;">Automated payment notice.</td></tr>
</table></td></tr></table>
</body></html>
