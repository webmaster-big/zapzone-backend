<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SmsService
{
    protected ?Client $client = null;
    protected ?string $fromNumber = null;

    public function __construct()
    {
        $sid = config('twilio.sid');
        $token = config('twilio.auth_token');
        $this->fromNumber = config('twilio.from_number');

        if ($sid && $token && $this->fromNumber) {
            $this->client = new Client($sid, $token);
        }
    }

    /**
     * Check if Twilio SMS is configured.
     */
    public static function isConfigured(): bool
    {
        return !empty(config('twilio.sid'))
            && !empty(config('twilio.auth_token'))
            && !empty(config('twilio.from_number'));
    }

    /**
     * Send an SMS message.
     *
     * @param string $to Phone number in E.164 format (e.g., +15551234567)
     * @param string $message The SMS body text
     * @return string|null The message SID on success
     * @throws \Exception If SMS service is not configured or sending fails
     */
    public function sendSms(string $to, string $message): ?string
    {
        if (!$this->client) {
            throw new \Exception('Twilio SMS service is not configured. Set TWILIO_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER in your environment.');
        }

        // Clean phone number - ensure E.164 format
        $to = $this->formatPhoneNumber($to);

        try {
            $result = $this->client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $message,
            ]);

            Log::info('SMS sent successfully', [
                'to' => $to,
                'sid' => $result->sid,
                'status' => $result->status,
            ]);

            return $result->sid;
        } catch (\Exception $e) {
            Log::error('Failed to send SMS', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Format a phone number to E.164 format.
     * Assumes US numbers if no country code prefix.
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Strip all non-numeric characters except leading +
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // If already has +, keep it
        if ($hasPlus) {
            return '+' . $digits;
        }

        // If 10 digits (US), prepend +1
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        // If 11 digits starting with 1 (US with country code), prepend +
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        // Otherwise prepend + and hope for the best
        return '+' . $digits;
    }
}
