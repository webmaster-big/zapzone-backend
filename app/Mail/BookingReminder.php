<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingReminder extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public string $customerName;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;

        if ($booking->customer) {
            $this->customerName = trim($booking->customer->first_name . ' ' . $booking->customer->last_name);
        } else {
            $this->customerName = $booking->guest_name ?? 'Valued Customer';
        }
    }

    public function envelope(): Envelope
    {
        $companyName = $this->booking->location?->company?->name ?? 'ZapZone';

        return new Envelope(
            subject: "Reminder: Your Booking is Tomorrow - {$companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-reminder',
            with: [
                'booking' => $this->booking,
                'customerName' => $this->customerName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
