-- ============================================================================
-- Monthly attendance sign-off: correctable figures, reopen, notification state
--
-- 2026_08_06_attendance_approvals.sql froze each student-month's figures at the
-- moment the month was opened. That was meant to pin down "what the faculty
-- member actually saw and agreed to", but it pinned them down BEFORE anybody
-- had seen them: a leave approved a week after opening never reached the row, an
-- office override never reached it, and a disputed row could never be put right
-- and signed again. The figures are now recomputed from the attendance record
-- while a row is still pending, and what each signer attested to is pinned by
-- VERSION instead:
--
--   figures_version        bumps every time a recomputation changes the figures
--   figures_updated_at     when that last happened (NULL = as opened)
--   ta_figures_version     the version the TA coordinator acted on
--   guide_figures_version  the version the faculty guide acted on
--   history                JSON list of what changed and why: every figure
--                          change (with the previous snapshot), every reopen
--                          (with the dispute it cleared), every re-route
--
-- The faculty page posts the version it rendered and AttendanceApproval::act()
-- refuses a decision on any other, so nobody signs numbers they were not shown.
--
--   outdated_figures       set by the admin re-check on a SIGNED row whose
--                          figures no longer match the record (a leave that
--                          was still at the HOD when the month was signed,
--                          approved later): the recomputed figures, as JSON.
--                          The signed figures themselves are never rewritten;
--                          the admin reopens the row to correct it.
--
--   notified_at            when the faculty member at the CURRENT stage was
--                          emailed about this row. Reset whenever the stage or
--                          its assignee changes. A catch-up run of the monthly
--                          cron used to email every faculty member again; it now
--                          emails only rows nobody has been told about.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_09_23_attendance_signoff.sql
-- Safe to re-run.
--
-- Portability: production is MariaDB 10.11 and dev is MySQL 8.0, which has no
-- `ADD COLUMN IF NOT EXISTS`. Each ADD is guarded by an information_schema check
-- and run as a prepared statement (house pattern, see
-- 2026_09_05_guide_ta_change_requests.sql).
-- ============================================================================

-- Decided BEFORE any column is added: the backfills at the bottom must run on
-- the first application only. On a re-run, a NULL notified_at is a genuinely
-- un-notified row and must stay NULL.
SET @first_run = (
    SELECT IF(COUNT(*) = 0, 1, 0)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'notified_at'
);

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `figures_version` INT(11) NOT NULL DEFAULT 1
             COMMENT ''bumps each time a recomputation changes the figures''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'figures_version'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `figures_updated_at` DATETIME DEFAULT NULL
             COMMENT ''last recomputation that changed the figures; NULL = as opened''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'figures_updated_at'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `ta_figures_version` INT(11) DEFAULT NULL
             COMMENT ''figures_version the TA coordinator acted on''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'ta_figures_version'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `guide_figures_version` INT(11) DEFAULT NULL
             COMMENT ''figures_version the faculty guide acted on''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'guide_figures_version'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- MEDIUMTEXT rather than JSON: MariaDB's JSON is only an alias for LONGTEXT and
-- MySQL's is a binary type, so plain text behaves the same on both.
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `history` MEDIUMTEXT DEFAULT NULL
             COMMENT ''JSON list: figure changes, reopens, re-routes''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'history'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `outdated_figures` TEXT DEFAULT NULL
             COMMENT ''signed row no longer matching the record: recomputed figures (JSON)''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'outdated_figures'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `att_month_approvals`
             ADD COLUMN `notified_at` DATETIME DEFAULT NULL
             COMMENT ''current-stage faculty emailed at; NULL = not yet told''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'att_month_approvals'
      AND COLUMN_NAME = 'notified_at'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- Backfill, first application only.
-- ---------------------------------------------------------------------------

-- Every row opened before this migration was emailed by the cron (or by the TA
-- hand-off). Without this, the first catch-up run after deploying would treat
-- all of them as never notified and email every faculty member again — the very
-- behaviour this migration exists to stop.
UPDATE `att_month_approvals`
   SET `notified_at` = COALESCE(`ta_acted_at`, `created_at`)
 WHERE @first_run = 1 AND `notified_at` IS NULL;

-- Figures never changed before this migration, so anything already acted on was
-- acted on at version 1.
UPDATE `att_month_approvals`
   SET `ta_figures_version` = 1
 WHERE @first_run = 1 AND `ta_status` IN ('approved','rejected') AND `ta_figures_version` IS NULL;

UPDATE `att_month_approvals`
   SET `guide_figures_version` = 1
 WHERE @first_run = 1 AND `guide_status` IN ('approved','rejected') AND `guide_figures_version` IS NULL;
