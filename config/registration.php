<?php

return [
    'token_ttl_days' => (int) env('REGISTRATION_TOKEN_TTL_DAYS', 0),

    'self_registered_status' => env('REGISTRATION_SELF_REGISTERED_STATUS', 'active'),

];
