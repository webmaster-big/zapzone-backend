# Package `is_active` Status Implementation

## Overview
This document describes the implementation of the `is_active` status field for packages and how it affects the system's operational status reporting.

## Database Schema

### Packages Table
The `is_active` column already exists in the packages table:
- **Column**: `is_active` (BOOLEAN)
- **Default**: `true`
- **Indexed**: Yes
- **Migration**: `2025_10_29_105434_create_packages_table.php`

## Model Configuration

### Package Model
Location: `app/Models/Package.php`

**Fillable Attributes:**
```php
'is_active',
```

**Casts:**
```php
'is_active' => 'boolean',
```

**Scopes:**
```php
public function scopeActive($query)
{
    return $query->where('is_active', true);
}
```

## Controller Updates

### 1. AnalyticsController
Location: `app/Http/Controllers/Api/AnalyticsController.php`

**Updated `getKeyMetrics()` Method:**
- Now calculates total packages vs active packages
- Provides accurate operational status messages:
  - "All operational" - when all packages are active
  - "None operational" - when no packages are active
  - "X inactive" - shows count of inactive packages

**Implementation:**
```php
// Active counts
$activePackages = Package::where('location_id', $locationId)
    ->where('is_active', true)
    ->count();

$totalPackages = Package::where('location_id', $locationId)->count();

$activeAttractions = Attraction::where('location_id', $locationId)
    ->where('is_active', true)
    ->count();

$totalAttractions = Attraction::where('location_id', $locationId)->count();

// Determine operational status messages
$packageStatus = $activePackages === $totalPackages ? 'All operational' : 
                 ($activePackages === 0 ? 'None operational' : 
                 ($totalPackages - $activePackages) . ' inactive');

$attractionStatus = $activeAttractions === $totalAttractions ? 'All operational' : 
                    ($activeAttractions === 0 ? 'None operational' : 
                    ($totalAttractions - $activeAttractions) . ' inactive');
```

**Response Format:**
```json
{
  "active_packages": {
    "value": 10,
    "info": "All operational"
  },
  "active_attractions": {
    "value": 8,
    "info": "2 inactive"
  }
}
```

### 2. BookingController
Location: `app/Http/Controllers/Api/BookingController.php`

**Updated Validation Rules:**
Added custom validation to prevent booking inactive packages:

**Store Method (`POST /api/bookings`):**
```php
'package_id' => [
    'nullable',
    'exists:packages,id',
    function ($attribute, $value, $fail) {
        if ($value) {
            $package = \App\Models\Package::find($value);
            if ($package && !$package->is_active) {
                $fail('The selected package is currently not available for booking.');
            }
        }
    },
],
```

**Update Method (`PUT /api/bookings/{booking}`):**
```php
'package_id' => [
    'sometimes',
    'nullable',
    'exists:packages,id',
    function ($attribute, $value, $fail) {
        if ($value) {
            $package = \App\Models\Package::find($value);
            if ($package && !$package->is_active) {
                $fail('The selected package is currently not available for booking.');
            }
        }
    },
],
```

### 3. PackageController
Location: `app/Http/Controllers/Api/PackageController.php`

**Already Implemented:**
The following methods already filter by `is_active`:

1. **`packagesGroupedByName()`** - Public packages for customer booking:
   ```php
   ->where('is_active', true)
   ```

2. **`getByLocation()`** - Location-specific packages:
   ```php
   ->active()
   ```

3. **`getByCategory()`** - Category-filtered packages:
   ```php
   ->active()
   ```

4. **`toggleStatus()`** - Toggle package active/inactive status

5. **Package Performance Analytics:**
   ```php
   Package::where('location_id', $locationId)
       ->where('is_active', true)
   ```

## API Resource

### PackageResource
Location: `app/Http/Resources/PackageResource.php`

**Already Includes:**
```php
'is_active' => $this->is_active,
```

## Request Validation

### StorePackageRequest
Location: `app/Http/Requests/StorePackageRequest.php`

```php
'is_active' => 'boolean',
```

### UpdatePackageRequest
Location: `app/Http/Requests/UpdatePackageRequest.php`

```php
'is_active' => 'boolean',
```

## Frontend Integration

### Displaying Package Status
When fetching packages from the API, the frontend receives the `is_active` status:

```javascript
// Example response
{
  "id": 1,
  "name": "Party Package",
  "is_active": true,
  // ... other fields
}
```

### Filtering Active Packages
Most public-facing endpoints automatically filter for active packages only:
- `GET /api/packages/grouped-by-name` - Only shows active packages
- `GET /api/packages/location/{locationId}` - Only shows active packages
- `GET /api/packages/category/{category}` - Only shows active packages

### Analytics Dashboard
The analytics endpoints now provide accurate operational status:

**Location Manager Analytics:**
```javascript
// GET /api/analytics/location/{locationId}
{
  "key_metrics": {
    "active_packages": {
      "value": 10,
      "info": "All operational"  // or "2 inactive" if some are inactive
    },
    "active_attractions": {
      "value": 8,
      "info": "All operational"  // or "1 inactive" if some are inactive
    }
  }
}
```

### Booking Validation
When attempting to book an inactive package, the API returns:

```json
{
  "message": "The selected package is currently not available for booking.",
  "errors": {
    "package_id": [
      "The selected package is currently not available for booking."
    ]
  }
}
```

## Admin Features

### Toggle Package Status
Admin users can toggle package status via:

**Endpoint:** `PATCH /api/packages/{package}/toggle-status`

**Effect:**
- Toggles `is_active` between `true` and `false`
- Immediately affects:
  - Package availability for new bookings
  - Analytics operational counts
  - Public package listings

## Testing Scenarios

### Test Case 1: Analytics with All Active
- All packages have `is_active = true`
- Expected: `"info": "All operational"`

### Test Case 2: Analytics with Some Inactive
- Some packages have `is_active = false`
- Expected: `"info": "2 inactive"` (showing count of inactive packages)

### Test Case 3: Analytics with None Active
- All packages have `is_active = false`
- Expected: `"info": "None operational"`

### Test Case 4: Booking Inactive Package
- Attempt to create booking with inactive package
- Expected: Validation error message

### Test Case 5: Public Package Listing
- Some packages are inactive
- Expected: Only active packages appear in public listings

## Migration Commands

No new migrations needed - `is_active` column already exists.

To verify:
```bash
php artisan migrate:status
```

## Benefits

1. **Accurate Reporting**: Analytics now accurately reflect which packages are operational
2. **Booking Protection**: Customers cannot book inactive packages
3. **Flexible Management**: Administrators can temporarily disable packages without deleting them
4. **Better UX**: Frontend can show clear operational status to users
5. **Data Integrity**: Inactive packages remain in the system for historical data

## Notes

- Existing bookings for inactive packages remain valid
- Inactive packages still appear in admin interfaces for management
- Package performance analytics only include active packages
- Inactive packages don't appear in customer-facing package lists
