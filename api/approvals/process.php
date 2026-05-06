<?php
/**
 * API: Process Approval/Rejection
 * Handles approve/reject actions from Faculty and HOD
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

try {
    $userId = getCurrentUserId();
    $userRole = getCurrentUserRole();

    $approval = new Approval();
    $leave = new Leave();
    $emailService = new EmailService();

    // Get leave details
    $leaveDetails = $leave->getById($leaveId);

    if (!$leaveDetails) {
        jsonResponse(false, 'Leave application not found', [], 404);
    }

    // Verify access
    if (!canAccessLeave($leaveDetails, $userId, $userRole)) {
        jsonResponse(false, 'Access denied', [], 403);
    }

    $result = false;
    $message = '';

    // Process based on role and action
    if ($userRole === 'faculty') {
        if ($role === 'guide') {
            if ($action === 'approve') {
                $result = $approval->approveByGuide($leaveId, $userId, $remarks);
                $message = 'Leave approved successfully as Faculty Guide';

                // Send email to TA Coordinator
                if ($result) {
                    $emailService->sendGuideApproval($leaveDetails);
                }
            } else {
                $result = $approval->rejectByGuide($leaveId, $userId, $remarks);
                $message = 'Leave rejected as Faculty Guide';

                // Send rejection email to student
                if ($result) {
                    $emailService->sendRejection($leaveDetails, 'guide');
                }
            }
        } elseif ($role === 'ta') {
            if ($action === 'approve') {
                $noDutyConfirmed = isset($_POST['no_duty_confirmed']) ? 1 : 0;

                if (!$noDutyConfirmed) {
                    jsonResponse(false, 'Please confirm that student has no TA duties', [], 400);
                }

                $result = $approval->approveByTA($leaveId, $userId, $remarks, $noDutyConfirmed);
                $message = 'Leave approved successfully as TA Coordinator';

                // Send email to HOD
                if ($result) {
                    $emailService->sendTAApproval($leaveDetails);
                }
            } else {
                $result = $approval->rejectByTA($leaveId, $userId, $remarks);
                $message = 'Leave rejected as TA Coordinator';

                // Send rejection email to student
                if ($result) {
                    $emailService->sendRejection($leaveDetails, 'ta_coordinator');
                }
            }
        }
    } elseif ($userRole === 'hod') {
        $paymentStatus = !empty($_POST['payment_status']) ? sanitize($_POST['payment_status']) : null;

        if ($action === 'approve') {
            $result = $approval->approveByHOD($leaveId, $remarks, $paymentStatus);
            $message = 'Leave approved successfully as HOD';

            // Send appropriate email based on whether it needs admin approval
            if ($result) {
                $updatedLeave = $leave->getById($leaveId);
                if ($updatedLeave['status'] === 'pending_admin') {
                    $emailService->sendAdminNotification($updatedLeave);
                } else {
                    $emailService->sendHODApproval($updatedLeave);
                }
            }
        } else {
            $result = $approval->rejectByHOD($leaveId, $remarks);
            $message = 'Leave rejected as HOD';

            // Send rejection email to student
            if ($result) {
                $emailService->sendRejection($leaveDetails, 'hod');
            }
        }
    } elseif ($userRole === 'admin') {
        $documentPath = sanitize($_POST['document_path'] ?? '');

        if ($action === 'approve') {
            $result = $approval->approveByAdmin($leaveId, $documentPath, $remarks);
            $message = 'Leave approved successfully by Admin';

            // Send final approval email to student
            if ($result) {
                $updatedLeave = $leave->getById($leaveId);
                $emailService->sendHODApproval($updatedLeave); // Reuse HOD approval template
            }
        } elseif ($action === 'cancel') {
            $result = $approval->cancelByAdmin($leaveId, $remarks);
            $message = 'Leave application cancelled by Admin';

            // Send cancellation email to student with remarks
            if ($result) {
                $emailService->sendRejection($leaveDetails, 'admin', $remarks, true);
            }
        } else {
            $result = $approval->rejectByAdmin($leaveId, $remarks);
            $message = 'Leave rejected by Admin';

            // Send rejection email to student
            if ($result) {
                $emailService->sendRejection($leaveDetails, 'admin');
            }
        }
    } else {
        jsonResponse(false, 'Invalid user role', [], 403);
    }

    if ($result) {
        jsonResponse(true, $message, ['leave_id' => $leaveId]);
    } else {
        jsonResponse(false, 'Failed to process request', [], 500);
    }

} catch (Exception $e) {
    logError("Approval processing error: " . $e->getMessage());
    jsonResponse(false, $e->getMessage(), [], 500);
}
