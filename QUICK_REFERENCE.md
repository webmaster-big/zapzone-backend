# Quick Reference: Authorize.Net Multi-Location System

## 🔑 API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/authorize-net/account` | Check connection status |
| POST | `/api/authorize-net/account` | Connect new account |
| PUT | `/api/authorize-net/account` | Update credentials/settings |
| DELETE | `/api/authorize-net/account` | Disconnect account |
| POST | `/api/authorize-net/account/test` | Test connection |

**Authentication**: All endpoints require `Authorization: Bearer {token}`

## 📥 Request Examples

### Connect Account
```json
POST /api/authorize-net/account
{
  "api_login_id": "your_api_login_id",
  "transaction_key": "your_transaction_key",
  "environment": "sandbox"
}
```

### Update Settings
```json
PUT /api/authorize-net/account
{
  "environment": "production",
  "is_active": true
}
```

## 💻 Code Examples

### Frontend (React)
```javascript
// Check status
const response = await fetch('/api/authorize-net/account', {
  headers: { 'Authorization': `Bearer ${token}` }
});
const { connected, account } = await response.json();
```

### Backend (Laravel)
```php
use App\Services\AuthorizeNetPaymentService;

$service = new AuthorizeNetPaymentService();
$service->forLocation($booking->location);
$result = $service->chargeTransaction(['amount' => 99.99]);
```

## 🔐 Security Checklist

- [ ] HTTPS enabled
- [ ] APP_KEY unique
- [ ] .env not committed
- [ ] API authentication working
- [ ] Location isolation verified

## 📂 Key Files

- **Model**: `app/Models/AuthorizeNetAccount.php`
- **Controller**: `app/Http/Controllers/Api/AuthorizeNetAccountController.php`
- **Service**: `app/Services/AuthorizeNetPaymentService.php`
- **Migration**: `database/migrations/*_create_authorize_net_accounts_table.php`

## 🎯 Common Tasks

### Get Location's Account
```php
$account = Location::find(1)->authorizeNetAccount;
```

### Check if Connected
```php
$isConnected = Location::find(1)->authorizeNetAccount()->exists();
```

### Process Payment
```php
$service = new AuthorizeNetPaymentService();
$service->forLocation($locationId);
$result = $service->chargeTransaction($paymentData);
```

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| 404 on routes | Run `php artisan route:clear` |
| Encryption error | Check APP_KEY in .env |
| Unauthorized | Verify user has location_id |
| "Account not found" | Location hasn't connected account yet |

## 📊 Database Schema

```sql
authorize_net_accounts
├─ id
├─ location_id (UNIQUE, FK)
├─ api_login_id (ENCRYPTED)
├─ transaction_key (ENCRYPTED)
├─ environment (sandbox/production)
├─ is_active
├─ connected_at
└─ last_tested_at
```

## ⚠️ Important Notes

1. **Never log credentials** - Even encrypted
2. **One account per location** - Unique constraint on location_id
3. **Automatic encryption** - Handled by model attributes
4. **Hidden from API** - Credentials never in responses
5. **Location isolated** - Users only see their location

## 🚀 Quick Start

1. Location manager goes to Settings
2. Enters Authorize.Net credentials
3. Selects sandbox/production
4. Clicks "Connect"
5. System encrypts and stores
6. Ready to process payments!

## 📞 Support

- **Documentation**: `AUTHORIZE_NET_SETUP.md`
- **Security**: `SECURITY_CHECKLIST.md`
- **Examples**: `frontend-integration-examples.js`
- **Logs**: `storage/logs/laravel.log`

---

**Version**: 1.0  
**Last Updated**: November 28, 2025
