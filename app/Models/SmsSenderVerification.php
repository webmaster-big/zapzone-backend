<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSenderVerification extends Model
{
    public const METHOD_STAFF_CONFIRMED = 'staff_confirmed';
    public const METHOD_GUEST_OPENED_LINK = 'guest_opened_link';
    public const METHOD_RESET = 'reset';

    protected $fillable = [
        'company_id',
        'location_id',
        'sender',
        'photo_delivery_id',
        'provider_sid',
        'method',
        'confirmed_by',
        'confirmed_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeActiveFor($query, int $companyId, string $sender)
    {
        return $query->where('company_id', $companyId)
            ->where('sender', $sender)
            ->whereNull('revoked_at');
    }
}
