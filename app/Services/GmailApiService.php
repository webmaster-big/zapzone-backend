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

    public function sendEmail($to, $subject, $htmlBody, $fromName = null, $attachments = [])
    {
        try {
            // Use config for sender name if not provided
            $fromName = $fromName ?? config('gmail.sender_name', 'Zap Zone');

            // Process inline images - embed them in the email
            $inlineImages = [];
            $htmlBody = $this->processInlineImages($htmlBody, $inlineImages);

            $message = $this->createMessage(
                config('gmail.sender_email', 'bookings@zap-zone.com'),
                $to,
                $subject,
                $htmlBody,
                $fromName,
                $attachments,
                $inlineImages
            );

            $result = $this->service->users_messages->send('me', $message);

            Log::info('Gmail API email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'message_id' => $result->getId(),
                'attachments_count' => count($attachments),
                'inline_images_count' => count($inlineImages)
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

    /**
     * Process inline images in HTML body - download and prepare for embedding.
     */
    private function processInlineImages(string $htmlBody, array &$inlineImages): string
    {
        // Find all img tags with src attributes
        $pattern = '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use (&$inlineImages) {
            $fullTag = $matches[0];
            $imageUrl = $matches[1];

            // Skip if already a cid: reference or data: URI
            if (str_starts_with($imageUrl, 'cid:') || str_starts_with($imageUrl, 'data:')) {
                return $fullTag;
            }

            try {
                // Generate a unique Content-ID for this image
                $contentId = 'img_' . uniqid() . '@zapzone';

                // Try to get the image content
                $imageData = null;
                $mimeType = 'image/jpeg';
                $filename = 'image.jpg';

                // Check if it's a local storage URL
                if (str_contains($imageUrl, '/storage/email-images/')) {
                    // Extract the path from the URL
                    preg_match('/\/storage\/email-images\/([^"\'?\s]+)/', $imageUrl, $pathMatch);
                    if (!empty($pathMatch[1])) {
                        $localPath = storage_path('app/public/email-images/' . $pathMatch[1]);
                        if (file_exists($localPath)) {
                            $imageData = file_get_contents($localPath);
                            $mimeType = mime_content_type($localPath);
                            $filename = basename($localPath);
                        }
                    }
                }

                // If not found locally, try to fetch from URL
                if ($imageData === null && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $context = stream_context_create([
                        'http' => [
                            'timeout' => 10,
                            'user_agent' => 'Mozilla/5.0'
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false
                        ]
                    ]);
                    $imageData = @file_get_contents($imageUrl, false, $context);

                    if ($imageData !== false) {
                        // Detect mime type from content
                        $finfo = new \finfo(FILEINFO_MIME_TYPE);
                        $mimeType = $finfo->buffer($imageData);
                        $filename = basename(parse_url($imageUrl, PHP_URL_PATH)) ?: 'image.jpg';
                    }
                }

                if ($imageData) {
                    $inlineImages[] = [
                        'content_id' => $contentId,
                        'data' => base64_encode($imageData),
                        'mime_type' => $mimeType,
                        'filename' => $filename,
                    ];

                    // Replace src with cid: reference
                    return str_replace($imageUrl, 'cid:' . $contentId, $fullTag);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to process inline image: ' . $e->getMessage(), [
                    'url' => $imageUrl
                ]);
            }

            // Return original tag if processing failed
            return $fullTag;
        }, $htmlBody);
    }

    private function createMessage($from, $to, $subject, $htmlBody, $fromName = 'Zap Zone', $attachments = [], $inlineImages = [])
    {
        $mixedBoundary = uniqid('mixed_');
        $relatedBoundary = uniqid('related_');
        $altBoundary = uniqid('alt_');

        $hasAttachments = !empty($attachments);
        $hasInlineImages = !empty($inlineImages);

        Log::info('Creating email message', [
            'to' => $to,
            'hasAttachments' => $hasAttachments,
            'attachmentsCount' => count($attachments),
            'hasInlineImages' => $hasInlineImages,
        ]);

        $emailContent = "From: {$fromName} <{$from}>\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Reply-To: {$from}\r\n";
        $emailContent .= "Subject: {$subject}\r\n";
        $emailContent .= "MIME-Version: 1.0\r\n";

        if ($hasAttachments && $hasInlineImages) {
            // Structure: mixed -> related -> alternative + inline images, then attachments
            $emailContent .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$mixedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$relatedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } elseif ($hasAttachments) {
            // Structure: mixed -> alternative, then attachments
            $emailContent .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$mixedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } elseif ($hasInlineImages) {
            // Structure: related -> alternative + inline images
            $emailContent .= "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$relatedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } else {
            // Simple: just alternative (text + html)
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        }

        // Plain text version
        $plainText = strip_tags($htmlBody);
        $emailContent .= "--{$altBoundary}\r\n";
        $emailContent .= "Content-Type: text/plain; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $plainText . "\r\n\r\n";

        // HTML version
        $emailContent .= "--{$altBoundary}\r\n";
        $emailContent .= "Content-Type: text/html; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $htmlBody . "\r\n\r\n";

        $emailContent .= "--{$altBoundary}--\r\n";

        // Add inline images if any
        if ($hasInlineImages) {
            foreach ($inlineImages as $image) {
                $emailContent .= "\r\n--{$relatedBoundary}\r\n";
                $emailContent .= "Content-Type: {$image['mime_type']}; name=\"{$image['filename']}\"\r\n";
                $emailContent .= "Content-Transfer-Encoding: base64\r\n";
                $emailContent .= "Content-ID: <{$image['content_id']}>\r\n";
                $emailContent .= "Content-Disposition: inline; filename=\"{$image['filename']}\"\r\n\r\n";
                $emailContent .= chunk_split($image['data']) . "\r\n";
            }
            $emailContent .= "--{$relatedBoundary}--\r\n";
        }

        // Add file attachments if any
        if ($hasAttachments) {
            foreach ($attachments as $attachment) {
                $emailContent .= "\r\n--{$mixedBoundary}\r\n";
                $emailContent .= "Content-Type: {$attachment['mime_type']}; name=\"{$attachment['filename']}\"\r\n";
                $emailContent .= "Content-Transfer-Encoding: base64\r\n";
                $emailContent .= "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n";
                $emailContent .= chunk_split($attachment['data']) . "\r\n";
            }
            $emailContent .= "--{$mixedBoundary}--";
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
