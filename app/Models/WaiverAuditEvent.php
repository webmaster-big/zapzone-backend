<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'waiver_id',
        'event',
        'occurred_at',
        'meta',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'meta' => 'array',
    ];

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }
}
