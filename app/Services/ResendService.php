<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class ResendService
{
    public const ENDPOINT = 'https://api.resend.com/emails';

    public const UNAVAILABLE_KEY = 'resend:unavailable';

    public const UNAVAILABLE_SECONDS = 60;

    public const ACCOUNT_ERRORS = ['missing_api_key', 'invalid_api_key', 'restricted_api_key', 'invalid_from_address', 'daily_quota_exceeded', 'monthly_quota_exceeded', 'security_error'];

    public const OUTCOMES = ['sent', 'failed', 'gmail'];

    protected static float $lastBulkSend = 0.0;

    public static function isConfigured(): bool
    {
        return (bool) config('services.resend.enabled') && filled(config('services.resend.key'));
    }

    public static function fromAddress(bool $transactional = true): string
    {
        $address = trim((string) (($transactional ? null : config('services.resend.marketing_from_address'))
            ?: config('services.resend.from_address')
            ?: config('gmail.sender_email', 'bookings@zap-zone.com')));

        return preg_match('/<([^<>]+)>/', $address, $match) ? trim($match[1]) : $address;
    }

    public static function apiKey(bool $transactional = true): string
    {
        $key = ($transactional ? null : config('services.resend.marketing_key')) ?: config('services.resend.key');

        return (string) preg_replace('/^bearer\s+/i', '', trim((string) $key));
    }

    public static function fromHeader(string $name, string $address): string
    {
        return preg_match('/[()<>\[\]:;@\\\\,."]/', $name)
            ? sprintf('"%s" <%s>', addcslashes($name, '"\\'), $address)
            : sprintf('%s <%s>', $name, $address);
    }

    public static function unavailableReason(): ?string
    {
        try {
            $reason = Cache::get(self::UNAVAILABLE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $fromName,
        array $attachments = [],
        array $inlineImages = [],
        array $extraHeaders = [],
        bool $transactional = true,
        bool $skipWhileUnavailable = true
    ): string {
        if ($skipWhileUnavailable && ($reason = self::unavailableReason()) !== null) {
            throw new ResendException("Resend is skipped for a minute after it failed ({$reason}).", 0, 'unavailable', ResendException::TRANSIENT);
        }

        $oneLine = static fn ($value) => trim(str_replace(["\r", "\n", "\0"], ' ', (string) $value));
        $name = $oneLine($fromName) ?: $oneLine(config('gmail.sender_name', 'Zap Zone')) ?: 'Zap Zone';

        $payload = [
            'from' => self::fromHeader($name, self::fromAddress($transactional)),
            'to' => [$oneLine($to)],
            'reply_to' => config('gmail.sender_email', 'bookings@zap-zone.com'),
            'subject' => $oneLine($subject),
            'html' => $htmlBody,
            'text' => $textBody,
        ];

        $files = array_merge(
            array_map(fn (array $file) => [
                'filename' => $file['filename'] ?? 'attachment',
                'content' => $file['data'],
                'content_type' => $file['mime_type'] ?? 'application/octet-stream',
            ], $attachments),
            array_map(fn (array $image) => [
                'filename' => $image['filename'] ?? 'image.jpg',
                'content' => $image['data'],
                'content_type' => $image['mime_type'] ?? 'image/jpeg',
                'content_id' => $image['content_id'],
            ], $inlineImages)
        );

        if ($files !== []) {
            $payload['attachments'] = $files;
        }

        if ($extraHeaders !== []) {
            $payload['headers'] = array_map($oneLine, $extraHeaders);
        }

        if (!$transactional) {
            $this->paceBulkSends();
        }

        $droppedOnce = false;

        try {
            $response = Http::withToken(self::apiKey($transactional))
                ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                ->withUserAgent('zapzone-backend')
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($transactional ? 10 : 45)
                ->retry(
                    $transactional ? 2 : 3,
                    fn (int $attempt, \Throwable $e) => self::retryDelay($attempt, $e),
                    function (\Throwable $e) use (&$droppedOnce) {
                        $droppedOnce = $droppedOnce || $e instanceof ConnectionException;

                        return self::shouldRetry($e);
                    },
                    false
                )
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $e) {
            self::markUnavailable('it could not be reached');
            self::recordOutcome('failed', 'Resend could not be reached: ' . $e->getMessage());

            throw new ResendException('Resend could not be reached: ' . $e->getMessage(), 0, 'connection_error', ResendException::TRANSIENT, $e);
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (\Throwable $e) {
            self::recordOutcome('failed', 'The email could not be sent to Resend: ' . $e->getMessage());

            throw new ResendException('The email could not be sent to Resend: ' . $e->getMessage(), 0, 'request_error', ResendException::RECIPIENT, $e);
        }

        if ($droppedOnce) {
            self::markUnavailable('it timed out or dropped the connection');
        }

        $status = $response->status();
        $error = (string) ($response->json('name') ?? '');

        if ($status === 409 && $error === 'concurrent_idempotent_requests') {
            self::recordOutcome('sent');

            return '';
        }

        if ($response->failed()) {
            if ($status >= 500) {
                self::markUnavailable("it answered HTTP {$status}");
            }

            $message = sprintf(
                'Resend refused the email (HTTP %d, %s): %s',
                $status,
                $error !== '' ? $error : 'no code',
                $response->json('message') ?? mb_substr($response->body(), 0, 300)
            );

            self::recordOutcome('failed', $message);

            throw new ResendException($message, $status, $error, self::scopeOf($status, $error));
        }

        self::recordOutcome('sent');

        return (string) ($response->json('id') ?? '');
    }

    public static function scopeOf(int $status, string $error): string
    {
        if ($status >= 500 || $status === 0 || $error === 'rate_limit_exceeded') {
            return ResendException::TRANSIENT;
        }

        if (in_array($status, [401, 403, 451], true) || in_array($error, self::ACCOUNT_ERRORS, true)) {
            return ResendException::ACCOUNT;
        }

        return $status === 429 ? ResendException::TRANSIENT : ResendException::RECIPIENT;
    }

    public static function recordOutcome(string $outcome, ?string $detail = null): void
    {
        try {
            $key = 'resend:stats:' . now()->format('Y-m-d') . ':' . $outcome;
            Cache::add($key, 0, now()->addDays(8));
            Cache::increment($key);

            if ($detail !== null) {
                Cache::put('resend:last:' . $outcome, ['at' => now()->toDateTimeString(), 'detail' => mb_substr($detail, 0, 500)], now()->addDays(8));
            }
        } catch (\Throwable) {
        }
    }

    public static function campaignLimits(): array
    {
        return [
            ['limit' => (int) config('services.resend.campaign_per_hour'), 'key' => 'resend:campaign:' . now()->format('Y-m-d-H'), 'minutes' => 120, 'env' => 'RESEND_CAMPAIGN_PER_HOUR', 'period' => 'this hour'],
            ['limit' => (int) config('services.resend.campaign_per_day'), 'key' => 'resend:campaign:' . now()->format('Y-m-d'), 'minutes' => 1560, 'env' => 'RESEND_CAMPAIGN_PER_DAY', 'period' => 'today'],
        ];
    }

    public static function campaignUsed(array $window): int
    {
        try {
            return (int) Cache::get($window['key'], 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function claimCampaignAllowance(): ?string
    {
        $windows = array_filter(self::campaignLimits(), fn (array $window) => $window['limit'] > 0);

        foreach ($windows as $window) {
            if (self::campaignUsed($window) >= $window['limit']) {
                return "the warm-up limit of {$window['limit']} campaign emails {$window['period']} ({$window['env']}) is used up; the rest go out after that";
            }
        }

        foreach ($windows as $window) {
            try {
                Cache::add($window['key'], 0, now()->addMinutes($window['minutes']));
                Cache::increment($window['key']);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public static function stats(int $days = 7): array
    {
        $rows = [];

        for ($day = 0; $day < $days; $day++) {
            $date = now()->subDays($day)->format('Y-m-d');
            $rows[$date] = [];

            foreach (self::OUTCOMES as $outcome) {
                try {
                    $rows[$date][$outcome] = (int) Cache::get("resend:stats:{$date}:{$outcome}", 0);
                } catch (\Throwable) {
                    $rows[$date][$outcome] = 0;
                }
            }
        }

        return $rows;
    }

    public static function lastOutcome(string $outcome): ?array
    {
        try {
            $last = Cache::get('resend:last:' . $outcome);
        } catch (\Throwable) {
            return null;
        }

        return is_array($last) ? $last : null;
    }

    protected function paceBulkSends(): void
    {
        $perSecond = (float) config('services.resend.bulk_per_second', 4);

        if ($perSecond > 0) {
            $wait = static::$lastBulkSend + 1 / $perSecond - microtime(true);

            if ($wait > 0) {
                Sleep::usleep((int) ceil($wait * 1000000));
            }
        }

        static::$lastBulkSend = microtime(true);
    }

    protected static function retryDelay(int $attempt, \Throwable $e): int
    {
        if ($e instanceof RequestException && $e->response->status() === 429) {
            return 1000 * min(5, max(1, (int) $e->response->header('retry-after')));
        }

        return 500 * $attempt;
    }

    protected static function shouldRetry(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (!$e instanceof RequestException) {
            return false;
        }

        $status = $e->response->status();
        $error = (string) $e->response->json('name');

        return ($status === 429 && !str_contains($error, 'quota'))
            || ($status === 409 && $error === 'concurrent_idempotent_requests')
            || $status >= 500;
    }

    protected static function markUnavailable(string $reason): void
    {
        try {
            Cache::put(self::UNAVAILABLE_KEY, $reason, self::UNAVAILABLE_SECONDS);
        } catch (\Throwable) {
        }
    }
}
