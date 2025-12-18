# Package Availability Schedules - API Routes

Add these routes to your `routes/api.php` file:

```php
use App\Http\Controllers\Api\PackageController;

// Package Availability Schedules Management
Route::prefix('packages/{package}')->group(function () {
    // Get all availability schedules for a package
    Route::get('/availability-schedules', [PackageController::class, 'getAvailabilitySchedules']);

    // Update all availability schedules for a package (bulk replace)
    Route::put('/availability-schedules', [PackageController::class, 'updateAvailabilitySchedules']);

    // Delete a specific availability schedule
    Route::delete('/availability-schedules/{scheduleId}', [PackageController::class, 'deleteAvailabilitySchedule']);
});

// Note: Available time slots are retrieved via PackageTimeSlotController
// which automatically uses the new availability schedules system
```

## API Endpoints Documentation

### 1. Get Availability Schedules
**Endpoint:** `GET /api/packages/{package}/availability-schedules`

**Response:**
```json
{
  "success": true,
  "data": {
    "package_id": 1,
    "package_name": "Kids Party Package",
    "schedules": [
      {
        "id": 1,
        "package_id": 1,
        "availability_type": "monthly",
        "day_configuration": "last-sunday",
        "time_slot_start": "15:00",
        "time_slot_end": "00:00",
        "time_slot_interval": 30,
        "priority": 10,
        "is_active": true,
        "created_at": "2025-12-18T10:00:00.000000Z",
        "updated_at": "2025-12-18T10:00:00.000000Z"
      }
    ]
  }
}
```

### 2. Update Availability Schedules
**Endpoint:** `PUT /api/packages/{package}/availability-schedules`

**Request Body:**
```json
{
  "schedules": [
    {
      "availability_type": "monthly",
      "day_configuration": "last-sunday",
      "time_slot_start": "15:00",
      "time_slot_end": "00:00",
      "time_slot_interval": 30,
      "priority": 10,
      "is_active": true
    },
    {
      "availability_type": "weekly",
      "day_configuration": "monday",
      "time_slot_start": "12:00",
      "time_slot_end": "20:00",
      "time_slot_interval": 60,
      "priority": 5,
      "is_active": true
    }
  ]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Availability schedules updated successfully",
  "data": {
    "package_id": 1,
    "package_name": "Kids Party Package",
    "schedules": [...]
  }
}
```

### 3. Delete Specific Schedule
**Endpoint:** `DELETE /api/packages/{package}/availability-schedules/{scheduleId}`

**Response:**
```json
{
  "success": true,
  "message": "Availability schedule deleted successfully"
}
```

### 4. Get Available Time Slots (via PackageTimeSlotController)

**Note:** Available time slots are retrieved through the `PackageTimeSlotController` which now automatically uses the new availability schedules system.

**How it works:**
- The `PackageTimeSlotController` methods (`getAvailableSlotsAuto`) now use `$package->getTimeSlotsForDate($date)`
- This automatically checks the package's availability schedules
- Returns slots with room availability checking
- Supports SSE (Server-Sent Events) for real-time updates

**Endpoint:** See `PackageTimeSlotController` for available time slots endpoints

## Validation Rules

### Availability Type
- **Values:** `daily`, `weekly`, `monthly`
- **Required:** Yes

### Day Configuration
- **Format:**
  - For `weekly`: Day name (e.g., `monday`, `tuesday`)
  - For `monthly`: Pattern `{occurrence}-{day}` (e.g., `last-sunday`, `first-monday`)
  - For `daily`: Can be `null`
- **Occurrences:** `first`, `second`, `third`, `fourth`, `last`
- **Days:** `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`

### Time Slot Start/End
- **Format:** HH:MM (24-hour format)
- **Required:** Yes
- **Example:** `15:00`, `23:30`

### Time Slot Interval
- **Type:** Integer
- **Min:** 15 minutes
- **Max:** 240 minutes (4 hours)
- **Required:** Yes

### Priority
- **Type:** Integer
- **Min:** 0
- **Optional:** Yes (defaults to 0)
- **Purpose:** Higher priority schedules are used when multiple schedules match a date

### Is Active
- **Type:** Boolean
- **Optional:** Yes (defaults to true)
