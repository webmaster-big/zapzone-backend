<?php

namespace App\Services\Schedule;

use App\Models\DayOff;
use App\Models\Package;
use App\Models\Room;
use Carbon\Carbon;

class ScheduleDayWindow
{
    public const MINUTES_PER_DAY = 1440;

    public const FALLBACK_OPEN = 600;

    public const FALLBACK_CLOSE = 1320;

    public const FALLBACK_INTERVAL = 30;

    public function forDate(?int $locationId, string $date): array
    {
        $day = Carbon::parse($date)->toDateString();

        $packages = Package::query()
            ->select(['id', 'location_id', 'name', 'duration', 'duration_unit', 'is_active'])
            ->where('is_active', true)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->with([
                'availabilitySchedules:id,package_id,availability_type,day_configuration,time_slot_start,time_slot_end,time_slot_interval,priority,is_active',
                'rooms:id,name',
            ])
            ->get();

        $rooms = Room::query()
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->get(['id', 'location_id', 'name', 'is_available']);

        $locationClosed = $locationId !== null && DayOff::isDateBlocked($locationId, $day);

        $dayOffs = DayOff::query()
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->forDate($day)
            ->get();

        $packageWindows = [];
        $roomWindows = [];
        $open = null;
        $close = null;
        $interval = null;

        foreach ($packages as $package) {
            $schedule = $package->availabilitySchedules
                ->where('is_active', true)
                ->filter(fn ($candidate) => $candidate->matchesDate($day))
                ->sortByDesc('priority')
                ->first();

            if (! $schedule) {
                continue;
            }

            $window = $this->normalizeWindow($schedule->time_slot_start, $schedule->time_slot_end);

            if ($window === null) {
                continue;
            }

            [$startMinutes, $endMinutes] = $window;

            $packageClosedRanges = [];
            $packageClosedAllDay = $locationClosed;

            foreach ($dayOffs as $dayOff) {
                if ((int) $dayOff->location_id !== (int) $package->location_id) {
                    continue;
                }

                if (! $dayOff->appliesToPackage((int) $package->id)) {
                    continue;
                }

                if ($dayOff->isFullDay()) {
                    $packageClosedAllDay = true;

                    continue;
                }

                if ($dayOff->isCloseEarly()) {
                    $closesAt = $this->alignToWindow($this->toMinutes($dayOff->time_start), $startMinutes, $endMinutes);
                    if ($closesAt !== null && $closesAt < $endMinutes) {
                        $endMinutes = max($startMinutes, $closesAt);
                    }
                } elseif ($dayOff->isDelayedOpening()) {
                    $opensAt = $this->alignToWindow($this->toMinutes($dayOff->time_end), $startMinutes, $endMinutes);
                    if ($opensAt !== null && $opensAt > $startMinutes) {
                        $startMinutes = min($endMinutes, $opensAt);
                    }
                } elseif ($dayOff->isTimeRange()) {
                    $from = $this->alignToWindow($this->toMinutes($dayOff->time_start), $startMinutes, $endMinutes);
                    $to = $this->alignToWindow($this->toMinutes($dayOff->time_end), $startMinutes, $endMinutes);
                    if ($from !== null && $to !== null && $to > $from) {
                        $packageClosedRanges[] = [
                            'start_minutes' => $from,
                            'end_minutes' => $to,
                            'reason' => $dayOff->reason ?: 'Closed',
                        ];
                    }
                }
            }

            if ($packageClosedAllDay || $endMinutes <= $startMinutes) {
                continue;
            }

            $packageWindows[] = [
                'package_id' => (int) $package->id,
                'name' => $package->name,
                'location_id' => (int) $package->location_id,
                'open_minutes' => $startMinutes,
                'close_minutes' => $endMinutes,
                'interval_minutes' => (int) ($schedule->time_slot_interval ?: self::FALLBACK_INTERVAL),
                'closed_ranges' => $packageClosedRanges,
                'room_ids' => $package->rooms->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ];

            $open = $open === null ? $startMinutes : min($open, $startMinutes);
            $close = $close === null ? $endMinutes : max($close, $endMinutes);

            $scheduleInterval = (int) ($schedule->time_slot_interval ?: 0);
            if ($scheduleInterval > 0) {
                $interval = $interval === null ? $scheduleInterval : min($interval, $scheduleInterval);
            }

            foreach ($package->rooms as $room) {
                $roomId = (int) $room->id;
                $existing = $roomWindows[$roomId] ?? null;
                $roomWindows[$roomId] = [
                    'open_minutes' => $existing ? min($existing['open_minutes'], $startMinutes) : $startMinutes,
                    'close_minutes' => $existing ? max($existing['close_minutes'], $endMinutes) : $endMinutes,
                ];
            }
        }

        $roomPayload = [];
        foreach ($rooms as $room) {
            $window = $roomWindows[(int) $room->id] ?? null;
            $roomOpen = $window['open_minutes'] ?? null;
            $roomClose = $window['close_minutes'] ?? null;
            $roomClosedRanges = [];
            $closureReason = null;
            $roomClosedAllDay = $locationClosed || ! $room->is_available;

            foreach ($dayOffs as $dayOff) {
                if ((int) $dayOff->location_id !== (int) $room->location_id) {
                    continue;
                }

                if (! $dayOff->appliesToRoom((int) $room->id)) {
                    continue;
                }

                if ($dayOff->isFullDay()) {
                    $roomClosedAllDay = true;
                    $closureReason = $dayOff->reason ?: 'Closed';

                    continue;
                }

                if ($roomOpen === null || $roomClose === null) {
                    continue;
                }

                if ($dayOff->isCloseEarly()) {
                    $roomClosesAt = $this->alignToWindow($this->toMinutes($dayOff->time_start), $roomOpen, $roomClose);
                    if ($roomClosesAt !== null && $roomClosesAt < $roomClose) {
                        $roomClose = max($roomOpen, $roomClosesAt);
                        $closureReason ??= $dayOff->reason ?: 'Closes early';
                    }
                } elseif ($dayOff->isDelayedOpening()) {
                    $roomOpensAt = $this->alignToWindow($this->toMinutes($dayOff->time_end), $roomOpen, $roomClose);
                    if ($roomOpensAt !== null && $roomOpensAt > $roomOpen) {
                        $roomOpen = min($roomClose, $roomOpensAt);
                        $closureReason ??= $dayOff->reason ?: 'Opens late';
                    }
                } elseif ($dayOff->isTimeRange()) {
                    $from = $this->alignToWindow($this->toMinutes($dayOff->time_start), $roomOpen, $roomClose);
                    $to = $this->alignToWindow($this->toMinutes($dayOff->time_end), $roomOpen, $roomClose);
                    if ($from !== null && $to !== null && $to > $from) {
                        $roomClosedRanges[] = [
                            'start_minutes' => $from,
                            'end_minutes' => $to,
                            'reason' => $dayOff->reason ?: 'Closed',
                        ];
                    }
                }
            }

            if ($roomOpen !== null && $roomClose !== null && $roomClose <= $roomOpen) {
                $roomClosedAllDay = true;
                $closureReason ??= 'Closed';
            }

            $roomPayload[] = [
                'room_id' => (int) $room->id,
                'location_id' => (int) $room->location_id,
                'open_minutes' => $roomClosedAllDay ? ($window['open_minutes'] ?? null) : $roomOpen,
                'close_minutes' => $roomClosedAllDay ? ($window['close_minutes'] ?? null) : $roomClose,
                'closed_all_day' => (bool) $roomClosedAllDay,
                'closed_ranges' => $roomClosedRanges,
                'bookable' => ! $roomClosedAllDay && $roomOpen !== null && $roomClose !== null,
                'reason' => $closureReason ?? $this->closureReason($locationClosed, $room, $window !== null),
            ];
        }

        $hasWindow = $open !== null && $close !== null && $close > $open;

        return [
            'date' => $day,
            'weekday' => strtolower(Carbon::parse($day)->format('l')),
            'location_id' => $locationId,
            'open_minutes' => $hasWindow ? $open : self::FALLBACK_OPEN,
            'close_minutes' => $hasWindow ? $close : self::FALLBACK_CLOSE,
            'interval_minutes' => $interval ?: self::FALLBACK_INTERVAL,
            'has_schedule' => $hasWindow,
            'location_closed' => $locationClosed,
            'rooms' => $roomPayload,
            'packages' => $packageWindows,
        ];
    }

    private function normalizeWindow(?string $start, ?string $end): ?array
    {
        $startMinutes = $this->toMinutes($start);
        $endMinutes = $this->toMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            return null;
        }

        if ($endMinutes <= $startMinutes) {
            $endMinutes += self::MINUTES_PER_DAY;
        }

        return [$startMinutes, $endMinutes];
    }

    private function toMinutes(?string $clock): ?int
    {
        if (! $clock) {
            return null;
        }

        $parts = explode(':', $clock);

        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return ((int) $parts[0]) * 60 + (int) $parts[1];
    }

    private function alignToWindow(?int $minutes, ?int $windowOpen, ?int $windowClose): ?int
    {
        if ($minutes === null || $windowOpen === null || $windowClose === null) {
            return $minutes;
        }

        if ($windowClose > self::MINUTES_PER_DAY && $minutes < $windowOpen) {
            return $minutes + self::MINUTES_PER_DAY;
        }

        return $minutes;
    }

    private function toClock(int $minutes): string
    {
        $wrapped = $minutes % self::MINUTES_PER_DAY;

        return sprintf('%02d:%02d', intdiv($wrapped, 60), $wrapped % 60);
    }

    private function closureReason(bool $locationClosed, Room $room, bool $hasWindow): ?string
    {
        if ($locationClosed) {
            return 'Location closed';
        }

        if (! $room->is_available) {
            return 'Space unavailable';
        }

        if (! $hasWindow) {
            return 'No package scheduled';
        }

        return null;
    }
}
