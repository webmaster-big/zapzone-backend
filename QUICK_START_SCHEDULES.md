# Quick Start Guide - Package Availability Schedules

## 🚀 Quick Setup

### 1. Run Migrations (5 seconds)
```bash
cd /c/laragon/www/zapzone-backend
php artisan migrate
```

This will:
- ✅ Create `package_availability_schedules` table
- ✅ Drop old availability columns from `packages` table

### 2. Routes Already Added ✅
Routes are already added to [routes/api.php](routes/api.php) - no action needed!

## 📝 Quick Usage Examples

### Create Schedules via API

**Endpoint:** `PUT /api/packages/1/availability-schedules`

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
    }
  ]
}
```

### Get Time Slots for Booking

**Note:** Available time slots are retrieved through `PackageTimeSlotController` which now automatically uses the new availability schedules system.

The controller's existing methods (`getAvailableSlotsAuto`) now:
- Use `$package->getTimeSlotsForDate($date)` to get slots from schedules
- Check room availability automatically
- Return slots with room assignments

## 🎯 Common Schedule Patterns

### Pattern 1: Last Sunday of Month (3pm-12am)
```json
{
  "availability_type": "monthly",
  "day_configuration": "last-sunday",
  "time_slot_start": "15:00",
  "time_slot_end": "00:00",
  "time_slot_interval": 30,
  "priority": 10
}
```

### Pattern 2: Every Monday (12pm-8pm)
```json
{
  "availability_type": "weekly",
  "day_configuration": "monday",
  "time_slot_start": "12:00",
  "time_slot_end": "20:00",
  "time_slot_interval": 60,
  "priority": 5
}
```

### Pattern 3: Every Day (10am-6pm)
```json
{
  "availability_type": "daily",
  "day_configuration": null,
  "time_slot_start": "10:00",
  "time_slot_end": "18:00",
  "time_slot_interval": 30,
  "priority": 1
}
```

### Pattern 4: First Friday of Month (5pm-11pm)
```json
{
  "availability_type": "monthly",
  "day_configuration": "first-friday",
  "time_slot_start": "17:00",
  "time_slot_end": "23:00",
  "time_slot_interval": 30,
  "priority": 8
}
```

## 📋 Day Configuration Format

### For Weekly (`availability_type: "weekly"`)
Use day names (lowercase):
- `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`

### For Monthly (`availability_type: "monthly"`)
Use pattern: `{occurrence}-{day}`

**Occurrences:**
- `first`, `second`, `third`, `fourth`, `last`

**Examples:**
- `last-sunday` - Last Sunday of every month
- `first-monday` - First Monday of every month
- `third-friday` - Third Friday of every month
- `second-wednesday` - Second Wednesday of every month

### For Daily (`availability_type: "daily"`)
Set `day_configuration: null` - applies to all days

## 🔧 Testing the Implementation

### 1. Test Creating Schedules
```bash
curl -X PUT http://localhost/api/packages/1/availability-schedules \
  -H "Content-Type: application/json" \
  -d '{
    "schedules": [
      {
        "availability_type": "weekly",
        "day_configuration": "monday",
        "time_slot_start": "12:00",
        "time_slot_end": "20:00",
        "time_slot_interval": 60,
        "priority": 5
      }
    ]
  }'
```

### 2. Test Getting Time Slots (via PackageTimeSlotController)
Time slots are automatically retrieved through the `PackageTimeSlotController` which uses the new schedules system.

### 3. Test in PHP/Laravel
```php
use App\Models\Package;

// Get package
$package = Package::find(1);

// Get time slots for a specific date
$slots = $package->getTimeSlotsForDate('2025-12-22');
dd($slots); // ["12:00", "13:00", "14:00", ...]
```

## 📚 Additional Documentation

- **Full Guide:** [PACKAGE_AVAILABILITY_SCHEDULES.md](PACKAGE_AVAILABILITY_SCHEDULES.md)
- **API Documentation:** [API_ROUTES_AVAILABILITY_SCHEDULES.md](API_ROUTES_AVAILABILITY_SCHEDULES.md)
- **Implementation Summary:** [IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)

## ✅ What's Already Done

- ✅ Database migrations created
- ✅ Models updated (Package, PackageAvailabilitySchedule)
- ✅ Controller methods added
- ✅ Request validation implemented
- ✅ API routes registered
- ✅ Resource updated to include schedules
- ✅ Complete documentation

## 🎉 You're Ready!

Just run `php artisan migrate` and start using the new flexible scheduling system!
