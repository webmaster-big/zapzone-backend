@php
    $customer = $membership->customer;
    $plan = $membership->plan;
    $company = $membership->homeLocation?->company;
@endphp
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Payment Receipt</title></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#374151;background:#f9fafb;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;padding:40px 20px;"><tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;width:100%;">
<tr><td style="text-align:center;background:#059669;color:#fff;padding:24px 32px;border-radius:8px 8px 0 0;">
    @if($company)<p style="margin:0 0 8px 0;font-size:16px;font-weight:700;color:#fff;">{{ $company->company_name }}</p>@endif
    <h1 style="margin:0;font-size:20px;font-weight:600;color:#fff;">Payment Receipt</h1>
    <p style="margin:6px 0 0 0;font-size:13px;opacity:0.9;color:#fff;">Transaction #{{ $payment->transaction_id ?? $payment->id }}</p>
</td></tr>
<tr><td style="background:#fff;padding:32px;border-radius:0 0 8px 8px;border:1px solid #e5e7eb;border-top:none;">
    <p style="margin:0 0 16px 0;font-size:14px;color:#4b5563;">Hi {{ $customer?->first_name }},</p>
    <p style="margin:0 0 20px 0;font-size:14px;color:#4b5563;">We've successfully charged your saved payment method for your <strong>{{ $plan?->name }}</strong> membership.</p>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;margin:0 0 20px 0;">
        <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Plan:</span><span style="color:#111827;font-weight:500;">{{ $plan?->name }}</span></td></tr>
        <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Amount:</span><span style="color:#111827;font-weight:600;">${{ number_format((float) $payment->amount, 2) }}</span></td></tr>
        <tr><td style="padding:10px 16px;border-bottom:1px solid #e5e7eb;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Charged:</span><span style="color:#111827;">{{ optional($payment->charged_at)->format('M j, Y g:i A') }}</span></td></tr>
        @if($membership->next_billing_at)
        <tr><td style="padding:10px 16px;font-size:14px;"><span style="color:#6b7280;display:inline-block;width:140px;">Next Billing:</span><span style="color:#111827;">{{ $membership->next_billing_at->format('M j, Y') }}</span></td></tr>
        @endif
    </table>
    <p style="margin:0 0 8px 0;font-size:13px;color:#6b7280;">No action needed. Your membership remains active.</p>
    <p style="margin:0;font-size:14px;color:#374151;">Thanks,<br/>The {{ $company?->company_name ?? 'ZapZone' }} Team</p>
</td></tr>
<tr><td style="padding:16px;text-align:center;font-size:11px;color:#9ca3af;">Automated payment confirmation.</td></tr>
</table></td></tr></table>
</body></html>
