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

    /**
     * Create a new message instance.
     */
    public function __construct(ShareableToken $token)
    {
        $this->token = $token;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Registration Invitation - Zap Zone',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.shareable-token',
            with: [
                'link' => $this->token->getShareableLink(),
                'role' => $this->token->role,
                'email' => $this->token->email,
            ],
        );
    }
}
