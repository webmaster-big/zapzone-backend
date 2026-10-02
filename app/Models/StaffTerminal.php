<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffTerminal extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'device_id',
        'label',
        'enrolled_by_user_id',
        'ceiling_role',
        'idle_seconds',
        'idle_disabled',
        'elevated_idle_seconds',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'idle_disabled' => 'boolean',
            'idle_seconds' => 'integer',
            'elevated_idle_seconds' => 'integer',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function effectiveIdleSeconds(): ?int
    {
        if ($this->idle_disabled) {
            return null;
        }

        return $this->idle_seconds ?? (int) config('staff_pins.idle.default_seconds');
    }

    public function effectiveElevatedIdleSeconds(): int
    {
        $configured = $this->elevated_idle_seconds ?? (int) config('staff_pins.idle.elevated_seconds');
        $max = (int) config('staff_pins.idle.elevated_max_seconds');

        return max(1, min($configured, $max));
    }
}
