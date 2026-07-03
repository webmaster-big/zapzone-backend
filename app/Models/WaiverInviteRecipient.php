<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WaiverInviteRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'waiver_bulk_invite_id',
        'name',
        'email',
        'phone',
        'status',
        'invite_token',
        'waiver_id',
        'resent_count',
        'last_sent_at',
    ];

    protected $casts = [
        'resent_count' => 'integer',
        'last_sent_at' => 'datetime',
    ];

    public const STATUS_NOT_SENT = 'not_sent';
    public const STATUS_SENT = 'sent';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_NOT_COMPLETE = 'not_complete';
    public const STATUS_FAILED = 'failed';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WaiverInviteRecipient $recipient) {
            if (empty($recipient->invite_token)) {
                $recipient->invite_token = self::generateUniqueToken();
            }
        });
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('invite_token', $token)->exists());

        return $token;
    }

    public function bulkInvite(): BelongsTo
    {
        return $this->belongsTo(WaiverBulkInvite::class, 'waiver_bulk_invite_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }
}
