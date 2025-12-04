<!DOCTYPE html>
<html>
<head>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.5;
            color: #374151;
            background-color: #f9fafb;
            padding: 40px 20px;
        }
        .email-wrapper {
            max-width: 480px;
            margin: 0 auto;
        }
        .container {
            background: #ffffff;
            padding: 32px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        h2 {
            color: #111827;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            letter-spacing: -0.01em;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 16px;
            color: #4b5563;
        }
        .info {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            margin: 20px 0;
            border: 1px solid #e5e7eb;
        }
        .info p {
            margin: 0;
            font-size: 14px;
            line-height: 1.8;
            color: #374151;
        }
        .info p + p {
            margin-top: 4px;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            background: #1e40af;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            margin: 20px 0 16px 0;
            transition: background-color 0.15s;
        }
        .button:hover {
            background: #1e3a8a;
        }
        .link-alternative {
            font-size: 14px;
            color: #6b7280;
            margin-top: 12px;
        }
        .link-alternative a {
            color: #1e40af;
            text-decoration: none;
            word-break: break-all;
            font-size: 14px;
        }
        .link-alternative a:hover {
            text-decoration: underline;
        }
        .footer-note {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="container">
            <h2>Welcome to Zap Zone!</h2>

            <p>You've been invited to register as a <strong>{{ ucwords(str_replace('_', ' ', $role)) }}</strong>.</p>

            <div class="info">
                <p><strong>Email:</strong> {{ $email }}</p>
                <p><strong>Role:</strong> {{ ucwords(str_replace('_', ' ', $role)) }}</p>
            </div>

            <p>Click the button below to complete your registration:</p>

            <a href="{{ $link }}" class="button">Register Now</a>

            <p class="link-alternative">
                Or copy this link:<br>
                <a href="{{ $link }}">{{ $link }}</a>
            </p>

            <p class="footer-note">
                This is a one-time use invitation link. Once you complete registration, this link will expire.
            </p>
        </div>
    </div>
</body>
</html>
