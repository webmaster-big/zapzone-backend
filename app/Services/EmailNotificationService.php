<?php

namespace App\Services;

use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\EmailNotificationLog;
use App\Models\Location;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmailNotificationService
{
    protected GmailApiService $gmailService;

    public function __construct()
    {
        $this->gmailService = new GmailApiService();
    }

    // ============================================
    // MAIN TRIGGER METHODS - Call these from controllers
    // ============================================

    /**
     * Process any booking-related trigger.
     */
    public function triggerBookingNotification(Booking $booking, string $triggerType): void
    {
        try {
            $booking->load(['customer', 'package', 'location', 'room', 'addOns']);

            $notifications = EmailNotification::findForBooking($booking, $triggerType);

            if ($notifications->isNotEmpty()) {
                foreach ($notifications as $notification) {
                    Log::info('Processing custom email notification for booking', [
                        'booking_id' => $booking->id,
                        'notification_id' => $notification->id,
                        'trigger_type' => $triggerType,
                    ]);
                    $this->sendNotification($notification, $booking, 'booking');
                }
            } else {
                Log::info('No custom notification found for booking trigger', [
                    'booking_id' => $booking->id,
                    'trigger_type' => $triggerType,
                ]);
                // Only send default for created trigger
                if ($triggerType === EmailNotification::TRIGGER_BOOKING_CREATED) {
                    $this->sendDefaultBookingNotification($booking);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error processing booking notification', [
                'booking_id' => $booking->id,
                'trigger_type' => $triggerType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process any purchase-related trigger.
     */
    public function triggerPurchaseNotification(AttractionPurchase $purchase, string $triggerType): void
    {
        try {
            $purchase->load(['customer', 'attraction.location']);

            $notifications = EmailNotification::findForPurchase($purchase, $triggerType);

            if ($notifications->isNotEmpty()) {
                foreach ($notifications as $notification) {
                    Log::info('Processing custom email notification for purchase', [
                        'purchase_id' => $purchase->id,
                        'notification_id' => $notification->id,
                        'trigger_type' => $triggerType,
                    ]);
                    $this->sendNotification($notification, $purchase, 'purchase');
                }
            } else {
                Log::info('No custom notification found for purchase trigger', [
                    'purchase_id' => $purchase->id,
                    'trigger_type' => $triggerType,
                ]);
                // Only send default for created trigger
                if ($triggerType === EmailNotification::TRIGGER_PURCHASE_CREATED) {
                    $this->sendDefaultPurchaseNotification($purchase);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error processing purchase notification', [
                'purchase_id' => $purchase->id,
                'trigger_type' => $triggerType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process any payment-related trigger.
     */
    public function triggerPaymentNotification(Payment $payment, string $triggerType): void
    {
        try {
            $payment->load('payable');
            $notifications = EmailNotification::findForPayment($payment, $triggerType);

            foreach ($notifications as $notification) {
                Log::info('Processing payment email notification', [
                    'payment_id' => $payment->id,
                    'notification_id' => $notification->id,
                    'trigger_type' => $triggerType,
                ]);
                $this->sendNotification($notification, $payment, 'payment');
            }
        } catch (\Exception $e) {
            Log::error('Error processing payment notification', [
                'payment_id' => $payment->id,
                'trigger_type' => $triggerType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ============================================
    // LEGACY METHODS - For backward compatibility
    // ============================================

    /**
     * Process email notification for a new booking (legacy method).
     */
    public function processBookingCreated(Booking $booking): void
    {
        $this->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CREATED);
    }

    /**
     * Process email notification for a new attraction purchase (legacy method).
     */
    public function processPurchaseCreated(AttractionPurchase $purchase): void
    {
        $this->triggerPurchaseNotification($purchase, EmailNotification::TRIGGER_PURCHASE_CREATED);
    }

    /**
     * Send notification using custom configuration.
     */
    protected function sendNotification(EmailNotification $notification, $entity, string $type, ?string $overrideRecipient = null): void
    {
        $recipients = $overrideRecipient
            ? [['email' => $overrideRecipient, 'type' => 'custom']]
            : $this->getRecipients($notification, $entity, $type);

        foreach ($recipients as $recipient) {
            try {
                // Create log entry
                $log = EmailNotificationLog::create([
                    'email_notification_id' => $notification->id,
                    'recipient_email' => $recipient['email'],
                    'recipient_type' => $recipient['type'],
                    'notifiable_type' => get_class($entity),
                    'notifiable_id' => $entity->id,
                    'status' => EmailNotificationLog::STATUS_PENDING,
                ]);

                // Build variables
                $variables = $this->buildVariables($entity, $type, $notification->include_qr_code);

                // Get subject and body
                $subject = $this->replaceVariables($notification->getEffectiveSubject(), $variables);
                $body = $this->replaceVariables($notification->getEffectiveBody(), $variables);

                // Generate HTML
                $htmlBody = $this->generateHtmlEmail($body);

                // Send email
                $this->sendEmail($recipient['email'], $subject, $htmlBody, $variables);

                $log->markAsSent();

                Log::info('Email notification sent', [
                    'notification_id' => $notification->id,
                    'recipient' => $recipient['email'],
                    'type' => $type,
                ]);

            } catch (\Exception $e) {
                if (isset($log)) {
                    $log->markAsFailed($e->getMessage());
                }

                Log::error('Failed to send email notification', [
                    'notification_id' => $notification->id,
                    'recipient' => $recipient['email'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get recipients based on notification configuration.
     */
    protected function getRecipients(EmailNotification $notification, $entity, string $type): array
    {
        $recipients = [];
        $recipientTypes = $notification->recipient_types ?? [];

        foreach ($recipientTypes as $recipientType) {
            switch ($recipientType) {
                case EmailNotification::RECIPIENT_CUSTOMER:
                    $email = $this->getCustomerEmail($entity, $type);
                    if ($email) {
                        $recipients[] = ['email' => $email, 'type' => 'customer'];
                    }
                    break;

                case EmailNotification::RECIPIENT_STAFF:
                    $staffEmails = $this->getStaffEmails($entity, $type);
                    foreach ($staffEmails as $email) {
                        $recipients[] = ['email' => $email, 'type' => 'staff'];
                    }
                    break;

                case EmailNotification::RECIPIENT_COMPANY_ADMIN:
                    $adminEmails = $this->getCompanyAdminEmails($entity, $type);
                    foreach ($adminEmails as $email) {
                        $recipients[] = ['email' => $email, 'type' => 'company_admin'];
                    }
                    break;

                case EmailNotification::RECIPIENT_LOCATION_MANAGER:
                    $managerEmails = $this->getLocationManagerEmails($entity, $type);
                    foreach ($managerEmails as $email) {
                        $recipients[] = ['email' => $email, 'type' => 'location_manager'];
                    }
                    break;

                case EmailNotification::RECIPIENT_CUSTOM:
                    $customEmails = $notification->custom_emails ?? [];
                    foreach ($customEmails as $email) {
                        $recipients[] = ['email' => $email, 'type' => 'custom'];
                    }
                    break;
            }
        }

        // Remove duplicates
        $seen = [];
        return array_filter($recipients, function ($recipient) use (&$seen) {
            if (in_array($recipient['email'], $seen)) {
                return false;
            }
            $seen[] = $recipient['email'];
            return true;
        });
    }

    /**
     * Get customer email from entity.
     */
    protected function getCustomerEmail($entity, string $type): ?string
    {
        if ($type === 'booking') {
            return $entity->customer?->email ?? $entity->guest_email;
        } else {
            return $entity->customer?->email ?? $entity->guest_email;
        }
    }

    /**
     * Get staff emails for location.
     */
    protected function getStaffEmails($entity, string $type): array
    {
        $locationId = $type === 'booking' ? $entity->location_id : ($entity->attraction->location_id ?? null);

        if (!$locationId) {
            return [];
        }

        return User::where('location_id', $locationId)
            ->where('role', 'attendant')
            ->where('status', 'active')
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();
    }

    /**
     * Get company admin emails.
     */
    protected function getCompanyAdminEmails($entity, string $type): array
    {
        $location = $type === 'booking' ? $entity->location : ($entity->attraction->location ?? null);

        if (!$location) {
            return [];
        }

        return User::where('company_id', $location->company_id)
            ->whereIn('role', ['company_admin', 'owner'])
            ->where('status', 'active')
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();
    }

    /**
     * Get location manager emails.
     */
    protected function getLocationManagerEmails($entity, string $type): array
    {
        $locationId = $type === 'booking' ? $entity->location_id : ($entity->attraction->location_id ?? null);

        if (!$locationId) {
            return [];
        }

        return User::where('location_id', $locationId)
            ->where('role', 'location_manager')
            ->where('status', 'active')
            ->whereNotNull('email')
            ->pluck('email')
            ->toArray();
    }

    /**
     * Build variables for email template.
     */
    protected function buildVariables($entity, string $type, bool $includeQrCode = true): array
    {
        switch ($type) {
            case 'booking':
                return $this->buildBookingVariables($entity, $includeQrCode);
            case 'purchase':
                return $this->buildPurchaseVariables($entity, $includeQrCode);
            case 'payment':
                return $this->buildPaymentVariables($entity);
            default:
                return $this->buildCommonVariables();
        }
    }

    /**
     * Build common variables available for all email types.
     */
    protected function buildCommonVariables(?Location $location = null, ?Company $company = null): array
    {
        return [
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
            'current_time' => now()->format('g:i A'),
            'location_name' => $location?->name ?? '',
            'location_address' => $location ? trim(implode(', ', array_filter([
                $location->address,
                $location->city,
                $location->state,
                $location->zip_code
            ]))) : '',
            'location_phone' => $location?->phone ?? '',
            'location_email' => $location?->email ?? '',
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'company_address' => $company?->address ?? '',
        ];
    }

    /**
     * Build variables for a booking.
     */
    protected function buildBookingVariables(Booking $booking, bool $includeQrCode = true): array
    {
        $customer = $booking->customer;
        $package = $booking->package;
        $location = $booking->location;
        $room = $booking->room;
        $company = $location?->company;

        // Build add-ons list
        $addOnsList = '';
        $addOnsTotal = 0;
        if ($booking->addOns && $booking->addOns->count() > 0) {
            $addOnItems = [];
            foreach ($booking->addOns as $addOn) {
                $qty = $addOn->pivot->quantity ?? 1;
                $price = $addOn->pivot->price ?? $addOn->price;
                $subtotal = $qty * $price;
                $addOnsTotal += $subtotal;
                $addOnItems[] = "{$addOn->name} x{$qty} - \${$subtotal}";
            }
            $addOnsList = implode('<br>', $addOnItems);
        }

        // QR Code
        $qrCodeHtml = '';
        if ($includeQrCode && $booking->qrcode_path) {
            $qrCodeUrl = Storage::url($booking->qrcode_path);
            $qrCodeHtml = "<img src=\"{$qrCodeUrl}\" alt=\"Booking QR Code\" style=\"width: 150px; height: 150px;\" />";
        }

        $locationAddress = $location
            ? trim(implode(', ', array_filter([
                $location->address,
                $location->city,
                $location->state,
                $location->zip_code
            ])))
            : '';

        return [
            // Customer variables
            'customer_name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : ($booking->guest_name ?? 'Guest'),
            'customer_first_name' => $customer?->first_name ?? explode(' ', $booking->guest_name ?? 'Guest')[0],
            'customer_last_name' => $customer?->last_name ?? '',
            'customer_email' => $customer?->email ?? $booking->guest_email ?? '',
            'customer_phone' => $customer?->phone ?? $booking->guest_phone ?? '',

            // Booking variables
            'booking_id' => (string) $booking->id,
            'booking_reference' => $booking->reference_number ?? '',
            'booking_date' => $booking->booking_date?->format('F j, Y') ?? '',
            'booking_time' => $booking->booking_time ?? '',
            'booking_status' => ucfirst($booking->status ?? ''),
            'booking_participants' => (string) ($booking->participants ?? 0),
            'booking_total' => '$' . number_format($booking->total_amount ?? 0, 2),
            'booking_amount_paid' => '$' . number_format($booking->amount_paid ?? 0, 2),
            'booking_balance' => '$' . number_format(($booking->total_amount ?? 0) - ($booking->amount_paid ?? 0), 2),
            'booking_payment_status' => ucfirst($booking->payment_status ?? ''),
            'booking_payment_method' => ucfirst($booking->payment_method ?? ''),
            'booking_notes' => $booking->notes ?? '',
            'booking_created_at' => $booking->created_at?->format('F j, Y g:i A') ?? '',

            // Package variables
            'package_name' => $package?->name ?? '',
            'package_description' => $package?->description ?? '',
            'package_duration' => (string) ($package?->duration_minutes ?? 0) . ' minutes',
            'package_price' => '$' . number_format($package?->price ?? 0, 2),
            'package_min_participants' => (string) ($package?->min_participants ?? 1),
            'package_max_participants' => (string) ($package?->max_participants ?? 10),

            // Room variables
            'room_name' => $room?->name ?? '',
            'room_description' => $room?->description ?? '',

            // Location variables
            'location_name' => $location?->name ?? '',
            'location_address' => $locationAddress,
            'location_phone' => $location?->phone ?? '',
            'location_email' => $location?->email ?? '',

            // Company variables
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'company_address' => $company?->address ?? '',

            // Add-ons
            'addons_list' => $addOnsList,
            'addons_total' => '$' . number_format($addOnsTotal, 2),

            // QR Code
            'qr_code' => $qrCodeHtml,
            'qr_code_url' => $booking->qrcode_path ? Storage::url($booking->qrcode_path) : '',

            // Date/time
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
        ];
    }

    /**
     * Build variables for a purchase.
     */
    protected function buildPurchaseVariables(AttractionPurchase $purchase, bool $includeQrCode = true): array
    {
        $customer = $purchase->customer;
        $attraction = $purchase->attraction;
        $location = $attraction?->location;
        $company = $location?->company;

        // QR Code
        $qrCodeHtml = '';
        if ($includeQrCode && $purchase->qrcode_path) {
            $qrCodeUrl = Storage::url($purchase->qrcode_path);
            $qrCodeHtml = "<img src=\"{$qrCodeUrl}\" alt=\"Purchase QR Code\" style=\"width: 150px; height: 150px;\" />";
        }

        $locationAddress = $location
            ? trim(implode(', ', array_filter([
                $location->address,
                $location->city,
                $location->state,
                $location->zip_code
            ])))
            : '';

        return [
            // Customer variables
            'customer_name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : ($purchase->guest_name ?? 'Guest'),
            'customer_first_name' => $customer?->first_name ?? explode(' ', $purchase->guest_name ?? 'Guest')[0],
            'customer_last_name' => $customer?->last_name ?? '',
            'customer_email' => $customer?->email ?? $purchase->guest_email ?? '',
            'customer_phone' => $customer?->phone ?? $purchase->guest_phone ?? '',

            // Purchase variables
            'purchase_id' => (string) $purchase->id,
            'purchase_reference' => $purchase->reference_number ?? '',
            'purchase_date' => $purchase->purchase_date?->format('F j, Y') ?? '',
            'purchase_status' => ucfirst($purchase->status ?? ''),
            'purchase_quantity' => (string) ($purchase->quantity ?? 1),
            'purchase_unit_price' => '$' . number_format($purchase->unit_price ?? 0, 2),
            'purchase_total' => '$' . number_format($purchase->total_amount ?? 0, 2),
            'purchase_amount_paid' => '$' . number_format($purchase->amount_paid ?? 0, 2),
            'purchase_balance' => '$' . number_format(($purchase->total_amount ?? 0) - ($purchase->amount_paid ?? 0), 2),
            'purchase_payment_method' => ucfirst($purchase->payment_method ?? ''),
            'purchase_notes' => $purchase->notes ?? '',
            'purchase_created_at' => $purchase->created_at?->format('F j, Y g:i A') ?? '',

            // Attraction variables
            'attraction_name' => $attraction?->name ?? '',
            'attraction_description' => $attraction?->description ?? '',
            'attraction_price' => '$' . number_format($attraction?->price ?? 0, 2),
            'attraction_duration' => (string) ($attraction?->duration_minutes ?? 0) . ' minutes',

            // Location variables
            'location_name' => $location?->name ?? '',
            'location_address' => $locationAddress,
            'location_phone' => $location?->phone ?? '',
            'location_email' => $location?->email ?? '',

            // Company variables
            'company_name' => $company?->company_name ?? '',
            'company_email' => $company?->email ?? '',
            'company_phone' => $company?->phone ?? '',
            'company_address' => $company?->address ?? '',

            // QR Code
            'qr_code' => $qrCodeHtml,
            'qr_code_url' => $purchase->qrcode_path ? Storage::url($purchase->qrcode_path) : '',

            // Date/time
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
        ];
    }

    /**
     * Build variables for a payment.
     */
    protected function buildPaymentVariables(Payment $payment): array
    {
        $payable = $payment->payable;
        $location = null;
        $company = null;
        $customerName = 'Customer';

        if ($payable instanceof Booking) {
            $location = $payable->location;
            $company = $location?->company;
            $customerName = $payable->customer
                ? trim($payable->customer->first_name . ' ' . $payable->customer->last_name)
                : ($payable->guest_name ?? 'Guest');
        }

        return array_merge($this->buildCommonVariables($location, $company), [
            'payment_id' => (string) $payment->id,
            'payment_amount' => '$' . number_format($payment->amount ?? 0, 2),
            'payment_method' => ucfirst($payment->payment_method ?? ''),
            'payment_status' => ucfirst($payment->status ?? ''),
            'payment_date' => $payment->created_at?->format('F j, Y g:i A') ?? '',
            'payment_transaction_id' => $payment->transaction_id ?? '',
            'payment_reference' => $payable?->reference_number ?? '',
            'customer_name' => $customerName,
            'payable_type' => class_basename($payment->payable_type ?? ''),
            'payable_id' => (string) ($payment->payable_id ?? ''),
        ]);
    }

    /**
     * Replace variables in content.
     */
    protected function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $content = preg_replace(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                $value ?? '',
                $content
            );
        }

        return $content;
    }

    /**
     * Generate HTML email.
     */
    protected function generateHtmlEmail(string $body): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    {$body}
</body>
</html>
HTML;
    }

    /**
     * Send email via Gmail API or fallback.
     */
    protected function sendEmail(string $to, string $subject, string $htmlBody, array $variables): void
    {
        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            $this->gmailService->sendEmail(
                $to,
                $subject,
                $htmlBody,
                $variables['company_name'] ?? 'Zap Zone',
                [] // No attachments for now
            );
        } else {
            // Fallback to Laravel Mail
            \Illuminate\Support\Facades\Mail::html($htmlBody, function ($message) use ($to, $subject, $variables) {
                $message->to($to)
                    ->subject($subject)
                    ->from(config('mail.from.address'), $variables['company_name'] ?? config('mail.from.name'));
            });
        }
    }

    /**
     * Send default booking notification.
     */
    protected function sendDefaultBookingNotification(Booking $booking): void
    {
        $customerEmail = $booking->customer?->email ?? $booking->guest_email;

        if (!$customerEmail) {
            Log::info('No customer email for booking, skipping default notification', [
                'booking_id' => $booking->id,
            ]);
            return;
        }

        try {
            $variables = $this->buildBookingVariables($booking, true);

            $subject = "Booking Confirmation - {$variables['booking_reference']}";
            $body = $this->getDefaultBookingEmailBody();
            $processedBody = $this->replaceVariables($body, $variables);
            $htmlBody = $this->generateHtmlEmail($processedBody);

            $this->sendEmail($customerEmail, $subject, $htmlBody, $variables);

            Log::info('Default booking notification sent', [
                'booking_id' => $booking->id,
                'recipient' => $customerEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send default booking notification', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send default purchase notification.
     */
    protected function sendDefaultPurchaseNotification(AttractionPurchase $purchase): void
    {
        $customerEmail = $purchase->customer?->email ?? $purchase->guest_email;

        if (!$customerEmail) {
            Log::info('No customer email for purchase, skipping default notification', [
                'purchase_id' => $purchase->id,
            ]);
            return;
        }

        try {
            $variables = $this->buildPurchaseVariables($purchase, true);

            $subject = "Purchase Confirmation - {$variables['attraction_name']}";
            $body = $this->getDefaultPurchaseEmailBody();
            $processedBody = $this->replaceVariables($body, $variables);
            $htmlBody = $this->generateHtmlEmail($processedBody);

            $this->sendEmail($customerEmail, $subject, $htmlBody, $variables);

            Log::info('Default purchase notification sent', [
                'purchase_id' => $purchase->id,
                'recipient' => $customerEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send default purchase notification', [
                'purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get default booking email body.
     */
    protected function getDefaultBookingEmailBody(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #333;">Booking Confirmation</h1>

    <p>Dear {{customer_name}},</p>

    <p>Thank you for your booking! Here are your booking details:</p>

    <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0;">Booking Details</h3>
        <p><strong>Reference:</strong> {{booking_reference}}</p>
        <p><strong>Package:</strong> {{package_name}}</p>
        <p><strong>Date:</strong> {{booking_date}}</p>
        <p><strong>Time:</strong> {{booking_time}}</p>
        <p><strong>Participants:</strong> {{booking_participants}}</p>
        <p><strong>Room:</strong> {{room_name}}</p>
        <p><strong>Total:</strong> {{booking_total}}</p>
    </div>

    <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0;">Location</h3>
        <p><strong>{{location_name}}</strong></p>
        <p>{{location_address}}</p>
        <p>Phone: {{location_phone}}</p>
    </div>

    <div style="text-align: center; margin: 30px 0;">
        {{qr_code}}
        <p style="color: #666; font-size: 12px;">Show this QR code at check-in</p>
    </div>

    <p>If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>

    <p>We look forward to seeing you!</p>

    <p>Best regards,<br>{{company_name}} Team</p>

    <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
    <p style="color: #999; font-size: 12px;">© {{current_year}} {{company_name}}. All rights reserved.</p>
</div>
HTML;
    }

    /**
     * Get default purchase email body.
     */
    protected function getDefaultPurchaseEmailBody(): string
    {
        return <<<HTML
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #333;">Purchase Confirmation</h1>

    <p>Dear {{customer_name}},</p>

    <p>Thank you for your purchase! Here are your purchase details:</p>

    <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0;">Purchase Details</h3>
        <p><strong>Attraction:</strong> {{attraction_name}}</p>
        <p><strong>Quantity:</strong> {{purchase_quantity}}</p>
        <p><strong>Unit Price:</strong> {{purchase_unit_price}}</p>
        <p><strong>Total:</strong> {{purchase_total}}</p>
        <p><strong>Date:</strong> {{purchase_date}}</p>
    </div>

    <div style="background: #f0f8ff; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3 style="margin-top: 0;">Location</h3>
        <p><strong>{{location_name}}</strong></p>
        <p>{{location_address}}</p>
        <p>Phone: {{location_phone}}</p>
    </div>

    <div style="text-align: center; margin: 30px 0;">
        {{qr_code}}
        <p style="color: #666; font-size: 12px;">Show this QR code when you arrive</p>
    </div>

    <p>If you have any questions, please contact us at {{location_email}} or {{location_phone}}.</p>

    <p>Enjoy your experience!</p>

    <p>Best regards,<br>{{company_name}} Team</p>

    <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">
    <p style="color: #999; font-size: 12px;">© {{current_year}} {{company_name}}. All rights reserved.</p>
</div>
HTML;
    }

    /**
     * Get all available variables for templates.
     */
    public static function getAvailableVariables(): array
    {
        return [
            'booking' => [
                'Customer' => [
                    'customer_name' => 'Full customer name',
                    'customer_first_name' => 'Customer first name',
                    'customer_last_name' => 'Customer last name',
                    'customer_email' => 'Customer email',
                    'customer_phone' => 'Customer phone',
                ],
                'Booking' => [
                    'booking_id' => 'Booking ID',
                    'booking_reference' => 'Booking reference number',
                    'booking_date' => 'Booking date (formatted)',
                    'booking_time' => 'Booking time',
                    'booking_status' => 'Booking status',
                    'booking_participants' => 'Number of participants',
                    'booking_total' => 'Total amount',
                    'booking_amount_paid' => 'Amount paid',
                    'booking_balance' => 'Remaining balance',
                    'booking_payment_status' => 'Payment status',
                    'booking_payment_method' => 'Payment method',
                    'booking_notes' => 'Booking notes',
                    'booking_created_at' => 'Booking creation date/time',
                ],
                'Package' => [
                    'package_name' => 'Package name',
                    'package_description' => 'Package description',
                    'package_duration' => 'Package duration',
                    'package_price' => 'Package price',
                    'package_min_participants' => 'Minimum participants',
                    'package_max_participants' => 'Maximum participants',
                ],
                'Room' => [
                    'room_name' => 'Room name',
                    'room_description' => 'Room description',
                ],
                'Add-ons' => [
                    'addons_list' => 'List of add-ons with prices',
                    'addons_total' => 'Total add-ons amount',
                ],
                'QR Code' => [
                    'qr_code' => 'QR code image HTML',
                    'qr_code_url' => 'QR code image URL',
                ],
            ],
            'purchase' => [
                'Customer' => [
                    'customer_name' => 'Full customer name',
                    'customer_first_name' => 'Customer first name',
                    'customer_last_name' => 'Customer last name',
                    'customer_email' => 'Customer email',
                    'customer_phone' => 'Customer phone',
                ],
                'Purchase' => [
                    'purchase_id' => 'Purchase ID',
                    'purchase_reference' => 'Purchase reference number',
                    'purchase_date' => 'Purchase date (formatted)',
                    'purchase_status' => 'Purchase status',
                    'purchase_quantity' => 'Quantity purchased',
                    'purchase_unit_price' => 'Unit price',
                    'purchase_total' => 'Total amount',
                    'purchase_amount_paid' => 'Amount paid',
                    'purchase_balance' => 'Remaining balance',
                    'purchase_payment_method' => 'Payment method',
                    'purchase_notes' => 'Purchase notes',
                    'purchase_created_at' => 'Purchase creation date/time',
                ],
                'Attraction' => [
                    'attraction_name' => 'Attraction name',
                    'attraction_description' => 'Attraction description',
                    'attraction_price' => 'Attraction price',
                    'attraction_duration' => 'Attraction duration',
                ],
                'QR Code' => [
                    'qr_code' => 'QR code image HTML',
                    'qr_code_url' => 'QR code image URL',
                ],
            ],
            'payment' => [
                'Payment' => [
                    'payment_id' => 'Payment ID',
                    'payment_amount' => 'Payment amount',
                    'payment_method' => 'Payment method',
                    'payment_status' => 'Payment status',
                    'payment_date' => 'Payment date/time',
                    'payment_transaction_id' => 'Transaction ID',
                    'payment_reference' => 'Related booking/purchase reference',
                    'customer_name' => 'Customer name',
                    'payable_type' => 'Payment type (Booking/Purchase)',
                    'payable_id' => 'Related booking/purchase ID',
                ],
            ],
            'common' => [
                'Location' => [
                    'location_name' => 'Location name',
                    'location_address' => 'Full location address',
                    'location_phone' => 'Location phone',
                    'location_email' => 'Location email',
                ],
                'Company' => [
                    'company_name' => 'Company name',
                    'company_email' => 'Company email',
                    'company_phone' => 'Company phone',
                    'company_address' => 'Company address',
                ],
                'Date/Time' => [
                    'current_date' => 'Current date (formatted)',
                    'current_time' => 'Current time',
                    'current_year' => 'Current year',
                ],
            ],
        ];
    }
}
