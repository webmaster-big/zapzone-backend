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

    public static function enabled(): bool
    {
        return ResendService::isConfigured() || self::gmailConfigured();
    }

    public static function gmailConfigured(): bool
    {
        return (bool) config('gmail.enabled', false)
            && (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));
    }

    public static function transport(): string
    {
        if (ResendService::isConfigured()) {
            return 'resend';
        }

        return self::gmailConfigured() ? 'gmail_api' : (string) config('mail.default', 'smtp');
    }

    protected function gmail()
    {
        if ($this->service) {
            return $this->service;
        }

        $this->client = new Client();

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

        return $this->service = new Gmail($this->client);
    }

    public function sendEmail($to, $subject, $htmlBody, $fromName = null, $attachments = [], $extraHeaders = [], $skipInlineImages = false)
    {
        return $this->deliver($to, $subject, $htmlBody, $fromName, $attachments, $extraHeaders, $skipInlineImages, false);
    }

    public function sendTransactional($to, $subject, $htmlBody, $fromName = null, $attachments = [], $extraHeaders = [], $skipInlineImages = false)
    {
        return $this->deliver($to, $subject, $htmlBody, $fromName, $attachments, $extraHeaders, $skipInlineImages, true);
    }

    protected function deliver($to, $subject, $htmlBody, $fromName, $attachments, $extraHeaders, $skipInlineImages, bool $transactional)
    {
        $resendFailure = null;

        try {
            $fromName = $fromName ?? config('gmail.sender_name', 'Zap Zone');

            $inlineImages = [];
            if (!$skipInlineImages) {
                $htmlBody = $this->processInlineImages($htmlBody, $inlineImages);
            }

            foreach ($attachments as $index => $attachment) {
                if (isset($attachment['data'], $attachment['content_id'])) {
                    $inlineImages[] = [
                        'content_id' => $attachment['content_id'],
                        'data' => $attachment['data'],
                        'mime_type' => $attachment['mime_type'] ?? 'image/jpeg',
                        'filename' => $attachment['filename'] ?? 'image.jpg',
                    ];
                    unset($attachments[$index]);
                }
            }
            $attachments = array_values($attachments);

            $viaResend = ResendService::isConfigured();

            if ($viaResend) {
                $resendId = null;

                try {
                    $resendId = app(ResendService::class)->send(
                        $to,
                        $subject,
                        $htmlBody,
                        $this->htmlToPlainText($htmlBody),
                        (string) $fromName,
                        $attachments,
                        $inlineImages,
                        $extraHeaders,
                        $transactional,
                        !$transactional || self::gmailConfigured()
                    );
                } catch (\Throwable $e) {
                    if (!$transactional || !self::gmailConfigured()) {
                        Log::warning($transactional
                            ? 'Resend send failed and Gmail is not set up, so the email was not sent'
                            : 'Resend send failed; bulk email not sent, because only transactional email falls back to Gmail', [
                                'to' => $to,
                                'subject' => $subject,
                                'error' => $e->getMessage(),
                            ]);
                        $resendFailure = $e;

                        throw $e;
                    }

                    Log::error('Resend send failed; sending through Gmail instead', [
                        'to' => $to,
                        'subject' => $subject,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($resendId !== null) {
                    Log::info('Resend email sent', [
                        'to' => $to,
                        'subject' => $subject,
                        'resend_id' => $resendId,
                        'transactional' => $transactional,
                        'attachments_count' => count($attachments),
                        'inline_images_count' => count($inlineImages),
                    ]);

                    return true;
                }
            }

            $message = $this->createMessage(
                config('gmail.sender_email', 'bookings@zap-zone.com'),
                $to,
                $subject,
                $htmlBody,
                $fromName,
                $attachments,
                $inlineImages,
                $extraHeaders
            );

            $result = $this->gmail()->users_messages->send('me', $message);

            if ($viaResend) {
                ResendService::recordOutcome('gmail', $subject);
            }

            Log::info('Gmail API email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'message_id' => $result->getId(),
                'attachments_count' => count($attachments),
                'inline_images_count' => count($inlineImages)
            ]);

            return true;
        } catch (\Exception $e) {
            if ($e !== $resendFailure) {
                Log::error('Gmail API error: ' . $e->getMessage(), [
                    'to' => $to,
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw $e;
        }
    }

    public const INLINE_IMAGE_MAX_BYTES = 5242880;

    public static function inlineImageData(string $imageUrl): ?array
    {
        if (str_contains($imageUrl, '/storage/email-images/')) {
            $local = self::localEmailImage($imageUrl);

            if ($local !== null) {
                return $local;
            }
        }

        return self::remoteImage($imageUrl);
    }

    public static function localEmailImage(string $imageUrl): ?array
    {
        if (!preg_match('#/storage/email-images/([^"\'?\#\s]+)#', $imageUrl, $match)) {
            return null;
        }

        $directory = realpath(storage_path('app/public/email-images'));
        $relative = rawurldecode($match[1]);

        if ($directory === false || str_contains($relative, "\0")) {
            return null;
        }

        $path = realpath($directory . DIRECTORY_SEPARATOR . $relative);

        if ($path === false || !is_file($path) || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (filesize($path) > self::INLINE_IMAGE_MAX_BYTES) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        return ['data' => (string) file_get_contents($path), 'mime_type' => $mime, 'filename' => basename($path)];
    }

    public static function remoteImage(string $imageUrl): ?array
    {
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($imageUrl) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $addresses = self::publicAddresses($host);

        if ($addresses === []) {
            return null;
        }

        $data = self::fetchPinned($imageUrl, $addresses[0]);

        if ($data === null) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);

        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        return ['data' => $data, 'mime_type' => $mime, 'filename' => basename((string) ($parts['path'] ?? '')) ?: 'image.jpg'];
    }

    public static function fetchPinned(string $url, string $address, int $timeout = 10): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $data = '';
        $tooLarge = false;

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$data, &$tooLarge): int {
                if (strlen($data) + strlen($chunk) > self::INLINE_IMAGE_MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }

                $data .= $chunk;

                return strlen($chunk);
            },
        ];

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $options[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? '[' . $address . ']' : $address)];
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, $options);
        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($completed === false || $tooLarge || $status < 200 || $status >= 300 || $data === '') {
            return null;
        }

        return $data;
    }

    public static function isPublicHost(string $host): bool
    {
        return self::publicAddresses($host) !== [];
    }

    public static function publicAddresses(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];

            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [];
            }

            foreach (self::BLOCKED_RANGES as $range) {
                if (self::inRange($address, $range)) {
                    return [];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private const BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
        '224.0.0.0/3',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
    ];

    private static function inRange(string $address, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range);
        $ip = @inet_pton($address);
        $network = @inet_pton($subnet);

        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xff << (8 - $remainder)) & 0xff);

        return ($ip[$bytes] & $mask) === ($network[$bytes] & $mask);
    }

    private function processInlineImages(string $htmlBody, array &$inlineImages): string
    {
        $pattern = '/<img\s+[^>]*src=["\']([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($matches) use (&$inlineImages) {
            $fullTag = $matches[0];
            $imageUrl = $matches[1];

            if (str_starts_with($imageUrl, 'cid:') || str_starts_with($imageUrl, 'data:')) {
                return $fullTag;
            }

            try {
                $contentId = 'img_' . uniqid() . '@zapzone';
                $image = self::inlineImageData($imageUrl);

                if ($image !== null) {
                    $inlineImages[] = [
                        'content_id' => $contentId,
                        'data' => base64_encode($image['data']),
                        'mime_type' => $image['mime_type'],
                        'filename' => $image['filename'],
                    ];

                    return str_replace($imageUrl, 'cid:' . $contentId, $fullTag);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to process inline image: ' . $e->getMessage(), [
                    'url' => $imageUrl
                ]);
            }

            return $fullTag;
        }, $htmlBody);
    }

    private function createMessage($from, $to, $subject, $htmlBody, $fromName = 'Zap Zone', $attachments = [], $inlineImages = [], $extraHeaders = [])
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

        $headerSafe = static fn ($value) => trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));

        $fromName = $headerSafe($fromName);
        $from = $headerSafe($from);
        $to = $headerSafe($to);
        $subject = $headerSafe($subject);

        $encoded = static fn (string $value) => preg_match('/[^\x20-\x7E]/', $value)
            ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n ")
            : $value;

        try {
            $fromHeader = (new \Symfony\Component\Mime\Header\MailboxHeader('From', new \Symfony\Component\Mime\Address($from, $fromName)))->toString();
        } catch (\Throwable) {
            $displayName = preg_match('/[^\x20-\x7E]/', $fromName)
                ? $encoded($fromName)
                : (preg_match('/[()<>\[\]:;@\\\\,."]/', $fromName) ? '"' . addcslashes($fromName, '"\\') . '"' : $fromName);
            $fromHeader = "From: {$displayName} <{$from}>";
        }

        $emailContent = $fromHeader . "\r\n";
        $emailContent .= "To: {$to}\r\n";
        $emailContent .= "Reply-To: {$from}\r\n";
        $emailContent .= "Subject: " . $encoded($subject) . "\r\n";

        foreach ($extraHeaders as $headerName => $headerValue) {
            $headerName = $headerSafe($headerName);
            $headerValue = $headerSafe($headerValue);
            $emailContent .= "{$headerName}: {$headerValue}\r\n";
        }

        $emailContent .= "MIME-Version: 1.0\r\n";

        if ($hasAttachments && $hasInlineImages) {
            $emailContent .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$mixedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$relatedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } elseif ($hasAttachments) {
            $emailContent .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$mixedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } elseif ($hasInlineImages) {
            $emailContent .= "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n";
            $emailContent .= "--{$relatedBoundary}\r\n";
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        } else {
            $emailContent .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
        }

        $plainText = $this->htmlToPlainText($htmlBody);
        $emailContent .= "--{$altBoundary}\r\n";
        $emailContent .= "Content-Type: text/plain; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $plainText . "\r\n\r\n";

        $emailContent .= "--{$altBoundary}\r\n";
        $emailContent .= "Content-Type: text/html; charset=utf-8\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $htmlBody . "\r\n\r\n";

        $emailContent .= "--{$altBoundary}--\r\n";

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

    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $text);

        $text = preg_replace('/<!--.*?-->/s', '', $text);

        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', '$2 ( $1 )', $text);

        $text = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/si', "\n\n\$1\n", $text);

        $text = preg_replace('/<\/(p|div|tr|li)>/i', "\n", $text);
        $text = preg_replace('/<br[^>]*>/i', "\n", $text);
        $text = preg_replace('/<\/(td)>/i', "\t", $text);

        $text = preg_replace('/<hr[^>]*>/i', "\n---\n", $text);

        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        $text = preg_replace('/[ \t]+/', ' ', $text);

        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", $lines);

        return trim($text);
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
