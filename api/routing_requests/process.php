<?php
/**
 * API: record an admin's decision on a guide / TA change request.
 *
 * Approving rewrites the part of the student's routing they asked to change;
 * rejecting leaves it alone. Either way the leave applications held behind the
 * request are released into the normal chain, so this endpoint is also where
 * their first approver finally gets told about them — and, on approval, where
 * a new guide/TA is told about any monthly attendance sign-off moved to them.
 *
 * If the roster has changed since the student raised the request, approving
 * answers 409 with needs_confirmation, a description of the difference and the
 * roster the admin is being shown (seen_guide_id / seen_ta_id); the page asks
 * the admin and re-posts with confirm_drift=1 and those two ids, and is asked
 * again if the roster has moved in between.
 *
 * Authorisation is the session role, never anything posted by the browser.
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/GuideTAChange.php';
require_once __DIR__ . '/../../classes/Leave.php';
require_once __DIR__ . '/../../includes/EmailService.php';

header('Content-Type: application/json');

requireLogin();
// Only the office decides this. It rewrites who approves a student's leave and
// who signs off their attendance, so it is not delegated to faculty or the HOD.
if (getCurrentUserRole() !== 'admin') {
    jsonResponse(false, 'Only the admin office can decide guide/TA changes', [], 403);
}
// An impersonating admin must not be able to change a roster as someone else.
// JSON, not blockIfImpersonating()'s plain text: the page reads res.json().
if (isImpersonating()) {
    jsonResponse(false, 'Guide/TA changes cannot be decided while impersonating another user.', [], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    jsonResponse(false, 'CSRF token validation failed', [], 403);
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision  = sanitize($_POST['action'] ?? '');
$remarks   = sanitize($_POST['remarks'] ?? '');
// The roster the admin confirmed: exactly what the previous 409 showed them.
$confirmed = !empty($_POST['confirm_drift'])
    ? ['faculty_guide_id' => $_POST['confirm_guide_id'] ?? null,
       'ta_coordinator_id' => $_POST['confirm_ta_id'] ?? null]
    : null;

if ($requestId <= 0) {
    jsonResponse(false, 'No request selected', [], 400);
}
if (!in_array($decision, ['approve', 'reject'], true)) {
    jsonResponse(false, 'Invalid action', [], 400);
}
// A rejection sends the student back to an arrangement they have said is wrong,
// so it has to carry an explanation they can act on.
if ($decision === 'reject' && strlen(trim($remarks)) < 5) {
    jsonResponse(false, 'Please give a reason of at least 5 characters', [], 400);
}

try {
    $changes = new GuideTAChange();
    $result = $changes->decide($requestId, getCurrentUserId(), $decision, $remarks, $confirmed);

    if (!$result['ok'] && !empty($result['needs_confirmation'])) {
        jsonResponse(false, $result['message'], [
            'needs_confirmation' => true,
            'seen_guide_id' => $result['seen_roster']['faculty_guide_id'],
            'seen_ta_id' => $result['seen_roster']['ta_coordinator_id'],
        ], 409);
    }

    $request = $result['request'];
    $released = $result['released'];
    $cancelled = $result['cancelled'];

    // Email is best-effort: the decision is already committed, and failing the
    // request here would invite the admin to click again and be told it was
    // already decided.
    try {
        $emailService = new EmailService();

        // The email states who the student's guide and TA now are. It reads the
        // requested names on approval and the snapshot names on rejection, and
        // either can be wrong now: approval applies only what was asked (the
        // other field may have been changed by the office since), and the
        // snapshot is from when the request was raised. Tell the student what
        // is actually on record.
        $forEmail = $request;
        $forEmail['requested_guide_name'] = $forEmail['current_guide_name'] = $request['live_guide_name'];
        $forEmail['requested_ta_name'] = $forEmail['current_ta_name'] = $request['live_ta_name'];
        $emailService->sendGuideTAChangeDecision($forEmail, $released, $cancelled);

        // Each released application is entering the chain now, so its first
        // approver needs the same notification they would have had at apply time.
        if ($released) {
            $leave = new Leave();
            foreach ($released as $leaveId) {
                $details = $leave->getById($leaveId);
                if ($details && $details['status'] === 'pending_guide'
                    && !empty($details['guide_email'])) {
                    $emailService->sendLeaveApplication($details, 'guide');
                }
            }
        }
    } catch (Exception $emailEx) {
        logError('Guide/TA decision email failed: ' . $emailEx->getMessage());
    }

    // Monthly attendance sign-offs that moved to a new guide/TA: tell them, as
    // the cron would have when the month opened. Separate from the block above
    // so a failed leave email cannot stop it.
    $signoffsMoved = 0;
    if (!empty($result['signoff_notify'])) {
        try {
            $signoffsMoved = (new AttendanceApproval())->notifyTargets($result['signoff_notify']);
        } catch (Exception $e) {
            logError('Guide/TA decision: sign-off notification failed: ' . $e->getMessage());
        }
    }

    // In-flight applications that moved on (or changed hands) with the roster.
    if (!empty($result['leave_routing']['notify'])) {
        Approval::sendRoutingNotifications($result['leave_routing']['notify']);
    }

    $verb = $decision === 'approve' ? 'approved' : 'rejected';
    $message = "Request $verb.";
    if ($decision === 'approve' && !$result['applied']) {
        $message .= ' The roster already matched what was asked for, so nothing was changed.';
    }
    if (!empty($result['signoff_notify'])) {
        $message .= ' Pending attendance sign-offs were moved to the new approver'
                  . ($signoffsMoved ? ' and they have been emailed.' : '.');
    }
    if ($released) {
        $message .= ' ' . count($released) . ' held leave application(s) sent for approval.';
    }
    if ($cancelled) {
        $message .= ' ' . count($cancelled) . ' application(s) could not be routed (the student '
                  . 'still has no Faculty Guide) and were cancelled.';
    }
    if (!empty($result['leave_routing']['moved'])) {
        $message .= ' ' . count($result['leave_routing']['moved']) . ' application(s) waiting at the TA stage '
                  . 'were forwarded to the HOD (no separate TA approval is needed any more).';
    }
    if (!empty($result['leave_routing']['returned'])) {
        $message .= ' ' . count($result['leave_routing']['returned']) . ' application(s) no faculty member had '
                  . 'approved yet were sent back to the Faculty Guide.';
    }
    if (!empty($result['leave_routing']['stranded'])) {
        $message .= ' Warning: ' . count($result['leave_routing']['stranded']) . ' application(s) are waiting '
                  . 'for a Faculty Guide and the student now has none.';
    }
    if (!$released && !$cancelled) {
        $message .= ' No leave applications were waiting on it.';
    }

    jsonResponse(true, $message, [
        'request_id' => $requestId,
        'status' => $request['status'],
        'released' => $released,
        'cancelled' => $cancelled,
    ]);

} catch (Exception $e) {
    logError('Guide/TA change decision failed: ' . $e->getMessage());
    jsonResponse(false, $e->getMessage(), [], 400);
}
