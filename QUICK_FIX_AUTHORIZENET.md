# Quick Fix Guide - Authorize.Net "MAC is invalid" Error

## Immediate Fix (Choose One)

### ✅ **Option 1: Reconnect Account (EASIEST - 2 minutes)**

1. SSH into production server
2. Run the deployment:
   ```bash
   cd /home/forge/zapzone-backend-yt1lm2w5.on-forge.com
   git pull origin main
   php artisan config:clear
   php artisan cache:clear
   ```

3. Have an admin user:
   - Go to Authorize.Net settings in the admin panel
   - Click "Disconnect" 
   - Click "Connect" and re-enter the API Login ID and Transaction Key
   - The credentials will be encrypted with the correct APP_KEY

**This is the recommended solution - it's fast and foolproof.**

---

### Option 2: Fix APP_KEY (if you have the original key)

If you know the original APP_KEY that was used locally:

1. SSH to production
2. Edit `.env`:
   ```bash
   nano .env
   # Replace APP_KEY with the original one from local
   ```
3. Clear config:
   ```bash
   php artisan config:clear
   ```

---

### Option 3: Use Re-encryption Command

If you want to keep existing data and have the old APP_KEY:

```bash
# Test first
php artisan authorizenet:re-encrypt --old-key="base64:YOUR_OLD_KEY" --test

# Actually re-encrypt
php artisan authorizenet:re-encrypt --old-key="base64:YOUR_OLD_KEY"
```

---

## After Deploying

1. Test the API endpoint:
   ```bash
   curl https://zapzone-backend-yt1lm2w5.on-forge.com/api/authorize-net/public-key/1
   ```

2. Should return JSON (not 500 error):
   ```json
   {
     "api_login_id": "your_id",
     "environment": "sandbox"
   }
   ```

3. Test in frontend - payment form should load without errors

## What Was Fixed

- ✅ Added error handling for encryption key mismatches
- ✅ Better error messages explaining the issue
- ✅ Created re-encryption command for APP_KEY changes
- ✅ Added validation to detect corrupted credentials
- ✅ Comprehensive documentation

## Files Changed

- `app/Http/Controllers/Api/AuthorizeNetAccountController.php`
- `app/Console/Commands/ReEncryptAuthorizeNetCredentials.php` (new)
- `AUTHORIZENET_ENCRYPTION_FIX.md` (new documentation)
