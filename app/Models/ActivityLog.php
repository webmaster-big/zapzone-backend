<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_name',
        'actor_role',
        'location_id',
        'action',
        'category',
        'entity_type',
        'entity_id',
        'description',
        'ip_address',
        'user_agent',
        'metadata',
        'reason',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Activity logs are an append-only audit trail: once written a row must never change or
     * disappear. Nothing in the app updates or deletes one today, and these guards keep it that
     * way if future code tries. Routes already expose only index/store/show.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Activity logs are append-only and cannot be edited.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Activity logs are append-only and cannot be deleted.');
        });

        static::creating(function (self $log) {
            if ($log->actor_name === null || $log->actor_role === null) {
                $actor = $log->user_id ? User::find($log->user_id) : auth()->user();
                if ($actor instanceof User) {
                    $log->actor_name = $log->actor_name ?? self::describeActor($actor);
                    $log->actor_role = $log->actor_role ?? $actor->role;
                }
            }
        });
    }

    public static function describeActor(User $actor): string
    {
        // `??` is not enough here: these attributes come back as EMPTY STRINGS rather than null on
        // some rows, which would silently store a blank employee name.
        foreach ([
            trim(implode(' ', array_filter([$actor->first_name ?? null, $actor->last_name ?? null]))),
            $actor->name ?? null,
            $actor->email ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Unknown employee';
    }

    /**
     * The employee name as it should appear in the log, preferring the snapshot taken at write
     * time so a since-deleted employee is still attributable.
     */
    public function getEmployeeNameAttribute(): string
    {
        if (! empty($this->actor_name)) {
            return $this->actor_name;
        }

        if ($this->user instanceof User) {
            return self::describeActor($this->user);
        }

        return 'Deleted employee';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }


    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByEntity($query, $entityType, $entityId = null)
    {
        $query->where('entity_type', $entityType);

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        return $query;
    }

    public function scopeByAction($query, $action)
    {
        return $query->where('action', 'like', "%{$action}%");
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public static function log(
        string $action,
        string $category,
        string $description,
        ?int $userId = null,
        ?int $locationId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?string $reason = null
    ): self {
        return self::create([
            'user_id' => $userId ?? auth()->user()?->getKey(),
            'location_id' => $locationId,
            'action' => $action,
            'category' => $category,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata,
            'reason' => $reason,
        ]);
    }
}
