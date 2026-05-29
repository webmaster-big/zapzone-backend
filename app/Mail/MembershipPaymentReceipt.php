<?php

namespace App\Mail;

use App\Models\Membership;
use App\Models\MembershipPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipPaymentReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public Membership $membership;
    public MembershipPayment $payment;

    public function __construct(Membership $membership, MembershipPayment $payment)
    {
        $membership->loadMissing(['customer', 'plan', 'homeLocation.company']);
        $this->membership = $membership;
        $this->payment = $payment;
    }

    public function build()
    {
        $planName = $this->membership->plan?->name ?? 'Membership';

        return $this->subject("Payment Receipt - {$planName}")
            ->view('emails.membership-payment-receipt')
            ->with([
                'membership' => $this->membership,
                'payment'    => $this->payment,
            ]);
    }
}
