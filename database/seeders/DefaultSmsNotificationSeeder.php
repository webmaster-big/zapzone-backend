<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\SmsNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DefaultSmsNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            $this->seedForCompany($company);
        }

        $this->command?->info("Default SMS notifications seeded for {$companies->count()} companies.");
    }

    public static function seedForCompany(Company $company): void
    {
        foreach (self::getDefaultDefinitions() as $definition) {
            $exists = SmsNotification::where('company_id', $company->id)
                ->where('default_key', $definition['default_key'])
                ->exists();

            if ($exists) {
                continue;
            }

            SmsNotification::create(array_merge($definition, [
                'company_id' => $company->id,
                'location_id' => null,
                'is_default' => true,
                'is_active' => true,
                'default_body' => $definition['body'],
            ]));
        }

        Log::info("Default SMS notifications seeded for company {$company->id}");
    }

    public static function getDefaultDefinitions(): array
    {
        $C = SmsNotification::RECIPIENT_CUSTOMER;
        $STAFF = [
            SmsNotification::RECIPIENT_STAFF,
            SmsNotification::RECIPIENT_COMPANY_ADMIN,
            SmsNotification::RECIPIENT_LOCATION_MANAGER,
        ];

        return [
            // ---- Parties (packages) ----
            [
                'default_key' => SmsNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER,
                'name' => 'Party Booking Confirmation (Customer)',
                'description' => 'Sent to the customer when a party booking is confirmed.',
                'trigger_type' => SmsNotification::TRIGGER_BOOKING_CONFIRMED,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: Party booked! {{package_name}} on {{booking_date}} at {{booking_time}}. Ref {{booking_reference}}. Balance due {{booking_balance}}. Info: {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_BOOKING_REMINDER_CUSTOMER,
                'name' => 'Party Booking Reminder (Customer)',
                'description' => 'Sent to the customer before their party booking. Uses send_before_hours.',
                'trigger_type' => SmsNotification::TRIGGER_BOOKING_REMINDER,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'send_before_hours' => 24,
                'body' => '{{company_name}} reminder: your {{package_name}} party is {{booking_date}} at {{booking_time}}, {{location_name}}. See you soon! {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_BOOKING_RESCHEDULE_CUSTOMER,
                'name' => 'Party Booking Reschedule (Customer)',
                'description' => 'Sent to the customer when a party booking is updated or rescheduled.',
                'trigger_type' => SmsNotification::TRIGGER_BOOKING_RESCHEDULED,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: your booking {{booking_reference}} was updated. New date/time: {{booking_date}} {{booking_time}}. Questions? {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_BOOKING_CANCELLATION_CUSTOMER,
                'name' => 'Party Booking Cancellation (Customer)',
                'description' => 'Sent to the customer when a party booking is cancelled.',
                'trigger_type' => SmsNotification::TRIGGER_BOOKING_CANCELLED,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: your booking {{booking_reference}} on {{booking_date}} has been cancelled. Refunds (if any) take 5-10 business days. {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_BOOKING_CONFIRMATION_STAFF,
                'name' => 'Party Booking Alert (Staff)',
                'description' => 'Sent to staff/admin/managers when a new party booking is confirmed.',
                'trigger_type' => SmsNotification::TRIGGER_BOOKING_CONFIRMED,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => $STAFF,
                'custom_phones' => [],
                'body' => 'New booking: {{package_name}} {{booking_date}} {{booking_time}}, {{booking_participants}} guests. {{customer_name}} {{customer_phone}}. Ref {{booking_reference}}',
            ],

            // ---- Attractions ----
            [
                'default_key' => SmsNotification::DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER,
                'name' => 'Attraction Confirmation (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is confirmed.',
                'trigger_type' => SmsNotification::TRIGGER_PURCHASE_CONFIRMED,
                'entity_type' => SmsNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: {{attraction_name}} confirmed! Qty {{purchase_quantity}}, total {{purchase_total}}. Ref {{purchase_reference}}. Show at entry. {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_PURCHASE_REMINDER_CUSTOMER,
                'name' => 'Attraction Reminder (Customer)',
                'description' => 'Sent to the customer before their attraction visit. Uses send_before_hours.',
                'trigger_type' => SmsNotification::TRIGGER_PURCHASE_REMINDER,
                'entity_type' => SmsNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'send_before_hours' => 24,
                'body' => '{{company_name}} reminder: your {{attraction_name}} visit is on {{purchase_date}}, {{location_name}}. See you soon! {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_PURCHASE_RESCHEDULE_CUSTOMER,
                'name' => 'Attraction Reschedule (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is updated or rescheduled.',
                'trigger_type' => SmsNotification::TRIGGER_PURCHASE_RESCHEDULED,
                'entity_type' => SmsNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: your {{attraction_name}} purchase {{purchase_reference}} was updated. New date: {{purchase_date}}. Questions? {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_PURCHASE_CANCELLATION_CUSTOMER,
                'name' => 'Attraction Cancellation (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is cancelled.',
                'trigger_type' => SmsNotification::TRIGGER_PURCHASE_CANCELLED,
                'entity_type' => SmsNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: your {{attraction_name}} purchase {{purchase_reference}} has been cancelled. Refunds (if any) take 5-10 business days. {{location_phone}}',
            ],

            // ---- Events ----
            [
                'default_key' => SmsNotification::DEFAULT_EVENT_CONFIRMATION_CUSTOMER,
                'name' => 'Event Confirmation (Customer)',
                'description' => 'Sent to the customer when an event purchase is confirmed.',
                'trigger_type' => SmsNotification::TRIGGER_EVENT_CONFIRMED,
                'entity_type' => SmsNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: you\'re going to {{event_name}}! {{event_date}} {{event_time}}. Qty {{event_quantity}}. Ref {{event_reference}}. Balance {{event_balance}}. {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_EVENT_REMINDER_CUSTOMER,
                'name' => 'Event Reminder (Customer)',
                'description' => 'Sent to the customer before an event. Uses send_before_hours.',
                'trigger_type' => SmsNotification::TRIGGER_EVENT_REMINDER,
                'entity_type' => SmsNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'send_before_hours' => 24,
                'body' => '{{company_name}} reminder: {{event_name}} is on {{event_date}} at {{event_time}}, {{location_name}}. Can\'t wait to see you! {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_EVENT_RESCHEDULE_CUSTOMER,
                'name' => 'Event Reschedule (Customer)',
                'description' => 'Sent to the customer when an event purchase is rescheduled.',
                'trigger_type' => SmsNotification::TRIGGER_EVENT_RESCHEDULED,
                'entity_type' => SmsNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: {{event_name}} ({{event_reference}}) has been rescheduled to {{event_date}} {{event_time}}. Questions? {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_EVENT_CANCELLATION_CUSTOMER,
                'name' => 'Event Cancellation (Customer)',
                'description' => 'Sent to the customer when an event purchase is cancelled.',
                'trigger_type' => SmsNotification::TRIGGER_EVENT_CANCELLED,
                'entity_type' => SmsNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: your {{event_name}} order {{event_reference}} has been cancelled. Refunds (if any) take 5-10 business days. {{location_phone}}',
            ],

            // ---- Payments (all segments) ----
            [
                'default_key' => SmsNotification::DEFAULT_PAYMENT_RECEIVED_CUSTOMER,
                'name' => 'Payment Received (Customer)',
                'description' => 'Sent to the customer when a payment is received.',
                'trigger_type' => SmsNotification::TRIGGER_PAYMENT_RECEIVED,
                'entity_type' => SmsNotification::ENTITY_ALL,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: payment of {{payment_amount}} received. Ref {{payment_reference}}. Thank you! {{location_phone}}',
            ],
            [
                'default_key' => SmsNotification::DEFAULT_PAYMENT_REFUNDED_CUSTOMER,
                'name' => 'Payment Refunded (Customer)',
                'description' => 'Sent to the customer when a refund is processed.',
                'trigger_type' => SmsNotification::TRIGGER_PAYMENT_REFUNDED,
                'entity_type' => SmsNotification::ENTITY_ALL,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => '{{company_name}}: a refund of {{payment_amount}} has been processed for {{payment_reference}}. Allow 5-10 business days. {{location_phone}}',
            ],

            // ---- Party invitation (sent per-guest by InvitationService) ----
            [
                'default_key' => SmsNotification::DEFAULT_INVITATION_GUEST,
                'name' => 'Party Invitation (Guest)',
                'description' => 'Text sent to each invited party guest with the RSVP link. Sent by the invitation tool, not an automated trigger.',
                'trigger_type' => SmsNotification::TRIGGER_INVITATION_SENT,
                'entity_type' => SmsNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [$C],
                'custom_phones' => [],
                'body' => 'Hi {{guest_first_name}}! You\'re invited by {{host_name}} to a celebration at {{company_name}} {{location_name}} on {{booking_date}} at {{booking_time}}. RSVP here: {{rsvp_url}}',
            ],
        ];
    }
}
