<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id', 'user_id', 'type', 'content', 'pinned', 'visibility',
    ];

    protected $casts = [
        'pinned' => 'boolean',
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
