-- PhD Time-Off Portal Database Schema
-- Created: 2025-10-12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Database: phd_timeoff
CREATE DATABASE IF NOT EXISTS `phd_timeoff` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `phd_timeoff`;

-- --------------------------------------------------------
-- Table structure for table `users`
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('student','faculty','hod','admin') NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `invitation_token` varchar(255) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `password_reset_token` varchar(255) DEFAULT NULL,
  `password_reset_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `students`
-- --------------------------------------------------------

CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `roll_number` varchar(50) NOT NULL,
  `faculty_guide_id` int(11) DEFAULT NULL,
  `ta_coordinator_id` int(11) DEFAULT NULL,
  `joining_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `roll_number` (`roll_number`),
  KEY `faculty_guide_id` (`faculty_guide_id`),
  KEY `ta_coordinator_id` (`ta_coordinator_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`faculty_guide_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`ta_coordinator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `leave_balances`
-- --------------------------------------------------------

CREATE TABLE `leave_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `personal_leaves` decimal(5,1) DEFAULT 15.0,
  `medical_leaves` decimal(5,1) DEFAULT 5.0,
  `academic_leaves` decimal(5,1) DEFAULT 0.0,
  `last_reset_personal` date DEFAULT NULL,
  `last_reset_medical` date DEFAULT NULL,
  `last_reset_academic` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  CONSTRAINT `leave_balances_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `leave_applications`
-- --------------------------------------------------------

CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `leave_type` enum('personal','medical','academic') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending_guide','pending_ta','pending_hod','pending_admin','approved','rejected','cancelled') DEFAULT 'pending_guide',
  `guide_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `guide_remarks` text DEFAULT NULL,
  `guide_approved_at` datetime DEFAULT NULL,
  `ta_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `ta_remarks` text DEFAULT NULL,
  `ta_no_duty_confirmed` tinyint(1) DEFAULT 0,
  `ta_approved_at` datetime DEFAULT NULL,
  `hod_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `hod_remarks` text DEFAULT NULL,
  `hod_payment_status` enum('paid','partially_paid','unpaid') DEFAULT NULL,
  `hod_approved_at` datetime DEFAULT NULL,
  `admin_status` enum('pending','approved','rejected') DEFAULT NULL,
  `admin_document_path` varchar(255) DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `admin_approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_leave_type` (`leave_type`),
  KEY `idx_dates` (`start_date`, `end_date`),
  CONSTRAINT `leave_applications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `holidays`
-- --------------------------------------------------------

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `holiday_name` varchar(255) NOT NULL,
  `holiday_date` date NOT NULL,
  `is_working_day` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date` (`holiday_date`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `holidays_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `leave_adjustments`
-- --------------------------------------------------------

CREATE TABLE `leave_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `leave_type` enum('personal','medical','academic') NOT NULL,
  `adjustment_amount` decimal(5,1) NOT NULL,
  `remarks` text NOT NULL,
  `adjusted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `adjusted_by` (`adjusted_by`),
  CONSTRAINT `leave_adjustments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_adjustments_ibfk_2` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `email_logs`
-- --------------------------------------------------------

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `email_type` varchar(50) NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('sent','failed') DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recipient_id` (`recipient_id`),
  KEY `idx_type` (`email_type`),
  KEY `idx_reference` (`reference_id`),
  CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `system_logs`
-- --------------------------------------------------------

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_module` (`module`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `monthly_reminders`
-- --------------------------------------------------------

CREATE TABLE `monthly_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `month` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_students` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `month_year` (`month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Insert default admin user
-- Password: admin123 (CHANGE THIS IMMEDIATELY)
-- --------------------------------------------------------

COMMIT;
