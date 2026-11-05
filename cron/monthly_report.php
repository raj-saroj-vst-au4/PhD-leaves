<?php
/**
 * Cron Job: Monthly Report
 * Generates and emails monthly attendance report to admins
 * Runs on last working day of month
 *
 * Crontab entry (the script itself decides whether today is the day, so the
 * window only has to contain the last working day of every month):
 * 0 17 20-31 * * /usr/bin/php /var/www/html/phdtimeoff/cron/monthly_report.php
 *
 * To (re)send the report for a month that has ended:
 *   php cron/monthly_report.php --month=2026-10
 * That skips the last-working-day check and reports on the leave as it stands
 * now. An existing CSV for that month is kept as the record of the original run.
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

set_time_limit(600);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Holiday.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Logs and the report CSV go to the private log directory. They used to be
// written to logs/ inside the webroot, which production nginx serves as static
// files: the CSV (every student's leaves and balances) was downloadable.
$logDir = rtrim(LOG_DIR, '/');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
// Test actual file creation since is_writable() can be unreliable
$testFile = $logDir . '/.write_test_' . getmypid();
$canWrite = @file_put_contents($testFile, '') !== false;
if ($canWrite) {
    @unlink($testFile);
} else {
    @chmod($logDir, 0775);
    $canWrite = @file_put_contents($testFile, '') !== false;
    if ($canWrite) {
        @unlink($testFile);
    } else {
        $logDir = sys_get_temp_dir();
        echo "[WARNING] " . LOG_DIR . " not writable, logging to $logDir\n";
    }
}
$logFile = $logDir . '/cron_report_' . date('Y-m-d') . '.log';

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("===== Starting Monthly Report Generation =====");

try {
    $db = getDBConnection();
    $userClass = new User($db);
    $leaveClass = new Leave($db);
    $holidayClass = new Holiday($db);
    $emailService = new EmailService();

    $today = new DateTime();

    // --month=YYYY-MM: a resend for a month that has ended. $today becomes that
    // month's last day, so every range and label below is for that month.
    $resendMonth = null;
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (preg_match('/^--month=(\d{4}-(0[1-9]|1[0-2]))$/', $arg, $m)) {
            $resendMonth = $m[1];
        } else {
            cronLog("ERROR: unknown argument '$arg' (expected --month=YYYY-MM). Exiting.");
            exit(1);
        }
    }
    if ($resendMonth !== null) {
        $today = new DateTime($resendMonth . '-01');
        $today->modify('last day of this month');
        if ($today->format('Y-m-d') >= (new DateTime())->format('Y-m-d')) {
            cronLog("ERROR: $resendMonth has not ended yet; the scheduled run sends it. Exiting.");
            exit(1);
        }
        cronLog("Resending the report for " . $today->format('F Y') . " (--month)");
    }

    // Last working day of the month, by Holiday's calendar: Mon-Fri, minus
    // holidays, plus any weekend day the holidays table declares working. The
    // old loop put every holidays row in one "holiday" list and never read
    // is_working_day, so a Saturday declared working was still a day off.
    $workingDays = $holidayClass->getWorkingDaysInRange($today->format('Y-m-01'), $today->format('Y-m-t'));
    $lastWorkingDay = empty($workingDays) ? null : new DateTime(end($workingDays));

    if (!$lastWorkingDay) {
        cronLog("ERROR: No working day found in this month. Exiting.");
        exit(1);
    }

    cronLog("Last working day of month: " . $lastWorkingDay->format('Y-m-d'));
    cronLog("Today: " . $today->format('Y-m-d'));

    if ($resendMonth === null && $today->format('Y-m-d') !== $lastWorkingDay->format('Y-m-d')) {
        cronLog("Not the last working day. Exiting.");
        exit(0);
    }

    cronLog("Generating monthly report for " . $today->format('F Y'));

    // Generate report data
    $reportData = [];

    // Month date range
    $startDate = $today->format('Y-m-01');
    $endDate = $today->format('Y-m-t');

    // Every leave application that touches the month. The old filter
    // (start_date >= 1st AND end_date <= last day) matched only leaves lying
    // wholly inside the month, so a leave running from the 28th into the next
    // month appeared in neither month's report.
    $leaves = $leaveClass->getAll([
        'overlaps_from' => $startDate,
        'overlaps_to' => $endDate
    ]);

    // 'combined' rows are superseded duplicates: combineLeaves() inserts a new
    // application spanning the same dates, so counting both doubles the totals.
    $leaves = array_values(array_filter($leaves, fn($l) => $l['status'] !== 'combined'));

    // Days of each leave that fall inside this month (total_days counts
    // calendar days, like this).
    $daysInMonth = function ($leave) use ($startDate, $endDate) {
        $from = max($leave['start_date'], $startDate);
        $to = min($leave['end_date'], $endDate);
        return (new DateTime($from))->diff(new DateTime($to))->days + 1;
    };

    $approvedLeaves = array_filter($leaves, fn($l) => $l['status'] === 'approved');
    $rejectedLeaves = array_filter($leaves, fn($l) => $l['status'] === 'rejected');
    $pendingLeaves = array_filter($leaves, fn($l) => in_array($l['status'], Leave::PENDING_STATUSES));

    // Keys matching what sendMonthlyReport() expects
    $reportData['month'] = $today->format('F Y');
    $reportData['total_applications'] = count($leaves);
    $reportData['approved_applications'] = count($approvedLeaves);
    $reportData['rejected_applications'] = count($rejectedLeaves);
    $reportData['pending_applications'] = count($pendingLeaves);

    // Breakdown by leave type — keys matching sendMonthlyReport() placeholders
    $reportData['personal_leaves'] = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'personal'));
    $reportData['medical_leaves'] = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'medical'));
    $reportData['academic_leaves'] = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'academic'));
    // "Extended" = the leaves that need Dean AP approval: academic leave longer
    // than EXTENDED_LEAVE_THRESHOLD days, the same rule Approval uses to route
    // to pending_admin. It used to test leave_type === 'extended', a type that
    // does not exist, so the count was always 0.
    $reportData['extended_leaves'] = count(array_filter($leaves, fn($l) =>
        $l['leave_type'] === 'academic' && (int) $l['total_days'] > EXTENDED_LEAVE_THRESHOLD));

    // Students with negative balances
    $stmt = $db->query("SELECT s.roll_number, u.name, lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
                        FROM leave_balances lb
                        JOIN students s ON lb.student_id = s.id
                        JOIN users u ON s.user_id = u.id
                        WHERE u.is_active = 1 AND (lb.personal_leaves < 0 OR lb.medical_leaves < 0 OR lb.academic_leaves < 0)
                        ORDER BY u.name");
    $negativeBalances = $stmt->fetchAll();

    // Approved leave days taken in this month (a leave spanning months counts
    // only its days inside this one)
    $totalDays = array_sum(array_map($daysInMonth, $approvedLeaves));

    // Generate CSV report
    $csvFile = $logDir . '/monthly_report_' . $today->format('Y-m') . '.csv';
    $keepOriginal = $resendMonth !== null && file_exists($csvFile);
    $fp = $keepOriginal ? false : @fopen($csvFile, 'w');

    if ($fp) {
        fputcsv($fp, ['Monthly Leave Report - ' . $today->format('F Y')]);
        fputcsv($fp, []);
        fputcsv($fp, ['Summary']);
        fputcsv($fp, ['Total Applications', $reportData['total_applications']]);
        fputcsv($fp, ['Approved', $reportData['approved_applications']]);
        fputcsv($fp, ['Rejected', $reportData['rejected_applications']]);
        fputcsv($fp, ['Pending', $reportData['pending_applications']]);
        fputcsv($fp, ['Approved Leave Days in ' . $today->format('F'), $totalDays]);
        fputcsv($fp, []);

        fputcsv($fp, ['By Leave Type']);
        fputcsv($fp, ['Personal', $reportData['personal_leaves']]);
        fputcsv($fp, ['Medical', $reportData['medical_leaves']]);
        fputcsv($fp, ['Academic', $reportData['academic_leaves']]);
        fputcsv($fp, ['Extended academic (> ' . EXTENDED_LEAVE_THRESHOLD . ' days, Dean AP)', $reportData['extended_leaves']]);
        fputcsv($fp, []);

        fputcsv($fp, ['All Leave Applications']);
        fputcsv($fp, ['Roll Number', 'Student Name', 'Leave Type', 'Start Date', 'End Date', 'Days',
                      'Days in ' . $today->format('F'), 'Status']);

        foreach ($leaves as $leave) {
            fputcsv($fp, [
                $leave['roll_number'],
                $leave['student_name'],
                ucfirst($leave['leave_type']),
                $leave['start_date'],
                $leave['end_date'],
                $leave['total_days'],
                $daysInMonth($leave),
                str_replace('_', ' ', ucfirst($leave['status']))
            ]);
        }

        fputcsv($fp, []);
        fputcsv($fp, ['Students with Negative Balances']);
        if (!empty($negativeBalances)) {
            fputcsv($fp, ['Roll Number', 'Name', 'Personal', 'Medical', 'Academic']);
            foreach ($negativeBalances as $student) {
                fputcsv($fp, [
                    $student['roll_number'],
                    $student['name'],
                    $student['personal_leaves'],
                    $student['medical_leaves'],
                    $student['academic_leaves']
                ]);
            }
        } else {
            fputcsv($fp, ['No students with negative balances']);
        }

        fclose($fp);
        @chmod($csvFile, 0640);
        cronLog("CSV report generated: $csvFile");

        // Clean up old CSV reports (keep last 12 months)
        cleanupOldFiles($logDir . '/', 'monthly_report_*.csv', 12);
    } elseif ($keepOriginal) {
        cronLog("Kept the existing CSV from the original run: $csvFile");
    } else {
        cronLog("WARNING: Could not write CSV report to $csvFile (permission denied). Skipping CSV generation.");
    }

    // Clean up old log files (keep last 90 days)
    cleanupOldFiles($logDir . '/', 'cron_report_*.log', 90);

    // Send report to all admins
    $admins = $userClass->getAllByRole('admin', true);
    cronLog("Found " . count($admins) . " admins");

    $result = $emailService->sendMonthlyReport($admins, $reportData);
    $emailsSent = $result ? count($admins) : 0;
    $emailsFailed = $result ? 0 : count($admins);

    // Log to system
    $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                          VALUES (NULL, 'cron_monthly_report', 'cron', :description)");
    $stmt->execute([
        ':description' => "Monthly report " . ($resendMonth !== null ? 're-sent' : 'generated') . " for " . $today->format('F Y') . ". Sent to $emailsSent admins. Failed: $emailsFailed"
    ]);

    cronLog("Report emails sent: $emailsSent, Failed: $emailsFailed");
    cronLog("===== Monthly Report Completed =====");

} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage());
    cronLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

/**
 * Remove old files matching a glob pattern, keeping the most recent $keep files
 */
function cleanupOldFiles($dir, $pattern, $keep) {
    $files = glob($dir . $pattern);
    if ($files === false) return;
    sort($files);
    $toDelete = array_slice($files, 0, max(0, count($files) - $keep));
    foreach ($toDelete as $file) {
        unlink($file);
        cronLog("Cleaned up old file: " . basename($file));
    }
}

exit(0);
