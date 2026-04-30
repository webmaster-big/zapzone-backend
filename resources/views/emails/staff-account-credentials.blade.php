<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Zap Zone Staff Account</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; background:#f5f6f8; margin:0; padding:24px; color:#222;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
        <tr>
            <td style="background:#0b3a8a;color:#fff;padding:20px 24px;">
                <h2 style="margin:0;font-size:20px;">Welcome to Zap Zone</h2>
                @if($company)
                    <div style="font-size:13px;opacity:.9;margin-top:4px;">{{ $company->company_name ?? $company->name ?? '' }}</div>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p>Hi {{ $user->first_name }},</p>

                <p>
                    A staff account has been created for you
                    @if($createdByName) by <strong>{{ $createdByName }}</strong> @endif
                    on the Zap Zone platform.
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:16px 0;">
                    <tr>
                        <td style="padding:8px 12px;background:#f1f3f7;border:1px solid #e3e6ec;width:160px;"><strong>Role</strong></td>
                        <td style="padding:8px 12px;border:1px solid #e3e6ec;">{{ ucwords(str_replace('_',' ',$user->role)) }}</td>
                    </tr>
                    @if($location)
                        <tr>
                            <td style="padding:8px 12px;background:#f1f3f7;border:1px solid #e3e6ec;"><strong>Location</strong></td>
                            <td style="padding:8px 12px;border:1px solid #e3e6ec;">{{ $location->name }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:8px 12px;background:#f1f3f7;border:1px solid #e3e6ec;"><strong>Email (login)</strong></td>
                        <td style="padding:8px 12px;border:1px solid #e3e6ec;">{{ $user->email }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 12px;background:#f1f3f7;border:1px solid #e3e6ec;"><strong>Temporary Password</strong></td>
                        <td style="padding:8px 12px;border:1px solid #e3e6ec;font-family:Consolas,monospace;font-size:15px;letter-spacing:1px;">{{ $plainPassword }}</td>
                    </tr>
                </table>

                <p style="margin:20px 0;">
                    <a href="{{ $loginUrl }}"
                       style="background:#0b3a8a;color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;">
                        Log in to your account
                    </a>
                </p>

                <p style="font-size:13px;color:#555;">
                    For your security, please change your password immediately after your first login.
                    If you did not expect this email, please contact your administrator.
                </p>

                <p style="margin-top:24px;font-size:12px;color:#999;">
                    &mdash; Zap Zone Team
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
