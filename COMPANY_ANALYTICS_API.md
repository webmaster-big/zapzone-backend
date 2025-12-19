# Company Analytics API Documentation

## Overview
This API provides enterprise-wide analytics across all locations for company owners and administrators. Aggregates data from **package bookings** and **attraction ticket purchases** across multiple locations.

## Authentication
All endpoints require authentication via Laravel Sanctum token:
```
Authorization: Bearer {token}
```

---

## Endpoints

### 1. Get Company Analytics
Retrieve comprehensive analytics data aggregated across all company locations.

**Endpoint:** `GET /api/analytics/company`

**Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | integer | Yes | - | The ID of the company |
| `date_range` | string | No | `30d` | Time period: `7d`, `30d`, `90d`, `1y` |
| `location_ids` | array | No | all | Filter by specific location IDs |

**Example Request:**
```bash
curl -X GET "https://your-domain.com/api/analytics/company?company_id=1&date_range=30d" \
  -H "Authorization: Bearer your-token-here" \
  -H "Accept: application/json"
```

**With Location Filtering:**
```bash
curl -X GET "https://your-domain.com/api/analytics/company?company_id=1&date_range=30d&location_ids[]=1&location_ids[]=2&location_ids[]=3" \
  -H "Authorization: Bearer your-token-here" \
  -H "Accept: application/json"
```

**Response Structure:**
```json
{
  "company": {
    "id": 1,
    "name": "ZapZone Entertainment",
    "total_locations": 10
  },
  "date_range": {
    "period": "30d",
    "start_date": "2025-11-20",
    "end_date": "2025-12-20"
  },
  "selected_locations": [],
  "key_metrics": {
    "total_revenue": {
      "value": 1248500.00,
      "change": "+12.5%",
      "trend": "up"
    },
    "total_locations": {
      "value": 10,
      "info": "2 new locations"
    },
    "package_bookings": {
      "value": 1847,
      "change": "+8.3%",
      "trend": "up"
    },
    "ticket_purchases": {
      "value": 5420,
      "change": "+15.7%",
      "trend": "up"
    },
    "total_participants": {
      "value": 14280,
      "change": "+11.2%",
      "trend": "up"
    },
    "active_packages": {
      "value": 24,
      "info": "3 new packages"
    }
  },
  "revenue_trend": [
    {
      "month": "Apr 25",
      "revenue": 125000.50,
      "bookings": 450
    },
    // ... 9 months of data
  ],
  "location_performance": [
    {
      "location": "Brighton",
      "location_id": 1,
      "revenue": 148000.00,
      "bookings": 258
    },
    // ... all locations sorted by revenue
  ],
  "package_distribution": [
    {
      "name": "Birthday Package",
      "value": 35.0,
      "count": 647,
      "color": "#3b82f6"
    },
    // ... other package categories
  ],
  "peak_hours": [
    {
      "hour": "09:00",
      "bookings": 45
    },
    // ... hourly data from 9 AM to 9 PM
  ],
  "daily_performance": [
    {
      "day": "Mon",
      "date": "2025-12-14",
      "revenue": 42500.75,
      "participants": 785
    },
    // ... last 7 days
  ],
  "booking_status": [
    {
      "status": "Confirmed",
      "count": 850,
      "color": "#10b981"
    },
    {
      "status": "Pending",
      "count": 120,
      "color": "#f59e0b"
    },
    {
      "status": "Cancelled",
      "count": 45,
      "color": "#ef4444"
    }
  ],
  "top_attractions": [
    {
      "id": 3,
      "name": "Laser Tag",
      "tickets_sold": 450,
      "revenue": 33750.00
    },
    // ... top 10 attractions by revenue
  ]
}
```

---

### 2. Export Company Analytics
Export company-wide analytics data in JSON or CSV format.

**Endpoint:** `POST /api/analytics/company/export`

**Parameters:**
| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | integer | Yes | - | The ID of the company |
| `date_range` | string | No | `30d` | Time period: `7d`, `30d`, `90d`, `1y` |
| `format` | string | No | `json` | Export format: `json`, `csv` |
| `location_ids` | array | No | all | Filter by specific location IDs |
| `sections` | array | No | all | Data sections to include |

**Example Request:**
```bash
curl -X POST "https://your-domain.com/api/analytics/company/export" \
  -H "Authorization: Bearer your-token-here" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": 1,
    "date_range": "30d",
    "format": "json",
    "location_ids": [1, 2, 3]
  }'
```

---

## Data Breakdown

### Key Metrics

#### 1. Total Revenue
Combined revenue from all locations (bookings + attraction purchases).
- **Calculation:** `SUM(all_bookings.total_amount) + SUM(all_attraction_purchases.total_amount)`
- **Comparison:** vs previous period

#### 2. Total Locations
Number of locations included in the analysis.
- **Info:** Count of new locations added in the period

#### 3. Package Bookings
Total package reservations across all locations.
- **Source:** `bookings` table
- **Comparison:** vs previous period

#### 4. Ticket Purchases
Total attraction tickets sold across all locations.
- **Source:** `attraction_purchases.quantity`
- **Comparison:** vs previous period

#### 5. Total Participants
All guests across packages and attractions.
- **Calculation:** `SUM(bookings.participants) + SUM(attraction_purchases.quantity)`
- **Comparison:** vs previous period

#### 6. Active Packages
Total active packages across all locations.
- **Info:** Count of new packages added in period

---

### Revenue Trend

**Time Periods:**
- **90+ days:** Shows last 9 months (monthly aggregation)
- **<90 days:** Shows daily data

**Data Points:**
- Month/Day label
- Total revenue (bookings + attractions)
- Number of bookings

**Use Case:** Track growth trends and seasonality

---

### Location Performance

**Shows:** All locations ranked by revenue

**Data per Location:**
- Location name and ID
- Total revenue
- Number of bookings

**Use Case:** Compare performance across locations, identify top and underperforming locations

---

### Package Distribution

**Shows:** Breakdown of bookings by package category

**Data per Category:**
- Package category name
- Percentage of total bookings
- Number of bookings
- Color for visualization

**Categories:**
- Birthday Package
- Corporate Package
- Family Package
- Adventure Package
- Group Package
- Other

**Use Case:** Understand which package types are most popular

---

### Peak Hours

**Shows:** Booking activity by hour across all locations

**Data Points:**
- Hour (9 AM - 9 PM)
- Number of bookings

**Use Case:** Identify busiest times for staffing and resource allocation

---

### Daily Performance

**Shows:** Last 7 days of activity

**Data Points:**
- Day of week
- Date
- Total revenue
- Total participants

**Use Case:** Track recent trends and day-of-week patterns

---

### Booking Status

**Shows:** Current status distribution of all bookings

**Statuses:**
- Confirmed (green)
- Pending (orange)
- Cancelled (red)
- Checked-in (cyan)
- Completed (purple)

**Use Case:** Monitor booking pipeline and completion rates

---

### Top Attractions

**Shows:** Top 10 attractions by revenue across all locations

**Data per Attraction:**
- Attraction name and ID
- Total tickets sold
- Total revenue

**Use Case:** Identify most profitable attractions

---

## React Integration Example

```typescript
// Fetch company analytics
const fetchCompanyAnalytics = async (
  companyId: number,
  dateRange: string = '30d',
  locationIds: number[] = []
) => {
  const params = new URLSearchParams({
    company_id: companyId.toString(),
    date_range: dateRange,
  });
  
  // Add location filters
  locationIds.forEach(id => params.append('location_ids[]', id.toString()));
  
  const response = await fetch(`/api/analytics/company?${params}`, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  
  return await response.json();
};

// Usage in component
const { data, loading, error } = useCompanyAnalytics(companyId, dateRange, selectedLocationIds);
```

---

## Complete React Hook

```typescript
import { useState, useEffect } from 'react';

interface CompanyAnalyticsData {
  company: {
    id: number;
    name: string;
    total_locations: number;
  };
  date_range: {
    period: string;
    start_date: string;
    end_date: string;
  };
  selected_locations: number[];
  key_metrics: {
    total_revenue: { value: number; change: string; trend: string };
    total_locations: { value: number; info: string };
    package_bookings: { value: number; change: string; trend: string };
    ticket_purchases: { value: number; change: string; trend: string };
    total_participants: { value: number; change: string; trend: string };
    active_packages: { value: number; info: string };
  };
  revenue_trend: Array<{ month: string; revenue: number; bookings: number }>;
  location_performance: Array<{
    location: string;
    location_id: number;
    revenue: number;
    bookings: number;
  }>;
  package_distribution: Array<{
    name: string;
    value: number;
    count: number;
    color: string;
  }>;
  peak_hours: Array<{ hour: string; bookings: number }>;
  daily_performance: Array<{
    day: string;
    date: string;
    revenue: number;
    participants: number;
  }>;
  booking_status: Array<{ status: string; count: number; color: string }>;
  top_attractions: Array<{
    id: number;
    name: string;
    tickets_sold: number;
    revenue: number;
  }>;
}

export const useCompanyAnalytics = (
  companyId: number,
  dateRange: string = '30d',
  locationIds: number[] = []
) => {
  const [data, setData] = useState<CompanyAnalyticsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchAnalytics = async () => {
      setLoading(true);
      setError(null);
      
      try {
        const params = new URLSearchParams({
          company_id: companyId.toString(),
          date_range: dateRange,
        });
        
        locationIds.forEach(id => params.append('location_ids[]', id.toString()));

        const response = await fetch(`/api/analytics/company?${params}`, {
          headers: {
            'Authorization': `Bearer ${localStorage.getItem('token')}`,
            'Accept': 'application/json'
          }
        });

        if (!response.ok) {
          throw new Error('Failed to fetch analytics');
        }

        const analyticsData = await response.json();
        setData(analyticsData);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'An error occurred');
      } finally {
        setLoading(false);
      }
    };

    fetchAnalytics();
  }, [companyId, dateRange, JSON.stringify(locationIds)]);

  return { data, loading, error };
};
```

---

## Performance Optimization

### Caching Strategy
```php
$cacheKey = "analytics:company_{$companyId}:" . 
            implode(',', $locationIds) . ":{$dateRange}";
            
Cache::remember($cacheKey, 300, function () {
    // Analytics calculations
});
```

### Database Indexes
Ensure these exist:
```sql
INDEX idx_location_company (location_id, company_id)
INDEX idx_booking_location_date (location_id, booking_date, status)
INDEX idx_purchase_date_status (purchase_date, status)
```

---

## Authorization

### Recommended Middleware
```php
Route::middleware(['auth:sanctum', 'role:admin,owner'])
    ->get('analytics/company', [AnalyticsController::class, 'getCompanyAnalytics']);
```

### Check Company Access
```php
$user = $request->user();
$companyId = $request->company_id;

if ($user->company_id !== $companyId && $user->role !== 'super_admin') {
    return response()->json(['message' => 'Unauthorized'], 403);
}
```

---

## Testing

### Example Test
```php
public function test_can_get_company_analytics()
{
    $company = Company::factory()->create();
    $locations = Location::factory()->count(3)->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
    
    $response = $this->actingAs($user)
        ->getJson("/api/analytics/company?company_id={$company->id}&date_range=30d");
    
    $response->assertOk()
        ->assertJsonStructure([
            'company',
            'date_range',
            'key_metrics',
            'revenue_trend',
            'location_performance',
            'package_distribution',
            'peak_hours',
            'daily_performance',
            'booking_status',
            'top_attractions'
        ]);
}
```

---

## Changelog

### Version 1.0.0 (December 20, 2025)
- Initial release
- Company-wide analytics with multi-location support
- Location filtering capability
- Monthly revenue trends
- Package distribution analysis
- Top performers tracking
