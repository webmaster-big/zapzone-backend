<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Attraction;
use App\Models\DayOff;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class DayOffPresenter
{
    public function presentMany(iterable $dayOffs): array
    {
        $models = new EloquentCollection(collect($dayOffs)->all());

        if ($models->isEmpty()) {
            return [];
        }

        $models->loadMissing([
            'creator:id,first_name,last_name,email,role',
            'updater:id,first_name,last_name,email,role',
        ]);

        $names = $this->namesFor($models);

        return $models->map(fn (DayOff $dayOff) => $this->present($dayOff, $names))->values()->all();
    }

    public function presentOne(DayOff $dayOff): array
    {
        return $this->presentMany([$dayOff])[0];
    }

    public function windowLabel(DayOff $dayOff): string
    {
        if ($dayOff->isFullDay()) {
            return 'Closed all day';
        }

        if ($dayOff->isOperatingWindow()) {
            $opensAt = DayOff::clockToMinutes($dayOff->opens_at);
            $closesAt = $dayOff->closes_at !== null && $dayOff->closes_at !== '' ? DayOff::closingClockToMinutes($dayOff->closes_at) : null;

            if ($opensAt !== null && $closesAt !== null) {
                return 'Open only ' . $this->clock($opensAt) . ' – ' . $this->clock($closesAt)
                    . ($closesAt <= $opensAt ? ' (next morning)' : '');
            }

            if ($opensAt !== null) {
                return 'Opens late at ' . $this->clock($opensAt);
            }

            return 'Closes early at ' . $this->clock((int) $closesAt);
        }

        if ($dayOff->isCloseEarly()) {
            return 'Closed from ' . $this->clock((int) DayOff::clockToMinutes($dayOff->time_start));
        }

        if ($dayOff->isDelayedOpening()) {
            return 'Closed until ' . $this->clock((int) DayOff::clockToMinutes($dayOff->time_end));
        }

        $start = DayOff::clockToMinutes($dayOff->time_start);
        $end = DayOff::clockToMinutes($dayOff->time_end);
        $label = 'Closed ' . $this->clock((int) $start) . ' – ' . $this->clock((int) $end);

        return $dayOff->closedRangeMinutes() === null ? $label . ' (invalid range, blocks nothing)' : $label;
    }

    public function displayRanges(DayOff $dayOff): array
    {
        return collect($dayOff->blockedRanges())
            ->map(fn (array $range) => [
                'start_minutes' => max(0, $range[0]),
                'end_minutes' => min(DayOff::MINUTES_PER_DAY, $range[1]),
            ])
            ->filter(fn (array $range) => $range['end_minutes'] > $range['start_minutes'])
            ->values()
            ->all();
    }

    public function scopeKey(DayOff $dayOff): string
    {
        $targeted = array_filter([
            'packages' => ! empty($dayOff->package_ids),
            'rooms' => ! empty($dayOff->room_ids),
            'attractions' => ! empty($dayOff->attraction_ids),
            'events' => ! empty($dayOff->event_ids),
        ]);

        if ($targeted === []) {
            return 'location';
        }

        return count($targeted) === 1 ? array_key_first($targeted) : 'mixed';
    }

    private function present(DayOff $dayOff, array $names): array
    {
        return [
            'id' => $dayOff->id,
            'location_id' => (int) $dayOff->location_id,
            'location_name' => $names['locations'][(int) $dayOff->location_id] ?? null,
            'date' => $dayOff->date ? Carbon::parse($dayOff->date)->toDateString() : null,
            'is_recurring' => (bool) $dayOff->is_recurring,
            'block_type' => $dayOff->block_type,
            'opens_at' => DayOff::shortClock($dayOff->opens_at),
            'closes_at' => DayOff::shortClock($dayOff->closes_at),
            'time_start' => DayOff::shortClock($dayOff->time_start),
            'time_end' => DayOff::shortClock($dayOff->time_end),
            'window_label' => $this->windowLabel($dayOff),
            'blocked_ranges' => $this->displayRanges($dayOff),
            'scope' => $this->scopeKey($dayOff),
            'scope_label' => $this->scopeLabel($dayOff, $names),
            'package_ids' => DayOff::normalizedIds($dayOff->package_ids),
            'room_ids' => DayOff::normalizedIds($dayOff->room_ids),
            'attraction_ids' => DayOff::normalizedIds($dayOff->attraction_ids),
            'event_ids' => DayOff::normalizedIds($dayOff->event_ids),
            'reason' => $dayOff->reason,
            'group_name' => $dayOff->group_name,
            'set_by' => $this->person($dayOff->creator),
            'last_changed_by' => $this->person($dayOff->updater),
        ];
    }

    private function scopeLabel(DayOff $dayOff, array $names): string
    {
        if ($dayOff->isLocationWide()) {
            return 'Entire location';
        }

        $parts = [];

        foreach ([
            'package_ids' => ['packages', 'Package', 'Packages'],
            'room_ids' => ['rooms', 'Space', 'Spaces'],
            'attraction_ids' => ['attractions', 'Attraction', 'Attractions'],
            'event_ids' => ['events', 'Event', 'Events'],
        ] as $column => [$bucket, $singular, $plural]) {
            $ids = DayOff::normalizedIds($dayOff->{$column}) ?? [];

            if ($ids === []) {
                continue;
            }

            $labels = array_map(
                fn (int $id) => $names[$bucket][$id] ?? "{$singular} #{$id}",
                $ids
            );

            $parts[] = (count($ids) === 1 ? $singular : $plural) . ': ' . implode(', ', $labels);
        }

        return implode('; ', $parts);
    }

    private function namesFor(EloquentCollection $dayOffs): array
    {
        $ids = fn (string $column) => $dayOffs
            ->flatMap(fn (DayOff $dayOff) => DayOff::normalizedIds($dayOff->{$column}) ?? [])
            ->unique()
            ->values()
            ->all();

        $lookup = function (string $modelClass, array $ids): array {
            if ($ids === []) {
                return [];
            }

            return $modelClass::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
                ->all();
        };

        return [
            'locations' => $lookup(Location::class, $dayOffs->pluck('location_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all()),
            'packages' => $lookup(Package::class, $ids('package_ids')),
            'rooms' => $lookup(Room::class, $ids('room_ids')),
            'attractions' => $lookup(Attraction::class, $ids('attraction_ids')),
            'events' => $lookup(Event::class, $ids('event_ids')),
        ];
    }

    private function person(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => ActivityLog::describeActor($user),
            'role' => $user->role,
        ];
    }

    private function clock(int $minutes): string
    {
        if ($minutes >= DayOff::MINUTES_PER_DAY) {
            return 'midnight';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $period = $hours >= 12 ? 'PM' : 'AM';
        $twelveHour = $hours % 12 === 0 ? 12 : $hours % 12;

        return sprintf('%d:%02d %s', $twelveHour, $mins, $period);
    }
}
