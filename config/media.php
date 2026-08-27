<?php

return [
    'exclude_heavy_columns' => (bool) env('MEDIA_EXCLUDE_HEAVY_COLUMNS', true),

    'max_path_length' => (int) env('MEDIA_MAX_IMAGE_PATH_LENGTH', 2048),

    'downscale' => [
        'enabled' => (bool) env('MEDIA_DOWNSCALE_ENABLED', false),
        'max_width' => (int) env('MEDIA_DOWNSCALE_MAX_WIDTH', 1920),
        'max_bytes' => (int) env('MEDIA_DOWNSCALE_MAX_BYTES', 1048576),
        'jpeg_quality' => (int) env('MEDIA_DOWNSCALE_JPEG_QUALITY', 85),
        'max_source_pixels' => (int) env('MEDIA_DOWNSCALE_MAX_SOURCE_PIXELS', 40000000),
    ],
];
