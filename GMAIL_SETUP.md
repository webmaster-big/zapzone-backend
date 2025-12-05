# Gmail API Setup for Production

## Deployment Steps

### 1. Upload Gmail Credentials
After deployment, you need to manually upload the Gmail API credentials file to the server:

```bash
# On your Laravel Forge server or via Forge dashboard
# Upload gmail.json to: storage/app/gmail.json
```

### 2. Using Laravel Forge

1. Go to your site in Laravel Forge
2. Navigate to **Files** section
3. Create a new file at: `storage/app/gmail.json`
4. Paste the contents of your local `storage/app/gmail.json` file
5. Save the file

### 3. Via SSH (Alternative)

```bash
# SSH into your server
ssh forge@your-server-ip

# Navigate to your app directory
cd /home/forge/your-site.com

# Create the gmail.json file
nano storage/app/gmail.json

# Paste your credentials and save (Ctrl+X, Y, Enter)

# Set proper permissions
chmod 600 storage/app/gmail.json
chown forge:forge storage/app/gmail.json
```

### 4. Verify File Exists

```bash
# Check if file exists
ls -la storage/app/gmail.json

# Test the application
php artisan tinker
>>> file_exists(storage_path('app/gmail.json'));
=> true
```

## Important Notes

- ⚠️ **Never commit `gmail.json` to git** - It's already in `.gitignore`
- 🔒 The credentials file contains sensitive service account information
- 📋 Keep a secure backup of `gmail.json` in a password manager
- 🔄 After each deployment, ensure the file still exists in `storage/app/`
- ✅ The application will log an error if the file is missing

## Gmail Service Account Details

**Service Account Email:** `zapzone-mail-sender@best-in-games-warren.iam.gserviceaccount.com`  
**Sending From:** `webmaster@bestingames.com`  
**Scopes Required:** Gmail Send API

## Troubleshooting

If you see the error: `Gmail credentials file not found`

1. Check file exists: `ls -la storage/app/gmail.json`
2. Check file permissions: Should be readable by web server user
3. Check storage path in logs to verify correct location
4. Verify JSON file is valid: `cat storage/app/gmail.json | jq`
