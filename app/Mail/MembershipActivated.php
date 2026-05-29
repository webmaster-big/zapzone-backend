<?php

namespace App\Mail;

use App\Models\Membership;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipActivated extends Mailable
{
    use Queueable, SerializesModels;

    public Membership $membership;
    public ?string $qrCodeBase64;

    public function __construct(Membership $membership, ?string $qrCodeBase64 = null)
    {
        $membership->loadMissing(['customer', 'plan', 'homeLocation.company']);
        $this->membership = $membership;
        $this->qrCodeBase64 = $qrCodeBase64;
    }

    public function build()
    {
        $planName = $this->membership->plan?->name ?? 'Membership';

        $this->subject("Welcome to {$planName} - Your Membership is Active")
            ->view('emails.membership-activated')
            ->with([
                'membership'   => $this->membership,
                'qrCodeBase64' => $this->qrCodeBase64,
            ]);

        if ($this->qrCodeBase64) {
            $img = base64_decode($this->qrCodeBase64);
            if ($img !== false) {
                $this->attachData($img, 'membership-qr.png', ['mime' => 'image/png']);
            }
        }

        return $this;
    }
}
