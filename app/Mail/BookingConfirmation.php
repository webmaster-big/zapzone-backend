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
        $this->booking = $booking;
        $this->qrCodePath = $qrCodePath;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Confirmation - ' . $this->booking->reference_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // Generate a unique CID for the QR code
        $qrCodeCid = 'booking_qr_' . $this->booking->id . '_' . time();
        
        return new Content(
            view: 'emails.booking-confirmation',
            with: [
                'booking' => $this->booking,
                'customerName' => $this->booking->customer 
                    ? $this->booking->customer->first_name . ' ' . $this->booking->customer->last_name
                    : $this->booking->guest_name,
                'packageName' => $this->booking->package?->name ?? 'N/A',
                'locationName' => $this->booking->location?->name ?? 'N/A',
                'roomName' => $this->booking->room?->name ?? 'N/A',
                'qrCodeCid' => $qrCodeCid,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        $attachments = [];

        if ($this->qrCodePath && file_exists($this->qrCodePath)) {
            // Attach as downloadable file
            $attachments[] = Attachment::fromPath($this->qrCodePath)
                ->as('booking-qrcode.png')
                ->withMime('image/png');
            
            // Embed inline for viewing in email
            $qrCodeCid = 'booking_qr_' . $this->booking->id . '_' . time();
            $attachments[] = Attachment::fromPath($this->qrCodePath)
                ->as($qrCodeCid)
                ->withMime('image/png')
                ->inline();
        }

        return $attachments;
    }
}
