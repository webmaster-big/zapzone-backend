<?php

return [
    'font_path' => env('PHOTO_DATE_FONT_PATH'),

    'device_token_days' => (int) env('PHOTO_DEVICE_TOKEN_DAYS', 30),

    'support_contact' => env('PHOTO_SUPPORT_CONTACT', env('MAIL_FROM_ADDRESS', '')),

    'max_upload_kb' => (int) env('PHOTO_MAX_UPLOAD_KB', 12288),
];
