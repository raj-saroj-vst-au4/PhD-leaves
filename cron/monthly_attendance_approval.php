<?php
/**
 * Cron Job: open the previous month's attendance for faculty sign-off.
 *
 * Routes every active student's monthly attendance summary through
 *     TA coordinator  ->  faculty guide
 * and emails each faculty member ONE combined report covering all of their
 * students, rather than one message per student.
 *
 * Only the TA coordinators are emailed here — a guide is notified when the TA
 * hands their students on (api/attendance_approvals/process.php), so a guide is
 * never asked to review numbers the TA has not confirmed yet. Students whose TA
 * stage is skipped (no TA assigned) start at the guide, and those guides are
 * emailed by this job too.
 *
 * Idempotent: att_month_approvals has a unique key per (student, month), and a
 * row is emailed only while its notified_at is empty, so a second execution
 * neither duplicates rows nor re-sends email. A manual catch-up run (explicit
 * year and month) for a month already opened therefore emails only the faculty
 * of students opened by THIS run — e.g. one whose guide the office has just
 * assigned — plus any row whose earlier email failed. It used to email every
 * faculty member with anything pending, all over again.
 *
 * Every run first retries any sign-off email that failed earlier, for ANY
 * month (a failed hand-off, a failed re-route notice, a failed first email) —
 * even when this run's own month is already open and there is nothing else to
 * do. Before, only a manual run for that same month would ever look at them.
 *
 * Dates are IST: config/constants.php sets the timezone for the whole app, so
 * "previous month" and "not over yet" below agree with the cron schedule and
 * with the attendance backend's today_ist().
 *
 * Crontab (daily; on the 1st it runs after the attendance report at 08:00):
 *   30 8 * * * /usr/bin/php /var/www/html/phdtimeoff/cron/monthly_attendance_approval.php
 * Daily keeps failed-email retries prompt: on any day but the 1st the month is
 * already open, so a run only retries.
 */

/*
 * CLI only.
 *
 * These scripts live inside the document root, so without this an
 * unauthenticated HTTP GET could run them: opening a sign-off month early,
 * mass-emailing faculty, resetting balances, and echoing recipient names and
 * addresses into the response body (cronLog also echoes). The web server is the
 * wrong place to enforce it — production runs nginx, which ignores .htaccess —
 * so the guard belongs in the script itself.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_time_limit(900);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../classes/AttendanceApproval.php';
require_once __DIR__ . '/../config/attendance.php';

// ------------------------------------------------------------------ logging ---
// LOG_DIR is outside the web root. The old logs/ directory is inside it, and
// nginx serves any non-.php file there as static bytes.
$logDir = rtrim(LOG_DIR, '/');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
if (!@is_writable($logDir)) {
    $logDir = sys_get_temp_dir();
}
$logFile = $logDir . '/cron_attendance_approval_' . date('Y-m-d') . '.log';

function cronLog($message)
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] $message\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

/** Log what a notifyUnnotified() pass did — by user id, not name/address:
 *  the log must not become a faculty directory. Ids are enough to trace a
 *  delivery. */
function logNotify(array $notify, $prefix = '')
{
    foreach ($notify['emailed'] as $e) {
        cronLog(sprintf('  %semailed user_id=%d — %d student(s) [%s]',
            $prefix, $e['faculty_id'], $e['count'], strtoupper($e['role'])));
    }
    foreach ($notify['failed'] as $f) {
        cronLog(sprintf('  %sWARNING: email failed for user_id=%d [%s] — will be retried on the next run',
            $prefix, $f['faculty_id'], $f['role']));
    }
    foreach ($notify['inactive'] as $i) {
        cronLog(sprintf('  %sWARNING: %d record(s) wait on user_id=%d [%s], who is deactivated — '
            . 'reassign the student\'s guide/TA in Users', $prefix, $i['count'], $i['faculty_id'],
            strtoupper($i['role'])));
    }
}

/** Ask the attendance backend for the month's computed figures — the same
 *  report the stipend is paid against. The fetch (and the system ticket it
 *  carries) lives in AttendanceApproval, which also uses it to keep pending
 *  sign-offs' figures current. */
function fetchMonthReport($year, $month)
{
    return AttendanceApproval::fetchBackendReport($year, $month, 300, 'cron:attendance-approval');
}

// --------------------------------------------------------------------- run ---
cronLog('=== Monthly attendance approval: start ===');

try {
    // Previous calendar month. Anchored to the 1st so a run on the 31st cannot
    // land on a month with fewer days (the classic "-1 month" date trap).
    $anchor = new DateTime('first day of this month');
    $period = (clone $anchor)->modify('-1 month');
    $year = (int) $period->format('Y');
    $month = (int) $period->format('n');
    $label = $period->format('F Y');

    // Allow an explicit period for a manual catch-up run:
    //   php monthly_attendance_approval.php 2026 7
    if ($argc >= 3) {
        $year = (int) $argv[1];
        $month = (int) $argv[2];
        if ($month < 1 || $month > 12) {
            cronLog("ERROR: month must be 1-12, got $month");
            exit(1);
        }
        if ($year < 2000 || $year > 2100) {
            cronLog("ERROR: implausible year '$year'");
            exit(1);
        }
        // Refuse a month that has not finished. The report only counts a day as
        // absent once it has passed, so opening the current month would put
        // partial figures in front of faculty — every student near 100% on a
        // full-month denominator — and invite them to sign those.
        // "This month" is the IST month (the app timezone): no UTC offset
        // arithmetic is needed or wanted here — just after midnight IST on the
        // 1st, UTC is still on the previous month's last day, and the old
        // UTC-default PHP would have refused the month that had just ended.
        $firstOfThisMonth = (new DateTime('first day of this month'))->format('Y-m-01');
        $requested = sprintf('%04d-%02d-01', $year, $month);
        if ($requested >= $firstOfThisMonth) {
            cronLog("ERROR: $year-" . sprintf('%02d', $month) . ' is not over yet. '
                  . 'Sign-off can only be opened for a completed month.');
            exit(1);
        }
        $label = DateTime::createFromFormat('!Y-n-j', "$year-$month-1")->format('F Y');
        cronLog("Manual period override: $label");
    }

    cronLog("Period: $label ($year-" . sprintf('%02d', $month) . ')');

    $db = getDBConnection();
    $approval = new AttendanceApproval($db);

    // 0. Retry earlier notifications that never went out, for every month —
    //    before the already-run exit below, or they would wait forever: the
    //    scheduled run only ever opens the previous month.
    foreach ($approval->periodsWithUnnotified() as [$py, $pm]) {
        $retry = $approval->notifyUnnotified($py, $pm);
        if ($retry['emailed'] || $retry['failed'] || $retry['inactive']) {
            cronLog(sprintf('Retrying un-notified sign-offs for %04d-%02d:', $py, $pm));
            logNotify($retry);
        }
    }

    $previous = $approval->alreadyRun($year, $month);
    if ($previous && $argc < 3) {
        cronLog('Already opened on ' . $previous['created_at'] . ' — nothing to do. '
              . '(Pass a year and month to force a catch-up run.)');
        cronLog('=== done ===');
        exit(0);
    }

    // 1. The figures, from the single source of truth.
    $report = fetchMonthReport($year, $month);
    cronLog(sprintf('Report: %d students, %d working days',
        count($report['rows']), (int) $report['working_days']));

    if ((int) $report['working_days'] === 0) {
        cronLog('No working days in this period — nothing to approve.');
        $approval->recordRun($year, $month, 0, 0, 0, $argc >= 3 ? 'manual' : 'cron');
        cronLog('=== done ===');
        exit(0);
    }

    // 2. Open the month (one row per student, routed to TA then guide).
    $opened = $approval->openMonth($year, $month, $report['rows']);
    cronLog("Opened {$opened['created']} student row(s); skipped {$opened['skipped']} "
          . '(already open, or not routable)');
    // Say WHICH students were left out and why, so the office can act rather
    // than wonder why a name never reached a faculty member.
    if (!empty($opened['unroutable'])) {
        cronLog('  NOT routed — no faculty guide AND no TA coordinator assigned: student_id '
              . implode(', ', $opened['unroutable'])
              . ' (assign one in Users, then re-run with an explicit period)');
    }
    if (!empty($opened['not_yet_joined'])) {
        cronLog('  skipped — joined after this period ended: student_id '
              . implode(', ', $opened['not_yet_joined']));
    }

    // 3. One combined email per faculty member with rows nobody has told them
    //    about yet. On the first run that is everyone with work waiting; on a
    //    catch-up run it is only the students this run opened (and any row
    //    whose earlier email failed) — not the whole month over again.
    $notify = $approval->notifyUnnotified($year, $month);
    $sent = $notify['sent'];
    logNotify($notify);
    if (!$notify['emailed'] && !$notify['failed'] && !$notify['inactive']) {
        cronLog('  nobody new to notify');
    }
    cronLog("Emails sent — TA: {$sent['ta']}, guide (TA-skipped students): {$sent['guide']}");

    $approval->recordRun($year, $month, $opened['created'], $sent['ta'], $sent['guide'],
        $argc >= 3 ? 'manual' : 'cron');

    logSystemAction(null, 'attendance_approval_opened', 'attendance',
        sprintf('Opened %s for sign-off: %d students, %d TA + %d guide emails',
            $label, $opened['created'], $sent['ta'], $sent['guide']));

    cronLog('=== done ===');
    exit(0);
} catch (Exception $e) {
    cronLog('ERROR: ' . $e->getMessage());
    logError('Monthly attendance approval cron failed: ' . $e->getMessage());
    exit(1);
}
