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
 * Idempotent: att_month_approvals has a unique key per (student, month) and the
 * run is recorded in att_month_approval_runs, so a second execution neither
 * duplicates rows nor re-sends email.
 *
 * Crontab (run after the attendance report is generated at 08:00 on the 1st):
 *   30 8 1 * * /usr/bin/php /srv/www/phdtimeoff/cron/monthly_attendance_approval.php
 */

set_time_limit(900);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../classes/AttendanceApproval.php';
require_once __DIR__ . '/../config/attendance.php';

// ------------------------------------------------------------------ logging ---
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
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

/**
 * A short-lived admin ticket for this process.
 *
 * att_ticket() needs a logged-in web session, which a CLI job has not got. The
 * payload format is identical (see config/attendance.php) so the backend's
 * verify_ticket accepts it; uid 0 marks it as the system rather than a person.
 * The call never leaves loopback.
 */
function att_cron_ticket()
{
    $payload = [
        'uid'   => 0,
        'role'  => 'admin',
        'sid'   => null,
        'name'  => 'cron:attendance-approval',
        'email' => ADMIN_EMAIL,
        'exp'   => time() + 300,
    ];
    $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, ATT_SHARED_SECRET, true)), '+/', '-_'), '=');
    return $body . '.' . $sig;
}

/** Ask the attendance backend for the month's computed figures.
 *
 * Deliberately not recomputed in PHP: working days, and "excused = approved
 * leave OR office-excused", already live in the Python report module. Two
 * implementations would eventually disagree, and the sign-off has to match the
 * report the stipend is paid against.
 */
function fetchMonthReport($year, $month)
{
    $url = rtrim(ATT_BACKEND_URL, '/') . '/api/admin/report/preview?year=' . (int) $year
         . '&month=' . (int) $month;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,   // a full month of frames is not quick
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . att_cron_ticket()],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        throw new Exception("attendance backend returned HTTP $status" . ($err ? " ($err)" : ''));
    }
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['rows'])) {
        throw new Exception('attendance backend returned no student rows');
    }
    return $data;
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
        $label = DateTime::createFromFormat('!Y-n-j', "$year-$month-1")->format('F Y');
        cronLog("Manual period override: $label");
    }

    cronLog("Period: $label ($year-" . sprintf('%02d', $month) . ')');

    $db = getDBConnection();
    $approval = new AttendanceApproval($db);
    $emailService = new EmailService();

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
          . '(already open, or student not active)');

    // 3. One combined email per faculty member who now has work waiting.
    $sent = ['ta' => 0, 'guide' => 0];
    foreach (['ta', 'guide'] as $role) {
        foreach ($approval->facultyAwaiting($year, $month, $role) as $faculty) {
            $rows = $approval->rowsForFacultyMonth($faculty['id'], $role, $year, $month);
            if (!$rows) {
                continue;
            }
            $ok = $emailService->sendAttendanceApprovalRequest($faculty, $role, $label, $rows);
            if ($ok) {
                $sent[$role]++;
                cronLog(sprintf('  emailed %s (%s) — %d student(s) [%s]',
                    $faculty['name'], $faculty['email'], count($rows), strtoupper($role)));
            } else {
                cronLog('  WARNING: email failed for ' . $faculty['email'] . ' [' . $role . ']');
            }
        }
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
