# Party Summaries & Package Invoices - Quick Reference

## 🎉 Party Summaries (Staff Organization)

### Purpose
Printable reports with full booking details, notes, and special requests for staff to organize their day/week.

### Quick Access

```bash
# Today's parties
GET /api/payments/party-summaries/day/{today_date}

# Current week's parties
GET /api/payments/party-summaries/week/current

# Next week's parties
GET /api/payments/party-summaries/week/next

# Specific date range
GET /api/payments/party-summaries/export?start_date=2024-01-15&end_date=2024-01-22
```

### Common Filters

| Filter | Example | Description |
|--------|---------|-------------|
| Date | `?date=2024-01-15` | Specific day |
| Location | `?location_id=2` | Specific location |
| Package | `?package_id=5` | Specific package |
| Status | `?status=confirmed` | Booking status |
| View Mode | `?view_mode=compact` | Compact or detailed |
| Stream | `?stream=true` | View in browser |

### What's Included in PDF?
✅ Customer name, email, phone  
✅ Booking time, room, participants  
✅ Guest of honor details  
✅ Attractions and add-ons  
✅ Special requests  
✅ Customer notes  
✅ Internal staff notes  
✅ Payment status  
✅ Summary statistics  

---

## 💼 Package Invoices (Financial Reports)

### Purpose
Export all payment invoices for a specific package with consistent invoice format.

### Quick Access

```bash
# All invoices for a package
GET /api/payments/package-invoices/export?package_id=5

# Package invoices for January
GET /api/payments/package-invoices/export?package_id=5&start_date=2024-01-01&end_date=2024-01-31

# Package invoices at specific location
GET /api/payments/package-invoices/export?package_id=5&location_id=2

# Only completed payments
GET /api/payments/package-invoices/export?package_id=5&status=completed
```

### Common Filters

| Filter | Example | Description |
|--------|---------|-------------|
| Package (Required) | `?package_id=5` | **Required** - The package |
| Date Range | `?start_date=2024-01-01&end_date=2024-01-31` | Filter by date |
| Location | `?location_id=2` | Specific location |
| Status | `?status=completed` | Payment status |
| Stream | `?stream=true` | View in browser |

### What's Included in PDF?
✅ Invoice numbers  
✅ Customer information  
✅ Payment dates and times  
✅ Payment methods (card/cash)  
✅ Payment status  
✅ Transaction IDs  
✅ Total amounts  
✅ Status breakdown  
✅ Summary statistics  

---

## 🔄 Common Use Cases

### 1. Morning Staff Briefing
```bash
# Get today's schedule
GET /api/payments/party-summaries/day/2024-01-15?status=confirmed
```

### 2. Weekly Planning
```bash
# Review next week
GET /api/payments/party-summaries/week/next
```

### 3. Monthly Package Revenue Report
```bash
# All invoices for Laser Tag package in January
GET /api/payments/package-invoices/export?package_id=5&start_date=2024-01-01&end_date=2024-01-31
```

### 4. Location Performance Analysis
```bash
# Package performance at location
GET /api/payments/package-invoices/export?package_id=5&location_id=2&status=completed
```

---

## 📊 View Modes (Party Summaries Only)

### Detailed (Default)
- More spacing and formatting
- 2 bookings per page
- Easier to read
- **Best for:** < 10 bookings

### Compact
- Space-saving layout
- More bookings per page
- Still readable
- **Best for:** > 10 bookings

```bash
# Use compact mode
GET /api/payments/party-summaries/day/2024-01-15?view_mode=compact
```

---

## 🖨️ Download vs Stream

### Download (Default)
- Saves PDF to computer
- Default behavior

```bash
GET /api/payments/party-summaries/day/2024-01-15
```

### Stream (Browser Preview)
- Opens PDF in browser
- Add `stream=true`

```bash
GET /api/payments/party-summaries/day/2024-01-15?stream=true
```

---

## 📝 Integration Examples

### React/TypeScript

```typescript
// Party summaries for today
const downloadTodaysSummary = async () => {
  const today = new Date().toISOString().split('T')[0];
  const response = await fetch(
    `/api/payments/party-summaries/day/${today}?location_id=${locationId}`,
    {
      headers: { Authorization: `Bearer ${token}` }
    }
  );
  
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `party-summaries-${today}.pdf`;
  a.click();
};

// Package invoices
const downloadPackageInvoices = async (packageId: number, startDate: string, endDate: string) => {
  const response = await fetch(
    `/api/payments/package-invoices/export?package_id=${packageId}&start_date=${startDate}&end_date=${endDate}`,
    {
      headers: { Authorization: `Bearer ${token}` }
    }
  );
  
  const blob = await response.blob();
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `package-invoices.pdf`;
  a.click();
};
```

---

## ✅ Status Values

### Booking Status (Party Summaries)
- `pending`
- `confirmed`
- `checked-in`
- `completed`
- `cancelled` (excluded by default)

### Payment Status (Package Invoices)
- `pending`
- `completed`
- `failed`
- `refunded`

---

## 🎯 Tips

### Party Summaries
1. Use `compact` view mode for busy days
2. Filter by `confirmed` status to see only confirmed bookings
3. Specify `location_id` for multi-location businesses
4. Use `stream=true` for quick preview before printing

### Package Invoices
1. **Always include `package_id`** (required)
2. Use date ranges for monthly/quarterly reports
3. Filter by `status=completed` for actual revenue
4. Combine with `location_id` for location analysis

### Performance
- Keep date ranges < 90 days
- Use specific filters to reduce result size
- Stream for preview, download for saving

---

## 🚨 Common Errors

### 404 - No Results
```json
{
  "success": false,
  "message": "No bookings found for the specified criteria"
}
```
**Solution:** Adjust your filters or date range

### 422 - Validation Error
```json
{
  "message": "The package id field is required."
}
```
**Solution:** Include required parameters (e.g., `package_id` for package invoices)

---

## 📁 File Naming

### Party Summaries
- `party-summaries-2024-01-15.pdf` (single day)
- `party-summaries-2024-01-15-to-2024-01-22.pdf` (date range)
- `party-summaries-laser-tag-party-2024-01-15.pdf` (with package)

### Package Invoices
- `invoices-laser-tag-party-2024-01-15.pdf` (single day)
- `invoices-laser-tag-party-2024-01-01-to-2024-01-31.pdf` (date range)

---

## 📞 Support

For detailed documentation, see: `PARTY_SUMMARIES_AND_PACKAGE_INVOICES_API.md`

For general API documentation, see: `README.md`

---

**Quick Links:**
- Party Summaries Endpoint: `/api/payments/party-summaries/export`
- Package Invoices Endpoint: `/api/payments/package-invoices/export`
- PDF Views: `resources/views/exports/`
