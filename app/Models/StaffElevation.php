<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffElevation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'location_id',
        'capability',
        'requested_by_user_id',
        'approved_by_user_id',
        'staff_terminal_id',
        'target_type',
        'target_id',
        'token_hash',
        'uses_remaining',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'uses_remaining' => 'integer',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(StaffTerminal::class, 'staff_terminal_id');
    }
}
