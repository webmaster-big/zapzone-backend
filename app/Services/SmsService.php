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

        $formatted = self::toE164($to);

        if ($formatted === null) {
            throw new \InvalidArgumentException(
                'This mobile number cannot be texted as written: "' . $to . '". Twilio needs a country code and 10 to 15 digits.'
            );
        }

        $to = $formatted;

        try {
            $result = $this->client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $message,
            ]);

            // "queued" here is normal and is not a problem: it is the status at the moment
            // the provider accepted the message, which is all we can know synchronously.
            // Delivery to the handset happens afterwards and is reported separately, so do
            // not read this line as proof the message arrived.
            Log::info('Text message accepted by the provider (delivery not confirmed yet)', [
                'to' => $to,
                'sid' => $result->sid,
                'status_at_acceptance' => $result->status,
                'check_delivery_with' => 'php artisan sms:diagnose --sid=' . $result->sid,
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
     * Turn a number written any of the ways staff and customers write them into the
     * E.164 form Twilio requires, or null when it cannot be one.
     *
     * Brackets, spaces, dots, dashes and a leading + were always handled. These were not,
     * and each was accepted and then rejected by Twilio as an invalid destination:
     *
     *   (810) 555-0134 x22      a desk extension is not part of the number
     *   011 44 20 7946 0958     "011" is how you dial out of the US, not a country code
     *   0044 20 7946 0958       same for "00" elsewhere
     *   +44 (0)20 7946 0958     the trunk zero is dropped when the country code is used
     */
    public static function toE164(?string $phone): ?string
    {
        $raw = trim((string) $phone);

        if ($raw === '') {
            return null;
        }

        // A trailing extension belongs to a desk phone, not the mobile. The marker has to sit
        // between digits and digits at the very end, so that a messy field such as
        // "Alex 810-555-0134" or "#8105550134" is not mistaken for one and thrown away.
        $raw = (string) preg_replace('/(?<=\d)\s*(?:extension|ext\.?|x|#|,|;)\s*\d+\s*$/i', '', $raw);

        $hadPlus = str_starts_with($raw, '+');

        // A bracketed trunk zero, as UK and Australian numbers are commonly written.
        $hadBracketedTrunkZero = preg_match('/\(\s*0\s*\)/', $raw) === 1;
        $raw = (string) preg_replace('/\(\s*0\s*\)/', '', $raw);

        $digits = (string) preg_replace('/\D+/', '', $raw);

        // "011" and "00" are how you dial out of a country rather than part of the number.
        // Removing one tells us the digits that remain already start with a country code.
        $hadDialOutPrefix = false;

        if (!$hadPlus) {
            if (str_starts_with($digits, '011') && strlen($digits) > 11) {
                $digits = substr($digits, 3);
                $hadDialOutPrefix = true;
            } elseif (str_starts_with($digits, '00') && strlen($digits) > 10) {
                $digits = substr($digits, 2);
                $hadDialOutPrefix = true;
            }
        }

        $startedWithZero = str_starts_with($digits, '0');
        $digits = ltrim($digits, '0');
        $countryCodeGiven = $hadPlus || $hadDialOutPrefix;

        // Still beginning with a zero, or carrying a bracketed trunk zero, and no country code
        // anywhere: this is some country's national format and there is no way to tell which.
        // Guessing North America would text a real stranger, so refuse it and let staff correct it.
        if (!$countryCodeGiven && ($startedWithZero || $hadBracketedTrunkZero)) {
            return null;
        }

        // A bare 10-digit number is North American; Twilio still needs the country code. Never
        // once a country code is already present: "011 65 9123 4567" is a Singapore mobile, and
        // adding a 1 to it would deliver the message to a real Alabama number instead.
        if (!$countryCodeGiven && strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        // E.164 allows 15 digits in total. Short numbers are only trusted when the country
        // code was spelled out, so that a mistyped local number is refused rather than sent
        // somewhere unintended: 810-555-013 is a typo, not a nine-digit foreign number.
        $shortest = $countryCodeGiven ? 8 : 11;

        if (strlen($digits) < $shortest || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }

    protected function formatPhoneNumber(string $phone): string
    {
        return self::toE164($phone) ?? $phone;
    }
}
