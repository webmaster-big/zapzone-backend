# Booking Reminders System

## Overview
Automatic email reminder system that sends customers a reminder email for bookings scheduled for tomorrow. The system runs every time the bookings index API is called and checks for bookings that need reminders.

## Database Changes

### New Columns in `bookings` Table
- `reminder_sent` (boolean, default: false) - Tracks if reminder has been sent
- `reminder_sent_at` (timestamp, nullable) - When the reminder was sent

## How It Works

### Automatic Trigger
Every time the `GET /api/bookings` endpoint is called, the system:
1. Checks for bookings scheduled for tomorrow
2. Filters only bookings that haven't been reminded yet (`reminder_sent = false`)
3. Only sends to bookings with status 'pending' or 'confirmed' (excludes cancelled/completed)
4. Sends reminder email to each qualifying booking
5. Marks booking as reminded to prevent duplicate sends

### Email Content
The reminder email includes:
- **Company Logo** (from location's company)
- **Booking Reference Number**
- **Date and Time Reminder** (highlighted alert box)
- **Complete Booking Details**:
  - Package name
  - Date and time
  - Duration
  - Number of participants
  - Guest of honor (if applicable)
- **Location Information**:
  - Name
  - Address
  - Phone number
- **Room Assignment** (if applicable)
- **Payment Summary**:
  - Total amount
  - Amount paid
  - Balance due (or "Fully Paid")
- **Special Requests** (if any)
- **Helpful Tips**:
  - Arrive 15 minutes early
  - Bring confirmation/reference number
  - Wear comfortable clothing
  - Waiver requirement

### Customer Notification
When a reminder is sent, a customer notification is also created:
- **Type**: reminder
- **Priority**: medium
- **Title**: "Booking Reminder"
- **Message**: Details about tomorrow's booking
- **Action**: Link to view booking details

## API Endpoints

### Bookings Index (Triggers Reminders)
```
GET /api/bookings
```

**Query Parameters:**
- All standard booking filters apply
- Reminders are sent regardless of filters (scans all bookings)

**Response:**
```json
{
  "success": true,
  "data": {
    "bookings": [...],
    "pagination": {...}
  }
}
```

## Files Created/Modified

### New Files
1. **Migration**: `database/migrations/2026_01_12_201255_add_reminder_sent_to_bookings_table.php`
2. **Mailable**: `app/Mail/BookingReminder.php`
3. **Email Template**: `resources/views/emails/booking-reminder.blade.php`

### Modified Files
1. **BookingController**: `app/Http/Controllers/Api/BookingController.php`
   - Added `sendTomorrowBookingReminders()` method
   - Added `sendBookingReminderEmail()` method
   - Calls reminder method in `index()` function

2. **Booking Model**: `app/Models/Booking.php`
   - Added `reminder_sent` and `reminder_sent_at` to fillable
   - Added casts for boolean and timestamp

## Email Sending Methods

The system supports two email sending methods:

### Gmail API (Preferred)
- Set `GMAIL_ENABLED=true` in `.env`
- Configure Gmail API credentials
- More reliable for high-volume sending

### SMTP (Fallback)
- Uses Laravel's default mail driver
- Configured in `config/mail.php`
- Falls back automatically if Gmail API unavailable

## Business Logic

### Reminder Criteria
A booking receives a reminder if ALL conditions are met:
- Booking date is tomorrow
- `reminder_sent` is `false`
- Status is 'pending' or 'confirmed'
- Has a valid recipient email (customer email or guest email)

### Error Handling
- If no email address: Marks as sent to avoid repeated attempts
- If email fails: Does NOT mark as sent (can retry on next API call)
- All actions are logged for monitoring

## Logs

### Success Log
```
✅ Booking reminder email sent successfully
- booking_id
- reference_number
- recipient_email
- method (Gmail API or SMTP)
```

### Error Log
```
❌ Failed to send booking reminder email
- booking_id
- reference_number
- recipient_email
- error message
```

## Manual Reset (If Needed)

To resend reminders for specific bookings:

```sql
-- Reset reminder flag for specific booking
UPDATE bookings 
SET reminder_sent = false, reminder_sent_at = NULL 
WHERE id = 123;

-- Reset all reminders for tomorrow's bookings
UPDATE bookings 
SET reminder_sent = false, reminder_sent_at = NULL 
WHERE booking_date = CURDATE() + INTERVAL 1 DAY;
```

## Testing

### Test Reminder Email
1. Create a test booking for tomorrow's date
2. Ensure `reminder_sent = false`
3. Set status to 'confirmed'
4. Add valid email address
5. Call `GET /api/bookings`
6. Check email and database

### Verify Database Update
```sql
SELECT id, reference_number, booking_date, 
       reminder_sent, reminder_sent_at 
FROM bookings 
WHERE booking_date = CURDATE() + INTERVAL 1 DAY;
```

## Performance Considerations

- Reminder check runs on every `index()` call
- Only queries bookings for tomorrow (efficient)
- Uses single query with proper indexes
- Emails sent asynchronously per booking
- Failed emails don't block the API response

## Future Enhancements

Potential improvements:
1. **Schedule as Cron Job**: Move to scheduled task instead of on-demand
2. **Queue System**: Process emails in background queue
3. **Configurable Timing**: Allow reminders at different times (2 days, 1 week)
4. **SMS Reminders**: Add SMS capability alongside email
5. **Reminder Preferences**: Let customers opt-in/out of reminders
6. **Multiple Reminders**: Send follow-up reminders if needed

## Troubleshooting

### Reminders Not Sending
1. Check database connection
2. Verify booking meets criteria
3. Check email configuration (Gmail/SMTP)
4. Review logs for errors
5. Ensure `reminder_sent` is `false`

### Duplicate Reminders
- Check `reminder_sent` flag is being set
- Review commit transaction success
- Check for concurrent API calls

### Email Not Received
1. Check spam/junk folder
2. Verify email address is valid
3. Check email service limits
4. Review email logs
5. Test email configuration

## Support

For issues or questions:
1. Check application logs: `storage/logs/laravel.log`
2. Review email service logs
3. Verify database updates
4. Test with known good email address
