<?php
/**
 * Monthly attendance sign-off workflow.
 *
 * Order here is TA coordinator first, then the faculty guide. Note this is the
 * REVERSE of the leave chain (guide then TA) — deliberately, because the TA
 * coordinator holds the duty roster and is best placed to check the raw days,
 * leaving the guide the final word. What is borrowed from the leave workflow is
 * its handling of the edges, not its order.
 * A stage with nobody assigned is SKIPPED rather than blocking — the same
 * decision Leave/Approval makes when a student has no TA coordinator.
 *
 * Every transition is guarded by the current status, so a stale browser tab or
 * a double-tap cannot approve a stage twice or approve out of order.
 */
class AttendanceApproval
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDBConnection();
    }

    /** Open a month for sign-off: one row per active student. Idempotent.
     *
     * @param array $rows Rows from the attendance report (student_id, working_days,
     *                    present, manual, excused, absent, percentage).
     * @return array ['created' => int, 'skipped' => int]
     */
    public function openMonth($year, $month, array $rows)
    {
        $created = 0;
        $skipped = 0;
        $unroutable = [];       // no guide AND no TA — the office must assign one
        $notYetJoined = [];     // enrolled after this period ended

        $sql = "INSERT INTO att_month_approvals
                    (period_year, period_month, student_id, ta_coordinator_id, faculty_guide_id,
                     working_days, present_days, excused_days, absent_days, percentage,
                     status, ta_status, guide_status)
                VALUES
                    (:year, :month, :student_id, :ta_id, :guide_id,
                     :working, :present, :excused, :absent, :pct,
                     :status, :ta_status, :guide_status)";
        $stmt = $this->db->prepare($sql);

        $periodEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

        foreach ($rows as $row) {
            $studentId = (int) $row['student_id'];
            $routing = $this->routingFor($studentId);
            if (!$routing) {
                $skipped++;                        // student no longer active
                continue;
            }

            // Someone who joined after this month ended has no attendance to
            // answer for. The report counts every past working day with no record
            // as absent, so without this a student enrolled last week would be
            // sent for sign-off showing a full month of fabricated absences.
            if (!empty($routing['joining_date']) && $routing['joining_date'] > $periodEnd) {
                $notYetJoined[] = $studentId;
                $skipped++;
                continue;
            }

            // No TA assigned: that stage is skipped and the guide reviews first,
            // exactly as an unassigned TA behaves in the leave chain.
            $hasTa = !empty($routing['ta_coordinator_id']);
            $hasGuide = !empty($routing['faculty_guide_id']);

            // One person holding both roles is common here. Asking them to
            // approve the same month twice is pure friction and tells us
            // nothing, so collapse it to a single stage — the same reasoning
            // Leave::apply uses when guide and TA coordinator are one person.
            if ($hasTa && $hasGuide
                && (int) $routing['ta_coordinator_id'] === (int) $routing['faculty_guide_id']) {
                $hasGuide = false;
            }

            if ($hasTa) {
                $status = 'pending_ta';
                $taStatus = 'pending';
                $guideStatus = $hasGuide ? 'pending' : 'skipped';
            } elseif ($hasGuide) {
                $status = 'pending_guide';
                $taStatus = 'skipped';
                $guideStatus = 'pending';
            } else {
                // Nobody to sign off. Creating the row anyway produced a record no
                // query could ever surface (every consumer keys on the snapshotted
                // ta/guide id) and that uk_period_student then blocked from being
                // re-created once routing was fixed. Skip it and report it, so a
                // later run picks the student up once a guide or TA is assigned —
                // Leave::apply is likewise strict rather than creating an
                // unroutable record.
                $unroutable[] = $studentId;
                $skipped++;
                continue;
            }

            try {
                $stmt->execute([
                    ':year' => $year, ':month' => $month, ':student_id' => $studentId,
                    ':ta_id' => $routing['ta_coordinator_id'] ?: null,
                    ':guide_id' => $routing['faculty_guide_id'] ?: null,
                    ':working' => (int) ($row['working_days'] ?? 0),
                    // NOT present + manual: the report's `manual` is a SUBSET of
                    // `present` (its spreadsheet column reads "of which Manual"),
                    // so adding them double-counted every office-marked day and
                    // could make the columns exceed the working days.
                    ':present' => (int) ($row['present'] ?? 0),
                    ':excused' => (int) ($row['excused'] ?? 0),
                    ':absent' => (int) ($row['absent'] ?? 0),
                    // The report returns null when nothing is accountable (e.g. a
                    // month entirely covered by approved leave) and prints "n/a".
                    // Coercing that to 0 would show a compliant student as 0%.
                    ':pct' => isset($row['percentage']) && $row['percentage'] !== null
                        ? (float) $row['percentage'] : null,
                    ':status' => $status, ':ta_status' => $taStatus, ':guide_status' => $guideStatus,
                ]);
                $created++;
            } catch (PDOException $e) {
                // uk_period_student => this month is already open for the student.
                if ($e->getCode() === '23000') {
                    $skipped++;
                    continue;
                }
                throw $e;
            }
        }

        return ['created' => $created, 'skipped' => $skipped,
                'unroutable' => $unroutable, 'not_yet_joined' => $notYetJoined];
    }

    /**
     * Leave applications that must be decided BEFORE this faculty member can
     * sign off the given month.
     *
     * A leave still sitting at their stage can turn an "absent" day into an
     * "excused" one, so approving the attendance first would be attesting to
     * figures that are about to change. Scoped to leaves that actually OVERLAP
     * the month being signed off — a pending application for next term has no
     * bearing on last month's numbers, and blocking on it would be busywork.
     *
     * @param string $role 'ta' | 'guide' — only their own stage blocks them
     * @return array leave rows (id, student name, dates) that are in the way
     */
    public function blockingLeaves($userId, $role, $year, $month)
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = date('Y-m-t', strtotime($first));

        $ownerCol = $role === 'ta' ? 's.ta_coordinator_id' : 's.faculty_guide_id';
        $leaveStatus = $role === 'ta' ? 'pending_ta' : 'pending_guide';

        $stmt = $this->db->prepare(
            "SELECT la.id, la.start_date, la.end_date, la.leave_type, la.total_days,
                    u.name AS student_name, s.roll_number
             FROM leave_applications la
             JOIN students s ON s.id = la.student_id
             JOIN users u ON u.id = s.user_id
             WHERE $ownerCol = :uid
               AND la.status = :leave_status
               AND la.start_date <= :last AND la.end_date >= :first
             ORDER BY la.start_date"
        );
        $stmt->execute([':uid' => $userId, ':leave_status' => $leaveStatus,
                        ':last' => $last, ':first' => $first]);
        return $stmt->fetchAll();
    }

    /** Current guide/TA for a student, or null if the student is not active. */
    private function routingFor($studentId)
    {
        $stmt = $this->db->prepare(
            "SELECT s.faculty_guide_id, s.ta_coordinator_id, s.joining_date
             FROM students s JOIN users u ON u.id = s.user_id
             WHERE s.id = :id AND u.is_active = 1 AND u.role = 'student'"
        );
        $stmt->execute([':id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Rows awaiting this faculty member, for the stage they act at.
     *  @param string $role 'ta' | 'guide'
     */
    public function getPendingForFaculty($userId, $role)
    {
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
        $stmt = $this->db->prepare(
            "SELECT a.*, s.roll_number, u.name AS student_name, u.email AS student_email
             FROM att_month_approvals a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE a.$column = :uid AND a.status = :status
             ORDER BY a.period_year DESC, a.period_month DESC, s.roll_number"
        );
        $stmt->execute([':uid' => $userId, ':status' => $status]);
        return $stmt->fetchAll();
    }

    /** Everything this faculty member has already acted on, for reference. */
    public function getHistoryForFaculty($userId, $role)
    {
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $stageCol = $role === 'ta' ? 'ta_status' : 'guide_status';
        $stmt = $this->db->prepare(
            "SELECT a.*, s.roll_number, u.name AS student_name
             FROM att_month_approvals a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE a.$column = :uid AND a.$stageCol IN ('approved','rejected')
             ORDER BY a.period_year DESC, a.period_month DESC, s.roll_number"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, s.roll_number, u.name AS student_name, u.email AS student_email
             FROM att_month_approvals a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE a.id = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Record one decision.
     *
     * @param string $role     'ta' | 'guide' — the stage being acted on
     * @param string $decision 'approve' | 'reject'
     * @return array ['ok' => bool, 'message' => string, 'new_status' => string|null]
     */
    public function act($id, $userId, $role, $decision, $remarks)
    {
        if (!in_array($role, ['ta', 'guide'], true)) {
            return ['ok' => false, 'message' => 'Invalid role.', 'new_status' => null];
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Invalid decision.', 'new_status' => null];
        }
        if ($decision === 'reject' && strlen(trim($remarks)) < 5) {
            return ['ok' => false, 'message' => 'A reason of at least 5 characters is required to reject.',
                    'new_status' => null];
        }

        $this->db->beginTransaction();
        try {
            // Lock the row so two tabs cannot both act on the same stage.
            $stmt = $this->db->prepare("SELECT * FROM att_month_approvals WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $rec = $stmt->fetch();
            if (!$rec) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'Record not found.', 'new_status' => null];
            }

            // Right person, right stage — checked here rather than trusting the page.
            $ownerColumn = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
            $expectedStatus = $role === 'ta' ? 'pending_ta' : 'pending_guide';
            if ((int) $rec[$ownerColumn] !== (int) $userId) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'This is not assigned to you.', 'new_status' => null];
            }
            if ($rec['status'] !== $expectedStatus) {
                $this->db->rollBack();
                return ['ok' => false,
                        'message' => 'This has already been dealt with (it is now "' . $rec['status'] . '").',
                        'new_status' => $rec['status']];
            }

            // Approving is an attestation that the figures are right, so it is
            // refused while a leave for this month still awaits this person —
            // deciding it could move days from absent to excused. Disputing is
            // still allowed: that asserts the opposite and needs no clean slate.
            if ($decision === 'approve') {
                $blocking = $this->blockingLeaves($userId, $role,
                    (int) $rec['period_year'], (int) $rec['period_month']);
                if ($blocking) {
                    $this->db->rollBack();
                    return ['ok' => false, 'new_status' => null,
                            'message' => 'Decide your ' . count($blocking) . ' pending leave application(s) '
                                . 'for this month first — approving one could change these attendance figures.'];
                }
            }

            if ($decision === 'reject') {
                $newStatus = 'rejected';
            } elseif ($role === 'ta') {
                // Hand on to the guide, unless there is no guide to hand to.
                $newStatus = $rec['guide_status'] === 'skipped' ? 'approved' : 'pending_guide';
            } else {
                $newStatus = 'approved';
            }

            $stageCol = $role === 'ta' ? 'ta' : 'guide';
            $upd = $this->db->prepare(
                "UPDATE att_month_approvals
                    SET status = :status,
                        {$stageCol}_status = :stage_status,
                        {$stageCol}_remarks = :remarks,
                        {$stageCol}_acted_at = NOW(),
                        {$stageCol}_acted_by = :uid
                  WHERE id = :id"
            );
            $upd->execute([
                ':status' => $newStatus,
                ':stage_status' => $decision === 'approve' ? 'approved' : 'rejected',
                ':remarks' => trim($remarks),
                ':uid' => $userId,
                ':id' => $id,
            ]);

            $this->db->commit();

            logSystemAction($userId, 'attendance_approval_' . $decision, 'attendance',
                sprintf('%s %s attendance for student_id=%d %04d-%02d (now %s)',
                    $role === 'ta' ? 'TA coordinator' : 'Faculty guide', $decision,
                    (int) $rec['student_id'], (int) $rec['period_year'], (int) $rec['period_month'],
                    $newStatus));

            return ['ok' => true, 'new_status' => $newStatus,
                    'message' => $decision === 'approve'
                        ? ($newStatus === 'pending_guide'
                            ? 'Approved — sent to the faculty guide.'
                            : 'Approved. Sign-off complete.')
                        : 'Recorded as disputed.'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Faculty who now have rows waiting at a given stage — used by the cron to
     *  send one combined email each rather than one per student. */
    public function facultyAwaiting($year, $month, $role)
    {
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.email, COUNT(*) AS pending_count
             FROM att_month_approvals a
             JOIN users u ON u.id = a.$column
             WHERE a.period_year = :year AND a.period_month = :month
               AND a.status = :status AND u.is_active = 1
             GROUP BY u.id, u.name, u.email
             ORDER BY u.name"
        );
        $stmt->execute([':year' => $year, ':month' => $month, ':status' => $status]);
        return $stmt->fetchAll();
    }

    /** The rows one faculty member must act on for a given month. */
    public function rowsForFacultyMonth($userId, $role, $year, $month)
    {
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
        $stmt = $this->db->prepare(
            "SELECT a.*, s.roll_number, u.name AS student_name
             FROM att_month_approvals a
             JOIN students s ON s.id = a.student_id
             JOIN users u ON u.id = s.user_id
             WHERE a.$column = :uid AND a.status = :status
               AND a.period_year = :year AND a.period_month = :month
             ORDER BY s.roll_number"
        );
        $stmt->execute([':uid' => $userId, ':status' => $status, ':year' => $year, ':month' => $month]);
        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------- admin views ---

    /** Periods that have been opened, newest first — drives the admin picker. */
    public function availablePeriods()
    {
        return $this->db->query(
            "SELECT period_year, period_month, COUNT(*) AS total
             FROM att_month_approvals
             GROUP BY period_year, period_month
             ORDER BY period_year DESC, period_month DESC"
        )->fetchAll();
    }

    /**
     * Every student's sign-off state for a month, with the names of whoever the
     * record is waiting on — that is the question an admin actually has
     * ("who do I chase?"), so the join answers it directly.
     */
    public function getMonthOverview($year, $month)
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, s.roll_number,
                    stu.name  AS student_name,
                    ta.name   AS ta_name,   ta.email   AS ta_email,
                    g.name    AS guide_name, g.email   AS guide_email,
                    actedTa.name    AS ta_acted_by_name,
                    actedGuide.name AS guide_acted_by_name
             FROM att_month_approvals a
             JOIN students s   ON s.id = a.student_id
             JOIN users stu    ON stu.id = s.user_id
             LEFT JOIN users ta ON ta.id = a.ta_coordinator_id
             LEFT JOIN users g  ON g.id  = a.faculty_guide_id
             LEFT JOIN users actedTa    ON actedTa.id    = a.ta_acted_by
             LEFT JOIN users actedGuide ON actedGuide.id = a.guide_acted_by
             WHERE a.period_year = :year AND a.period_month = :month
             ORDER BY FIELD(a.status,'rejected','pending_ta','pending_guide','approved'), s.roll_number"
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        return $stmt->fetchAll();
    }

    /** Counts per status for a month, for the admin summary tiles. */
    public function monthSummary($year, $month)
    {
        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) AS n FROM att_month_approvals
             WHERE period_year = :year AND period_month = :month GROUP BY status"
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        $out = ['pending_ta' => 0, 'pending_guide' => 0, 'approved' => 0, 'rejected' => 0, 'total' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }

    /** Faculty still holding work for a month, so admins can chase the right
     *  person rather than emailing everyone. */
    public function outstandingByFaculty($year, $month)
    {
        $rows = [];
        foreach (['ta', 'guide'] as $role) {
            foreach ($this->facultyAwaiting($year, $month, $role) as $f) {
                $rows[] = [
                    'role' => $role,
                    'name' => $f['name'],
                    'email' => $f['email'],
                    'pending_count' => (int) $f['pending_count'],
                ];
            }
        }
        return $rows;
    }

    public function alreadyRun($year, $month)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM att_month_approval_runs WHERE period_year = :y AND period_month = :m"
        );
        $stmt->execute([':y' => $year, ':m' => $month]);
        return $stmt->fetch() ?: null;
    }

    public function recordRun($year, $month, $studentRows, $taEmails, $guideEmails, $trigger = 'cron')
    {
        $stmt = $this->db->prepare(
            "INSERT INTO att_month_approval_runs
                 (period_year, period_month, student_rows, ta_emails_sent, guide_emails_sent, triggered_by)
             VALUES (:y, :m, :rows, :ta, :guide, :trigger)
             ON DUPLICATE KEY UPDATE
                 student_rows = student_rows + VALUES(student_rows),
                 ta_emails_sent = ta_emails_sent + VALUES(ta_emails_sent),
                 guide_emails_sent = guide_emails_sent + VALUES(guide_emails_sent)"
        );
        $stmt->execute([':y' => $year, ':m' => $month, ':rows' => $studentRows,
                        ':ta' => $taEmails, ':guide' => $guideEmails, ':trigger' => $trigger]);
    }
}
