# Gmail API Setup for Production

## Deployment Steps for Laravel Forge

### Important: Forge Uses Shared Storage
Laravel Forge uses zero-downtime deployments with changing release directories. The `gmail.json` file must be placed in the **shared storage** directory that persists across deployments.

### 1. Upload Gmail Credentials via SSH

```bash
# SSH into your server
ssh forge@your-server-ip

# Navigate to the SHARED storage directory (NOT releases)
cd /home/forge/zapzone-backend-1oulhaj4.on-forge.com

# Create the gmail.json file in shared storage
nano storage/app/gmail.json

# Paste your credentials and save (Ctrl+X, Y, Enter)

# Set proper permissions
chmod 600 storage/app/gmail.json
chown forge:forge storage/app/gmail.json
```

### 2. Verify File Location

The file should be at:
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json
```

**NOT** in the releases directory:
```
/home/forge/zapzone-backend-1oulhaj4.on-forge.com/releases/XXXXXXXX/storage/app/gmail.json ❌
```

### 3. Verify File Exists

```bash
# Check if file exists in shared storage
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/storage/app/gmail.json

# The symlink should point to shared storage
ls -la /home/forge/zapzone-backend-1oulhaj4.on-forge.com/current/storage
```

## Alternative: Using Laravel Forge Dashboard

1. Go to your site in Laravel Forge
2. Click on **Files** section
3. Navigate to: `storage/app/`
4. Create new file: `gmail.json`
5. Paste the contents from your local `storage/app/gmail.json`
6. Save

## Important Notes

- ⚠️ **Never commit `gmail.json` to git** - It's already in `.gitignore`
- 🔒 The credentials file contains sensitive service account information
- 📋 Keep a secure backup of `gmail.json` in a password manager
- 🔄 File should be in **shared storage**, not releases directory
- ✅ The application will log an error if the file is missing

## Gmail Service Account Details

**Service Account Email:** `zapzone-mail-sender@best-in-games-warren.iam.gserviceaccount.com`  
**Sending From:** `webmaster@bestingames.com`  
**Scopes Required:** Gmail Send API

## Troubleshooting

If you see the error: `Gmail credentials file not found`

1. **Check shared storage location:**
   ```bash
   ls -la /home/forge/your-site.com/storage/app/gmail.json
   ```

2. **Verify storage symlink:**
   ```bash
   ls -la /home/forge/your-site.com/current/storage
   # Should show: storage -> /home/forge/your-site.com/storage
   ```

3. **Check file permissions:**
   ```bash
   # Should be readable by forge user
   chmod 600 /home/forge/your-site.com/storage/app/gmail.json
   chown forge:forge /home/forge/your-site.com/storage/app/gmail.json
   ```

4. **Validate JSON:**
   ```bash
   cat /home/forge/your-site.com/storage/app/gmail.json | jq
   ```

5. **Check logs for actual path being checked:**
   ```bash
   tail -f /home/forge/your-site.com/current/storage/logs/laravel.log
   ```
