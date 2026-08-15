<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Expo Push Notifications
    |--------------------------------------------------------------------------
    |
    | Delivery to the staff mobile app. Set EXPO_PUSH_ENABLED=false to stop
    | every push at the door without touching code. An access token is only
    | needed once "push security" is turned on for the Expo project.
    |
    */

    'enabled' => (bool) env('EXPO_PUSH_ENABLED', true),

    'base_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push'),

    'access_token' => env('EXPO_ACCESS_TOKEN'),

    'timeout' => (int) env('EXPO_PUSH_TIMEOUT', 10),
];
