<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaiverProfile extends Model
{
    use SoftDeletes;

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
