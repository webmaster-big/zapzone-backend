# Analytics APIs - Quick Reference

## 🎯 Overview

Two comprehensive analytics APIs for different user roles:
1. **Location Manager Analytics** - Single location focus
2. **Company Analytics** - Multi-location, enterprise-wide

---

## 📍 Location Manager Analytics

**For:** Location managers to track their specific location
**Endpoint:** `GET /api/analytics/location`

### Request
```bash
GET /api/analytics/location?location_id=1&date_range=30d
```

### Key Metrics (6 cards)
- Location Revenue
- Package Bookings
- Ticket Sales
- Total Visitors
- Active Packages
- Active Attractions

### Charts
- Hourly Revenue Pattern (9 AM - 9 PM)
- Daily Performance (Last 7 days)
- Weekly Trend (5 weeks)
- Package Performance
- Attraction Performance (Utilization)
- Time Slot Performance (Morning/Afternoon/Evening)

### Tables
- Package Bookings Detail
- Attraction Ticket Sales Detail

---

## 🏢 Company Analytics

**For:** Admins/Owners to track all locations
**Endpoint:** `GET /api/analytics/company`

### Request
```bash
GET /api/analytics/company?company_id=1&date_range=30d&location_ids[]=1&location_ids[]=2
```

### Key Metrics (6 cards)
- Total Revenue (all locations)
- Total Locations
- Package Bookings (all locations)
- Ticket Purchases (all locations)
- Total Participants (all locations)
- Active Packages (all locations)

### Charts
- Revenue & Bookings Trend (9 months or daily)
- Location Performance Comparison
- Package Distribution (Pie Chart)
- Peak Hours (Company-wide)
- Daily Performance (Last 7 days)
- Booking Status (Pie Chart)

### Tables
- Top Locations by Revenue
- Top Attractions (Ticket Sales)

---

## 🔄 Data Sources

Both APIs aggregate data from:

### Bookings Table
- **Represents:** Package reservations (group experiences)
- **Key Fields:** `total_amount`, `participants`, `booking_date`, `booking_time`, `status`
- **Filters:** Excludes `cancelled` bookings
- **Revenue:** Full booking amount

### Attraction Purchases Table
- **Represents:** Individual ticket sales
- **Key Fields:** `total_amount`, `quantity`, `purchase_date`, `status`
- **Filters:** Excludes `cancelled` purchases
- **Revenue:** Full purchase amount
- **Location Link:** Via `attractions.location_id`

---

## 🎨 React Component Mapping

### Location Manager Component
```typescript
import LocationManagerAnalytics from './LocationManagerAnalytics';

// Component expects:
const { data, loading, error } = useLocationAnalytics(locationId, dateRange);

// Data structure matches API response exactly - no transformation needed!
```

### Company Component
```typescript
import CompanyAnalytics from './CompanyAnalytics';

// Component expects:
const { data, loading, error } = useCompanyAnalytics(companyId, dateRange, locationIds);

// Data structure matches API response exactly - no transformation needed!
```

---

## 📊 Common Parameters

| Parameter | Type | Required | Options | Default |
|-----------|------|----------|---------|---------|
| `location_id` | integer | Yes (Location API) | Valid location ID | - |
| `company_id` | integer | Yes (Company API) | Valid company ID | - |
| `date_range` | string | No | `7d`, `30d`, `90d`, `1y` | `30d` |
| `location_ids` | array | No (Company API) | Array of location IDs | all |

---

## 📤 Export Functionality

Both APIs support JSON and CSV export:

```bash
POST /api/analytics/location/export
POST /api/analytics/company/export
```

**Parameters:**
- `format`: `json` or `csv`
- `sections`: Filter which data to export (optional)

---

## 🔐 Authentication

All endpoints require authentication:
```
Authorization: Bearer {your-token}
```

**Recommended Authorization:**
- **Location Analytics:** Location managers, admins
- **Company Analytics:** Admins, owners only

---

## ⚡ Performance Tips

### 1. Caching
```php
Cache::remember("analytics:key", 300, fn() => $data);
```

### 2. Database Indexes
```sql
-- Critical indexes
INDEX idx_location_date (location_id, booking_date)
INDEX idx_purchase_date (purchase_date)
INDEX idx_status (status)
```

### 3. Eager Loading
```php
Location::with('company')->find($id);
```

---

## 🧪 Quick Test

### Test Location Analytics
```bash
curl -X GET "http://localhost/api/analytics/location?location_id=1&date_range=30d" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Test Company Analytics
```bash
curl -X GET "http://localhost/api/analytics/company?company_id=1&date_range=30d" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 📁 Files Created

### Backend
- ✅ `app/Http/Controllers/Api/AnalyticsController.php` - Controller with all methods
- ✅ `routes/api.php` - Routes added

### Documentation
- ✅ `LOCATION_MANAGER_ANALYTICS_API.md` - Complete location API docs
- ✅ `COMPANY_ANALYTICS_API.md` - Complete company API docs
- ✅ `FRONTEND_INTEGRATION_GUIDE.md` - React integration guide
- ✅ `ANALYTICS_IMPLEMENTATION_SUMMARY.md` - Implementation overview
- ✅ `ANALYTICS_TESTING_GUIDE.md` - Testing instructions
- ✅ `ANALYTICS_QUICK_REFERENCE.md` - This file

---

## 🎯 Data Calculations

### Revenue
```php
$totalRevenue = $bookingsRevenue + $attractionPurchasesRevenue;
```

### Visitors
```php
$totalVisitors = $bookingParticipants + $ticketQuantities;
```

### Percentage Change
```php
$change = (($current - $previous) / $previous) * 100;
$formatted = ($change >= 0 ? '+' : '') . number_format($change, 1) . '%';
```

### Utilization
```php
$maxPossible = $attraction->max_capacity * $daysInPeriod;
$utilization = ($ticketsSold / $maxPossible) * 100;
```

---

## 🔄 API Response Compatibility

### ✅ **No Data Transformation Needed!**

Both APIs are designed to match the React component structure exactly:

```typescript
// Location Analytics
<LineChart data={data.hourly_revenue}>
  <Line dataKey="revenue" />
  <Line dataKey="bookings" />
</LineChart>

// Company Analytics
<BarChart data={data.location_performance}>
  <Bar dataKey="revenue" />
</BarChart>
```

All chart data, tables, and metrics can be used **directly** from the API response!

---

## 🚀 Quick Start

### 1. Get Auth Token
```bash
curl -X POST "http://localhost/api/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

### 2. Fetch Analytics
```typescript
const response = await fetch('/api/analytics/location?location_id=1&date_range=30d', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
});
const data = await response.json();
```

### 3. Use in Component
```typescript
// Pass data directly to your charts
<LineChart data={data.hourly_revenue}>
  <Line dataKey="revenue" />
</LineChart>
```

---

## 📚 Full Documentation

- **Location API:** See `LOCATION_MANAGER_ANALYTICS_API.md`
- **Company API:** See `COMPANY_ANALYTICS_API.md`
- **Frontend Guide:** See `FRONTEND_INTEGRATION_GUIDE.md`
- **Testing:** See `ANALYTICS_TESTING_GUIDE.md`
- **Implementation:** See `ANALYTICS_IMPLEMENTATION_SUMMARY.md`

---

## ✅ Status

**Both APIs are production-ready!**

- ✅ Real database queries
- ✅ Period-over-period comparison
- ✅ JSON & CSV export
- ✅ Location filtering (Company API)
- ✅ Comprehensive documentation
- ✅ React integration examples
- ✅ TypeScript interfaces
- ✅ No errors or warnings

**Ready to integrate with your React frontend!** 🎉
