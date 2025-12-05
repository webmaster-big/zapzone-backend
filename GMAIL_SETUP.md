# Gmail API Setup for Production

## Step 1: Upload Gmail Credentials to Shared Storage

```bash
# SSH into your server
ssh forge@your-server-ip

# Navigate to the shared storage directory (OUTSIDE releases)
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app

# Create the gmail.json file
nano gmail.json

# Paste your credentials and save (Ctrl+X, Y, Enter)

# Set proper permissions
chmod 600 gmail.json
chown forge:forge gmail.json
```

## Step 2: Add Environment Variable in Laravel Forge

1. Go to your site in **Laravel Forge**
2. Click **Environment** section
3. Add this line to your `.env` file:

```bash
GMAIL_CREDENTIALS_PATH=/home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
```

4. Click **Save**
5. The server will automatically reload

## Verify Setup

```bash
# SSH into server
ssh forge@your-server-ip

# Check file exists in shared storage
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json

# Should show something like:
# -rw------- 1 forge forge 1234 Dec 05 14:30 gmail.json
```

## File Locations

✅ **Correct location (shared storage):**
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
```

❌ **Wrong location (releases directory):**
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/releases/XXXXXXXX/storage/app/gmail.json
```

## Important Notes

- ⚠️ **Never commit `gmail.json` to git** - It's already in `.gitignore`
- 🔒 The credentials file contains sensitive service account information
- 📋 Keep a secure backup of `gmail.json` in a password manager
- 🔄 File persists across deployments in shared storage
- ✅ Environment variable points to absolute path outside releases

## Gmail Service Account Details

**Service Account Email:** `zapzone-mail-sender@best-in-games-warren.iam.gserviceaccount.com`  
**Sending From:** `webmaster@bestingames.com`  
**Scopes Required:** Gmail Send API

## Troubleshooting

If you see the error: `Gmail credentials file not found`

1. **Verify file in shared storage:**
   ```bash
   ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
   ```

2. **Check environment variable in Forge:**
   - Go to site → Environment
   - Verify `GMAIL_CREDENTIALS_PATH` is set correctly

3. **Check file permissions:**
   ```bash
   chmod 600 /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
   chown forge:forge /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
   ```

4. **Validate JSON:**
   ```bash
   cat /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json | jq
   ```

5. **Test in tinker:**
   ```bash
   cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current
   php artisan tinker
   >>> env('GMAIL_CREDENTIALS_PATH')
   >>> file_exists(env('GMAIL_CREDENTIALS_PATH'))
   ```
