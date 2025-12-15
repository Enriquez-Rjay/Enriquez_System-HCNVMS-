-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 27, 2025 at 12:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12


SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";




/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


--
-- Database: `healthcenter`
--


-- --------------------------------------------------------


--
-- Table structure for table `appointments`
--


CREATE TABLE `appointments` (
  `id` int(10) UNSIGNED NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `health_worker_id` int(10) UNSIGNED DEFAULT NULL,
  `scheduled_at` datetime NOT NULL,
  `status` enum('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `appointments`
--


INSERT INTO `appointments` (`id`, `patient_id`, `health_worker_id`, `scheduled_at`, `status`, `notes`, `created_at`) VALUES
(1, 4, 2, '2025-11-26 09:00:00', 'scheduled', 'Routine vaccination', '2025-11-26 14:35:38'),
(2, 5, 2, '2025-11-26 10:30:00', 'scheduled', 'Follow-up', '2025-11-26 14:35:38'),
(3, 6, NULL, '2025-11-27 14:00:00', 'scheduled', 'Next day appt', '2025-11-26 14:35:38'),
(4, 4, 2, '2025-11-26 09:00:00', 'scheduled', 'Routine vaccination', '2025-11-26 14:37:35'),
(5, 5, 2, '2025-11-26 10:30:00', 'scheduled', 'Follow-up', '2025-11-26 14:37:35'),
(6, 6, NULL, '2025-11-27 14:00:00', 'scheduled', 'Next day appt', '2025-11-26 14:37:35'),
(7, 3, NULL, '2025-11-29 09:00:00', 'scheduled', 'dfs', '2025-11-27 08:26:52'),
(8, 3, NULL, '2025-11-30 08:00:00', 'scheduled', '', '2025-11-27 08:34:58'),
(9, 3, NULL, '2025-12-09 10:00:00', 'scheduled', '', '2025-11-27 09:26:34'),
(10, 3, NULL, '2025-12-11 14:00:00', 'scheduled', '', '2025-11-27 09:27:01');


-- --------------------------------------------------------


--
-- Table structure for table `health_centers`
--


CREATE TABLE `health_centers` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------


--
-- Table structure for table `health_worker_profiles`
--


CREATE TABLE `health_worker_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `license_no` varchar(100) DEFAULT NULL,
  `health_center_id` int(10) UNSIGNED DEFAULT NULL,
  `specialty` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------


--
-- Table structure for table `patient_profiles`
--


CREATE TABLE `patient_profiles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `child_name` varchar(255) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `guardian_name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `patient_profiles`
--


INSERT INTO `patient_profiles` (`id`, `user_id`, `child_name`, `birth_date`, `guardian_name`, `address`, `created_at`) VALUES
(1, 3, 'John Dave Timkang', '2022-05-12', 'Angelo', 'P-8 Caasinan Barangay Site', '2025-11-26 14:35:38'),
(2, 4, 'Child Two', '2023-04-01', 'Maria Santos', 'Address 2', '2025-11-26 14:35:38'),
(3, 5, 'Child Three', '2021-11-10', 'Jose Reyes', 'Address 3', '2025-11-26 14:35:38'),
(4, 6, 'Child Four', '2020-08-22', 'Ana Lopez', 'Address 4', '2025-11-26 14:35:38');


-- --------------------------------------------------------


--
-- Table structure for table `reports`
--


CREATE TABLE `reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `report_type` varchar(100) NOT NULL,
  `params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`params`)),
  `generated_by` int(10) UNSIGNED DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_path` varchar(1024) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------


--
-- Table structure for table `system_settings`
--


CREATE TABLE `system_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(191) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------


--
-- Table structure for table `users`
--


CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','health_worker','patient') NOT NULL DEFAULT 'patient',
  `full_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `phone` varchar(30) DEFAULT NULL,
  `avatar_url` varchar(512) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `users`
--


INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `full_name`, `email`, `created_at`, `status`, `phone`, `avatar_url`, `last_login`, `updated_at`) VALUES
(1, 'Admin', '$2y$10$HQ0TXuIQwJ3PqUv7wzaTueTIkj.QOKzSEZ/xsuqEhQbAshnLKmsOe', 'admin', 'Lea Lajera', 'leamae@gmail.com', '2025-11-26 12:18:07', 'active', '', NULL, NULL, '2025-11-27 10:55:05'),
(2, 'Worker', '$2y$10$usiPdCRQcqJ7.Bv3eOa8Ieml8nlG2aV1PkHTLA5eBwtePV2DVkJu6', 'health_worker', 'Frince Rjay Enriquez', 'frenriquez@gmail.com', '2025-11-26 12:18:07', 'active', NULL, NULL, NULL, '2025-11-27 03:07:05'),
(3, 'Patient', '$2y$10$U/xX8Y.AJKbuRDmIAE07W.rTewtnJawj5awo9Bb/X8khIcAwNpwrW', 'patient', 'John Dave Timkang', 'jdtimkang@gmail.com', '2025-11-26 12:18:44', 'active', '', NULL, NULL, '2025-11-27 07:33:04'),
(4, 'patient2', '$2y$10$U/xX8Y.AJKbuRDmIAE07W.rTewtnJawj5awo9Bb/X8khIcAwNpwrW', 'patient', 'Maria Santos', 'maria.santos@example.com', '2025-11-26 14:35:38', 'active', NULL, NULL, NULL, '2025-11-27 03:05:05'),
(5, 'patient3', '$2y$10$U/xX8Y.AJKbuRDmIAE07W.rTewtnJawj5awo9Bb/X8khIcAwNpwrW', 'patient', 'Jose Reyes', 'jose.reyes@example.com', '2025-11-26 14:35:38', 'active', NULL, NULL, NULL, '2025-11-27 03:16:32'),
(6, 'patient4', '$2y$10$U/xX8Y.AJKbuRDmIAE07W.rTewtnJawj5awo9Bb/X8khIcAwNpwrW', 'patient', 'Ana Lopez', 'ana.lopez@example.com', '2025-11-26 14:35:38', 'active', NULL, NULL, NULL, NULL),
(26, 'jade', '$2y$10$2gT6YmuNay4WHT1FyL0pZujdcpivJX80Gcykj.V4r54fZqRyr2pbi', 'patient', 'Jade Montenegro', 'lealajera@gmail.com', '2025-11-27 03:34:43', 'active', NULL, NULL, NULL, '2025-11-27 04:52:02'),
(28, 'rcy', '$2y$10$ffXToDZq3ceGivUKzmId.u0zT.futWOd8nBaU/EM.8/Q7I1jg4dJy', 'health_worker', 'Ronelyn Cy', 'rcy@gmail.com', '2025-11-27 04:10:18', 'active', NULL, NULL, NULL, NULL);


-- --------------------------------------------------------


--
-- Table structure for table `vaccination_records`
--


CREATE TABLE `vaccination_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `vaccine_id` int(10) UNSIGNED NOT NULL,
  `date_given` date NOT NULL,
  `dose` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `vaccination_records`
--


INSERT INTO `vaccination_records` (`id`, `patient_id`, `vaccine_id`, `date_given`, `dose`, `created_at`) VALUES
(1, 3, 1, '2022-05-20', 1, '2025-11-26 14:35:38'),
(2, 4, 2, '2023-04-10', 1, '2025-11-26 14:35:38'),
(3, 4, 3, '2023-04-10', 1, '2025-11-26 14:35:38'),
(4, 5, 4, '2021-12-01', 1, '2025-11-26 14:35:38'),
(5, 3, 1, '2022-05-20', 1, '2025-11-26 14:37:35'),
(6, 4, 2, '2023-04-10', 1, '2025-11-26 14:37:35'),
(7, 4, 3, '2023-04-10', 1, '2025-11-26 14:37:35'),
(8, 5, 4, '2021-12-01', 1, '2025-11-26 14:37:35');


-- --------------------------------------------------------


--
-- Table structure for table `vaccines`
--


CREATE TABLE `vaccines` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `storage_condition` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `vaccines`
--


INSERT INTO `vaccines` (`id`, `name`, `manufacturer`, `description`, `storage_condition`, `created_at`) VALUES
(1, 'BCG', 'Manufacturer A', 'Bacillus Calmette-Guérin vaccine', '2-8°C, Protect from light', '2025-11-26 14:35:38'),
(2, 'Polio', 'Manufacturer B', 'Polio vaccine', '2-8°C', '2025-11-26 14:35:38'),
(3, 'DPT', 'Manufacturer C', 'DPT vaccine', '2-8°C', '2025-11-26 14:35:38'),
(4, 'HepB', 'Manufacturer D', 'Hepatitis B vaccine', '2-8°C', '2025-11-26 14:35:38'),
(5, 'MMR', 'Manufacturer E', 'Measles, Mumps, Rubella vaccine', '2-8°C', '2025-11-26 14:35:38'),
(6, 'Hib', 'Manufacturer F', 'Haemophilus influenzae type b vaccine', '2-8°C', '2025-11-26 14:35:38');


-- --------------------------------------------------------


--
-- Table structure for table `vaccine_batches`
--


CREATE TABLE `vaccine_batches` (
  `id` int(10) UNSIGNED NOT NULL,
  `vaccine_id` int(10) UNSIGNED NOT NULL,
  `batch_number` varchar(128) NOT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `quantity_available` int(11) NOT NULL DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `received_at` date DEFAULT NULL,
  `storage_location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `vaccine_batches`
--


INSERT INTO `vaccine_batches` (`id`, `vaccine_id`, `batch_number`, `quantity_received`, `quantity_available`, `expiry_date`, `received_at`, `storage_location`, `created_at`) VALUES
(1, 1, 'BCG-2024-01', 100, 0, '2024-07-15', '2024-01-15', 'Cold Room A', '2025-11-26 14:35:38'),
(2, 2, 'POLIO-2025-02', 500, 25, '2026-02-01', '2025-02-01', 'Cold Room B', '2025-11-26 14:35:38'),
(3, 3, 'DPT-2025-03', 400, 400, '2026-05-01', '2025-03-10', 'Cold Room B', '2025-11-26 14:35:38'),
(4, 4, 'HEPB-2024-05', 200, 15, '2025-11-30', '2024-05-20', 'Cold Room C', '2025-11-26 14:35:38'),
(5, 5, 'MMR-2023-09', 80, 0, '2024-07-01', '2023-09-10', 'Cold Room A', '2025-11-26 14:35:38'),
(6, 6, 'HIB-2025-06', 150, 150, '2026-06-30', '2025-06-01', 'Cold Room C', '2025-11-26 14:35:38');


-- --------------------------------------------------------


--
-- Table structure for table `vaccine_transactions`
--


CREATE TABLE `vaccine_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `batch_id` int(10) UNSIGNED NOT NULL,
  `type` enum('receive','use','adjustment') NOT NULL,
  `quantity` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `performed_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--
-- Dumping data for table `vaccine_transactions`
--


INSERT INTO `vaccine_transactions` (`id`, `batch_id`, `type`, `quantity`, `notes`, `performed_by`, `created_at`) VALUES
(1, 1, 'receive', 100, 'Initial stock', 1, '2025-11-26 14:35:38'),
(2, 2, 'receive', 500, 'Initial stock', 1, '2025-11-26 14:35:38'),
(3, 1, 'receive', 100, 'Initial stock', 1, '2025-11-26 14:37:35'),
(4, 2, 'receive', 500, 'Initial stock', 1, '2025-11-26 14:37:35');


--
-- Indexes for dumped tables
--


--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `appt_patient_idx` (`patient_id`),
  ADD KEY `appt_hw_idx` (`health_worker_id`);


--
-- Indexes for table `health_centers`
--
ALTER TABLE `health_centers`
  ADD PRIMARY KEY (`id`);


--
-- Indexes for table `health_worker_profiles`
--
ALTER TABLE `health_worker_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `hw_center_fk` (`health_center_id`);


--
-- Indexes for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);


--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `reports_user_fk` (`generated_by`);


--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);


--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role` (`role`),
  ADD KEY `email` (`email`);


--
-- Indexes for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vr_patient_idx` (`patient_id`),
  ADD KEY `vr_vaccine_idx` (`vaccine_id`);


--
-- Indexes for table `vaccines`
--
ALTER TABLE `vaccines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);


--
-- Indexes for table `vaccine_batches`
--
ALTER TABLE `vaccine_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_number` (`batch_number`,`vaccine_id`),
  ADD KEY `vaccine_idx` (`vaccine_id`);


--
-- Indexes for table `vaccine_transactions`
--
ALTER TABLE `vaccine_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `txn_batch_idx` (`batch_id`),
  ADD KEY `txn_user_fk` (`performed_by`);


--
-- AUTO_INCREMENT for dumped tables
--


--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;


--
-- AUTO_INCREMENT for table `health_centers`
--
ALTER TABLE `health_centers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;


--
-- AUTO_INCREMENT for table `health_worker_profiles`
--
ALTER TABLE `health_worker_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;


--
-- AUTO_INCREMENT for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;


--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;


--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;


--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;


--
-- AUTO_INCREMENT for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;


--
-- AUTO_INCREMENT for table `vaccines`
--
ALTER TABLE `vaccines`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;


--
-- AUTO_INCREMENT for table `vaccine_batches`
--
ALTER TABLE `vaccine_batches`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;


--
-- AUTO_INCREMENT for table `vaccine_transactions`
--
ALTER TABLE `vaccine_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;


--
-- Constraints for dumped tables
--


--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appt_hw_fk` FOREIGN KEY (`health_worker_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `appt_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


--
-- Constraints for table `health_worker_profiles`
--
ALTER TABLE `health_worker_profiles`
  ADD CONSTRAINT `hw_center_fk` FOREIGN KEY (`health_center_id`) REFERENCES `health_centers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `hw_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


--
-- Constraints for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  ADD CONSTRAINT `patient_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;


--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_user_fk` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;


--
-- Constraints for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD CONSTRAINT `vr_patient_fk` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vr_vaccine_fk` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE;


--
-- Constraints for table `vaccine_batches`
--
ALTER TABLE `vaccine_batches`
  ADD CONSTRAINT `batch_vaccine_fk` FOREIGN KEY (`vaccine_id`) REFERENCES `vaccines` (`id`) ON DELETE CASCADE;


--
-- Constraints for table `vaccine_transactions`
--
ALTER TABLE `vaccine_transactions`
  ADD CONSTRAINT `txn_batch_fk` FOREIGN KEY (`batch_id`) REFERENCES `vaccine_batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `txn_user_fk` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;


/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;



