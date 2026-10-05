<?php
/**
 * API: Get Leave Details
 * Returns full leave application details including approval chain
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../classes/Leave.php';
require_once __DIR__ . '/../../classes/Approval.php';

header('Content-Type: application/json');

// Check authentication for API - return JSON error instead of redirect
if (!isLoggedIn()) {
    jsonResponse(false, 'Authentication required. Please log in again.', [], 401);
}

$leaveId = (int)($_GET['id'] ?? 0);

if (empty($leaveId)) {
    jsonResponse(false, 'Leave ID is required', [], 400);
}

try {
    $leave = new Leave();
    $approval = new Approval();

    $leaveDetails = $leave->getById($leaveId);

    if (!$leaveDetails) {
        jsonResponse(false, 'Leave application not found', [], 404);
    }

    // Check access permission
    $userId = getCurrentUserId();
    $userRole = getCurrentUserRole();

    if (!canAccessLeave($leaveDetails, $userId, $userRole)) {
        jsonResponse(false, 'Access denied', [], 403);
    }

    // Get approval chain
    $approvalChainResult = $approval->getApprovalChain($leaveId);
    $approvalChain = $approvalChainResult['approval_chain'] ?? [];

    // Format response. Every caller (student/leave_history.php, hod/all_leaves.php,
    // admin/leave_management.php) drops these fields straight into innerHTML, so
    // the free-text ones are returned as escaped HTML — a student's reason is
    // otherwise markup running in the HOD's or admin's session.
    $response = [
        'id' => $leaveDetails['id'],
        'student_name' => detailsHtml($leaveDetails['student_name'] ?? ''),
        'roll_number' => detailsHtml($leaveDetails['roll_number'] ?? ''),
        'leave_type' => $leaveDetails['leave_type'],
        'leave_type_label' => getLeaveTypeLabel($leaveDetails['leave_type']),
        'start_date' => $leaveDetails['start_date'],
        'start_date_formatted' => formatDate($leaveDetails['start_date']),
        'end_date' => $leaveDetails['end_date'],
        'end_date_formatted' => formatDate($leaveDetails['end_date']),
        'total_days' => $leaveDetails['total_days'],
        'reason' => detailsHtml($leaveDetails['reason'], true),
        'status' => $leaveDetails['status'],
        'status_badge' => getStatusBadge($leaveDetails['status']),
        'created_at' => $leaveDetails['created_at'],
        'created_at_formatted' => formatDateTime($leaveDetails['created_at']),
        'approval_chain' => formatApprovalChain($approvalChain)
    ];

    jsonResponse(true, 'Leave details retrieved successfully', $response);

} catch (Exception $e) {
    logError("Get leave details error: " . $e->getMessage());
    jsonResponse(false, 'An error occurred while retrieving leave details', [], 500);
}

/**
 * Stored text as HTML, escaped exactly once. Values are stored HTML-escaped by
 * sanitize() on input, but not all of them (combined leaves, older rows), so
 * decode first: escaping an escaped value shows "&#039;", and passing a raw one
 * through lets markup in.
 */
function detailsHtml($value, $multiline = false) {
    $plain = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
    return $multiline ? nl2br($html) : $html;
}

function formatApprovalChain($chain) {
    if (empty($chain)) {
        return null;
    }

    $html = '<div class="space-y-3">';

    foreach ($chain as $step) {
        $statusClass = '';
        $icon = '';

        if ($step['status'] === 'approved') {
            $statusClass = 'border-green-500 bg-green-50';
            $icon = '<i class="fas fa-check-circle text-green-600"></i>';
        } elseif ($step['status'] === 'rejected') {
            $statusClass = 'border-red-500 bg-red-50';
            $icon = '<i class="fas fa-times-circle text-red-600"></i>';
        } else {
            $statusClass = 'border-gray-300 bg-gray-50';
            $icon = '<i class="fas fa-clock text-gray-400"></i>';
        }

        $html .= '<div class="border-l-4 ' . $statusClass . ' p-3 rounded">';
        $html .= '<div class="flex items-center justify-between mb-2">';
        $html .= '<div class="flex items-center">';
        $html .= $icon;
        $html .= '<span class="ml-2 font-semibold">' . detailsHtml($step['stage']) . '</span>';
        $html .= '</div>';

        if ($step['status'] !== 'pending' && !empty($step['approved_at'])) {
            $html .= '<span class="text-xs text-gray-500">' . date('M j, Y h:i A', strtotime($step['approved_at'])) . '</span>';
        }

        $html .= '</div>';

        if (!empty($step['approver_name'])) {
            $html .= '<p class="text-sm text-gray-700">By: ' . detailsHtml($step['approver_name']) . '</p>';
        }

        if (!empty($step['remarks'])) {
            $html .= '<p class="text-sm text-gray-600 mt-2 italic">"' . detailsHtml($step['remarks'], true) . '"</p>';
        }

        if ($step['stage'] === 'TA Coordinator' && !empty($step['no_duty_confirmed'])) {
            $html .= '<p class="text-xs text-green-600 mt-1"><i class="fas fa-check"></i> Confirmed no TA duties</p>';
        }

        if ($step['stage'] === 'HOD' && !empty($step['payment_status'])) {
            $html .= '<p class="text-xs text-blue-600 mt-1"><i class="fas fa-money-bill-wave"></i> Payment Status: ' . detailsHtml(ucwords(str_replace('_', ' ', $step['payment_status']))) . '</p>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}
