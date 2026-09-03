<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverProfileDependent extends Model
{
    protected $fillable = [
        'waiver_profile_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'relationship',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(WaiverProfile::class, 'waiver_profile_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function matches(?string $first, ?string $last, ?string $dob): bool
    {
        $norm = fn (?string $v) => mb_strtolower(trim((string) $v));

        return $norm($this->first_name) === $norm($first)
            && $norm($this->last_name) === $norm($last)
            && (string) $this->date_of_birth?->toDateString() === (string) ($dob ? substr($dob, 0, 10) : '');
    }
}
