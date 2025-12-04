# Authorize.Net Multi-Location Account Management

## Overview
This system allows each location to securely connect and manage their own Authorize.Net payment account. Location managers can connect, update, test, and disconnect their Authorize.Net credentials through the frontend settings page.

## Security Features

### 1. **Encrypted Storage**
- API Login ID and Transaction Key are automatically encrypted using Laravel's `Crypt` facade
- Encryption happens transparently in the model using Laravel Attributes
- Credentials are never exposed in API responses
- Stored in `TEXT` columns to accommodate encrypted data length

### 2. **Access Control**
- Only authenticated users with assigned locations can manage accounts
- Users can only access/modify their own location's Authorize.Net account
- Role-based permissions (location_manager, admin, super_admin)
- Authorization enforced via Laravel Policies

### 3. **Validation**
- FormRequest classes ensure data integrity
- API credentials are validated before storage
- Environment must be either 'sandbox' or 'production'

## Database Schema

### Table: `authorize_net_accounts`
```sql
- id (primary key)
- location_id (foreign key, unique) - One account per location
- api_login_id (text, encrypted)
- transaction_key (text, encrypted)
- environment (enum: sandbox, production)
- is_active (boolean)
- connected_at (timestamp)
- last_tested_at (timestamp)
- created_at (timestamp)
- updated_at (timestamp)
```

## API Endpoints

All endpoints are protected with `auth:sanctum` middleware.

### 1. **Get Account Status**
```
GET /api/authorize-net/account
```
**Response:**
```json
{
  "connected": true,
  "account": {
    "id": 1,
    "location_id": 5,
    "environment": "sandbox",
    "is_active": true,
    "connected_at": "2025-11-28T10:30:00.000000Z",
    "last_tested_at": "2025-11-28T11:00:00.000000Z"
  }
}
```

### 2. **Connect Account (Create)**
```
POST /api/authorize-net/account
Content-Type: application/json

{
  "api_login_id": "your_api_login_id",
  "transaction_key": "your_transaction_key",
  "environment": "sandbox"
}
```

**Response:**
```json
{
  "message": "Authorize.Net account connected successfully",
  "account": {
    "id": 1,
    "location_id": 5,
    "environment": "sandbox",
    "is_active": true,
    "connected_at": "2025-11-28T10:30:00.000000Z"
  }
}
```

### 3. **Update Account**
```
PUT /api/authorize-net/account
Content-Type: application/json

{
  "environment": "production",
  "is_active": true
}
```

### 4. **Test Connection**
```
POST /api/authorize-net/account/test
```

**Response:**
```json
{
  "message": "Connection test successful",
  "tested_at": "2025-11-28T11:00:00.000000Z"
}
```

### 5. **Disconnect Account (Delete)**
```
DELETE /api/authorize-net/account
```

**Response:**
```json
{
  "message": "Authorize.Net account disconnected successfully"
}
```

## Usage Examples

### Frontend Integration Example (React/Vue)

```javascript
// Check if account is connected
const checkConnection = async () => {
  const response = await fetch('/api/authorize-net/account', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  const data = await response.json();
  return data.connected;
};

// Connect new account
const connectAccount = async (credentials) => {
  const response = await fetch('/api/authorize-net/account', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({
      api_login_id: credentials.loginId,
      transaction_key: credentials.transactionKey,
      environment: credentials.environment // 'sandbox' or 'production'
    })
  });
  return await response.json();
};

// Disconnect account
const disconnectAccount = async () => {
  const response = await fetch('/api/authorize-net/account', {
    method: 'DELETE',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/json'
    }
  });
  return await response.json();
};
```

### Backend Usage - Processing Payments

```php
use App\Services\AuthorizeNetPaymentService;

// In your payment controller or service
$paymentService = new AuthorizeNetPaymentService();

// Initialize with a location
$paymentService->forLocation($booking->location);

// Get credentials (for direct API usage)
$credentials = $paymentService->getCredentials();

// Process a payment
$result = $paymentService->chargeTransaction([
    'amount' => 99.99,
    'card_number' => '4111111111111111',
    'expiration_date' => '12/25',
    // ... other payment data
]);

// Process a refund
$refund = $paymentService->refundTransaction('TRANS_ID_123', 50.00);

// Test connection
$testResult = $paymentService->testConnection();
```

## Model Relationships

### Location Model
```php
$location->authorizeNetAccount; // HasOne relationship
```

### AuthorizeNetAccount Model
```php
$account->location; // BelongsTo relationship
$account->isProduction(); // Check if production mode
$account->isSandbox(); // Check if sandbox mode
$account->markAsTested(); // Update last_tested_at timestamp
```

## Security Best Practices

1. **Never log or expose credentials**
   - The `api_login_id` and `transaction_key` are in the `$hidden` array
   - Never return these in API responses
   - Logging is done without exposing sensitive data

2. **Use HTTPS**
   - Always use HTTPS in production to protect credentials in transit

3. **Environment Variables**
   - Ensure `APP_KEY` is set in `.env` for encryption
   - Never commit `.env` file to version control

4. **Regular Key Rotation**
   - Encourage location managers to rotate their Authorize.Net credentials periodically

5. **Audit Logging**
   - All account operations are logged (without sensitive data)
   - Monitor logs for suspicious activity

## Migration

Run the migration:
```bash
php artisan migrate
```

Rollback if needed:
```bash
php artisan migrate:rollback
```

## Testing

### Manual Testing Steps

1. **Connect Account:**
   - POST to `/api/authorize-net/account` with test credentials
   - Verify account is created and encrypted in database

2. **Verify Encryption:**
   - Check database directly - credentials should be encrypted strings
   - Fetch via API - credentials should NOT appear in response

3. **Test Authorization:**
   - Try accessing another location's account - should be denied
   - Try without authentication - should be denied

4. **Test Connection:**
   - POST to `/api/authorize-net/account/test`
   - Verify `last_tested_at` is updated

5. **Disconnect:**
   - DELETE request should remove account
   - Verify database record is deleted

## Future Enhancements

1. **Actual Authorize.Net SDK Integration**
   - Install `authorizenet/authorizenet` package
   - Implement real API calls in `AuthorizeNetPaymentService`
   - Add transaction history tracking

2. **Webhook Support**
   - Handle payment notifications from Authorize.Net
   - Update transaction statuses automatically

3. **Multi-Currency Support**
   - Support different currencies per location

4. **Advanced Reporting**
   - Transaction analytics per location
   - Revenue tracking and reconciliation

## Support

For issues or questions:
- Check Laravel logs: `storage/logs/laravel.log`
- Review Authorize.Net documentation: https://developer.authorize.net/
- Contact system administrator

## Files Created

1. **Migration:** `database/migrations/2025_11_28_192733_create_authorize_net_accounts_table.php`
2. **Model:** `app/Models/AuthorizeNetAccount.php`
3. **Controller:** `app/Http/Controllers/Api/AuthorizeNetAccountController.php`
4. **Policy:** `app/Policies/AuthorizeNetAccountPolicy.php`
5. **Requests:** 
   - `app/Http/Requests/StoreAuthorizeNetAccountRequest.php`
   - `app/Http/Requests/UpdateAuthorizeNetAccountRequest.php`
6. **Service:** `app/Services/AuthorizeNetPaymentService.php`
7. **Routes:** Added to `routes/api.php`
8. **Updated:** `app/Models/Location.php` (added relationship)
