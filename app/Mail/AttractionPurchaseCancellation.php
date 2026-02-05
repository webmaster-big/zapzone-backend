<?php

namespace App\Mail;

use App\Models\AttractionPurchase;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AttractionPurchaseCancellation extends Mailable
{
    use Queueable, SerializesModels;

    public AttractionPurchase $purchase;
    public Payment $payment;
    public float $refundAmount;
    public string $type; // 'refund' or 'void'

    /**
     * Create a new message instance.
     */
    public function __construct(AttractionPurchase $purchase, Payment $payment, float $refundAmount, string $type = 'refund')
    {
        $purchase->loadMissing(['attraction.location.company', 'customer']);
        $this->purchase = $purchase;
        $this->payment = $payment;
        $this->refundAmount = $refundAmount;
        $this->type = $type;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = $this->type === 'void'
            ? 'Purchase Cancelled - Order #' . $this->purchase->id
            : 'Purchase Cancelled & Refund Processed - Order #' . $this->purchase->id;

        return $this->subject($subject)
            ->view('emails.attraction-purchase-cancellation')
            ->with([
                'purchase' => $this->purchase,
                'payment' => $this->payment,
                'refundAmount' => $this->refundAmount,
                'type' => $this->type,
                'customerName' => $this->purchase->customer
                    ? $this->purchase->customer->first_name . ' ' . $this->purchase->customer->last_name
                    : $this->purchase->guest_name ?? 'Guest',
                'attractionName' => $this->purchase->attraction?->name ?? 'N/A',
                'locationName' => $this->purchase->attraction?->location?->name ?? 'N/A',
            ]);
    }
}
