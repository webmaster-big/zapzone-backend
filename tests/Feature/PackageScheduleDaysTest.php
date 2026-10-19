<?php

namespace Tests\Feature;

use App\Models\PackageAvailabilitySchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageScheduleDaysTest extends TestCase
{
    public static function monthlyDates(): array
    {
        return [
            'first sunday' => ['first-sunday', '2026-10-04', true],
            'first sunday is not the second' => ['first-sunday', '2026-10-11', false],
            'second sunday' => ['second-sunday', '2026-10-11', true],
            'third wednesday' => ['third-wednesday', '2026-10-21', true],
            'third wednesday is not the second' => ['third-wednesday', '2026-10-14', false],
            'fourth sunday' => ['fourth-sunday', '2026-10-25', true],
            'last sunday with six days left' => ['last-sunday', '2026-10-25', true],
            'last saturday on the last day' => ['last-saturday', '2026-10-31', true],
            'a week before the last saturday' => ['last-saturday', '2026-10-24', false],
            'last tuesday with six days left' => ['last-tuesday', '2026-11-24', true],
            'first saturday on the third' => ['first-saturday', '2026-10-03', true],
            'second saturday is not the first' => ['first-saturday', '2026-10-10', false],
            'there is no fifth saturday rule' => ['fourth-saturday', '2026-10-31', false],
            'the wrong order never matches' => ['sunday-first', '2026-10-04', false],
            'mixed case still matches' => ['First-Sunday', '2026-10-04', true],
            'a bare day name is not a monthly day' => ['sunday', '2026-10-04', false],
            'a wrong weekday never matches' => ['first-monday', '2026-10-04', false],
        ];
    }

    #[DataProvider('monthlyDates')]
    public function test_monthly_days_match_the_same_dates_the_booking_pages_offer(string $pattern, string $date, bool $expected): void
    {
        $schedule = new PackageAvailabilitySchedule(['availability_type' => 'monthly', 'day_configuration' => [$pattern]]);

        $this->assertSame($expected, $schedule->matchesDate($date));
    }

    public function test_malformed_stored_days_are_ignored_instead_of_crashing(): void
    {
        $monthly = new PackageAvailabilitySchedule(['availability_type' => 'monthly', 'day_configuration' => [5, ['x'], 'first-sunday']]);
        $weekly = new PackageAvailabilitySchedule(['availability_type' => 'weekly', 'day_configuration' => [5, ['x'], 'Sunday']]);

        $this->assertTrue($monthly->matchesDate('2026-10-04'));
        $this->assertFalse($monthly->matchesDate('2026-10-11'));
        $this->assertTrue($weekly->matchesDate('2026-10-04'));
        $this->assertFalse($weekly->matchesDate('2026-10-05'));
    }
}
