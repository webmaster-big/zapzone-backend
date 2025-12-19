# Analytics API - Quick Testing Guide

## 🧪 Testing the Analytics Endpoints

### Prerequisites
1. You have a Laravel backend running
2. You have at least one location in the database
3. You have some bookings and/or attraction purchases
4. You have a valid authentication token

---

## 📍 Step 1: Get Your Location ID

### Option A: Check database directly
```sql
SELECT id, name, city FROM locations;
```

### Option B: Use API
```bash
curl -X GET "http://localhost/api/locations/company/{companyId}" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 📊 Step 2: Test Get Analytics Endpoint

### Using cURL (Windows PowerShell)
```powershell
$token = "YOUR_TOKEN_HERE"
$locationId = 1

curl -X GET "http://localhost/api/analytics/location?location_id=$locationId&date_range=30d" `
  -H "Authorization: Bearer $token" `
  -H "Accept: application/json"
```

### Using cURL (Windows CMD)
```cmd
curl -X GET "http://localhost/api/analytics/location?location_id=1&date_range=30d" ^
  -H "Authorization: Bearer YOUR_TOKEN" ^
  -H "Accept: application/json"
```

### Using Postman
1. **Method:** GET
2. **URL:** `http://localhost/api/analytics/location`
3. **Query Params:**
   - `location_id`: 1
   - `date_range`: 30d
4. **Headers:**
   - `Authorization`: Bearer YOUR_TOKEN
   - `Accept`: application/json
5. **Click:** Send

### Using JavaScript (Browser Console)
```javascript
fetch('http://localhost/api/analytics/location?location_id=1&date_range=30d', {
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Accept': 'application/json'
  }
})
.then(res => res.json())
.then(data => console.log(data))
.catch(err => console.error(err));
```

---

## 📤 Step 3: Test Export JSON

### Using cURL (PowerShell)
```powershell
$token = "YOUR_TOKEN_HERE"
$body = @{
    location_id = 1
    date_range = "30d"
    format = "json"
    sections = @("metrics", "packages", "attractions")
} | ConvertTo-Json

curl -X POST "http://localhost/api/analytics/location/export" `
  -H "Authorization: Bearer $token" `
  -H "Content-Type: application/json" `
  -H "Accept: application/json" `
  -d $body
```

### Using Postman
1. **Method:** POST
2. **URL:** `http://localhost/api/analytics/location/export`
3. **Headers:**
   - `Authorization`: Bearer YOUR_TOKEN
   - `Content-Type`: application/json
   - `Accept`: application/json
4. **Body (raw JSON):**
```json
{
  "location_id": 1,
  "date_range": "30d",
  "format": "json",
  "sections": ["metrics", "packages", "attractions"]
}
```
5. **Click:** Send

---

## 📥 Step 4: Test Export CSV

### Using cURL (PowerShell) - Save to File
```powershell
$token = "YOUR_TOKEN_HERE"
$body = @{
    location_id = 1
    date_range = "30d"
    format = "csv"
} | ConvertTo-Json

curl -X POST "http://localhost/api/analytics/location/export" `
  -H "Authorization: Bearer $token" `
  -H "Content-Type: application/json" `
  -d $body `
  -o "analytics.csv"
```

### Using Postman
1. **Method:** POST
2. **URL:** `http://localhost/api/analytics/location/export`
3. **Headers:**
   - `Authorization`: Bearer YOUR_TOKEN
   - `Content-Type`: application/json
4. **Body (raw JSON):**
```json
{
  "location_id": 1,
  "date_range": "30d",
  "format": "csv"
}
```
5. **Click:** Send and Download

---

## 🔍 Expected Responses

### ✅ Success Response (200 OK)
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
      "value": 15000.00,
      "change": "+10.5%",
      "trend": "up"
    },
    "package_bookings": {
      "value": 25,
      "change": "+8.7%",
      "trend": "up"
    },
    // ... more metrics
  },
  "hourly_revenue": [ /* ... */ ],
  "daily_revenue": [ /* ... */ ],
  "weekly_trend": [ /* ... */ ],
  "package_performance": [ /* ... */ ],
  "attraction_performance": [ /* ... */ ],
  "time_slot_performance": [ /* ... */ ]
}
```

### ❌ Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "location_id": [
      "The location id field is required."
    ]
  }
}
```

### ❌ Unauthorized (401)
```json
{
  "message": "Unauthenticated."
}
```

### ❌ Not Found (404)
```json
{
  "message": "No query results for model [App\\Models\\Location] 999"
}
```

---

## 🔑 Getting an Auth Token

If you don't have a token, login first:

### Using cURL (PowerShell)
```powershell
$loginBody = @{
    email = "admin@example.com"
    password = "password"
} | ConvertTo-Json

curl -X POST "http://localhost/api/login" `
  -H "Content-Type: application/json" `
  -d $loginBody
```

### Using Postman
1. **Method:** POST
2. **URL:** `http://localhost/api/login`
3. **Body (raw JSON):**
```json
{
  "email": "admin@example.com",
  "password": "password"
}
```
4. **Response will contain:**
```json
{
  "token": "1|abc123def456...",
  "user": { /* ... */ }
}
```

**Save the token** and use it in subsequent requests!

---

## 📊 Verify Data is Returned

### Check if you have data in database
```sql
-- Check bookings
SELECT 
    COUNT(*) as booking_count,
    SUM(total_amount) as total_revenue
FROM bookings 
WHERE location_id = 1 
AND status != 'cancelled'
AND booking_date >= DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Check attraction purchases
SELECT 
    COUNT(*) as purchase_count,
    SUM(total_amount) as total_revenue,
    SUM(quantity) as total_tickets
FROM attraction_purchases ap
JOIN attractions a ON ap.attraction_id = a.id
WHERE a.location_id = 1
AND ap.status != 'cancelled'
AND ap.purchase_date >= DATE_SUB(NOW(), INTERVAL 30 DAY);
```

If these return 0, you need to create some test data first.

---

## 🎯 Create Test Data (If Needed)

### Create a Test Booking
```php
php artisan tinker

$booking = \App\Models\Booking::create([
    'reference_number' => 'TEST-' . uniqid(),
    'location_id' => 1,
    'package_id' => 1, // Make sure this package exists
    'booking_date' => now(),
    'booking_time' => now()->setTime(14, 0),
    'participants' => 10,
    'duration' => 2,
    'duration_unit' => 'hours',
    'total_amount' => 500.00,
    'amount_paid' => 500.00,
    'payment_method' => 'card',
    'payment_status' => 'paid',
    'status' => 'confirmed',
    'guest_name' => 'Test Customer',
    'guest_email' => 'test@example.com',
]);
```

### Create a Test Attraction Purchase
```php
php artisan tinker

$purchase = \App\Models\AttractionPurchase::create([
    'attraction_id' => 1, // Make sure this attraction exists
    'quantity' => 5,
    'total_amount' => 375.00,
    'amount_paid' => 375.00,
    'payment_method' => 'card',
    'status' => 'completed',
    'purchase_date' => now(),
    'guest_name' => 'Test Customer',
    'guest_email' => 'test@example.com',
]);
```

---

## 🐛 Troubleshooting

### Issue: "Unauthenticated"
**Solution:** Make sure you're including the Bearer token in the Authorization header.

### Issue: "Location not found"
**Solution:** Check that the location_id exists in your database.

### Issue: "No data returned" (empty arrays)
**Solution:** 
1. Check if you have bookings/purchases in the date range
2. Verify the location_id is correct
3. Ensure bookings/purchases are not all cancelled

### Issue: "CORS error" (in browser)
**Solution:** Add CORS middleware to your Laravel app:
```php
// config/cors.php
'paths' => ['api/*'],
'allowed_origins' => ['http://localhost:3000'],
```

### Issue: "Call to undefined method scopeByLocation"
**Solution:** Make sure the AttractionPurchase model has the scope:
```php
public function scopeByLocation($query, $locationId)
{
    return $query->whereHas('attraction', function ($q) use ($locationId) {
        $q->where('location_id', $locationId);
    });
}
```

---

## ✅ Success Checklist

- [ ] Can fetch analytics data for a location
- [ ] Data includes all 6 key metrics
- [ ] Charts data is populated (hourly, daily, weekly)
- [ ] Package performance shows packages
- [ ] Attraction performance shows attractions
- [ ] Can export as JSON
- [ ] Can export as CSV
- [ ] Date range parameter works (7d, 30d, 90d, 1y)
- [ ] Percentage changes are calculated correctly
- [ ] Previous period comparison works

---

## 📱 Testing from React App

Once backend is working, test from your React component:

```typescript
// In your component or hook
const testAnalytics = async () => {
  try {
    const response = await fetch('/api/analytics/location?location_id=1&date_range=30d', {
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Accept': 'application/json'
      }
    });
    
    const data = await response.json();
    console.log('Analytics Data:', data);
    
    // Verify data structure
    console.assert(data.key_metrics, 'key_metrics missing');
    console.assert(data.hourly_revenue, 'hourly_revenue missing');
    console.assert(data.package_performance, 'package_performance missing');
    console.assert(data.attraction_performance, 'attraction_performance missing');
    
    return data;
  } catch (error) {
    console.error('Analytics fetch failed:', error);
  }
};

// Call it
testAnalytics();
```

---

## 🎉 Done!

If all tests pass, your analytics API is ready to integrate with the React frontend! 🚀
