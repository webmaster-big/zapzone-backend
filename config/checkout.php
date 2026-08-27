<?php

return [
    'enforce_server_total' => (bool) env('CHECKOUT_ENFORCE_SERVER_TOTAL', false),
    'enforce_for_staff' => (bool) env('CHECKOUT_ENFORCE_FOR_STAFF', false),
    'total_tolerance' => (float) env('CHECKOUT_TOTAL_TOLERANCE', 0.05),
    'log_channel' => env('CHECKOUT_LOG_CHANNEL'),
    'membership_hardening' => env('CHECKOUT_MEMBERSHIP_HARDENING', 'log'),
];
