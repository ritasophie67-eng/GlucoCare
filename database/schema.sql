-- =====================================================================
-- GlucoCare - Diabetes Clinical Decision-Support & Self-Management System
-- Case study: Sir Albert Cook Hospital, Kampala
-- PHASE 4 (complete system): Phase 3 + vitals, vitals-gated prescriptions, simulation & evaluation
-- Safe to re-run (IF NOT EXISTS / ON DUPLICATE KEY).
-- Demo password for ALL seed accounts: Password123!
-- All seed people are fictional.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `glucocare` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `glucocare`;

-- A hospital or clinic network. Every account belongs to exactly one organisation;
-- data is only ever shown to people inside the same organisation.
CREATE TABLE IF NOT EXISTS `organisations` (
    `org_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `short_name` VARCHAR(40) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('patient','clinician','admin') NOT NULL,
    `org_id` INT NOT NULL DEFAULT 1,                                   -- owning organisation
    `totp_secret` VARCHAR(64) NULL,                                    -- set when 2FA is enabled
    `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
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
    `phone` VARCHAR(20) NOT NULL,                                     -- canonical E.164 (+256XXXXXXXXX), shown as +256 700 000 001
    `allergies` VARCHAR(255) NULL,                                    -- free text, checked when prescribing
    `preferred_unit` ENUM('mg/dL','mmol/L') NOT NULL DEFAULT 'mg/dL',  -- display/logging unit for this patient
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
    `glucose_value` DECIMAL(5,1) NOT NULL,                              -- canonical value, always mg/dL
    `entered_unit` ENUM('mg/dL','mmol/L') NOT NULL DEFAULT 'mg/dL',     -- unit the patient typed the reading in
    `meal_context` ENUM('fasting','pre_meal','post_meal','bedtime','nocturnal') NOT NULL,
    `symptoms` VARCHAR(255) NULL,
    `was_confirmed` TINYINT(1) NOT NULL DEFAULT 0,   -- patient confirmed an extreme value
    `was_edited` TINYINT(1) NOT NULL DEFAULT 0,      -- corrected by the patient after saving
    `edited_at` TIMESTAMP NULL DEFAULT NULL,
    `logged_at` DATETIME NOT NULL,                    -- when the reading was taken
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- when it was submitted
    CONSTRAINT `fk_readings_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
    INDEX `idx_patient_logged` (`patient_id`, `logged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alerts generated by the rule engine. A clinician sees only alerts of THEIR assigned patients.
CREATE TABLE IF NOT EXISTS `clinical_alerts` (
    `alert_id` INT AUTO_INCREMENT PRIMARY KEY,
    `reading_id` INT NULL,
    `patient_id` INT NOT NULL,
    `clinician_id` INT NULL,
    `alert_type` VARCHAR(40) NOT NULL,
    `risk_level` ENUM('low','moderate','high','critical') NOT NULL,
    `data_confidence` ENUM('high','low') NOT NULL,
    `adherence_pct` DECIMAL(5,1) NOT NULL DEFAULT 0,
    `alert_message` TEXT NOT NULL,
    `status` ENUM('pending','reviewed','resolved') NOT NULL DEFAULT 'pending',
    `clinician_note` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    `digest_sent_at` TIMESTAMP NULL DEFAULT NULL,
    `digest_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,             -- delivery retries
    `digest_error` VARCHAR(255) NULL,                                  -- last delivery error
    `patient_notified_at` TIMESTAMP NULL DEFAULT NULL,                 -- SMS sent to the patient
    CONSTRAINT `fk_alerts_reading` FOREIGN KEY (`reading_id`) REFERENCES `glucose_readings`(`reading_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_alerts_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_alerts_clinician` FOREIGN KEY (`clinician_id`) REFERENCES `clinicians`(`clinician_id`) ON DELETE SET NULL,
    INDEX `idx_alert_patient` (`patient_id`, `status`, `alert_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vitals recorded by clinical staff. A prescription cannot be issued without recent vitals.
CREATE TABLE IF NOT EXISTS `vitals` (
    `vital_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `recorded_by_user_id` INT NOT NULL,
    `systolic_bp` INT NOT NULL,
    `diastolic_bp` INT NOT NULL,
    `heart_rate` INT NOT NULL,
    `weight_kg` DECIMAL(5,1) NULL,
    `temperature_c` DECIMAL(3,1) NULL,
    `notes` VARCHAR(255) NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_vitals_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vitals_recorder` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_vitals_patient` (`patient_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prescriptions` (
    `prescription_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `clinician_id` INT NOT NULL,
    `vital_id` INT NULL,                       -- the vitals check this prescription was issued against
    `medication_name` VARCHAR(100) NOT NULL,
    `dosage` VARCHAR(50) NOT NULL,
    `frequency` VARCHAR(50) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `status` ENUM('active','completed','discontinued') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rx_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rx_clinician` FOREIGN KEY (`clinician_id`) REFERENCES `clinicians`(`clinician_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rx_vital` FOREIGN KEY (`vital_id`) REFERENCES `vitals`(`vital_id`) ON DELETE SET NULL,
    INDEX `idx_rx_patient` (`patient_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ground truth for the simulated datasets (used only by admin/evaluation.php).
CREATE TABLE IF NOT EXISTS `sim_ground_truth` (
    `truth_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_id` INT NOT NULL,
    `window_index` TINYINT NOT NULL,
    `window_start` DATETIME NOT NULL,
    `window_end` DATETIME NOT NULL,
    `is_positive` TINYINT(1) NOT NULL,        -- 1 = a dangerous pattern or severe event was injected
    `severe_events` INT NOT NULL DEFAULT 0,   -- injected single severe lows / critical highs
    `note` VARCHAR(40) NOT NULL DEFAULT '',
    CONSTRAINT `fk_sim_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value store for job bookkeeping (last scan, last digest, last backup).
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(64) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use, hashed, time-limited password reset tokens.
CREATE TABLE IF NOT EXISTS `password_resets` (
    `reset_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    INDEX `idx_reset_token` (`token_hash`)
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
INSERT INTO `organisations` (`org_id`,`name`,`short_name`) VALUES
(1,'GlucoCare Network','Network')
ON DUPLICATE KEY UPDATE `name`=`name`;

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
