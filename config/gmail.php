<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gmail API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Gmail API for sending emails. You can either use environment
    | variables (recommended) or provide a path to the credentials JSON file.
    |
    */

    'enabled' => env('USE_GMAIL_API', false),

    'sender_email' => env('GMAIL_SENDER_EMAIL', 'bookings@zap-zone.com'),
    
    'sender_name' => env('GMAIL_SENDER_NAME', 'Zap Zone'),

    // Service Account Credentials
    'credentials' => [
        'project_id' => env('GMAIL_PROJECT_ID'),
        'private_key_id' => env('GMAIL_PRIVATE_KEY_ID'),
        'private_key' => env('GMAIL_PRIVATE_KEY'),
        'client_email' => env('GMAIL_CLIENT_EMAIL'),
        'client_id' => env('GMAIL_CLIENT_ID'),
        'client_cert_url' => env('GMAIL_CLIENT_CERT_URL'),
    ],

    // Alternative: Path to credentials JSON file
    'credentials_path' => env('GMAIL_CREDENTIALS_PATH'),

];
