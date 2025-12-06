# Gmail API Setup Guide for Booking Confirmation Emails

## Problem
```
Gmail credentials file not found at: /home/forge/.../storage/app/gmail.json
```

## Solution: Set Up Gmail API

### Option 1: Use Gmail API (Current Setup - Recommended for Production)

#### Step 1: Create Google Cloud Project & Enable Gmail API

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing one
3. Enable the Gmail API:
   - Go to **APIs & Services** → **Library**
   - Search for "Gmail API"
   - Click **Enable**

#### Step 2: Create Service Account

1. Go to **APIs & Services** → **Credentials**
2. Click **Create Credentials** → **Service Account**
3. Fill in details:
   - Name: `zapzone-email-sender`
   - Description: `Service account for sending booking confirmation emails`
   - Click **Create**

#### Step 3: Generate JSON Key

1. Click on the service account you just created
2. Go to **Keys** tab
3. Click **Add Key** → **Create new key**
4. Choose **JSON** format
5. Download the JSON file (keep it secure!)

#### Step 4: Set Up Domain-Wide Delegation

1. In the service account page, enable **Domain-Wide Delegation**
2. Note the **Client ID** (you'll need this)
3. Go to [Google Workspace Admin Console](https://admin.google.com/) (you need workspace admin access)
4. Navigate to **Security** → **API Controls** → **Domain-wide Delegation**
5. Click **Add new**
6. Enter the **Client ID** from step 2
7. Add OAuth Scope: `https://www.googleapis.com/auth/gmail.send`
8. Click **Authorize**

#### Step 5: Upload Credentials to Production

```bash
# SSH to production
ssh forge@zapzone-backend-yt1lm2w5.on-forge.com

# Navigate to project
cd /home/forge/zapzone-backend-yt1lm2w5.on-forge.com

# Create the credentials file (use nano or vim)
nano storage/app/gmail.json

# Paste the JSON content from the downloaded file
# Press Ctrl+X, then Y, then Enter to save

# Set proper permissions
chmod 600 storage/app/gmail.json
```

#### Step 6: Update .env (if needed)

If you want to store the file in a different location:

```bash
nano .env

# Add or update:
GMAIL_CREDENTIALS_PATH=/home/forge/zapzone-backend-yt1lm2w5.on-forge.com/storage/app/gmail.json
```

---

### Option 2: Use Laravel Mail (Simpler Alternative - SMTP)

If Gmail API is too complex, switch to standard Laravel Mail with SMTP:

#### Update `.env` on production:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=webmaster@bestingames.com
MAIL_PASSWORD=your_app_password_here
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=webmaster@bestingames.com
MAIL_FROM_NAME="Zap Zone"
```

**Note:** For Gmail SMTP, you need an [App Password](https://support.google.com/accounts/answer/185833):
1. Go to Google Account settings
2. Enable 2-Step Verification
3. Generate App Password for "Mail"
4. Use that password in `MAIL_PASSWORD`

#### Update `BookingController.php`:

Replace Gmail API code with Laravel Mail:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\BookingConfirmation;

// In storeQrCode method, replace the Gmail API section with:
if ($recipientEmail) {
    try {
        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);
        
        Mail::to($recipientEmail)->send(new BookingConfirmation($booking, $emailQrPath));
        
        $emailSent = true;
        
        Log::info('✅ Booking confirmation email sent successfully', [
            'email' => $recipientEmail,
            'booking_id' => $booking->id,
        ]);
    } catch (\Exception $e) {
        $emailError = $e->getMessage();
        Log::error('❌ Failed to send booking confirmation email', [
            'email' => $recipientEmail,
            'booking_id' => $booking->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

---

## Quick Test After Setup

```bash
# Test email sending
php artisan tinker

# Send test email
Mail::raw('Test email from Zap Zone', function ($message) {
    $message->to('test@example.com')
            ->subject('Test Email');
});
```

---

## Recommended Approach

For **production**, I recommend **Option 2 (Laravel Mail with SMTP)** because:
- ✅ Much simpler to set up
- ✅ No service account or domain delegation needed
- ✅ Works immediately with Gmail App Password
- ✅ Standard Laravel functionality

For **Google Workspace** with advanced features, use **Option 1 (Gmail API)**.

---

## Files to Check

- `app/Services/GmailApiService.php` - Current Gmail API service
- `app/Mail/BookingConfirmation.php` - Email template
- `resources/views/emails/booking-confirmation.blade.php` - Email view
- `.env` - Mail configuration

---

## Next Steps

1. **Choose your option** (Gmail API or Laravel Mail)
2. **Set up credentials** (gmail.json or SMTP settings)
3. **Test email sending**
4. **Create a test booking** to verify QR code + email

Let me know which option you prefer and I'll help you implement it!
