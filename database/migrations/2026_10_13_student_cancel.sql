-- ============================================================================
-- Students cancel their own leave (October 2026): a student may cancel a
-- pending or approved application of theirs until the day before it starts
-- (Approval::cancelByStudent). An approved one has its days returned to the
-- balance.
--
-- Who cancelled, when and why, for every cancellation from now on — the
-- student's and the admin's (Approval::cancelByAdmin also fills them):
--
--   cancelled_by    users.id of whoever cancelled it
--   cancelled_at    when
--   cancel_reason   the reason they gave (stored escaped, like other remarks)
--
-- The approval history shows these when set. Older cancelled rows have none;
-- they keep falling back to system_logs and admin_remarks as before.
--
-- Apply BEFORE deploying the code that uses it:
--   mysql -u root -p phd_timeoff < 2026_10_13_student_cancel.sql
-- Safe to re-run: each DDL is guarded by an information_schema check and run as
-- a prepared statement (MySQL 8 has no ADD COLUMN IF NOT EXISTS; this works on
-- MySQL 5.7+/8 and MariaDB alike).
-- ============================================================================

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `cancelled_by` INT(11) DEFAULT NULL
             COMMENT ''users.id of whoever cancelled the application''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'cancelled_by'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `cancelled_at` DATETIME DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'cancelled_at'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `cancel_reason` TEXT DEFAULT NULL',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'cancel_reason'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ON DELETE SET NULL: deleting a user must not delete the applications they
-- cancelled.
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD CONSTRAINT `leave_applications_cancelled_by_fk`
             FOREIGN KEY (`cancelled_by`)
             REFERENCES `users` (`id`) ON DELETE SET NULL',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND CONSTRAINT_NAME = 'leave_applications_cancelled_by_fk'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
