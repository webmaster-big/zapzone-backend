<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    protected $client = null;
    protected ?string $fromNumber = null;

    public function __construct()
    {
        $sid = config('twilio.sid');
        $token = config('twilio.auth_token');
        $this->fromNumber = config('twilio.from_number');

        if ($sid && $token && $this->fromNumber && class_exists(\Twilio\Rest\Client::class)) {
            $this->client = new \Twilio\Rest\Client($sid, $token);
        }
    }

    public static function isConfigured(): bool
    {
        return !empty(config('twilio.sid'))
            && !empty(config('twilio.auth_token'))
            && !empty(config('twilio.from_number'))
            && class_exists(\Twilio\Rest\Client::class);
    }

    public function sendSms(string $to, string $message): ?string
    {
        if (!$this->client) {
            throw new \Exception('Twilio SMS service is not configured. Set TWILIO_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER in your environment.');
        }

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

    protected function formatPhoneNumber(string $phone): string
    {
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if ($hasPlus) {
            return '+' . $digits;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}
