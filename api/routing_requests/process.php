<?php
/**
 * API: record an admin's decision on a guide / TA change request.
 *
 * Approving rewrites the student's routing; rejecting leaves it alone. Either
 * way the leave applications held behind the request are released into the
 * normal chain, so this endpoint is also where their first approver finally
 * gets told about them.
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
blockIfImpersonating('Guide/TA changes cannot be decided while impersonating another user.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    jsonResponse(false, 'CSRF token validation failed', [], 403);
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision  = sanitize($_POST['action'] ?? '');
$remarks   = sanitize($_POST['remarks'] ?? '');

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
    $result = $changes->decide($requestId, getCurrentUserId(), $decision, $remarks);

    $request = $result['request'];
    $released = $result['released'];
    $cancelled = $result['cancelled'];

    // Email is best-effort: the decision is already committed, and failing the
    // request here would invite the admin to click again and be told it was
    // already decided.
    try {
        $emailService = new EmailService();
        $emailService->sendGuideTAChangeDecision($request, $released, $cancelled);

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

    $verb = $decision === 'approve' ? 'approved' : 'rejected';
    $message = "Request $verb.";
    if ($released) {
        $message .= ' ' . count($released) . ' held leave application(s) sent for approval.';
    }
    if ($cancelled) {
        $message .= ' ' . count($cancelled) . ' application(s) could not be routed (the student '
                  . 'still has no Faculty Guide) and were cancelled.';
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
