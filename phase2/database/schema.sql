-- =====================================================================
-- GlucoCare - Diabetes Clinical Decision-Support & Self-Management System
-- Case study: Sir Albert Cook Hospital, Kampala
-- PHASE 2: Phase 1 (access control, assignments, audit) + glucose logging, validation, reliability
-- Safe to re-run (IF NOT EXISTS / ON DUPLICATE KEY).
-- Demo password for ALL seed accounts: Password123!
-- All seed people are fictional.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `glucocare` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `glucocare`;

CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('patient','clinician','admin') NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clinician profiles are created ONLY by hospital administrators.
CREATE TABLE IF NOT EXISTS `clinicians` (
    `clinician_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `specialization` VARCHAR(100) NOT NULL,
    `license_no` VARCHAR(50) NOT NULL UNIQUE,
    CONSTRAINT `fk_clinicians_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- clinician_id = the ONE doctor allowed to see this patient (set by an administrator).
CREATE TABLE IF NOT EXISTS `patients` (
    `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `clinician_id` INT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `date_of_birth` DATE NOT NULL,
    `gender` ENUM('male','female','other') NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `assigned_at` TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT `fk_patients_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_patients_clinician` FOREIGN KEY (`clinician_id`) REFERENCES `clinicians`(`clinician_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-patient target ranges (ADA defaults; clinicians can override from Phase 3).
CREATE TABLE IF NOT EXISTS `patient_thresholds` (
    `threshold_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL UNIQUE,
    `target_fasting_min` DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    `target_fasting_max` DECIMAL(5,2) NOT NULL DEFAULT 130.00,
    `target_postprandial_max` DECIMAL(5,2) NOT NULL DEFAULT 180.00,
    `hypo_threshold` DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    `severe_hypo_threshold` DECIMAL(5,2) NOT NULL DEFAULT 54.00,
    `critical_high_threshold` DECIMAL(5,2) NOT NULL DEFAULT 300.00,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_thresholds_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `glucose_readings` (
    `reading_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `glucose_value` DECIMAL(5,1) NOT NULL,
    `meal_context` ENUM('fasting','pre_meal','post_meal','bedtime','nocturnal') NOT NULL,
    `symptoms` VARCHAR(255) NULL,
    `was_confirmed` TINYINT(1) NOT NULL DEFAULT 0,   -- patient confirmed an extreme value
    `logged_at` DATETIME NOT NULL,                    -- when the reading was taken
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- when it was submitted
    CONSTRAINT `fk_readings_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
    INDEX `idx_patient_logged` (`patient_id`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `target_table` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `details` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
    INDEX `idx_audit_action` (`target_table`, `action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------
INSERT INTO `users` (`user_id`,`username`,`password_hash`,`role`,`email`) VALUES
(1,'admin','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','admin','admin@example.org'),
(2,'dr_ssekandi','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','clinician','ssekandi@example.org'),
(3,'dr_namukasa','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','clinician','namukasa@example.org'),
(4,'amina_n','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','patient','amina.n@example.org'),
(5,'moses_o','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','patient','moses.o@example.org'),
(6,'grace_a','$2y$10$OdRtpUTOYvFIqPdb0VaznugrHKrSRecZhCkPl4C9jjSk6nfGF.STe','patient','grace.a@example.org')
ON DUPLICATE KEY UPDATE `username`=`username`;

INSERT INTO `clinicians` (`clinician_id`,`user_id`,`full_name`,`specialization`,`license_no`) VALUES
(1,2,'Dr. Peter Ssekandi','Endocrinology & Diabetology','UMDPC-DEMO-0001'),
(2,3,'Dr. Mary Namukasa','Internal Medicine','UMDPC-DEMO-0002')
ON DUPLICATE KEY UPDATE `full_name`=`full_name`;

-- Amina & Moses belong to Dr. Ssekandi; Grace belongs to Dr. Namukasa.
INSERT INTO `patients` (`patient_id`,`user_id`,`clinician_id`,`full_name`,`date_of_birth`,`gender`,`phone`,`assigned_at`) VALUES
(1,4,1,'Amina Nakato','1990-08-14','female','+256700000001',NOW()),
(2,5,1,'Moses Okello','1975-03-22','male','+256700000002',NOW()),
(3,6,2,'Grace Atim','1982-11-05','female','+256700000003',NOW())
ON DUPLICATE KEY UPDATE `full_name`=`full_name`;

INSERT INTO `patient_thresholds` (`patient_id`) VALUES (1),(2),(3)
ON DUPLICATE KEY UPDATE `patient_id`=`patient_id`;

INSERT INTO `audit_logs` (`user_id`,`target_table`,`action`,`details`)
SELECT 1,'system','INITIALIZE','Database initialised (Phase 1) with demo accounts.'
WHERE NOT EXISTS (SELECT 1 FROM `audit_logs` WHERE `action`='INITIALIZE');
