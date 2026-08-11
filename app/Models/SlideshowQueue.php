<?php

namespace App\Models;

use App\Support\OperatingDay;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlideshowQueue extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'company_id',
        'location_id',
        'operating_day',
        'status',
        'is_paused',
        'opened_at',
        'closed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'is_paused' => false,
    ];

    protected $casts = [
        'operating_day' => 'date',
        'is_paused' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'slideshow_queue_id');
    }

    public function visiblePhotos(): HasMany
    {
        return $this->photos()
            ->where('slideshow_eligible', true)
            ->where('slideshow_state', Photo::SLIDESHOW_VISIBLE)
            ->where('processing_status', Photo::PROCESSING_READY)
            ->whereNull('purged_at')
            ->orderByDesc('slideshow_priority')
            ->orderBy('captured_at');
    }

    public static function activeFor(Location $location, ?string $operatingDay = null): self
    {
        $day = $operatingDay ?: OperatingDay::forLocation($location);

        $queue = self::where('location_id', $location->id)
            ->whereDate('operating_day', $day)
            ->first();

        if ($queue) {
            return $queue;
        }

        return self::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'operating_day' => $day,
            'status' => self::STATUS_ACTIVE,
            'opened_at' => now(),
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
