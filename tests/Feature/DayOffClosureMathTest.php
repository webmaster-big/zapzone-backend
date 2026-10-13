<?php

namespace Tests\Feature;

use App\Models\DayOff;
use Carbon\Carbon;
use Tests\TestCase;

class DayOffClosureMathTest extends TestCase
{
    private function legacyIsTimeBlocked(?string $timeStart, ?string $timeEnd, string $slotStart, ?string $slotEnd): bool
    {
        if ($timeStart === null && $timeEnd === null) {
            return true;
        }

        $slotStartTime = Carbon::parse($slotStart);
        $slotEndTime = $slotEnd ? Carbon::parse($slotEnd) : null;

        if ($timeStart !== null && $timeEnd === null) {
            $closeTime = Carbon::parse($timeStart);
            if ($slotStartTime->gte($closeTime)) {
                return true;
            }

            return $slotEndTime && $slotEndTime->gt($closeTime);
        }

        if ($timeStart === null && $timeEnd !== null) {
            return $slotStartTime->lt(Carbon::parse($timeEnd));
        }

        $rangeStart = Carbon::parse($timeStart);
        $rangeEnd = Carbon::parse($timeEnd);

        if ($rangeEnd->lte($rangeStart)) {
            return false;
        }

        if ($slotEndTime) {
            return $slotStartTime->lt($rangeEnd) && $slotEndTime->gt($rangeStart);
        }

        return $slotStartTime->gte($rangeStart) && $slotStartTime->lt($rangeEnd);
    }

    private function clock(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public function test_older_closed_hours_rows_block_exactly_what_they_blocked_before(): void
    {
        $closures = [
            [null, null],
            ['09:00', null], ['12:00', null], ['15:30', null], ['23:45', null], ['00:00', null],
            [null, '09:00'], [null, '12:00'], [null, '15:30'], [null, '23:45'],
            ['12:00', '15:00'], ['09:15', '22:00'], ['15:45', '14:15'], ['12:00', '12:00'],
            ['12:00:00', '15:00:00'], [null, '16:00:00'],
        ];

        $compared = 0;

        foreach ($closures as [$timeStart, $timeEnd]) {
            $dayOff = new DayOff(['time_start' => $timeStart, 'time_end' => $timeEnd]);

            for ($start = 0; $start < 1440; $start += 15) {
                foreach ([null, 15, 60, 120, 240] as $duration) {
                    if ($duration !== null && $start + $duration >= 1440) {
                        continue;
                    }

                    $slotStart = $this->clock($start);
                    $slotEnd = $duration === null ? null : $this->clock($start + $duration);

                    $this->assertSame(
                        $this->legacyIsTimeBlocked($timeStart, $timeEnd, $slotStart, $slotEnd),
                        $dayOff->isTimeBlocked($slotStart, $slotEnd),
                        "closure {$timeStart}-{$timeEnd}, slot {$slotStart}-{$slotEnd}"
                    );
                    $compared++;
                }
            }
        }

        $this->assertGreaterThan(5000, $compared);
    }

    public function test_a_slot_running_past_midnight_is_caught_by_a_late_closure(): void
    {
        $closesAt2230 = new DayOff(['time_start' => '22:30']);
        $this->assertTrue($closesAt2230->isTimeBlocked('22:00', '00:30'));

        $window = new DayOff(['opens_at' => '18:00', 'closes_at' => '00:00']);
        $this->assertFalse($window->isTimeBlocked('22:00', '00:00'));
        $this->assertTrue($window->isTimeBlocked('23:00', '01:00'));
    }

    public function test_an_operating_window_blocks_only_outside_its_hours(): void
    {
        $window = new DayOff(['opens_at' => '12:00', 'closes_at' => '18:00']);

        $this->assertSame(DayOff::BLOCK_OPERATING_WINDOW, $window->block_type);
        $this->assertFalse($window->isFullDay());

        $this->assertTrue($window->isTimeBlocked('11:00', '13:00'));
        $this->assertFalse($window->isTimeBlocked('12:00', '14:00'));
        $this->assertFalse($window->isTimeBlocked('16:00', '18:00'));
        $this->assertTrue($window->isTimeBlocked('17:00', '19:00'));
        $this->assertTrue($window->isTimeBlocked('18:00'));
        $this->assertFalse($window->isTimeBlocked('17:59'));
        $this->assertTrue($window->isTimeBlocked('11:59'));
        $this->assertFalse($window->isTimeBlocked('12:00'));
        $this->assertTrue($window->isTimeBlocked('08:00', '10:00'));
        $this->assertTrue($window->isTimeBlocked('19:00', '20:00'));
    }

    public function test_a_window_with_only_one_end_set(): void
    {
        $opensLate = new DayOff(['opens_at' => '15:00']);
        $this->assertTrue($opensLate->isTimeBlocked('14:00', '16:00'));
        $this->assertFalse($opensLate->isTimeBlocked('15:00', '17:00'));
        $this->assertFalse($opensLate->isTimeBlocked('23:00'));

        $closesEarly = new DayOff(['closes_at' => '17:00']);
        $this->assertTrue($closesEarly->isTimeBlocked('16:00', '18:00'));
        $this->assertFalse($closesEarly->isTimeBlocked('15:00', '17:00'));
        $this->assertFalse($closesEarly->isTimeBlocked('09:00'));
        $this->assertTrue($closesEarly->isTimeBlocked('17:00'));
    }

    public function test_block_types(): void
    {
        $this->assertSame(DayOff::BLOCK_FULL_DAY, (new DayOff())->block_type);
        $this->assertSame(DayOff::BLOCK_LEGACY_CLOSURE, (new DayOff(['time_start' => '12:00']))->block_type);
        $this->assertSame(DayOff::BLOCK_OPERATING_WINDOW, (new DayOff(['closes_at' => '12:00']))->block_type);
        $this->assertTrue((new DayOff())->isTimeBlocked('10:00', '11:00'));
    }

    public function test_an_operating_window_is_handed_to_calendars_as_its_closed_halves(): void
    {
        $this->assertSame(
            [['time_start' => null, 'time_end' => '12:00'], ['time_start' => '18:00', 'time_end' => null]],
            (new DayOff(['opens_at' => '12:00:00', 'closes_at' => '18:00:00']))->asLegacyClosures()
        );

        $this->assertSame(
            [['time_start' => null, 'time_end' => '18:00']],
            (new DayOff(['opens_at' => '18:00', 'closes_at' => '00:00']))->asLegacyClosures()
        );

        $this->assertSame(
            [['time_start' => '17:00', 'time_end' => null]],
            (new DayOff(['closes_at' => '17:00']))->asLegacyClosures()
        );

        $this->assertSame(
            [['time_start' => null, 'time_end' => null]],
            (new DayOff())->asLegacyClosures()
        );

        $this->assertSame(
            [['time_start' => '12:00', 'time_end' => '15:00']],
            (new DayOff(['time_start' => '12:00:00', 'time_end' => '15:00:00']))->asLegacyClosures()
        );
    }

    public function test_the_closed_halves_block_the_same_minutes_as_the_window(): void
    {
        $windows = [['12:00', '18:00'], ['09:30', null], [null, '20:15'], ['18:00', '00:00'], ['00:15', '23:45']];

        foreach ($windows as [$opensAt, $closesAt]) {
            $window = new DayOff(['opens_at' => $opensAt, 'closes_at' => $closesAt]);
            $halves = array_map(fn (array $closure) => new DayOff($closure), $window->asLegacyClosures());

            for ($start = 0; $start < 1440; $start += 15) {
                foreach ([null, 30, 90] as $duration) {
                    if ($duration !== null && $start + $duration > 1440) {
                        continue;
                    }

                    $slotStart = $this->clock($start);
                    $slotEnd = $duration === null ? null : $this->clock($start + $duration);
                    $byHalves = collect($halves)->contains(fn (DayOff $half) => $half->isTimeBlocked($slotStart, $slotEnd));

                    $this->assertSame(
                        $window->isTimeBlocked($slotStart, $slotEnd),
                        $byHalves,
                        "window {$opensAt}-{$closesAt}, slot {$slotStart}-{$slotEnd}"
                    );
                }
            }
        }
    }

    public function test_slots_after_midnight_belong_to_the_night_before(): void
    {
        $this->assertSame(60, DayOff::overnightUntil('20:00', '01:00'));
        $this->assertSame(120, DayOff::overnightUntil('18:00:00', '02:00:00'));
        $this->assertNull(DayOff::overnightUntil('18:00', '00:00'));
        $this->assertNull(DayOff::overnightUntil('10:00', '18:00'));
        $this->assertNull(DayOff::overnightUntil(null, '01:00'));

        $closesAt10 = new DayOff(['closes_at' => '22:00']);
        $this->assertFalse($closesAt10->isTimeBlocked('00:00', '01:00'));
        $this->assertTrue($closesAt10->isTimeBlocked('00:00', '01:00', 60));
        $this->assertTrue($closesAt10->isTimeBlocked('00:30', null, 60));
        $this->assertFalse($closesAt10->isTimeBlocked('21:00', '22:00', 60));

        $opensAtNoon = new DayOff(['opens_at' => '12:00']);
        $this->assertTrue($opensAtNoon->isTimeBlocked('00:00', '01:00'));
        $this->assertFalse($opensAtNoon->isTimeBlocked('00:00', '01:00', 60));
        $this->assertTrue($opensAtNoon->isTimeBlocked('09:00', '11:00', 120));

        $legacyEarlyClose = new DayOff(['time_start' => '23:00']);
        $this->assertTrue($legacyEarlyClose->isTimeBlocked('00:00', '01:00', 60));
    }

    public function test_opening_and_closing_minutes_cover_both_formats(): void
    {
        $window = new DayOff(['opens_at' => '12:00:00', 'closes_at' => '00:00:00']);
        $this->assertSame(720, $window->opensAtMinutes());
        $this->assertSame(1440, $window->closesAtMinutes());
        $this->assertNull($window->closedRangeMinutes());

        $delayed = new DayOff(['time_end' => '15:00:00']);
        $this->assertSame(900, $delayed->opensAtMinutes());
        $this->assertNull($delayed->closesAtMinutes());

        $early = new DayOff(['time_start' => '16:00']);
        $this->assertSame(960, $early->closesAtMinutes());

        $range = new DayOff(['time_start' => '12:00', 'time_end' => '15:00']);
        $this->assertSame([720, 900], $range->closedRangeMinutes());
        $this->assertNull((new DayOff(['time_start' => '15:45', 'time_end' => '14:15']))->closedRangeMinutes());
    }
}
