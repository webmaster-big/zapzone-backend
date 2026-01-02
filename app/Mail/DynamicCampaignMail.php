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

    /**
     * Create a new message instance.
     */
    public function __construct(string $subject, string $body, array $variables = [])
    {
        $this->emailSubject = $subject;
        $this->emailBody = $body;
        $this->variables = $variables;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        // Replace variables in subject and body
        $processedSubject = $this->replaceVariables($this->emailSubject);
        $processedBody = $this->replaceVariables($this->emailBody);

        return $this->subject($processedSubject)
            ->view('emails.dynamic-campaign')
            ->with([
                'emailBody' => $processedBody,
                'variables' => $this->variables,
            ]);
    }

    /**
     * Replace template variables with actual values
     */
    protected function replaceVariables(string $content): string
    {
        foreach ($this->variables as $key => $value) {
            // Support both {{ variable }} and {{variable}} formats
            $content = preg_replace(
                '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/',
                $value ?? '',
                $content
            );
        }

        return $content;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->replaceVariables($this->emailSubject),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.dynamic-campaign',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
