<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\EmailNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DefaultEmailNotificationSeeder extends Seeder
{
    /**
     * Seed default email notifications for all companies.
     * Safe to re-run — skips companies that already have defaults.
     */
    public function run(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            $this->seedForCompany($company);
        }

        $this->command?->info("Default email notifications seeded for {$companies->count()} companies.");
    }

    /**
     * Seed default email notifications for a specific company.
     * Called from seeder and also from company creation flow.
     */
    public static function seedForCompany(Company $company): void
    {
        $defaults = self::getDefaultDefinitions();

        foreach ($defaults as $definition) {
            // Skip if already exists for this company
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

    /**
     * Get all default email notification definitions.
     */
    public static function getDefaultDefinitions(): array
    {
        return [
            // ============================================
            // BOOKING NOTIFICATIONS
            // ============================================
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

            // ============================================
            // ATTRACTION PURCHASE NOTIFICATIONS
            // ============================================
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

            // ============================================
            // PAYMENT NOTIFICATIONS
            // ============================================
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
        ];
    }

    // ============================================
    // DEFAULT EMAIL BODY TEMPLATES
    // ============================================

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
}
