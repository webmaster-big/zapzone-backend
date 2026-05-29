<?php

namespace App\Mail;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipCanceled extends Mailable
{
    use Queueable, SerializesModels;

    public Membership $membership;
    public string $mode;

    public function __construct(Membership $membership, string $mode = 'end_of_term')
    {
        $membership->loadMissing(['customer', 'plan']);
        $this->membership = $membership;
        $this->mode = $mode;
    }

    public function build()
    {
        return $this->subject('Membership Canceled')
            ->view('emails.membership-canceled')
            ->with([
                'membership' => $this->membership,
                'mode'       => $this->mode,
            ]);
    }
}
