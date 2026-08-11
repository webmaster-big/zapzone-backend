<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PhotoOverlay extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'image_path',
        'starts_at',
        'ends_at',
        'is_enabled',
        'priority',
        'created_by',
    ];

    protected $attributes = [
        'is_enabled' => true,
        'priority' => 0,
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_enabled' => 'boolean',
        'priority' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActiveAt(?\Illuminate\Support\Carbon $at = null): bool
    {
        if (!$this->is_enabled) {
            return false;
        }

        $at = $at ?: now();

        if ($this->starts_at && $this->starts_at->greaterThan($at)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lessThan($at)) {
            return false;
        }

        return true;
    }

    public function status(?\Illuminate\Support\Carbon $at = null): string
    {
        if (!$this->is_enabled) {
            return self::STATUS_DISABLED;
        }

        $at = $at ?: now();

        if ($this->starts_at && $this->starts_at->greaterThan($at)) {
            return self::STATUS_SCHEDULED;
        }
        if ($this->ends_at && $this->ends_at->lessThan($at)) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    public function deleteMedia(): void
    {
        if ($this->image_path && Storage::disk('public')->exists($this->image_path)) {
            Storage::disk('public')->delete($this->image_path);
        }
    }

    public function scopeCandidatesFor($query, int $locationId, ?\Illuminate\Support\Carbon $at = null)
    {
        $at = $at ?: now();

        return $query->where('location_id', $locationId)
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }
}
