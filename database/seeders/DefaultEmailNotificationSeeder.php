<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\EmailNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DefaultEmailNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            $this->seedForCompany($company);
        }

        $this->command?->info("Default email notifications seeded for {$companies->count()} companies.");
    }

    public static function seedForCompany(Company $company): void
    {
        $defaults = self::getDefaultDefinitions();

        foreach ($defaults as $definition) {
            $exists = EmailNotification::where('company_id', $company->id)
                ->where('default_key', $definition['default_key'])
                ->exists();

            if ($exists) {
                continue;
            }

            EmailNotification::create(array_merge($definition, [
                'company_id' => $company->id,
                'location_id' => null, // Applies to all locations
                'is_default' => true,
                'is_active' => true,
                'include_qr_code' => $definition['include_qr_code'] ?? true,
                'default_subject' => $definition['subject'],
                'default_body' => $definition['body'],
            ]));
        }

        Log::info("Default email notifications seeded for company {$company->id}");
    }

    public static function getDefaultDefinitions(): array
    {
        return [
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER,
                'name' => 'Booking Confirmation (Customer)',
                'description' => 'Sent to the customer when a new booking is created. Includes booking details, package info, location, and QR code.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'subject' => 'Booking Confirmation - {{booking_reference}}',
                'body' => self::getBookingConfirmationCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_CONFIRMATION_STAFF,
                'name' => 'Booking Notification (Staff)',
                'description' => 'Sent to staff, admin, and location managers when a new booking is created. Includes customer info and booking details.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [
                    EmailNotification::RECIPIENT_STAFF,
                    EmailNotification::RECIPIENT_COMPANY_ADMIN,
                    EmailNotification::RECIPIENT_LOCATION_MANAGER,
                ],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'New Booking Alert - {{booking_reference}}',
                'body' => self::getBookingConfirmationStaffBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_CANCELLATION_CUSTOMER,
                'name' => 'Booking Cancellation (Customer)',
                'description' => 'Sent to the customer when a booking is cancelled. Includes refund/void information.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_CANCELLED,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Booking Cancelled - {{booking_reference}}',
                'body' => self::getBookingCancellationCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_REMINDER_CUSTOMER,
                'name' => 'Booking Reminder (Customer)',
                'description' => 'Sent to the customer before their booking as a reminder. Uses send_before_hours to schedule.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_REMINDER,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'send_before_hours' => 24,
                'subject' => 'Reminder: Your Booking is Tomorrow - {{company_name}}',
                'body' => self::getBookingReminderCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_UPDATED_CUSTOMER,
                'name' => 'Booking Updated (Customer)',
                'description' => 'Sent to the customer when their booking details are updated or rescheduled.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_UPDATED,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'subject' => 'Booking Updated - {{booking_reference}}',
                'body' => self::getBookingUpdatedCustomerBody(),
            ],

            [
                'default_key' => EmailNotification::DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER,
                'name' => 'Purchase Confirmation (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is created. Includes purchase details and QR code.',
                'trigger_type' => EmailNotification::TRIGGER_PURCHASE_CONFIRMED,
                'entity_type' => EmailNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'subject' => 'Purchase Confirmation - {{attraction_name}}',
                'body' => self::getPurchaseConfirmationCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_PURCHASE_CANCELLATION_CUSTOMER,
                'name' => 'Purchase Cancellation (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is cancelled. Includes refund information.',
                'trigger_type' => EmailNotification::TRIGGER_PURCHASE_CANCELLED,
                'entity_type' => EmailNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Purchase Cancelled - {{attraction_name}}',
                'body' => self::getPurchaseCancellationCustomerBody(),
            ],

            [
                'default_key' => EmailNotification::DEFAULT_PAYMENT_RECEIVED_CUSTOMER,
                'name' => 'Payment Received (Customer)',
                'description' => 'Sent to the customer when a payment is successfully received.',
                'trigger_type' => EmailNotification::TRIGGER_PAYMENT_RECEIVED,
                'entity_type' => EmailNotification::ENTITY_ALL,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Payment Received - {{payment_reference}}',
                'body' => self::getPaymentReceivedCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_PAYMENT_REFUNDED_CUSTOMER,
                'name' => 'Payment Refunded (Customer)',
                'description' => 'Sent to the customer when a refund is processed.',
                'trigger_type' => EmailNotification::TRIGGER_PAYMENT_REFUNDED,
                'entity_type' => EmailNotification::ENTITY_ALL,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Refund Processed - {{payment_reference}}',
                'body' => self::getPaymentRefundedCustomerBody(),
            ],

            // ---- Party reschedule ----
            [
                'default_key' => EmailNotification::DEFAULT_BOOKING_RESCHEDULE_CUSTOMER,
                'name' => 'Party Booking Reschedule (Customer)',
                'description' => 'Sent to the customer when a party booking is rescheduled.',
                'trigger_type' => EmailNotification::TRIGGER_BOOKING_RESCHEDULED,
                'entity_type' => EmailNotification::ENTITY_PACKAGE,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'subject' => 'Booking Rescheduled - {{booking_reference}}',
                'body' => self::getBookingUpdatedCustomerBody(),
            ],

            // ---- Attraction reminder + reschedule ----
            [
                'default_key' => EmailNotification::DEFAULT_PURCHASE_REMINDER_CUSTOMER,
                'name' => 'Attraction Reminder (Customer)',
                'description' => 'Sent to the customer before their attraction visit. Uses send_before_hours.',
                'trigger_type' => EmailNotification::TRIGGER_PURCHASE_REMINDER,
                'entity_type' => EmailNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => true,
                'send_before_hours' => 24,
                'subject' => 'Reminder: Your {{attraction_name}} Visit is Coming Up',
                'body' => self::getPurchaseReminderCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_PURCHASE_RESCHEDULE_CUSTOMER,
                'name' => 'Attraction Reschedule (Customer)',
                'description' => 'Sent to the customer when an attraction purchase is rescheduled.',
                'trigger_type' => EmailNotification::TRIGGER_PURCHASE_RESCHEDULED,
                'entity_type' => EmailNotification::ENTITY_ATTRACTION,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Purchase Updated - {{attraction_name}}',
                'body' => self::getPurchaseRescheduleCustomerBody(),
            ],

            // ---- Events ----
            [
                'default_key' => EmailNotification::DEFAULT_EVENT_CONFIRMATION_CUSTOMER,
                'name' => 'Event Confirmation (Customer)',
                'description' => 'Sent to the customer when an event purchase is confirmed.',
                'trigger_type' => EmailNotification::TRIGGER_EVENT_CONFIRMED,
                'entity_type' => EmailNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Your Tickets for {{event_name}}',
                'body' => self::getEventConfirmationCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_EVENT_REMINDER_CUSTOMER,
                'name' => 'Event Reminder (Customer)',
                'description' => 'Sent to the customer before an event. Uses send_before_hours.',
                'trigger_type' => EmailNotification::TRIGGER_EVENT_REMINDER,
                'entity_type' => EmailNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'send_before_hours' => 24,
                'subject' => 'Reminder: {{event_name}} is Coming Up',
                'body' => self::getEventReminderCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_EVENT_RESCHEDULE_CUSTOMER,
                'name' => 'Event Reschedule (Customer)',
                'description' => 'Sent to the customer when an event is rescheduled.',
                'trigger_type' => EmailNotification::TRIGGER_EVENT_RESCHEDULED,
                'entity_type' => EmailNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => '{{event_name}} Has Been Rescheduled',
                'body' => self::getEventRescheduleCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_EVENT_CANCELLATION_CUSTOMER,
                'name' => 'Event Cancellation (Customer)',
                'description' => 'Sent to the customer when an event purchase is cancelled.',
                'trigger_type' => EmailNotification::TRIGGER_EVENT_CANCELLED,
                'entity_type' => EmailNotification::ENTITY_EVENT,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Event Cancelled - {{event_name}}',
                'body' => self::getEventCancellationCustomerBody(),
            ],

            // ---- Waivers ----
            [
                'default_key' => EmailNotification::DEFAULT_WAIVER_STAFF_SENT_CUSTOMER,
                'name' => 'Waiver Link Sent (Customer)',
                'description' => 'Sent when a staff member or the booking flow sends a waiver link to the customer/guardian to complete before their visit.',
                'trigger_type' => EmailNotification::TRIGGER_WAIVER_STAFF_SENT,
                'entity_type' => EmailNotification::ENTITY_WAIVER,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Complete Your Waiver - {{company_name}}',
                'body' => self::getWaiverLinkCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_WAIVER_REMINDER_CUSTOMER,
                'name' => 'Waiver Reminder (Customer)',
                'description' => 'Sent when a waiver is still incomplete within the reminder window before the selected visit date.',
                'trigger_type' => EmailNotification::TRIGGER_WAIVER_REMINDER,
                'entity_type' => EmailNotification::ENTITY_WAIVER,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Reminder: Please Complete Your Waiver - {{company_name}}',
                'body' => self::getWaiverReminderCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_WAIVER_SIGNED_CUSTOMER,
                'name' => 'Waiver Signed (Customer)',
                'description' => 'Sent to the customer/guardian after their waiver is completed and accepted electronically.',
                'trigger_type' => EmailNotification::TRIGGER_WAIVER_SIGNED,
                'entity_type' => EmailNotification::ENTITY_WAIVER,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Waiver Completed - {{company_name}}',
                'body' => self::getWaiverSignedCustomerBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_WAIVER_BULK_CHAPERONE,
                'name' => 'Bulk Waiver Invite (Chaperone)',
                'description' => 'Sent to a group organizer/chaperone so they can invite parents/guardians to complete waivers for their group.',
                'trigger_type' => EmailNotification::TRIGGER_WAIVER_BULK_CHAPERONE,
                'entity_type' => EmailNotification::ENTITY_WAIVER,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Waivers Needed for Your Group - {{company_name}}',
                'body' => self::getWaiverBulkChaperoneBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_WAIVER_PARENT_INVITE,
                'name' => 'Waiver Invite (Parent/Guardian)',
                'description' => 'Sent to a parent/guardian by a chaperone to complete a waiver for their minor(s).',
                'trigger_type' => EmailNotification::TRIGGER_WAIVER_PARENT_INVITE,
                'entity_type' => EmailNotification::ENTITY_WAIVER,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'custom_emails' => [],
                'include_qr_code' => false,
                'subject' => 'Please Complete a Waiver - {{company_name}}',
                'body' => self::getWaiverParentInviteBody(),
            ],
            [
                'default_key' => EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT,
                'name' => 'End of Day Sales Report',
                'description' => 'Automated daily summary of the day\'s sales (transactions created today, Michigan time). Sent once per business day to the configured recipients. Delivered by the reports:send-daily-sales scheduled command.',
                'trigger_type' => EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT,
                'entity_type' => EmailNotification::ENTITY_ALL,
                'entity_ids' => [],
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOM],
                'custom_emails' => [
                    'clark@zone-entertainment.com',
                    'gaz@zone-entertainment.com',
                    'brian@zone-entertainment.com',
                ],
                'include_qr_code' => false,
                'subject' => 'End of Day Sales Report - {{report_date}}',
                'body' => self::getEndOfDaySalesReportBody(),
            ],
        ];
    }

    protected static function getWaiverLinkCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Complete Your Waiver</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">{{activity_name}}</p>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Hi {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">To save time when you arrive at {{location_name}}, please complete your waiver before your visit on {{waiver_date}}.</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{waiver_link}}" style="display: inline-block; background-color: #1e40af; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600;">Complete Waiver</a>
        </div>
        <p style="margin: 0 0 8px 0; font-size: 13px; color: #6b7280; line-height: 1.6;">If the button doesn't work, copy and paste this link:<br><span style="color: #1e40af;">{{waiver_link}}</span></p>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getWaiverReminderCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #b45309; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Your Visit Is Coming Up</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">{{activity_name}}</p>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Hi {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">This is a friendly reminder to complete your waiver before arriving at {{location_name}}. Completing it ahead of time saves you time at check-in.</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{waiver_link}}" style="display: inline-block; background-color: #b45309; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600;">Complete Waiver Now</a>
        </div>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getWaiverSignedCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #059669; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Waiver Completed</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">{{activity_name}}</p>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Hi {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Thank you &mdash; your waiver has been completed and recorded. You're all set for your visit to {{location_name}}.</p>
        <p style="margin: 0; font-size: 14px;">See you soon,<br><strong>{{company_name}}</strong></p>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getWaiverBulkChaperoneBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Waivers Needed for Your Group</h1>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Hi {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Your group needs each participant (or a parent/guardian for minors) to complete a waiver before visiting {{location_name}}. Use the link below to add your group's contacts and send them their waiver invites, and to track who has completed theirs.</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{waiver_link}}" style="display: inline-block; background-color: #1e40af; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600;">Manage Group Waivers</a>
        </div>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getWaiverParentInviteBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Please Complete a Waiver</h1>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Hi {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">You've been asked to complete a waiver for an upcoming visit to {{location_name}}. You can sign for yourself and any minors in your care using the link below.</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{waiver_link}}" style="display: inline-block; background-color: #1e40af; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600;">Complete Waiver</a>
        </div>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }


    protected static function getEndOfDaySalesReportBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #374151; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">End of Day Sales Report</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.85;">{{report_date}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 24px 16px; text-align: center;">
                    <p style="margin: 0 0 6px 0; font-size: 12px; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7280;">Total Collected</p>
                    <p style="margin: 0; font-size: 32px; font-weight: 700; color: #111827;">{{total_collected}}</p>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #6b7280;">{{report_scope}} &middot; {{items_sold}} items sold</p>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">Financial Summary</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Gross Sales</strong>
                    <span style="color: #111827; float: right;">{{gross_sales}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Discounts</strong>
                    <span style="color: #111827; float: right;">- {{discount_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Net Sales</strong>
                    <span style="color: #111827; float: right;">{{net_sales}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Tax</strong>
                    <span style="color: #111827; float: right;">{{tax_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Fees</strong>
                    <span style="color: #111827; float: right;">{{fee_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount Billed</strong>
                    <span style="color: #111827; float: right;">{{total_billed}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Collected (Card)</strong>
                    <span style="color: #111827; float: right;">{{collected_card}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Collected (Cash / In-Store)</strong>
                    <span style="color: #111827; float: right;">{{collected_cash}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Balance Due</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{balance_due}}</span>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">By Location</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 6px; margin: 0 0 24px 0; border-collapse: collapse;">
            <tr style="background: #f3f4f6;">
                <th style="padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Location</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Net Sales</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Collected</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Items</th>
            </tr>
            {{location_breakdown_rows}}
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #111827;">By Category</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 6px; margin: 0 0 8px 0; border-collapse: collapse;">
            <tr style="background: #f3f4f6;">
                <th style="padding: 10px 16px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Category</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Gross</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Net</th>
                <th style="padding: 10px 16px; text-align: right; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; border-bottom: 1px solid #e5e7eb;">Collected</th>
            </tr>
            {{category_breakdown_rows}}
        </table>

        <p style="margin: 16px 0 0 0; font-size: 12px; color: #9ca3af;">Generated {{generated_at}} &middot; Figures reflect transactions created during the business day (Michigan time).</p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getBookingConfirmationCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Booking Confirmation</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Reference: {{booking_reference}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Thank you for your booking! Here are your booking details:</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{booking_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Package:</strong>
                    <span style="color: #111827; float: right;">{{package_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{booking_date}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Time:</strong>
                    <span style="color: #111827; float: right;">{{booking_time}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Participants:</strong>
                    <span style="color: #111827; float: right;">{{booking_participants}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Room:</strong>
                    <span style="color: #111827; float: right;">{{room_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{booking_total}}</span>
                </td>
            </tr>
        </table>

        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 0 0 24px 0;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #1e40af;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">Phone: {{location_phone}}</p>
        </div>

        <div style="text-align: center; margin: 24px 0;">
            {{qr_code}}
            <p style="color: #6b7280; font-size: 12px; margin: 8px 0 0 0;">Show this QR code at check-in</p>
        </div>

        {{waiver_section}}

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">We look forward to seeing you!</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getBookingConfirmationStaffBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #059669; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">New Booking Alert</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Reference: {{booking_reference}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">A new booking has been created. Here are the details:</p>

        <h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">Customer Information</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Name:</strong>
                    <span style="color: #111827; float: right;">{{customer_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Email:</strong>
                    <span style="color: #111827; float: right;">{{customer_email}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Phone:</strong>
                    <span style="color: #111827; float: right;">{{customer_phone}}</span>
                </td>
            </tr>
        </table>

        <h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">Booking Details</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{booking_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Package:</strong>
                    <span style="color: #111827; float: right;">{{package_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{booking_date}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Time:</strong>
                    <span style="color: #111827; float: right;">{{booking_time}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Participants:</strong>
                    <span style="color: #111827; float: right;">{{booking_participants}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Room:</strong>
                    <span style="color: #111827; float: right;">{{room_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{booking_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Payment Status:</strong>
                    <span style="color: #111827; float: right;">{{booking_payment_status}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Notes:</strong>
                    <span style="color: #111827; float: right;">{{booking_notes}}</span>
                </td>
            </tr>
        </table>

        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #059669;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
        </div>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getBookingCancellationCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #dc2626; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Booking Cancelled</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Reference: {{booking_reference}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Your booking has been cancelled. If a refund is applicable, it will be processed to your original payment method. Please allow 5-10 business days for the refund to appear on your statement.</p>

        <h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">Cancelled Booking Details</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{booking_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Package:</strong>
                    <span style="color: #111827; float: right;">{{package_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{booking_date}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount Paid:</strong>
                    <span style="color: #111827; float: right;">{{booking_amount_paid}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Status:</strong>
                    <span style="color: #dc2626; font-weight: 600; float: right;">Cancelled</span>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions about the cancellation, please contact us at {{location_email}} or {{location_phone}}.</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">We hope to see you in the future!</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getBookingReminderCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #7c3aed; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Booking Reminder</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Your visit is coming up!</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">This is a friendly reminder about your upcoming booking. We can't wait to see you!</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{booking_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Package:</strong>
                    <span style="color: #111827; float: right;">{{package_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{booking_date}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Time:</strong>
                    <span style="color: #111827; float: right;">{{booking_time}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Participants:</strong>
                    <span style="color: #111827; float: right;">{{booking_participants}}</span>
                </td>
            </tr>
        </table>

        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 0 0 24px 0;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #7c3aed;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">Phone: {{location_phone}}</p>
        </div>

        <div style="text-align: center; margin: 24px 0;">
            {{qr_code}}
            <p style="color: #6b7280; font-size: 12px; margin: 8px 0 0 0;">Show this QR code at check-in</p>
        </div>

        <p style="margin: 0; font-size: 14px;">See you soon!<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getBookingUpdatedCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #d97706; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Booking Updated</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Reference: {{booking_reference}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Your booking has been updated. Please review the updated details below:</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{booking_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Package:</strong>
                    <span style="color: #111827; float: right;">{{package_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{booking_date}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Time:</strong>
                    <span style="color: #111827; float: right;">{{booking_time}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Participants:</strong>
                    <span style="color: #111827; float: right;">{{booking_participants}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{booking_total}}</span>
                </td>
            </tr>
        </table>

        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 0 0 24px 0;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #d97706;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">Phone: {{location_phone}}</p>
        </div>

        <div style="text-align: center; margin: 24px 0;">
            {{qr_code}}
            <p style="color: #6b7280; font-size: 12px; margin: 8px 0 0 0;">Show this QR code at check-in</p>
        </div>

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getPurchaseConfirmationCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Purchase Confirmation</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">{{attraction_name}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Thank you for your purchase! Here are your purchase details:</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Attraction:</strong>
                    <span style="color: #111827; float: right;">{{attraction_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Quantity:</strong>
                    <span style="color: #111827; float: right;">{{purchase_quantity}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Unit Price:</strong>
                    <span style="color: #111827; float: right;">{{purchase_unit_price}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Total:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{purchase_total}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{purchase_date}}</span>
                </td>
            </tr>
        </table>

        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 0 0 24px 0;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #1e40af;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">Phone: {{location_phone}}</p>
        </div>

        <div style="text-align: center; margin: 24px 0;">
            {{qr_code}}
            <p style="color: #6b7280; font-size: 12px; margin: 8px 0 0 0;">Show this QR code when you arrive</p>
        </div>

        {{waiver_section}}

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Enjoy your experience!</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getPurchaseCancellationCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #dc2626; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Purchase Cancelled</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">{{attraction_name}}</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">Your purchase has been cancelled. If a refund is applicable, it will be processed to your original payment method. Please allow 5-10 business days for the refund to appear on your statement.</p>

        <h3 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #111827;">Cancelled Purchase Details</h3>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Attraction:</strong>
                    <span style="color: #111827; float: right;">{{attraction_name}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Quantity:</strong>
                    <span style="color: #111827; float: right;">{{purchase_quantity}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount Paid:</strong>
                    <span style="color: #111827; float: right;">{{purchase_amount_paid}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Status:</strong>
                    <span style="color: #dc2626; font-weight: 600; float: right;">Cancelled</span>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getPaymentReceivedCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #059669; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Payment Received</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Thank you for your payment!</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">We have successfully received your payment. Here are the details:</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Amount:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{payment_amount}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Method:</strong>
                    <span style="color: #111827; float: right;">{{payment_method}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Transaction ID:</strong>
                    <span style="color: #111827; float: right;">{{payment_transaction_id}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{payment_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{payment_date}}</span>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions about this payment, please contact us at {{location_email}} or {{location_phone}}.</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getPaymentRefundedCustomerBody(): string
    {
        return <<<'HTML'
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: #d97706; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">Refund Processed</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">Your refund has been initiated</p>
    </div>

    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">A refund has been processed for your account. Please allow 5-10 business days for the refund to appear on your statement.</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Refund Amount:</strong>
                    <span style="color: #111827; font-weight: 600; float: right;">{{payment_amount}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Method:</strong>
                    <span style="color: #111827; float: right;">{{payment_method}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Transaction ID:</strong>
                    <span style="color: #111827; float: right;">{{payment_transaction_id}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-size: 14px;">
                    <strong style="color: #6b7280;">Reference:</strong>
                    <span style="color: #111827; float: right;">{{payment_reference}}</span>
                </td>
            </tr>
            <tr>
                <td style="padding: 12px 16px; font-size: 14px;">
                    <strong style="color: #6b7280;">Date:</strong>
                    <span style="color: #111827; float: right;">{{payment_date}}</span>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.6;">If you have any questions about this refund, please contact us at {{location_email}} or {{location_phone}}.</p>

        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>

    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    /**
     * Branded shell shared by the newer templates. $rows is an array of
     * [label, value] pairs rendered as a detail table.
     */
    protected static function shell(string $headerColor, string $title, string $subtitle, string $intro, array $rows, string $outro, string $extra = ''): string
    {
        $rowsHtml = '';
        $count = count($rows);
        foreach (array_values($rows) as $i => $row) {
            $border = $i === $count - 1 ? '' : 'border-bottom: 1px solid #e5e7eb;';
            $label = $row[0];
            $value = $row[1];
            $rowsHtml .= <<<ROW
            <tr>
                <td style="padding: 12px 16px; $border font-size: 14px;">
                    <strong style="color: #6b7280;">$label</strong>
                    <span style="color: #111827; float: right;">$value</span>
                </td>
            </tr>
ROW;
        }

        return <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #374151;">
    <div style="background-color: $headerColor; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 600;">$title</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">$subtitle</p>
    </div>
    <div style="background-color: #ffffff; padding: 32px; border: 1px solid #e5e7eb; border-top: none;">
        <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6;">Dear {{customer_name}},</p>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">$intro</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 0 0 24px 0;">
            $rowsHtml
        </table>
        <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 0 0 24px 0;">
            <h3 style="margin: 0 0 8px 0; font-size: 16px; color: $headerColor;">Location</h3>
            <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600;">{{location_name}}</p>
            <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{{location_address}}</p>
            <p style="margin: 0; font-size: 14px; color: #4b5563;">Phone: {{location_phone}}</p>
        </div>
        <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6;">$outro</p>
        $extra
        <p style="margin: 0; font-size: 14px;">Best regards,<br><strong>{{company_name}} Team</strong></p>
    </div>
    <div style="padding: 16px 32px; text-align: center; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px; background: #f9fafb;">
        <p style="color: #9ca3af; font-size: 12px; margin: 0;">&copy; {{current_year}} {{company_name}}. All rights reserved.</p>
    </div>
</div>
HTML;
    }

    protected static function getPurchaseReminderCustomerBody(): string
    {
        return self::shell(
            '#7c3aed',
            'Visit Reminder',
            'Your visit is coming up!',
            'This is a friendly reminder about your upcoming attraction visit. We can\'t wait to see you!',
            [
                ['Attraction:', '{{attraction_name}}'],
                ['Quantity:', '{{purchase_quantity}}'],
                ['Date:', '{{purchase_date}}'],
                ['Reference:', '{{purchase_reference}}'],
            ],
            'If you have any questions, contact us at {{location_email}} or {{location_phone}}.'
        );
    }

    protected static function getPurchaseRescheduleCustomerBody(): string
    {
        return self::shell(
            '#d97706',
            'Purchase Updated',
            '{{attraction_name}}',
            'Your attraction purchase has been updated. Please review the latest details below:',
            [
                ['Attraction:', '{{attraction_name}}'],
                ['Quantity:', '{{purchase_quantity}}'],
                ['Date:', '{{purchase_date}}'],
                ['Total:', '{{purchase_total}}'],
                ['Reference:', '{{purchase_reference}}'],
            ],
            'If you did not request this change, contact us at {{location_email}} or {{location_phone}}.'
        );
    }

    protected static function getEventConfirmationCustomerBody(): string
    {
        return self::shell(
            '#1e40af',
            'You\'re Going!',
            '{{event_name}}',
            'Thank you for your purchase! Here are your event details:',
            [
                ['Event:', '{{event_name}}'],
                ['Date:', '{{event_date}}'],
                ['Time:', '{{event_time}}'],
                ['Tickets:', '{{event_quantity}}'],
                ['Total:', '{{event_total}}'],
                ['Balance Due:', '{{event_balance}}'],
                ['Reference:', '{{event_reference}}'],
            ],
            'If you have any questions, contact us at {{location_email}} or {{location_phone}}. Enjoy the event!',
            '{{waiver_section}}'
        );
    }

    protected static function getEventReminderCustomerBody(): string
    {
        return self::shell(
            '#7c3aed',
            'Event Reminder',
            '{{event_name}} is coming up!',
            'This is a friendly reminder about the upcoming event. We can\'t wait to see you there!',
            [
                ['Event:', '{{event_name}}'],
                ['Date:', '{{event_date}}'],
                ['Time:', '{{event_time}}'],
                ['Tickets:', '{{event_quantity}}'],
                ['Reference:', '{{event_reference}}'],
            ],
            'If you have any questions, contact us at {{location_email}} or {{location_phone}}.'
        );
    }

    protected static function getEventRescheduleCustomerBody(): string
    {
        return self::shell(
            '#d97706',
            'Event Rescheduled',
            '{{event_name}}',
            'The event you purchased tickets for has been rescheduled. Your tickets remain valid for the new date and time below:',
            [
                ['Event:', '{{event_name}}'],
                ['New Date:', '{{event_date}}'],
                ['New Time:', '{{event_time}}'],
                ['Tickets:', '{{event_quantity}}'],
                ['Reference:', '{{event_reference}}'],
            ],
            'If the new date does not work for you, contact us at {{location_email}} or {{location_phone}}.'
        );
    }

    protected static function getEventCancellationCustomerBody(): string
    {
        return self::shell(
            '#dc2626',
            'Event Cancelled',
            '{{event_name}}',
            'Your event order has been cancelled. If a refund is applicable, it will be processed to your original payment method. Please allow 5-10 business days for the refund to appear.',
            [
                ['Event:', '{{event_name}}'],
                ['Date:', '{{event_date}}'],
                ['Tickets:', '{{event_quantity}}'],
                ['Amount Paid:', '{{event_amount_paid}}'],
                ['Reference:', '{{event_reference}}'],
            ],
            'If you have any questions, contact us at {{location_email}} or {{location_phone}}.'
        );
    }
}
