<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaiverProfile extends Model
{
    use SoftDeletes;

    public const COUNTRY_CODE_NEEDED = 'Please check the country code. Start a US or Canadian number with +1, or put a space after your country code, for example +47 912 34 567.';

    public const PHONE_NOT_RECOGNISED = 'Please enter a 10-digit US or Canadian number, or start a number from another country with + and its country code.';

    protected $fillable = [
        'company_id',
        'last_location_id',
        'phone_digits',
        'phone_e164',
        'phone_raw',
        'first_name',
        'last_name',
        'email',
        'date_of_birth',
        'needs_staff_review',
        'submissions_count',
        'last_waiver_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'needs_staff_review' => 'boolean',
        'submissions_count' => 'integer',
        'last_waiver_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lastLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'last_location_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(WaiverProfileDependent::class);
    }

    public function activeDependents(): HasMany
    {
        return $this->dependents()->where('is_active', true);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class)->orderByDesc('submitted_at');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public static function storedPhoneFor(?string $phone): ?string
    {
        $raw = \App\Services\SmsService::cleanPhoneInput($phone);

        if ($raw === '') {
            return null;
        }

        $foreign = self::explicitForeignNumber($raw);

        if ($foreign !== null) {
            if (!self::looksNorthAmerican($foreign)) {
                return $foreign;
            }

            $countryCode = self::separatedCountryCode($raw, $foreign);

            return $countryCode === null ? null : '+' . $countryCode . ' ' . substr($foreign, 1 + strlen($countryCode));
        }

        $e164 = \App\Services\SmsService::toE164($raw);

        return $e164 !== null && preg_match('/^\+1([2-9]\d{9})$/', $e164, $national) === 1 ? $national[1] : null;
    }

    public static function phoneProblem(?string $phone): string
    {
        return self::needsCountryCode($phone) ? self::COUNTRY_CODE_NEEDED : self::PHONE_NOT_RECOGNISED;
    }

    public static function needsCountryCode(?string $phone): bool
    {
        $raw = \App\Services\SmsService::cleanPhoneInput($phone);
        $foreign = self::explicitForeignNumber($raw);

        return $foreign !== null && self::looksNorthAmerican($foreign) && self::separatedCountryCode($raw, $foreign) === null;
    }

    protected static function separatedCountryCode(string $raw, string $e164): ?string
    {
        $afterPrefix = (string) preg_replace('/^\s*(\+|011|00)\s*/', '', $raw);
        $afterPrefix = ltrim((string) preg_replace('/^\(\s*0\s*\)/', '', $afterPrefix), "0 \t");

        if (preg_match('/^(\d{1,2})(?=\D)\D*\d/', $afterPrefix, $group) !== 1) {
            return null;
        }

        return str_starts_with(substr($e164, 1), $group[1]) ? $group[1] : null;
    }

    protected static function explicitForeignNumber(string $raw): ?string
    {
        if (preg_match('/^(\+|00|011)/', $raw) !== 1) {
            return null;
        }

        $e164 = \App\Services\SmsService::toE164($raw);

        return $e164 !== null && !str_starts_with($e164, '+1') ? $e164 : null;
    }

    protected static function looksNorthAmerican(string $e164): bool
    {
        return preg_match('/^\+[2-9]\d{2}[2-9]\d{6}$/', $e164) === 1;
    }

    public static function digitsFor(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }
}
