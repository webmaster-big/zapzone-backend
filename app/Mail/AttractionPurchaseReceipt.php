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

    public function __construct(AttractionPurchase $purchase, ?string $qrCodeBase64 = null)
    {
        $purchase->loadMissing(['attraction.location.company', 'customer']);
        $this->purchase = $purchase;
        $this->qrCodeBase64 = $qrCodeBase64;
    }

    public function build()
    {
        $this->subject('Your Attraction Purchase Receipt - Order #' . $this->purchase->id)
            ->view('emails.attraction-purchase-receipt')
            ->with([
                'purchase' => $this->purchase,
                'qrCodeBase64' => $this->qrCodeBase64,
            ]);

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
