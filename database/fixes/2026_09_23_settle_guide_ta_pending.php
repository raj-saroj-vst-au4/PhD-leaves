<?php
/**
 * One-off data fix: leaves sitting at the TA stage when the student's guide is
 * also their TA coordinator.
 *
 * Two different shapes exist, and only one may be settled automatically:
 *
 *  - SETTLE: the guide really approved at the guide stage (guide_approved_at set
 *    and a 'guide_approved' audit entry by that same person), and the application
 *    then waited at pending_ta for the same person to approve a second time.
 *    Commit 1eb5c27 settles this for new approvals; this applies the same result
 *    to older ones: TA stage approved with the standard remark, on to the HOD.
 *
 *  - REPORT ONLY: releases before 2026-09 created guide==TA applications straight
 *    at pending_ta with the guide stage merely MARKED approved. Nobody has
 *    approved those — the one person's single approval was meant to happen at the
 *    TA stage, and they are listed in that person's TA queue. Moving them to the
 *    HOD would skip the only faculty review, so they are left alone and listed
 *    for someone to chase. (Leaves 31 and 32 on production are expected to be
 *    this shape — check the dry run.)
 *
 * Usage (CLI only):
 *   php database/fixes/2026_09_23_settle_guide_ta_pending.php             # dry run + stale-leave report
 *   php database/fixes/2026_09_23_settle_guide_ta_pending.php --apply --admin-id=1 [--no-email]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Leave.php';
require_once __DIR__ . '/../../classes/Approval.php';
require_once __DIR__ . '/../../includes/EmailService.php';

$opts = getopt('', ['apply', 'admin-id:', 'no-email']);
$apply = isset($opts['apply']);
$adminId = isset($opts['admin-id']) ? (int) $opts['admin-id'] : 0;
$sendEmail = !isset($opts['no-email']);

$db = getDBConnection();
$leaveClass = new Leave($db);

if ($apply) {
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
    $stmt->execute([$adminId]);
    if (!$stmt->fetch()) {
        fwrite(STDERR, "--apply needs --admin-id=<an active admin's users.id> (recorded as who released them)\n");
        exit(2);
    }
}

$candidates = $db->query("SELECT la.id, la.guide_approved_at, s.faculty_guide_id
                          FROM leave_applications la
                          JOIN students s ON s.id = la.student_id
                          WHERE la.status = 'pending_ta'
                            AND s.ta_coordinator_id IS NOT NULL
                            AND s.ta_coordinator_id = s.faculty_guide_id
                          ORDER BY la.id")->fetchAll();

$approval = new Approval($db);
$settle = [];
$waiting = [];
foreach ($candidates as $c) {
    $approver = $c['guide_approved_at'] ? $approval->guideApproverId($c['id']) : null;
    if ($approver !== null && $approver === (int) $c['faculty_guide_id']) {
        $settle[] = (int) $c['id'];
    } else {
        $waiting[] = (int) $c['id'];
    }
}

printf("%s: %d leave(s) at pending_ta whose guide is also the TA\n", $apply ? 'APPLY' : 'DRY RUN', count($candidates));

if ($waiting) {
    echo "\nNot settled — no faculty member has approved these yet; they are in the guide/TA's own TA queue\n"
       . "and need that person's single approval (or reassign the student's TA in Admin -> Users):\n";
    foreach ($waiting as $leaveId) {
        $leave = $leaveClass->getById($leaveId);
        printf("  #%d %s %s..%s  %s — waiting on user #%d since %s\n", $leaveId, $leave['leave_type'],
            $leave['start_date'], $leave['end_date'], html_entity_decode($leave['student_name'] ?? '', ENT_QUOTES),
            $leave['faculty_guide_id'], substr($leave['created_at'], 0, 10));
    }
}

if ($settle) {
    echo "\nGuide already approved; the TA stage asks the same person again — settle to HOD:\n";
}
foreach ($settle as $leaveId) {
    $leave = $leaveClass->getById($leaveId);
    printf("  #%d %s %s..%s  %s — guide/TA user #%d\n", $leaveId, $leave['leave_type'], $leave['start_date'],
        $leave['end_date'], html_entity_decode($leave['student_name'] ?? '', ENT_QUOTES), $leave['faculty_guide_id']);
    if (!$apply) {
        continue;
    }

    $db->beginTransaction();
    try {
        // Re-check under the row lock: still at the TA stage, and the roster still
        // has the same person who approved as guide in both roles.
        $lock = $db->prepare("SELECT la.status, s.faculty_guide_id, s.ta_coordinator_id
                              FROM leave_applications la JOIN students s ON s.id = la.student_id
                              WHERE la.id = ? FOR UPDATE");
        $lock->execute([$leaveId]);
        $now = $lock->fetch();
        if (!$now || $now['status'] !== 'pending_ta'
            || (int) $now['ta_coordinator_id'] !== (int) $now['faculty_guide_id']
            || $approval->guideApproverId($leaveId) !== (int) $now['faculty_guide_id']) {
            $db->rollBack();
            echo "    changed since the scan; left alone\n";
            continue;
        }
        $upd = $db->prepare("UPDATE leave_applications
                             SET ta_status = 'approved', ta_remarks = :remarks, ta_approved_at = NOW(),
                                 status = 'pending_hod'
                             WHERE id = :id AND status = 'pending_ta'");
        $upd->execute([':remarks' => Approval::TA_AUTO_APPROVED_REMARK, ':id' => $leaveId]);
        logSystemAction($adminId, 'ta_stage_settled', 'data_fix',
            "TA stage settled for leave $leaveId: the guide (user #{$now['faculty_guide_id']}) is also the TA "
            . "and had already approved as guide; forwarded to HOD (Ref ID: $leaveId)");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        echo "    FAILED: " . $e->getMessage() . "\n";
        continue;
    }
    echo "    -> pending_hod\n";

    if ($sendEmail) {
        $ok = (new EmailService())->sendTAApproval($leaveClass->getById($leaveId));
        echo $ok ? "    HOD emailed\n" : "    HOD email FAILED (see email_logs)\n";
    }
}

// Everything else that has been waiting a long time, for a human to chase.
echo "\nOther pending leaves whose dates are long past (not changed):\n";
$rows = $db->query("SELECT la.id, la.status, la.start_date, la.end_date, la.created_at,
                           s.faculty_guide_id, s.ta_coordinator_id
                    FROM leave_applications la
                    JOIN students s ON s.id = la.student_id
                    WHERE la.status IN ('pending_routing','pending_guide','pending_ta','pending_hod','pending_admin')
                      AND la.end_date < CURDATE() - INTERVAL 30 DAY
                    ORDER BY la.created_at")->fetchAll();
foreach ($rows as $r) {
    $waitingOn = [
        'pending_guide' => 'guide user #' . $r['faculty_guide_id'],
        'pending_ta' => 'TA user #' . $r['ta_coordinator_id'],
        'pending_hod' => 'HOD',
        'pending_admin' => 'admin (Dean AP document)',
        'pending_routing' => 'admin (guide/TA change request)',
    ][$r['status']];
    printf("  #%d %s since %s (dates %s..%s) — waiting on %s\n", $r['id'], $r['status'],
        substr($r['created_at'], 0, 10), $r['start_date'], $r['end_date'], $waitingOn);
}
if (!$rows) {
    echo "  none\n";
}
if (!$apply) {
    echo "\nDry run only. Re-run with --apply --admin-id=<admin users.id> to settle the ones listed under \"settle\".\n";
}
