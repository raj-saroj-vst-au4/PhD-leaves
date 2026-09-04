<?php
/**
 * Leave Class
 * Handles all leave application and balance operations
 */

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

    private $db;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Apply for leave
     */
    public function apply($studentId, $data) {
        try {
            // Validate dates
            if (strtotime($data['end_date']) < strtotime($data['start_date'])) {
                throw new Exception("End date cannot be before start date");
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

            // Get student details to check for assigned approvers
            $studentSql = "SELECT faculty_guide_id, ta_coordinator_id FROM students WHERE id = :student_id";
            $studentStmt = $this->db->prepare($studentSql);
            $studentStmt->execute([':student_id' => $studentId]);
            $student = $studentStmt->fetch();

            // A guide/TA correction raised with this application holds it at
            // pending_routing until the admin decides. The assignment check below
            // is waived in that case: asking for the assignment to be corrected is
            // exactly how a student who has none is supposed to get moving, so
            // refusing them here would be a dead end.
            $routingRequestId = !empty($data['routing_request_id'])
                ? (int) $data['routing_request_id'] : null;

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
            $this->logAction($studentId, 'leave_applied', $logMessage);

            return [
                'success' => true,
                'leave_id' => $leaveId,
                'total_days' => $totalDays,
                'warning' => $warningMessage,
                'adjacent_leave_id' => $adjacentLeaveId,
                'held_for_routing' => $routingRequestId !== null
            ];

        } catch (PDOException $e) {
            logError("Leave application failed: " . $e->getMessage());
            throw new Exception("Failed to submit leave application");
        }
    }

    /**
     * Calculate total leave days between two dates (including weekends and holidays)
     * All calendar days are counted when they fall within the leave period
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
     */
    public function findAdjacentLeave($studentId, $startDate, $endDate) {
        // Get all active leaves (pending or approved) for this student
        $sql = "SELECT id, start_date, end_date, leave_type, status
                FROM leave_applications
                WHERE student_id = :student_id
                AND status NOT IN ('rejected', 'cancelled')
                ORDER BY start_date";

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

        // Get holidays in the gap period
        $holidays = $this->getHolidayDatesInRange($gapStart->format('Y-m-d'), $gapEnd->format('Y-m-d'));

        $current = clone $gapStart;
        while ($current < $gapEnd) {
            $dayOfWeek = (int)$current->format('N'); // 1=Monday, 7=Sunday
            $dateStr = $current->format('Y-m-d');

            // If it's a weekday (Mon-Fri) and not a holiday, gap has working days
            if ($dayOfWeek >= 1 && $dayOfWeek <= 5 && !in_array($dateStr, $holidays)) {
                return false;
            }

            $current->modify('+1 day');
        }

        return true;
    }

    /**
     * Get holiday dates in a range
     */
    private function getHolidayDatesInRange($startDate, $endDate) {
        $sql = "SELECT holiday_date FROM holidays
                WHERE holiday_date >= :start_date AND holiday_date < :end_date
                AND is_working_day = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $holidays = [];
        while ($row = $stmt->fetch()) {
            $holidays[] = $row['holiday_date'];
        }

        return $holidays;
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
     * Get pending leave days (approved but not yet deducted)
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
     * Reset leave balances (called by cron job)
     * Only updates balances for active students (skips passed out/dropped out students)
     */
    public function resetBalances() {
        try {
            $this->db->beginTransaction();

            // Update personal leaves (carry forward) - only for active students
            $sql = "UPDATE leave_balances lb
                    JOIN students s ON lb.student_id = s.id
                    JOIN users u ON s.user_id = u.id
                    SET lb.personal_leaves = lb.personal_leaves + :allocation,
                        lb.last_reset_personal = :reset_date
                    WHERE u.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':allocation' => PERSONAL_LEAVE_ALLOCATION,
                ':reset_date' => date('Y-m-d')
            ]);

            // Update medical leaves (carry forward) - only for active students
            $sql = "UPDATE leave_balances lb
                    JOIN students s ON lb.student_id = s.id
                    JOIN users u ON s.user_id = u.id
                    SET lb.medical_leaves = lb.medical_leaves + :allocation,
                        lb.last_reset_medical = :reset_date
                    WHERE u.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':allocation' => MEDICAL_LEAVE_ALLOCATION,
                ':reset_date' => date('Y-m-d')
            ]);

            // Update academic leaves (NO carry forward - reset to 25 for eligible students)
            // Only for active students
            $sql = "UPDATE leave_balances lb
                    JOIN students s ON lb.student_id = s.id
                    JOIN users u ON s.user_id = u.id
                    SET lb.academic_leaves = :allocation,
                        lb.last_reset_academic = :reset_date
                    WHERE s.joining_date <= DATE_SUB(NOW(), INTERVAL :eligibility_months MONTH)
                    AND u.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':allocation' => ACADEMIC_LEAVE_ALLOCATION,
                ':reset_date' => date('Y-m-d'),
                ':eligibility_months' => ACADEMIC_LEAVE_ELIGIBILITY_MONTHS
            ]);

            $this->db->commit();
            return true;

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Leave balance reset failed: " . $e->getMessage());
            return false;
        }
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
                AND la.status NOT IN ('rejected', 'cancelled', 'combined')
                ORDER BY la.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get all combinable leave groups for a student
     * Returns groups of adjacent leaves that can be combined
     */
    public function getAdjacentLeaveGroups($studentId = null) {
        $sql = "SELECT la.*, s.roll_number, u.name as student_name
                FROM leave_applications la
                JOIN students s ON la.student_id = s.id
                JOIN users u ON s.user_id = u.id
                WHERE la.adjacent_leave_id IS NOT NULL
                AND la.status NOT IN ('rejected', 'cancelled', 'combined', 'pending_routing')";

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

            // Fetch the adjacent leave if not already in the group
            if (!isset($groups[$key]['leaves'][$leave['adjacent_leave_id']])) {
                $adjLeave = $this->getById($leave['adjacent_leave_id']);
                if ($adjLeave) {
                    $groups[$key]['leaves'][$adjLeave['id']] = $adjLeave;
                }
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
     */
    public function combineLeaves($leaveIds, $adminId, $remarks = '') {
        if (count($leaveIds) < 2) {
            throw new Exception("At least 2 leaves are required to combine");
        }

        try {
            $this->db->beginTransaction();

            // Fetch all leaves to combine
            $leaves = [];
            $studentId = null;
            $leaveType = null;

            foreach ($leaveIds as $leaveId) {
                $leave = $this->getById($leaveId);
                if (!$leave) {
                    throw new Exception("Leave ID $leaveId not found");
                }
                if ($leave['status'] === 'combined') {
                    throw new Exception("Leave ID $leaveId has already been combined");
                }

                // Verify all leaves belong to same student
                if ($studentId === null) {
                    $studentId = $leave['student_id'];
                } elseif ($studentId !== $leave['student_id']) {
                    throw new Exception("Cannot combine leaves from different students");
                }

                // Use the leave type from the first leave (or most restrictive)
                if ($leaveType === null) {
                    $leaveType = $leave['leave_type'];
                }

                $leaves[] = $leave;
            }

            // Sort leaves by start date
            usort($leaves, function($a, $b) {
                return strtotime($a['start_date']) - strtotime($b['start_date']);
            });

            // Calculate combined date range
            $combinedStartDate = $leaves[0]['start_date'];
            $combinedEndDate = $leaves[count($leaves) - 1]['end_date'];

            // Ensure we get the actual min/max dates
            foreach ($leaves as $leave) {
                if (strtotime($leave['start_date']) < strtotime($combinedStartDate)) {
                    $combinedStartDate = $leave['start_date'];
                }
                if (strtotime($leave['end_date']) > strtotime($combinedEndDate)) {
                    $combinedEndDate = $leave['end_date'];
                }
            }

            // Calculate total days for combined leave
            $totalDays = $this->calculateWorkingDays($combinedStartDate, $combinedEndDate);

            // Combine reasons
            $combinedReason = "Combined leave from multiple applications:\n";
            foreach ($leaves as $leave) {
                $combinedReason .= "- Leave #{$leave['id']} ({$leave['start_date']} to {$leave['end_date']}): {$leave['reason']}\n";
            }
            if ($remarks) {
                $combinedReason .= "\nAdmin remarks: $remarks";
            }

            // Determine status - use the most progressed status
            $statusPriority = [
                'approved' => 6,
                'pending_admin' => 5,
                'pending_hod' => 4,
                'pending_ta' => 3,
                'pending_guide' => 2,
                'rejected' => 1,
                'cancelled' => 0
            ];

            $bestStatus = 'pending_guide';
            foreach ($leaves as $leave) {
                if (($statusPriority[$leave['status']] ?? 0) > ($statusPriority[$bestStatus] ?? 0)) {
                    $bestStatus = $leave['status'];
                }
            }

            // Create new combined leave application
            $sql = "INSERT INTO leave_applications
                    (student_id, leave_type, start_date, end_date, total_days, reason, status,
                     guide_status, ta_status, hod_status, admin_remarks)
                    VALUES (:student_id, :leave_type, :start_date, :end_date, :total_days, :reason, :status,
                     'approved', 'approved', 'pending', :admin_remarks)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':leave_type' => $leaveType,
                ':start_date' => $combinedStartDate,
                ':end_date' => $combinedEndDate,
                ':total_days' => $totalDays,
                ':reason' => $combinedReason,
                ':status' => 'pending_hod',
                ':admin_remarks' => "Combined from leaves: " . implode(', ', $leaveIds)
            ]);

            $newLeaveId = $this->db->lastInsertId();

            // Mark old leaves as combined
            $oldLeaveIds = implode(',', array_map('intval', $leaveIds));
            $sql = "UPDATE leave_applications
                    SET status = 'combined',
                        admin_remarks = CONCAT(IFNULL(admin_remarks, ''), '\nCombined into leave #$newLeaveId by admin')
                    WHERE id IN ($oldLeaveIds)";
            $this->db->exec($sql);

            $this->db->commit();

            // Log action
            $this->logAction($studentId, 'leaves_combined',
                "Leaves " . implode(', ', $leaveIds) . " combined into leave #$newLeaveId");

            return [
                'success' => true,
                'new_leave_id' => $newLeaveId,
                'combined_leave_ids' => $leaveIds,
                'total_days' => $totalDays,
                'start_date' => $combinedStartDate,
                'end_date' => $combinedEndDate
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Log action
     */
    private function logAction($studentId, $action, $description) {
        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES ((SELECT user_id FROM students WHERE id = :student_id), :action, 'leaves', :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':student_id' => $studentId,
            ':action' => $action,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }
}
