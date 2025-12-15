-- Add vaccine_id and dosage columns to appointments table
ALTER TABLE `appointments` 
ADD COLUMN `vaccine_id` INT UNSIGNED DEFAULT NULL AFTER `notes`,
ADD COLUMN `dosage` VARCHAR(50) DEFAULT NULL AFTER `vaccine_id`;

-- Add foreign key constraint
ALTER TABLE `appointments`
ADD CONSTRAINT `appt_vaccine_fk` 
FOREIGN KEY (`vaccine_id`) 
REFERENCES `vaccines` (`id`) 
ON DELETE SET NULL;

-- Update the status enum to include 'pending' status if it doesn't exist
ALTER TABLE `appointments` 
MODIFY COLUMN `status` ENUM('scheduled','completed','cancelled','pending') NOT NULL DEFAULT 'scheduled';
