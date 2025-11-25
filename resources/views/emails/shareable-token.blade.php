<!DOCTYPE html>
<html>
<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background: #f4f4f4;
            padding: 30px;
            border-radius: 8px;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: blue;
            color: white !important;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Welcome to Zap Zone!</h2>

        <p>You've been invited to register as a <strong>{{ ucwords(str_replace('_', ' ', $role)) }}</strong>.</p>

        <div class="info">
            <p><strong>Email:</strong> {{ $email }}</p>
            <p><strong>Role:</strong> {{ ucwords(str_replace('_', ' ', $role)) }}</p>
        </div>

        <p>Click the button below to complete your registration:</p>

        <a href="{{ $link }}" class="button">Register Now</a>

        <p style="font-size: 12px; color: #666;">
            Or copy this link: <br>
            <a href="{{ $link }}">{{ $link }}</a>
        </p>

        <p style="margin-top: 30px; font-size: 12px; color: #999;">
            This is a one-time use invitation link. Once you complete registration, this link will expire.
        </p>
    </div>
</body>
</html>
