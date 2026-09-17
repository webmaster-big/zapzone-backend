<?php

return [
    'add_on_quantity' => env('BOOKING_RULES_ADD_ON_QUANTITY', 'log'),
    'exclusive_slots' => env('BOOKING_RULES_EXCLUSIVE_SLOTS', 'enforce'),
    'room_cleanup_minutes' => env('BOOKING_RULES_ROOM_CLEANUP_MINUTES', 15),
    'forced_add_ons' => env('BOOKING_RULES_FORCED_ADD_ONS', 'log'),
    'capacity' => env('BOOKING_RULES_CAPACITY', 'log'),
    'package_required' => env('BOOKING_RULES_PACKAGE_REQUIRED', 'log'),
    'csv_participants' => env('BOOKING_RULES_CSV_PARTICIPANTS', 'log'),
    /*
     * 'enforce' refuses a booking that overlaps another, unless a manager approves it with their
     * override PIN. A venue where no manager holds a PIN yet is warned in the log and allowed
     * through, so turning this on cannot strand a front desk.
     */
    'slot_conflict' => env('BOOKING_RULES_SLOT_CONFLICT', 'enforce'),

    /*
     * Whether a booking change must carry a typed reason.
     *   'all'           - DEFAULT. Required for every employee-made booking change EXCEPT the two
     *                     marked CHANGE_INTERNAL: editing internal notes and checking a party in.
     *                     Staff do both dozens of times a shift and neither is a decision anyone
     *                     audits, so the prompt was pure friction. Everything else - including
     *                     changing payment status, which moves money and emails the guest - still
     *                     needs one. Customers and unauthenticated callers are never prompted
     *                     (see CapturesChangeReason::actorIsStaff).
     *   'guest_visible' - narrower: only changes a guest would notice (date, time, room, package,
     *                     participants, price, status, cancellation, location move).
     *   'off'           - never required; reasons are still stored when supplied.
     */
    'change_reason' => env('BOOKING_RULES_CHANGE_REASON', 'all'),
];
