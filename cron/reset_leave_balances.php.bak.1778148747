<?php
/**
 * Cron Job: Reset Leave Balances
 * Runs on January 1st and July 1st at midnight
 *
 * Crontab entry:
 * 0 0 1 1,7 * /usr/bin/php /srv/www/phdtimeoff/cron/reset_leave_balances.php
 */

// Set execution time limit
set_time_limit(300); // 5 minutes

// Include required files
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Log directory
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
$logFile = $logDir . '/cron_reset_balances_' . date('Y-m-d') . '.log';

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("===== Starting Leave Balance Reset =====");

try {
    $db = getDBConnection();
    $leaveClass = new Leave($db);
    $userClass = new User($db);
    $emailService = new EmailService();

    // Check if today is reset day (Jan 1 or Jul 1)
    $today = date('m-d');
    if ($today !== '01-01' && $today !== '07-01') {
        cronLog("Not a reset day. Today is: " . date('Y-m-d'));
        cronLog("Reset days are: January 1st and July 1st only");
        exit(0);
    }

    cronLog("Reset day confirmed: " . date('F j, Y'));

    // Idempotency check: prevent double-run on same day
    $resetDate = date('Y-m-d');
    $stmt = $db->prepare("SELECT id FROM system_logs
                          WHERE action = 'cron_leave_reset'
                          AND description LIKE :pattern
                          AND created_at >= :today_start");
    $stmt->execute([
        ':pattern' => "Leave balances reset%",
        ':today_start' => $resetDate . ' 00:00:00'
    ]);
    if ($stmt->fetch()) {
        cronLog("Leave balances already reset today. Exiting to prevent double allocation.");
        exit(0);
    }

    // Perform reset
    $result = $leaveClass->resetBalances();

    if ($result) {
        cronLog("Leave balances reset successfully!");

        // Get all active students
        $students = $userClass->getAllStudents(true);
        cronLog("Found " . count($students) . " active students");

        // Calculate eligibility cutoff date to match Leave::resetBalances() logic
        $eligibilityCutoff = new DateTime();
        $eligibilityCutoff->modify('-' . (int)ACADEMIC_LEAVE_ELIGIBILITY_MONTHS . ' months');
        $eligibilityCutoffStr = $eligibilityCutoff->format('Y-m-d');

        // Send notification emails
        $emailsSent = 0;
        $emailsFailed = 0;

        foreach ($students as $student) {
            try {
                $joiningDate = $student['joining_date'] ?? null;
                $academicEligible = $joiningDate && $joiningDate <= $eligibilityCutoffStr;

                if ($emailService->sendBalanceReset($student, $academicEligible)) {
                    $emailsSent++;
                } else {
                    $emailsFailed++;
                    cronLog("Failed to send email to: " . $student['email']);
                }

                // Small delay to avoid overwhelming SMTP server
                usleep(100000); // 0.1 second

            } catch (Exception $e) {
                $emailsFailed++;
                cronLog("Email error for " . $student['email'] . ": " . $e->getMessage());
            }
        }

        cronLog("Emails sent: $emailsSent, Failed: $emailsFailed");

        // Log to system_logs table
        $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                              VALUES (1, 'cron_leave_reset', 'cron', :description)");
        $stmt->execute([
            ':description' => "Leave balances reset. Emails sent: $emailsSent, Failed: $emailsFailed"
        ]);

        cronLog("===== Leave Balance Reset Completed Successfully =====");

    } else {
        cronLog("ERROR: Leave balance reset failed!");
        exit(1);
    }

} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage());
    cronLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

// Clean up old log files (keep last 30)
$oldLogs = glob($logDir . '/cron_reset_balances_*.log');
if ($oldLogs) {
    sort($oldLogs);
    $toDelete = array_slice($oldLogs, 0, max(0, count($oldLogs) - 30));
    foreach ($toDelete as $file) {
        unlink($file);
    }
}

exit(0);
