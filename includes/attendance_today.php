<?php
/**
 * A student's attendance situation for today, read straight from the shared
 * database.
 *
 * Mirrors the attendance backend's own rules — attendance/backend/app/
 * timewindow.py resolve_window() for the calendar (date override, then the
 * holidays table, then the per-weekday default; extensions stretch the end of
 * an open window) and marking.check_not_already_marked() for "marked" (the
 * latest record for the day, unless it is a void). It only decides where to
 * land a student after sign-in and what the dashboard highlights; the backend
 * still enforces every gate when they actually mark.
 *
 * Returns null when attendance is switched off, the attendance tables are not
 * there, or anything fails — callers then behave exactly as before.
 *
 * @return array{working_day: bool, open_now: bool, closed_for_today: bool,
 *               start: string, end: string, label: ?string, marked: bool,
 *               marked_at: ?string, on_leave: bool, enrolled: bool, due: bool}|null
 */
function attendanceToday($studentId) {
    if (!$studentId) {
        return null;
    }
    try {
        $db = getDBConnection();
        $one = function ($sql, array $params = []) use ($db) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        };

        $enabled = $one("SELECT svalue FROM att_settings WHERE skey = 'attendance_enabled'");
        if ($enabled && (string) $enabled['svalue'] === '0') {
            return null;
        }

        $today = date('Y-m-d');                       // IST: config/constants.php
        $nowMinute = (int) date('G') * 60 + (int) date('i');

        // 3. per-weekday default
        $win = $one("SELECT is_working_day, start_minute, end_minute FROM att_windows WHERE weekday = ?",
            [(int) date('N')]) ?: ['is_working_day' => 1, 'start_minute' => 540, 'end_minute' => 630];
        $working = (bool) $win['is_working_day'];
        $start = (int) $win['start_minute'];
        $end = (int) $win['end_minute'];
        $label = null;

        // 2. the leave system's holiday calendar
        $holiday = $one("SELECT holiday_name, is_working_day FROM holidays WHERE holiday_date = ?
                         ORDER BY id DESC LIMIT 1", [$today]);
        if ($holiday) {
            $working = (bool) $holiday['is_working_day'];
            $label = $holiday['holiday_name'];
        }

        // 1. the office's explicit per-date decision wins
        $override = $one("SELECT is_working_day, start_minute, end_minute, label FROM att_date_overrides
                          WHERE date_ist = ?", [$today]);
        if ($override) {
            if ($override['is_working_day'] !== null) {
                $working = (bool) $override['is_working_day'];
            }
            if ($override['start_minute'] !== null) {
                $start = (int) $override['start_minute'];
            }
            if ($override['end_minute'] !== null) {
                $end = (int) $override['end_minute'];
            }
            $label = $override['label'] ?: $label;
        }

        if ($working) {
            $ext = $one("SELECT COALESCE(SUM(extra_minutes), 0) AS mins FROM att_extensions WHERE date_ist = ?",
                [$today]);
            $end = min($end + (int) ($ext['mins'] ?? 0), 24 * 60);
        }

        $record = $one("SELECT kind, marked_at_utc FROM att_records WHERE student_id = ? AND date_ist = ?
                        ORDER BY version DESC LIMIT 1", [(int) $studentId, $today]);
        $marked = $record && $record['kind'] !== 'void';
        $markedAt = null;
        if ($marked && !empty($record['marked_at_utc'])) {
            $markedAt = (new DateTime($record['marked_at_utc'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('H:i');
        }

        // Approved leave covering today is excused in the report; nothing to mark.
        $onLeave = (bool) $one("SELECT 1 FROM leave_applications WHERE student_id = ? AND status = 'approved'
                                AND start_date <= ? AND end_date >= ? LIMIT 1",
            [(int) $studentId, $today, $today]);

        $enrolled = (bool) $one("SELECT 1 FROM att_face_templates WHERE student_id = ? AND status = 'active' LIMIT 1",
            [(int) $studentId]);

        $closedForToday = $working && $nowMinute >= $end;
        $fmt = function ($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };

        return [
            'working_day' => $working,
            'open_now' => $working && $nowMinute >= $start && $nowMinute < $end,
            'closed_for_today' => $closedForToday,
            'start' => $fmt($start),
            'end' => $fmt($end),
            'label' => $label,
            'marked' => $marked,
            'marked_at' => $markedAt,
            'on_leave' => $onLeave,
            'enrolled' => $enrolled,
            // Something to do today: a working day, not yet marked, not on leave,
            // and the window has not closed yet (before it opens still counts —
            // the Attendance page shows when it opens).
            'due' => $working && !$marked && !$onLeave && !$closedForToday,
        ];
    } catch (Throwable $e) {
        logError('attendanceToday failed: ' . $e->getMessage(), ['student_id' => (int) $studentId]);
        return null;
    }
}

/**
 * Where a student lands after signing in: the Attendance page while today's
 * attendance is still due (not marked, window not closed) — marking is the
 * first thing they should do — otherwise their dashboard. A student who has
 * not registered their face yet also lands there, on the enrollment steps.
 */
function studentLandingPage($studentId) {
    $today = attendanceToday($studentId);
    if ($today !== null && $today['due']) {
        return BASE_URL . '/student/attendance.php';
    }
    return getRoleHomePage('student');
}
