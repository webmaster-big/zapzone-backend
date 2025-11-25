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
    public $qrCodePath;

    /**
     * Create a new message instance.
     */
    public function __construct(AttractionPurchase $purchase, ?string $qrCodePath = null)
    {
        $this->purchase = $purchase;
        $this->qrCodePath = $qrCodePath;
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
                'qrCodePath' => $this->qrCodePath,
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

        // Attach QR code if path is provided
        if ($this->qrCodePath && file_exists($this->qrCodePath)) {
            $attachments[] = Attachment::fromPath($this->qrCodePath)
                ->as('ticket-qrcode.png')
                ->withMime('image/png');
        }

        return $attachments;
    }
}
