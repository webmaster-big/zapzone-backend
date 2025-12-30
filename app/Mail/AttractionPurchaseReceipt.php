<?php

namespace App\Mail;

use App\Models\AttractionPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class AttractionPurchaseReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public $purchase;
    public $qrCodeBase64;

    /**
     * Create a new message instance.
     */
    public function __construct(AttractionPurchase $purchase, ?string $qrCodeBase64 = null)
    {
        // Ensure attraction.location.company relationship is loaded for email template
        $purchase->loadMissing(['attraction.location.company', 'customer']);
        $this->purchase = $purchase;
        $this->qrCodeBase64 = $qrCodeBase64;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $this->subject('Your Attraction Purchase Receipt - Order #' . $this->purchase->id)
            ->view('emails.attraction-purchase-receipt')
            ->with([
                'purchase' => $this->purchase,
                'qrCodeBase64' => $this->qrCodeBase64,
            ]);

        // Attach QR code if base64 data is provided
        if ($this->qrCodeBase64) {
            $qrCodeImage = base64_decode($this->qrCodeBase64);
            if ($qrCodeImage !== false) {
                $this->attachData($qrCodeImage, 'ticket-qrcode.png', [
                    'mime' => 'image/png',
                ]);
            }
        }

        return $this;
    }
}
