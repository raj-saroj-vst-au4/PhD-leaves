-- ============================================================================
-- Attendance backend hardening (September 2026 audit)
--
--  1. Deleting a student may no longer wipe their attendance.
--     att_records and att_month_approvals referenced students ON DELETE
--     CASCADE, and users -> students is CASCADE too, so hard-deleting an
--     inactive user from admin/users.php silently erased every attendance row
--     and every monthly sign-off for them. InnoDB does not fire triggers for
--     cascaded deletes, so the append-only triggers on att_records never saw
--     it, and nothing was logged. Both become ON DELETE RESTRICT: such a DELETE
--     now fails with error 1451 (SQLSTATE 23000) and removes nothing at all.
--
--     Deliberately LEFT as CASCADE: att_face_templates, att_devices,
--     att_enroll_sessions, att_challenges. Those are biometric/operational
--     data about the person, not the attendance record; removing them with the
--     person is the privacy-correct outcome, and a student who never marked
--     can still be removed cleanly.
--
--  2. att_challenges.issued_at — millisecond issue time, so the backend can
--     reject a marking burst that arrives faster than a person can perform the
--     pose steps. The backend reads it only if present, so deploying the code
--     before this migration is harmless (the lower bound is just not enforced).
--
--  3. att_frame_hashes — the SHA-256 of every frame a student has submitted
--     (marking and enrollment), so a burst containing a frame sent before is
--     refused as a replay. Hashes only, no image content. The backend skips
--     the check (with a log warning) while this table does not exist.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_09_23_attendance_backend.sql
-- Safe to re-run. Portable across MySQL 8.0 (dev) and MariaDB 10.11 (prod):
-- neither `ADD COLUMN IF NOT EXISTS` nor a same-statement drop/re-add of a
-- foreign key is relied on; each step is guarded by an information_schema
-- check and run as a prepared statement.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1a. att_records.student_id -> students: CASCADE -> RESTRICT
-- ---------------------------------------------------------------------------
SET @ddl = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `att_records` DROP FOREIGN KEY `att_records_student_fk`',
        'DO 0')
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_records'
      AND CONSTRAINT_NAME = 'att_records_student_fk'
      AND DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION')
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_records`
             ADD CONSTRAINT `att_records_student_fk` FOREIGN KEY (`student_id`)
             REFERENCES `students` (`id`) ON DELETE RESTRICT',
        'DO 0')
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_records'
      AND CONSTRAINT_NAME = 'att_records_student_fk'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 1b. att_month_approvals.student_id -> students: CASCADE -> RESTRICT
--     (the signed-off figures a stipend was paid against)
-- ---------------------------------------------------------------------------
SET @ddl = (
    SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `att_month_approvals` DROP FOREIGN KEY `att_month_appr_student_fk`',
        'DO 0')
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND CONSTRAINT_NAME = 'att_month_appr_student_fk'
      AND DELETE_RULE NOT IN ('RESTRICT', 'NO ACTION')
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Guarded on the table too: an install that never applied
-- 2026_08_06_attendance_approvals.sql has nothing to constrain.
SET @ddl = (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'att_month_approvals') = 1
        AND COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD CONSTRAINT `att_month_appr_student_fk` FOREIGN KEY (`student_id`)
             REFERENCES `students` (`id`) ON DELETE RESTRICT',
        'DO 0')
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND CONSTRAINT_NAME = 'att_month_appr_student_fk'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2. att_challenges.issued_at
--    DATETIME(3) with its own default, so the backend's INSERT need not name
--    it. The backend session runs in UTC, and NOW(3) at consume time is read
--    on the same session clock, so no timezone conversion is involved.
-- ---------------------------------------------------------------------------
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_challenges`
             ADD COLUMN `issued_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
             COMMENT ''issue time, ms precision (minimum-duration check)''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_challenges'
      AND COLUMN_NAME = 'issued_at'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 3. att_frame_hashes
--    uk_student_sha is the replay guard: the backend inserts a burst's hashes
--    in ONE statement, so a burst reusing any earlier frame fails as a whole,
--    even when two copies race. CASCADE with the student, like the other
--    per-person operational tables (it is not attendance evidence).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_frame_hashes` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `attempt_id` BIGINT(20) DEFAULT NULL COMMENT 'att_attempts.id, NULL for an enrollment frame',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_student_sha` (`student_id`,`sha256`),
  KEY `idx_attempt` (`attempt_id`),
  CONSTRAINT `att_frame_hashes_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
