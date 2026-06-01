<?php

namespace App\Mail;

use App\Models\ShareableToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShareableTokenMail extends Mailable
{
    use Queueable, SerializesModels;

    public ShareableToken $token;

    public function __construct(ShareableToken $token)
    {
        $this->token = $token;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Registration Invitation - Zap Zone',
        );
    }

    public function content(): Content
    {
        $company = null;
        if ($this->token->company_id) {
            $company = \App\Models\Company::find($this->token->company_id);
        }

        return new Content(
            view: 'emails.shareable-token',
            with: [
                'link' => $this->token->getShareableLink(),
                'role' => $this->token->role,
                'email' => $this->token->email,
                'company' => $company,
            ],
        );
    }
}
