# Package Availability Schedules System

## Overview

This system allows packages to have different time slot configurations based on availability types (daily, weekly, monthly) and specific day configurations.

## Database Structure

### Table: `package_availability_schedules`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `package_id` | bigint | Foreign key to packages table |
| `availability_type` | enum | Type: `daily`, `weekly`, `monthly` |
| `day_configuration` | string | Day specification (see examples below) |
| `time_slot_start` | time | Start time (e.g., '15:00') |
| `time_slot_end` | time | End time (e.g., '23:00') |
| `time_slot_interval` | integer | Interval in minutes (default: 30) |
| `priority` | integer | Priority when multiple schedules match (higher = preferred) |
| `is_active` | boolean | Active status |
| `created_at` | timestamp | Creation timestamp |
| `updated_at` | timestamp | Update timestamp |

## Day Configuration Format

### Daily
- `day_configuration`: `null` (applies to all days)
- Example: Open every day with the same time slots

### Weekly
- `day_configuration`: Day name in lowercase
- Examples: `'monday'`, `'tuesday'`, `'friday'`, etc.

### Monthly
- `day_configuration`: Pattern `{occurrence}-{day}`
- Occurrences: `'first'`, `'second'`, `'third'`, `'fourth'`, `'last'`
- Examples:
  - `'last-sunday'` - Last Sunday of every month
  - `'first-monday'` - First Monday of every month
  - `'third-friday'` - Third Friday of every month

## Usage Examples

### Example 1: Multiple Time Slots for Different Days

```php
// Package #1: Kids Party Package
$package = Package::find(1);

// Schedule 1: Last Sunday of each month (3pm to 12am)
$package->availabilitySchedules()->create([
    'availability_type' => 'monthly',
    'day_configuration' => 'last-sunday',
    'time_slot_start' => '15:00',
    'time_slot_end' => '00:00',
    'time_slot_interval' => 30,
    'priority' => 10,
    'is_active' => true,
]);

// Schedule 2: Every Monday (12pm to 8pm)
$package->availabilitySchedules()->create([
    'availability_type' => 'weekly',
    'day_configuration' => 'monday',
    'time_slot_start' => '12:00',
    'time_slot_end' => '20:00',
    'time_slot_interval' => 60,
    'priority' => 5,
    'is_active' => true,
]);

// Schedule 3: Every Friday (5pm to 11pm)
$package->availabilitySchedules()->create([
    'availability_type' => 'weekly',
    'day_configuration' => 'friday',
    'time_slot_start' => '17:00',
    'time_slot_end' => '23:00',
    'time_slot_interval' => 30,
    'priority' => 8,
    'is_active' => true,
]);
```

### Example 2: Get Time Slots for a Specific Date

```php
$package = Package::find(1);

// Get time slots for December 25, 2025 (if it's a Monday)
$timeSlots = $package->getTimeSlotsForDate('2025-12-25');

// Returns: ['12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00']
```

### Example 3: Creating Schedules in Controller

```php
// In PackageController or similar

public function updateAvailabilitySchedules(Request $request, Package $package)
{
    $validated = $request->validate([
        'schedules' => 'required|array',
        'schedules.*.availability_type' => 'required|in:daily,weekly,monthly',
        'schedules.*.day_configuration' => 'nullable|string',
        'schedules.*.time_slot_start' => 'required|date_format:H:i',
        'schedules.*.time_slot_end' => 'required|date_format:H:i',
        'schedules.*.time_slot_interval' => 'required|integer|min:15',
        'schedules.*.priority' => 'nullable|integer',
    ]);

    // Delete existing schedules
    $package->availabilitySchedules()->delete();

    // Create new schedules
    foreach ($validated['schedules'] as $scheduleData) {
        $package->availabilitySchedules()->create($scheduleData);
    }

    return response()->json([
        'message' => 'Availability schedules updated successfully',
        'package' => $package->load('availabilitySchedules')
    ]);
}
```

### Example 4: API Request Format

```json
{
  "schedules": [
    {
      "availability_type": "monthly",
      "day_configuration": "last-sunday",
      "time_slot_start": "15:00",
      "time_slot_end": "00:00",
      "time_slot_interval": 30,
      "priority": 10
    },
    {
      "availability_type": "weekly",
      "day_configuration": "monday",
      "time_slot_start": "12:00",
      "time_slot_end": "20:00",
      "time_slot_interval": 60,
      "priority": 5
    },
    {
      "availability_type": "weekly",
      "day_configuration": "friday",
      "time_slot_start": "17:00",
      "time_slot_end": "23:00",
      "time_slot_interval": 30,
      "priority": 8
    }
  ]
}
```

## Frontend Usage

### Getting Available Time Slots for Booking

```php
// In your booking controller
public function getAvailableTimeSlots(Request $request)
{
    $validated = $request->validate([
        'package_id' => 'required|exists:packages,id',
        'date' => 'required|date_format:Y-m-d',
    ]);

    $package = Package::find($validated['package_id']);
    $timeSlots = $package->getTimeSlotsForDate($validated['date']);

    return response()->json([
        'date' => $validated['date'],
        'time_slots' => $timeSlots,
    ]);
}
```

## Migration Steps

1. **Run the migration to create the new table:**
   ```bash
   php artisan migrate
   ```
   This creates the `package_availability_schedules` table.

2. **Migrate existing data (if needed):**
   - If you have existing packages with availability settings, you'll need to migrate that data to the new table
   - Create schedules for each package based on their old `availability_type` and time slot settings

3. **Drop old columns:**
   ```bash
   php artisan migrate
   ```
   The migration `drop_old_availability_columns_from_packages_table` removes:
   - `availability_type`
   - `available_days`
   - `available_week_days`
   - `available_month_days`
   - `time_slot_start`
   - `time_slot_end`
   - `time_slot_interval`

**Note:** The old columns will be permanently removed. Make sure to migrate any existing data before running this migration!

## Priority System

When multiple schedules match a date, the system uses the `priority` field (higher number = higher priority).

**Example:**
- Daily schedule (priority: 1) applies to all days
- Monday schedule (priority: 5) applies only to Mondays
- When checking a Monday, the weekly Monday schedule (priority 5) will be used instead of daily (priority 1)

## Querying Schedules

```php
// Get all active schedules for a package
$schedules = $package->availabilitySchedules()->active()->get();

// Get only weekly schedules
$weeklySchedules = $package->availabilitySchedules()
    ->active()
    ->byType('weekly')
    ->get();

// Get schedules for a specific day
$mondaySchedules = $package->availabilitySchedules()
    ->active()
    ->byType('weekly')
    ->byDayConfiguration('monday')
    ->get();

// Check if a schedule matches a specific date
$schedule = PackageAvailabilitySchedule::find(1);
$matches = $schedule->matchesDate('2025-12-25'); // true/false
```

## Benefits

1. **Flexibility**: Different time slots for different days/occasions
2. **Scalability**: Easy to add new schedules without modifying code
3. **Priority Control**: Handle overlapping schedules with priority system
4. **Backward Compatible**: Falls back to package default time slots
5. **Clean API**: Simple method to get time slots for any date

## Notes

- Time slots that cross midnight (e.g., 15:00 to 00:00) are automatically handled
- The system uses Carbon for date calculations
- All times are stored in 24-hour format (H:i)
- Priority helps resolve conflicts when multiple schedules match a date
