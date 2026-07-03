<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverMinor extends Model
{
    use HasFactory;

    protected $fillable = [
        'waiver_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'relationship',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
