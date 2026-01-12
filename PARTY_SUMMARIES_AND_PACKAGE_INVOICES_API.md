# Party Summaries and Package Invoices API Documentation

This document provides detailed information about the party summary and package-specific invoice export functionality.

## Overview

The system now includes comprehensive reporting features for staff organization and financial tracking:
- **Party Summaries**: Printable reports with full booking details and notes for staff to organize their day/week
- **Package Invoices**: Export all invoices for a specific package in a consistent PDF format

---

## 📋 Party Summaries API

### Purpose
Generate printable party summaries with full booking details, notes, special requests, and customer information for staff to organize their daily/weekly operations.

### Features
- Full booking details including customer info, participants, room assignments
- Guest of honor information (name, age, gender)
- Attractions and add-ons included in each booking
- Special requests and notes (both customer and internal staff notes)
- Payment status and amounts
- Booking status badges
- Summary statistics (total parties, guests, revenue)
- Two view modes: **detailed** (more spacing) and **compact** (space-saving)

---

## API Endpoints

### 1. General Party Summaries Export

**Endpoint:** `GET /api/payments/party-summaries/export`

**Description:** Export party summaries with flexible filtering options.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `date` | date (Y-m-d) | No | Filter by specific date |
| `start_date` | date (Y-m-d) | No | Start of date range |
| `end_date` | date (Y-m-d) | No | End of date range |
| `week` | string | No | `current`, `next`, or specific date for week |
| `location_id` | integer | No | Filter by location |
| `package_id` | integer | No | Filter by specific package |
| `room_id` | integer | No | Filter by room |
| `status` | string | No | Filter by booking status (`pending`, `confirmed`, `checked-in`, `completed`) |
| `view_mode` | string | No | `detailed` or `compact` (default: `detailed`) |
| `stream` | boolean | No | Set to `true` to stream PDF instead of download |

**Example Requests:**

```bash
# All bookings for today
GET /api/payments/party-summaries/export?date=2024-01-15

# All bookings for a specific week
GET /api/payments/party-summaries/export?week=current

# Bookings for a specific package in a date range
GET /api/payments/party-summaries/export?package_id=5&start_date=2024-01-15&end_date=2024-01-22

# Compact view for a location
GET /api/payments/party-summaries/export?location_id=2&view_mode=compact&date=2024-01-15

# Stream PDF instead of download
GET /api/payments/party-summaries/export?date=2024-01-15&stream=true
```

**Response:**
- Content-Type: `application/pdf`
- Downloads or streams a PDF file with party summaries

**Filename Format:**
- Single date: `party-summaries-2024-01-15.pdf`
- Date range: `party-summaries-2024-01-15-to-2024-01-22.pdf`
- With package: `party-summaries-laser-tag-party-2024-01-15.pdf`

---

### 2. Day-Specific Party Summaries (Shortcut)

**Endpoint:** `GET /api/payments/party-summaries/day/{date}`

**Description:** Quick export for a specific day.

**Path Parameters:**
- `date`: Date in Y-m-d format (e.g., `2024-01-15`)

**Query Parameters:**
All parameters from general export (except `date`) are supported.

**Example Requests:**

```bash
# All bookings for January 15, 2024
GET /api/payments/party-summaries/day/2024-01-15

# With location filter
GET /api/payments/party-summaries/day/2024-01-15?location_id=2

# Compact view
GET /api/payments/party-summaries/day/2024-01-15?view_mode=compact
```

---

### 3. Week-Specific Party Summaries (Shortcut)

**Endpoint:** `GET /api/payments/party-summaries/week/{week?}`

**Description:** Quick export for a week period.

**Path Parameters:**
- `week` (optional): `current` (default), `next`, or specific date (Y-m-d)

**Query Parameters:**
All parameters from general export (except `week` and `date`) are supported.

**Example Requests:**

```bash
# Current week
GET /api/payments/party-summaries/week
GET /api/payments/party-summaries/week/current

# Next week
GET /api/payments/party-summaries/week/next

# Week containing a specific date
GET /api/payments/party-summaries/week/2024-01-15

# With filters
GET /api/payments/party-summaries/week/current?location_id=2&status=confirmed
```

---

## 💼 Package-Specific Invoices API

### Purpose
Export all payment invoices for bookings of a specific package in a consistent invoice PDF format, useful for financial reporting and tracking package-specific revenue.

### Features
- Consistent invoice design across all payments
- Grouped by package for easy tracking
- Complete payment details (invoice number, customer, date, method, status)
- Transaction IDs for reference
- Summary statistics (total invoices, amount collected, pending)
- Payment status breakdown
- Date range filtering
- Location filtering

---

## API Endpoint

### Package Invoices Export

**Endpoint:** `GET /api/payments/package-invoices/export`

**Description:** Export all invoices for bookings of a specific package.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `package_id` | integer | **Yes** | The package to generate invoices for |
| `date` | date (Y-m-d) | No | Filter by specific date |
| `start_date` | date (Y-m-d) | No | Start of date range |
| `end_date` | date (Y-m-d) | No | End of date range |
| `location_id` | integer | No | Filter by location |
| `status` | string | No | Filter by payment status (`pending`, `completed`, `failed`, `refunded`) |
| `stream` | boolean | No | Set to `true` to stream PDF instead of download |

**Example Requests:**

```bash
# All invoices for a package
GET /api/payments/package-invoices/export?package_id=5

# Package invoices for a specific date
GET /api/payments/package-invoices/export?package_id=5&date=2024-01-15

# Package invoices for a date range
GET /api/payments/package-invoices/export?package_id=5&start_date=2024-01-01&end_date=2024-01-31

# Package invoices for a location
GET /api/payments/package-invoices/export?package_id=5&location_id=2

# Only completed payments
GET /api/payments/package-invoices/export?package_id=5&status=completed

# Combined filters
GET /api/payments/package-invoices/export?package_id=5&start_date=2024-01-15&end_date=2024-01-22&location_id=2&status=completed

# Stream PDF
GET /api/payments/package-invoices/export?package_id=5&stream=true
```

**Response:**
- Content-Type: `application/pdf`
- Downloads or streams a PDF file with package invoices

**Filename Format:**
- Package only: `invoices-laser-tag-party-2024-01-15.pdf`
- With date: `invoices-laser-tag-party-2024-01-15.pdf`
- With range: `invoices-laser-tag-party-2024-01-01-to-2024-01-31.pdf`

---

## PDF Report Details

### Party Summaries PDF

**Header Information:**
- Report title: "Party Summaries - Staff Organization"
- Company and location name
- Date range or specific date
- Package name (if filtered by package)

**Summary Statistics Box:**
- Total Parties
- Total Guests
- Total Revenue
- Total Paid

**Each Booking Card Includes:**
1. **Header (colored bar)**
   - Reference number
   - Package name
   - Booking time and duration

2. **Customer Details**
   - Customer name (from customer record or guest name)
   - Contact information (email and phone)
   - Number of participants

3. **Booking Information**
   - Room assignment
   - Status badge (pending, confirmed, checked-in, completed)
   - Payment status badge (paid, partial, pending)
   - Amount paid vs. total amount

4. **Guest of Honor Section** (if applicable)
   - Name
   - Age
   - Gender

5. **Attractions and Add-ons**
   - List of attractions with quantities
   - List of add-ons with quantities

6. **Special Requests**
   - Highlighted section for special requests

7. **Notes Section** (if present)
   - Customer notes
   - Internal staff notes (clearly marked)

**View Modes:**
- **Detailed**: More spacing, 2 bookings per page
- **Compact**: Space-saving, more bookings per page

---

### Package Invoices PDF

**Header Information:**
- Report title: "Package Invoices Report"
- Package name (prominent display)
- Company and location name
- Date range or specific date

**Summary Box (gradient background):**
- Total Invoices
- Unique Bookings
- Total Amount
- Amount Collected

**Invoice Table:**
Each row contains:
- Invoice number (INV-XXXXXX format)
- Booking reference number
- Customer name and email
- Payment date and time
- Payment method badge (CARD/CASH)
- Status badge (completed, pending, failed, refunded)
- Amount
- Transaction ID

**Payment Status Breakdown:**
- Completed Payments (count and amount)
- Pending Payments (count and amount)
- Refunded Payments (count and amount, if any)

**Totals Section:**
- Total number of invoices
- Total amount across all invoices

---

## Use Cases

### 1. Daily Staff Organization
**Scenario:** Staff needs to see all parties happening today to prepare rooms and equipment.

```bash
# Morning check - see all parties for today
GET /api/payments/party-summaries/day/2024-01-15

# Or use compact view if many bookings
GET /api/payments/party-summaries/day/2024-01-15?view_mode=compact
```

**What Staff Gets:**
- Complete schedule with times
- Room assignments
- Customer contact info
- Special requests to prepare for
- Notes from booking staff

---

### 2. Weekly Planning
**Scenario:** Manager wants to review next week's bookings to allocate staff.

```bash
# See all confirmed bookings for next week
GET /api/payments/party-summaries/week/next?status=confirmed
```

**What Manager Gets:**
- Week overview
- Total number of parties and guests
- Revenue forecast
- Staffing requirements

---

### 3. Package-Specific Financial Reporting
**Scenario:** Finance department needs all invoices for the "Laser Tag Party" package in January.

```bash
# Get all Laser Tag Party invoices for January 2024
GET /api/payments/package-invoices/export?package_id=5&start_date=2024-01-01&end_date=2024-01-31
```

**What Finance Gets:**
- Every invoice for the package
- Payment methods used
- Status of each payment
- Total collected vs. pending
- Transaction IDs for reconciliation

---

### 4. Location-Specific Analysis
**Scenario:** Location manager wants to see performance of a specific package at their location.

```bash
# Package invoices for a specific location
GET /api/payments/package-invoices/export?package_id=5&location_id=2&start_date=2024-01-01&end_date=2024-01-31
```

**What Location Manager Gets:**
- All invoices for their location only
- Revenue breakdown
- Payment status
- Customer information

---

## Error Handling

### No Results Found

**Response:** `404 Not Found`
```json
{
  "success": false,
  "message": "No bookings found for the specified criteria"
}
```
or
```json
{
  "success": false,
  "message": "No payments found for this package with the specified criteria"
}
```

### Validation Errors

**Response:** `422 Unprocessable Entity`
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "package_id": ["The package id field is required."],
    "end_date": ["The end date must be after or equal to start date."]
  }
}
```

---

## Technical Implementation

### Controllers
- **PaymentController**: Contains all party summary and package invoice methods
  - `partySummariesExport()`: Main party summary export
  - `partySummariesDay()`: Day shortcut
  - `partySummariesWeek()`: Week shortcut
  - `packageInvoicesExport()`: Package invoice export

### Views
- **resources/views/exports/party-summaries-staff.blade.php**: Party summary PDF template
- **resources/views/exports/package-invoices-report.blade.php**: Package invoice PDF template

### Routes
- Defined in `routes/api.php` under the authenticated admin middleware group

### Database Queries
- Party summaries query the `bookings` table with relationships
- Package invoices query the `payments` table filtered by package through booking relationship
- All queries use eager loading for optimal performance

---

## Best Practices

### For Party Summaries
1. **Use date filters** to limit results to relevant bookings
2. **Choose view mode** based on number of bookings:
   - Detailed: < 10 bookings
   - Compact: > 10 bookings
3. **Stream PDFs** for quick preview in browser
4. **Filter by status** to exclude cancelled bookings (default behavior)

### For Package Invoices
1. **Always specify package_id** (required parameter)
2. **Use date ranges** for financial period reporting
3. **Filter by status** to separate completed from pending payments
4. **Combine with location_id** for multi-location analysis

### General Tips
- Use the shortcut endpoints (`day`, `week`) for common scenarios
- Add `stream=true` for quick in-browser viewing
- Keep date ranges reasonable (< 90 days) for optimal performance
- Consider file size when generating reports with many records

---

## Integration with Frontend

### Example JavaScript/TypeScript

```typescript
// Party summaries for today
async function getTodayPartySummaries(locationId?: number) {
  const today = new Date().toISOString().split('T')[0];
  let url = `/api/payments/party-summaries/day/${today}`;
  
  if (locationId) {
    url += `?location_id=${locationId}`;
  }
  
  const response = await fetch(url, {
    headers: {
      'Authorization': `Bearer ${token}`,
    }
  });
  
  // Trigger download
  const blob = await response.blob();
  const downloadUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = downloadUrl;
  a.download = `party-summaries-${today}.pdf`;
  a.click();
}

// Package invoices
async function getPackageInvoices(packageId: number, startDate: string, endDate: string) {
  const url = `/api/payments/package-invoices/export?package_id=${packageId}&start_date=${startDate}&end_date=${endDate}`;
  
  const response = await fetch(url, {
    headers: {
      'Authorization': `Bearer ${token}`,
    }
  });
  
  const blob = await response.blob();
  const downloadUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = downloadUrl;
  a.download = `package-invoices-${startDate}-${endDate}.pdf`;
  a.click();
}

// Stream PDF in iframe
async function previewPartySummaries(date: string) {
  const url = `/api/payments/party-summaries/day/${date}?stream=true`;
  
  const iframe = document.getElementById('pdf-preview') as HTMLIFrameElement;
  iframe.src = url;
}
```

---

## Summary

Both reporting features are fully functional and ready to use:

✅ **Party Summaries**: Comprehensive daily/weekly booking reports for staff organization  
✅ **Package Invoices**: Financial reports for specific packages with consistent invoice format  
✅ **Flexible Filtering**: Date ranges, locations, statuses, packages  
✅ **Professional PDFs**: Well-formatted, print-ready documents  
✅ **Multiple Access Methods**: General export, day shortcuts, week shortcuts  
✅ **Complete Documentation**: All endpoints, parameters, and use cases covered  

These features enhance staff workflow and financial reporting capabilities significantly!
