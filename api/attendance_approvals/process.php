<?php
/**
 * API: record a monthly attendance sign-off decision.
 *
 * Handles one record or a whole month in bulk ("approve all"), for either stage.
 * When a TA approval hands students on to a guide, the guide is emailed here —
 * so the second stage starts without waiting for the next cron run.
 *
 * Authorisation is decided by AttendanceApproval::act(), which checks that the
 * caller really is the assigned TA/guide AND that the record is at that stage.
 * Nothing here trusts the role or the id posted by the browser.
 *
 * The figures are re-checked against the attendance record first, and each
 * decision carries the figures_version the page showed (`figures_version` for
 * one record, `v[<id>]` for a batch). A record whose figures moved in between
 * is refused, not signed — the signer is told to reload and look again.
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/AttendanceApproval.php';
require_once __DIR__ . '/../../includes/EmailService.php';

header('Content-Type: application/json');

requireLogin();
if (!in_array(getCurrentUserRole(), ['faculty', 'hod'], true)) {
    jsonResponse(false, 'Only faculty may sign off attendance', [], 403);
}
// A sign-off is the faculty member's own attestation. An admin "logged in as"
// them could otherwise sign on their behalf, and the record would read as the
// faculty member's decision. JSON rather than blockIfImpersonating()'s plain
// text, because the page reads the reply with res.json().
if (isImpersonating()) {
    jsonResponse(false, 'Attendance cannot be signed off while impersonating another user.', [], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    jsonResponse(false, 'CSRF token validation failed', [], 403);
}

$role     = sanitize($_POST['role'] ?? '');          // ta | guide
$decision = sanitize($_POST['action'] ?? '');        // approve | reject
$remarks  = sanitize($_POST['remarks'] ?? '');
$ids      = $_POST['ids'] ?? ($_POST['id'] ?? null); // one id, or many for bulk

if (!in_array($role, ['ta', 'guide'], true)) {
    jsonResponse(false, 'Invalid role', [], 400);
}
if (!in_array($decision, ['approve', 'reject'], true)) {
    jsonResponse(false, 'Invalid action', [], 400);
}
if ($ids === null || $ids === '') {
    jsonResponse(false, 'Nothing selected', [], 400);
}
if (!is_array($ids)) {
    $ids = [$ids];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
if (!$ids) {
    jsonResponse(false, 'Nothing selected', [], 400);
}
// The figures version each record was shown at, keyed by id.
$versions = [];
foreach ((array) ($_POST['v'] ?? []) as $vid => $ver) {
    $versions[(int) $vid] = (int) $ver;
}
if (count($ids) === 1 && isset($_POST['figures_version']) && $_POST['figures_version'] !== '') {
    $versions[$ids[0]] = (int) $_POST['figures_version'];
}
// Rejection is a dispute the office must act on, so it always needs a reason.
if ($decision === 'reject' && strlen(trim($remarks)) < 5) {
    jsonResponse(false, 'Please give a reason of at least 5 characters', [], 400);
}

try {
    $userId = getCurrentUserId();
    $approval = new AttendanceApproval();

    // Bring the figures up to date before anyone signs them: a leave approved
    // or an office override since the page loaded has changed what is true.
    // If the attendance service is down the stored figures stand, and the
    // version check below still guarantees they are the ones that were shown.
    $approval->refreshPendingIds($ids, $userId, $role, 'on sign-off');

    $done = 0;
    $failed = [];
    $stale = 0;
    // Tally what actually happened to the records. The message used to be worded
    // from the email count, so a failed handover email reported "sign-off is
    // complete" when the records were in fact waiting on a guide who was never
    // told — the opposite of the truth.
    $movedToGuide = 0;
    $completed = 0;
    // Records signed while one of the student's leaves for that month was still
    // in flight somewhere else (HOD, office, the other faculty member): not a
    // block, but the signer must be told what they signed.
    $signedWithPending = [];
    // Records that have just moved to the guide, so the guide can be told once
    // per (guide, month) instead of once per student.
    $handoffs = [];

    foreach ($ids as $id) {
        $before = $approval->getById($id);
        $result = $approval->act($id, $userId, $role, $decision, $remarks, $versions[$id] ?? null);
        if ($result['ok']) {
            $done++;
            if (!empty($result['pending_leaves'])) {
                $signedWithPending[] = ['id' => $id, 'leaves' => array_map(function ($l) {
                    return ['id' => (int) $l['id'], 'status' => $l['status'],
                            'start_date' => $l['start_date'], 'end_date' => $l['end_date']];
                }, $result['pending_leaves'])];
            }
            if ($result['new_status'] === 'pending_guide') {
                $movedToGuide++;
            } elseif ($result['new_status'] === 'approved') {
                $completed++;
            }
            if ($result['new_status'] === 'pending_guide' && $before) {
                $key = (int) $before['faculty_guide_id'] . ':' . (int) $before['period_year']
                     . ':' . (int) $before['period_month'];
                $handoffs[$key] = [(int) $before['faculty_guide_id'], 'guide',
                                   (int) $before['period_year'], (int) $before['period_month']];
            }
        } else {
            if (!empty($result['stale'])) {
                $stale++;
            }
            $failed[] = ['id' => $id, 'message' => $result['message']];
        }
    }

    // Notify each affected guide, once, with the records just handed to them.
    // A failed handover email does not fail the approval that already
    // committed — the work is on the guide's page, and the rows stay marked
    // un-notified so the next cron run tries again.
    $notified = $handoffs ? $approval->notifyTargets(array_values($handoffs)) : 0;

    if ($done === 0) {
        jsonResponse(false, $failed ? $failed[0]['message'] : 'Nothing was updated',
            ['failed' => $failed, 'stale' => $stale], 409);
    }

    $verb = $decision === 'approve' ? 'approved' : 'marked as disputed';
    $message = "$done record(s) $verb.";
    if ($decision === 'approve') {
        // Describe both outcomes when a batch produced a mix of the two.
        $parts = [];
        if ($movedToGuide > 0) {
            $parts[] = $movedToGuide . ' sent to the faculty guide'
                . ($notified > 0 ? ' (notified by email)' : ' — the notification email could not be sent,'
                    . ' but it is on their approvals page');
        }
        if ($completed > 0) {
            $parts[] = $completed . ' fully signed off';
        }
        if ($parts) {
            $message .= ' ' . ucfirst(implode('; ', $parts)) . '.';
        }
    }
    if ($signedWithPending) {
        $where = [];
        foreach ($signedWithPending as $w) {
            foreach ($w['leaves'] as $l) {
                $where[AttendanceApproval::leaveStageLabel($l['status'])] = true;
            }
        }
        $message .= ' Note: ' . count($signedWithPending) . ' of these student(s) still have a leave application '
                  . 'for this month pending (' . implode(', ', array_keys($where)) . '). Until it is approved those '
                  . 'days count as absent in what you signed; if it is approved later, the office will reopen '
                  . 'the record to correct it.';
    }
    if ($stale) {
        $message .= ' ' . $stale . ' record(s) NOT signed: the figures changed after the page loaded'
                  . ' — reload to review them.';
    }
    if (count($failed) > $stale) {
        $message .= ' ' . (count($failed) - $stale) . ' could not be updated (already actioned).';
    }

    jsonResponse(true, $message, ['done' => $done, 'failed' => $failed, 'stale' => $stale,
                                  'guides_notified' => $notified,
                                  'signed_with_pending_leave' => $signedWithPending]);
} catch (Exception $e) {
    logError('Attendance approval processing error: ' . $e->getMessage());
    jsonResponse(false, 'Could not record the decision. Please try again.', [], 500);
}
