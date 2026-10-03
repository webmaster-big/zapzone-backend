<?php

return [
    'enabled' => filter_var(env('STAFF_PINS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'pepper' => env('STAFF_PIN_PEPPER'),
    'pepper_previous' => env('STAFF_PIN_PEPPER_PREVIOUS'),

    'length' => (int) env('STAFF_PIN_LENGTH', 6),

    'roles' => ['company_admin', 'location_manager', 'attendant'],

    'approver_roles' => ['company_admin', 'location_manager'],

    'manager_roles' => ['company_admin', 'location_manager'],

    'elevated_roles' => ['company_admin', 'location_manager'],

    'idle' => [
        'default_seconds' => (int) env('STAFF_PIN_IDLE_SECONDS', 60),
        'min_seconds' => (int) env('STAFF_PIN_IDLE_MIN_SECONDS', 15),
        'max_seconds' => (int) env('STAFF_PIN_IDLE_MAX_SECONDS', 3600),
        'elevated_seconds' => (int) env('STAFF_PIN_ELEVATED_IDLE_SECONDS', 60),
        'elevated_max_seconds' => (int) env('STAFF_PIN_ELEVATED_IDLE_MAX_SECONDS', 300),
    ],

    'session_hours' => (int) env('STAFF_PIN_SESSION_HOURS', 12),

    'elevated_session_minutes' => (int) env('STAFF_PIN_ELEVATED_SESSION_MINUTES', 60),

    'terminal_token_days' => (int) env('STAFF_PIN_TERMINAL_TOKEN_DAYS', 90),

    'lockout' => [
        'attempts' => (int) env('STAFF_PIN_LOCKOUT_ATTEMPTS', 5),
        'minutes' => (int) env('STAFF_PIN_LOCKOUT_MINUTES', 15),
    ],

    'company_breaker' => [
        'failures_per_hour' => (int) env('STAFF_PIN_BREAKER_FAILURES', 200),
        'cooldown_minutes' => (int) env('STAFF_PIN_BREAKER_COOLDOWN', 30),
    ],

    'elevation' => env('STAFF_PIN_ELEVATION', 'log'),

    'elevation_capabilities' => env('STAFF_PIN_ELEVATION_CAPABILITIES', ''),

    'elevation_ttl_seconds' => (int) env('STAFF_PIN_ELEVATION_TTL', 90),

    'elevation_read_ttl_seconds' => (int) env('STAFF_PIN_ELEVATION_READ_TTL', 900),

    'elevation_read_uses' => (int) env('STAFF_PIN_ELEVATION_READ_USES', 25),

    'capabilities' => [
        'waiver.assign' => ['company_admin', 'location_manager'],
        'waiver.print' => ['company_admin', 'location_manager'],
        'membership.extend' => ['company_admin', 'location_manager'],
        'membership.unfreeze' => ['company_admin', 'location_manager'],
        'membership.status' => ['company_admin', 'location_manager'],
        'membership.cancel' => ['company_admin', 'location_manager'],
        'membership.refund' => ['company_admin', 'location_manager'],
        'membership.void' => ['company_admin', 'location_manager'],
        'membership.destroy' => ['company_admin'],
        'payment.view' => ['company_admin', 'location_manager'],
        'payment.refund' => ['company_admin', 'location_manager'],
        'payment.void' => ['company_admin', 'location_manager'],
        'booking.void' => ['company_admin', 'location_manager'],
        'purchase.void' => ['company_admin', 'location_manager'],
        'order.void' => ['company_admin', 'location_manager'],
        'price.override' => ['company_admin', 'location_manager'],
        'overlap.override' => ['company_admin', 'location_manager'],
    ],

    'read_capabilities' => ['payment.view'],
];
