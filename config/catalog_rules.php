<?php

return [
    'default' => env('CATALOG_RULES_MODE', 'log'),
    'groups' => [
        'package_schedules' => env('CATALOG_RULES_PACKAGE_SCHEDULES'),
        'package_booking_window' => env('CATALOG_RULES_PACKAGE_BOOKING_WINDOW'),
        'package_pricing' => env('CATALOG_RULES_PACKAGE_PRICING'),
        'events' => env('CATALOG_RULES_EVENTS'),
        'attractions' => env('CATALOG_RULES_ATTRACTIONS'),
        'gift_cards' => env('CATALOG_RULES_GIFT_CARDS'),
        'add_ons' => env('CATALOG_RULES_ADD_ONS'),
    ],
];
