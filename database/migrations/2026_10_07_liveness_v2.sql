-- ============================================================================
-- Liveness v2 (October 2026): named head turns, a blink and a colour flash,
-- shipped behind a flag.
--
-- No schema change is needed: a v2 challenge is stored in the existing
-- att_challenges.challenge / att_attempts.challenge_spec columns (VARCHAR(100);
-- every v2 spec is 68 characters or fewer, e.g.
--   v2:centre,flash,left,blink,right|RGBYMCR|380,450,410,500,360,470,340 )
-- and the new fail codes (BLINK_CHALLENGE_FAILED, FLASH_CHALLENGE_FAILED,
-- LIVENESS_REVIEW) fit att_attempts.fail_code VARCHAR(40).
--
-- This file only seeds the settings with their defaults, so they appear in
-- the office Configuration tab and in `SELECT * FROM att_settings`. The
-- backend uses the same defaults when a row is missing, so running it is
-- optional and changes no behaviour:
--
--   liveness_v2                 '0'  everyone stays on the current check
--   liveness_v2_students        '[]' students.id list piloting v2 meanwhile
--   flash_enabled               '1'  v2 includes the colour flash
--   flash_min_signal            skin colour change (RMS, log units) below
--                                    which the flash is "too weak to judge"
--                                    and the attempt goes to office review
--   flash_min_correlation       how closely the skin must follow the colours
--   antispoof_review_threshold  MiniFASNet spoof probability that sends an
--                                    otherwise-passing attempt to review
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_10_07_liveness_v2.sql
-- Safe to re-run (INSERT IGNORE never overwrites a value the office has set).
-- Portable across MySQL 8.0 (dev) and MariaDB 10.11 (prod).
-- Requires att_settings (2026_07_27_attendance.sql).
-- ============================================================================

INSERT IGNORE INTO `att_settings` (`skey`, `svalue`) VALUES
  ('liveness_v2', '0'),
  ('liveness_v2_students', '[]'),
  ('flash_enabled', '1'),
  ('flash_min_signal', '0.008'),
  ('flash_min_correlation', '0.75'),
  ('antispoof_review_threshold', '0.90');
