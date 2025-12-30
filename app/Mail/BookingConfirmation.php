<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public ?string $qrCodePath;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, ?string $qrCodePath = null)
    {
        // Ensure location.company relationship is loaded for email template
        $booking->loadMissing(['location.company', 'customer', 'package', 'room']);
        $this->booking = $booking;
        $this->qrCodePath = $qrCodePath;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $this->subject('Booking Confirmation - Order #' . $this->booking->id)
            ->view('emails.booking-confirmation')
            ->with([
                'booking' => $this->booking,
                'customerName' => $this->booking->customer
                    ? $this->booking->customer->first_name . ' ' . $this->booking->customer->last_name
                    : $this->booking->guest_name,
                'packageName' => $this->booking->package?->name ?? 'N/A',
                'locationName' => $this->booking->location?->name ?? 'N/A',
                'roomName' => $this->booking->room?->name ?? 'N/A',
            ]);

        // Attach QR code if path is provided
        if ($this->qrCodePath && file_exists($this->qrCodePath)) {
            $this->attachData(file_get_contents($this->qrCodePath), 'booking-qrcode.png', [
                'mime' => 'image/png',
            ]);
        }

        return $this;
    }
}
