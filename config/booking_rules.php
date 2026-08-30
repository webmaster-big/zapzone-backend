<?php

return [
    'add_on_quantity' => env('BOOKING_RULES_ADD_ON_QUANTITY', 'log'),
    'exclusive_slots' => env('BOOKING_RULES_EXCLUSIVE_SLOTS', 'enforce'),
    'room_driven_slots' => env('BOOKING_RULES_ROOM_DRIVEN_SLOTS', 'on'),
    'room_cleanup_minutes' => env('BOOKING_RULES_ROOM_CLEANUP_MINUTES', 15),
    'forced_add_ons' => env('BOOKING_RULES_FORCED_ADD_ONS', 'log'),
    'capacity' => env('BOOKING_RULES_CAPACITY', 'log'),
    'package_required' => env('BOOKING_RULES_PACKAGE_REQUIRED', 'log'),
    'csv_participants' => env('BOOKING_RULES_CSV_PARTICIPANTS', 'log'),
];
