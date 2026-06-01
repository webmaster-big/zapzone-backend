<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicCampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $emailSubject;
    public string $emailBody;
    public array $variables;

    public function __construct(string $subject, string $body, array $variables = [])
    {
        $this->emailSubject = $subject;
        $this->emailBody = $body;
        $this->variables = $variables;
    }

    public function build()
    {
        $processedSubject = $this->replaceVariables($this->emailSubject);
        $processedBody = $this->replaceVariables($this->emailBody);

        return $this->subject($processedSubject)
            ->view('emails.dynamic-campaign')
            ->with([
                'emailBody' => $processedBody,
                'variables' => $this->variables,
            ]);
    }

    protected function replaceVariables(string $content): string
    {
        foreach ($this->variables as $key => $value) {
            $content = preg_replace(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                $value ?? '',
                $content
            );
        }

        return $content;
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
