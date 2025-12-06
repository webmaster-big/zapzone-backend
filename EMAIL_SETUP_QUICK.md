# Quick Email Setup for Production

## The system now uses **Laravel Mail (SMTP)** by default - much simpler!

### Step 1: Get Gmail App Password

1. Go to your Google Account: https://myaccount.google.com/
2. Go to **Security**
3. Enable **2-Step Verification** (if not already enabled)
4. Go to **App Passwords** (search for it)
5. Generate a new app password:
   - App: Mail
   - Device: Other (custom name) - enter "Zap Zone Production"
6. Copy the 16-character password (it looks like: `abcd efgh ijkl mnop`)

### Step 2: Update Production .env

SSH to your production server:

```bash
ssh forge@zapzone-backend-yt1lm2w5.on-forge.com
cd /home/forge/zapzone-backend-yt1lm2w5.on-forge.com
nano .env
```

Add/update these lines (use your actual Gmail app password):

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=webmaster@bestingames.com
MAIL_PASSWORD=abcdefghijklmnop
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=webmaster@bestingames.com
MAIL_FROM_NAME="Zap Zone"
```

**Important:** Remove spaces from the app password!

### Step 3: Clear Config Cache

```bash
php artisan config:clear
php artisan cache:clear
```

### Step 4: Test

Create a test booking with QR code - you should receive an email!

---

## Alternative: Use Gmail API (Advanced)

If you want to use Gmail API instead, add this to `.env`:

```env
USE_GMAIL_API=true
GMAIL_CREDENTIALS_PATH=/home/forge/zapzone-backend-yt1lm2w5.on-forge.com/storage/app/gmail.json
```

Then follow the Gmail API setup guide in `GMAIL_SETUP.md`.

---

## Troubleshooting

### Email not sending?

Check the logs:
```bash
tail -f storage/logs/laravel.log
```

Look for:
- ✅ `Email sent successfully via Laravel Mail` - It worked!
- ❌ `Failed to send booking confirmation email` - Check the error message

### Common errors:

**"Username and Password not accepted"**
- Make sure you're using an App Password, not your regular Gmail password
- Remove all spaces from the app password
- Enable 2-Step Verification first

**"Could not authenticate"**
- Double-check MAIL_USERNAME is correct
- Make sure less secure apps is enabled (or use app password)

---

## What Changed

- ✅ Now uses **Laravel Mail by default** (simpler, more reliable)
- ✅ Gmail API is **optional** (set `USE_GMAIL_API=true` to enable)
- ✅ QR code automatically attached to emails
- ✅ Comprehensive logging for debugging
- ✅ Email status returned in API response

---

## Next Steps

1. Set up Gmail App Password
2. Update production `.env`
3. Clear config cache
4. Test with a booking!

Need help? Check the logs and let me know what error you see.
