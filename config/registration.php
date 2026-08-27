<?php

return [
    'require_token' => (bool) env('REGISTRATION_REQUIRE_TOKEN', false),

    'require_staff_to_invite' => (bool) env('REGISTRATION_REQUIRE_STAFF_TO_INVITE', false),

    'token_ttl_days' => (int) env('REGISTRATION_TOKEN_TTL_DAYS', 0),

    'self_registered_status' => env('REGISTRATION_SELF_REGISTERED_STATUS', 'active'),

    'block_inactive_login' => (bool) env('AUTH_BLOCK_INACTIVE_LOGIN', false),
];
