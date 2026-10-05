-- ============================================================================
-- Combined leaves: a pointer from each source to the leave that replaced it,
-- and a record of what was refunded when it was combined.
--
-- Leave::combineLeaves() refunds the days of every source that was already
-- approved (and so already deducted), because the combined leave is charged
-- instead. If that combined leave is then rejected or cancelled — the Admin
-- turns down an academic leave that, combined, crossed the Dean AP threshold —
-- the sources' days would never be charged at all. Every reject/cancel path now
-- calls Leave::restoreCombinedSources(), which finds the sources through
-- combined_into_id, puts back to 'approved' the ones with combine_refunded_days,
-- and deducts exactly that again.
--
--   combined_into_id       leave_applications.id this leave was combined into
--                          (was only in admin_remarks as "Combined into leave #N")
--   combine_refunded_days  days refunded when it was combined; NULL = nothing
--                          was refunded (it was still pending), or it has been
--                          restored since
--
-- Existing 'combined' rows are NOT back-filled: the old combineLeaves() never
-- refunded anything, so none of them has anything to restore.
-- (database/fixes/2026_09_23_refund_combined_double_charge.php deals with those.)
--
-- Apply BEFORE deploying the code that uses it:
--   mysql -u root -p phd_timeoff < 2026_09_23_leaves.sql
-- Safe to re-run: each DDL is guarded by an information_schema check and run as
-- a prepared statement (MySQL 8 has no ADD COLUMN IF NOT EXISTS; this works on
-- MySQL 5.7+/8 and MariaDB alike).
-- ============================================================================

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `combined_into_id` INT(11) DEFAULT NULL
             COMMENT ''leave_applications.id this leave was combined into''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'combined_into_id'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `combine_refunded_days` INT(11) DEFAULT NULL
             COMMENT ''days refunded when combined; re-deducted if the combined leave is rejected/cancelled''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'combine_refunded_days'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications` ADD KEY `idx_combined_into` (`combined_into_id`)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND INDEX_NAME = 'idx_combined_into'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ON DELETE SET NULL, like adjacent_leave_id: deleting a leave must never delete
-- the applications that were combined into it.
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD CONSTRAINT `leave_applications_combined_into_fk`
             FOREIGN KEY (`combined_into_id`)
             REFERENCES `leave_applications` (`id`) ON DELETE SET NULL',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND CONSTRAINT_NAME = 'leave_applications_combined_into_fk'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
