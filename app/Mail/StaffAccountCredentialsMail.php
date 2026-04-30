<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffAccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public string $plainPassword;
    public ?string $loginUrl;
    public ?string $createdByName;

    public function __construct(User $user, string $plainPassword, ?string $loginUrl = null, ?string $createdByName = null)
    {
        $this->user = $user;
        $this->plainPassword = $plainPassword;
        $this->loginUrl = $loginUrl ?: rtrim(config('app.frontend_url', config('app.url', '')), '/') . '/login';
        $this->createdByName = $createdByName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Zap Zone Staff Account',
        );
    }

    public function content(): Content
    {
        $user = $this->user->loadMissing(['company', 'location']);

        return new Content(
            view: 'emails.staff-account-credentials',
            with: [
                'user'          => $user,
                'plainPassword' => $this->plainPassword,
                'loginUrl'      => $this->loginUrl,
                'createdByName' => $this->createdByName,
                'company'       => $user->company,
                'location'      => $user->location,
            ],
        );
    }
}
