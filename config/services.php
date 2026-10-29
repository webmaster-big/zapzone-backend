<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
        'marketing_key' => env('RESEND_MARKETING_KEY'),
        'enabled' => env('RESEND_ENABLED', false),
        'from_address' => env('RESEND_FROM_ADDRESS'),
        'marketing_from_address' => env('RESEND_MARKETING_FROM_ADDRESS'),
        'bulk_per_second' => env('RESEND_BULK_PER_SECOND', 4),
        'campaign_per_hour' => env('RESEND_CAMPAIGN_PER_HOUR'),
        'campaign_per_day' => env('RESEND_CAMPAIGN_PER_DAY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorize.Net Configuration
    |--------------------------------------------------------------------------
    |
    | Note: Actual credentials are stored per-location in the database
    | These are just global settings if needed
    |
    */
    'authorize_net' => [
        'sandbox_endpoint' => 'https://apitest.authorize.net/xml/v1/request.api',
        'production_endpoint' => 'https://api.authorize.net/xml/v1/request.api',
    ],

];
