<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePushNotificationLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'notification_id',
        'mobile_push_device_id',
        'user_id',
        'expo_push_token',
        'status',
        'ticket_id',
        'receipt_status',
        'error_code',
        'error_message',
        'sent_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(MobilePushDevice::class, 'mobile_push_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markAsSent(?string $ticketId): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'ticket_id' => $ticketId,
            'sent_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public function markAsFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSent($query)
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
