<?php
/**
 * Approval Class
 * Handles all leave approval workflow operations
 */

// cancelByAdmin() reads Leave::PENDING_STATUSES. Every caller happens to load
// Leave first, but relying on that makes this file silently order-dependent.
require_once __DIR__ . '/Leave.php';

class Approval {
    /**
     * Written into ta_remarks when the guide's approval also settles the TA
     * stage because one person holds both roles. Public so the approval-chain
     * view and the faculty pages can recognise an automatic approval and label
     * it as such instead of presenting it as a decision someone made by hand.
     */
    const TA_AUTO_APPROVED_REMARK =
        'Auto-approved: the Faculty Guide and TA Coordinator are the same person, '
        . 'and this was settled by their approval at the Guide stage.';

    private $db;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Approve leave by Faculty Guide
     */
    public function approveByGuide($leaveId, $facultyId, $remarks = null) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify this faculty is the guide for this student
            if ($leave['faculty_guide_id'] != $facultyId) {
                throw new Exception("Unauthorized: You are not the guide for this student");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_guide') {
                throw new Exception("Leave is not pending guide approval");
            }

            $hasTA = !empty($leave['ta_coordinator_id']);
            // One person holding both roles would otherwise be asked to approve
            // the same application twice, wearing a different hat each time. The
            // second click carries no new information: the supervisor's consent
            // and the duty-roster check are being made by the same person in the
            // same sitting. So the TA stage is settled here and the application
            // goes straight to the HOD.
            $taIsGuide = $hasTA
                && (int) $leave['ta_coordinator_id'] === (int) $leave['faculty_guide_id'];

            if ($taIsGuide) {
                $newStatus = 'pending_hod';
                $taStatus = 'approved';
                // Recorded rather than left blank: the approval chain has to show
                // WHY that stage was never actioned by hand, or it reads as a
                // stage that was skipped without authority.
                $taRemarks = self::TA_AUTO_APPROVED_REMARK;
                $nextApprover = 'HOD';
                $message = 'Leave approved. You are also the TA Coordinator for this student, '
                         . 'so the TA stage was approved automatically. Forwarded to HOD.';
            } elseif ($hasTA) {
                $newStatus = 'pending_ta';
                $taStatus = 'pending';
                $taRemarks = null;
                $nextApprover = 'TA Coordinator';
                $message = 'Leave approved successfully. Forwarded to TA Coordinator.';
            } else {
                // Skip TA step, go directly to HOD
                $newStatus = 'pending_hod';
                $taStatus = 'approved';
                $taRemarks = 'Auto-approved: no TA Coordinator is assigned to this student.';
                $nextApprover = 'HOD';
                $message = 'Leave approved successfully. Forwarded to HOD (no TA Coordinator assigned).';
            }

            // Update leave application. ta_approved_at is stamped alongside an
            // auto-approval so the stage carries a time as well as a reason.
            $sql = "UPDATE leave_applications
                    SET guide_status = 'approved',
                        guide_remarks = :remarks,
                        guide_approved_at = NOW(),
                        ta_status = :ta_status,
                        ta_remarks = COALESCE(:ta_remarks, ta_remarks),
                        ta_approved_at = CASE WHEN :ta_auto = 1 THEN NOW() ELSE ta_approved_at END,
                        status = :new_status,
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':ta_status' => $taStatus,
                ':ta_remarks' => $taRemarks,
                ':ta_auto' => $taStatus === 'approved' ? 1 : 0,
                ':new_status' => $newStatus,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($facultyId, 'guide_approved', $leaveId,
                "Guide approved leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to next approver. Note this keys on the status we
            // just wrote, not on $hasTA — when the guide is also the TA there IS a
            // TA, but the application has already passed that stage.
            if ($newStatus === 'pending_ta') {
                $this->sendNotification($leave['ta_coordinator_id'], 'ta_approval_pending', $leaveId);
            } else {
                $this->sendNotification(null, 'hod_approval_pending', $leaveId, 'hod');
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => $message,
                'next_approver' => $nextApprover
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Guide approval failed: " . $e->getMessage());
            throw new Exception("Failed to approve leave: " . $e->getMessage());
        }
    }

    /**
     * Reject leave by Faculty Guide
     */
    public function rejectByGuide($leaveId, $facultyId, $remarks) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify this faculty is the guide for this student
            if ($leave['faculty_guide_id'] != $facultyId) {
                throw new Exception("Unauthorized: You are not the guide for this student");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_guide') {
                throw new Exception("Leave is not pending guide approval");
            }

            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET guide_status = 'rejected',
                        guide_remarks = :remarks,
                        guide_approved_at = NOW(),
                        status = 'rejected',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($facultyId, 'guide_rejected', $leaveId,
                "Guide rejected leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_rejected', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave application rejected'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Guide rejection failed: " . $e->getMessage());
            throw new Exception("Failed to reject leave: " . $e->getMessage());
        }
    }

    /**
     * Approve leave by TA Coordinator
     */
    public function approveByTA($leaveId, $facultyId, $remarks = null, $noDutyConfirmed = false) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify this faculty is the TA coordinator for this student
            if ($leave['ta_coordinator_id'] != $facultyId) {
                throw new Exception("Unauthorized: You are not the TA coordinator for this student");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_ta') {
                throw new Exception("Leave is not pending TA coordinator approval");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET ta_status = 'approved',
                        ta_remarks = :remarks,
                        ta_no_duty_confirmed = :no_duty_confirmed,
                        ta_approved_at = NOW(),
                        status = 'pending_hod',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':no_duty_confirmed' => $noDutyConfirmed ? 1 : 0,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($facultyId, 'ta_approved', $leaveId,
                "TA Coordinator approved leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to HOD
            $this->sendNotification(null, 'hod_approval_pending', $leaveId, 'hod');

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave approved successfully. Forwarded to HOD.',
                'next_approver' => 'HOD'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("TA approval failed: " . $e->getMessage());
            throw new Exception("Failed to approve leave: " . $e->getMessage());
        }
    }

    /**
     * Reject leave by TA Coordinator
     */
    public function rejectByTA($leaveId, $facultyId, $remarks) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify this faculty is the TA coordinator for this student
            if ($leave['ta_coordinator_id'] != $facultyId) {
                throw new Exception("Unauthorized: You are not the TA coordinator for this student");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_ta') {
                throw new Exception("Leave is not pending TA coordinator approval");
            }

            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET ta_status = 'rejected',
                        ta_remarks = :remarks,
                        ta_approved_at = NOW(),
                        status = 'rejected',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($facultyId, 'ta_rejected', $leaveId,
                "TA Coordinator rejected leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_rejected', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave application rejected'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("TA rejection failed: " . $e->getMessage());
            throw new Exception("Failed to reject leave: " . $e->getMessage());
        }
    }

    /**
     * Approve leave by HOD
     */
    public function approveByHOD($leaveId, $remarks = null, $paymentStatus = null) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_hod') {
                throw new Exception("Leave is not pending HOD approval");
            }

            // Check if this is extended academic leave (>15 days)
            $requiresAdminApproval = ($leave['leave_type'] === 'academic' && $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD);

            $newStatus = $requiresAdminApproval ? 'pending_admin' : 'approved';

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET hod_status = 'approved',
                        hod_remarks = :remarks,
                        hod_payment_status = :payment_status,
                        hod_approved_at = NOW(),
                        status = :new_status,
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':payment_status' => $paymentStatus,
                ':new_status' => $newStatus,
                ':leave_id' => $leaveId
            ]);

            // If fully approved (no admin approval needed), deduct leave balance
            if ($newStatus === 'approved') {
                $this->deductLeaveBalance($leave);
            }

            // Log action
            $this->logAction($leave['user_id'], 'hod_approved', $leaveId,
                "HOD approved leave application ID $leaveId for student {$leave['student_name']}");

            if ($requiresAdminApproval) {
                // Send notification to admin
                $this->sendNotification(null, 'admin_approval_pending', $leaveId, 'admin');
                $message = 'Leave approved successfully. Forwarded to Admin for extended academic leave approval.';
                $nextApprover = 'Admin';
            } else {
                // Send notification to student
                $this->sendNotification($leave['user_id'], 'leave_approved', $leaveId);
                $message = 'Leave approved successfully. Leave balance has been updated.';
                $nextApprover = null;
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => $message,
                'next_approver' => $nextApprover,
                'requires_admin_approval' => $requiresAdminApproval
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("HOD approval failed: " . $e->getMessage());
            throw new Exception("Failed to approve leave: " . $e->getMessage());
        }
    }

    /**
     * Reject leave by HOD
     */
    public function rejectByHOD($leaveId, $remarks) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_hod') {
                throw new Exception("Leave is not pending HOD approval");
            }

            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET hod_status = 'rejected',
                        hod_remarks = :remarks,
                        hod_approved_at = NOW(),
                        status = 'rejected',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($leave['user_id'], 'hod_rejected', $leaveId,
                "HOD rejected leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_rejected', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave application rejected'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("HOD rejection failed: " . $e->getMessage());
            throw new Exception("Failed to reject leave: " . $e->getMessage());
        }
    }

    /**
     * Approve leave by Admin (for extended academic leaves)
     */
    public function approveByAdmin($leaveId, $documentPath = null, $remarks = null) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_admin') {
                throw new Exception("Leave is not pending admin approval");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET admin_status = 'approved',
                        admin_document_path = :document_path,
                        admin_approved_at = NOW(),
                        status = 'approved',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':document_path' => $documentPath,
                ':leave_id' => $leaveId
            ]);

            // Deduct leave balance now that it's fully approved
            $this->deductLeaveBalance($leave);

            // Log action
            $this->logAction($leave['user_id'], 'admin_approved', $leaveId,
                "Admin approved leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_approved', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave approved successfully. Leave balance has been updated.'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Admin approval failed: " . $e->getMessage());
            throw new Exception("Failed to approve leave: " . $e->getMessage());
        }
    }

    /**
     * Reject leave by Admin
     */
    public function rejectByAdmin($leaveId, $remarks) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Verify current status
            if ($leave['status'] !== 'pending_admin') {
                throw new Exception("Leave is not pending admin approval");
            }

            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            // Update leave application
            $sql = "UPDATE leave_applications
                    SET admin_status = 'rejected',
                        admin_approved_at = NOW(),
                        status = 'rejected',
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($leave['user_id'], 'admin_rejected', $leaveId,
                "Admin rejected leave application ID $leaveId for student {$leave['student_name']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_rejected', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave application rejected'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Admin rejection failed: " . $e->getMessage());
            throw new Exception("Failed to reject leave: " . $e->getMessage());
        }
    }

    /**
     * Get full approval chain/history for a leave application
     */
    public function getApprovalChain($leaveId) {
        try {
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            $approvalChain = [];

            // Guide approval
            $approvalChain[] = [
                'stage' => 'Faculty Guide',
                'approver_name' => $leave['guide_name'],
                'approver_email' => $leave['guide_email'],
                'status' => $leave['guide_status'],
                'remarks' => $leave['guide_remarks'],
                'approved_at' => $leave['guide_approved_at'],
                'order' => 1
            ];

            // TA Coordinator approval
            $approvalChain[] = [
                'stage' => 'TA Coordinator',
                'approver_name' => $leave['ta_coordinator_name'],
                'approver_email' => $leave['ta_coordinator_email'],
                'status' => $leave['ta_status'],
                'remarks' => $leave['ta_remarks'],
                'no_duty_confirmed' => $leave['ta_no_duty_confirmed'],
                'approved_at' => $leave['ta_approved_at'],
                // Settled by the guide's approval rather than by a second click
                // from the same person. Flagged so a view can label it instead of
                // string-matching the remark, and so it is never mistaken for a
                // decision the TA sat down and made separately.
                'auto_approved' => $leave['ta_remarks'] === self::TA_AUTO_APPROVED_REMARK,
                'order' => 2
            ];

            // HOD approval
            $approvalChain[] = [
                'stage' => 'HOD',
                'approver_name' => 'Head of Department',
                'approver_email' => null,
                'status' => $leave['hod_status'],
                'remarks' => $leave['hod_remarks'],
                'payment_status' => $leave['hod_payment_status'],
                'approved_at' => $leave['hod_approved_at'],
                'order' => 3
            ];

            // Admin approval (only for extended academic leaves)
            if ($leave['leave_type'] === 'academic' && $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD) {
                $approvalChain[] = [
                    'stage' => 'Admin',
                    'approver_name' => 'System Administrator',
                    'approver_email' => null,
                    'status' => $leave['admin_status'] ?? 'pending',
                    'remarks' => null,
                    'document_path' => $leave['admin_document_path'],
                    'approved_at' => $leave['admin_approved_at'],
                    'order' => 4
                ];
            }

            return [
                'success' => true,
                'leave_id' => $leaveId,
                'current_status' => $leave['status'],
                'approval_chain' => $approvalChain
            ];

        } catch (PDOException $e) {
            logError("Failed to get approval chain: " . $e->getMessage());
            throw new Exception("Failed to retrieve approval history");
        }
    }

    /**
     * Get leave details with all related information
     */
    private function getLeaveDetails($leaveId) {
        $sql = "SELECT la.*,
                       s.id as student_id, s.user_id, s.roll_number,
                       s.faculty_guide_id, s.ta_coordinator_id,
                       u.name as student_name, u.email as student_email,
                       fg.name as guide_name, fg.email as guide_email,
                       tc.name as ta_coordinator_name, tc.email as ta_coordinator_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN users fg ON s.faculty_guide_id = fg.id
                LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
                WHERE la.id = :leave_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':leave_id' => $leaveId]);
        return $stmt->fetch();
    }

    /**
     * Deduct leave balance after full approval
     */
    private function deductLeaveBalance($leave) {
        $field = $leave['leave_type'] . '_leaves';

        $sql = "UPDATE leave_balances
                SET $field = $field - :days,
                    updated_at = NOW()
                WHERE student_id = :student_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':days' => $leave['total_days'],
            ':student_id' => $leave['student_id']
        ]);

        // Log the balance deduction
        $this->logAction($leave['user_id'], 'leave_balance_deducted', $leave['id'],
            "Deducted {$leave['total_days']} days from {$leave['leave_type']} leave balance");
    }

    /**
     * Send notification (stub method for now)
     * TODO: Implement email notification using EmailService
     */
    private function sendNotification($userId, $notificationType, $leaveId, $role = null) {
        // Log notification intent
        $this->logAction($userId, 'notification_queued', $leaveId,
            "Notification queued: Type=$notificationType, LeaveID=$leaveId, Role=$role");

        // TODO: Implement actual email notification
        // Example: EmailService::send($userId, $notificationType, $leaveId);

        return true;
    }

    /**
     * Cancel any pending leave by Admin
     * Admin can cancel leaves at any stage in the approval process
     */
    public function cancelByAdmin($leaveId, $remarks) {
        try {
            $this->db->beginTransaction();

            // Get leave details
            $leave = $this->getLeaveDetails($leaveId);
            if (!$leave) {
                throw new Exception("Leave application not found");
            }

            // Check if leave is in a pending state (can be cancelled)
            $pendingStatuses = Leave::PENDING_STATUSES;
            if (!in_array($leave['status'], $pendingStatuses)) {
                throw new Exception("Only pending leaves can be cancelled. Current status: " . $leave['status']);
            }

            if (empty($remarks)) {
                throw new Exception("Cancellation remarks are required");
            }

            // Update leave application to cancelled status
            $sql = "UPDATE leave_applications
                    SET status = 'cancelled',
                        admin_remarks = :remarks,
                        admin_approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :leave_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':remarks' => $remarks,
                ':leave_id' => $leaveId
            ]);

            // Log action
            $this->logAction($leave['user_id'], 'admin_cancelled', $leaveId,
                "Admin cancelled leave application ID $leaveId for student {$leave['student_name']}. Previous status: {$leave['status']}");

            // Send notification to student
            $this->sendNotification($leave['user_id'], 'leave_cancelled', $leaveId);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Leave application cancelled successfully'
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Admin cancellation failed: " . $e->getMessage());
            throw new Exception("Failed to cancel leave: " . $e->getMessage());
        }
    }

    /**
     * Log action to system logs
     */
    private function logAction($userId, $action, $referenceId, $description) {
        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, :module, :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':module' => 'approvals',
            ':description' => $description . " (Ref ID: $referenceId)",
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
}
