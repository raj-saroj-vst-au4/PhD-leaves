<?php
/**
 * Approval Class
 * Handles all leave approval workflow operations
 *
 * Every decision (approve / reject / cancel) runs the same way:
 *
 *   BEGIN -> lock the leave row (SELECT ... FOR UPDATE) -> re-check that it is
 *   still at the stage this decision belongs to, and that the actor is that
 *   stage's approver -> update the row, conditionally on that same stage, and on
 *   final approval deduct the balance -> COMMIT.
 *
 * The lock is what makes a decision safe to repeat. Without it two approvers (or
 * one double-click, or a retried request) could both read "pending_hod", both pass
 * the status check and both deduct the balance. With it the second request waits
 * for the first, then sees the new status and fails cleanly with nothing changed.
 *
 * Emails are NOT sent from here: the caller sends them after the method returns,
 * i.e. after the commit, so nobody is told about a decision that was rolled back.
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

    /** Values hod_payment_status accepts. */
    const PAYMENT_STATUSES = ['paid', 'partially_paid', 'unpaid'];

    private $db;
    // True while a decision started by this object is open, so a failure rolls
    // back only what this object began — never a transaction a caller owns.
    private $inDecision = false;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Approve leave by Faculty Guide
     */
    public function approveByGuide($leaveId, $facultyId, $remarks = null) {
        try {
            $leave = $this->lockLeave($leaveId);

            // Verify this faculty is the guide for this student
            if ((int) $leave['faculty_guide_id'] !== (int) $facultyId) {
                throw new Exception("Unauthorized: You are not the guide for this student");
            }

            // Verify current status
            $this->requireStatus($leave, 'pending_guide', 'Faculty Guide');

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
            $this->transition($leaveId, 'pending_guide',
                "guide_status = 'approved',
                 guide_remarks = :remarks,
                 guide_approved_at = NOW(),
                 ta_status = :ta_status,
                 ta_remarks = COALESCE(:ta_remarks, ta_remarks),
                 ta_approved_at = CASE WHEN :ta_auto = 1 THEN NOW() ELSE ta_approved_at END,
                 status = :new_status", [
                ':remarks' => $remarks,
                ':ta_status' => $taStatus,
                ':ta_remarks' => $taRemarks,
                ':ta_auto' => $taStatus === 'approved' ? 1 : 0,
                ':new_status' => $newStatus,
            ]);

            // Log action
            $this->logAction($facultyId, 'guide_approved', $leaveId,
                "Guide approved leave application ID $leaveId for student " . $this->studentLabel($leave)
                . ($newStatus === 'pending_hod' ? '; TA stage settled automatically, forwarded to HOD' : ''));

            // Send notification to next approver. Note this keys on the status we
            // just wrote, not on $hasTA — when the guide is also the TA there IS a
            // TA, but the application has already passed that stage.
            if ($newStatus === 'pending_ta') {
                $this->sendNotification($facultyId, $leave['ta_coordinator_id'], 'ta_approval_pending', $leaveId);
            } else {
                $this->sendNotification($facultyId, null, 'hod_approval_pending', $leaveId, 'hod');
            }

            $this->commitDecision();

            return [
                'success' => true,
                'message' => $message,
                'next_approver' => $nextApprover,
                // The caller emails whoever acts next; it needs the stage the
                // application actually landed in, not a guess from $hasTA.
                'new_status' => $newStatus
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'Guide approval failed', 'Failed to approve leave');
        }
    }

    /**
     * Reject leave by Faculty Guide
     */
    public function rejectByGuide($leaveId, $facultyId, $remarks) {
        try {
            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            $leave = $this->lockLeave($leaveId);

            // Verify this faculty is the guide for this student
            if ((int) $leave['faculty_guide_id'] !== (int) $facultyId) {
                throw new Exception("Unauthorized: You are not the guide for this student");
            }

            // Verify current status. Nothing is refunded on rejection: the balance
            // is deducted only on the move to 'approved', and only a pending
            // application can get here.
            $this->requireStatus($leave, 'pending_guide', 'Faculty Guide');

            // Update leave application
            $this->transition($leaveId, 'pending_guide',
                "guide_status = 'rejected',
                 guide_remarks = :remarks,
                 guide_approved_at = NOW(),
                 status = 'rejected'", [
                ':remarks' => $remarks,
            ]);

            $restored = $this->restoreCombinedSources($leaveId, $facultyId, 'rejected');

            // Log action
            $this->logAction($facultyId, 'guide_rejected', $leaveId,
                "Guide rejected leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to student
            $this->sendNotification($facultyId, $leave['user_id'], 'leave_rejected', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave application rejected' . self::restoredNote($restored),
                'restored_leave_ids' => $restored
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'Guide rejection failed', 'Failed to reject leave');
        }
    }

    /**
     * Approve leave by TA Coordinator
     */
    public function approveByTA($leaveId, $facultyId, $remarks = null, $noDutyConfirmed = false) {
        try {
            $leave = $this->lockLeave($leaveId);

            // Verify this faculty is the TA coordinator for this student
            if ((int) $leave['ta_coordinator_id'] !== (int) $facultyId) {
                throw new Exception("Unauthorized: You are not the TA coordinator for this student");
            }

            // Verify current status
            $this->requireStatus($leave, 'pending_ta', 'TA Coordinator');

            // Update leave application
            $this->transition($leaveId, 'pending_ta',
                "ta_status = 'approved',
                 ta_remarks = :remarks,
                 ta_no_duty_confirmed = :no_duty_confirmed,
                 ta_approved_at = NOW(),
                 status = 'pending_hod'", [
                ':remarks' => $remarks,
                ':no_duty_confirmed' => $noDutyConfirmed ? 1 : 0,
            ]);

            // Log action
            $this->logAction($facultyId, 'ta_approved', $leaveId,
                "TA Coordinator approved leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to HOD
            $this->sendNotification($facultyId, null, 'hod_approval_pending', $leaveId, 'hod');

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave approved successfully. Forwarded to HOD.',
                'next_approver' => 'HOD',
                'new_status' => 'pending_hod'
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'TA approval failed', 'Failed to approve leave');
        }
    }

    /**
     * Reject leave by TA Coordinator
     */
    public function rejectByTA($leaveId, $facultyId, $remarks) {
        try {
            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            $leave = $this->lockLeave($leaveId);

            // Verify this faculty is the TA coordinator for this student
            if ((int) $leave['ta_coordinator_id'] !== (int) $facultyId) {
                throw new Exception("Unauthorized: You are not the TA coordinator for this student");
            }

            // Verify current status (pending, so never deducted: nothing to refund)
            $this->requireStatus($leave, 'pending_ta', 'TA Coordinator');

            // Update leave application
            $this->transition($leaveId, 'pending_ta',
                "ta_status = 'rejected',
                 ta_remarks = :remarks,
                 ta_approved_at = NOW(),
                 status = 'rejected'", [
                ':remarks' => $remarks,
            ]);

            $restored = $this->restoreCombinedSources($leaveId, $facultyId, 'rejected');

            // Log action
            $this->logAction($facultyId, 'ta_rejected', $leaveId,
                "TA Coordinator rejected leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to student
            $this->sendNotification($facultyId, $leave['user_id'], 'leave_rejected', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave application rejected' . self::restoredNote($restored),
                'restored_leave_ids' => $restored
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'TA rejection failed', 'Failed to reject leave');
        }
    }

    /**
     * Approve leave by HOD
     *
     * @param int $hodId users.id of the HOD making the decision (for the audit trail)
     */
    public function approveByHOD($leaveId, $hodId, $remarks = null, $paymentStatus = null) {
        try {
            if ($paymentStatus !== null && !in_array($paymentStatus, self::PAYMENT_STATUSES, true)) {
                throw new Exception("Invalid payment status");
            }

            $leave = $this->lockLeave($leaveId);

            // Verify current status
            $this->requireStatus($leave, 'pending_hod', 'HOD');

            // Check if this is extended academic leave (>15 days)
            $requiresAdminApproval = ($leave['leave_type'] === 'academic' && $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD);

            $newStatus = $requiresAdminApproval ? 'pending_admin' : 'approved';

            // Update leave application
            $this->transition($leaveId, 'pending_hod',
                "hod_status = 'approved',
                 hod_remarks = :remarks,
                 hod_payment_status = :payment_status,
                 hod_approved_at = NOW(),
                 status = :new_status", [
                ':remarks' => $remarks,
                ':payment_status' => $paymentStatus,
                ':new_status' => $newStatus,
            ]);

            // If fully approved (no admin approval needed), deduct leave balance.
            // Same transaction as the status change, under the row lock: the
            // deduction happens exactly once, or not at all.
            if ($newStatus === 'approved') {
                $this->deductLeaveBalance($leave, $hodId);
            }

            // Log action
            $this->logAction($hodId, 'hod_approved', $leaveId,
                "HOD approved leave application ID $leaveId for student " . $this->studentLabel($leave)
                . ($requiresAdminApproval ? '; forwarded to Admin for Dean AP approval' : ''));

            if ($requiresAdminApproval) {
                // Send notification to admin
                $this->sendNotification($hodId, null, 'admin_approval_pending', $leaveId, 'admin');
                $message = 'Leave approved successfully. Forwarded to Admin for extended academic leave approval.';
                $nextApprover = 'Admin';
            } else {
                // Send notification to student
                $this->sendNotification($hodId, $leave['user_id'], 'leave_approved', $leaveId);
                $message = 'Leave approved successfully. Leave balance has been updated.';
                $nextApprover = null;
            }

            $this->commitDecision();

            return [
                'success' => true,
                'message' => $message,
                'next_approver' => $nextApprover,
                'requires_admin_approval' => $requiresAdminApproval,
                'new_status' => $newStatus
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'HOD approval failed', 'Failed to approve leave');
        }
    }

    /**
     * Reject leave by HOD
     *
     * @param int $hodId users.id of the HOD making the decision (for the audit trail)
     */
    public function rejectByHOD($leaveId, $hodId, $remarks) {
        try {
            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            $leave = $this->lockLeave($leaveId);

            // Verify current status (pending, so never deducted: nothing to refund)
            $this->requireStatus($leave, 'pending_hod', 'HOD');

            // Update leave application
            $this->transition($leaveId, 'pending_hod',
                "hod_status = 'rejected',
                 hod_remarks = :remarks,
                 hod_approved_at = NOW(),
                 status = 'rejected'", [
                ':remarks' => $remarks,
            ]);

            $restored = $this->restoreCombinedSources($leaveId, $hodId, 'rejected');

            // Log action
            $this->logAction($hodId, 'hod_rejected', $leaveId,
                "HOD rejected leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to student
            $this->sendNotification($hodId, $leave['user_id'], 'leave_rejected', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave application rejected' . self::restoredNote($restored),
                'restored_leave_ids' => $restored
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'HOD rejection failed', 'Failed to reject leave');
        }
    }

    /**
     * Attach (or replace) the signed Dean AP approval document on an extended
     * academic leave waiting for the admin.
     *
     * @param string $storedName basename returned by uploadFile(..., DEAN_AP_DOCS_DIR)
     * @return string|null the previously attached document, for the caller to
     *                     delete once this has committed
     */
    public function attachDeanAPDocument($leaveId, $adminId, $storedName) {
        try {
            $leave = $this->lockLeave($leaveId);

            // Only while the admin stage is open: once decided, the document on
            // record is the one the decision was taken on.
            $this->requireStatus($leave, 'pending_admin', 'Admin');

            $previous = $leave['admin_document_path'] ?: null;

            $this->transition($leaveId, 'pending_admin',
                "admin_document_path = :document", [
                ':document' => $storedName,
            ]);

            $this->logAction($adminId, $previous ? 'dean_ap_document_replaced' : 'dean_ap_document_uploaded', $leaveId,
                "Dean AP approval document " . ($previous ? 'replaced' : 'uploaded')
                . " for leave application ID $leaveId of student " . $this->studentLabel($leave));

            $this->commitDecision();

            return $previous;

        } catch (Throwable $e) {
            $this->abort($e, 'Dean AP document upload failed', 'Failed to save the document');
        }
    }

    /**
     * Approve leave by Admin (for extended academic leaves)
     *
     * The Dean AP document is the one already attached to the application with
     * attachDeanAPDocument(); it is never taken from the request, so a caller
     * cannot point the record at an arbitrary path.
     *
     * @param int $adminId users.id of the admin making the decision
     */
    public function approveByAdmin($leaveId, $adminId, $remarks = null) {
        try {
            $leave = $this->lockLeave($leaveId);

            // Verify current status
            $this->requireStatus($leave, 'pending_admin', 'Admin');

            // Final approval of an extended academic leave rests on the Dean's
            // sign-off, so it has to be on file (and actually readable) first.
            if (privateFilePath(DEAN_AP_DOCS_DIR, $leave['admin_document_path']) === null) {
                throw new Exception("Upload the signed Dean AP approval document before approving");
            }

            // Update leave application
            $this->transition($leaveId, 'pending_admin',
                "admin_status = 'approved',
                 admin_remarks = :remarks,
                 admin_approved_at = NOW(),
                 status = 'approved'", [
                ':remarks' => $remarks,
            ]);

            // Deduct leave balance now that it's fully approved
            $this->deductLeaveBalance($leave, $adminId);

            // Log action
            $this->logAction($adminId, 'admin_approved', $leaveId,
                "Admin approved leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to student
            $this->sendNotification($adminId, $leave['user_id'], 'leave_approved', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave approved successfully. Leave balance has been updated.',
                'new_status' => 'approved'
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'Admin approval failed', 'Failed to approve leave');
        }
    }

    /**
     * Reject leave by Admin
     *
     * @param int $adminId users.id of the admin making the decision
     */
    public function rejectByAdmin($leaveId, $adminId, $remarks) {
        try {
            if (empty($remarks)) {
                throw new Exception("Rejection remarks are required");
            }

            $leave = $this->lockLeave($leaveId);

            // Verify current status (pending, so never deducted: nothing to refund)
            $this->requireStatus($leave, 'pending_admin', 'Admin');

            // Update leave application. The remarks are stored: they are what the
            // student is told, and they used to be dropped here.
            $this->transition($leaveId, 'pending_admin',
                "admin_status = 'rejected',
                 admin_remarks = :remarks,
                 admin_approved_at = NOW(),
                 status = 'rejected'", [
                ':remarks' => $remarks,
            ]);

            // An extended academic leave the admin turns down may be one that
            // combineLeaves() made from approved leaves: those stand again.
            $restored = $this->restoreCombinedSources($leaveId, $adminId, 'rejected');

            // Log action
            $this->logAction($adminId, 'admin_rejected', $leaveId,
                "Admin rejected leave application ID $leaveId for student " . $this->studentLabel($leave));

            // Send notification to student
            $this->sendNotification($adminId, $leave['user_id'], 'leave_rejected', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave application rejected' . self::restoredNote($restored),
                'restored_leave_ids' => $restored
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'Admin rejection failed', 'Failed to reject leave');
        }
    }

    /**
     * Re-settle a student's in-flight leave applications after their guide/TA
     * changed (admin roster edit, or an approved change request).
     *
     * The leave chain reads the guide and TA from `students` at query time, so
     * most applications simply follow the new people. The cases that do not:
     *  - pending_ta with no TA any more: nobody could ever act on it. If a guide
     *    really approved it, the TA stage is settled and it goes to the HOD, as
     *    approveByGuide() does for a student with no TA. If not — releases before
     *    2026-09 created guide==TA applications straight at pending_ta with the
     *    guide stage merely marked approved, so no faculty member has looked at
     *    it — it goes back to the guide.
     *  - pending_ta whose TA is now the guide who REALLY approved it (on record in
     *    system_logs as that same person): settled, as approveByGuide() now does.
     *    A guide_status of 'approved' alone is not evidence of a decision.
     *  - pending_guide with no guide left is NOT auto-approved; it is returned as
     *    stranded so the caller can tell the admin.
     *
     * Runs inside the caller's transaction when there is one. Emails are the
     * caller's job, after commit: pass 'notify' to sendRoutingNotifications().
     *
     * @return array {moved: int[] (to the HOD), returned: int[] (to the guide),
     *                stranded: int[], notify: array<[kind, leaveId]>}
     */
    public function resettleAfterRoutingChange($studentId, $actorId, $oldGuideId, $oldTaId) {
        $oldTaId = $oldTaId ? (int) $oldTaId : null;
        $oldGuideId = $oldGuideId ? (int) $oldGuideId : null;
        $result = ['moved' => [], 'returned' => [], 'stranded' => [], 'notify' => []];

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT la.id, la.status, la.guide_status, la.guide_approved_at,
                        s.faculty_guide_id, s.ta_coordinator_id
                 FROM leave_applications la
                 JOIN students s ON s.id = la.student_id
                 WHERE la.student_id = ? AND la.status IN ('pending_guide', 'pending_ta')
                 FOR UPDATE");
            $stmt->execute([(int) $studentId]);

            foreach ($stmt->fetchAll() as $row) {
                $leaveId = (int) $row['id'];
                $guide = $row['faculty_guide_id'] ? (int) $row['faculty_guide_id'] : null;
                $ta = $row['ta_coordinator_id'] ? (int) $row['ta_coordinator_id'] : null;

                if ($row['status'] === 'pending_guide') {
                    if ($guide === null) {
                        $result['stranded'][] = $leaveId;
                    } elseif ($guide !== $oldGuideId) {
                        $result['notify'][] = ['guide', $leaveId];
                    }
                    continue;
                }

                // pending_ta: who, if anyone, actually approved the guide stage?
                $approver = !empty($row['guide_approved_at']) ? $this->guideApproverId($leaveId) : null;

                if ($ta === null) {
                    if ($approver !== null) {
                        $this->settleTaStage($leaveId, $actorId,
                            'Auto-approved: no TA Coordinator is assigned to this student.',
                            'no TA Coordinator assigned');
                        $result['moved'][] = $leaveId;
                        $result['notify'][] = ['hod', $leaveId];
                    } elseif ($guide !== null) {
                        $upd = $this->db->prepare(
                            "UPDATE leave_applications
                             SET status = 'pending_guide', guide_status = 'pending', guide_approved_at = NULL
                             WHERE id = :id AND status = 'pending_ta'");
                        $upd->execute([':id' => $leaveId]);
                        if ($upd->rowCount() === 1) {
                            $this->logAction($actorId, 'returned_to_guide', $leaveId,
                                "Leave application ID $leaveId returned to the Faculty Guide: the TA Coordinator was "
                                . 'removed and no guide had approved it (it was created at the TA stage when one '
                                . 'person held both roles)');
                            $result['returned'][] = $leaveId;
                            $result['notify'][] = ['guide', $leaveId];
                        }
                    } else {
                        $result['stranded'][] = $leaveId;
                    }
                    continue;
                }

                if ($ta === $guide && $approver !== null && $approver === $guide) {
                    $this->settleTaStage($leaveId, $actorId, self::TA_AUTO_APPROVED_REMARK,
                        'the guide who approved it is now also the TA Coordinator');
                    $result['moved'][] = $leaveId;
                    $result['notify'][] = ['hod', $leaveId];
                } elseif ($ta !== $oldTaId) {
                    $result['notify'][] = ['ta', $leaveId];
                }
            }

            if ($own) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * users.id of whoever last approved this leave at the guide stage, from the
     * audit trail; null when no human guide approval is on record.
     */
    public function guideApproverId($leaveId) {
        $stmt = $this->db->prepare(
            "SELECT user_id FROM system_logs
             WHERE action = 'guide_approved' AND description REGEXP ?
             ORDER BY id DESC LIMIT 1");
        $stmt->execute(['Ref ID: ' . (int) $leaveId . '([^0-9]|$)']);
        $id = $stmt->fetchColumn();
        return ($id === false || $id === null) ? null : (int) $id;
    }

    /** Settle the TA stage of a pending_ta application and forward it to the HOD. */
    private function settleTaStage($leaveId, $actorId, $remark, $why) {
        $upd = $this->db->prepare(
            "UPDATE leave_applications
             SET ta_status = 'approved', ta_remarks = :remarks, ta_approved_at = NOW(),
                 status = 'pending_hod'
             WHERE id = :id AND status = 'pending_ta'");
        $upd->execute([':remarks' => $remark, ':id' => (int) $leaveId]);
        if ($upd->rowCount() === 1) {
            $this->logAction($actorId, 'ta_stage_settled', $leaveId,
                "TA stage settled after a guide/TA change for leave application ID $leaveId ($why); forwarded to HOD");
        }
    }

    /**
     * Email whoever now has to act on the applications resettleAfterRoutingChange()
     * touched. Best-effort; call only after the routing change has committed.
     */
    public static function sendRoutingNotifications(array $notify) {
        if (!$notify) {
            return;
        }
        require_once __DIR__ . '/../includes/EmailService.php';
        $leave = new Leave();
        $email = new EmailService();
        foreach ($notify as [$kind, $leaveId]) {
            try {
                $details = $leave->getById($leaveId);
                if (!$details) {
                    continue;
                }
                if ($kind === 'hod') {
                    $email->sendTAApproval($details);
                } elseif ($kind === 'ta') {
                    $email->sendGuideApproval($details);
                } elseif ($kind === 'guide') {
                    $email->sendLeaveApplication($details, 'guide');
                }
            } catch (Throwable $e) {
                logError("Routing-change notification ($kind) failed for leave $leaveId: " . $e->getMessage());
            }
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
                    'remarks' => $leave['admin_remarks'],
                    // A stored name, not a URL: link to it through
                    // api/files/download.php?doc=dean_ap, never by path.
                    'document_path' => $leave['admin_document_path'],
                    'has_document' => !empty($leave['admin_document_path']),
                    'approved_at' => $leave['admin_approved_at'],
                    'order' => 4
                ];
            }

            // A leave that ended outside the approval stages — cancelled, or
            // merged into a combined leave — gets a closing entry saying who
            // ended it, when and why. Without it the view showed only a bare
            // "Cancelled" badge above stages still marked pending.
            $outcome = $this->outcomeEntry($leave);
            if (in_array($leave['status'], ['cancelled', 'combined', 'rejected'], true)) {
                foreach ($approvalChain as &$step) {
                    if ($step['status'] === 'pending' || $step['status'] === null) {
                        $step['not_reached'] = true;
                    }
                }
                unset($step);
            }
            if ($outcome !== null) {
                $approvalChain[] = $outcome;
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
     * The closing entry for a cancelled or combined leave: who, when and why.
     *
     * Who comes from the audit log (system_logs, by "Ref ID"). Entries written
     * before 2026-09 recorded the STUDENT as the actor of an admin's
     * cancellation or combine (since fixed), so an actor who is the student
     * on an admin-only action is shown as "the office" rather than misnamed.
     */
    private function outcomeEntry(array $leave) {
        $status = $leave['status'];
        if ($status !== 'cancelled' && $status !== 'combined') {
            return null;
        }
        $leaveId = (int) $leave['id'];

        if ($status === 'cancelled') {
            $stmt = $this->db->prepare(
                "SELECT sl.user_id, sl.action, sl.created_at, u.name, u.role
                   FROM system_logs sl LEFT JOIN users u ON u.id = sl.user_id
                  WHERE sl.action IN ('admin_cancelled', 'leave_cancelled_no_guide')
                    AND sl.description REGEXP ?
                  ORDER BY sl.id DESC LIMIT 1");
            $stmt->execute(['Ref ID: ' . $leaveId . '([^0-9]|$)']);
            $log = $stmt->fetch() ?: null;
            $reason = trim((string) ($leave['admin_remarks'] ?? ''));
            $stage = 'Cancelled';
        } else {
            $successor = !empty($leave['combined_into_id']) ? (int) $leave['combined_into_id'] : null;
            if ($successor === null && preg_match_all('/Combined into leave #(\d+)/', (string) $leave['admin_remarks'], $m)) {
                $successor = (int) end($m[1]);
            }
            $log = null;
            if ($successor !== null) {
                $stmt = $this->db->prepare(
                    "SELECT sl.user_id, sl.action, sl.created_at, u.name, u.role
                       FROM system_logs sl LEFT JOIN users u ON u.id = sl.user_id
                      WHERE sl.action = 'leaves_combined' AND sl.description REGEXP ?
                      ORDER BY sl.id DESC LIMIT 1");
                $stmt->execute(['combined into leave #' . $successor . '([^0-9]|$)']);
                $log = $stmt->fetch() ?: null;
            }
            $reason = $successor !== null
                ? "Combined with the adjacent leave(s) into leave application #$successor, which now carries these days."
                : 'Combined into another leave application.';
            $stage = 'Combined';
        }

        // Only the office cancels or combines. The student as actor is the old
        // logging bug; no log at all predates the audit trail.
        if ($log && !empty($log['name']) && (int) $log['user_id'] !== (int) $leave['user_id']) {
            $who = $log['name'] . ' (' . ucfirst((string) $log['role']) . ')';
        } elseif ($log && $log['action'] === 'leave_cancelled_no_guide') {
            $who = 'Automatically, after a guide/TA change request was decided';
        } else {
            $who = 'The office (admin)';
        }

        return [
            'stage' => $stage,
            'approver_name' => $who,
            'approver_email' => null,
            'status' => $status,
            'remarks' => $reason !== '' ? $reason : null,
            'approved_at' => $log['created_at'] ?? ($leave['updated_at'] ?? null),
            'outcome' => true,
            'order' => 9
        ];
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
     * Start a decision: open a transaction, lock the leave row, and return the
     * application as it stands NOW.
     *
     * Only leave_applications is locked (MariaDB has no FOR UPDATE OF <table>,
     * and locking the joined users rows would serialise every approval by the
     * same guide for no reason). The lock is taken BEFORE the joined read, and
     * a REPEATABLE READ snapshot starts at a transaction's first plain read, so
     * the details below include whatever a competing approver committed while
     * this request was waiting for the lock.
     */
    private function lockLeave($leaveId) {
        $this->db->beginTransaction();
        $this->inDecision = true;

        $stmt = $this->db->prepare("SELECT id FROM leave_applications WHERE id = :leave_id FOR UPDATE");
        $stmt->execute([':leave_id' => $leaveId]);
        if (!$stmt->fetch()) {
            throw new Exception("Leave application not found");
        }

        $leave = $this->getLeaveDetails($leaveId);
        if (!$leave) {
            throw new Exception("Leave application not found");
        }
        return $leave;
    }

    /** Commit the open decision. */
    private function commitDecision() {
        $this->db->commit();
        $this->inDecision = false;
    }

    /**
     * Refuse a decision on an application that is no longer at this stage —
     * already decided by someone else, rejected, cancelled, or combined. This is
     * what turns a double submission into a clean error instead of a second
     * status change (and a second deduction).
     */
    private function requireStatus($leave, $expected, $stageLabel) {
        if ($leave['status'] !== $expected) {
            throw new Exception("This application is no longer awaiting $stageLabel approval (current status: "
                . str_replace('_', ' ', $leave['status']) . "). It may already have been processed; please refresh the page.");
        }
    }

    /**
     * Apply a decision's UPDATE, but only if the row is still at $fromStatus.
     * The row lock already guarantees this; the condition is a second line of
     * defence so that a decision can never be applied to a row that moved on.
     */
    private function transition($leaveId, $fromStatus, $setSql, array $params) {
        $sql = "UPDATE leave_applications
                SET $setSql,
                    updated_at = NOW()
                WHERE id = :leave_id AND status = :from_status";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + [
            ':leave_id' => $leaveId,
            ':from_status' => $fromStatus
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new Exception("This application has already been processed. Please refresh the page.");
        }
    }

    /**
     * Roll back whatever the failed decision had done and rethrow. Validation
     * errors (plain Exceptions) used to skip the rollback and leave the
     * transaction — and its row lock — open for the rest of the request.
     */
    private function abort(Throwable $e, $logPrefix, $userPrefix) {
        if ($this->inDecision && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $this->inDecision = false;
        if ($e instanceof PDOException) {
            logError("$logPrefix: " . $e->getMessage());
            throw new Exception("$userPrefix: " . $e->getMessage());
        }
        throw $e;
    }

    /**
     * Deduct leave balance after full approval
     *
     * Called only inside a decision's transaction, after the status has moved
     * to 'approved' under the row lock — so each leave is deducted exactly once.
     * Nothing is ever refunded on rejection or cancellation for the same reason:
     * only a leave that reached 'approved' was deducted, and those cannot be
     * rejected or cancelled here. The one balance change a rejection or
     * cancellation makes is the reverse: restoreCombinedSources() charges again
     * the approved leaves a combined leave had replaced.
     */
    private function deductLeaveBalance($leave, $actorId) {
        if (!in_array($leave['leave_type'], ['personal', 'medical', 'academic'], true)) {
            throw new Exception("Unknown leave type: " . $leave['leave_type']);
        }
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

        if ($stmt->rowCount() === 0) {
            // Approval still stands (a missing balance row is an admin fix, not
            // the approver's problem), but it must not pass silently.
            logError("No leave_balances row for student_id {$leave['student_id']}; leave {$leave['id']} approved without a deduction");
        }

        // Log the balance deduction
        $this->logAction($actorId, 'leave_balance_deducted', $leave['id'],
            "Deducted {$leave['total_days']} days from {$leave['leave_type']} leave balance of student "
            . $this->studentLabel($leave));
    }

    /**
     * Send notification (stub method for now)
     * TODO: Implement email notification using EmailService
     *
     * The real emails are sent by api/approvals/process.php after the decision
     * commits. This only records the intent, under the ACTOR's id: the old entry
     * was filed under the recipient, so it read as something they had done.
     */
    private function sendNotification($actorId, $recipientUserId, $notificationType, $leaveId, $role = null) {
        $recipient = $role ? "role=$role" : "user #" . (int) $recipientUserId;
        $this->logAction($actorId, 'notification_queued', $leaveId,
            "Notification queued: Type=$notificationType, LeaveID=$leaveId, Recipient=$recipient");

        return true;
    }

    /**
     * Cancel any pending leave by Admin
     * Admin can cancel leaves at any stage in the approval process
     *
     * @param int $adminId users.id of the admin making the decision
     */
    public function cancelByAdmin($leaveId, $adminId, $remarks) {
        try {
            if (empty($remarks)) {
                throw new Exception("Cancellation remarks are required");
            }

            $leave = $this->lockLeave($leaveId);

            // Only a pending leave can be cancelled. Checked under the row lock, so
            // an approval that commits first turns this into an error rather than
            // cancelling a leave whose days have just been deducted. Pending leaves
            // were never deducted, so there is nothing to refund.
            $pendingStatuses = Leave::PENDING_STATUSES;
            if (!in_array($leave['status'], $pendingStatuses, true)) {
                throw new Exception("Only pending leaves can be cancelled. Current status: " . $leave['status']);
            }

            // Update leave application to cancelled status
            $this->transition($leaveId, $leave['status'],
                "status = 'cancelled',
                 admin_remarks = :remarks,
                 admin_approved_at = NOW()", [
                ':remarks' => $remarks,
            ]);

            $restored = $this->restoreCombinedSources($leaveId, $adminId, 'cancelled');

            // Log action
            $this->logAction($adminId, 'admin_cancelled', $leaveId,
                "Admin cancelled leave application ID $leaveId for student " . $this->studentLabel($leave)
                . ". Previous status: {$leave['status']}");

            // Send notification to student
            $this->sendNotification($adminId, $leave['user_id'], 'leave_cancelled', $leaveId);

            $this->commitDecision();

            return [
                'success' => true,
                'message' => 'Leave application cancelled successfully' . self::restoredNote($restored),
                'previous_status' => $leave['status'],
                'restored_leave_ids' => $restored
            ];

        } catch (Throwable $e) {
            $this->abort($e, 'Admin cancellation failed', 'Failed to cancel leave');
        }
    }

    /**
     * The leave just rejected or cancelled may be one that Leave::combineLeaves()
     * made from approved leaves, whose days it refunded because the combined
     * leave was to be charged instead. Those leaves go back to 'approved' and
     * are charged again, in this decision's transaction and under the row lock
     * lockLeave() holds on the combined leave. Called from every reject/cancel
     * path, after the status change.
     *
     * @return int[] the leaves restored
     */
    private function restoreCombinedSources($leaveId, $actorId, $outcome) {
        return (new Leave($this->db))->restoreCombinedSources($leaveId, $actorId, $outcome);
    }

    /** Sentence for a decision's message naming the leaves restoreCombinedSources() put back. */
    private static function restoredNote(array $restored) {
        return $restored
            ? '. Leave(s) #' . implode(', #', $restored) . ' that had been combined into it are approved again'
              . ' and their days deducted.'
            : '';
    }

    /** "Name (roll)" for audit descriptions, which are filed under the actor. */
    private function studentLabel($leave) {
        return $leave['student_name'] . (!empty($leave['roll_number']) ? " ({$leave['roll_number']})" : '')
             . " [user #" . (int) $leave['user_id'] . "]";
    }

    /**
     * Log action to system logs
     *
     * $actorId is the users.id of whoever made the decision — guide, TA, HOD or
     * admin. HOD and admin decisions used to be filed under the STUDENT's id, so
     * the audit trail showed students approving their own leave. Routed through
     * logSystemAction() so an impersonated session is annotated with the real
     * admin behind it.
     */
    private function logAction($actorId, $action, $referenceId, $description) {
        logSystemAction($actorId, $action, 'approvals', $description . " (Ref ID: $referenceId)");
    }
}
