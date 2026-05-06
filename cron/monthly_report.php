<?php
/**
 * Cron Job: Monthly Report
 * Generates and emails monthly attendance report to admins
 * Runs on last working day of month
 *
 * Crontab entry:
 * 0 17 25-31 * * /usr/bin/php /srv/www/phdtimeoff/cron/monthly_report.php
 */

set_time_limit(600);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Holiday.php';
require_once __DIR__ . '/../includes/EmailService.php';

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
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
        echo "[WARNING] logs/ directory not writable, logging to $logDir\n";
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
    $currentMonth = $today->format('m');
    $currentYear = $today->format('Y');

    // Calculate last working day with a safety bound to prevent infinite loop
    $lastDayOfMonth = new DateTime("last day of $currentYear-$currentMonth");
    $firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01");
    $holidays = $holidayClass->getByYear($currentYear);
    $holidayDates = array_map(function($h) { return $h['holiday_date']; }, $holidays);

    $checkDate = clone $lastDayOfMonth;
    $lastWorkingDay = null;

    while ($checkDate >= $firstDayOfMonth) {
        $dayOfWeek = (int)$checkDate->format('N');
        $dateStr = $checkDate->format('Y-m-d');

        if ($dayOfWeek < 6 && !in_array($dateStr, $holidayDates)) {
            $lastWorkingDay = clone $checkDate;
            break;
        }
        $checkDate->modify('-1 day');
    }

    if (!$lastWorkingDay) {
        cronLog("ERROR: No working day found in this month. Exiting.");
        exit(1);
    }

    cronLog("Last working day of month: " . $lastWorkingDay->format('Y-m-d'));
    cronLog("Today: " . $today->format('Y-m-d'));

    if ($today->format('Y-m-d') !== $lastWorkingDay->format('Y-m-d')) {
        cronLog("Not the last working day. Exiting.");
        exit(0);
    }

    cronLog("Generating monthly report for " . $today->format('F Y'));

    // Generate report data
    $reportData = [];

    // Month date range
    $startDate = $today->format('Y-m-01');
    $endDate = $today->format('Y-m-t');

    // Get all leave applications for the month
    $leaves = $leaveClass->getAll([
        'start_date' => $startDate,
        'end_date' => $endDate
    ]);

    $approvedLeaves = array_filter($leaves, fn($l) => $l['status'] === 'approved');
    $rejectedLeaves = array_filter($leaves, fn($l) => $l['status'] === 'rejected');
    $pendingLeaves = array_filter($leaves, fn($l) => in_array($l['status'], ['pending_guide', 'pending_ta', 'pending_hod', 'pending_admin']));

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
    $reportData['extended_leaves'] = count(array_filter($leaves, fn($l) => ($l['leave_type'] ?? '') === 'extended'));

    // Students with negative balances
    $stmt = $db->query("SELECT s.roll_number, u.name, lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
                        FROM leave_balances lb
                        JOIN students s ON lb.student_id = s.id
                        JOIN users u ON s.user_id = u.id
                        WHERE u.is_active = 1 AND (lb.personal_leaves < 0 OR lb.medical_leaves < 0 OR lb.academic_leaves < 0)
                        ORDER BY u.name");
    $negativeBalances = $stmt->fetchAll();

    // Total leave days taken
    $totalDays = array_sum(array_map(fn($l) => $l['total_days'], $approvedLeaves));

    // Generate CSV report
    $csvFile = $logDir . '/monthly_report_' . date('Y-m') . '.csv';
    $fp = @fopen($csvFile, 'w');

    if ($fp) {
        fputcsv($fp, ['Monthly Leave Report - ' . $today->format('F Y')]);
        fputcsv($fp, []);
        fputcsv($fp, ['Summary']);
        fputcsv($fp, ['Total Applications', $reportData['total_applications']]);
        fputcsv($fp, ['Approved', $reportData['approved_applications']]);
        fputcsv($fp, ['Rejected', $reportData['rejected_applications']]);
        fputcsv($fp, ['Pending', $reportData['pending_applications']]);
        fputcsv($fp, ['Total Leave Days', $totalDays]);
        fputcsv($fp, []);

        fputcsv($fp, ['By Leave Type']);
        fputcsv($fp, ['Personal', $reportData['personal_leaves']]);
        fputcsv($fp, ['Medical', $reportData['medical_leaves']]);
        fputcsv($fp, ['Academic', $reportData['academic_leaves']]);
        fputcsv($fp, []);

        fputcsv($fp, ['All Leave Applications']);
        fputcsv($fp, ['Roll Number', 'Student Name', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Status']);

        foreach ($leaves as $leave) {
            fputcsv($fp, [
                $leave['roll_number'],
                $leave['student_name'],
                ucfirst($leave['leave_type']),
                $leave['start_date'],
                $leave['end_date'],
                $leave['total_days'],
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
        cronLog("CSV report generated: $csvFile");

        // Clean up old CSV reports (keep last 12 months)
        cleanupOldFiles($logDir . '/', 'monthly_report_*.csv', 12);
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
        ':description' => "Monthly report generated for " . $today->format('F Y') . ". Sent to $emailsSent admins. Failed: $emailsFailed"
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
