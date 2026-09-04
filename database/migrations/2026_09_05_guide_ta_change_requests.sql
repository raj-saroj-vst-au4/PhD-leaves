-- ============================================================================
-- Guide / TA change requests
--
-- Students may no longer edit their own faculty guide or TA coordinator (that
-- was possible from profile.php and let a student re-route, shorten or opt out
-- of their own approval chain). Instead the correction is raised while applying
-- for leave and the ADMIN decides:
--
--     student applies + flags routing wrong
--         -> leave_applications.status = 'pending_routing'   (held, not routed)
--         -> guide_ta_change_requests row, status = 'pending'
--                 |
--      admin approves  ->  students.faculty_guide_id / ta_coordinator_id updated
--                          held leaves released into the normal chain
--                 |
--      admin rejects   ->  routing left as it was
--                          held leaves released into the normal chain anyway,
--                          under the EXISTING guide/TA
--
-- Either way the student is emailed the decision and the leave continues; a
-- rejected routing request is the admin confirming the current guide/TA is
-- correct, which is not a reason to throw the leave application away.
--
-- Current values are snapshotted on the request for the same reason
-- att_month_approvals snapshots its figures: the admin must be able to see what
-- the student was actually looking at, even if the roster moved underneath.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_09_05_guide_ta_change_requests.sql
-- Safe to re-run.
--
-- Portability note: this server is MySQL 8.0, which has no
-- `ADD COLUMN/KEY/CONSTRAINT IF NOT EXISTS` (that is MariaDB syntax). Each
-- non-idempotent DDL below is therefore guarded by an information_schema check
-- and executed as a prepared statement, which works on MySQL 5.7+, MySQL 8 and
-- MariaDB alike.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `guide_ta_change_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL COMMENT 'students.id',

  -- What was on record when the student raised this.
  `current_guide_id` INT(11) DEFAULT NULL COMMENT 'users.id at request time',
  `current_ta_id` INT(11) DEFAULT NULL COMMENT 'users.id at request time',

  -- What the student is asking for. Both columns always carry the full
  -- requested state (not just the half that changed), so approving is a single
  -- unambiguous assignment.
  `requested_guide_id` INT(11) DEFAULT NULL COMMENT 'users.id, must be role=faculty',
  `requested_ta_id` INT(11) DEFAULT NULL,

  `reason` TEXT NOT NULL COMMENT 'why the student says the current routing is wrong',

  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_remarks` TEXT DEFAULT NULL,
  `decided_by` INT(11) DEFAULT NULL COMMENT 'users.id of the admin',
  `decided_at` DATETIME DEFAULT NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- At most one request in flight per student. Enforced in the DB as well as in
  -- PHP because a double-submitted apply form would otherwise open two requests,
  -- and the second approval would silently undo the first. A unique index
  -- ignores NULLs, so only 'pending' rows occupy the slot and decided ones stack
  -- up freely.
  --
  -- VIRTUAL, not STORED: InnoDB refuses a cascading foreign key on a column that
  -- a STORED generated column is derived from ("Cannot add foreign key
  -- constraint", errno 1215), and student_id needs its ON DELETE CASCADE. The
  -- value is trivial to compute, so there is nothing to gain by storing it.
  -- GuideTAChange::create() also locks the student row and re-checks, so the
  -- rule holds even where this index cannot be created.
  `pending_slot` INT(11) AS (IF(`status` = 'pending', `student_id`, NULL)) VIRTUAL
      COMMENT 'student_id while pending, NULL once decided',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_one_pending` (`pending_slot`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_req_guide` (`requested_guide_id`),
  KEY `idx_req_ta` (`requested_ta_id`),
  CONSTRAINT `gtcr_student_fk` FOREIGN KEY (`student_id`)
      REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gtcr_req_guide_fk` FOREIGN KEY (`requested_guide_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gtcr_req_ta_fk` FOREIGN KEY (`requested_ta_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- leave_applications: the held state, and the link to what is holding it.
-- ---------------------------------------------------------------------------

-- 'pending_routing' is APPENDED to the enum. Order matters: MySQL stores an enum
-- as its ordinal, so inserting the new value mid-list would silently rewrite
-- every existing row's status. 'combined' is included because production has it
-- (database/schema.sql does not — it is stale; prod-schema-20260727.sql is the
-- accurate reference). A bare MODIFY is naturally idempotent.
ALTER TABLE `leave_applications`
    MODIFY `status` ENUM('pending_guide','pending_ta','pending_hod','pending_admin',
                         'approved','rejected','cancelled','combined','pending_routing')
        DEFAULT 'pending_guide';

-- routing_request_id: which request is holding this application.
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD COLUMN `routing_request_id` INT(11) DEFAULT NULL
             COMMENT ''guide_ta_change_requests.id holding this application''',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME = 'routing_request_id'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications` ADD KEY `idx_routing_request` (`routing_request_id`)',
        'DO 0')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND INDEX_NAME = 'idx_routing_request'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Many leaves may queue behind one request (a student can apply again while the
-- first is still with the admin), so this is deliberately not unique. ON DELETE
-- SET NULL rather than CASCADE: deleting a request must never delete a student's
-- leave application.
SET @ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `leave_applications`
             ADD CONSTRAINT `leave_applications_routing_fk`
             FOREIGN KEY (`routing_request_id`)
             REFERENCES `guide_ta_change_requests` (`id`) ON DELETE SET NULL',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_applications'
      AND CONSTRAINT_NAME = 'leave_applications_routing_fk'
);
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
