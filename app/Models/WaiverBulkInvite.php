<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WaiverBulkInvite extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'booking_id',
        'event_id',
        'waiver_template_id',
        'selected_date',
        'chaperone_name',
        'chaperone_email',
        'chaperone_phone',
        'manage_token',
        'shareable_token',
        'allow_shareable_link',
        'status',
        'created_by',
    ];

    protected $casts = [
        'selected_date' => 'date',
        'allow_shareable_link' => 'boolean',
    ];

    public const STATUS_NOT_SENT = 'not_sent';
    public const STATUS_SENT = 'sent';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WaiverBulkInvite $invite) {
            if (empty($invite->manage_token)) {
                $invite->manage_token = self::generateUniqueToken('manage_token');
            }
            if ($invite->allow_shareable_link && empty($invite->shareable_token)) {
                $invite->shareable_token = self::generateUniqueToken('shareable_token');
            }
        });
    }

    public static function generateUniqueToken(string $column): string
    {
        do {
            $token = Str::random(48);
        } while (self::where($column, $token)->exists());

        return $token;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplate::class, 'waiver_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WaiverInviteRecipient::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class, 'bulk_invite_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
