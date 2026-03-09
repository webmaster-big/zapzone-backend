<?php

namespace App\Mail;

use App\Models\EventPurchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EventPurchaseConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $purchase;

    /**
     * Create a new message instance.
     */
    public function __construct(EventPurchase $purchase)
    {
        $purchase->loadMissing(['event.location.company', 'customer', 'addOns']);
        $this->purchase = $purchase;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $this->subject('Event Purchase Confirmation - ' . $this->purchase->reference_number)
            ->view('emails.event-purchase-confirmation')
            ->with([
                'purchase' => $this->purchase,
            ]);

        return $this;
    }
}
