# Email Setup Guide - Booking Confirmations

## 🎯 Quick Fix (RECOMMENDED - 5 minutes)

Use Gmail SMTP instead of Gmail API - much simpler!

### Step 1: Get Gmail App Password

1. Go to your Google Account: https://myaccount.google.com/
2. Security → 2-Step Verification (enable if not already)
3. Security → App Passwords
4. Create new app password for "Mail"
5. Copy the 16-character password (example: `abcd efgh ijkl mnop`)

### Step 2: Update Production `.env`

SSH to your server and edit `.env`:

```bash
ssh forge@zapzone-backend-yt1lm2w5.on-forge.com
cd /home/forge/zapzone-backend-yt1lm2w5.on-forge.com
nano .env
```

Update these lines:

```env
# Change Gmail API to false (or remove this line)
USE_GMAIL_API=false

# Set up Gmail SMTP
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-gmail@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-gmail@gmail.com
MAIL_FROM_NAME="Zap Zone"
```

### Step 3: Clear Config Cache

```bash
php artisan config:clear
php artisan cache:clear
```

### Step 4: Test

Create a booking and check if email is sent!

---

## 🔧 Alternative: Gmail API Setup (Complex)

Only use this if you MUST use Gmail API instead of SMTP.

### Requirements:
- Google Cloud Project
- Gmail API enabled
- Service Account with domain-wide delegation
- Downloaded credentials JSON file

### Steps:

1. **Create Google Cloud Project**
   - Go to https://console.cloud.google.com
   - Create new project "Zap Zone Emails"

2. **Enable Gmail API**
   - APIs & Services → Enable APIs
   - Search "Gmail API" → Enable

3. **Create Service Account**
   - IAM & Admin → Service Accounts
   - Create Service Account
   - Grant "Gmail API" permissions
   - Create JSON key → Download it

4. **Domain-Wide Delegation**
   - Service Account → Enable domain-wide delegation
   - In Google Workspace Admin Console
   - Security → API Controls → Manage Domain-Wide Delegation
   - Add Client ID with scope: `https://www.googleapis.com/auth/gmail.send`

5. **Upload to Server**
   ```bash
   # Upload gmail.json to server
   scp gmail.json forge@zapzone-backend-yt1lm2w5.on-forge.com:/home/forge/zapzone-backend-yt1lm2w5.on-forge.com/storage/app/gmail.json
   
   # Set permissions
   ssh forge@zapzone-backend-yt1lm2w5.on-forge.com
   chmod 600 /home/forge/zapzone-backend-yt1lm2w5.on-forge.com/storage/app/gmail.json
   ```

6. **Update `.env`**
   ```env
   USE_GMAIL_API=true
   GMAIL_CREDENTIALS_PATH=/home/forge/zapzone-backend-yt1lm2w5.on-forge.com/storage/app/gmail.json
   ```

---

## 🧪 Testing

### Check Current Configuration

```bash
php artisan tinker
```

```php
// Check mail config
config('mail.default');
config('mail.mailers.smtp');

// Test email
Mail::raw('Test email', function($message) {
    $message->to('test@example.com')
            ->subject('Test Email');
});
```

### Check Logs

```bash
tail -f storage/logs/laravel.log
```

Look for:
- `✅ Booking confirmation email sent successfully`
- Method used: `SMTP` or `Gmail API`

---

## 📊 Comparison

| Feature | Gmail SMTP | Gmail API |
|---------|------------|-----------|
| Setup Time | 5 minutes | 30+ minutes |
| Complexity | Easy | Complex |
| Requirements | Gmail account + App Password | Google Cloud Project + Service Account |
| Reliability | High | High |
| Cost | Free (500 emails/day) | Free (1 billion requests/day) |
| **Recommended** | ✅ **YES** | Only if required |

---

## 🐛 Troubleshooting

### Email not sending with SMTP

1. **Check credentials**
   ```bash
   php artisan tinker
   config('mail.username');
   ```

2. **Test connection**
   ```bash
   telnet smtp.gmail.com 587
   ```

3. **Check Gmail settings**
   - 2-Step Verification enabled?
   - App Password created?
   - Less secure apps disabled? (use App Password instead)

### Email not sending with Gmail API

1. **Check credentials file exists**
   ```bash
   ls -la storage/app/gmail.json
   ```

2. **Check permissions**
   ```bash
   chmod 600 storage/app/gmail.json
   ```

3. **Verify .env setting**
   ```bash
   grep GMAIL .env
   ```

---

## 🎯 Recommended Production Setup

```env
# Email Configuration (SMTP - Simple & Reliable)
USE_GMAIL_API=false
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=bookings@zap-zone.com
MAIL_PASSWORD=your-app-password-here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=bookings@zap-zone.com
MAIL_FROM_NAME="Zap Zone Bookings"
```

**Why SMTP?**
- ✅ Much simpler to set up
- ✅ No Google Cloud Project needed
- ✅ No service account complexity
- ✅ Works with any Gmail account
- ✅ Easy to debug
- ✅ Reliable and tested

The code will automatically use SMTP when `USE_GMAIL_API=false` or when the Gmail credentials file is not found.
