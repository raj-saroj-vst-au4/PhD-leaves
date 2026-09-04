-- ============================================================================
-- Monthly attendance sign-off workflow
--
-- Each month every student's attendance summary is routed for approval:
--     pending_ta  ->  pending_guide  ->  approved
--                 \                  \
--                  ->  rejected       ->  rejected
--
-- One row per (student, month). The faculty UI groups rows into a single
-- combined view/email per faculty member, but the record is per student so the
-- decision, remarks and timestamps are attributable — the same shape the leave
-- workflow uses (leave_applications.guide_status / ta_status).
--
-- The attendance NUMBERS are snapshotted here on purpose. att_records is
-- append-only and can gain superseding rows later (an office override, a late
-- review approval); a sign-off has to record what the faculty member actually
-- saw and agreed to, not a figure that changes underneath it.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_08_06_attendance_approvals.sql
-- Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `att_month_approvals` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `period_year` INT(11) NOT NULL,
  `period_month` INT(11) NOT NULL COMMENT '1-12',
  `student_id` INT(11) NOT NULL COMMENT 'students.id',

  -- Who the routing was resolved to when the month was opened. Snapshotted so a
  -- later guide/TA reassignment cannot orphan or silently re-route a sign-off
  -- that is already in flight.
  `ta_coordinator_id` INT(11) DEFAULT NULL COMMENT 'users.id at snapshot time',
  `faculty_guide_id` INT(11) DEFAULT NULL COMMENT 'users.id at snapshot time',

  -- Attendance summary as presented for approval.
  `working_days` INT(11) NOT NULL DEFAULT 0,
  `present_days` INT(11) NOT NULL DEFAULT 0 COMMENT 'self-marked + office manual',
  `excused_days` INT(11) NOT NULL DEFAULT 0 COMMENT 'approved leave or office-excused',
  `absent_days` INT(11) NOT NULL DEFAULT 0,
  `percentage` DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = n/a, nothing accountable',

  `status` ENUM('pending_ta','pending_guide','approved','rejected')
      NOT NULL DEFAULT 'pending_ta',

  `ta_status` ENUM('pending','approved','rejected','skipped') NOT NULL DEFAULT 'pending',
  `ta_remarks` TEXT DEFAULT NULL,
  `ta_acted_at` DATETIME DEFAULT NULL,
  `ta_acted_by` INT(11) DEFAULT NULL COMMENT 'users.id who actually clicked',

  `guide_status` ENUM('pending','approved','rejected','skipped') NOT NULL DEFAULT 'pending',
  `guide_remarks` TEXT DEFAULT NULL,
  `guide_acted_at` DATETIME DEFAULT NULL,
  `guide_acted_by` INT(11) DEFAULT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  `updated_at` TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  -- The idempotency guarantee: re-running the cron for a month cannot duplicate
  -- or reopen a sign-off already in progress.
  UNIQUE KEY `uk_period_student` (`period_year`,`period_month`,`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_ta_status` (`ta_coordinator_id`,`status`),
  KEY `idx_guide_status` (`faculty_guide_id`,`status`),
  CONSTRAINT `att_month_appr_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `att_month_appr_ta_fk` FOREIGN KEY (`ta_coordinator_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `att_month_appr_guide_fk` FOREIGN KEY (`faculty_guide_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracks which months have been opened and notified, so a re-run is a no-op
-- rather than a second round of emails to every faculty member.
CREATE TABLE IF NOT EXISTS `att_month_approval_runs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `period_year` INT(11) NOT NULL,
  `period_month` INT(11) NOT NULL,
  `student_rows` INT(11) NOT NULL DEFAULT 0,
  `ta_emails_sent` INT(11) NOT NULL DEFAULT 0,
  `guide_emails_sent` INT(11) NOT NULL DEFAULT 0,
  `triggered_by` ENUM('cron','manual') NOT NULL DEFAULT 'cron',
  `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_period` (`period_year`,`period_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing installs: percentage started out NOT NULL DEFAULT 0.00, which turned
-- the report's deliberate "n/a" (a month with nothing accountable, e.g. fully
-- covered by approved leave) into a headline 0%. MODIFY is idempotent.
ALTER TABLE `att_month_approvals`
    MODIFY `percentage` DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = n/a, nothing accountable';
