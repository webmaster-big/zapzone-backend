<?php

namespace App\Mail;

use App\Support\TemplatePlaceholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    protected string $emailSubject;
    protected string $emailBody;
    protected array $variables;

    public function __construct(string $subject, string $body, array $variables = [])
    {
        $this->emailSubject = $subject;
        $this->emailBody = $body;
        $this->variables = $variables;
    }

    public function build()
    {
        $processedSubject = $this->replaceVariables($this->emailSubject);
        $processedBody = $this->replaceVariables($this->emailBody, true);

        return $this->subject($processedSubject)
            ->view('emails.dynamic-campaign')
            ->with([
                'emailBody' => $processedBody,
                'variables' => $this->variables,
            ]);
    }

    protected function replaceVariables(string $content, bool $escape = false): string
    {
        return TemplatePlaceholders::fill($content, $this->variables, $escape);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->replaceVariables($this->emailSubject),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dynamic-campaign',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
