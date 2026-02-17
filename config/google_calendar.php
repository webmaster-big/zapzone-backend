<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Calendar Integration
    |--------------------------------------------------------------------------
    |
    | OAuth2 credentials for Google Calendar integration.
    | Credentials are stored in the database (google_calendar_settings table)
    | so you do NOT need to set env vars on Forge.
    |
    | Env vars below are optional fallbacks if DB credentials are not set.
    | Create credentials at: https://console.cloud.google.com/apis/credentials
    | Enable the Google Calendar API in your project.
    |
    */

    'app_name' => env('GOOGLE_CALENDAR_APP_NAME', 'ZapZone Booking Calendar'),

    // Fallback: only used if not configured in DB via admin UI
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),

    // Auto-sync new bookings to Google Calendar when created
    'auto_sync' => env('GOOGLE_CALENDAR_AUTO_SYNC', true),

];
