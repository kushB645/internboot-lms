-- ============================================================================
-- InternBoot Platform - Complete Single-File Production MySQL Database Schema
-- Database Engine: MySQL 8.0+ / MariaDB 10.3+ (InnoDB Engine)
-- File: schema.sql
--
-- SAFE TO RE-RUN: All CREATE TABLE IF NOT EXISTS statements use IF NOT EXISTS.
-- Running this file directly creates missing tables and does NOT drop or alter existing data.
-- To apply this schema: php scripts/apply-schema.php
--
-- To FORCE a full destructive reinstall (drops all tables and data):
--   php scripts/apply-schema.php --force
-- ============================================================================

-- Safely disable foreign key checks during creation/re-creation
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Table 1: users
-- Purpose: Authentication & core user account records with role permissions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL COMMENT 'Bcrypt/Argon2id hashed password, never plaintext',
  `role` ENUM('candidate', 'admin', 'staff') NOT NULL DEFAULT 'candidate',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 2: candidates
-- Purpose: Candidate personal background, contact info, and profile details
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `candidates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) NOT NULL UNIQUE,
  `profile_details` TEXT DEFAULT NULL COMMENT 'JSON/Text profile metadata presence alone drives dashboard Verified status flag',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_candidates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_candidates_user_id` (`user_id`),
  INDEX `idx_candidates_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 3: payments
-- Purpose: Financial transaction history for candidate assessment registrations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `reference_number` VARCHAR(100) NOT NULL UNIQUE,
  `payment_date` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_payments_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_payments_candidate` (`candidate_id`),
  INDEX `idx_payments_assessment` (`assessment_id`),
  INDEX `idx_payments_status` (`status`),
  INDEX `idx_payments_ref_no` (`reference_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 4: assessments
-- Purpose: Assessment test configurations (duration, question counts, status)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assessments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
  `total_questions` INT UNSIGNED NOT NULL DEFAULT 50,
  `status` ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_assessments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 5: batches
-- Purpose: Candidate groupings formed together once threshold capacity is met
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `batches` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `batch_number` VARCHAR(50) NOT NULL UNIQUE,
  `assessment_id` BIGINT UNSIGNED NOT NULL,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_batches_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_batches_assessment` (`assessment_id`),
  INDEX `idx_batches_number` (`batch_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 6: enrollments
-- Purpose: Links candidate to assessment, eligibility status, payment & batch
-- Architecture Note: uk_candidate_assessment ensures a candidate has exactly ONE active registration per assessment. Retakes (if settings.retake_allowed = 1) create new rows in `attempts`, NOT new enrollments.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enrollments` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` BIGINT UNSIGNED DEFAULT NULL,
  `batch_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Allocated batch once threshold is met',
  `provisional_schedule_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Candidate preferred provisional schedule before finalization',
  `preferred_exam_date` DATE DEFAULT NULL,
  `eligibility_status` ENUM('pending', 'eligible') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_candidate_assessment` (`candidate_id`, `assessment_id`),
  CONSTRAINT `fk_enrollments_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollments_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollments_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollments_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollment_prov_sched` FOREIGN KEY (`provisional_schedule_id`) REFERENCES `exam_schedules` (`id`) ON DELETE SET NULL,
  INDEX `idx_enrollments_candidate` (`candidate_id`),
  INDEX `idx_enrollments_assessment` (`assessment_id`),
  INDEX `idx_enrollments_payment` (`payment_id`),
  INDEX `idx_enrollments_batch` (`batch_id`),
  INDEX `idx_enrollments_prov_sched` (`provisional_schedule_id`),
  INDEX `idx_enrollments_eligibility` (`eligibility_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Candidate registrations. Retakes create new attempts, not new enrollments.';

-- ----------------------------------------------------------------------------
-- Table 7: exam_schedules
-- Purpose: Scheduling dates for batch exams (Business logic restricts dates to Sat/Sun)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_schedules` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `exam_date` DATE NOT NULL COMMENT 'Exam date restricted to Saturday or Sunday',
  `status` ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'provisional') NOT NULL DEFAULT 'scheduled',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_schedules_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_schedules_batch` (`batch_id`),
  INDEX `idx_schedules_exam_date` (`exam_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 8: exam_slots
-- Purpose: Specific time slots, total capacity, and seat management per schedule.
-- Concurrency Note: Booking updates MUST execute as `UPDATE exam_slots SET seats_remaining = seats_remaining - 1 WHERE id = ? AND seats_remaining > 0` to prevent overselling.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_slots` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `exam_schedule_id` BIGINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 50,
  `seats_remaining` INT UNSIGNED NOT NULL DEFAULT 50,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_slots_schedule` FOREIGN KEY (`exam_schedule_id`) REFERENCES `exam_schedules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_seats_remaining` CHECK (`seats_remaining` >= 0),
  INDEX `idx_slots_schedule` (`exam_schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 9: question_banks
-- Purpose: Named collections of AI-generated/curated questions per assessment
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `question_banks` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `status` ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_qbanks_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_qbanks_assessment` (`assessment_id`),
  INDEX `idx_qbanks_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 10: questions
-- Purpose: Individual test questions linked to a question bank.
-- Exam Engine Note: Only questions with approval_status = 'approved' should be delivered in exams.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `questions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question_bank_id` BIGINT UNSIGNED NOT NULL,
  `question_text` TEXT NOT NULL,
  `type` ENUM('MCQ') NOT NULL DEFAULT 'MCQ',
  `difficulty` ENUM('easy', 'medium', 'hard') NOT NULL DEFAULT 'medium',
  `approval_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_questions_qbank` FOREIGN KEY (`question_bank_id`) REFERENCES `question_banks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_questions_qbank` (`question_bank_id`),
  INDEX `idx_questions_difficulty` (`difficulty`),
  INDEX `idx_questions_approval_status` (`approval_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 11: options
-- Purpose: Multiple-choice answer options per question with correctness flag
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `options` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `option_text` TEXT NOT NULL,
  `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_options_question` (`question_id`),
  INDEX `idx_options_is_correct` (`is_correct`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 12: attempts
-- Purpose: Execution details of candidate exam sessions, status & timings.
-- Constraint: exam_slot_id is NOT NULL with RESTRICT to ensure no NULL-bypass race conditions.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `assessment_id` BIGINT UNSIGNED NOT NULL,
  `exam_slot_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('in_progress', 'submitted', 'expired') NOT NULL DEFAULT 'in_progress',
  `start_time` DATETIME DEFAULT NULL,
  `end_time` DATETIME DEFAULT NULL COMMENT 'Server-side calculated mandatory completion deadline',
  `submitted_at` DATETIME DEFAULT NULL,
  `violations` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_candidate_exam_slot` (`candidate_id`, `exam_slot_id`),
  CONSTRAINT `fk_attempts_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempts_assessment` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempts_slot` FOREIGN KEY (`exam_slot_id`) REFERENCES `exam_slots` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_attempts_candidate` (`candidate_id`),
  INDEX `idx_attempts_assessment` (`assessment_id`),
  INDEX `idx_attempts_slot` (`exam_slot_id`),
  INDEX `idx_attempts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 13: answers
-- Purpose: Candidate selected options per question for an exam attempt
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `answers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `selected_option_id` BIGINT UNSIGNED DEFAULT NULL,
  `is_correct` TINYINT(1) DEFAULT NULL COMMENT 'Evaluated score status (0/1)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_attempt_question` (`attempt_id`, `question_id`),
  CONSTRAINT `fk_answers_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_answers_option` FOREIGN KEY (`selected_option_id`) REFERENCES `options` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_answers_attempt` (`attempt_id`),
  INDEX `idx_answers_question` (`question_id`),
  INDEX `idx_answers_option` (`selected_option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 14: results
-- Purpose: Final calculated score, percentage, and assigned level for an attempt
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `results` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `total_score` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `percentage` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
  `level_assigned` TINYINT UNSIGNED NOT NULL COMMENT 'Assigned candidate level (1-5)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_results_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_results_percentage` CHECK (`percentage` BETWEEN 0.00 AND 100.00),
  INDEX `idx_results_attempt` (`attempt_id`),
  INDEX `idx_results_level` (`level_assigned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 15: levels
-- Purpose: Configurable score range to Level (1-5) assignment rules
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `levels` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `level_number` TINYINT UNSIGNED NOT NULL UNIQUE COMMENT 'Level rank (1 to 5)',
  `level_name` VARCHAR(100) NOT NULL,
  `min_percentage` DECIMAL(5, 2) NOT NULL,
  `max_percentage` DECIMAL(5, 2) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_levels_min_percentage` CHECK (`min_percentage` BETWEEN 0.00 AND 100.00),
  CONSTRAINT `chk_levels_max_percentage` CHECK (`max_percentage` BETWEEN 0.00 AND 100.00),
  CONSTRAINT `chk_levels_range_valid` CHECK (`min_percentage` <= `max_percentage`),
  INDEX `idx_levels_number` (`level_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 16: certificates
-- Purpose: Issued certificates for candidates based on evaluated results
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `certificate_number` VARCHAR(100) NOT NULL UNIQUE,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `result_id` BIGINT UNSIGNED NOT NULL UNIQUE,
  `level` TINYINT UNSIGNED NOT NULL,
  `issue_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_certs_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_certs_result` FOREIGN KEY (`result_id`) REFERENCES `results` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_certs_number` (`certificate_number`),
  INDEX `idx_certs_candidate` (`candidate_id`),
  INDEX `idx_certs_result` (`result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 17: placement_records
-- Purpose: Post-assessment candidate recruitment status, result link, and employer notes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `placement_records` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `candidate_id` BIGINT UNSIGNED NOT NULL,
  `result_id` BIGINT UNSIGNED DEFAULT NULL,
  `placement_status` ENUM('eligible', 'shortlisted', 'interviewing', 'placed', 'not_placed') NOT NULL DEFAULT 'eligible',
  `notes` TEXT DEFAULT NULL,
  `company_name` VARCHAR(200) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_placement_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_placement_result` FOREIGN KEY (`result_id`) REFERENCES `results` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_placement_candidate` (`candidate_id`),
  INDEX `idx_placement_result` (`result_id`),
  INDEX `idx_placement_status` (`placement_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 18: admin_logs
-- Purpose: System audit trail for security tracking of administrative actions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_admin_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_admin_logs_user` (`user_id`),
  INDEX `idx_admin_logs_action` (`action`),
  INDEX `idx_admin_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 19: settings
-- Purpose: Global system configurations stored as key-value pairs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 20: email_verifications
-- Purpose: Staging table for pending registrations & OTP email verification (M3 Auth)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `otp_hash` VARCHAR(64) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('candidate', 'admin', 'staff') NOT NULL DEFAULT 'candidate',
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `resend_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email_verifications_email` (`email`),
  INDEX `idx_email_verifications_lookup` (`email`, `is_used`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks after table creation
SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
-- DATABASE TRIGGERS (Concurrency Safety & Seat Management)
-- Note: DELIMITER is not used here. apply-schema.php sends each trigger as a
-- single mysqli::query() call which does not need DELIMITER notation.
-- ============================================================================

-- Trigger 1: Prevent negative seats on UPDATE
DELIMITER $$
DROP TRIGGER IF EXISTS `trg_prevent_negative_seats_update`$$
CREATE TRIGGER `trg_prevent_negative_seats_update`
BEFORE UPDATE ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Concurrency Error: seats_remaining cannot be negative';
    END IF;
END$$

-- Trigger 2: Prevent negative seats on INSERT
DROP TRIGGER IF EXISTS `trg_prevent_negative_seats_insert`$$
CREATE TRIGGER `trg_prevent_negative_seats_insert`
BEFORE INSERT ON `exam_slots`
FOR EACH ROW
BEGIN
    IF NEW.seats_remaining < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Validation Error: Initial seats_remaining cannot be negative';
    END IF;
END$$

-- Trigger 3: Sync enrollments on successful payment INSERT
-- Note: This enforces the "success => eligible" forward-only synchronization to prevent desyncs.
-- If a payment is later refunded, eligibility is not automatically revoked (matches existing app behavior).
DROP TRIGGER IF EXISTS `trg_payments_after_insert`$$
CREATE TRIGGER `trg_payments_after_insert`
AFTER INSERT ON `payments`
FOR EACH ROW
BEGIN
    IF NEW.status = 'success' THEN
        INSERT INTO `enrollments` (`candidate_id`, `assessment_id`, `payment_id`, `eligibility_status`)
        VALUES (NEW.candidate_id, NEW.assessment_id, NEW.id, 'eligible')
        ON DUPLICATE KEY UPDATE 
            `payment_id` = VALUES(`payment_id`), 
            `eligibility_status` = 'eligible';
    END IF;
END$$

-- Trigger 4: Sync enrollments on successful payment UPDATE
-- Note: ON DUPLICATE KEY UPDATE strictly touches payment_id and eligibility_status so batch_id is preserved.
DROP TRIGGER IF EXISTS `trg_payments_after_update`$$
CREATE TRIGGER `trg_payments_after_update`
AFTER UPDATE ON `payments`
FOR EACH ROW
BEGIN
    IF NEW.status = 'success' AND OLD.status != 'success' THEN
        INSERT INTO `enrollments` (`candidate_id`, `assessment_id`, `payment_id`, `eligibility_status`)
        VALUES (NEW.candidate_id, NEW.assessment_id, NEW.id, 'eligible')
        ON DUPLICATE KEY UPDATE 
            `payment_id` = VALUES(`payment_id`), 
            `eligibility_status` = 'eligible';
    END IF;
END$$



DELIMITER ;

-- ============================================================================
-- INITIAL DATA SEEDING
-- ============================================================================

-- Default Global Application Settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('batch_threshold', '100', 'Minimum number of registered candidates required to form a batch'),
('exam_fee', '2999', 'Assessment fee per candidate in local currency (INR)'),
('negative_marking_enabled', '0', 'Boolean flag (1/0) indicating whether negative marking is active'),
('negative_marking_value', '0.25', 'Marks deducted per wrong (attempted) answer when negative_marking_enabled is 1'),
('retake_allowed', '0', 'Boolean flag (1/0) indicating whether candidates can re-attempt exams'),
('min_certificate_level', '4', 'Minimum level required for certificate issuance (default Level 4 / Basic Knowledge, 40%)'),
('min_certificate_percentage', '40.00', 'Minimum score percentage required for certificate issuance');

-- Sample Initial Level Mapping Configurations (Levels 1 to 5)
INSERT INTO `levels` (`level_number`, `level_name`, `min_percentage`, `max_percentage`, `description`) VALUES
(1, 'Top / Excellent', 85.00, 100.00, 'Top tier mastery eligible for premium placement tracks'),
(2, 'Intermediate', 70.00, 84.99, 'Strong proficiency across topics'),
(3, 'Basic / Employable', 55.00, 69.99, 'Competent skill level ready for standard entry-level roles'),
(4, 'Basic Knowledge', 40.00, 54.99, 'Basic understanding of core concepts'),
(5, 'Needs Training', 0.00, 39.99, 'Foundation level skills requiring additional training');

-- ----------------------------------------------------------------------------
-- Table 23: login_attempts
-- Purpose: Tracking failed logins for rate limiting
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_login_attempts_email_ip` (`email`, `ip_address`),
  INDEX `idx_login_attempts_ip` (`ip_address`),
  INDEX `idx_login_attempts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 23b: registration_attempts
-- Purpose: Tracking registration requests for rate limiting
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `registration_attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_registration_attempts_ip` (`ip_address`),
  INDEX `idx_registration_attempts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ----------------------------------------------------------------------------
-- Table 24: attempt_questions
-- Purpose: Frozen snapshot of the exact questions served to one attempt,
--          in fixed order, so the paper cannot change mid-exam.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attempt_questions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` BIGINT UNSIGNED NOT NULL,
  `question_id` BIGINT UNSIGNED NOT NULL,
  `position` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_attempt_question` (`attempt_id`, `question_id`),
  UNIQUE KEY `uk_attempt_position` (`attempt_id`, `position`),
  CONSTRAINT `fk_aq_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `attempts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_aq_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_aq_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 25: password_resets
-- Purpose: One-time tokens for the forgot-password flow.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_password_resets_user` (`user_id`),
  INDEX `idx_password_resets_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table 26: certificate_verification_attempts
-- Purpose: Tracking public certificate lookups for rate limiting (enumeration prevention)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `certificate_verification_attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cert_verif_ip` (`ip_address`),
  INDEX `idx_cert_verif_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- M5 preference batching migration
-- ----------------------------------------------------------------------------
ALTER TABLE `enrollments` 
  ADD COLUMN `preferred_date` DATE NULL COMMENT 'Candidate preferred exam date (Saturday or Sunday)',
  ADD COLUMN `preferred_time_slot` VARCHAR(50) NULL COMMENT 'Candidate preferred time slot (e.g. 10:00:00-11:00:00)';

ALTER TABLE `enrollments` ADD INDEX `idx_pref` (`preferred_date`, `preferred_time_slot`);
