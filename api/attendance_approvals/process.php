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
// Rejection is a dispute the office must act on, so it always needs a reason.
if ($decision === 'reject' && strlen(trim($remarks)) < 5) {
    jsonResponse(false, 'Please give a reason of at least 5 characters', [], 400);
}

try {
    $userId = getCurrentUserId();
    $approval = new AttendanceApproval();

    $done = 0;
    $failed = [];
    // Tally what actually happened to the records. The message used to be worded
    // from the email count, so a failed handover email reported "sign-off is
    // complete" when the records were in fact waiting on a guide who was never
    // told — the opposite of the truth.
    $movedToGuide = 0;
    $completed = 0;
    // Records that have just moved to the guide, so the guide can be told once
    // per (guide, month) instead of once per student.
    $handoffs = [];

    foreach ($ids as $id) {
        $before = $approval->getById($id);
        $result = $approval->act($id, $userId, $role, $decision, $remarks);
        if ($result['ok']) {
            $done++;
            if ($result['new_status'] === 'pending_guide') {
                $movedToGuide++;
            } elseif ($result['new_status'] === 'approved') {
                $completed++;
            }
            if ($result['new_status'] === 'pending_guide' && $before) {
                $key = (int) $before['faculty_guide_id'] . ':' . (int) $before['period_year']
                     . ':' . (int) $before['period_month'];
                $handoffs[$key] = [
                    'guide_id' => (int) $before['faculty_guide_id'],
                    'year' => (int) $before['period_year'],
                    'month' => (int) $before['period_month'],
                ];
            }
        } else {
            $failed[] = ['id' => $id, 'message' => $result['message']];
        }
    }

    // Notify each affected guide, once, with everything now on their desk.
    $notified = 0;
    if ($handoffs) {
        $emailService = new EmailService();
        $db = getDBConnection();
        foreach ($handoffs as $h) {
            $stmt = $db->prepare("SELECT id, name, email FROM users WHERE id = :id AND is_active = 1");
            $stmt->execute([':id' => $h['guide_id']]);
            $guide = $stmt->fetch();
            if (!$guide) {
                continue;
            }
            $rows = $approval->rowsForFacultyMonth($guide['id'], 'guide', $h['year'], $h['month']);
            if (!$rows) {
                continue;
            }
            $label = DateTime::createFromFormat('!Y-n-j', $h['year'] . '-' . $h['month'] . '-1')
                ->format('F Y');
            // A failed handover email must not fail the approval that already
            // committed — the work is still visible on the guide's page.
            try {
                if ($emailService->sendAttendanceApprovalRequest($guide, 'guide', $label, $rows)) {
                    $notified++;
                }
            } catch (Exception $e) {
                logError('Attendance sign-off: guide notification failed: ' . $e->getMessage());
            }
        }
    }

    if ($done === 0) {
        jsonResponse(false, $failed ? $failed[0]['message'] : 'Nothing was updated', ['failed' => $failed], 409);
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
    if ($failed) {
        $message .= ' ' . count($failed) . ' could not be updated (already actioned).';
    }

    jsonResponse(true, $message, ['done' => $done, 'failed' => $failed, 'guides_notified' => $notified]);
} catch (Exception $e) {
    logError('Attendance approval processing error: ' . $e->getMessage());
    jsonResponse(false, 'Could not record the decision. Please try again.', [], 500);
}
