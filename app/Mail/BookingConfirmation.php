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
    public string $qrCodeCid;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, ?string $qrCodePath = null)
    {
        $this->booking = $booking;
        $this->qrCodePath = $qrCodePath;
        // Generate a unique CID for the QR code
        $this->qrCodeCid = 'booking_qr_' . $booking->id . '_' . time();
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
                'qrCodeCid' => $this->qrCodeCid,
            ]);

        // Attach and embed QR code if path is provided
        if ($this->qrCodePath && file_exists($this->qrCodePath)) {
            $qrCodeImage = file_get_contents($this->qrCodePath);
            
            // Attach as downloadable file
            $this->attachData($qrCodeImage, 'booking-qrcode.png', [
                'mime' => 'image/png',
            ]);
            
            // Embed inline for viewing in email using Swift_Attachment
            $this->withSwiftMessage(function ($message) use ($qrCodeImage) {
                $attachment = \Swift_Image::newInstance($qrCodeImage, $this->qrCodeCid, 'image/png');
                $message->embed($attachment);
            });
        }

        return $this;
    }
}
