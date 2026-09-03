<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class WaiverTemplateAd extends Model
{
    protected $fillable = [
        'company_id',
        'waiver_template_id',
        'location_id',
        'name',
        'image_path',
        'destination_url',
        'is_enabled',
        'is_fallback',
        'starts_at',
        'ends_at',
        'position',
        'created_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_fallback' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'position' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaiverTemplate::class, 'waiver_template_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WaiverAdEvent::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(WaiverAdSend::class);
    }

    public function scopeCandidatesFor(Builder $query, int $templateId, ?int $locationId, ?\DateTimeInterface $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('waiver_template_id', $templateId)
            ->where('is_enabled', true)
            ->where(function ($q) use ($locationId) {
                $q->whereNull('location_id');
                if ($locationId) {
                    $q->orWhere('location_id', $locationId);
                }
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }

    public function isActiveAt(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        if (!$this->is_enabled) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->gt($at)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->lt($at)) {
            return false;
        }

        return true;
    }

    public function status(): string
    {
        if (!$this->is_enabled) {
            return 'disabled';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function deleteMedia(): void
    {
        if ($this->image_path && Storage::disk('public')->exists($this->image_path)) {
            Storage::disk('public')->delete($this->image_path);
        }
    }
}
