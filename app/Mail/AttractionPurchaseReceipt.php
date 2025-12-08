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
    public string $qrCodeCid;

    /**
     * Create a new message instance.
     */
    public function __construct(AttractionPurchase $purchase, ?string $qrCodeBase64 = null)
    {
        $this->purchase = $purchase;
        $this->qrCodeBase64 = $qrCodeBase64;
        // Generate a unique CID for the QR code
        $this->qrCodeCid = 'purchase_qr_' . $purchase->id . '_' . time();
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
                'qrCodeCid' => $this->qrCodeCid,
            ]);

        // Attach QR code if base64 data is provided
        if ($this->qrCodeBase64) {
            $qrCodeImage = base64_decode($this->qrCodeBase64);
            if ($qrCodeImage !== false) {
                // Attach as downloadable file
                $this->attachData($qrCodeImage, 'ticket-qrcode.png', [
                    'mime' => 'image/png',
                ]);
                
                // Embed inline for viewing in email using rawAttachData
                $this->withSwiftMessage(function ($message) use ($qrCodeImage) {
                    $attachment = \Swift_Attachment::newInstance($qrCodeImage, $this->qrCodeCid, 'image/png')
                        ->setDisposition('inline')
                        ->setId($this->qrCodeCid . '@swift.generated');
                    $message->attach($attachment);
                });
            }
        }

        return $this;
    }
}
