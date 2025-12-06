<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmation;
use Illuminate\Support\Facades\Mail;
// ... rest of the imports stay the same

/**
 * ALTERNATIVE EMAIL SENDING - Using Laravel Mail instead of Gmail API
 * 
 * Replace the email sending section in storeQrCode() with this simpler version:
 */

// In the storeQrCode method, find this section:
/*
                // Send booking confirmation using Gmail API
                $gmailService = new GmailApiService();
                $mailable = new BookingConfirmation($booking, $emailQrPath);
                
                Log::info('Rendering email body', [
                    'booking_id' => $booking->id,
                ]);
                
                $emailBody = $mailable->render();

                // Prepare QR code attachment
                $attachments = [[
                    'data' => $qrCodeBase64,
                    'filename' => 'booking-qrcode.png',
                    'mime_type' => 'image/png'
                ]];

                Log::info('Sending email via Gmail API', [
                    'booking_id' => $booking->id,
                    'recipient' => $recipientEmail,
                    'subject' => 'Your Booking Confirmation - Zap Zone',
                    'has_attachments' => count($attachments) > 0,
                ]);

                $gmailService->sendEmail(
                    $recipientEmail,
                    'Your Booking Confirmation - Zap Zone',
                    $emailBody,
                    'Zap Zone',
                    $attachments
                );
*/

// And replace with this:
/*
                Log::info('Sending email via Laravel Mail', [
                    'booking_id' => $booking->id,
                    'recipient' => $recipientEmail,
                    'subject' => 'Your Booking Confirmation - Zap Zone',
                ]);

                Mail::to($recipientEmail)->send(new BookingConfirmation($booking, $emailQrPath));
*/

/**
 * PRODUCTION .env CONFIGURATION
 * 
 * Add these to your production .env file:
 * 
 * MAIL_MAILER=smtp
 * MAIL_HOST=smtp.gmail.com
 * MAIL_PORT=587
 * MAIL_USERNAME=webmaster@bestingames.com
 * MAIL_PASSWORD=your_gmail_app_password_here
 * MAIL_ENCRYPTION=tls
 * MAIL_FROM_ADDRESS=webmaster@bestingames.com
 * MAIL_FROM_NAME="Zap Zone"
 * 
 * To get Gmail App Password:
 * 1. Go to Google Account > Security
 * 2. Enable 2-Step Verification
 * 3. Go to App Passwords
 * 4. Generate password for "Mail"
 * 5. Use that 16-character password in MAIL_PASSWORD
 */
