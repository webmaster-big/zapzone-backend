<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverAdSend extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    protected $fillable = [
        'waiver_template_ad_id',
        'waiver_id',
        'company_id',
        'location_id',
        'channel',
        'destination',
        'status',
        'attempts',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function ad(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplateAd::class, 'waiver_template_ad_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }
}
