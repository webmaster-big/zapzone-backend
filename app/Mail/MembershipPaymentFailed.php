<?php

namespace App\Mail;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipPaymentFailed extends Mailable
{
    use Queueable, SerializesModels;

    public Membership $membership;
    public ?string $failureReason;

    public function __construct(Membership $membership, ?string $failureReason = null)
    {
        $membership->loadMissing(['customer', 'plan']);
        $this->membership = $membership;
        $this->failureReason = $failureReason;
    }

    public function build()
    {
        return $this->subject('Action Required: Membership Payment Failed')
            ->view('emails.membership-payment-failed')
            ->with([
                'membership'    => $this->membership,
                'failureReason' => $this->failureReason,
            ]);
    }
}
