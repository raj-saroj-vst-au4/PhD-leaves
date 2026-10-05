-- ============================================================================
-- Database user for the attendance backend, with append-only enforcement.
--
-- Run as MySQL/MariaDB root AFTER applying database/migrations/2026_07_27_attendance.sql:
--   mysql -u root -p < attendance/ops/grants.sql
--
-- Replace the password below, then put the same value in attendance/.env
-- (DB_PASSWORD). The container uses host networking and connects over the
-- loopback interface, hence the 'localhost' host.
-- ============================================================================

CREATE USER IF NOT EXISTS 'attendance_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

-- Existing portal tables: READ ONLY. The attendance module never writes them.
GRANT SELECT ON phd_timeoff.users              TO 'attendance_app'@'localhost';
GRANT SELECT ON phd_timeoff.students           TO 'attendance_app'@'localhost';
GRANT SELECT ON phd_timeoff.holidays           TO 'attendance_app'@'localhost';
GRANT SELECT ON phd_timeoff.leave_applications TO 'attendance_app'@'localhost';
-- The monthly report dates a deactivation from the portal's audit entry
-- (users has no deactivated_at). Without this it falls back to updated_at.
GRANT SELECT ON phd_timeoff.system_logs        TO 'attendance_app'@'localhost';

-- APPEND-ONLY evidence tables: INSERT + SELECT, no UPDATE, no DELETE, ever.
-- (att_records/att_audit also have BEFORE UPDATE/DELETE triggers as backup.)
GRANT SELECT, INSERT ON phd_timeoff.att_records TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT ON phd_timeoff.att_audit   TO 'attendance_app'@'localhost';
GRANT SELECT ON phd_timeoff.att_records_current TO 'attendance_app'@'localhost';

-- Attempts: verdict flips pending->pass/fail during office review; rows are
-- never deleted.
GRANT SELECT, INSERT, UPDATE ON phd_timeoff.att_attempts TO 'attendance_app'@'localhost';

-- Operational tables: normal DML, no DDL. (Revoked templates are erased by
-- UPDATE — embeddings='[]' — so att_face_templates still needs no DELETE.)
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_face_templates  TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_devices         TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_enroll_sessions TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON phd_timeoff.att_challenges      TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_windows         TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON phd_timeoff.att_date_overrides  TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT                 ON phd_timeoff.att_extensions      TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_allowed_ips     TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON phd_timeoff.att_settings        TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON phd_timeoff.att_reports         TO 'attendance_app'@'localhost';
GRANT SELECT, INSERT                 ON phd_timeoff.att_frame_hashes    TO 'attendance_app'@'localhost';

FLUSH PRIVILEGES;

-- Verify append-only enforcement (both must fail with ERROR 1142 / 45000):
--   mysql -u attendance_app -p phd_timeoff -e "UPDATE att_records SET version=version"
--   mysql -u attendance_app -p phd_timeoff -e "DELETE FROM att_audit LIMIT 1"
