<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'email_template_id',
        'sent_by',
        'name',
        'subject',
        'body',
        'recipient_types',
        'custom_emails',
        'recipient_filters',
        'attachments',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
        'scheduled_at',
        'sent_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'recipient_types' => 'array',
        'custom_emails' => 'array',
        'recipient_filters' => 'array',
        'attachments' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Recipient type constants
     */
    public const RECIPIENT_TYPE_CUSTOMERS = 'customers';
    public const RECIPIENT_TYPE_ATTENDANTS = 'attendants';
    public const RECIPIENT_TYPE_COMPANY_ADMIN = 'company_admin';
    public const RECIPIENT_TYPE_LOCATION_MANAGERS = 'location_managers';
    public const RECIPIENT_TYPE_CUSTOM = 'custom';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EmailCampaignLog::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSending($query)
    {
        return $query->where('status', self::STATUS_SENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeScheduled($query)
    {
        return $query->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now())
            ->where('status', self::STATUS_PENDING);
    }

    public function scopeReadyToSend($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            });
    }

    /**
     * Mark campaign as sending
     */
    public function markAsSending(): void
    {
        $this->update([
            'status' => self::STATUS_SENDING,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark campaign as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark campaign as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    /**
     * Increment sent count
     */
    public function incrementSent(): void
    {
        $this->increment('sent_count');
    }

    /**
     * Increment failed count
     */
    public function incrementFailed(): void
    {
        $this->increment('failed_count');
    }

    /**
     * Get success rate
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_recipients === 0) {
            return 0;
        }
        return round(($this->sent_count / $this->total_recipients) * 100, 2);
    }
}
