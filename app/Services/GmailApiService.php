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
        // Use environment variable or fallback to default path
        // For Forge: Set GMAIL_CREDENTIALS_PATH in .env to /home/forge/site.com/storage/app/gmail.json
        $credentialsPath = env('GMAIL_CREDENTIALS_PATH', storage_path('app/gmail.json'));
        
        if (!file_exists($credentialsPath)) {
            Log::error('Gmail credentials file not found', [
                'path' => $credentialsPath,
                'env_path' => env('GMAIL_CREDENTIALS_PATH'),
                'storage_path' => storage_path('app'),
            ]);
            throw new \Exception("Gmail credentials file not found at: {$credentialsPath}");
        }

        $this->client = new Client();
        $this->client->setAuthConfig($credentialsPath);
        $this->client->addScope(Gmail::GMAIL_SEND);
        $this->client->setSubject('webmaster@bestingames.com'); // The email to send from

        $this->service = new Gmail($this->client);
    }

    public function sendEmail($to, $subject, $htmlBody, $fromName = 'Zap Zone')
    {
        try {
            $message = $this->createMessage(
                'webmaster@bestingames.com',
                $to,
                $subject,
                $htmlBody,
                $fromName
            );

            $result = $this->service->users_messages->send('me', $message);

            Log::info('Gmail API email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'message_id' => $result->getId()
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

    private function createMessage($from, $to, $subject, $htmlBody, $fromName = 'Zap Zone')
    {
        // Create multipart message with proper encoding
        $boundary = uniqid('boundary_');
        
        $emailContent = "From: {$fromName} <{$from}>\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Reply-To: {$from}\r\n";
        $emailContent .= "Subject: {$subject}\r\n";
        $emailContent .= "MIME-Version: 1.0\r\n";
        $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        
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
        
        $emailContent .= "--{$boundary}--";

        $message = new Message();
        $message->setRaw($this->base64UrlEncode($emailContent));

        return $message;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
