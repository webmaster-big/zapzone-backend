<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageLinkUrl extends Model
{
    protected $fillable = [
        'message_link_id',
        'location_id',
        'url',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(MessageLink::class, 'message_link_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
