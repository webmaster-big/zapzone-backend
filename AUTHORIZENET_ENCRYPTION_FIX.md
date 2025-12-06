# Authorize.Net Encryption Issue Fix

## Problem

**Error Message:** `"The MAC is invalid"` when calling `/api/authorize-net/public-key/{locationId}`

**Root Cause:** The `APP_KEY` in your production environment is different from the key used when the Authorize.Net credentials were encrypted and stored in the database. Laravel's encryption uses the APP_KEY, and when it changes, previously encrypted data cannot be decrypted.

## Symptoms

- 500 Internal Server Error when accessing Authorize.Net endpoints
- Error in logs: "The MAC is invalid"
- Frontend shows: "Failed to initialize Authorize.Net"

## Solutions

### Option 1: Re-enter Credentials (Recommended - Simple)

The simplest solution is to disconnect and reconnect the Authorize.Net account in the admin panel:

1. Log into the admin panel on production
2. Navigate to Authorize.Net settings
3. Disconnect the current account
4. Reconnect by entering the credentials again

This will encrypt the credentials with the current production APP_KEY.

### Option 2: Use Correct APP_KEY (If Available)

If you have access to the original APP_KEY that was used to encrypt the data:

1. SSH into your production server
2. Update `.env` with the original APP_KEY
3. Run: `php artisan config:clear`
4. Verify the credentials can be decrypted

### Option 3: Re-encrypt Existing Data (Advanced)

If you want to keep existing data but change the APP_KEY, use the re-encryption command:

```bash
# First, backup your database!
mysqldump -u [user] -p [database] > backup.sql

# Test the re-encryption (dry run)
php artisan authorizenet:re-encrypt --old-key="base64:OLD_KEY_HERE" --test

# Actually re-encrypt the data
php artisan authorizenet:re-encrypt --old-key="base64:OLD_KEY_HERE"
```

### Option 4: Direct Database Update (Emergency)

If you need immediate access and have the credentials:

```sql
-- WARNING: Backup first!
-- Connect to production database

-- Delete the corrupted account (will need to be re-added)
DELETE FROM authorize_net_accounts WHERE location_id = 1;

-- Then re-add through the admin panel
```

## Prevention

To prevent this issue in the future:

1. **Never change APP_KEY in production** without re-encrypting data
2. **Use the same APP_KEY** across all environments if sharing the database
3. **Backup before key changes**: Always backup your database before changing APP_KEY
4. **Document your APP_KEY**: Store it securely in a password manager

## Production Deployment Checklist

When deploying to production:

- [ ] Set APP_KEY before first deployment (run `php artisan key:generate`)
- [ ] Never change APP_KEY after data is encrypted
- [ ] If you must change APP_KEY, use the re-encryption command
- [ ] Keep a secure backup of your APP_KEY
- [ ] Test encryption/decryption after deployment

## Technical Details

The `AuthorizeNetAccount` model uses Laravel's encrypted attributes:

```php
protected function apiLoginId(): Attribute
{
    return Attribute::make(
        get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
        set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
    );
}
```

This automatically encrypts/decrypts using `APP_KEY`. If the key changes, decryption fails with "The MAC is invalid".

## Error Handling

The controller now includes proper error handling:

- Catches `DecryptException` when APP_KEY mismatch occurs
- Returns user-friendly error messages
- Logs detailed information for debugging
- Suggests reconnecting the account

## Testing

After applying a fix, test with:

```bash
# Test the API endpoint
curl -X GET https://your-domain.com/api/authorize-net/public-key/1

# Should return:
# {
#   "api_login_id": "your_login_id",
#   "environment": "sandbox"
# }
```

## Support

If issues persist:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Verify APP_KEY is set: `php artisan tinker` → `config('app.key')`
3. Test database connection: `php artisan db:show`
4. Verify account exists: `SELECT * FROM authorize_net_accounts WHERE is_active = 1;`

## Related Files

- `app/Models/AuthorizeNetAccount.php` - Model with encryption
- `app/Http/Controllers/Api/AuthorizeNetAccountController.php` - API endpoints
- `app/Console/Commands/ReEncryptAuthorizeNetCredentials.php` - Re-encryption command
- `.env` - Contains APP_KEY configuration
