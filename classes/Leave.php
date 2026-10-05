<?php
/**
 * Leave Class
 * Handles all leave application and balance operations
 */

// Working-day overrides (a weekend declared working) live in Holiday; the
// adjacency checks below must use the same rule as everything else.
require_once __DIR__ . '/Holiday.php';

class Leave {
    /**
     * Every status in which an application is still in flight — it counts
     * against the balance, shows as pending on a dashboard, and is not a
     * finished decision.
     *
     * This list was copy-pasted across eight files and had to be edited in all
     * of them at once to stay correct; 'pending_routing' is the fifth member and
     * the first added since. Keep it here so a sixth cannot be half-applied.
     * Note this is NOT the set a guide or TA is waiting on — see
     * cron/daily_pending_reminder.php, which deliberately reminds only about
     * pending_guide and pending_ta.
     */
    const PENDING_STATUSES = [
        'pending_routing', 'pending_guide', 'pending_ta', 'pending_hod', 'pending_admin'
    ];

    /** The same list as a quoted SQL fragment for IN (...) clauses. */
    public static function pendingStatusSql() {
        return "'" . implode("','", self::PENDING_STATUSES) . "'";
    }

    /**
     * Every status in which an application still occupies its dates: in flight
     * or approved. A new application may not overlap one of these, and only
     * these can be combined. Rejected, cancelled and combined applications are
     * finished with and hold nothing.
     */
    const LIVE_STATUSES = [...self::PENDING_STATUSES, 'approved'];

    /** LIVE_STATUSES as a quoted SQL fragment for IN (...) clauses. */
    public static function liveStatusSql() {
        return "'" . implode("','", self::LIVE_STATUSES) . "'";
    }

    /** Balances are topped up on these days (MM-DD) — see resetBalances(). */
    const RESET_MONTH_DAYS = ['01-01', '07-01'];

    private $db;
    private $holiday;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Apply for leave
     *
     * Runs in the caller's transaction when there is one (apply_leave.php opens
     * one so a guide/TA change request raised with the application is kept or
     * discarded together with it), otherwise in its own. A caller that owns the
     * transaction should take lockStudent() as its FIRST statement and retry
     * once on isDeadlock(); database errors reach it unchanged.
     */
    public function apply($studentId, $data) {
        if (!in_array($data['leave_type'] ?? null, ['personal', 'medical', 'academic'], true)) {
            throw new Exception("Invalid leave type");
        }
        // Validate dates
        if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
            throw new Exception("End date cannot be before start date");
        }

        if ($this->db->inTransaction()) {
            return $this->applyLocked($studentId, $data);
        }
        return $this->inOwnTransaction(function () use ($studentId, $data) {
            return $this->applyLocked($studentId, $data);
        }, 'Leave application failed', 'Failed to submit leave application');
    }

    private function applyLocked($studentId, $data) {
        // The student row is the per-student mutex (see lockStudent): a
        // double-clicked submit, or two tabs, would otherwise both pass the
        // overlap check below and insert the same leave twice, and each copy is
        // deducted once approved.
        $student = $this->lockStudent($studentId);
        if (!$student) {
            throw new Exception("Student record not found");
        }

        // One day can only be taken as leave once. Overlapping applications
        // were accepted before and each was deducted on approval, so the
        // student paid twice for the same days. A plain read: every writer of
        // this student's leaves holds the student lock, so it is enough — and a
        // locking read here took gap locks that two DIFFERENT students applying
        // at the same moment could deadlock on.
        $overlap = $this->findOverlappingLeave($studentId, $data['start_date'], $data['end_date']);
        if ($overlap) {
            throw new Exception(sprintf(
                "These dates overlap your existing %s leave application #%d (%s to %s, %s). "
                . "Check your Leave History; ask the office to cancel that application first if it needs changing.",
                $overlap['leave_type'], $overlap['id'],
                date('d M Y', strtotime($overlap['start_date'])),
                date('d M Y', strtotime($overlap['end_date'])),
                self::statusLabel($overlap['status'])
            ));
        }

        // Check for adjacent leaves (detect gaming by splitting contiguous periods)
        $adjacentLeave = $this->findAdjacentLeave($studentId, $data['start_date'], $data['end_date']);
        $adjacentLeaveId = $adjacentLeave ? $adjacentLeave['id'] : null;

        // Calculate working days
        $totalDays = $this->calculateWorkingDays($data['start_date'], $data['end_date']);

        // Check eligibility for academic leave
        if ($data['leave_type'] === 'academic') {
            if (!$this->checkAcademicLeaveEligibility($studentId)) {
                throw new Exception("You are not eligible for academic leave. Must be in 2nd year or above.");
            }
        }

        // Check if sufficient balance exists
        $balance = $this->getBalance($studentId);
        $pendingDays = $this->getPendingLeaveDays($studentId, $data['leave_type']);

        $leaveField = $data['leave_type'] . '_leaves';
        $availableBalance = $balance[$leaveField] ?? 0;

        // Note: Negative balances are allowed as per requirements
        // But we should warn the user
        $warningMessage = null;
        if (($availableBalance - $pendingDays - $totalDays) < 0) {
            $warningMessage = "This will result in negative leave balance";
        }

        // A guide/TA correction raised with this application holds it at
        // pending_routing until the admin decides. The assignment check below
        // is waived in that case: asking for the assignment to be corrected is
        // exactly how a student who has none is supposed to get moving, so
        // refusing them here would be a dead end.
        //
        // The request id comes from the form, read before this transaction; the
        // admin may have decided it since. Holding an application on a decided
        // request would hold it forever — nothing releases it again — so it is
        // re-read under a lock and, if no longer pending, the application is
        // routed normally instead.
        $routingRequestId = !empty($data['routing_request_id'])
            ? (int) $data['routing_request_id'] : null;
        $routingNote = '';
        if ($routingRequestId && !$this->lockPendingRoutingRequest($routingRequestId, $studentId)) {
            $routingNote = " (guide/TA change request ID $routingRequestId was already decided; routed normally)";
            $routingRequestId = null;
        }

        if (!$routingRequestId) {
            if (empty($student['faculty_guide_id'])) {
                throw new Exception("Cannot apply for leave: No Faculty Guide assigned. Please contact the admin.");
            }
            if (empty($student['ta_coordinator_id'])) {
                throw new Exception("Cannot apply for leave: No TA Coordinator assigned. Please contact the admin.");
            }
        }

        // Always enter at the guide stage. When one person is both guide and
        // TA their single approval settles the TA stage too, but that happens
        // in Approval::approveByGuide once they have actually approved —
        // not by pre-approving the guide stage here. The difference matters:
        // the supervisor's own decision is what ends up recorded, rather than
        // a stage the system filled in on their behalf before they saw it.
        $initialStatus = $routingRequestId ? 'pending_routing' : 'pending_guide';
        $guideStatus = 'pending';
        $taStatus = 'pending';

        // Insert leave application
        $sql = "INSERT INTO leave_applications
                (student_id, leave_type, start_date, end_date, total_days, reason, status, guide_status, ta_status, hod_status, adjacent_leave_id, supporting_document, routing_request_id)
                VALUES (:student_id, :leave_type, :start_date, :end_date, :total_days, :reason, :status, :guide_status, :ta_status, 'pending', :adjacent_leave_id, :supporting_document, :routing_request_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':student_id' => $studentId,
            ':leave_type' => $data['leave_type'],
            ':start_date' => $data['start_date'],
            ':end_date' => $data['end_date'],
            ':total_days' => $totalDays,
            ':reason' => $data['reason'],
            ':status' => $initialStatus,
            ':guide_status' => $guideStatus,
            ':ta_status' => $taStatus,
            ':adjacent_leave_id' => $adjacentLeaveId,
            ':supporting_document' => $data['supporting_document'] ?? null,
            ':routing_request_id' => $routingRequestId
        ]);

        $leaveId = $this->db->lastInsertId();

        // Log action
        $logMessage = "Leave application ID $leaveId created with status $initialStatus";
        if ($adjacentLeaveId) {
            $logMessage .= " (adjacent to leave ID $adjacentLeaveId)";
        }
        if ($routingRequestId) {
            $logMessage .= " (held pending guide/TA change request ID $routingRequestId)";
        }
        $this->logAction($student['user_id'], 'leave_applied', $logMessage . $routingNote . " (Ref ID: $leaveId)");

        return [
            'success' => true,
            'leave_id' => $leaveId,
            'total_days' => $totalDays,
            'warning' => $warningMessage,
            'adjacent_leave_id' => $adjacentLeaveId,
            'held_for_routing' => $routingRequestId !== null
        ];
    }

    /**
     * Lock a student's row for the rest of the current transaction and return it.
     *
     * This row is the per-student mutex for everything that creates or reshapes
     * that student's leaves or balance: apply(), combineLeaves(), the balance
     * reset, GuideTAChange::create(). Each takes it FIRST, so they serialise on
     * it and cannot deadlock one another. Take it before any plain SELECT in the
     * transaction: a REPEATABLE READ snapshot starts at the first plain read,
     * and one started before the lock would not see what the previous holder
     * committed.
     */
    public function lockStudent($studentId) {
        $stmt = $this->db->prepare("SELECT id, user_id, roll_number, faculty_guide_id, ta_coordinator_id
                                    FROM students WHERE id = :student_id FOR UPDATE");
        $stmt->execute([':student_id' => $studentId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lock guide/TA change request $requestId and say whether it is still
     * pending for this student. Locked by primary key (a record lock, no gap
     * locks), so GuideTAChange::decide() — which locks the same row first —
     * cannot decide it between this check and the caller's commit and leave an
     * application held on a request that is already settled. (decide()'s
     * approve path takes this row and then the student row, the opposite order;
     * the rare deadlock that allows is retried by the transaction's owner.)
     */
    private function lockPendingRoutingRequest($requestId, $studentId) {
        $stmt = $this->db->prepare("SELECT student_id, status FROM guide_ta_change_requests
                                    WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => (int) $requestId]);
        $request = $stmt->fetch();
        return $request && (int) $request['student_id'] === (int) $studentId && $request['status'] === 'pending';
    }

    /**
     * Was this a deadlock (MySQL/MariaDB error 1213, SQLSTATE 40001)? The
     * server has already rolled the whole transaction back, so whoever owns the
     * transaction can run it again from the start.
     */
    public static function isDeadlock(Throwable $e) {
        return $e instanceof PDOException
            && ($e->getCode() === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213);
    }

    /**
     * Run $work in a transaction of its own; run it once more if the first
     * attempt was chosen as a deadlock victim. A database error is logged and
     * replaced by $userMessage (or rethrown as it is when that is null).
     */
    private function inOwnTransaction(callable $work, $logPrefix, $userMessage) {
        for ($attempt = 1; ; $attempt++) {
            $this->db->beginTransaction();
            try {
                $result = $work();
                $this->db->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ($attempt === 1 && self::isDeadlock($e)) {
                    continue;
                }
                if ($e instanceof PDOException) {
                    logError("$logPrefix: " . $e->getMessage());
                    if ($userMessage !== null) {
                        throw new Exception($userMessage);
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * A live (pending or approved) application of this student's that shares at
     * least one day with [$startDate, $endDate], or null.
     *
     * A plain read. Inside a transaction, call it under lockStudent() taken
     * before any other read, so it sees everything committed for this student.
     *
     * @param array $excludeIds leave ids to ignore (the ones being combined)
     */
    public function findOverlappingLeave($studentId, $startDate, $endDate, array $excludeIds = []) {
        $sql = "SELECT id, leave_type, start_date, end_date, status
                FROM leave_applications
                WHERE student_id = :student_id
                AND status IN (" . self::liveStatusSql() . ")
                AND start_date <= :end_date AND end_date >= :start_date";
        $excludeIds = array_map('intval', $excludeIds);
        if ($excludeIds) {
            $sql .= " AND id NOT IN (" . implode(',', $excludeIds) . ")";
        }
        $sql .= " ORDER BY start_date, id LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':student_id' => $studentId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetch() ?: null;
    }

    /** Plain-words status for messages shown to students and admins. */
    private static function statusLabel($status) {
        $labels = [
            'pending_routing' => 'awaiting the office\'s guide/TA decision',
            'pending_guide' => 'pending with the Faculty Guide',
            'pending_ta' => 'pending with the TA Coordinator',
            'pending_hod' => 'pending with the HOD',
            'pending_admin' => 'pending with the Admin',
        ];
        return $labels[$status] ?? $status;
    }

    /**
     * Calculate total leave days between two dates (including weekends and holidays)
     * All calendar days are counted when they fall within the leave period
     *
     * Despite the name this is a calendar-day count by policy, so working-day
     * overrides do not enter into it; they matter only for the gaps BETWEEN
     * leaves (isGapOnlyWeekendsOrHolidays).
     */
    public function calculateWorkingDays($startDate, $endDate) {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        // Calculate total calendar days (inclusive of both start and end)
        $interval = $start->diff($end);
        return $interval->days + 1;
    }

    /**
     * Check if new leave dates are adjacent to existing leaves (only separated by weekends/holidays)
     * This prevents gaming the system by splitting contiguous leaves
     * Returns the adjacent leave if found, null otherwise
     *
     * @param array $excludeIds leave ids to ignore (the ones being combined)
     */
    public function findAdjacentLeave($studentId, $startDate, $endDate, array $excludeIds = []) {
        // Get all active leaves (pending or approved) for this student. A
        // 'combined' leave is superseded by the leave it was merged into and
        // must not be pointed at: the admin could never combine with it.
        $sql = "SELECT id, start_date, end_date, leave_type, status
                FROM leave_applications
                WHERE student_id = :student_id
                AND status IN (" . self::liveStatusSql() . ")";
        $excludeIds = array_map('intval', $excludeIds);
        if ($excludeIds) {
            $sql .= " AND id NOT IN (" . implode(',', $excludeIds) . ")";
        }
        $sql .= " ORDER BY start_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':student_id' => $studentId]);
        $existingLeaves = $stmt->fetchAll();

        $newStart = new DateTime($startDate);
        $newEnd = new DateTime($endDate);

        foreach ($existingLeaves as $leave) {
            $existingStart = new DateTime($leave['start_date']);
            $existingEnd = new DateTime($leave['end_date']);

            // Check if new leave starts right after existing leave ends (with only weekends/holidays in between)
            if ($newStart > $existingEnd) {
                $gapStart = clone $existingEnd;
                $gapStart->modify('+1 day');

                if ($this->isGapOnlyWeekendsOrHolidays($gapStart, $newStart)) {
                    return $leave;
                }
            }

            // Check if new leave ends right before existing leave starts (with only weekends/holidays in between)
            if ($newEnd < $existingStart) {
                $gapStart = clone $newEnd;
                $gapStart->modify('+1 day');

                if ($this->isGapOnlyWeekendsOrHolidays($gapStart, $existingStart)) {
                    return $leave;
                }
            }

            // Check for overlapping dates
            if ($newStart <= $existingEnd && $newEnd >= $existingStart) {
                return $leave;
            }
        }

        return null;
    }

    /**
     * Check if the gap between two dates consists only of weekends and holidays
     * $gapStart is the day after the first leave ends
     * $gapEnd is the start of the next leave
     */
    private function isGapOnlyWeekendsOrHolidays($gapStart, $gapEnd) {
        // If gap is 0 or negative days, they're directly adjacent
        if ($gapStart >= $gapEnd) {
            return true;
        }

        // One query for the whole gap. The last gap day is the day before $gapEnd.
        $lastGapDay = clone $gapEnd;
        $lastGapDay->modify('-1 day');
        $overrides = $this->holiday()->getDayOverrides($gapStart->format('Y-m-d'), $lastGapDay->format('Y-m-d'));

        $current = clone $gapStart;
        while ($current < $gapEnd) {
            // A working day in the gap (an ordinary weekday, or a weekend the
            // office declared a working day) means the two leaves are genuinely
            // separate. The old check only asked "weekday and not a holiday", so
            // a Friday + Monday split around a working Saturday was flagged.
            if (Holiday::isWorkingDateWith($current->format('Y-m-d'), $overrides)) {
                return false;
            }

            $current->modify('+1 day');
        }

        return true;
    }

    private function holiday() {
        if ($this->holiday === null) {
            $this->holiday = new Holiday($this->db);
        }
        return $this->holiday;
    }

    /**
     * Check academic leave eligibility
     */
    private function checkAcademicLeaveEligibility($studentId) {
        $sql = "SELECT joining_date FROM students WHERE id = :student_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':student_id' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            return false;
        }

        $joiningTimestamp = strtotime($student['joining_date']);
        $eligibilityDate = strtotime('-' . ACADEMIC_LEAVE_ELIGIBILITY_MONTHS . ' months');

        return $joiningTimestamp <= $eligibilityDate;
    }

    /**
     * Get leave balance for student
     */
    public function getBalance($studentId) {
        $sql = "SELECT * FROM leave_balances WHERE student_id = :student_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':student_id' => $studentId]);
        return $stmt->fetch();
    }

    /**
     * Get pending leave days: days applied for and still in flight, which the
     * balance does not reflect yet (a leave is deducted only when it reaches
     * 'approved'). Cancelled, rejected and combined applications are excluded —
     * a combined one's days are carried by the leave it was merged into.
     */
    public function getPendingLeaveDays($studentId, $leaveType = null) {
        $sql = "SELECT SUM(total_days) as pending_days FROM leave_applications
                WHERE student_id = :student_id
                AND status IN (" . self::pendingStatusSql() . ")";

        if ($leaveType) {
            $sql .= " AND leave_type = :leave_type";
        }

        $stmt = $this->db->prepare($sql);
        $params = [':student_id' => $studentId];

        if ($leaveType) {
            $params[':leave_type'] = $leaveType;
        }

        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result['pending_days'] ?? 0;
    }

    /**
     * Number of applications still in flight (PENDING_STATUSES only), for one
     * student or for everyone. For dashboard counters: counting everything that
     * is not approved/rejected also counts cancelled and combined applications,
     * and counting over a "recent" slice misses older pending ones.
     */
    public function countPendingApplications($studentId = null) {
        $sql = "SELECT COUNT(*) FROM leave_applications
                WHERE status IN (" . self::pendingStatusSql() . ")";
        $params = [];
        if ($studentId !== null) {
            $sql .= " AND student_id = :student_id";
            $params[':student_id'] = $studentId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get leave application by ID
     */
    public function getById($leaveId) {
        $sql = "SELECT la.*, s.user_id, s.roll_number, s.faculty_guide_id, s.ta_coordinator_id,
                       u.name as student_name, u.email as student_email,
                       fg.name as guide_name, fg.email as guide_email,
                       tc.name as ta_coordinator_name, tc.email as ta_coordinator_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN users fg ON s.faculty_guide_id = fg.id
                LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
                WHERE la.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $leaveId]);
        return $stmt->fetch();
    }

    /**
     * Get leave applications for student
     */
    public function getByStudent($studentId, $filters = []) {
        $sql = "SELECT * FROM leave_applications WHERE student_id = :student_id";

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
        }

        if (!empty($filters['leave_type'])) {
            $sql .= " AND leave_type = :leave_type";
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $params = [':student_id' => $studentId];

        if (!empty($filters['status'])) {
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['leave_type'])) {
            $params[':leave_type'] = $filters['leave_type'];
        }

        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get pending approvals for faculty
     */
    public function getPendingForFaculty($facultyId, $role) {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE ";

        if ($role === 'guide') {
            $sql .= "s.faculty_guide_id = :faculty_id AND la.status = 'pending_guide'";
        } else if ($role === 'ta_coordinator') {
            $sql .= "s.ta_coordinator_id = :faculty_id AND la.status = 'pending_ta'";
        } else {
            // Any other role left a bare "WHERE ORDER BY" — a SQL error.
            return [];
        }

        $sql .= " ORDER BY la.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':faculty_id' => $facultyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get approval/rejection history for faculty (leaves already acted on)
     */
    public function getHistoryForFaculty($facultyId, $role) {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE ";

        if ($role === 'guide') {
            $sql .= "s.faculty_guide_id = :faculty_id AND la.guide_status IN ('approved', 'rejected')";
        } else if ($role === 'ta_coordinator') {
            $sql .= "s.ta_coordinator_id = :faculty_id AND la.ta_status IN ('approved', 'rejected')";
        }

        $sql .= " ORDER BY la.updated_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':faculty_id' => $facultyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get pending approvals for HOD
     */
    public function getPendingForHOD() {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE la.status = 'pending_hod'
                ORDER BY la.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get pending admin approvals
     */
    public function getPendingForAdmin() {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE la.status = 'pending_admin'
                ORDER BY la.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Update leave balance
     */
    public function updateBalance($studentId, $leaveType, $amount) {
        $field = $leaveType . '_leaves';

        $sql = "UPDATE leave_balances
                SET $field = $field + :amount
                WHERE student_id = :student_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':amount' => $amount,
            ':student_id' => $studentId
        ]);
    }

    /**
     * Adjust student leave balance with remarks (admin operation)
     * Inserts into leave_adjustments and updates leave_balances in a transaction
     */
    public function adjustBalance($studentId, $leaveType, $amount, $remarks, $adjustedBy) {
        try {
            $this->db->beginTransaction();

            $sql = "INSERT INTO leave_adjustments (student_id, leave_type, adjustment_amount, remarks, adjusted_by)
                    VALUES (:student_id, :leave_type, :amount, :remarks, :adjusted_by)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':leave_type' => $leaveType,
                ':amount' => $amount,
                ':remarks' => $remarks,
                ':adjusted_by' => $adjustedBy
            ]);

            $this->updateBalance($studentId, $leaveType, $amount);

            $this->db->commit();
            return true;

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Leave balance adjustment failed: " . $e->getMessage());
            throw new Exception("Failed to adjust leave balance");
        }
    }

    /**
     * Get leave adjustments for a student
     */
    public function getAdjustments($studentId) {
        $sql = "SELECT la.*, u.name as adjusted_by_name
                FROM leave_adjustments la
                LEFT JOIN users u ON la.adjusted_by = u.id
                WHERE la.student_id = :student_id
                ORDER BY la.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Deduct leave balance
     */
    public function deductLeaveBalance($leaveId) {
        $leave = $this->getById($leaveId);

        if ($leave) {
            $field = $leave['leave_type'] . '_leaves';

            $sql = "UPDATE leave_balances
                    SET $field = $field - :days
                    WHERE student_id = :student_id";

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':days' => $leave['total_days'],
                ':student_id' => $leave['student_id']
            ]);
        }

        return false;
    }

    /**
     * The latest 1 January or 1 July on or before $today (Y-m-d).
     */
    public static function mostRecentResetDate($today = null) {
        $today = $today ?? date('Y-m-d');
        $year = substr($today, 0, 4);
        return substr($today, 5) >= '07-01' ? "$year-07-01" : "$year-01-01";
    }

    /**
     * Throw unless $resetDate may be applied now: a real 1 January or 1 July,
     * not in the future, and the MOST RECENT one. An older half-year applied
     * after a newer one would top up again every row the newer run skipped
     * (NULL last_reset, or created in between), and nothing needs it: a missed
     * run is caught up by applying the latest date, once.
     */
    public static function assertResetDate($resetDate, $today = null) {
        $d = DateTime::createFromFormat('!Y-m-d', (string) $resetDate);
        if (!$d || $d->format('Y-m-d') !== $resetDate) {
            throw new Exception("Invalid reset date '$resetDate': expected YYYY-MM-DD");
        }
        if (!in_array(substr($resetDate, 5), self::RESET_MONTH_DAYS, true)) {
            throw new Exception("$resetDate is not a reset date: balances reset on 1 January and 1 July only");
        }
        $today = $today ?? date('Y-m-d');
        if ($resetDate > $today) {
            throw new Exception("$resetDate is in the future (today is $today)");
        }
        $latest = self::mostRecentResetDate($today);
        if ($resetDate !== $latest) {
            throw new Exception("$resetDate is not the most recent reset date ($latest); "
                . "an older half-year cannot be applied after a newer one");
        }
    }

    /**
     * What resetBalances($resetDate) would do, student by student, without
     * changing anything: every active student with at least one balance still
     * due for $resetDate, with before and after figures.
     *
     * @return array rows: student_id, user_id, roll_number, name, email,
     *   joining_date, before_{personal,medical,academic},
     *   after_{personal,medical,academic}, do_{personal,medical,academic},
     *   academic_eligible
     */
    public function previewReset($resetDate) {
        self::assertResetDate($resetDate);

        // "Due" per balance: its last_reset is NULL or earlier than this reset.
        // last_reset_* is set to the reset date by a run and to the account's
        // creation date by User::initializeLeaveBalance, so a student created
        // on or after $resetDate (who got their allocation at creation) and a
        // student already reset for $resetDate are both left alone. This is
        // what makes a second run — or a catch-up after a partial one — safe.
        $sql = "SELECT lb.student_id, s.user_id, s.roll_number, s.joining_date, u.name, u.email,
                       lb.personal_leaves AS before_personal,
                       lb.medical_leaves AS before_medical,
                       lb.academic_leaves AS before_academic,
                       (lb.last_reset_personal IS NULL OR lb.last_reset_personal < :d1) AS do_personal,
                       (lb.last_reset_medical IS NULL OR lb.last_reset_medical < :d2) AS do_medical,
                       (s.joining_date <= DATE_SUB(:d3, INTERVAL :m1 MONTH)
                        AND (lb.last_reset_academic IS NULL OR lb.last_reset_academic < :d4)) AS do_academic,
                       (s.joining_date <= DATE_SUB(:d5, INTERVAL :m2 MONTH)) AS academic_eligible
                FROM leave_balances lb
                JOIN students s ON lb.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE u.is_active = 1
                AND (lb.last_reset_personal IS NULL OR lb.last_reset_personal < :d6
                     OR lb.last_reset_medical IS NULL OR lb.last_reset_medical < :d7
                     OR (s.joining_date <= DATE_SUB(:d8, INTERVAL :m3 MONTH)
                         AND (lb.last_reset_academic IS NULL OR lb.last_reset_academic < :d9)))
                ORDER BY s.roll_number";

        $params = [];
        foreach (range(1, 9) as $i) {
            $params[":d$i"] = $resetDate;
        }
        foreach (range(1, 3) as $i) {
            $params[":m$i"] = ACADEMIC_LEAVE_ELIGIBILITY_MONTHS;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        while ($row = $stmt->fetch()) {
            foreach (['do_personal', 'do_medical', 'do_academic', 'academic_eligible'] as $flag) {
                $row[$flag] = (bool) $row[$flag];
            }
            $row['after_personal'] = $row['do_personal']
                ? $row['before_personal'] + PERSONAL_LEAVE_ALLOCATION : (float) $row['before_personal'];
            $row['after_medical'] = $row['do_medical']
                ? $row['before_medical'] + MEDICAL_LEAVE_ALLOCATION : (float) $row['before_medical'];
            $row['after_academic'] = $row['do_academic']
                ? (float) ACADEMIC_LEAVE_ALLOCATION : (float) $row['before_academic'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Reset leave balances for the half-year starting $resetDate (called by
     * cron/reset_leave_balances.php). Only active students are touched.
     *
     * Personal and medical carry forward (+allocation); academic does not
     * (set to the allocation, for students past the eligibility period AS OF
     * $resetDate — not as of whenever the run happens, which matters for a
     * catch-up run weeks later).
     *
     * Idempotent per row: a balance is updated only if its last_reset is NULL or
     * before $resetDate, and is stamped with $resetDate in the same statement.
     * The first production run (1 July 2026) never happened because PHP was on
     * UTC and saw 30 June, and the only "already ran" guard the cron had was a
     * system_logs row written after the email loop — so re-running after any
     * failure would have given everyone a double allocation.
     *
     * The 'cron_leave_reset' system_logs row is written in the same transaction,
     * so it exists exactly when the balances changed.
     *
     * Runs in the caller's transaction if there is one (so it can be exercised
     * inside a transaction that is rolled back), otherwise in its own, retried
     * once if it is chosen as a deadlock victim.
     *
     * @return array ['reset_date', 'personal', 'medical', 'academic' => rows
     *   updated, 'students' => previewReset() rows for the students updated]
     */
    public function resetBalances($resetDate) {
        self::assertResetDate($resetDate);

        if ($this->db->inTransaction()) {
            return $this->resetBalancesLocked($resetDate);
        }
        try {
            return $this->inOwnTransaction(function () use ($resetDate) {
                return $this->resetBalancesLocked($resetDate);
            }, 'Leave balance reset failed', null);
        } catch (Exception $e) {
            if (!$e instanceof PDOException) {
                logError("Leave balance reset failed: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function resetBalancesLocked($resetDate) {
        // Lock order: every students row (in id order), then every balance row
        // — the order apply() and combineLeaves() take them in (their student
        // first, then its leaves and balance). The reset used to lock the
        // balances first and then read students through its UPDATE ... JOIN,
        // while combineLeaves() held a student and waited for that student's
        // balance: a deadlock (reproduced, ERROR 1213). All students, not only
        // active ones, so no other lock on a students row is needed later.
        $this->db->query("SELECT id FROM students ORDER BY id FOR UPDATE")->fetchAll();
        $this->db->query("SELECT id FROM leave_balances ORDER BY id FOR UPDATE")->fetchAll();

        // The first plain read, after the locks: it sees everything committed
        // before them, and nothing it relies on can change until commit.
        $students = $this->previewReset($resetDate);

        // The UPDATEs touch leave_balances only, by student id: joining
        // students/users here would take shared locks on those rows as well.
        // The last_reset guard stays in each statement, so the reset stays
        // idempotent per row however it is called.
        $ids = function ($flag) use ($students) {
            $list = array_map(fn($row) => (int) $row['student_id'],
                array_filter($students, fn($row) => $row[$flag]));
            return $list ? implode(',', $list) : '0';
        };

        // Update personal leaves (carry forward) - only for active students
        $stmt = $this->db->prepare("UPDATE leave_balances
                SET personal_leaves = personal_leaves + :allocation,
                    last_reset_personal = :reset_date
                WHERE student_id IN (" . $ids('do_personal') . ")
                AND (last_reset_personal IS NULL OR last_reset_personal < :reset_date_2)");
        $stmt->execute([
            ':allocation' => PERSONAL_LEAVE_ALLOCATION,
            ':reset_date' => $resetDate,
            ':reset_date_2' => $resetDate
        ]);
        $personal = $stmt->rowCount();

        // Update medical leaves (carry forward) - only for active students
        $stmt = $this->db->prepare("UPDATE leave_balances
                SET medical_leaves = medical_leaves + :allocation,
                    last_reset_medical = :reset_date
                WHERE student_id IN (" . $ids('do_medical') . ")
                AND (last_reset_medical IS NULL OR last_reset_medical < :reset_date_2)");
        $stmt->execute([
            ':allocation' => MEDICAL_LEAVE_ALLOCATION,
            ':reset_date' => $resetDate,
            ':reset_date_2' => $resetDate
        ]);
        $medical = $stmt->rowCount();

        // Update academic leaves (NO carry forward - reset to 25 for students
        // eligible as of $resetDate, as previewReset() worked out)
        $stmt = $this->db->prepare("UPDATE leave_balances
                SET academic_leaves = :allocation,
                    last_reset_academic = :reset_date
                WHERE student_id IN (" . $ids('do_academic') . ")
                AND (last_reset_academic IS NULL OR last_reset_academic < :reset_date_2)");
        $stmt->execute([
            ':allocation' => ACADEMIC_LEAVE_ALLOCATION,
            ':reset_date' => $resetDate,
            ':reset_date_2' => $resetDate
        ]);
        $academic = $stmt->rowCount();

        // Written here, not after the emails: this row is the record that the
        // balances changed, and must commit or roll back with them. The cron
        // has no session, so there is no actor (user_id NULL).
        $stmt = $this->db->prepare("INSERT INTO system_logs (user_id, action, module, description)
                                    VALUES (NULL, 'cron_leave_reset', 'cron', :description)");
        $stmt->execute([
            ':description' => "Leave balances reset for $resetDate: personal +" . PERSONAL_LEAVE_ALLOCATION
                . " for $personal student(s), medical +" . MEDICAL_LEAVE_ALLOCATION
                . " for $medical, academic set to " . ACADEMIC_LEAVE_ALLOCATION . " for $academic"
        ]);

        return [
            'reset_date' => $resetDate,
            'personal' => $personal,
            'medical' => $medical,
            'academic' => $academic,
            'students' => $students
        ];
    }

    /**
     * Get all leave applications (for admin/reports)
     */
    public function getAll($filters = [], $limit = null, $offset = 0) {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND la.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['leave_type'])) {
            $sql .= " AND la.leave_type = :leave_type";
            $params[':leave_type'] = $filters['leave_type'];
        }

        if (!empty($filters['student_id'])) {
            $sql .= " AND la.student_id = :student_id";
            $params[':student_id'] = $filters['student_id'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND la.start_date >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND la.end_date <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }

        // Leaves touching any day of [overlaps_from, overlaps_to]. The
        // start_date/end_date filters above only match leaves fully inside a
        // range, so a leave spanning the boundary would be dropped from both
        // adjacent periods.
        if (!empty($filters['overlaps_from']) && !empty($filters['overlaps_to'])) {
            $sql .= " AND la.start_date <= :overlaps_to AND la.end_date >= :overlaps_from";
            $params[':overlaps_from'] = $filters['overlaps_from'];
            $params[':overlaps_to'] = $filters['overlaps_to'];
        }

        $sql .= " ORDER BY la.created_at DESC";

        if ($limit) {
            $sql .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        if ($limit) {
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all leaves with adjacent leave flags for admin review
     */
    public function getLeavesWithAdjacentFlags() {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name, u.email as student_email,
                       adj.id as adj_id, adj.start_date as adj_start_date, adj.end_date as adj_end_date,
                       adj.leave_type as adj_leave_type, adj.status as adj_status
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                LEFT JOIN leave_applications adj ON la.adjacent_leave_id = adj.id
                WHERE la.adjacent_leave_id IS NOT NULL
                AND la.status IN (" . self::liveStatusSql() . ")
                AND adj.status IN (" . self::liveStatusSql() . ")
                ORDER BY la.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all combinable leave groups for a student
     * Returns groups of adjacent leaves that can be combined
     *
     * Only live (pending or approved) leaves are offered, on BOTH sides of the
     * adjacency link. The linked leave used to be fetched whatever its status,
     * so a rejected or cancelled leave was offered for combining, and combining
     * brought it back to life.
     */
    public function getAdjacentLeaveGroups($studentId = null) {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE la.adjacent_leave_id IS NOT NULL
                AND la.status IN (" . self::liveStatusSql() . ")";

        if ($studentId) {
            $sql .= " AND la.student_id = :student_id";
        }

        $sql .= " ORDER BY la.student_id, la.start_date";

        $stmt = $this->db->prepare($sql);
        if ($studentId) {
            $stmt->execute([':student_id' => $studentId]);
        } else {
            $stmt->execute();
        }

        $leaves = $stmt->fetchAll();

        // Group leaves by student and their adjacent connections
        $groups = [];
        foreach ($leaves as $leave) {
            $adjLeave = $this->getById($leave['adjacent_leave_id']);
            if (!$adjLeave || !in_array($adjLeave['status'], self::LIVE_STATUSES, true)) {
                continue;
            }

            $key = $leave['student_id'] . '_' . min($leave['id'], $leave['adjacent_leave_id']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'student_id' => $leave['student_id'],
                    'student_name' => $leave['student_name'],
                    'roll_number' => $leave['roll_number'],
                    'leaves' => []
                ];
            }

            // Add both the current leave and its adjacent leave
            $groups[$key]['leaves'][$leave['id']] = $leave;
            if (!isset($groups[$key]['leaves'][$adjLeave['id']])) {
                $groups[$key]['leaves'][$adjLeave['id']] = $adjLeave;
            }
        }

        return array_values($groups);
    }

    /**
     * Combine multiple adjacent leaves into a single leave
     * @param array $leaveIds Array of leave IDs to combine
     * @param int $adminId Admin user ID performing the action
     * @param string $remarks Remarks for the combination
     * @return array Result with success status and new leave ID
     *
     * The leaves must be one student's, of one type, and form one unbroken
     * stretch with only weekends/holidays between them. They must be ALL
     * approved or ALL still pending. They become 'combined' (combined_into_id
     * points at the new leave) and the new leave carries their days.
     *
     * Balance: a leave is deducted exactly when it reaches 'approved'
     * (Approval::approveByHOD / approveByAdmin), and once the sources are
     * combined only the new leave can be.
     *  - All approved: every source is refunded (combine_refunded_days records
     *    it) and the combined leave is approved and deducted once, for its own
     *    total. Net effect for the student: -(combined total), once. The old
     *    version refunded nothing and the combined leave was deducted again on
     *    approval: Dhanashree S (student 50) paid twice for leaves 27 and 28
     *    when they became leave 29. Academic leave that now crosses the Dean AP
     *    threshold goes to the Admin instead, as Approval::approveByHOD would
     *    send it, and is deducted if the Admin approves; if it is rejected or
     *    cancelled, restoreCombinedSources() puts the approved sources back.
     *  - All pending: nothing was deducted, nothing is refunded. The combined
     *    leave is a new, longer application and starts again at the guide —
     *    or is held at pending_routing while the student has a guide/TA change
     *    with the office, as a new application would be. It used to jump
     *    straight to pending_hod, skipping the guide and TA.
     *  - A mix is refused. Refunding the approved part and sending the whole
     *    back through the chain left those days uncharged whenever the combined
     *    leave was then rejected: the pending application(s) are decided first.
     *
     * Runs in the caller's transaction if there is one, otherwise in its own,
     * retried once if it is chosen as a deadlock victim.
     */
    public function combineLeaves($leaveIds, $adminId, $remarks = '') {
        $leaveIds = array_values(array_unique(array_filter(array_map('intval', (array) $leaveIds))));
        if (count($leaveIds) < 2) {
            throw new Exception("At least 2 leaves are required to combine");
        }
        $idList = implode(',', $leaveIds); // integers only

        // Whose leaves these are is needed first, so that the student row can
        // be locked BEFORE the leave rows — the order apply() takes them in. A
        // leave never changes owner, so this is read before the transaction
        // starts: as its first plain read it would fix the snapshot BEFORE the
        // student lock, and later reads would miss what the previous lock
        // holder committed.
        $owners = $this->db->query("SELECT DISTINCT student_id FROM leave_applications WHERE id IN ($idList)")
                           ->fetchAll(PDO::FETCH_COLUMN);
        if (count($owners) > 1) {
            throw new Exception("Cannot combine leaves from different students");
        }
        if (!$owners) {
            throw new Exception("Leave ID {$leaveIds[0]} not found");
        }
        $studentId = (int) $owners[0];

        if ($this->db->inTransaction()) {
            return $this->combineLocked($studentId, $leaveIds, $adminId, $remarks);
        }
        return $this->inOwnTransaction(function () use ($studentId, $leaveIds, $adminId, $remarks) {
            return $this->combineLocked($studentId, $leaveIds, $adminId, $remarks);
        }, 'Combining leaves failed', 'Failed to combine leaves');
    }

    private function combineLocked($studentId, array $leaveIds, $adminId, $remarks) {
        $idList = implode(',', $leaveIds);

        $student = $this->lockStudent($studentId);

        // Primary-key locks only (no gap locks).
        $leaves = $this->db->query("SELECT * FROM leave_applications WHERE id IN ($idList)
                                    ORDER BY start_date, id FOR UPDATE")->fetchAll();

        $found = array_map('intval', array_column($leaves, 'id'));
        foreach ($leaveIds as $leaveId) {
            if (!in_array($leaveId, $found, true)) {
                throw new Exception("Leave ID $leaveId not found");
            }
        }

        $leaveType = $leaves[0]['leave_type'];
        foreach ($leaves as $leave) {
            if ((int) $leave['student_id'] !== $studentId) {
                throw new Exception("Cannot combine leaves from different students");
            }
            if ($leave['status'] === 'combined') {
                throw new Exception("Leave #{$leave['id']} has already been combined into another leave.");
            }
            if (!in_array($leave['status'], self::LIVE_STATUSES, true)) {
                throw new Exception("Leave #{$leave['id']} is {$leave['status']} and cannot be combined: "
                    . "only pending or approved leaves can be.");
            }
            if ($leave['leave_type'] !== $leaveType) {
                throw new Exception("Leave #{$leave['id']} is {$leave['leave_type']} leave but leave "
                    . "#{$leaves[0]['id']} is $leaveType leave: only leaves of the same type can be combined.");
            }
        }

        // One unbroken stretch: each leave starts by the day after the stretch
        // so far ends, or only weekends/holidays lie between. Otherwise the
        // combined range would charge the student for days they were at work.
        $combinedStartDate = $leaves[0]['start_date'];
        $stretchEnd = new DateTime($leaves[0]['end_date']);
        for ($i = 1; $i < count($leaves); $i++) {
            $nextStart = new DateTime($leaves[$i]['start_date']);
            $gapStart = (clone $stretchEnd)->modify('+1 day');
            if ($nextStart > $gapStart && !$this->isGapOnlyWeekendsOrHolidays($gapStart, $nextStart)) {
                throw new Exception("Leave #{$leaves[$i]['id']} does not follow on from the leave before it: "
                    . "there are working days between them, so they cannot be combined.");
            }
            $nextEnd = new DateTime($leaves[$i]['end_date']);
            if ($nextEnd > $stretchEnd) {
                $stretchEnd = $nextEnd;
            }
        }
        $combinedEndDate = $stretchEnd->format('Y-m-d');

        // Counted exactly as apply() counts a single application.
        $totalDays = $this->calculateWorkingDays($combinedStartDate, $combinedEndDate);

        // Another live leave inside the combined range would be paid for twice.
        // A plain read under the student lock (see findOverlappingLeave).
        $clash = $this->findOverlappingLeave($studentId, $combinedStartDate, $combinedEndDate, $leaveIds);
        if ($clash) {
            throw new Exception("Leave #{$clash['id']} ({$clash['start_date']} to {$clash['end_date']}) also falls "
                . "inside $combinedStartDate to $combinedEndDate: select it too, or cancel it first.");
        }

        $approvedLeaves = array_values(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
        $allApproved = count($approvedLeaves) === count($leaves);
        $idsText = '#' . implode(', #', array_column($leaves, 'id'));

        // All approved or all pending, never a mix (see the docblock): the
        // approved days would be refunded and then never charged again if the
        // combined application were rejected.
        if ($approvedLeaves && !$allApproved) {
            $pendingIds = array_column(array_filter($leaves, fn($l) => $l['status'] !== 'approved'), 'id');
            throw new Exception("Leave(s) #" . implode(', #', array_column($approvedLeaves, 'id'))
                . " are approved but #" . implode(', #', $pendingIds) . " are still pending. "
                . "Decide the pending application(s) first, then combine.");
        }

        $routingRequestId = null;
        if ($allApproved) {
            $newStatus = ($leaveType === 'academic' && $totalDays > EXTENDED_LEAVE_THRESHOLD)
                ? 'pending_admin' : 'approved';
        } else {
            // A pending guide/TA change holds a new application at
            // pending_routing. The candidate is re-checked under a lock on the
            // request row: holding the combined leave on a request the admin
            // has just decided would hold it forever, as nothing releases it.
            $stmt = $this->db->prepare("SELECT id FROM guide_ta_change_requests
                                        WHERE student_id = :student_id AND status = 'pending'
                                        ORDER BY id LIMIT 1");
            $stmt->execute([':student_id' => $studentId]);
            $routingRequestId = $stmt->fetchColumn() ?: null;
            if ($routingRequestId && !$this->lockPendingRoutingRequest($routingRequestId, $studentId)) {
                $routingRequestId = null;
            }

            if ($routingRequestId) {
                $newStatus = 'pending_routing';
            } elseif (empty($student['faculty_guide_id'])) {
                // Same rule as GuideTAChange::releaseHeldLeaves: the chain
                // starts at the guide, so there is nowhere to send it.
                throw new Exception("This student has no Faculty Guide assigned, so the combined leave "
                    . "could not enter the approval chain. Assign a Faculty Guide first.");
            } else {
                // No TA (or guide == TA) is settled by Approval::approveByGuide.
                $newStatus = 'pending_guide';
            }
        }

        // Each approval stage: carried over when every source had passed it,
        // otherwise to be done again for the combined dates.
        if ($allApproved) {
            $carried = "Approved as separate applications $idsText before they were combined.";
            $payments = array_unique(array_map(fn($l) => (string) $l['hod_payment_status'], $leaves));
            $hodRemarks = $carried;
            if (count($payments) > 1) {
                $hodRemarks .= ' Payment status: ' . implode(', ', array_map(
                    fn($l) => "#{$l['id']} " . ($l['hod_payment_status'] ?: 'not set'), $leaves)) . '.';
            }
            $stage = [
                ':guide_status' => 'approved',
                ':guide_remarks' => $carried,
                ':guide_approved_at' => max(array_column($leaves, 'guide_approved_at')),
                ':ta_status' => 'approved',
                ':ta_remarks' => $carried,
                ':ta_no_duty_confirmed' => min(array_map('intval', array_column($leaves, 'ta_no_duty_confirmed'))),
                ':ta_approved_at' => max(array_column($leaves, 'ta_approved_at')),
                ':hod_status' => 'approved',
                ':hod_remarks' => $hodRemarks,
                ':hod_payment_status' => count($payments) === 1 && reset($payments) !== '' ? reset($payments) : null,
                ':hod_approved_at' => max(array_column($leaves, 'hod_approved_at')),
                ':admin_status' => $newStatus === 'pending_admin' ? 'pending' : null,
            ];
        } else {
            $stage = [
                ':guide_status' => 'pending', ':guide_remarks' => null, ':guide_approved_at' => null,
                ':ta_status' => 'pending', ':ta_remarks' => null, ':ta_no_duty_confirmed' => 0,
                ':ta_approved_at' => null,
                ':hod_status' => 'pending', ':hod_remarks' => null, ':hod_payment_status' => null,
                ':hod_approved_at' => null,
                ':admin_status' => null,
            ];
        }

        // Combine reasons
        $combinedReason = "Combined leave from multiple applications:\n";
        foreach ($leaves as $leave) {
            $combinedReason .= "- Leave #{$leave['id']} ({$leave['start_date']} to {$leave['end_date']}): {$leave['reason']}\n";
        }
        if ($remarks) {
            $combinedReason .= "\nAdmin remarks: $remarks";
        }

        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id IN (:admin_id, :student_user_id)");
        $stmt->execute([':admin_id' => (int) $adminId, ':student_user_id' => (int) $student['user_id']]);
        $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $adminName = $names[(int) $adminId] ?? "user #$adminId";
        $studentName = $names[(int) $student['user_id']] ?? "student_id $studentId";
        $studentText = "$studentName ({$student['roll_number']}, student_id $studentId)";

        // A medical leave keeps the first certificate; the others stay on the
        // source leaves, which remain viewable.
        $documents = array_values(array_filter(array_column($leaves, 'supporting_document', 'id')));
        $adminRemarks = "Combined from leaves $idsText by $adminName on " . date('d M Y') . '.';
        if (count($documents) > 1) {
            $adminRemarks .= " Supporting documents are also on the combined leaves $idsText.";
        }
        if ($remarks) {
            $adminRemarks .= " Remarks: $remarks";
        }

        // Anything the sources were adjacent to (other than each other) is
        // adjacent to the combined leave.
        $adjacent = $this->findAdjacentLeave($studentId, $combinedStartDate, $combinedEndDate, $leaveIds);

        $sql = "INSERT INTO leave_applications
                (student_id, leave_type, start_date, end_date, total_days, reason, status,
                 guide_status, guide_remarks, guide_approved_at,
                 ta_status, ta_remarks, ta_no_duty_confirmed, ta_approved_at,
                 hod_status, hod_remarks, hod_payment_status, hod_approved_at,
                 admin_status, admin_remarks, adjacent_leave_id, supporting_document, routing_request_id)
                VALUES (:student_id, :leave_type, :start_date, :end_date, :total_days, :reason, :status,
                 :guide_status, :guide_remarks, :guide_approved_at,
                 :ta_status, :ta_remarks, :ta_no_duty_confirmed, :ta_approved_at,
                 :hod_status, :hod_remarks, :hod_payment_status, :hod_approved_at,
                 :admin_status, :admin_remarks, :adjacent_leave_id, :supporting_document, :routing_request_id)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($stage + [
            ':student_id' => $studentId,
            ':leave_type' => $leaveType,
            ':start_date' => $combinedStartDate,
            ':end_date' => $combinedEndDate,
            ':total_days' => $totalDays,
            ':reason' => $combinedReason,
            ':status' => $newStatus,
            ':admin_remarks' => $adminRemarks,
            ':adjacent_leave_id' => $adjacent ? $adjacent['id'] : null,
            ':supporting_document' => $documents[0] ?? null,
            ':routing_request_id' => $routingRequestId
        ]);

        $newLeaveId = (int) $this->db->lastInsertId();

        // Mark old leaves as combined, pointing at the leave that replaced them.
        // combine_refunded_days records what the refund below gives back, so
        // that rejecting or cancelling the combined leave can charge it again
        // (restoreCombinedSources). It is set before status in the SET list:
        // MySQL applies the assignments left to right.
        $stmt = $this->db->prepare("UPDATE leave_applications
                SET combine_refunded_days = IF(status = 'approved', total_days, NULL),
                    combined_into_id = :new_id,
                    status = 'combined',
                    admin_remarks = CONCAT_WS(:sep, admin_remarks, :note),
                    updated_at = NOW()
                WHERE id IN ($idList)");
        $stmt->execute([
            ':new_id' => $newLeaveId,
            ':sep' => "\n",
            ':note' => "Combined into leave #$newLeaveId by $adminName on " . date('d M Y') . '.'
        ]);

        // Live leaves that pointed at a source now point at the combined leave,
        // so they can still be reviewed and combined with it.
        $stmt = $this->db->prepare("UPDATE leave_applications SET adjacent_leave_id = :new_id
                WHERE adjacent_leave_id IN ($idList) AND id NOT IN ($idList)
                AND status IN (" . self::liveStatusSql() . ")");
        $stmt->execute([':new_id' => $newLeaveId]);

        // Refund what was already deducted, then deduct the combined leave once
        // if it is approved now.
        $refundedDays = 0;
        foreach ($approvedLeaves as $leave) {
            $this->updateBalance($studentId, $leaveType, $leave['total_days']);
            $refundedDays += $leave['total_days'];
            $this->logAction($adminId, 'leave_balance_refunded',
                "Refunded {$leave['total_days']} days to the $leaveType leave balance of $studentText: "
                . "approved leave #{$leave['id']} was combined into leave #$newLeaveId (Ref ID: {$leave['id']})");
        }

        $deductedDays = 0;
        if ($newStatus === 'approved') {
            $this->updateBalance($studentId, $leaveType, -$totalDays);
            $deductedDays = $totalDays;
            $this->logAction($adminId, 'leave_balance_deducted',
                "Deducted $totalDays days from the $leaveType leave balance of $studentText "
                . "for combined leave #$newLeaveId (Ref ID: $newLeaveId)");
        }

        $this->logAction($adminId, 'leaves_combined',
            "Leaves $idsText of $studentText combined into leave #$newLeaveId "
            . "($combinedStartDate to $combinedEndDate, $totalDays days, status $newStatus). "
            . "Refunded $refundedDays day(s) already deducted; "
            . ($deductedDays ? "deducted $deductedDays." : "the combined leave is deducted when approved.")
            . " (Ref ID: $newLeaveId)");

        return [
            'success' => true,
            'new_leave_id' => $newLeaveId,
            'combined_leave_ids' => $leaveIds,
            'total_days' => $totalDays,
            'start_date' => $combinedStartDate,
            'end_date' => $combinedEndDate,
            'status' => $newStatus,
            'refunded_days' => $refundedDays,
            'deducted_days' => $deductedDays
        ];
    }

    /**
     * Put back the approved leaves that were combined into $combinedLeaveId,
     * now that it has been rejected or cancelled.
     *
     * combineLeaves() refunds approved sources because the combined leave is
     * to be charged instead. If the combined leave never gets approved, those
     * days — approved in their own right — would otherwise never be charged
     * at all. Each such source goes back to 'approved' and is deducted again,
     * in the caller's transaction, logged under the actor with the source's
     * Ref ID. Sources that were still pending when combined were never
     * deducted and stay 'combined'.
     *
     * Every reject/cancel path in Approval calls this after its status change,
     * while holding the lock on the combined leave's row. That lock is what
     * guards the sources too: nothing else writes a 'combined' leave, so the
     * plain read below is enough (a locking range read would take gap locks).
     *
     * @param string $outcome 'rejected' or 'cancelled', for the remarks and log
     * @return int[] ids of the leaves restored to 'approved'
     */
    public function restoreCombinedSources($combinedLeaveId, $actorUserId, $outcome) {
        $stmt = $this->db->prepare("SELECT la.id, la.student_id, la.leave_type, la.combine_refunded_days,
                                           s.roll_number, u.name AS student_name
                                    FROM leave_applications la
                                    JOIN students s ON s.id = la.student_id
                                    JOIN users u ON u.id = s.user_id
                                    WHERE la.combined_into_id = :id AND la.status = 'combined'
                                    AND la.combine_refunded_days > 0
                                    ORDER BY la.start_date, la.id");
        $stmt->execute([':id' => (int) $combinedLeaveId]);
        $sources = $stmt->fetchAll();

        $restore = $this->db->prepare("UPDATE leave_applications
                SET status = 'approved',
                    combine_refunded_days = NULL,
                    admin_remarks = CONCAT_WS(:sep, admin_remarks, :note),
                    updated_at = NOW()
                WHERE id = :id AND status = 'combined'");

        $restored = [];
        foreach ($sources as $src) {
            $restore->execute([
                ':sep' => "\n",
                ':note' => "Approved again on " . date('d M Y') . ": combined leave #$combinedLeaveId was $outcome.",
                ':id' => (int) $src['id']
            ]);
            if ($restore->rowCount() !== 1) {
                continue;
            }

            $days = (int) $src['combine_refunded_days'];
            $this->updateBalance($src['student_id'], $src['leave_type'], -$days);
            $this->logAction($actorUserId, 'leave_balance_deducted',
                "Leave #{$src['id']} of {$src['student_name']} ({$src['roll_number']}, student_id {$src['student_id']}) "
                . "is approved again because combined leave #$combinedLeaveId was $outcome; deducted $days days "
                . "from the {$src['leave_type']} leave balance again (Ref ID: {$src['id']})");
            $restored[] = (int) $src['id'];
        }

        return $restored;
    }

    /**
     * Log action
     *
     * $actorUserId is the users.id of whoever acted: the student for their own
     * application, the admin for a combination (name the student in the
     * description then). This used to take a student id and log every entry
     * as that student's user, so an admin combining a student's leaves was
     * recorded as the student doing it.
     */
    private function logAction($actorUserId, $action, $description) {
        // logSystemAction adds the "performed by admin #X while impersonating"
        // note. Used only when it writes through this same connection, so the
        // entry commits or rolls back with the change it describes; the cron
        // scripts do not load functions.php at all.
        if (function_exists('logSystemAction') && $this->db === getDBConnection()) {
            logSystemAction($actorUserId, $action, 'leaves', $description);
            return;
        }

        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, 'leaves', :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $actorUserId,
            ':action' => $action,
            ':description' => $description,
            // REMOTE_ADDR is the front proxy for everyone on production.
            ':ip_address' => (function_exists('getClientIp') ? getClientIp() : null) ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ]);
    }
}
