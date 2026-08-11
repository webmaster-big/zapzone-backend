<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    use HasFactory;

    public const SOURCE_CAMERA = 'camera';
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_KIOSK = 'kiosk';

    public const PROCESSING_PENDING = 'pending';
    public const PROCESSING_RUNNING = 'processing';
    public const PROCESSING_READY = 'ready';
    public const PROCESSING_FAILED = 'failed';

    public const SLIDESHOW_VISIBLE = 'visible';
    public const SLIDESHOW_HIDDEN = 'hidden';
    public const SLIDESHOW_REMOVED = 'removed';

    protected $fillable = [
        'photo_session_id',
        'company_id',
        'location_id',
        'slideshow_queue_id',
        'photo_overlay_id',
        'position',
        'source',
        'processing_status',
        'processing_error',
        'original_path',
        'delivery_path',
        'slideshow_path',
        'thumbnail_path',
        'width',
        'height',
        'bytes',
        'slideshow_eligible',
        'slideshow_state',
        'slideshow_priority',
        'captured_at',
        'capture_date',
        'operating_day',
        'download_count',
        'purged_at',
    ];

    protected $attributes = [
        'position' => 0,
        'processing_status' => self::PROCESSING_PENDING,
        'slideshow_eligible' => false,
        'slideshow_state' => self::SLIDESHOW_VISIBLE,
        'slideshow_priority' => 0,
        'download_count' => 0,
    ];

    protected $casts = [
        'position' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
        'slideshow_eligible' => 'boolean',
        'slideshow_priority' => 'integer',
        'captured_at' => 'datetime',
        'capture_date' => 'date',
        'operating_day' => 'date',
        'download_count' => 'integer',
        'purged_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoSession::class, 'photo_session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function overlay(): BelongsTo
    {
        return $this->belongsTo(PhotoOverlay::class, 'photo_overlay_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(SlideshowQueue::class, 'slideshow_queue_id');
    }

    public function isReady(): bool
    {
        return $this->processing_status === self::PROCESSING_READY && $this->purged_at === null;
    }

    public function showsInSlideshow(): bool
    {
        return $this->slideshow_eligible
            && $this->slideshow_state === self::SLIDESHOW_VISIBLE
            && $this->isReady();
    }

    public function pathForVariant(string $variant): ?string
    {
        return match ($variant) {
            'delivery' => $this->delivery_path,
            'slideshow' => $this->slideshow_path ?: $this->delivery_path,
            'thumb' => $this->thumbnail_path,
            default => null,
        };
    }

    /**
     * Delete the image files and mark the row purged, keeping the row itself so the
     * delivery record and activity trail survive. Used both by a manual delete from the
     * daily library and by the retention job, so the two can never drift apart.
     *
     * Once purged, the customer page lists no photos and every signed media URL 404s.
     */
    public function purge(): void
    {
        $this->deleteMedia();

        $this->update([
            'purged_at' => now(),
            'original_path' => null,
            'delivery_path' => null,
            'slideshow_path' => null,
            'thumbnail_path' => null,
            'slideshow_eligible' => false,
            'slideshow_state' => self::SLIDESHOW_REMOVED,
            'slideshow_queue_id' => null,
        ]);
    }

    public function deleteMedia(): void
    {
        $disk = Storage::disk(\App\Services\PhotoProcessingService::DISK);

        foreach ([$this->original_path, $this->delivery_path, $this->slideshow_path, $this->thumbnail_path] as $path) {
            if ($path && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    public function scopeLive($query)
    {
        return $query->whereNull('purged_at');
    }

    public function scopeReady($query)
    {
        return $query->where('processing_status', self::PROCESSING_READY)->whereNull('purged_at');
    }
}
