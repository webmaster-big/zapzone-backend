# Accept.js Payment Integration Guide

## Overview
This endpoint processes payments using Authorize.Net's Accept.js, which tokenizes payment data on the client-side before sending to the server. This ensures PCI compliance as card data never touches your server.

## Endpoint

```
POST /api/payments/charge
```

## Request Format

```json
{
  "location_id": 1,
  "opaqueData": {
    "dataDescriptor": "COMMON.ACCEPT.INAPP.PAYMENT",
    "dataValue": "eyJjb2RlIjoiNTBfMl8wNjAwMDUyN..."
  },
  "amount": 99.99,
  "order_id": "ORDER-123",
  "customer_id": 5,
  "booking_id": 10,
  "description": "Package booking payment"
}
```

### Required Fields
- `location_id` - The location processing the payment
- `opaqueData` - Tokenized payment data from Accept.js
  - `dataDescriptor` - Usually "COMMON.ACCEPT.INAPP.PAYMENT"
  - `dataValue` - Encrypted payment token from Accept.js
- `amount` - Payment amount (decimal)

### Optional Fields
- `order_id` - Your order/invoice number
- `customer_id` - Customer ID from your database
- `booking_id` - Booking ID if applicable
- `description` - Payment description

## Response Format

### Success Response (200)
```json
{
  "success": true,
  "message": "Payment processed successfully",
  "transaction_id": "60198581875",
  "auth_code": "ABC123",
  "payment": {
    "id": 1,
    "booking_id": 10,
    "customer_id": 5,
    "location_id": 1,
    "amount": "99.99",
    "currency": "USD",
    "method": "card",
    "status": "completed",
    "transaction_id": "60198581875",
    "payment_id": "60198581875",
    "notes": "Package booking payment",
    "paid_at": "2025-11-29T10:30:00.000000Z",
    "created_at": "2025-11-29T10:30:00.000000Z"
  }
}
```

### Error Response (400/500/503)
```json
{
  "success": false,
  "message": "Error description here"
}
```

## Frontend Integration Example (React)

### Step 1: Include Accept.js Script

```html
<!-- Add to your HTML head -->
<!-- Sandbox -->
<script type="text/javascript" 
  src="https://jstest.authorize.net/v1/Accept.js" 
  charset="utf-8">
</script>

<!-- Production -->
<!-- <script type="text/javascript" 
  src="https://js.authorize.net/v1/Accept.js" 
  charset="utf-8">
</script> -->
```

### Step 2: Get API Login ID from Backend

First, you need to get the public client key (API Login ID) for the location:

```javascript
// Create a new endpoint in AuthorizeNetAccountController or use this:
const getPublicKey = async (locationId) => {
  const response = await fetch(`/api/locations/${locationId}/authorize-public-key`);
  const data = await response.json();
  return data.api_login_id; // Only expose API Login ID, NOT transaction key
};
```

### Step 3: Payment Form Component

```jsx
import React, { useState } from 'react';

const PaymentForm = ({ locationId, amount, orderId, onSuccess, onError }) => {
  const [loading, setLoading] = useState(false);
  const [formData, setFormData] = useState({
    cardNumber: '',
    cardCode: '',
    expMonth: '',
    expYear: '',
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);

    try {
      // 1. Get API Login ID for this location
      const apiLoginId = await getPublicKey(locationId);

      // 2. Prepare card data for Accept.js
      const authData = {
        clientKey: apiLoginId,
        apiLoginID: apiLoginId
      };

      const cardData = {
        cardNumber: formData.cardNumber,
        month: formData.expMonth,
        year: formData.expYear,
        cardCode: formData.cardCode
      };

      // 3. Tokenize with Accept.js
      window.Accept.dispatchData({ authData, cardData }, async (response) => {
        if (response.messages.resultCode === "Error") {
          const errors = response.messages.message.map(m => m.text).join(', ');
          onError(errors);
          setLoading(false);
          return;
        }

        // 4. Send tokenized data to your backend
        try {
          const paymentResponse = await fetch('/api/payments/charge', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Authorization': `Bearer ${yourAuthToken}`,
            },
            body: JSON.stringify({
              location_id: locationId,
              opaqueData: {
                dataDescriptor: response.opaqueData.dataDescriptor,
                dataValue: response.opaqueData.dataValue,
              },
              amount: amount,
              order_id: orderId,
              description: 'Payment description',
            }),
          });

          const result = await paymentResponse.json();

          if (result.success) {
            onSuccess(result);
          } else {
            onError(result.message);
          }
        } catch (error) {
          onError('Payment failed: ' + error.message);
        } finally {
          setLoading(false);
        }
      });

    } catch (error) {
      onError('Failed to process payment: ' + error.message);
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <div>
        <label>Card Number</label>
        <input
          type="text"
          value={formData.cardNumber}
          onChange={(e) => setFormData({ ...formData, cardNumber: e.target.value })}
          placeholder="4111 1111 1111 1111"
          required
        />
      </div>

      <div>
        <label>Expiration Month</label>
        <input
          type="text"
          value={formData.expMonth}
          onChange={(e) => setFormData({ ...formData, expMonth: e.target.value })}
          placeholder="12"
          maxLength="2"
          required
        />
      </div>

      <div>
        <label>Expiration Year</label>
        <input
          type="text"
          value={formData.expYear}
          onChange={(e) => setFormData({ ...formData, expYear: e.target.value })}
          placeholder="2025"
          maxLength="4"
          required
        />
      </div>

      <div>
        <label>CVV</label>
        <input
          type="text"
          value={formData.cardCode}
          onChange={(e) => setFormData({ ...formData, cardCode: e.target.value })}
          placeholder="123"
          maxLength="4"
          required
        />
      </div>

      <button type="submit" disabled={loading}>
        {loading ? 'Processing...' : `Pay $${amount}`}
      </button>
    </form>
  );
};

export default PaymentForm;
```

### Step 4: Usage Example

```jsx
const CheckoutPage = () => {
  const handleSuccess = (result) => {
    console.log('Payment successful:', result);
    alert(`Payment successful! Transaction ID: ${result.transaction_id}`);
    // Redirect or update UI
  };

  const handleError = (error) => {
    console.error('Payment error:', error);
    alert(`Payment failed: ${error}`);
  };

  return (
    <div>
      <h1>Checkout</h1>
      <PaymentForm
        locationId={1}
        amount={99.99}
        orderId="ORDER-123"
        onSuccess={handleSuccess}
        onError={handleError}
      />
    </div>
  );
};
```

## Vanilla JavaScript Example

```javascript
function processPayment(locationId, amount, orderId) {
  // Get form data
  const cardNumber = document.getElementById('cardNumber').value;
  const expMonth = document.getElementById('expMonth').value;
  const expYear = document.getElementById('expYear').value;
  const cardCode = document.getElementById('cardCode').value;

  // Get API Login ID (you need to fetch this from your backend)
  fetch(`/api/locations/${locationId}/authorize-public-key`)
    .then(res => res.json())
    .then(data => {
      const authData = {
        clientKey: data.api_login_id,
        apiLoginID: data.api_login_id
      };

      const cardData = {
        cardNumber: cardNumber,
        month: expMonth,
        year: expYear,
        cardCode: cardCode
      };

      // Tokenize with Accept.js
      Accept.dispatchData({ authData, cardData }, function(response) {
        if (response.messages.resultCode === "Error") {
          alert('Error: ' + response.messages.message[0].text);
          return;
        }

        // Send to backend
        fetch('/api/payments/charge', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + yourAuthToken
          },
          body: JSON.stringify({
            location_id: locationId,
            opaqueData: {
              dataDescriptor: response.opaqueData.dataDescriptor,
              dataValue: response.opaqueData.dataValue
            },
            amount: amount,
            order_id: orderId
          })
        })
        .then(res => res.json())
        .then(result => {
          if (result.success) {
            alert('Payment successful! Transaction ID: ' + result.transaction_id);
          } else {
            alert('Payment failed: ' + result.message);
          }
        })
        .catch(error => {
          alert('Error: ' + error.message);
        });
      });
    });
}
```

## Testing

### Sandbox Test Card Numbers

Use these test cards in sandbox mode:

- **Visa**: 4111111111111111
- **Mastercard**: 5424000000000015
- **Amex**: 370000000000002
- **Discover**: 6011000000000012

**CVV**: Any 3-4 digit number  
**Expiration**: Any future date

## Security Notes

1. **Never send card data directly to your server** - Always use Accept.js to tokenize first
2. **Only expose API Login ID** - Never expose the Transaction Key to the frontend
3. **Use HTTPS** - Always use HTTPS in production
4. **Validate on backend** - Always validate amounts and order details on the server
5. **PCI Compliance** - Accept.js handles tokenization, keeping you PCI compliant

## Error Handling

Common error scenarios:

1. **No Authorize.Net account** (503): Location hasn't connected their account yet
2. **Invalid card** (400): Card was declined or invalid
3. **Insufficient funds** (400): Card has insufficient funds
4. **Expired card** (400): Card is expired
5. **Invalid amount** (422): Amount validation failed

## Additional Resources

- [Accept.js Documentation](https://developer.authorize.net/api/reference/features/acceptjs.html)
- [Authorize.Net Testing Guide](https://developer.authorize.net/hello_world/testing_guide/)
- [PCI Compliance](https://www.authorize.net/payments/security/pci-compliance/)

---

**Note**: Make sure the location has an active Authorize.Net account connected before attempting to process payments.
