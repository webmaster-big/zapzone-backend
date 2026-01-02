<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Email' }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333333;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .email-content {
            font-size: 16px;
            color: #333333;
        }
        .email-content h1, .email-content h2, .email-content h3 {
            color: #1a1a1a;
            margin-top: 0;
        }
        .email-content p {
            margin: 0 0 16px 0;
        }
        .email-content a {
            color: #0066cc;
            text-decoration: none;
        }
        .email-content a:hover {
            text-decoration: underline;
        }
        .email-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eeeeee;
            font-size: 12px;
            color: #888888;
            text-align: center;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #0066cc;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 10px 0;
        }
        .button:hover {
            background-color: #0055aa;
            text-decoration: none;
        }
        /* Responsive styles */
        @media only screen and (max-width: 600px) {
            .email-wrapper {
                padding: 10px;
            }
            .email-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            <div class="email-content">
                {!! $emailBody !!}
            </div>
            <div class="email-footer">
                <p>This email was sent from {{ $variables['company_name'] ?? 'our system' }}.</p>
                <p>&copy; {{ $variables['current_year'] ?? date('Y') }} All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
