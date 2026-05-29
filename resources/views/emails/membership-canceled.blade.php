@php
    $customer = $membership->customer;
    $plan = $membership->plan;
    $endDate = $mode === 'end_of_term' ? $membership->current_term_end : ($membership->canceled_at ?? now());
@endphp
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Membership Canceled</title></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#374151;background:#f9fafb;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;padding:40px 20px;"><tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;">
<tr><td style="text-align:center;background:#374151;color:#fff;padding:24px 32px;border-radius:8px 8px 0 0;">
    <h1 style="margin:0;font-size:20px;font-weight:600;color:#fff;">Membership Canceled</h1>
</td></tr>
<tr><td style="background:#fff;padding:32px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb;border-top:none;">
    <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">Hi {{ $customer?->first_name }},</p>
    <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">Your <strong>{{ $plan?->name }}</strong> membership has been canceled.</p>
    @if($mode === 'end_of_term' && $endDate)
        <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">You'll keep your benefits until <strong>{{ $endDate->format('M j, Y') }}</strong>, at which point your membership will end and no further charges will be made.</p>
    @else
        <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">Your access ended immediately and no further charges will be made.</p>
    @endif
    <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">We're sorry to see you go. If you change your mind, you can re-subscribe any time from the app.</p>
    <p style="margin:0;font-size:14px;color:#374151;">Thanks,<br/>The ZapZone Team</p>
</td></tr>
<tr><td style="padding:16px;text-align:center;font-size:11px;color:#9ca3af;">Automated cancellation confirmation.</td></tr>
</table></td></tr></table>
</body></html>
