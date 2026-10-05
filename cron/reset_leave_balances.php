<?php
/**
 * Cron Job: Reset Leave Balances
 * Runs on January 1st and July 1st at midnight
 *
 * Crontab entry:
 * 0 0 1 1,7 * /usr/bin/php /srv/www/phdtimeoff/cron/reset_leave_balances.php
 *
 * Options:
 *   --date=YYYY-MM-DD  catch up a missed run. Must be a 1 January or 1 July, not
 *                      in the future, and the most recent such date.
 *   --dry-run          show each student's balances before/after; change nothing,
 *                      send nothing.
 *   --no-email         apply the reset but do not email the students.
 *
 * Safe to run more than once for the same date: Leave::resetBalances() only
 * touches balances whose last_reset is before that date, and stamps them with
 * it. A second run finds nothing to do and emails nobody.
 *
 * Catching up the missed 1 July 2026 reset (production PHP was on UTC, so the
 * 00:00 IST run saw 30 June and exited as "not a reset day"):
 *   php cron/reset_leave_balances.php --date=2026-07-01 --dry-run
 *   php cron/reset_leave_balances.php --date=2026-07-01
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

// Set execution time limit
set_time_limit(300); // 5 minutes

// Include required files
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Arguments. Anything unrecognised stops the run rather than being ignored: a
// mistyped "--dryrun" must not turn into a real reset.
$dryRun = false;
$sendEmail = true;
$dateArg = null;
$usage = "Usage: php reset_leave_balances.php [--date=YYYY-MM-DD] [--dry-run] [--no-email]\n";
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--no-email') {
        $sendEmail = false;
    } elseif (strpos($arg, '--date=') === 0) {
        $dateArg = substr($arg, strlen('--date='));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo $usage;
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n$usage");
        exit(2);
    }
}

// Log directory: private, never under the webroot's served paths.
$logDir = rtrim(LOG_DIR, '/');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
if (!is_writable($logDir)) {
    $logDir = sys_get_temp_dir();
    echo "[WARNING] " . LOG_DIR . " not writable, logging to $logDir\n";
}
$logFile = $logDir . '/cron_reset_balances_' . date('Y-m-d') . '.log';

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("===== Starting Leave Balance Reset" . ($dryRun ? " (DRY RUN)" : "") . " =====");

try {
    $db = getDBConnection();
    $leaveClass = new Leave($db);

    $today = date('Y-m-d');
    if ($dateArg !== null) {
        // Catch-up run for a missed reset day.
        try {
            Leave::assertResetDate($dateArg, $today);
        } catch (Exception $e) {
            cronLog("Refusing --date=$dateArg: " . $e->getMessage());
            exit(2);
        }
        $resetDate = $dateArg;
        cronLog("Catch-up run for reset date $resetDate (today is $today)");
    } else {
        // Check if today is reset day (Jan 1 or Jul 1)
        if (!in_array(date('m-d'), Leave::RESET_MONTH_DAYS, true)) {
            cronLog("Not a reset day. Today is: $today");
            cronLog("Reset days are: January 1st and July 1st only (use --date=YYYY-MM-DD to catch up a missed one)");
            exit(0);
        }
        $resetDate = $today;
        cronLog("Reset day confirmed: " . date('F j, Y'));
    }

    if ($dryRun) {
        $rows = $leaveClass->previewReset($resetDate);
        cronLog("DRY RUN: nothing will be written and no email sent.");
        cronLog(sprintf("%-12s %-28s %-22s %-22s %s", 'Roll', 'Name', 'Personal', 'Medical', 'Academic'));
        $counts = ['personal' => 0, 'medical' => 0, 'academic' => 0];
        foreach ($rows as $row) {
            $fmt = function ($type) use ($row, &$counts) {
                $before = number_format((float) $row["before_$type"], 1);
                if (!$row["do_$type"]) {
                    if ($type === 'academic' && !$row['academic_eligible']) {
                        return "$before (not eligible)";
                    }
                    return "$before (unchanged)";
                }
                $counts[$type]++;
                return "$before -> " . number_format((float) $row["after_$type"], 1);
            };
            cronLog(sprintf("%-12s %-28s %-22s %-22s %s",
                $row['roll_number'],
                $row['name'] . " (#{$row['student_id']})",
                $fmt('personal'), $fmt('medical'), $fmt('academic')));
        }
        cronLog(sprintf("Would update for %s: personal %d, medical %d, academic %d (%d student(s) due)",
            $resetDate, $counts['personal'], $counts['medical'], $counts['academic'], count($rows)));
        cronLog("===== Dry run finished; no changes made =====");
        exit(0);
    }

    // Perform reset. Throws on failure, having rolled back; the system_logs
    // 'cron_leave_reset' row is committed with the balance changes.
    $result = $leaveClass->resetBalances($resetDate);

    cronLog(sprintf("Leave balances reset for %s: personal %d, medical %d, academic %d",
        $resetDate, $result['personal'], $result['medical'], $result['academic']));

    $students = $result['students'];
    if (!$students) {
        cronLog("No balances were due for $resetDate (already applied). Nothing to email.");
    } elseif (!$sendEmail) {
        cronLog(count($students) . " student(s) updated; --no-email given, no notifications sent.");
    } else {
        // Only the students this run actually updated.
        $emailService = new EmailService();
        cronLog("Emailing " . count($students) . " updated student(s)");

        $emailsSent = 0;
        $emailsFailed = 0;

        foreach ($students as $student) {
            try {
                // Academic "reset to N" only for those whose academic balance
                // was reset now; everyone else is told they are not yet eligible.
                // $resetDate names the cycle (a catch-up run is not "this month").
                if ($emailService->sendBalanceReset($student, $student['do_academic'], $resetDate)) {
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

        $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                              VALUES (NULL, 'cron_leave_reset_email', 'cron', :description)");
        $stmt->execute([
            ':description' => "Leave balance reset $resetDate notifications. Emails sent: $emailsSent, Failed: $emailsFailed"
        ]);
    }

    cronLog("===== Leave Balance Reset Completed Successfully =====");

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
