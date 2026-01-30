<?php

namespace App\Console\Commands;

use App\Mail\BookingReminder;
use App\Models\Booking;
use App\Services\GmailApiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bookings:send-reminders';

    /**
     * The console command description.
     */
    protected $description = 'Send booking reminder emails for bookings scheduled for tomorrow';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::now()->toDateString();
        $tomorrow = Carbon::tomorrow()->toDateString();

        Log::info('Running booking reminder command', [
            'current_date' => $today,
            'tomorrow_date' => $tomorrow,
        ]);

        $this->info("Checking for bookings on {$tomorrow}...");

        // Get all bookings for tomorrow that haven't been reminded yet
        $bookingsToRemind = Booking::with(['customer', 'package', 'location', 'location.company', 'room'])
            ->where('booking_date', $tomorrow)
            ->where('reminder_sent', false)
            ->whereIn('status', ['confirmed', 'pending'])
            ->get();

        $this->info("Found {$bookingsToRemind->count()} bookings to remind.");

        if ($bookingsToRemind->isEmpty()) {
            Log::info('No bookings require reminders at this time');
            $this->info('No bookings require reminders.');
            return Command::SUCCESS;
        }

        $sentCount = 0;
        $failedCount = 0;

        foreach ($bookingsToRemind as $booking) {
            $recipientEmail = $booking->customer?->email ?? $booking->guest_email;

            if (!$recipientEmail) {
                Log::warning('No email address for booking reminder', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                ]);
                $this->warn("Skipping booking {$booking->reference_number} - no email address.");
                continue;
            }

            try {
                $customerName = $booking->customer
                    ? "{$booking->customer->first_name} {$booking->customer->last_name}"
                    : $booking->guest_name;

                // Check if Gmail API should be used
                $useGmailApi = config('gmail.enabled', false) &&
                              $booking->location &&
                              $booking->location->company_id;

                if ($useGmailApi) {
                    $gmailService = new GmailApiService($booking->location->company_id);

                    $emailBody = view('emails.booking-reminder', [
                        'booking' => $booking,
                        'customerName' => $customerName,
                    ])->render();

                    $gmailService->sendEmail(
                        $recipientEmail,
                        "Reminder: Your Booking Tomorrow - {$booking->reference_number}",
                        $emailBody
                    );
                } else {
                    Mail::to($recipientEmail)->send(new BookingReminder($booking));
                }

                // Mark reminder as sent
                $booking->update(['reminder_sent' => true]);

                Log::info('Booking reminder sent successfully', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'recipient' => $recipientEmail,
                    'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
                ]);

                $this->info("✓ Sent reminder for {$booking->reference_number} to {$recipientEmail}");
                $sentCount++;

            } catch (\Exception $e) {
                Log::error('Failed to send booking reminder', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'error' => $e->getMessage(),
                ]);
                $this->error("✗ Failed to send reminder for {$booking->reference_number}: {$e->getMessage()}");
                $failedCount++;
            }
        }

        $this->info("Completed: {$sentCount} sent, {$failedCount} failed.");

        Log::info('Booking reminder command completed', [
            'sent' => $sentCount,
            'failed' => $failedCount,
        ]);

        return Command::SUCCESS;
    }
}
