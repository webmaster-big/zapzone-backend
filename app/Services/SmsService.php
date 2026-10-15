<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    public const FINAL_STATUSES = ['delivered', 'undelivered', 'failed', 'canceled', 'read'];

    public const NOT_DELIVERED_STATUSES = ['undelivered', 'failed', 'canceled'];

    public const PENDING_STATUSES = ['queued', 'accepted', 'scheduled', 'sending'];

    public const GUEST_PROBLEM_CODES = ['21211', '21610', '21614', '30003', '30004', '30005', '30006'];

    public const TOLL_FREE_AREA_CODES = ['800', '833', '844', '855', '866', '877', '888'];

    public const SENDER_PROBLEM_CODES = ['30032', '30034', '21606', '20003', '21660', '30002'];

    public const CARRIER_BLOCK_CODES = ['30032', '30034'];

    public const GUEST_PERMANENT_CODES = ['21610', '21211', '21614', '21408', '21612', '30004', '30005', '30006'];

    protected const CODE_MEANINGS = [
        '30032' => 'The toll-free texting number is not verified with the phone carriers (the verification is pending or was rejected), so carriers block every text it sends. An administrator needs to check Toll-Free Verification in the Twilio console.',
        '30034' => 'The texting number is not registered for business texting yet (A2P 10DLC), so phone carriers block its texts.',
        '30007' => "The guest's phone carrier filtered the text as unwanted. This often happens to texts that contain a link.",
        '30003' => "The guest's phone was switched off or out of coverage.",
        '30004' => 'The guest or their carrier has blocked texts from this number.',
        '30005' => 'That phone number does not exist or is no longer in service.',
        '30006' => 'That number is a landline or cannot receive texts.',
        '30008' => "The guest's carrier did not say why the text was not delivered.",
        '30001' => 'The texting service was too busy and dropped the text.',
        '30002' => 'The texting account is suspended, so no text can be sent.',
        '21610' => 'This guest replied STOP to an earlier text, so their number cannot be texted until they reply START.',
        '21211' => 'The phone number is not valid.',
        '21614' => 'That number cannot receive texts because it is not a mobile number.',
        '21408' => 'Texting to that country is not switched on for this account.',
        '21612' => 'The texting number cannot send to that destination.',
        '21606' => 'The texting number is not able to send texts.',
        '20003' => 'The texting account details were rejected, so no text could be sent.',
        '21660' => 'The texting number does not belong to this account.',
    ];

    protected const GSM_BASIC = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    protected const GSM_EXTENDED = '^{}\\[~]|€';

    protected $client = null;
    protected $lookupClient = null;
    protected ?string $fromNumber = null;

    public function __construct()
    {
        $sid = config('twilio.sid');
        $token = config('twilio.auth_token');
        $this->fromNumber = config('twilio.from_number');

        if ($sid && $token && $this->fromNumber && class_exists(\Twilio\Rest\Client::class)) {
            $this->client = new \Twilio\Rest\Client($sid, $token);
            $this->lookupClient = new \Twilio\Rest\Client($sid, $token, null, null, new \Twilio\Http\CurlClient([
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]));
        }
    }

    public static function segmentInfo(string $body): array
    {
        $characters = mb_str_split($body);
        $gsm = true;
        $length = 0;

        foreach ($characters as $character) {
            if ($character === "\f" || mb_strpos(self::GSM_EXTENDED, $character) !== false) {
                $length += 2;
            } elseif ($character === "\n" || $character === "\r" || mb_strpos(self::GSM_BASIC, $character) !== false) {
                $length += 1;
            } else {
                $gsm = false;
                break;
            }
        }

        if (!$gsm) {
            $length = intdiv(strlen((string) mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2);
        }

        $single = $gsm ? 160 : 70;
        $part = $gsm ? 153 : 67;

        return [
            'characters' => $length,
            'encoding' => $gsm ? 'GSM-7' : 'UCS-2',
            'segments' => $length <= $single ? 1 : (int) ceil($length / $part),
        ];
    }

    public static function isConfigured(): bool
    {
        return !empty(config('twilio.sid'))
            && !empty(config('twilio.auth_token'))
            && !empty(config('twilio.from_number'))
            && class_exists(\Twilio\Rest\Client::class);
    }

    public static function senderNumber(): ?string
    {
        $from = trim((string) config('twilio.from_number'));

        if ($from === '') {
            return null;
        }

        return self::toE164($from) ?? $from;
    }

    public static function senderKind(?string $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        if ($digits === '') {
            return 'none';
        }
        if (strlen($digits) <= 6) {
            return 'short_code';
        }

        $areaCode = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1, 3) : substr($digits, 0, 3);

        return in_array($areaCode, self::TOLL_FREE_AREA_CODES, true) ? 'toll_free' : 'standard';
    }

    public static function meaningOf(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : (self::CODE_MEANINGS[$code] ?? null);
    }

    public static function isFinalStatus(?string $status): bool
    {
        return in_array((string) $status, self::FINAL_STATUSES, true);
    }

    public static function failureCode(\Throwable $e): ?string
    {
        if (class_exists(\Twilio\Exceptions\RestException::class)
            && $e instanceof \Twilio\Exceptions\RestException
            && $e->getCode() > 0) {
            return (string) $e->getCode();
        }

        return null;
    }

    public static function describeFailure(\Throwable $e): string
    {
        $code = self::failureCode($e);
        $raw = trim(mb_substr($e->getMessage(), 0, 400));
        $meaning = self::meaningOf($code);

        if ($meaning === null) {
            return $raw;
        }

        return $meaning . ' (Provider code ' . $code . ': ' . $raw . ')';
    }

    public function sendMessage(string $to, string $message): array
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

        try {
            $result = $this->client->messages->create($formatted, [
                'from' => $this->fromNumber,
                'body' => $message,
            ]);

            Log::info('Text message accepted by the provider (delivery not confirmed yet)', [
                'to' => $formatted,
                'sid' => $result->sid,
                'status_at_acceptance' => $result->status,
                'check_delivery_with' => 'php artisan sms:diagnose --sid=' . $result->sid,
            ]);

            return [
                'sid' => (string) $result->sid,
                'status' => (string) ($result->status ?? 'queued'),
                'to' => $formatted,
                'from' => (string) ($result->from ?? self::senderNumber() ?? $this->fromNumber),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send SMS', [
                'to' => $formatted,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fetchMessage(string $sid): array
    {
        $client = $this->lookupClient ?? $this->client;

        if (!$client) {
            throw new \Exception('Twilio SMS service is not configured, so the message status cannot be checked.');
        }

        $message = $client->messages($sid)->fetch();
        $code = $message->errorCode ?? null;

        return [
            'sid' => (string) $message->sid,
            'status' => (string) $message->status,
            'error_code' => $code === null || $code === '' ? null : (string) $code,
            'error_message' => $message->errorMessage ? (string) $message->errorMessage : null,
            'from' => $message->from ? (string) $message->from : null,
        ];
    }

    public function sendSms(string $to, string $message): ?string
    {
        return $this->sendMessage($to, $message)['sid'];
    }

    public static function cleanPhoneInput(?string $phone): string
    {
        $raw = (string) $phone;
        $raw = preg_replace('/\p{Cf}+/u', '', $raw) ?? $raw;
        $raw = strtr($raw, [
            "\u{FF0B}" => '+', "\u{FF10}" => '0', "\u{FF11}" => '1', "\u{FF12}" => '2', "\u{FF13}" => '3', "\u{FF14}" => '4',
            "\u{FF15}" => '5', "\u{FF16}" => '6', "\u{FF17}" => '7', "\u{FF18}" => '8', "\u{FF19}" => '9', "\u{00A0}" => ' ',
        ]);
        $raw = trim($raw);

        return preg_replace('/^[^\d+]*(?=\+)/u', '', $raw) ?? $raw;
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
        $raw = self::cleanPhoneInput($phone);

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
