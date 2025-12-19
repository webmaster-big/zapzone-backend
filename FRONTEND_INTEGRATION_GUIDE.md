# Location Manager Analytics - Frontend Integration Guide

## Quick Start

### API Endpoint
```
GET /api/analytics/location?location_id={id}&date_range={range}
```

### Response Mapping to React Component

Your React component expects these data structures. Here's how the API response maps to your component:

## 1. Key Metrics

**API Response:**
```json
"key_metrics": {
  "location_revenue": { "value": 148000.00, "change": "+14.2%", "trend": "up" },
  "package_bookings": { "value": 258, "change": "+9.7%", "trend": "up" },
  "ticket_sales": { "value": 485, "change": "+12.3%", "trend": "up" },
  "total_visitors": { "value": 1847, "change": "+11.8%", "trend": "up" },
  "active_packages": { "value": 12, "info": "2 new packages" },
  "active_attractions": { "value": 8, "info": "All operational" }
}
```

**Your Component Mapping:**
```typescript
const keyMetrics = [
  { 
    label: 'Location Revenue', 
    value: data.key_metrics.location_revenue.value,
    icon: DollarSign, 
    change: data.key_metrics.location_revenue.change + ' vs last period',
    trend: data.key_metrics.location_revenue.trend
  },
  { 
    label: 'Package Bookings', 
    value: data.key_metrics.package_bookings.value,
    icon: Package, 
    change: data.key_metrics.package_bookings.change + ' vs last period',
    trend: data.key_metrics.package_bookings.trend
  },
  // ... etc
];
```

## 2. Hourly Revenue Pattern

**API Response:**
```json
"hourly_revenue": [
  { "hour": "09:00", "revenue": 1250.50, "bookings": 8 },
  { "hour": "10:00", "revenue": 1840.25, "bookings": 12 },
  // ...
]
```

**Your Chart Component:**
```typescript
<LineChart data={data.hourly_revenue}>
  <Line yAxisId="left" dataKey="revenue" stroke={themeColor} name="Revenue ($)" />
  <Line yAxisId="right" dataKey="bookings" stroke="#10b981" name="Bookings" />
</LineChart>
```

✅ **No transformation needed** - use directly!

## 3. Daily Revenue (Last 7 Days)

**API Response:**
```json
"daily_revenue": [
  { "day": "Mon", "date": "2025-12-14", "revenue": 12500.75, "participants": 185 },
  { "day": "Tue", "date": "2025-12-15", "revenue": 13200.50, "participants": 198 },
  // ...
]
```

**Your Chart Component:**
```typescript
<AreaChart data={data.daily_revenue}>
  <Area dataKey="revenue" stroke={themeColor} fill={themeColor} name="Revenue ($)" />
  <Area dataKey="participants" stroke="#10b981" fill="#10b981" name="Participants" />
</AreaChart>
```

✅ **No transformation needed** - use directly!

## 4. Package Performance

**API Response:**
```json
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
  // ...
]
```

**Your Chart Component:**
```typescript
<BarChart data={data.package_performance}>
  <Bar dataKey="bookings" fill={themeColor} />
</BarChart>
```

**Your Table Component:**
```typescript
<table>
  <tbody>
    {data.package_performance.map((pkg) => (
      <tr key={pkg.id}>
        <td>{pkg.name}</td>
        <td>{pkg.bookings}</td>
        <td>{pkg.avg_party_size}</td>
      </tr>
    ))}
  </tbody>
</table>
```

✅ **No transformation needed** - use directly!

## 5. Attraction Performance

**API Response:**
```json
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
  // ...
]
```

**Your Chart Component (Utilization Bar Chart):**
```typescript
<BarChart data={data.attraction_performance} layout="vertical">
  <Bar dataKey="utilization" fill={themeColor} />
</BarChart>
```

**Your Table Component:**
```typescript
<table>
  <tbody>
    {data.attraction_performance.map((attraction) => (
      <tr key={attraction.id}>
        <td>{attraction.name}</td>
        <td>{attraction.tickets_sold}</td>
        <td>${attraction.revenue.toLocaleString()}</td>
      </tr>
    ))}
  </tbody>
</table>
```

✅ **No transformation needed** - use directly!

## 6. Weekly Trend

**API Response:**
```json
"weekly_trend": [
  {
    "week": "Week 1",
    "week_start": "2025-11-18",
    "week_end": "2025-11-24",
    "revenue": 45000.00,
    "bookings": 65,
    "tickets": 120
  },
  // ...
]
```

**Your Chart Component:**
```typescript
<LineChart data={data.weekly_trend}>
  <Line yAxisId="left" dataKey="revenue" stroke={themeColor} name="Revenue ($)" />
  <Line yAxisId="right" dataKey="bookings" stroke="#10b981" name="Bookings" />
</LineChart>
```

✅ **No transformation needed** - use directly!

## 7. Time Slot Performance

**API Response:**
```json
"time_slot_performance": [
  { "slot": "Morning (9-12)", "bookings": 45, "revenue": 12600.00, "avg_value": 280.00 },
  { "slot": "Afternoon (12-6)", "bookings": 98, "revenue": 35280.00, "avg_value": 360.00 },
  { "slot": "Evening (6-9)", "bookings": 115, "revenue": 50600.00, "avg_value": 440.00 }
]
```

**Your Chart Component:**
```typescript
<BarChart data={data.time_slot_performance}>
  <Bar dataKey="revenue" fill={themeColor} />
</BarChart>
```

✅ **No transformation needed** - use directly!

---

## Complete React Hook Example

```typescript
import { useState, useEffect } from 'react';

interface AnalyticsData {
  location: {
    id: number;
    name: string;
    full_address: string;
  };
  date_range: {
    period: string;
    start_date: string;
    end_date: string;
  };
  key_metrics: {
    location_revenue: { value: number; change: string; trend: string };
    package_bookings: { value: number; change: string; trend: string };
    ticket_sales: { value: number; change: string; trend: string };
    total_visitors: { value: number; change: string; trend: string };
    active_packages: { value: number; info: string };
    active_attractions: { value: number; info: string };
  };
  hourly_revenue: Array<{ hour: string; revenue: number; bookings: number }>;
  daily_revenue: Array<{ day: string; date: string; revenue: number; participants: number }>;
  weekly_trend: Array<{ week: string; week_start: string; week_end: string; revenue: number; bookings: number; tickets: number }>;
  package_performance: Array<{
    id: number;
    name: string;
    category: string;
    bookings: number;
    revenue: number;
    participants: number;
    avg_party_size: number;
    price: number;
  }>;
  attraction_performance: Array<{
    id: number;
    name: string;
    category: string;
    sessions: number;
    tickets_sold: number;
    revenue: number;
    utilization: number;
    price: number;
    max_capacity: number;
  }>;
  time_slot_performance: Array<{
    slot: string;
    bookings: number;
    revenue: number;
    avg_value: number;
  }>;
}

export const useLocationAnalytics = (locationId: number, dateRange: string = '30d') => {
  const [data, setData] = useState<AnalyticsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchAnalytics = async () => {
      setLoading(true);
      setError(null);
      
      try {
        const response = await fetch(
          `/api/analytics/location?location_id=${locationId}&date_range=${dateRange}`,
          {
            headers: {
              'Authorization': `Bearer ${localStorage.getItem('token')}`,
              'Accept': 'application/json'
            }
          }
        );

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
  }, [locationId, dateRange]);

  return { data, loading, error };
};
```

## Usage in Component

```typescript
const LocationManagerAnalytics: React.FC = () => {
  const { themeColor } = useThemeColor();
  const [dateRange, setDateRange] = useState<'7d' | '30d' | '90d' | '1y'>('30d');
  
  // Get location ID from user context or props
  const locationId = useAuth().user.location_id;
  
  // Fetch analytics data
  const { data: analyticsData, loading, error } = useLocationAnalytics(locationId, dateRange);
  
  if (loading) {
    return <div>Loading analytics...</div>;
  }
  
  if (error) {
    return <div>Error: {error}</div>;
  }
  
  if (!analyticsData) {
    return <div>No data available</div>;
  }
  
  // Now you can use analyticsData directly with your charts!
  return (
    <div className="min-h-screen bg-gray-50 p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
            <MapPin className={`w-6 h-6 text-${themeColor}-600`} />
            Location Analytics - {analyticsData.location.name}
          </h1>
          <p className="text-gray-600">
            {analyticsData.location.full_address}
          </p>
        </div>
        <select
          value={dateRange}
          onChange={(e) => setDateRange(e.target.value as any)}
          className="px-3 py-2 border rounded-lg"
        >
          <option value="7d">Last 7 days</option>
          <option value="30d">Last 30 days</option>
          <option value="90d">Last 90 days</option>
          <option value="1y">Last year</option>
        </select>
      </div>

      {/* Key Metrics */}
      <div className="grid grid-cols-6 gap-4">
        <MetricCard
          label="Location Revenue"
          value={analyticsData.key_metrics.location_revenue.value}
          change={analyticsData.key_metrics.location_revenue.change + ' vs last period'}
          trend={analyticsData.key_metrics.location_revenue.trend}
          icon={DollarSign}
        />
        {/* ... other metrics */}
      </div>

      {/* Charts */}
      <div className="grid grid-cols-2 gap-4">
        {/* Hourly Revenue Pattern */}
        <div className="bg-white rounded-xl p-4 shadow-sm">
          <h3 className="text-lg font-semibold mb-4">Hourly Revenue Pattern</h3>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={analyticsData.hourly_revenue}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="hour" />
              <YAxis yAxisId="left" />
              <YAxis yAxisId="right" orientation="right" />
              <Tooltip />
              <Legend />
              <Line yAxisId="left" dataKey="revenue" stroke={themeColor} name="Revenue ($)" />
              <Line yAxisId="right" dataKey="bookings" stroke="#10b981" name="Bookings" />
            </LineChart>
          </ResponsiveContainer>
        </div>

        {/* Package Performance */}
        <div className="bg-white rounded-xl p-4 shadow-sm">
          <h3 className="text-lg font-semibold mb-4">Package Bookings</h3>
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={analyticsData.package_performance}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="name" />
              <YAxis />
              <Tooltip />
              <Bar dataKey="bookings" fill={themeColor} />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {/* ... more charts */}
      </div>

      {/* Tables */}
      <div className="grid grid-cols-2 gap-4">
        {/* Package Details Table */}
        <div className="bg-white rounded-xl shadow-sm p-6">
          <h3 className="text-lg font-semibold mb-4">Package Bookings</h3>
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="text-left py-3">Package</th>
                <th className="text-left py-3">Bookings</th>
                <th className="text-left py-3">Participants</th>
              </tr>
            </thead>
            <tbody>
              {analyticsData.package_performance.map((pkg) => (
                <tr key={pkg.id} className="border-b hover:bg-gray-50">
                  <td className="py-3">{pkg.name}</td>
                  <td className="py-3">{pkg.bookings}</td>
                  <td className="py-3">{pkg.avg_party_size}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Attraction Details Table */}
        <div className="bg-white rounded-xl shadow-sm p-6">
          <h3 className="text-lg font-semibold mb-4">Attraction Ticket Sales</h3>
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="text-left py-3">Attraction</th>
                <th className="text-left py-3">Tickets Sold</th>
                <th className="text-left py-3">Revenue</th>
              </tr>
            </thead>
            <tbody>
              {analyticsData.attraction_performance.map((attraction) => (
                <tr key={attraction.id} className="border-b hover:bg-gray-50">
                  <td className="py-3">{attraction.name}</td>
                  <td className="py-3">{attraction.tickets_sold}</td>
                  <td className="py-3">${attraction.revenue.toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};
```

---

## Export Functionality

```typescript
const handleExport = async (format: 'json' | 'csv') => {
  setIsExporting(true);
  
  try {
    const response = await fetch('/api/analytics/location/export', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('token')}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        location_id: locationId,
        date_range: dateRange,
        format: format,
        sections: ['metrics', 'revenue', 'packages', 'attractions', 'timeslots']
      })
    });

    if (format === 'csv') {
      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${analyticsData.location.name.toLowerCase()}-analytics-${new Date().toISOString().split('T')[0]}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } else {
      const data = await response.json();
      const dataStr = JSON.stringify(data, null, 2);
      const blob = new Blob([dataStr], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${analyticsData.location.name.toLowerCase()}-analytics-${new Date().toISOString().split('T')[0]}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }
    
    setShowExportModal(false);
  } catch (error) {
    console.error('Export failed:', error);
    alert('Failed to export analytics');
  } finally {
    setIsExporting(false);
  }
};
```

---

## Summary

✅ **The API response structure matches your component's needs exactly!**

- No data transformation required
- All chart data is ready to use
- Table data maps directly
- Metric cards have the exact structure you need

Just replace your `generateLocationData()` function with the API call using the hook above, and everything will work seamlessly! 🎉
