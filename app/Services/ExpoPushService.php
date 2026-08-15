<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ExpoPushService
{
    /** Expo rejects anything larger with PUSH_TOO_MANY_NOTIFICATIONS. */
    public const MAX_MESSAGES_PER_REQUEST = 100;

    public static function isConfigured(): bool
    {
        return (bool) config('expo.enabled') && !empty(config('expo.base_url'));
    }

   
    public function send(array $messages): array
    {
        $results = [];

        foreach (array_chunk(array_values($messages), self::MAX_MESSAGES_PER_REQUEST) as $chunk) {
            foreach ($this->sendChunk($chunk) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function sendChunk(array $chunk): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout((int) config('expo.timeout', 10))
                ->post($this->url('send'), $chunk);
        } catch (\Throwable $e) {
            return $this->failChunk($chunk, 'TransportError', $e->getMessage());
        }

        if ($response->failed()) {
            return $this->failChunk($chunk, 'HttpError', 'Expo returned HTTP ' . $response->status() . '.');
        }

        $body = $response->json();

        // A request-level rejection (too many notifications, bad credentials) comes
        // back as an errors array with no tickets at all, so the whole chunk failed.
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return $this->failChunk(
                $chunk,
                (string) (data_get($body, 'errors.0.code') ?? 'InvalidResponse'),
                (string) (data_get($body, 'errors.0.message') ?? 'Expo returned an unexpected response.')
            );
        }

        $results = [];

        foreach ($chunk as $index => $message) {
            $ticket = $body['data'][$index] ?? null;

            if (!is_array($ticket)) {
                $results[] = $this->failure('MissingTicket', 'Expo did not return a ticket for this message.');
                continue;
            }

            if (($ticket['status'] ?? null) === 'ok') {
                $results[] = [
                    'status' => 'ok',
                    'ticket_id' => isset($ticket['id']) ? (string) $ticket['id'] : null,
                    'error_code' => null,
                    'error_message' => null,
                ];
                continue;
            }

            $results[] = $this->failure(
                (string) (data_get($ticket, 'details.error') ?? 'ExpoError'),
                (string) ($ticket['message'] ?? 'Expo rejected this message.')
            );
        }

        return $results;
    }

    private function failChunk(array $chunk, string $code, string $message): array
    {
        return array_fill(0, count($chunk), $this->failure($code, $message));
    }

    private function failure(string $code, string $message): array
    {
        return [
            'status' => 'error',
            'ticket_id' => null,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 1000),
        ];
    }

    private function headers(): array
    {
        $headers = [
            'accept' => 'application/json',
            'accept-encoding' => 'gzip, deflate',
            'content-type' => 'application/json',
        ];

        if ($token = config('expo.access_token')) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('expo.base_url'), '/') . '/' . $path;
    }
}
