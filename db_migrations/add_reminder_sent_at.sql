-- Add reminder_sent_at column to appointments table
-- This tracks when automatic reminders were sent to avoid duplicates

ALTER TABLE `appointments` 
ADD COLUMN `reminder_sent_at` DATETIME NULL DEFAULT NULL AFTER `status`;

-- Add index for better query performance
ALTER TABLE `appointments` 
ADD INDEX `idx_reminder_sent` (`reminder_sent_at`, `status`, `scheduled_at`);

