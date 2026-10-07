-- ============================================================================
-- Liveness v2 (October 2026): one named head turn and a blink (plus an
-- optional colour flash, off by default), shipped behind a flag.
--
-- No schema change is needed: a v2 challenge is stored in the existing
-- att_challenges.challenge / att_attempts.challenge_spec columns (VARCHAR(100);
-- a v2 spec is e.g. "v2:centre,blink,left", at most 63 characters even with
-- the optional flash) and the new fail codes (BLINK_CHALLENGE_FAILED,
-- FLASH_CHALLENGE_FAILED, LIVENESS_REVIEW) fit att_attempts.fail_code
-- VARCHAR(40).
--
-- This file only seeds the settings with their defaults, so they appear in
-- the office Configuration tab and in `SELECT * FROM att_settings`. The
-- backend uses the same defaults when a row is missing, so running it is
-- optional and changes no behaviour:
--
--   liveness_v2                 '0'  everyone stays on the current check
--   liveness_v2_students        '[]' students.id list piloting v2 meanwhile
--   flash_enabled               '0'  '1' adds the colour flash step back
--   review_skip_match           '0.65' a v2 mark held only for a soft liveness
--                                    doubt (blink unclear, flash weak,
--                                    anti-spoof) passes at this match or more
--   blink_closed_min / blink_open_max / blink_min_diff
--                                    the blink, on MediaPipe's eyeBlink scores
--   flash_min_signal            skin colour change (RMS, log units) below
--                                    which the flash is "too weak to judge"
--                                    and the attempt goes to office review
--   flash_min_correlation       how closely the skin must follow the colours
--   antispoof_review_threshold  MiniFASNet spoof probability that sends an
--                                    otherwise-passing attempt to review
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_10_07_liveness_v2.sql
-- Safe to re-run (INSERT IGNORE never overwrites a value the office has set).
-- NOTE for a database that ran an earlier copy of this file (production, Oct
-- 2026): that copy seeded flash_enabled='1'. Re-running adds the new keys but
-- leaves it at '1' — set it to '0' by hand:
--   UPDATE att_settings SET svalue='0' WHERE skey='flash_enabled';
-- Portable across MySQL 8.0 (dev) and MariaDB 10.11 (prod).
-- Requires att_settings (2026_07_27_attendance.sql).
-- ============================================================================

INSERT IGNORE INTO `att_settings` (`skey`, `svalue`) VALUES
  ('liveness_v2', '0'),
  ('liveness_v2_students', '[]'),
  ('flash_enabled', '0'),
  ('flash_min_signal', '0.008'),
  ('flash_min_correlation', '0.75'),
  ('antispoof_review_threshold', '0.90'),
  ('review_skip_match', '0.65'),
  ('blink_closed_min', '0.45'),
  ('blink_open_max', '0.40'),
  ('blink_min_diff', '0.25');
