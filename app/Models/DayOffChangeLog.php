<?php

namespace App\Models;

use App\Http\Middleware\EnsureStaff;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DayOffChangeLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_BOOKED_INSIDE = 'booked_inside_block';
    public const ACTION_BOOKING_REMOVED = 'booking_removed';

    protected $fillable = [
        'day_off_id',
        'location_id',
        'previous_location_id',
        'company_id',
        'action',
        'changed_by',
        'changed_by_name',
        'changed_by_email',
        'changed_by_role',
        'summary',
        'before',
        'after',
        'changed_fields',
        'context',
        'ip_address',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'changed_fields' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Availability change records are permanent and cannot be edited.');
        });

        static::deleting(function () {
            throw new RuntimeException('Availability change records are permanent and cannot be deleted.');
        });
    }

    public static function recordLocationRemoval(array $locationIds, string $because, ?User $actor, ?string $ipAddress = null, array $context = ['location_deleted' => true]): void
    {
        if ($locationIds === [] || ! DayOff::tracksAvailabilityDetails()) {
            return;
        }

        DayOff::whereIn('location_id', $locationIds)
            ->orderBy('id')
            ->get()
            ->each(function (DayOff $dayOff) use ($because, $actor, $ipAddress, $context) {
                $when = Carbon::parse($dayOff->date)->format('D, M j, Y') . ($dayOff->is_recurring ? ' (every year)' : '');
                $group = $dayOff->group_name ? " for {$dayOff->group_name}" : '';

                self::record(
                    self::ACTION_DELETED,
                    $dayOff,
                    $actor,
                    "Removed the block on {$when}{$group} because {$because}",
                    $dayOff->snapshot(),
                    null,
                    $context,
                    $ipAddress,
                );
            });
    }

    public static function record(
        string $action,
        ?DayOff $dayOff,
        ?User $actor,
        string $summary,
        ?array $before = null,
        ?array $after = null,
        array $context = [],
        ?string $ipAddress = null,
    ): self {
        $changedFields = null;

        if ($before !== null && $after !== null) {
            $changedFields = array_values(array_filter(
                array_unique(array_merge(array_keys($before), array_keys($after))),
                fn ($key) => ($before[$key] ?? null) != ($after[$key] ?? null)
            ));
        }

        $locationId = $dayOff?->location_id ?? ($after['location_id'] ?? $before['location_id'] ?? null);
        $previousLocationId = $before['location_id'] ?? null;

        return self::create([
            'day_off_id' => $dayOff?->id ?? ($after['id'] ?? $before['id'] ?? null),
            'location_id' => $locationId,
            'previous_location_id' => $previousLocationId !== null && (int) $previousLocationId !== (int) $locationId ? (int) $previousLocationId : null,
            'company_id' => $locationId !== null ? Location::query()->whereKey($locationId)->value('company_id') : null,
            'action' => $action,
            'changed_by' => $actor?->id,
            'changed_by_name' => $actor ? ActivityLog::describeActor($actor) : null,
            'changed_by_email' => $actor?->email,
            'changed_by_role' => $actor ? EnsureStaff::effectiveRole($actor) : null,
            'summary' => mb_substr($summary, 0, 500),
            'before' => $before,
            'after' => $after,
            'changed_fields' => $changedFields,
            'context' => $context === [] ? null : $context,
            'ip_address' => $ipAddress,
        ]);
    }
}
