<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    /** Expo rejects anything larger with PUSH_TOO_MANY_NOTIFICATIONS. */
    public const MAX_MESSAGES_PER_REQUEST = 100;

    /** Expo rejects anything larger with PUSH_TOO_MANY_RECEIPTS. */
    public const MAX_RECEIPT_IDS_PER_REQUEST = 1000;

    /** The one receipt error that means the token is dead rather than the send unlucky. */
    public const ERROR_DEVICE_NOT_REGISTERED = 'DeviceNotRegistered';

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

    /**
     * Look up what became of tickets Expo accepted earlier, chunked to its
     * per-request limit.
     *
     * Keyed by ticket id. A ticket Expo cannot answer for — never issued, or
     * older than the 24 hours it keeps receipts for — is simply absent, as is
     * every ticket in a chunk whose request failed. Absent always means "still
     * unknown", never "delivered" and never "dead device", so the caller can
     * safely leave those alone and ask again next run.
     *
     * @param  array<int, string|null>  $ticketIds
     * @return array<string, array{status: string, error_code: ?string, error_message: ?string}>
     */
    public function receipts(array $ticketIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $ticketIds,
            fn ($id) => is_string($id) && $id !== ''
        )));

        $receipts = [];

        foreach (array_chunk($ids, self::MAX_RECEIPT_IDS_PER_REQUEST) as $chunk) {
            foreach ($this->receiptChunk($chunk) as $ticketId => $receipt) {
                $receipts[$ticketId] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * @param  array<int, string>  $chunk
     * @return array<string, array{status: string, error_code: ?string, error_message: ?string}>
     */
    private function receiptChunk(array $chunk): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout((int) config('expo.timeout', 10))
                ->post($this->url('getReceipts'), ['ids' => $chunk]);
        } catch (\Throwable $e) {
            return $this->receiptChunkUnavailable($chunk, 'TransportError', $e->getMessage());
        }

        if ($response->failed()) {
            return $this->receiptChunkUnavailable($chunk, 'HttpError', 'Expo returned HTTP ' . $response->status() . '.');
        }

        $body = $response->json();

        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return $this->receiptChunkUnavailable(
                $chunk,
                (string) (data_get($body, 'errors.0.code') ?? 'InvalidResponse'),
                (string) (data_get($body, 'errors.0.message') ?? 'Expo returned an unexpected receipt response.')
            );
        }

        $receipts = [];

        foreach ($body['data'] as $ticketId => $receipt) {
            if (!is_array($receipt)) {
                continue;
            }

            $delivered = ($receipt['status'] ?? null) === 'ok';

            $receipts[(string) $ticketId] = [
                'status' => $delivered ? 'ok' : 'error',
                'error_code' => $delivered ? null : (string) (data_get($receipt, 'details.error') ?? 'ExpoError'),
                'error_message' => $delivered
                    ? null
                    : mb_substr((string) ($receipt['message'] ?? 'Expo reported a delivery error.'), 0, 1000),
            ];
        }

        return $receipts;
    }

    /**
     * @param  array<int, string>  $chunk
     * @return array<string, never>
     */
    private function receiptChunkUnavailable(array $chunk, string $code, string $message): array
    {
        Log::warning('Could not retrieve Expo push receipts', [
            'tickets' => count($chunk),
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 1000),
        ]);

        return [];
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
