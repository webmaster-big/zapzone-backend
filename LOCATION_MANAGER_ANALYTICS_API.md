# Location Manager Analytics API Documentation

## Overview
This API provides comprehensive analytics for location managers, tracking both **package bookings** and **attraction ticket purchases** to give a complete picture of location performance.

## Authentication
All endpoints require authentication via Laravel Sanctum token:
```
Authorization: Bearer {token}
```

---

## Endpoints

### 1. Get Location Analytics
Retrieve comprehensive analytics data for a specific location.

**Endpoint:** `GET /api/analytics/location`

**Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `location_id` | integer | Yes | - | The ID of the location to analyze |
| `date_range` | string | No | `30d` | Time period: `7d`, `30d`, `90d`, `1y` |

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/analytics/location?location_id=1&date_range=30d" \
  -H "Authorization: Bearer your-token-here" \
  -H "Accept: application/json"
```

**Response Structure:**
```json
{
  "location": {
    "id": 1,
    "name": "Brighton",
    "address": "456 Entertainment Avenue",
    "city": "Brighton",
    "state": "MI",
    "zip_code": "48116",
    "full_address": "456 Entertainment Avenue, Brighton, MI 48116"
  },
  "date_range": {
    "period": "30d",
    "start_date": "2025-11-20",
    "end_date": "2025-12-20"
  },
  "key_metrics": {
    "location_revenue": {
      "value": 148000.00,
      "change": "+14.2%",
      "trend": "up"
    },
    "package_bookings": {
      "value": 258,
      "change": "+9.7%",
      "trend": "up"
    },
    "ticket_sales": {
      "value": 485,
      "change": "+12.3%",
      "trend": "up"
    },
    "total_visitors": {
      "value": 1847,
      "change": "+11.8%",
      "trend": "up"
    },
    "active_packages": {
      "value": 12,
      "info": "2 new packages"
    },
    "active_attractions": {
      "value": 8,
      "info": "All operational"
    }
  },
  "hourly_revenue": [
    {
      "hour": "09:00",
      "revenue": 1250.50,
      "bookings": 8
    },
    // ... more hours
  ],
  "daily_revenue": [
    {
      "day": "Mon",
      "date": "2025-12-14",
      "revenue": 12500.75,
      "participants": 185
    },
    // ... more days
  ],
  "weekly_trend": [
    {
      "week": "Week 1",
      "week_start": "2025-11-18",
      "week_end": "2025-11-24",
      "revenue": 45000.00,
      "bookings": 65,
      "tickets": 120
    },
    // ... more weeks
  ],
  "package_performance": [
    {
      "id": 5,
      "name": "Birthday Blast",
      "category": "birthday",
      "bookings": 85,
      "revenue": 29750.00,
      "participants": 1020,
      "avg_party_size": 12.0,
      "price": 350.00
    },
    // ... more packages
  ],
  "attraction_performance": [
    {
      "id": 3,
      "name": "Laser Tag",
      "category": "active",
      "sessions": 145,
      "tickets_sold": 290,
      "revenue": 10875.00,
      "utilization": 85.2,
      "price": 75.00,
      "max_capacity": 20
    },
    // ... more attractions
  ],
  "time_slot_performance": [
    {
      "slot": "Morning (9-12)",
      "bookings": 45,
      "revenue": 12600.00,
      "avg_value": 280.00
    },
    {
      "slot": "Afternoon (12-6)",
      "bookings": 98,
      "revenue": 35280.00,
      "avg_value": 360.00
    },
    {
      "slot": "Evening (6-9)",
      "bookings": 115,
      "revenue": 50600.00,
      "avg_value": 440.00
    }
  ]
}
```

---

### 2. Export Analytics Data
Export analytics data in JSON or CSV format with optional filtering.

**Endpoint:** `POST /api/analytics/location/export`

**Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `location_id` | integer | Yes | - | The ID of the location |
| `date_range` | string | No | `30d` | Time period: `7d`, `30d`, `90d`, `1y` |
| `format` | string | No | `json` | Export format: `json`, `csv` |
| `sections` | array | No | all | Data sections to include |

**Available Sections:**
- `metrics` - Key performance metrics
- `revenue` - Hourly, daily, and weekly revenue data
- `packages` - Package performance data
- `attractions` - Attraction performance data
- `timeslots` - Time slot analysis

**Example Request (JSON Export):**
```bash
curl -X POST "https://your-domain.com/api/analytics/location/export" \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "location_id": 1,
    "date_range": "30d",
    "format": "json",
    "sections": ["metrics", "packages", "attractions"]
  }'
```

**Example Request (CSV Export):**
```bash
curl -X POST "https://your-domain.com/api/analytics/location/export" \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "location_id": 1,
    "date_range": "30d",
    "format": "csv"
  }' \
  --output "location-analytics.csv"
```

**JSON Response:**
Returns filtered analytics data with `generated_at` timestamp.

**CSV Response:**
Returns a downloadable CSV file with the following structure:
- Header information (location, date range)
- Key metrics table
- Package performance table
- Attraction performance table
- Time slot performance table

---

## Data Sources

### Package Bookings
Analytics include data from the `bookings` table:
- **Revenue:** Sum of `total_amount` field
- **Participants:** Sum of `participants` field
- **Status Filter:** Excludes `cancelled` bookings
- **Date Filter:** Based on `booking_date` and `booking_time`

**Related Fields:**
```php
- total_amount (decimal)
- amount_paid (decimal)
- participants (integer)
- booking_date (date)
- booking_time (time)
- status (enum: pending, confirmed, checked-in, completed, cancelled)
- payment_status (enum: paid, partial, pending)
```

### Attraction Purchases
Analytics include data from the `attraction_purchases` table:
- **Revenue:** Sum of `total_amount` field
- **Tickets:** Sum of `quantity` field
- **Status Filter:** Excludes `cancelled` purchases
- **Date Filter:** Based on `purchase_date`
- **Location Filter:** Via `attractions.location_id` relationship

**Related Fields:**
```php
- total_amount (decimal)
- quantity (integer)
- purchase_date (date)
- status (enum: pending, completed, cancelled)
```

---

## Key Metrics Explained

### 1. Location Revenue
Combined revenue from package bookings and attraction ticket sales.
- **Calculation:** `SUM(bookings.total_amount) + SUM(attraction_purchases.total_amount)`
- **Change:** Percentage comparison with previous period

### 2. Package Bookings
Total number of package reservations (group experiences).
- **Source:** `bookings` table
- **Excludes:** Cancelled bookings

### 3. Ticket Sales
Individual attraction tickets purchased.
- **Source:** `attraction_purchases.quantity`
- **Represents:** Walk-in customers and individual ticket purchases

### 4. Total Visitors
Combined count of all participants and ticket holders.
- **Calculation:** `SUM(bookings.participants) + SUM(attraction_purchases.quantity)`
- **Represents:** Total foot traffic

### 5. Active Packages
Count of active packages available at the location.
- **Source:** `packages` table where `is_active = true`

### 6. Active Attractions
Count of active attractions available at the location.
- **Source:** `attractions` table where `is_active = true`

---

## Analytics Calculations

### Hourly Revenue Pattern
Groups bookings by hour of day (9 AM - 9 PM) showing:
- Revenue per hour
- Number of bookings per hour
- **SQL:** Groups by `HOUR(booking_time)`

### Daily Revenue
Shows last 7 days with:
- Total revenue (bookings + tickets)
- Total participants/visitors
- Day of week label

### Weekly Trend
Shows last 5 weeks with:
- Weekly revenue totals
- Booking counts
- Ticket sales counts

### Package Performance
For each active package:
- Number of bookings
- Total revenue generated
- Total participants
- Average party size
- **Sorted by:** Total revenue (descending)

### Attraction Performance
For each active attraction:
- Number of purchase sessions
- Total tickets sold
- Total revenue
- Utilization percentage (tickets sold vs. max capacity)
- **Sorted by:** Total revenue (descending)

**Utilization Calculation:**
```php
$maxPossibleSessions = $attraction->max_capacity * $daysInPeriod;
$utilization = ($ticketsSold / $maxPossibleSessions) * 100;
```

### Time Slot Performance
Analyzes three time periods:
- **Morning (9 AM - 12 PM)**
- **Afternoon (12 PM - 6 PM)**
- **Evening (6 PM - 9 PM)**

Shows for each slot:
- Number of bookings
- Total revenue
- Average booking value

---

## Frontend Integration

### React Component Usage

```typescript
// Fetch analytics data
const fetchAnalytics = async (locationId: number, dateRange: string = '30d') => {
  const response = await fetch(
    `/api/analytics/location?location_id=${locationId}&date_range=${dateRange}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    }
  );
  return await response.json();
};

// Export analytics
const exportAnalytics = async (locationId: number, format: 'json' | 'csv') => {
  const response = await fetch('/api/analytics/location/export', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      location_id: locationId,
      date_range: '30d',
      format: format,
      sections: ['metrics', 'revenue', 'packages', 'attractions', 'timeslots']
    })
  });
  
  if (format === 'csv') {
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `analytics-${Date.now()}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  } else {
    return await response.json();
  }
};
```

---

## Database Schema Reference

### Key Tables

**bookings**
```sql
- id
- location_id (FK)
- package_id (FK, nullable)
- customer_id (FK, nullable)
- booking_date (date)
- booking_time (time)
- participants (int)
- total_amount (decimal)
- amount_paid (decimal)
- status (enum)
- payment_status (enum)
```

**attraction_purchases**
```sql
- id
- attraction_id (FK)
- customer_id (FK, nullable)
- quantity (int)
- total_amount (decimal)
- purchase_date (date)
- status (enum)
```

**packages**
```sql
- id
- location_id (FK)
- name (string)
- category (string)
- price (decimal)
- min_participants (int)
- max_participants (int)
- is_active (boolean)
```

**attractions**
```sql
- id
- location_id (FK)
- name (string)
- category (string)
- price (decimal)
- max_capacity (int)
- is_active (boolean)
```

---

## Performance Considerations

### Optimization Tips
1. **Indexing:** Ensure proper indexes on:
   - `bookings.location_id`
   - `bookings.booking_date`
   - `bookings.status`
   - `attraction_purchases.purchase_date`
   - `attractions.location_id`

2. **Caching:** Consider caching analytics data for:
   - 5 minutes for real-time dashboards
   - 1 hour for historical reports

3. **Query Optimization:**
   - Uses aggregate functions (SUM, COUNT, AVG)
   - Filters by date range and status
   - Eager loading for relationships

### Example Caching Implementation
```php
use Illuminate\Support\Facades\Cache;

$cacheKey = "analytics:location:{$locationId}:{$dateRange}";
$analytics = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($locationId, $startDate, $endDate) {
    return [
        'key_metrics' => $this->getKeyMetrics($locationId, $startDate, $endDate),
        'hourly_revenue' => $this->getHourlyRevenue($locationId, $startDate, $endDate),
        // ... other data
    ];
});
```

---

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "location_id": ["The location id field is required."],
    "date_range": ["The selected date range is invalid."]
  }
}
```

### Not Found (404)
```json
{
  "message": "Location not found"
}
```

### Unauthorized (401)
```json
{
  "message": "Unauthenticated."
}
```

---

## Testing

### Example Test Cases

```php
// Test analytics retrieval
public function test_can_get_location_analytics()
{
    $location = Location::factory()->create();
    $user = User::factory()->create(['location_id' => $location->id]);
    
    $response = $this->actingAs($user)
        ->getJson("/api/analytics/location?location_id={$location->id}&date_range=30d");
    
    $response->assertOk()
        ->assertJsonStructure([
            'location',
            'date_range',
            'key_metrics',
            'hourly_revenue',
            'daily_revenue',
            'package_performance',
            'attraction_performance'
        ]);
}

// Test CSV export
public function test_can_export_analytics_as_csv()
{
    $location = Location::factory()->create();
    $user = User::factory()->create(['location_id' => $location->id]);
    
    $response = $this->actingAs($user)
        ->postJson('/api/analytics/location/export', [
            'location_id' => $location->id,
            'format' => 'csv'
        ]);
    
    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv');
}
```

---

## Changelog

### Version 1.0.0 (December 20, 2025)
- Initial release
- Location manager analytics with package bookings and attraction purchases
- Comprehensive metrics and performance tracking
- JSON and CSV export functionality
- Hourly, daily, and weekly trend analysis
- Package and attraction performance metrics
- Time slot performance analysis
