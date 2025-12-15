# Automatic Reminder System Setup

This system automatically sends email reminders to patients before their scheduled appointments.

## Features

- **Automatic Reminders**: Sends reminders 24 hours before appointments (configurable)
- **No Duplicates**: Tracks sent reminders to avoid sending multiple times
- **Manual Reminders**: Manual "Send Reminder" button still works and prevents automatic reminders
- **Gmail Integration**: Uses Gmail SMTP to send emails

## Setup Instructions

### 1. Database Migration

Run the migration to add the `reminder_sent_at` column:

```sql
-- Run this SQL in your database
ALTER TABLE `appointments` 
ADD COLUMN `reminder_sent_at` DATETIME NULL DEFAULT NULL AFTER `status`;

ALTER TABLE `appointments` 
ADD INDEX `idx_reminder_sent` (`reminder_sent_at`, `status`, `scheduled_at`);
```

Or use the migration file:
```bash
mysql -u your_username -p your_database < db_migrations/add_reminder_sent_at.sql
```

### 2. Configure Cron Job

To run automatic reminders, set up a cron job that executes the script periodically.

#### Option A: Run Every Hour (Recommended)
```bash
# Edit crontab
crontab -e

# Add this line (runs every hour)
0 * * * * /usr/bin/php /path/to/HealthCenter/health_worker/send_automatic_reminders.php >> /var/log/reminder_cron.log 2>&1
```

#### Option B: Run Every 6 Hours
```bash
0 */6 * * * /usr/bin/php /path/to/HealthCenter/health_worker/send_automatic_reminders.php >> /var/log/reminder_cron.log 2>&1
```

#### Option C: Run Once Daily (at 8 AM)
```bash
0 8 * * * /usr/bin/php /path/to/HealthCenter/health_worker/send_automatic_reminders.php >> /var/log/reminder_cron.log 2>&1
```

**For Windows (XAMPP):**
- Use Windows Task Scheduler
- Create a task that runs: `php.exe C:\xampp\htdocs\HealthCenter\health_worker\send_automatic_reminders.php`
- Set it to run daily or hourly

### 3. Test the Script

You can test the script manually by accessing it via browser or command line:

**Via Browser:**
```
http://localhost/HealthCenter/health_worker/send_automatic_reminders.php
```

**Via Command Line:**
```bash
php health_worker/send_automatic_reminders.php
```

### 4. Configuration

To change when reminders are sent (default: 24 hours before), edit `send_automatic_reminders.php`:

```php
// Change this line (line 20)
$reminder_hours_before = 24; // Change to 12, 48, etc.
```

## How It Works

1. **Automatic Check**: The script runs periodically (via cron)
2. **Find Appointments**: Looks for appointments that:
   - Are scheduled/pending (not completed/cancelled)
   - Are within the reminder time window (e.g., 24 hours from now)
   - Haven't had a reminder sent yet (`reminder_sent_at IS NULL`)
   - Have a patient with a valid email address

3. **Send Email**: Sends reminder email via Gmail SMTP
4. **Mark as Sent**: Updates `reminder_sent_at` to prevent duplicates
5. **Log Activity**: Creates a report entry for tracking

## Manual Reminders

The manual "Send Reminder" button in the appointment details:
- Still works as before
- Also marks the reminder as sent
- Prevents automatic reminder from sending again

## Troubleshooting

### Reminders Not Sending

1. **Check Email Configuration**: Verify `config/email_config.php` has correct Gmail credentials
2. **Check Cron Job**: Verify cron is running: `crontab -l`
3. **Check Logs**: View cron log file for errors
4. **Test Manually**: Run the script manually to see errors
5. **Check Database**: Ensure `reminder_sent_at` column exists

### Gmail Authentication Issues

1. Enable 2-Step Verification in Google Account
2. Generate App Password (not regular password)
3. Update `config/email_config.php` with App Password

## Notes

- Reminders are sent 24 hours before the appointment time
- Each appointment receives only one automatic reminder
- Manual reminders also prevent automatic reminders
- Cancelled or completed appointments are excluded

