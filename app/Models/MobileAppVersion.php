<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MobileAppVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'latest_version',
        'minimum_version',
        'force_update',
        'apk_url',
        'update_message',
        'release_notes',
        'is_active',
    ];

    protected $casts = [
        'force_update' => 'boolean',
        'release_notes' => 'array',
        'is_active' => 'boolean',
    ];
}
