<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id', 'user_id', 'customer_id',
        'action', 'actor_type', 'before', 'after', 'note',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
