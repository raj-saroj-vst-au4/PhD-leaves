-- ============================================================================
-- Any device (October 2026): students may mark attendance from any phone or
-- browser, not only the one holding the att_device cookie from enrollment.
--
-- The device check (gate 4, marking.check_device) becomes a setting:
--
--   require_enrolled_device   '0'  any device; the face check, liveness,
--                                  office WiFi and window still apply
--                             '1'  only the phone bound at enrollment
--
-- With the check off a challenge is issued to no device, so
-- att_challenges.device_id becomes nullable (att_attempts.device_id and
-- att_records.device_id already are). Enrollment still binds a device, so
-- turning the check back on needs no re-enrollment.
--
-- The backend treats a missing row as '1' (the old behaviour), so code and
-- schema can be deployed in either order; apply this BEFORE the new backend
-- so a cookieless challenge never meets a NOT NULL column.
--
-- Apply:  mysql -u root -p phd_timeoff < 2026_10_09_optional_device.sql
-- Safe to re-run (MODIFY to the same definition is a no-op; INSERT IGNORE
-- never overwrites a value the office has set).
-- Portable across MySQL 8.0 (dev) and MariaDB 10.11 (prod).
-- Requires att_challenges and att_settings (2026_07_27_attendance.sql).
-- ============================================================================

ALTER TABLE `att_challenges` MODIFY `device_id` INT(11) DEFAULT NULL;

INSERT IGNORE INTO `att_settings` (`skey`, `svalue`) VALUES
  ('require_enrolled_device', '0');
