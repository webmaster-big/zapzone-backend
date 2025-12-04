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
        $this->purchase = $purchase;
        $this->qrCodeBase64 = $qrCodeBase64;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Attraction Purchase Receipt - Order #' . $this->purchase->id,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.attraction-purchase-receipt',
            with: [
                'purchase' => $this->purchase,
                'qrCodeBase64' => $this->qrCodeBase64,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        // Attach QR code if base64 data is provided
        if ($this->qrCodeBase64) {
            $qrCodeImage = base64_decode($this->qrCodeBase64);
            if ($qrCodeImage !== false) {
                $attachments[] = Attachment::fromData(fn () => $qrCodeImage, 'ticket-qrcode.png')
                    ->withMime('image/png');
            }
        }

        return $attachments;
    }
}
