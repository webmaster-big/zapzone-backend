<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingInvitation;
use App\Models\Contact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvitationService
{
    protected GmailApiService $gmailService;
    protected ?SmsService $smsService;

    public function __construct()
    {
        $this->gmailService = new GmailApiService();
        $this->smsService = SmsService::isConfigured() ? new SmsService() : null;
    }

    /**
     * Send an invitation email and/or SMS for a booking invitation.
     */
    public function sendInvitation(BookingInvitation $invitation): array
    {
        $invitation->load('booking.customer', 'booking.package', 'booking.location');
        $booking = $invitation->booking;
        $results = ['email' => null, 'sms' => null];

        // Send email
        if (in_array($invitation->send_via, ['email', 'both']) && $invitation->guest_email) {
            try {
                $this->sendInvitationEmail($invitation, $booking);
                $invitation->update(['email_sent_at' => now()]);
                $results['email'] = 'sent';
                Log::info('Invitation email sent', [
                    'invitation_id' => $invitation->id,
                    'guest_email' => $invitation->guest_email,
                ]);
            } catch (\Exception $e) {
                $results['email'] = 'failed';
                Log::error('Failed to send invitation email', [
                    'invitation_id' => $invitation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send SMS
        if (in_array($invitation->send_via, ['text', 'both']) && $invitation->guest_phone) {
            if ($this->smsService) {
                try {
                    $this->sendInvitationSms($invitation, $booking);
                    $invitation->update(['sms_sent_at' => now()]);
                    $results['sms'] = 'sent';
                    Log::info('Invitation SMS sent', [
                        'invitation_id' => $invitation->id,
                        'guest_phone' => $invitation->guest_phone,
                    ]);
                } catch (\Exception $e) {
                    $results['sms'] = 'failed';
                    Log::error('Failed to send invitation SMS', [
                        'invitation_id' => $invitation->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $results['sms'] = 'not_configured';
                Log::warning('SMS service not configured, skipping SMS invitation', [
                    'invitation_id' => $invitation->id,
                ]);
            }
        }

        return $results;
    }

    /**
     * Send the invitation email.
     */
    protected function sendInvitationEmail(BookingInvitation $invitation, Booking $booking): void
    {
        $variables = $this->buildInvitationVariables($invitation, $booking);
        $subject = $this->buildEmailSubject($variables);
        $htmlBody = $this->buildEmailHtml($variables);
        $attachments = $this->getInvitationAttachments($booking);

        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            $this->gmailService->sendEmail(
                $invitation->guest_email,
                $subject,
                $htmlBody,
                $variables['company_name'] ?: 'Zap Zone',
                $attachments
            );
        } else {
            \Illuminate\Support\Facades\Mail::html($htmlBody, function ($message) use ($invitation, $subject, $variables, $attachments) {
                $message->to($invitation->guest_email)
                    ->subject($subject)
                    ->from(config('mail.from.address'), $variables['company_name'] ?: config('mail.from.name'));

                foreach ($attachments as $attachment) {
                    $message->attachData(
                        base64_decode($attachment['data']),
                        $attachment['filename'],
                        ['mime' => $attachment['mime_type']]
                    );
                }
            });
        }
    }

    /**
     * Send the invitation SMS.
     */
    protected function sendInvitationSms(BookingInvitation $invitation, Booking $booking): void
    {
        $hostName = $booking->customer
            ? trim($booking->customer->first_name . ' ' . $booking->customer->last_name)
            : ($booking->guest_name ?? 'Your host');

        $packageName = $booking->package?->name ?? 'party';
        $bookingDate = $booking->booking_date?->format('F j, Y') ?? '';
        $bookingTime = $booking->booking_time ?? '';
        $locationName = $booking->location?->name ?? '';
        $rsvpUrl = $invitation->getRsvpUrl();

        $guestFirst = explode(' ', trim($invitation->guest_name ?? ''))[0] ?? 'Hi';

        $message = "{$guestFirst}, you're invited to {$hostName}'s {$packageName} at {$locationName} on {$bookingDate} at {$bookingTime}! RSVP here: {$rsvpUrl}";

        // Truncate if over 320 chars (2 SMS segments)
        if (strlen($message) > 320) {
            $message = "{$guestFirst}, you're invited to a party at {$locationName} on {$bookingDate}! RSVP: {$rsvpUrl}";
        }

        $this->smsService->sendSms($invitation->guest_phone, $message);
    }

    /**
     * Build template variables for invitation email.
     */
    protected function buildInvitationVariables(BookingInvitation $invitation, Booking $booking): array
    {
        $customer = $booking->customer;
        $package = $booking->package;
        $location = $booking->location;
        $company = $location?->company;

        $locationAddress = $location
            ? trim(implode(', ', array_filter([
                $location->address,
                $location->city,
                $location->state,
                $location->zip_code,
            ])))
            : '';

        return [
            // Host info
            'host_name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : ($booking->guest_name ?? 'Your Host'),
            'host_first_name' => $customer?->first_name ?? explode(' ', $booking->guest_name ?? 'Your Host')[0],

            // Guest info
            'guest_name' => $invitation->guest_name ?? 'Guest',
            'guest_first_name' => explode(' ', trim($invitation->guest_name ?? 'Guest'))[0],
            'guest_email' => $invitation->guest_email ?? '',

            // Party / Booking info
            'package_name' => $package?->name ?? 'Party',
            'booking_date' => $booking->booking_date?->format('F j, Y') ?? '',
            'booking_time' => $booking->booking_time ?? '',
            'booking_participants' => (string) ($booking->participants ?? 0),
            'guest_of_honor_name' => $booking->guest_of_honor_name ?? '',
            'guest_of_honor_age' => $booking->guest_of_honor_age ?? '',

            // Location info
            'location_name' => $location?->name ?? '',
            'location_address' => $locationAddress,
            'location_phone' => $location?->phone ?? '',
            'location_email' => $location?->email ?? '',

            // Company info
            'company_name' => $company?->company_name ?? '',

            // RSVP link
            'rsvp_link' => $invitation->getRsvpUrl(),
            'rsvp_url' => $invitation->getRsvpUrl(),

            // Date/time
            'current_date' => now()->format('F j, Y'),
            'current_year' => (string) now()->year,
        ];
    }

    /**
     * Build email subject.
     */
    protected function buildEmailSubject(array $variables): string
    {
        $hostName = $variables['host_first_name'] ?? 'Someone';
        $packageName = $variables['package_name'] ?? 'Party';

        return "You're Invited to {$hostName}'s {$packageName}!";
    }

    /**
     * Build the invitation email HTML body.
     */
    protected function buildEmailHtml(array $variables): string
    {
        $guestName = htmlspecialchars($variables['guest_first_name']);
        $hostName = htmlspecialchars($variables['host_name']);
        $packageName = htmlspecialchars($variables['package_name']);
        $bookingDate = htmlspecialchars($variables['booking_date']);
        $bookingTime = htmlspecialchars($variables['booking_time']);
        $locationName = htmlspecialchars($variables['location_name']);
        $locationAddress = htmlspecialchars($variables['location_address']);
        $locationPhone = htmlspecialchars($variables['location_phone']);
        $rsvpUrl = htmlspecialchars($variables['rsvp_url']);
        $companyName = htmlspecialchars($variables['company_name'] ?: 'Zap Zone');
        $currentYear = $variables['current_year'];
        $guestOfHonor = htmlspecialchars($variables['guest_of_honor_name']);
        $guestOfHonorAge = htmlspecialchars($variables['guest_of_honor_age']);

        $gohSection = '';
        if ($guestOfHonor) {
            $ageText = $guestOfHonorAge ? " is turning {$guestOfHonorAge} and" : '';
            $gohSection = "<p style=\"margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;\"><strong>{$guestOfHonor}</strong>{$ageText} would love for you to celebrate with them!</p>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Party Invitation</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #374151; background-color: #f9fafb;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="520" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align: center; background-color: #1e40af; color: #ffffff; padding: 24px 32px; border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0 0 8px 0; padding: 0; font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: #ffffff;">You're Invited! 🎉</h1>
                            <p style="margin: 0; padding: 0; font-size: 14px; opacity: 0.9; color: #ffffff;">{$hostName} has invited you to a party</p>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="background-color: #ffffff; padding: 32px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; border-top: none;">
                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">Hi {$guestName},</p>

                            <p style="margin: 0 0 16px 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">You're invited to join <strong>{$hostName}</strong> for a <strong>{$packageName}</strong>!</p>

                            {$gohSection}

                            <!-- Party Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <h3 style="margin: 0 0 12px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Party Details</h3>
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280; width: 100px;">Event</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{$packageName}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280;">Date</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{$bookingDate}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 4px 0; font-size: 14px; color: #6b7280;">Time</td>
                                                <td style="padding: 4px 0; font-size: 14px; color: #111827; font-weight: 500;">{$bookingTime}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- Location -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #eff6ff; border-radius: 6px; border: 1px solid #dbeafe; margin: 20px 0;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <h3 style="margin: 0 0 8px 0; padding: 0; font-size: 16px; font-weight: 600; color: #111827;">Location</h3>
                                        <p style="margin: 0 0 4px 0; font-size: 14px; font-weight: 500; color: #1e40af;">{$locationName}</p>
                                        <p style="margin: 0 0 4px 0; font-size: 14px; color: #4b5563;">{$locationAddress}</p>
                                        <p style="margin: 0; font-size: 14px; color: #4b5563;">{$locationPhone}</p>
                                    </td>
                                </tr>
                            </table>

                            <!-- RSVP Button -->
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 28px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{$rsvpUrl}" style="display: inline-block; background-color: #1e40af; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 6px; font-size: 16px; font-weight: 600;">
                                            RSVP Now
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 8px 0; padding: 0; font-size: 12px; line-height: 1.6; color: #9ca3af; text-align: center;">
                                Or copy this link: {$rsvpUrl}
                            </p>

                            <p style="margin: 24px 0 0 0; padding: 0; font-size: 14px; line-height: 1.6; color: #4b5563;">We hope to see you there!</p>

                            <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0; padding: 0; font-size: 12px; color: #9ca3af; text-align: center;">
                                &copy; {$currentYear} {$companyName}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Get invitation file attachments from the booking's package.
     */
    protected function getInvitationAttachments(Booking $booking): array
    {
        $attachments = [];
        $package = $booking->package;

        if (!$package || !$package->invitation_file) {
            return $attachments;
        }

        $invitationFile = $package->invitation_file;

        try {
            // Handle base64 data URI
            if (str_starts_with($invitationFile, 'data:')) {
                $parts = explode(',', $invitationFile, 2);
                $meta = $parts[0]; // e.g. data:application/pdf;base64
                $data = $parts[1] ?? '';

                preg_match('/data:([^;]+)/', $meta, $matches);
                $mimeType = $matches[1] ?? 'application/pdf';
                $extension = $this->mimeToExtension($mimeType);

                $attachments[] = [
                    'mime_type' => $mimeType,
                    'filename' => 'party-invitation.' . $extension,
                    'data' => $data,
                ];
            }
            // Handle URL
            elseif (str_starts_with($invitationFile, 'http://') || str_starts_with($invitationFile, 'https://')) {
                $fileContent = file_get_contents($invitationFile);
                if ($fileContent !== false) {
                    $extension = pathinfo(parse_url($invitationFile, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'pdf';
                    $mimeType = $this->extensionToMime($extension);

                    $attachments[] = [
                        'mime_type' => $mimeType,
                        'filename' => 'party-invitation.' . $extension,
                        'data' => base64_encode($fileContent),
                    ];
                }
            }
            // Handle storage path
            elseif (Storage::exists($invitationFile)) {
                $fileContent = Storage::get($invitationFile);
                $extension = pathinfo($invitationFile, PATHINFO_EXTENSION) ?: 'pdf';
                $mimeType = Storage::mimeType($invitationFile) ?: $this->extensionToMime($extension);

                $attachments[] = [
                    'mime_type' => $mimeType,
                    'filename' => 'party-invitation.' . $extension,
                    'data' => base64_encode($fileContent),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load invitation attachment', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $attachments;
    }

    /**
     * Create a marketing contact from RSVP data if opted in.
     */
    public function createContactFromRsvp(BookingInvitation $invitation): void
    {
        if (!$invitation->marketing_opt_in) {
            return;
        }

        $invitation->load('booking.location');
        $booking = $invitation->booking;
        $location = $booking?->location;
        $companyId = $location?->company_id;

        if (!$companyId) {
            Log::warning('Cannot create RSVP contact - no company found', [
                'invitation_id' => $invitation->id,
            ]);
            return;
        }

        $email = $invitation->rsvp_email ?? $invitation->guest_email;
        if (!$email) {
            return;
        }

        $nameParts = explode(' ', trim($invitation->rsvp_full_name ?? $invitation->guest_name ?? ''), 2);

        try {
            Contact::createOrUpdateFromSource(
                $companyId,
                [
                    'email' => $email,
                    'first_name' => $nameParts[0] ?? null,
                    'last_name' => $nameParts[1] ?? null,
                    'phone' => $invitation->rsvp_phone ?? $invitation->guest_phone ?? null,
                ],
                'rsvp',
                ['rsvp', 'party-guest', 'marketing-opt-in'],
                $location->id ?? null,
                null
            );

            Log::info('Contact created/updated from RSVP', [
                'invitation_id' => $invitation->id,
                'email' => $email,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create contact from RSVP', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * MIME type to file extension helper.
     */
    protected function mimeToExtension(string $mime): string
    {
        $map = [
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $map[$mime] ?? 'pdf';
    }

    /**
     * File extension to MIME type helper.
     */
    protected function extensionToMime(string $ext): string
    {
        $map = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }
}
