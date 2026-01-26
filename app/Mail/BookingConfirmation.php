<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\GlobalNote;
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
        // Get global notes for this package
        $globalNotes = [];
        if ($this->booking->package_id) {
            $globalNotes = GlobalNote::active()
                ->forPackage($this->booking->package_id)
                ->ordered()
                ->get();
        }

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
                'customerNotes' => $this->booking->package?->customer_notes,
                'globalNotes' => $globalNotes,
            ]);

        // Attach QR code if path is provided
        if ($this->qrCodePath && file_exists($this->qrCodePath)) {
            $this->attachData(file_get_contents($this->qrCodePath), 'booking-qrcode.png', [
                'mime' => 'image/png',
            ]);
        }

        // Attach invitation file if available
        if ($this->booking->package && $this->booking->package->invitation_file) {
            $invitationFile = $this->booking->package->invitation_file;
            
            // Check if it's a base64 encoded file or a file path
            if (str_starts_with($invitationFile, 'data:')) {
                // Base64 encoded file
                $fileData = explode(',', $invitationFile);
                if (count($fileData) === 2) {
                    $mimeType = explode(';', explode(':', $fileData[0])[1])[0];
                    $extension = explode('/', $mimeType)[1] ?? 'pdf';
                    $this->attachData(
                        base64_decode($fileData[1]),
                        'invitation.' . $extension,
                        ['mime' => $mimeType]
                    );
                }
            } elseif (str_starts_with($invitationFile, 'http://') || str_starts_with($invitationFile, 'https://')) {
                // URL - attach directly
                $this->attach($invitationFile, [
                    'as' => 'invitation.pdf',
                    'mime' => 'application/pdf'
                ]);
            } else {
                // File path in storage
                $storagePath = storage_path('app/public/' . $invitationFile);
                if (file_exists($storagePath)) {
                    $this->attach($storagePath, [
                        'as' => 'invitation.' . pathinfo($storagePath, PATHINFO_EXTENSION),
                    ]);
                }
            }
        }

        return $this;
    }
}
