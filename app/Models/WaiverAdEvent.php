<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverAdEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'waiver_template_ad_id',
        'waiver_id',
        'company_id',
        'location_id',
        'event',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplateAd::class, 'waiver_template_ad_id');
    }
}
