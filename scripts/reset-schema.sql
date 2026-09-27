-- ============================================================================
-- InternBoot Platform - Destructive Schema Reset
-- WARNING: THIS FILE DROPS ALL TABLES AND DESTROYS ALL DATA.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `candidates`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `assessments`;
DROP TABLE IF EXISTS `batches`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `exam_schedules`;
DROP TABLE IF EXISTS `exam_slots`;
DROP TABLE IF EXISTS `question_banks`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `options`;
DROP TABLE IF EXISTS `attempts`;
DROP TABLE IF EXISTS `answers`;
DROP TABLE IF EXISTS `results`;
DROP TABLE IF EXISTS `levels`;
DROP TABLE IF EXISTS `certificates`;
DROP TABLE IF EXISTS `placement_records`;
DROP TABLE IF EXISTS `admin_logs`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `email_verifications`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `attempt_questions`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `certificate_verification_attempts`;

SET FOREIGN_KEY_CHECKS = 1;
