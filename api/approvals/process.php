<?php
/**
 * API: Process Approval/Rejection
 * Handles approve/reject actions from Faculty and HOD, and approve/reject/cancel
 * from Admin
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Approval.php';
require_once __DIR__ . '/../../classes/Leave.php';
require_once __DIR__ . '/../../includes/EmailService.php';

header('Content-Type: application/json');

// Check authentication
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', [], 405);
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    jsonResponse(false, 'CSRF token validation failed', [], 403);
}

// An admin "logged in as" a faculty member or the HOD could approve, reject or
// cancel in their name, and the record would show that person's decision.
// blockIfImpersonating() answers with plain text, which the fetch() callers
// cannot parse, so the same refusal is given as JSON here.
if (isImpersonating()) {
    jsonResponse(false, 'Approval decisions cannot be made while impersonating another user. '
        . 'Return to your own account first.', [], 403);
}

$leaveId = (int)($_POST['leave_id'] ?? 0);
$action = sanitize($_POST['action'] ?? '');
$role = sanitize($_POST['role'] ?? '');
$remarks = sanitize($_POST['remarks'] ?? '');

// Validation
if (empty($leaveId) || empty($action) || empty($remarks)) {
    jsonResponse(false, 'All fields are required', [], 400);
}

if (!in_array($action, ['approve', 'reject', 'cancel'])) {
    jsonResponse(false, 'Invalid action', [], 400);
}

if (strlen($remarks) < 5) {
    jsonResponse(false, 'Remarks must be at least 5 characters', [], 400);
}

$userId = getCurrentUserId();
$userRole = getCurrentUserRole();

// Only an admin may cancel. For anyone else 'cancel' used to fall through to the
// reject branch and reject the application instead.
if ($action === 'cancel' && $userRole !== 'admin') {
    jsonResponse(false, 'Invalid action', [], 400);
}
if ($userRole === 'faculty' && !in_array($role, ['guide', 'ta'], true)) {
    jsonResponse(false, 'Invalid role', [], 400);
}

try {
    $approval = new Approval();
    $leave = new Leave();

    // Get leave details
    $leaveDetails = $leave->getById($leaveId);

    if (!$leaveDetails) {
        jsonResponse(false, 'Leave application not found', [], 404);
    }

    // Verify access
    if (!canAccessLeave($leaveDetails, $userId, $userRole)) {
        jsonResponse(false, 'Access denied', [], 403);
    }

    // The decision. Each Approval method locks the row, re-checks the stage and
    // the approver, and commits before returning; anything it refuses is thrown
    // and nothing is changed. $notify says which email goes out once it has
    // committed.
    $notify = null;

    if ($userRole === 'faculty') {
        if ($role === 'guide') {
            if ($action === 'approve') {
                $result = $approval->approveByGuide($leaveId, $userId, $remarks);
                $message = $result['message'] ?? 'Leave approved successfully as Faculty Guide';
                // Whoever acts next: the TA, or — when the guide is also the TA,
                // or no TA is assigned — the HOD. Keyed on where the application
                // actually landed; emailing "the TA" unconditionally sent the
                // guide their own application back and never told the HOD.
                $notify = ($result['new_status'] ?? '') === 'pending_ta' ? 'ta' : 'hod';
            } else {
                $result = $approval->rejectByGuide($leaveId, $userId, $remarks);
                $message = 'Leave rejected as Faculty Guide';
                $notify = 'rejected:guide';
            }
        } else {
            if ($action === 'approve') {
                $noDutyConfirmed = isset($_POST['no_duty_confirmed']) ? 1 : 0;

                if (!$noDutyConfirmed) {
                    jsonResponse(false, 'Please confirm that student has no TA duties', [], 400);
                }

                $result = $approval->approveByTA($leaveId, $userId, $remarks, $noDutyConfirmed);
                $message = 'Leave approved successfully as TA Coordinator';
                $notify = 'hod';
            } else {
                $result = $approval->rejectByTA($leaveId, $userId, $remarks);
                $message = 'Leave rejected as TA Coordinator';
                $notify = 'rejected:ta_coordinator';
            }
        }
    } elseif ($userRole === 'hod') {
        $paymentStatus = $_POST['payment_status'] ?? '';
        $paymentStatus = in_array($paymentStatus, Approval::PAYMENT_STATUSES, true) ? $paymentStatus : null;

        if ($action === 'approve') {
            $result = $approval->approveByHOD($leaveId, $userId, $remarks, $paymentStatus);
            $message = $result['message'] ?? 'Leave approved successfully as HOD';
            // Send appropriate email based on whether it needs admin approval
            $notify = ($result['new_status'] ?? '') === 'pending_admin' ? 'admin' : 'approved';
        } else {
            $result = $approval->rejectByHOD($leaveId, $userId, $remarks);
            $message = 'Leave rejected as HOD';
            $notify = 'rejected:hod';
        }
    } elseif ($userRole === 'admin') {
        if ($action === 'approve') {
            // The Dean AP document is the one attached on the pending-approvals
            // page; a path is never accepted from the request.
            $result = $approval->approveByAdmin($leaveId, $userId, $remarks);
            $message = 'Leave approved successfully by Admin';
            $notify = 'approved';
        } elseif ($action === 'cancel') {
            $result = $approval->cancelByAdmin($leaveId, $userId, $remarks);
            $message = 'Leave application cancelled by Admin';
            $notify = 'cancelled';
        } else {
            $result = $approval->rejectByAdmin($leaveId, $userId, $remarks);
            $message = 'Leave rejected by Admin';
            $notify = 'rejected:admin';
        }
    } else {
        jsonResponse(false, 'Invalid user role', [], 403);
    }

    // The decision has committed. Email is best-effort from here on: failing the
    // request now would invite the approver to click again and be told the
    // application was already processed. The row is re-read so the email carries
    // what was actually stored — including the remarks just written, which the
    // pre-decision copy did not have.
    try {
        $emailService = new EmailService();
        $current = $leave->getById($leaveId) ?: $leaveDetails;

        if ($notify === 'ta') {
            $emailService->sendGuideApproval($current);
        } elseif ($notify === 'hod') {
            $emailService->sendTAApproval($current);
        } elseif ($notify === 'admin') {
            $emailService->sendAdminNotification($current);
        } elseif ($notify === 'approved') {
            $emailService->sendHODApproval($current);
        } elseif ($notify === 'cancelled') {
            $emailService->sendRejection($current, 'admin', $remarks, true);
        } elseif (strpos((string) $notify, 'rejected:') === 0) {
            $emailService->sendRejection($current, substr($notify, strlen('rejected:')), $remarks);
        }
    } catch (Throwable $emailEx) {
        logError("Approval email failed for leave $leaveId: " . $emailEx->getMessage());
    }

    jsonResponse(true, $message, ['leave_id' => $leaveId]);

} catch (Exception $e) {
    logError("Approval processing error: " . $e->getMessage());
    jsonResponse(false, $e->getMessage(), [], 500);
}
