# Authorize.Net Customer Billing Data Integration

## Overview
Customer billing information is now sent to Authorize.Net with every payment transaction. This allows merchants to view customer details in their Authorize.Net dashboard for better transaction management and customer service.

## Frontend Implementation

### Step 1: Prepare Customer Data
When processing a payment, format your customer data as follows:

```javascript
const customerData = {
  first_name: form.firstName,
  last_name: form.lastName,
  email: form.email,
  phone: form.phone,
  address: form.address,
  city: form.city,
  state: form.state,
  zip: form.zip,
  country: form.country,
};
```

### Step 2: Send Payment Request
Include the customer data in your payment API call:

```javascript
const response = await fetch('/api/payments/charge', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`,
  },
  body: JSON.stringify({
    location_id: locationId,
    opaqueData: {
      dataDescriptor: opaqueDataDescriptor, // From Accept.js
      dataValue: opaqueDataValue,           // From Accept.js
    },
    amount: totalAmount,
    order_id: `PKG-${packageId}-${Date.now()}`,
    description: `Package Booking: ${packageName}`,
    customer: customerData, // Include customer billing data
    customer_id: customerId, // Optional: If customer exists in your system
    booking_id: bookingId,   // Optional: Link to booking
  }),
});
```

### Step 3: Handle Booking Creation
When creating a booking, include billing fields:

```javascript
const bookingData = {
  // Guest information
  guest_name: `${form.firstName} ${form.lastName}`,
  guest_email: form.email,
  guest_phone: form.phone,
  
  // Billing address (NEW!)
  guest_address: form.address,
  guest_city: form.city,
  guest_state: form.state,
  guest_zip: form.zip,
  guest_country: form.country,
  
  // Booking details
  location_id: pkg.location_id,
  package_id: pkg.id,
  room_id: selectedRoomId,
  booking_date: selectedDate,
  booking_time: selectedTime,
  participants: participants,
  
  // Payment information
  total_amount: total,
  amount_paid: amountToPay,
  payment_method: 'card',
  payment_status: paymentType === 'full' ? 'paid' : 'partial',
  transaction_id: paymentResponse.transaction_id, // From payment API response
  
  // Additional items
  additional_attractions: additionalAttractions,
  additional_addons: additionalAddons,
  
  // Optional
  promo_code: appliedPromo?.code,
  gift_card_code: appliedGiftCard?.code,
  notes: form.notes,
};
```

## Backend Database Schema

### Bookings Table
The following fields have been added to store guest billing information:

- `guest_address` - varchar(255), nullable
- `guest_city` - varchar(100), nullable
- `guest_state` - varchar(50), nullable
- `guest_zip` - varchar(20), nullable
- `guest_country` - varchar(100), nullable
- `transaction_id` - varchar(255), nullable, indexed

## What Merchants See in Authorize.Net

When viewing a transaction in the Authorize.Net Merchant Interface, merchants will now see:

**Customer Information:**
- Name: John Doe
- Email: john.doe@example.com
- Phone: (555) 123-4567

**Billing Address:**
- Address: 123 Main Street
- City: Los Angeles
- State: CA
- ZIP: 90001
- Country: USA

**Order Details:**
- Invoice Number: PKG-42-1702123456789
- Description: Package Booking: Laser Tag Ultimate

## Field Constraints

### Authorize.Net Field Limits
The following field length limits are enforced to comply with Authorize.Net API requirements:

| Field | Max Length | Description |
|-------|-----------|-------------|
| `first_name` | 50 | Customer first name |
| `last_name` | 50 | Customer last name |
| `email` | 255 | Customer email address |
| `phone` | 25 | Customer phone number |
| `address` | 60 | Street address |
| `city` | 40 | City name |
| `state` | 40 | State/Province |
| `zip` | 20 | Postal/ZIP code |
| `country` | 60 | Country name |

The backend automatically truncates fields that exceed these limits.

## Benefits

1. **Better Customer Service**: Merchants can contact customers directly from transaction records
2. **Dispute Resolution**: Full billing information available for chargebacks
3. **Transaction Matching**: Easy to match transactions to customers
4. **Compliance**: Proper billing records for accounting and tax purposes
5. **Fraud Prevention**: Complete customer data helps identify suspicious transactions

## Testing

### Sandbox Testing
When testing with Authorize.Net Sandbox:

```javascript
const testCustomerData = {
  first_name: "Test",
  last_name: "Customer",
  email: "test@example.com",
  phone: "555-123-4567",
  address: "123 Test Street",
  city: "Testville",
  state: "CA",
  zip: "12345",
  country: "USA",
};
```

### Production Checklist
- ✅ All billing fields collected in checkout form
- ✅ Email validation implemented
- ✅ Phone number formatting consistent
- ✅ State/Province dropdown (not free text)
- ✅ ZIP/Postal code validation
- ✅ Country selection (ISO country codes recommended)

## Error Handling

```javascript
try {
  const paymentResponse = await processPayment(paymentData, customerData);
  
  if (!paymentResponse.success) {
    // Handle payment failure
    console.error('Payment failed:', paymentResponse.message);
    throw new Error(paymentResponse.message);
  }
  
  // Proceed with booking creation
  const booking = await createBooking({
    ...bookingData,
    transaction_id: paymentResponse.transaction_id,
  });
  
} catch (error) {
  console.error('Booking error:', error);
  // Show user-friendly error message
}
```

## Optional vs Required Fields

### Always Include (Recommended):
- `first_name` ✅
- `last_name` ✅
- `email` ✅
- `phone` ✅

### Include When Available:
- `address` (strongly recommended)
- `city` (strongly recommended)
- `state` (recommended)
- `zip` (recommended)
- `country` (optional, defaults to USA if not provided)

**Note**: While address fields are optional in the API, including them provides significant value for merchants. Consider making them required in your checkout flow.

## API Endpoints

### Payment Processing
```
POST /api/payments/charge
```

**Request Body:**
```json
{
  "location_id": 1,
  "opaqueData": {
    "dataDescriptor": "COMMON.ACCEPT.INAPP.PAYMENT",
    "dataValue": "eyJjb2RlIjoiNTBfMl8wNjAwMDUzMEE4..."
  },
  "amount": 99.99,
  "order_id": "PKG-42-1702123456",
  "description": "Package Booking: Laser Tag",
  "customer": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john@example.com",
    "phone": "555-123-4567",
    "address": "123 Main St",
    "city": "Los Angeles",
    "state": "CA",
    "zip": "90001",
    "country": "USA"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Payment processed successfully",
  "data": {
    "payment_id": 123,
    "transaction_id": "60198765432",
    "auth_code": "ABC123",
    "amount": 99.99
  }
}
```

### Booking Creation
```
POST /api/bookings
```

**Request Body:**
```json
{
  "guest_name": "John Doe",
  "guest_email": "john@example.com",
  "guest_phone": "555-123-4567",
  "guest_address": "123 Main St",
  "guest_city": "Los Angeles",
  "guest_state": "CA",
  "guest_zip": "90001",
  "guest_country": "USA",
  "location_id": 1,
  "package_id": 42,
  "room_id": 5,
  "booking_date": "2025-12-15",
  "booking_time": "14:00",
  "participants": 6,
  "duration": 60,
  "duration_unit": "minutes",
  "total_amount": 99.99,
  "amount_paid": 99.99,
  "payment_method": "card",
  "payment_status": "paid",
  "transaction_id": "60198765432",
  "additional_attractions": [],
  "additional_addons": [],
  "notes": "Birthday party group"
}
```

## Migration Guide

If you have existing code without customer billing data:

### Before:
```javascript
await createBooking({
  guest_name: name,
  guest_email: email,
  guest_phone: phone,
  // ...other fields
});
```

### After:
```javascript
await createBooking({
  guest_name: name,
  guest_email: email,
  guest_phone: phone,
  guest_address: address,      // ADD
  guest_city: city,              // ADD
  guest_state: state,            // ADD
  guest_zip: zip,                // ADD
  guest_country: country,        // ADD
  transaction_id: txnId,         // ADD
  // ...other fields
});
```

## Support

For questions or issues:
- Review the Authorize.Net Accept.js documentation
- Check Laravel logs: `storage/logs/laravel.log`
- Test in Authorize.Net Sandbox first
- Contact technical support with transaction ID for troubleshooting
