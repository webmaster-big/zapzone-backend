<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Calendar Integration
    |--------------------------------------------------------------------------
    |
    | These are the APP's OAuth2 credentials (created once in Google Cloud Console).
    | They are NOT the user's Google account — users connect their own account
    | by simply clicking "Connect Google Calendar" and logging in with Google.
    |
    | Setup (one-time):
    | 1. Go to https://console.cloud.google.com/apis/credentials
    | 2. Create an OAuth 2.0 Client ID (Web application)
    | 3. Add redirect URI: {APP_URL}/api/google-calendar/callback
    | 4. Enable the Google Calendar API
    | 5. Paste your Client ID and Client Secret below
    |
    */

    'app_name' => 'Booking Calendar',

    // Paste your Google Cloud Console OAuth credentials here (one-time setup)
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID', ''),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET', ''),

    // Where the user is redirected after Google login (your frontend settings page)
    'frontend_redirect_url' => env('GOOGLE_CALENDAR_FRONTEND_REDIRECT_URL', 'https://booking.zap-zone.com/manager/settings'),

    // Auto-sync booking changes to Google Calendar
    'auto_sync' => env('GOOGLE_CALENDAR_AUTO_SYNC', true),

];
