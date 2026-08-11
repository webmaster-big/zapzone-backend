<?php

namespace App\Services;

class PhotoDeviceTokenService
{
    public const MODE_KIOSK = 'kiosk';
    public const MODE_SLIDESHOW = 'slideshow';

    public function issue(int $locationId, string $mode): array
    {
        $days = max(1, (int) config('photos.device_token_days', 30));
        $expiresAt = now()->addDays($days);

        $payload = [
            'location_id' => $locationId,
            'mode' => $mode,
            'exp' => $expiresAt->timestamp,
            'nonce' => bin2hex(random_bytes(8)),
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->sign($encoded);

        return [
            'token' => $encoded . '.' . $signature,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function verify(?string $token, int $locationId, string $mode): bool
    {
        if (!$token || !str_contains($token, '.')) {
            return false;
        }

        [$encoded, $signature] = explode('.', $token, 2);

        if (!hash_equals($this->sign($encoded), $signature)) {
            return false;
        }

        $payload = json_decode((string) $this->base64UrlDecode($encoded), true);

        if (!is_array($payload)) {
            return false;
        }

        if (($payload['mode'] ?? null) !== $mode) {
            return false;
        }
        if ((int) ($payload['location_id'] ?? 0) !== $locationId) {
            return false;
        }
        if ((int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return false;
        }

        return true;
    }

    public function sessionSecret(int $sessionId, ?string $createdAt): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', 'photo-kiosk-session:' . $sessionId . ':' . (string) $createdAt, $this->key(), true)
        );
    }

    public function verifySessionSecret(?string $candidate, int $sessionId, ?string $createdAt): bool
    {
        if (!$candidate) {
            return false;
        }

        return hash_equals($this->sessionSecret($sessionId, $createdAt), $candidate);
    }

    protected function sign(string $encoded): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->key(), true));
    }

    protected function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
