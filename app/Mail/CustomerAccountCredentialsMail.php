<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerAccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public Customer $customer;
    public string $plainPassword;
    public ?string $loginUrl;
    public ?string $createdByName;

    public function __construct(Customer $customer, string $plainPassword, ?string $loginUrl = null, ?string $createdByName = null)
    {
        $this->customer = $customer;
        $this->plainPassword = $plainPassword;
        $this->loginUrl = $loginUrl ?: rtrim(config('app.frontend_url', config('app.url', '')), '/') . '/customer/login';
        $this->createdByName = $createdByName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Zap Zone Account & Membership Login',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-account-credentials',
            with: [
                'customer'      => $this->customer,
                'plainPassword' => $this->plainPassword,
                'loginUrl'      => $this->loginUrl,
                'createdByName' => $this->createdByName,
            ],
        );
    }
}
