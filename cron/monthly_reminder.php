<?php
/**
 * Cron Job: Monthly Attendance Reminder
 * Sends reminder to all active students on 2nd last working day of month
 *
 * Crontab entry:
 * 0 9 25-31 * * /usr/bin/php /srv/www/phdtimeoff/cron/monthly_reminder.php
 */

set_time_limit(300);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Holiday.php';
require_once __DIR__ . '/../includes/EmailService.php';

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
if (!is_writable($logDir)) {
    @chmod($logDir, 0775);
    if (!is_writable($logDir)) {
        $logDir = sys_get_temp_dir();
        echo "[WARNING] logs/ directory not writable, logging to $logDir\n";
    }
}
$logFile = $logDir . '/cron_reminder_' . date('Y-m-d') . '.log';

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("===== Starting Monthly Reminder Check =====");

try {
    $db = getDBConnection();
    $userClass = new User($db);
    $holidayClass = new Holiday($db);
    $emailService = new EmailService();

    // Calculate 2nd last working day of current month
    $today = new DateTime();
    $currentMonth = $today->format('m');
    $currentYear = $today->format('Y');

    // Get last day and first day of month (first day as safety bound)
    $lastDayOfMonth = new DateTime("last day of $currentYear-$currentMonth");
    $firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01");

    // Get holidays for the month
    $holidays = $holidayClass->getByYear($currentYear);
    $holidayDates = array_map(function($h) {
        return $h['holiday_date'];
    }, $holidays);

    // Count back to find 2nd last working day
    $workingDaysFound = 0;
    $checkDate = clone $lastDayOfMonth;
    $secondLastWorkingDay = null;

    while ($checkDate >= $firstDayOfMonth && $workingDaysFound < 2) {
        $dayOfWeek = (int)$checkDate->format('N'); // 1 (Mon) to 7 (Sun)
        $dateStr = $checkDate->format('Y-m-d');

        // Check if it's a working day (not weekend and not holiday)
        if ($dayOfWeek < 6 && !in_array($dateStr, $holidayDates)) {
            $workingDaysFound++;
            if ($workingDaysFound === 2) {
                $secondLastWorkingDay = clone $checkDate;
                break;
            }
        }

        $checkDate->modify('-1 day');
    }

    if (!$secondLastWorkingDay) {
        cronLog("ERROR: Could not find 2nd last working day (fewer than 2 working days in month). Exiting.");
        exit(1);
    }

    cronLog("2nd last working day of month: " . $secondLastWorkingDay->format('Y-m-d'));
    cronLog("Today: " . $today->format('Y-m-d'));

    // Check if today is the 2nd last working day
    if ($today->format('Y-m-d') !== $secondLastWorkingDay->format('Y-m-d')) {
        cronLog("Not the 2nd last working day. Exiting.");
        exit(0);
    }

    cronLog("It's the 2nd last working day! Sending reminders...");

    // Check if reminder already sent this month
    $stmt = $db->prepare("SELECT * FROM monthly_reminders WHERE month = :month AND year = :year");
    $stmt->execute([':month' => $currentMonth, ':year' => $currentYear]);

    if ($stmt->fetch()) {
        cronLog("Reminder already sent for this month. Exiting.");
        exit(0);
    }

    // Get all active students
    $students = $userClass->getAllStudents(true);
    cronLog("Found " . count($students) . " active students");

    // sendMonthlyReminder handles the loop internally and returns counts
    $result = $emailService->sendMonthlyReminder($students);
    $emailsSent = is_array($result) ? ($result['success'] ?? 0) : (int)$result;
    $emailsFailed = is_array($result) ? ($result['failed'] ?? 0) : 0;

    // Log reminder sent
    $stmt = $db->prepare("INSERT INTO monthly_reminders (month, year, total_students)
                          VALUES (:month, :year, :total)");
    $stmt->execute([
        ':month' => $currentMonth,
        ':year' => $currentYear,
        ':total' => count($students)
    ]);

    // Log to system_logs
    $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                          VALUES (NULL, 'cron_monthly_reminder', 'cron', :description)");
    $stmt->execute([
        ':description' => "Monthly reminders sent. Success: $emailsSent, Failed: $emailsFailed"
    ]);

    cronLog("Reminder emails sent: $emailsSent, Failed: $emailsFailed");

    // Clean up old log files (keep last 90 days)
    $oldLogs = glob($logDir . '/cron_reminder_*.log');
    if ($oldLogs) {
        sort($oldLogs);
        $toDelete = array_slice($oldLogs, 0, max(0, count($oldLogs) - 90));
        foreach ($toDelete as $file) {
            unlink($file);
            cronLog("Cleaned up old log: " . basename($file));
        }
    }

    cronLog("===== Monthly Reminder Completed =====");

} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage());
    cronLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

exit(0);
