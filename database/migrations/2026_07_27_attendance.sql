-- ============================================================================
-- PhD Attendance module — schema
-- Runs inside the existing `phd_timeoff` database. All objects are prefixed
-- att_ so they never collide with the leave system's tables.
--
-- Identity is borrowed, not duplicated: att_* rows point at students.id, and
-- the roster / login / holidays already maintained by the leave system stay
-- the single source of truth.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_07_27_attendance.sql
-- Safe to re-run (every statement is IF NOT EXISTS / INSERT IGNORE).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Face templates. One ACTIVE row per student; re-enrollment revokes the old.
-- `embeddings` holds a JSON array of L2-normalised ArcFace vectors captured at
-- several angles, so verification is robust to head pose.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_face_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `embeddings` LONGTEXT NOT NULL COMMENT 'JSON: array of float vectors',
  `embedding_count` INT(11) NOT NULL DEFAULT 0,
  `model` VARCHAR(50) NOT NULL DEFAULT 'buffalo_l',
  `quality_score` DECIMAL(5,4) DEFAULT NULL,
  `frames_dir` VARCHAR(255) DEFAULT NULL COMMENT 'enrollment evidence frames',
  `enrolled_by` INT(11) DEFAULT NULL COMMENT 'users.id of supervising staff',
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `revoked_at` DATETIME DEFAULT NULL,
  `revoked_by` INT(11) DEFAULT NULL,
  `revoke_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_status` (`student_id`,`status`),
  CONSTRAINT `att_face_templates_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `att_face_templates_enroller_fk` FOREIGN KEY (`enrolled_by`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Device binding. Marking is only possible from the phone bound at enrollment.
-- Only the SHA-256 of the token is stored; the raw value lives in an httpOnly
-- cookie on the student's phone. This is what stops a laptop with a virtual
-- webcam from ever reaching the marking endpoint.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `ua_hint` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `revoked_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_student_status` (`student_id`,`status`),
  CONSTRAINT `att_devices_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Supervised enrollment sessions (staff-generated, single use, short TTL).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_enroll_sessions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `purpose` ENUM('enroll','re_enroll') NOT NULL DEFAULT 'enroll',
  `status` ENUM('pending','used','cancelled') NOT NULL DEFAULT 'pending',
  `expires_at` DATETIME NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_status_expiry` (`status`,`expires_at`),
  CONSTRAINT `att_enroll_sessions_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Single-use marking challenges (the random pose sequence). 2-minute TTL,
-- consumed on first use — this is what makes replaying a capture impossible.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_challenges` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `student_id` INT(11) NOT NULL,
  `device_id` INT(11) NOT NULL,
  `challenge` VARCHAR(100) NOT NULL COMMENT 'comma-joined pose sequence',
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_expiry` (`expires_at`),
  CONSTRAINT `att_challenges_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Every verification attempt — including failures. This is the evidence table:
-- a spoof attempt is stored with its frames and scores, which is both the
-- deterrent and the record the office reviews.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_attempts` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) DEFAULT NULL,
  `at_utc` DATETIME NOT NULL DEFAULT current_timestamp(),
  `date_ist` DATE NOT NULL,
  `source_ip` VARCHAR(45) DEFAULT NULL,
  `device_id` INT(11) DEFAULT NULL,
  `verdict` ENUM('pass','pending','fail') NOT NULL,
  `fail_code` VARCHAR(40) DEFAULT NULL,
  `match_score` DECIMAL(6,4) DEFAULT NULL,
  `liveness_score` DECIMAL(6,4) DEFAULT NULL,
  `pose_ok` TINYINT(1) DEFAULT NULL,
  `crossmatch_student_id` INT(11) DEFAULT NULL COMMENT 'if another students face scored higher',
  `crossmatch_score` DECIMAL(6,4) DEFAULT NULL,
  `challenge_spec` VARCHAR(100) DEFAULT NULL,
  `frames_dir` VARCHAR(255) DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  `detail` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_date` (`student_id`,`date_ist`),
  KEY `idx_date_verdict` (`date_ist`,`verdict`),
  KEY `idx_at` (`at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Attendance records — APPEND ONLY (enforced by grants in grants.sql and by
-- triggers below). A correction never edits a row: it inserts a new version
-- pointing at the one it supersedes, so the full history survives audit.
--   kind: self    = student marked with face verification
--         manual  = office marked present (reason mandatory)
--         excused = approved leave / office-excused (reason mandatory)
--         void    = cancels the previous version (reason mandatory)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_records` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `date_ist` DATE NOT NULL,
  `version` INT(11) NOT NULL DEFAULT 1,
  `kind` ENUM('self','manual','excused','void') NOT NULL,
  `marked_at_utc` DATETIME NOT NULL,
  `source_ip` VARCHAR(45) DEFAULT NULL,
  `device_id` INT(11) DEFAULT NULL,
  `attempt_id` BIGINT(20) DEFAULT NULL,
  `selfie_path` VARCHAR(255) DEFAULT NULL,
  `selfie_sha256` CHAR(64) DEFAULT NULL COMMENT 'survives the 60-day file purge',
  `match_score` DECIMAL(6,4) DEFAULT NULL,
  `liveness_score` DECIMAL(6,4) DEFAULT NULL,
  `reason` TEXT DEFAULT NULL COMMENT 'mandatory for manual/excused/void',
  `created_by_user_id` INT(11) DEFAULT NULL,
  `supersedes_id` BIGINT(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_student_date_version` (`student_id`,`date_ist`,`version`),
  UNIQUE KEY `uk_supersedes` (`supersedes_id`),
  KEY `idx_date` (`date_ist`),
  KEY `idx_student_date` (`student_id`,`date_ist`),
  CONSTRAINT `att_records_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Current state per student-day = highest version. Reports and dashboards read
-- this view; the append-only chain underneath stays intact.
CREATE OR REPLACE VIEW `att_records_current` AS
SELECT r.*
FROM `att_records` r
JOIN (
    SELECT `student_id`, `date_ist`, MAX(`version`) AS `v`
    FROM `att_records`
    GROUP BY `student_id`, `date_ist`
) m ON m.`student_id` = r.`student_id`
   AND m.`date_ist`   = r.`date_ist`
   AND m.`v`          = r.`version`;

-- ---------------------------------------------------------------------------
-- Marking window: per-weekday default (1=Mon … 7=Sun), minutes since IST
-- midnight. Start inclusive, end exclusive.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_windows` (
  `weekday` TINYINT(1) NOT NULL COMMENT '1=Mon .. 7=Sun',
  `is_working_day` TINYINT(1) NOT NULL DEFAULT 1,
  `start_minute` SMALLINT(4) NOT NULL DEFAULT 540,
  `end_minute` SMALLINT(4) NOT NULL DEFAULT 630,
  `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`weekday`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default: Mon–Fri 09:00–10:30 working, weekend off. Office edits in the UI.
INSERT IGNORE INTO `att_windows` (`weekday`,`is_working_day`,`start_minute`,`end_minute`) VALUES
  (1,1,540,630),(2,1,540,630),(3,1,540,630),(4,1,540,630),(5,1,540,630),
  (6,0,540,630),(7,0,540,630);

-- ---------------------------------------------------------------------------
-- Per-date window override. The working/non-working calendar itself comes from
-- the leave system's `holidays` table (already maintained by the office); this
-- table only overrides *times*, or forces a specific day's working status when
-- the office needs to differ from the holiday calendar.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_date_overrides` (
  `date_ist` DATE NOT NULL,
  `is_working_day` TINYINT(1) DEFAULT NULL COMMENT 'NULL = inherit holidays/weekday',
  `start_minute` SMALLINT(4) DEFAULT NULL,
  `end_minute` SMALLINT(4) DEFAULT NULL,
  `label` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`date_ist`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Emergency "extend today by N minutes" — cumulative, always logged. This is
-- the fairness valve for a WiFi/power/server problem during the window.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_extensions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date_ist` DATE NOT NULL,
  `extra_minutes` SMALLINT(4) NOT NULL,
  `reason` TEXT NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`date_ist`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- The WiFi gate: WAN IP(s) of the office router as this server sees them.
-- Rows are deactivated, never deleted, so history stays auditable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_allowed_ips` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `deactivated_at` DATETIME DEFAULT NULL,
  `deactivated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Settings (thresholds, toggles, report recipients).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_settings` (
  `skey` VARCHAR(64) NOT NULL,
  `svalue` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `att_settings` (`skey`,`svalue`) VALUES
  ('face_match_threshold','0.42'),      -- cosine; >= accepted outright
  ('face_review_threshold','0.32'),     -- between review & match => pending review
  ('liveness_threshold','0.60'),
  ('crossmatch_margin','0.05'),         -- other student must not beat own by this
  ('max_attempts_per_day','5'),
  ('frame_retention_days','60'),
  ('strict_mode','0'),                  -- rotating office code (off by default)
  ('strict_code_secret',''),
  ('report_recipients','[]'),
  ('enrollment_min_frames','5'),
  ('attendance_enabled','1');

-- ---------------------------------------------------------------------------
-- Audit log — APPEND ONLY. Every admin action and every blocked attempt.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_audit` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `at_utc` DATETIME NOT NULL DEFAULT current_timestamp(),
  `actor_type` ENUM('admin','student','system') NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `student_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50) DEFAULT NULL,
  `target_id` VARCHAR(64) DEFAULT NULL,
  `source_ip` VARCHAR(45) DEFAULT NULL,
  `details` TEXT DEFAULT NULL COMMENT 'JSON',
  PRIMARY KEY (`id`),
  KEY `idx_action_at` (`action`,`at_utc`),
  KEY `idx_student_at` (`student_id`,`at_utc`),
  KEY `idx_user_at` (`user_id`,`at_utc`),
  KEY `idx_at` (`at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Monthly report archive. Regeneration bumps `version`; old files stay on disk
-- with their checksums, because stipends were paid against them.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_reports` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `period_year` INT(11) NOT NULL,
  `period_month` INT(11) NOT NULL,
  `version` INT(11) NOT NULL DEFAULT 1,
  `trigger_type` ENUM('scheduled','manual') NOT NULL DEFAULT 'scheduled',
  `triggered_by` INT(11) DEFAULT NULL,
  `generated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `working_days` INT(11) NOT NULL DEFAULT 0,
  `student_count` INT(11) NOT NULL DEFAULT 0,
  `xlsx_path` VARCHAR(255) DEFAULT NULL,
  `xlsx_sha256` CHAR(64) DEFAULT NULL,
  `email_status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `email_error` TEXT DEFAULT NULL,
  `emailed_to` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_period_version` (`period_year`,`period_month`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Belt-and-braces append-only enforcement. Grants (grants.sql) are the primary
-- control; these triggers also stop a privileged accident at the console.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `att_records_no_update`;
DROP TRIGGER IF EXISTS `att_records_no_delete`;
DROP TRIGGER IF EXISTS `att_audit_no_update`;
DROP TRIGGER IF EXISTS `att_audit_no_delete`;

DELIMITER $$
CREATE TRIGGER `att_records_no_update` BEFORE UPDATE ON `att_records`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'att_records is append-only: insert a superseding version instead';
END$$

CREATE TRIGGER `att_records_no_delete` BEFORE DELETE ON `att_records`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'att_records is append-only: rows cannot be deleted';
END$$

CREATE TRIGGER `att_audit_no_update` BEFORE UPDATE ON `att_audit`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'att_audit is append-only';
END$$

CREATE TRIGGER `att_audit_no_delete` BEFORE DELETE ON `att_audit`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'att_audit is append-only';
END$$
DELIMITER ;
