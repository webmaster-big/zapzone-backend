<?php

namespace App\Services;

use Google\Client;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Illuminate\Support\Facades\Log;

class GmailApiService
{
    protected $client;
    protected $service;

    public function __construct()
    {
        $this->client = new Client();

        // Check if credentials are in config (from environment variables - recommended for production)
        if (config('gmail.credentials.client_email')) {
            Log::info('Using Gmail API credentials from config/environment variables');

            $credentials = [
                'type' => 'service_account',
                'project_id' => config('gmail.credentials.project_id'),
                'private_key_id' => config('gmail.credentials.private_key_id'),
                'private_key' => str_replace('\\n', "\n", config('gmail.credentials.private_key')),
                'client_email' => config('gmail.credentials.client_email'),
                'client_id' => config('gmail.credentials.client_id'),
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => config('gmail.credentials.client_cert_url'),
                'universe_domain' => 'googleapis.com'
            ];

            $this->client->setAuthConfig($credentials);
            $this->client->addScope(Gmail::GMAIL_SEND);
            $this->client->setSubject(config('gmail.sender_email', 'webmaster@bestingames.com'));
        }
        // Fallback to JSON file if config not set
        else {
            $credentialsPath = config('gmail.credentials_path', storage_path('app/gmail.json'));

            if (!file_exists($credentialsPath)) {
                Log::error('Gmail credentials not found', [
                    'path' => $credentialsPath,
                    'config_client_email' => config('gmail.credentials.client_email'),
                    'message' => 'Set GMAIL_CLIENT_EMAIL and related env vars, or provide gmail.json file'
                ]);
                throw new \Exception("Gmail credentials not configured. Set GMAIL_CLIENT_EMAIL in .env or provide gmail.json file at: {$credentialsPath}");
            }

            Log::info('Using Gmail API credentials from file', ['path' => $credentialsPath]);

            $this->client->setAuthConfig($credentialsPath);
            $this->client->addScope(Gmail::GMAIL_SEND);
            $this->client->setSubject(config('gmail.sender_email', 'webmaster@bestingames.com'));
        }

        $this->service = new Gmail($this->client);
    }

    public function sendEmail($to, $subject, $htmlBody, $fromName = 'Zap Zone', $attachments = [])
    {
        try {
            $message = $this->createMessage(
                'webmaster@bestingames.com',
                $to,
                $subject,
                $htmlBody,
                $fromName,
                $attachments
            );

            $result = $this->service->users_messages->send('me', $message);

            Log::info('Gmail API email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'message_id' => $result->getId(),
                'attachments_count' => count($attachments)
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Gmail API error: ' . $e->getMessage(), [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function createMessage($from, $to, $subject, $htmlBody, $fromName = 'Zap Zone', $attachments = [])
    {
        // Create multipart message with proper encoding
        $boundary = uniqid('boundary_');
        $attachmentBoundary = uniqid('attachment_boundary_');

        $emailContent = "From: {$fromName} <{$from}>\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Reply-To: {$from}\r\n";
        $emailContent .= "Subject: {$subject}\r\n";
        $emailContent .= "MIME-Version: 1.0\r\n";

        // Use mixed if we have attachments, otherwise use alternative
        if (!empty($attachments)) {
            $emailContent .= "Content-Type: multipart/mixed; boundary=\"{$attachmentBoundary}\"\r\n\r\n";

            // Start with the HTML/text content part
            $emailContent .= "--{$attachmentBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        } else {
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        }

        // Plain text version
        $plainText = strip_tags($htmlBody);
        $emailContent .= "--{$boundary}\r\n";
        $emailContent .= "Content-Type: text/plain; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $plainText . "\r\n\r\n";

        // HTML version
        $emailContent .= "--{$boundary}\r\n";
        $emailContent .= "Content-Type: text/html; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $htmlBody . "\r\n\r\n";

        $emailContent .= "--{$boundary}--\r\n";

        // Add attachments if any
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $emailContent .= "\r\n--{$attachmentBoundary}\r\n";
                $emailContent .= "Content-Type: {$attachment['mime_type']}; name=\"{$attachment['filename']}\"\r\n";
                $emailContent .= "Content-Transfer-Encoding: base64\r\n";
                $emailContent .= "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n";
                $emailContent .= chunk_split($attachment['data']) . "\r\n";
            }
            $emailContent .= "--{$attachmentBoundary}--";
        }

        $message = new Message();
        $message->setRaw($this->base64UrlEncode($emailContent));

        return $message;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
