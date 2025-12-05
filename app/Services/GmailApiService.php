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
        // For Laravel Forge deployments, use base_path for shared storage
        // In production: /home/forge/site.com/shared/storage/app/gmail.json
        // In local: /path/to/project/storage/app/gmail.json
        $credentialsPath = base_path('storage/app/gmail.json');
        
        if (!file_exists($credentialsPath)) {
            Log::error('Gmail credentials file not found', [
                'path' => $credentialsPath,
                'base_path' => base_path(),
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
        $emailContent = "From: {$fromName} <{$from}>\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Reply-To: {$from}\r\n";
        $emailContent .= "Subject: {$subject}\r\n";
        $emailContent .= "MIME-Version: 1.0\r\n";
        $emailContent .= "Content-Type: text/html; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $emailContent .= $htmlBody;

        $message = new Message();
        $message->setRaw($this->base64UrlEncode($emailContent));

        return $message;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
