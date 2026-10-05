<?php
/**
 * One-off data fix: refund balances double-charged by the old combineLeaves().
 *
 * Before 2026-09-23, combining leaves marked the sources 'combined' WITHOUT
 * refunding the ones that had already been approved (and therefore deducted),
 * then deducted the combined leave again when it was approved. Dhanashree S
 * (student 50) was charged for leaves 27 and 28 and then again for leave 29.
 *
 * For every 'combined' source leave this script establishes, from system_logs,
 * how many times it was deducted and refunded. A source still carrying a net
 * deduction is refunded when its successor is approved (the days were charged
 * twice) or still pending (the fixed code will charge the successor in full on
 * approval). Rejected/cancelled/re-combined successors are reported for a
 * human decision and never touched.
 *
 * Usage (CLI only):
 *   php database/fixes/2026_09_23_refund_combined_double_charge.php            # dry run
 *   php database/fixes/2026_09_23_refund_combined_double_charge.php --apply --admin-id=1 [--student=50]
 *
 * Idempotent: each refund is written through Leave::adjustBalance() with a
 * marker in the remarks, and a source that already has one is skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Leave.php';

$opts = getopt('', ['apply', 'admin-id:', 'student:']);
$apply = isset($opts['apply']);
$adminId = isset($opts['admin-id']) ? (int) $opts['admin-id'] : 0;
$onlyStudent = isset($opts['student']) ? (int) $opts['student'] : 0;

$db = getDBConnection();
$leaveClass = new Leave($db);

// 2026_09_23_leaves.sql adds the combine link columns; a refunded source must be
// recorded there so that rejecting its combined leave later charges it again.
$hasLinkColumns = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_applications'
      AND COLUMN_NAME IN ('combined_into_id', 'combine_refunded_days')")->fetchColumn() === 2;

if ($apply) {
    if (!$hasLinkColumns) {
        fwrite(STDERR, "Apply database/migrations/2026_09_23_leaves.sql first.\n");
        exit(2);
    }
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
    $stmt->execute([$adminId]);
    if (!$stmt->fetch()) {
        fwrite(STDERR, "--apply needs --admin-id=<an active admin's users.id> (recorded as who made the adjustment)\n");
        exit(2);
    }
}

$marker = function ($leaveId) {
    return "[combine-refund leave #$leaveId]";
};
$countLogs = function ($action, $leaveId) use ($db) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM system_logs
                          WHERE action = ? AND description REGEXP ?");
    $stmt->execute([$action, 'Ref ID: ' . (int) $leaveId . '([^0-9]|$)']);
    return (int) $stmt->fetchColumn();
};

$sql = "SELECT la.id, la.student_id, la.leave_type, la.total_days, la.start_date, la.end_date,
               la.hod_status, la.admin_remarks, s.roll_number, u.name AS student_name
        FROM leave_applications la
        JOIN students s ON s.id = la.student_id
        JOIN users u ON u.id = s.user_id
        WHERE la.status = 'combined'" . ($onlyStudent ? " AND la.student_id = " . $onlyStudent : '') . "
        ORDER BY la.student_id, la.start_date";
$sources = $db->query($sql)->fetchAll();

printf("%s: %d combined source leave(s)%s\n\n", $apply ? 'APPLY' : 'DRY RUN', count($sources),
    $onlyStudent ? " for student $onlyStudent" : '');

$refunds = [];
$manual = [];
foreach ($sources as $src) {
    $id = (int) $src['id'];
    $label = sprintf("#%d %s %s..%s %.1fd  %s (%s, student %d)", $id, $src['leave_type'], $src['start_date'],
        $src['end_date'], $src['total_days'], html_entity_decode($src['student_name'], ENT_QUOTES), $src['roll_number'], $src['student_id']);

    $refundedBefore = $countLogs('leave_balance_refunded', $id);
    $net = $countLogs('leave_balance_deducted', $id) - $refundedBefore;

    $stmt = $db->prepare("SELECT COUNT(*) FROM leave_adjustments WHERE student_id = ? AND remarks LIKE ?");
    $stmt->execute([$src['student_id'], '%' . $marker($id) . '%']);
    $alreadyFixed = (int) $stmt->fetchColumn() > 0;

    preg_match_all('/Combined into leave #(\d+)/', (string) $src['admin_remarks'], $m);
    $successorId = $m[1] ? (int) end($m[1]) : 0;
    $successor = $successorId ? $leaveClass->getById($successorId) : null;
    $succStatus = $successor['status'] ?? 'unknown';

    if ($alreadyFixed) {
        echo "  skip    $label — already refunded by this script\n";
        continue;
    }
    if ($net <= 0) {
        if ($refundedBefore > 0) {
            echo "  ok      $label — already refunded when it was combined\n";
        } elseif ($src['hod_status'] === 'approved') {
            $manual[] = "$label — HOD-approved but no deduction logged; check the balance history by hand";
        } else {
            echo "  ok      $label — never deducted\n";
        }
        continue;
    }
    if ($net > 1) {
        $manual[] = "$label — deducted $net times net; investigate";
        continue;
    }
    if (!$successor) {
        $manual[] = "$label — deducted, but its combined leave could not be found";
        continue;
    }
    if (in_array($succStatus, ['approved', 'pending_routing', 'pending_guide', 'pending_ta', 'pending_hod', 'pending_admin'], true)) {
        echo "  REFUND  $label — deducted; combined into #$successorId ($succStatus)\n";
        $refunds[] = [$src, $successorId, $succStatus];
    } else {
        $manual[] = "$label — deducted; combined into #$successorId which is '$succStatus' (decide whether the source days still stand)";
    }
}

if ($manual) {
    echo "\nNeeds a human decision (not changed):\n";
    foreach ($manual as $line) {
        echo "  - $line\n";
    }
}

$byStudent = [];
foreach ($refunds as [$src]) {
    $key = $src['student_id'] . ' ' . $src['leave_type'];
    $byStudent[$key] = ($byStudent[$key] ?? 0) + (float) $src['total_days'];
}
echo "\nRefund totals:\n";
foreach ($byStudent as $key => $days) {
    [$sid, $type] = explode(' ', $key);
    $bal = $db->prepare("SELECT {$type}_leaves FROM leave_balances WHERE student_id = ?");
    $bal->execute([$sid]);
    $now = (float) $bal->fetchColumn();
    printf("  student %d %s: +%.1f day(s)  (balance %.1f -> %.1f)\n", $sid, $type, $days, $now, $now + $days);
}
if (!$byStudent) {
    echo "  none\n";
}

if (!$apply) {
    echo "\nDry run only. Re-run with --apply --admin-id=<admin users.id> to write these refunds.\n";
    exit(0);
}

foreach ($refunds as [$src, $successorId, $succStatus]) {
    $remarks = sprintf('Refund of %.1f day(s): leave #%d was already deducted when it was combined into leave #%d (%s), '
        . 'which carries the charge — double charge by the pre-2026-09-23 combine code. %s',
        $src['total_days'], $src['id'], $successorId, $succStatus, $marker($src['id']));
    $leaveClass->adjustBalance($src['student_id'], $src['leave_type'], (float) $src['total_days'], $remarks, $adminId);
    // Link it the way the fixed combine code does, so that if the combined leave
    // is later rejected or cancelled, Leave::restoreCombinedSources() puts this
    // source back to 'approved' and charges these days again.
    $db->prepare("UPDATE leave_applications SET combined_into_id = ?, combine_refunded_days = ? WHERE id = ?")
       ->execute([$successorId, (int) $src['total_days'], $src['id']]);
    logSystemAction($adminId, 'leave_balance_refunded', 'data_fix',
        "Refunded {$src['total_days']} day(s) of {$src['leave_type']} leave for leave #{$src['id']} "
        . "(combined into #$successorId) to student {$src['student_id']} (Ref ID: {$src['id']})");
    printf("  refunded #%d: +%.1f %s\n", $src['id'], $src['total_days'], $src['leave_type']);
}
echo "Done.\n";
