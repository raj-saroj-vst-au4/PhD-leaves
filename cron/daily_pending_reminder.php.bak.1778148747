<?php
/**
 * Cron Job: Daily Pending Leave Reminder
 * Sends a daily reminder at 11:00 AM to each faculty guide and TA coordinator
 * listing leave applications from their students that are still awaiting their action.
 *
 * Crontab entry:
 * 0 11 * * * /usr/bin/php /srv/www/phdtimeoff/cron/daily_pending_reminder.php
 */

set_time_limit(300);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
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
$logFile = $logDir . '/cron_pending_reminder_' . date('Y-m-d') . '.log';

function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

cronLog("===== Starting Daily Pending Leave Reminder =====");

try {
    $db = getDBConnection();
    $emailService = new EmailService();

    // Idempotency: skip if already run today
    $todayStart = date('Y-m-d') . ' 00:00:00';
    $stmt = $db->prepare("SELECT id FROM system_logs
                          WHERE action = 'cron_pending_reminder'
                          AND created_at >= :today_start
                          LIMIT 1");
    $stmt->execute([':today_start' => $todayStart]);
    if ($stmt->fetch()) {
        cronLog("Pending reminders already sent today. Exiting.");
        exit(0);
    }

    // Pull all leaves awaiting guide or TA action, joined with the student
    // and the relevant faculty user. We fetch both stages in one pass so that
    // a faculty member who is both guide and TA gets one email per role.
    $sql = "SELECT
                la.id, la.leave_type, la.start_date, la.end_date,
                la.total_days, la.status, la.created_at,
                s.roll_number,
                stu.name AS student_name,
                CASE
                    WHEN la.status = 'pending_guide' THEN 'guide'
                    WHEN la.status = 'pending_ta'    THEN 'ta_coordinator'
                END AS approver_role,
                CASE
                    WHEN la.status = 'pending_guide' THEN s.faculty_guide_id
                    WHEN la.status = 'pending_ta'    THEN s.ta_coordinator_id
                END AS faculty_id
            FROM leave_applications la
            JOIN students s ON la.student_id = s.id
            JOIN users stu  ON s.user_id = stu.id
            WHERE la.status IN ('pending_guide', 'pending_ta')
            ORDER BY la.created_at ASC";

    $rows = $db->query($sql)->fetchAll();
    cronLog("Found " . count($rows) . " pending application(s) at guide/TA stage");

    if (empty($rows)) {
        cronLog("No pending leaves require reminders. Exiting.");
        $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                              VALUES (1, 'cron_pending_reminder', 'cron', :description)");
        $stmt->execute([':description' => "No pending leaves to remind about."]);
        exit(0);
    }

    // Group by (faculty_id, role) so each faculty gets one consolidated email per role
    $grouped = [];
    foreach ($rows as $row) {
        if (empty($row['faculty_id']) || empty($row['approver_role'])) {
            continue; // student has no guide/TA assigned
        }
        $key = $row['approver_role'] . ':' . $row['faculty_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'faculty_id' => (int)$row['faculty_id'],
                'role' => $row['approver_role'],
                'leaves' => []
            ];
        }
        $grouped[$key]['leaves'][] = $row;
    }

    if (empty($grouped)) {
        cronLog("Pending leaves exist but none have a guide/TA assigned. Exiting.");
        exit(0);
    }

    // Look up faculty contact details once per unique faculty_id
    $facultyIds = array_unique(array_map(fn($g) => $g['faculty_id'], $grouped));
    $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
    $stmt = $db->prepare("SELECT id, name, email FROM users
                          WHERE id IN ($placeholders) AND is_active = 1");
    $stmt->execute(array_values($facultyIds));
    $facultyById = [];
    foreach ($stmt->fetchAll() as $f) {
        $facultyById[(int)$f['id']] = $f;
    }

    $emailsSent = 0;
    $emailsFailed = 0;
    $skipped = 0;

    foreach ($grouped as $group) {
        $faculty = $facultyById[$group['faculty_id']] ?? null;
        if (!$faculty || empty($faculty['email'])) {
            $skipped++;
            cronLog("Skipped faculty_id={$group['faculty_id']} ({$group['role']}): inactive or missing email");
            continue;
        }

        try {
            $sent = $emailService->sendPendingLeavesReminder($faculty, $group['role'], $group['leaves']);
            if ($sent) {
                $emailsSent++;
                cronLog("Reminder sent to {$faculty['email']} ({$group['role']}, " . count($group['leaves']) . " leaves)");
            } else {
                $emailsFailed++;
                cronLog("Failed to send reminder to {$faculty['email']} ({$group['role']})");
            }
        } catch (Exception $e) {
            $emailsFailed++;
            cronLog("Email error for {$faculty['email']}: " . $e->getMessage());
        }

        usleep(100000); // 0.1s pacing
    }

    cronLog("Reminders sent: $emailsSent, Failed: $emailsFailed, Skipped: $skipped");

    $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                          VALUES (1, 'cron_pending_reminder', 'cron', :description)");
    $stmt->execute([
        ':description' => "Daily pending reminders. Sent: $emailsSent, Failed: $emailsFailed, Skipped: $skipped"
    ]);

    cronLog("===== Daily Pending Leave Reminder Completed =====");

} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage());
    cronLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

// Keep last 90 day-stamped log files
$oldLogs = glob($logDir . '/cron_pending_reminder_*.log');
if ($oldLogs) {
    sort($oldLogs);
    $toDelete = array_slice($oldLogs, 0, max(0, count($oldLogs) - 90));
    foreach ($toDelete as $file) {
        unlink($file);
    }
}

exit(0);
