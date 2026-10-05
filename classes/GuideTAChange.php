<?php
/**
 * Guide / TA coordinator change requests.
 *
 * Students used to edit these two fields directly on their profile page, which
 * meant a student could re-route their own approval chain: swap in a friendlier
 * guide while an application sat at pending_guide, set guide == TA to collapse a
 * stage, or blank both and disappear from monthly attendance sign-off
 * altogether. The fields are now read-only to students and a correction is
 * raised here, with the application it was noticed on, for an admin to decide.
 *
 *   student applies + flags the routing wrong
 *       -> leave_applications.status = 'pending_routing'   (held, not routed)
 *       -> one pending request per student (uk_one_pending)
 *              |
 *   admin approves -> the field(s) the student asked to change are updated
 *                     on students.faculty_guide_id / ta_coordinator_id;
 *                     held leaves released into the normal chain;
 *                     pending attendance sign-offs re-routed
 *              |
 *   admin rejects  -> roster untouched; held leaves released anyway, under the
 *                     EXISTING guide/TA
 *
 * A rejection is the admin confirming the current routing is right, which is no
 * reason to throw the student's leave application away. The one case that
 * cannot be released is a student who still has no guide afterwards — see
 * releaseHeldLeaves().
 *
 * Schema: database/migrations/2026_09_05_guide_ta_change_requests.sql
 */

require_once __DIR__ . '/Leave.php';
require_once __DIR__ . '/AttendanceApproval.php';

class GuideTAChange
{
    /** Minimum characters of justification. Matches the leave "reason" floor. */
    const MIN_REASON = 10;

    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Faculty a student may pick from. Deliberately role-scoped: the old
     * profile.php path validated only `is_active = 1`, so a crafted POST could
     * name another student, the HOD or an admin as a "guide".
     */
    public function selectableFaculty()
    {
        $stmt = $this->db->query(
            "SELECT id, name, email FROM users
             WHERE role = 'faculty' AND is_active = 1 ORDER BY name ASC"
        );
        return $stmt->fetchAll();
    }

    /** The student's in-flight request, or null. */
    public function pendingForStudent($studentId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM guide_ta_change_requests
             WHERE student_id = :sid AND status = 'pending' LIMIT 1"
        );
        $stmt->execute([':sid' => (int) $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Raise a request. Returns the new id.
     *
     * @param int      $studentId students.id
     * @param int|null $guideId   requested faculty guide (users.id) or null
     * @param int|null $taId      requested TA coordinator (users.id) or null
     * @param string   $reason    the student's justification
     * @param int|null $actorUserId users.id of whoever is raising it — the
     *                  logged-in user by default (the student, from apply_leave)
     * @throws Exception when the request is invalid or one is already open
     */
    public function create($studentId, $guideId, $taId, $reason, $actorUserId = null)
    {
        $studentId = (int) $studentId;
        $guideId = $guideId ? (int) $guideId : null;
        $taId = $taId ? (int) $taId : null;
        $reason = trim((string) $reason);

        if (strlen($reason) < self::MIN_REASON) {
            throw new Exception('Please explain why the guide or TA coordinator needs changing ('
                . self::MIN_REASON . ' characters or more).');
        }

        // Both must be real, active faculty. Anything else is a forged POST:
        // the form only ever offers selectableFaculty().
        foreach (['Faculty Guide' => $guideId, 'TA Coordinator' => $taId] as $label => $id) {
            if ($id !== null && !$this->isSelectableFaculty($id)) {
                throw new Exception("The selected $label is not a valid faculty member.");
            }
        }

        // Lock the student row for the rest of this transaction. Two submits
        // racing would otherwise both pass the pending check below and open two
        // requests, and approving the second would silently undo the first.
        // uk_one_pending catches that too, but it is a VIRTUAL-column index and
        // not every MySQL/MariaDB build will accept one — the lock is what makes
        // the rule hold regardless. Harmless when no transaction is open: the
        // lock is simply released at once.
        $current = $this->currentRouting($studentId, true);
        if (!$current) {
            throw new Exception('Student record not found.');
        }

        // Nothing to decide if it matches what is already on record. Without
        // this an admin would be asked to approve a no-op, and the leave would
        // be held for no reason.
        if ((int) $current['faculty_guide_id'] === (int) $guideId
            && (int) $current['ta_coordinator_id'] === (int) $taId) {
            throw new Exception('That is already your current Faculty Guide and TA Coordinator. '
                . 'If they are correct, choose "These are correct" instead.');
        }

        if ($this->pendingForStudent($studentId)) {
            throw new Exception('You already have a guide/TA change awaiting a decision from the '
                . 'office. Any leave you apply for will follow that decision.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO guide_ta_change_requests
                 (student_id, current_guide_id, current_ta_id,
                  requested_guide_id, requested_ta_id, reason, status)
             VALUES (:sid, :cur_guide, :cur_ta, :req_guide, :req_ta, :reason, 'pending')"
        );

        try {
            $stmt->execute([
                ':sid' => $studentId,
                ':cur_guide' => $current['faculty_guide_id'] ?: null,
                ':cur_ta' => $current['ta_coordinator_id'] ?: null,
                ':req_guide' => $guideId,
                ':req_ta' => $taId,
                ':reason' => $reason,
            ]);
        } catch (PDOException $e) {
            // uk_one_pending — two submits raced. Report it the same way as the
            // check above rather than as a database error.
            if ($e->getCode() === '23000') {
                throw new Exception('You already have a guide/TA change awaiting a decision.');
            }
            throw $e;
        }

        $id = (int) $this->db->lastInsertId();
        // Recorded against whoever actually raised it (the student, or an admin
        // "logged in as" them — logSystemAction names the admin then), and
        // naming the student either way, so the entry stands on its own.
        if ($actorUserId === null && function_exists('isLoggedIn') && isLoggedIn()) {
            $actorUserId = getCurrentUserId();
        }
        logSystemAction($actorUserId ?: $current['user_id'], 'guide_ta_change_requested', 'students',
            sprintf('Guide/TA change request ID %d for student_id %d (user #%d): guide %s -> %s, ta %s -> %s',
                $id, $studentId, (int) $current['user_id'],
                $current['faculty_guide_id'] ?: 'none', $guideId ?: 'none',
                $current['ta_coordinator_id'] ?: 'none', $taId ?: 'none'));

        return $id;
    }

    /** One request with every name resolved, for the admin table and emails. */
    public function getById($id)
    {
        $stmt = $this->db->prepare(
            "SELECT r.*,
                    u.id   AS student_user_id,
                    u.name AS student_name, u.email AS student_email,
                    s.roll_number,
                    cg.name AS current_guide_name, ct.name AS current_ta_name,
                    rg.name AS requested_guide_name, rg.email AS requested_guide_email,
                    rt.name AS requested_ta_name,
                    d.name  AS decided_by_name,
                    s.faculty_guide_id AS live_guide_id, s.ta_coordinator_id AS live_ta_id,
                    lg.name AS live_guide_name, lt.name AS live_ta_name
             FROM guide_ta_change_requests r
             JOIN students s ON s.id = r.student_id
             JOIN users u    ON u.id = s.user_id
             LEFT JOIN users cg ON cg.id = r.current_guide_id
             LEFT JOIN users ct ON ct.id = r.current_ta_id
             LEFT JOIN users rg ON rg.id = r.requested_guide_id
             LEFT JOIN users rt ON rt.id = r.requested_ta_id
             LEFT JOIN users d  ON d.id  = r.decided_by
             LEFT JOIN users lg ON lg.id = s.faculty_guide_id
             LEFT JOIN users lt ON lt.id = s.ta_coordinator_id
             WHERE r.id = :id"
        );
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Requests for the admin screen.
     *
     * @param string $status 'pending' for the queue, or '' for everything.
     */
    public function listRequests($status = 'pending', $limit = 200)
    {
        $sql = "SELECT r.*,
                       u.name AS student_name, u.email AS student_email,
                       s.roll_number,
                       cg.name AS current_guide_name, ct.name AS current_ta_name,
                       rg.name AS requested_guide_name, rt.name AS requested_ta_name,
                       d.name  AS decided_by_name,
                       s.faculty_guide_id AS live_guide_id, s.ta_coordinator_id AS live_ta_id,
                       lg.name AS live_guide_name, lt.name AS live_ta_name,
                       (SELECT COUNT(*) FROM leave_applications la
                         WHERE la.routing_request_id = r.id) AS held_leaves
                FROM guide_ta_change_requests r
                JOIN students s ON s.id = r.student_id
                JOIN users u    ON u.id = s.user_id
                LEFT JOIN users cg ON cg.id = r.current_guide_id
                LEFT JOIN users ct ON ct.id = r.current_ta_id
                LEFT JOIN users rg ON rg.id = r.requested_guide_id
                LEFT JOIN users rt ON rt.id = r.requested_ta_id
                LEFT JOIN users d  ON d.id  = r.decided_by
                LEFT JOIN users lg ON lg.id = s.faculty_guide_id
                LEFT JOIN users lt ON lt.id = s.ta_coordinator_id";
        $params = [];
        if ($status !== '') {
            $sql .= " WHERE r.status = :status";
            $params[':status'] = $status;
        }
        // Oldest pending first (a student is waiting); newest first once decided.
        $sql .= $status === 'pending'
            ? " ORDER BY r.created_at ASC"
            : " ORDER BY r.decided_at DESC, r.created_at DESC";
        $sql .= " LIMIT " . (int) $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Leaves currently held by a request, for showing the student what is waiting. */
    public function heldLeaves($requestId)
    {
        $stmt = $this->db->prepare(
            "SELECT id, leave_type, start_date, end_date, total_days, status
             FROM leave_applications
             WHERE routing_request_id = :rid
             ORDER BY start_date"
        );
        $stmt->execute([':rid' => (int) $requestId]);
        return $stmt->fetchAll();
    }

    /**
     * Record the admin's decision and release whatever it was holding.
     *
     * Approving applies ONLY what the student asked to change. The request
     * carries the full requested state, and approving used to write both
     * fields wholesale — so a request raised last week, approved today, quietly
     * undid any roster edit the office had made in between (e.g. a TA
     * reassigned for the new term, when the student had only asked about their
     * guide). The field the student did not ask about is now left as it is.
     *
     * And when the roster no longer matches what the request was based on
     * (current_guide_id / current_ta_id, snapshotted when it was raised), the
     * admin is shown the difference and must confirm before anything is
     * written: the student's reason was written about an arrangement that has
     * since moved. The confirmation names the roster the admin was SHOWN
     * (seen_roster in the refusal); if it has moved again by the time they
     * click, they are shown the new state and asked again, rather than a
     * blanket "yes" approving whatever the roster happens to be by then.
     *
     * @param string     $decision  'approve' | 'reject'
     * @param array|null $confirmed ['faculty_guide_id' => ?int, 'ta_coordinator_id' => ?int]:
     *                              the live roster the admin saw and confirmed
     * @return array {ok, needs_confirmation?, message?, seen_roster?, drift[], request,
     *                released[], cancelled[], applied[], signoff_notify[], leave_routing}
     */
    public function decide($id, $adminUserId, $decision, $adminRemarks = null, ?array $confirmed = null)
    {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new Exception('Invalid decision.');
        }

        $applied = [];
        $drift = [];
        $signoffNotify = [];
        $leaveRouting = ['moved' => [], 'returned' => [], 'stranded' => [], 'notify' => []];

        $this->db->beginTransaction();
        try {
            // Lock the row: two admins clicking at once must not both act.
            $stmt = $this->db->prepare(
                "SELECT * FROM guide_ta_change_requests WHERE id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => (int) $id]);
            $req = $stmt->fetch();

            if (!$req) {
                throw new Exception('Request not found.');
            }
            if ($req['status'] !== 'pending') {
                throw new Exception('This request has already been ' . $req['status'] . '.');
            }

            $studentId = (int) $req['student_id'];
            $approved = $decision === 'approve';

            // Lock the roster row as well, so an edit in admin/users.php cannot
            // land between the comparison below and the write.
            $live = $this->currentRouting($studentId, true);
            if (!$live) {
                throw new Exception('Student record not found.');
            }
            $student = $this->studentLabel($studentId);

            $fields = [
                'faculty_guide_id'  => ['Faculty Guide', 'current_guide_id', 'requested_guide_id'],
                'ta_coordinator_id' => ['TA Coordinator', 'current_ta_id', 'requested_ta_id'],
            ];
            $asked = [];
            foreach ($fields as $col => [$label, $curCol, $reqCol]) {
                $was = $req[$curCol] ? (int) $req[$curCol] : null;
                $want = $req[$reqCol] ? (int) $req[$reqCol] : null;
                $now = $live[$col] ? (int) $live[$col] : null;
                if ($was !== $want) {
                    $asked[$col] = $want;
                }
                if ($now !== $was) {
                    $drift[$col] = ['label' => $label, 'was' => $was, 'now' => $now,
                                    'asked' => $was !== $want, 'want' => $want];
                }
            }

            if ($approved) {
                $seen = [
                    'faculty_guide_id' => $live['faculty_guide_id'] ? (int) $live['faculty_guide_id'] : null,
                    'ta_coordinator_id' => $live['ta_coordinator_id'] ? (int) $live['ta_coordinator_id'] : null,
                ];
                $norm = function ($v) { return $v === null || $v === '' || (int) $v === 0 ? null : (int) $v; };
                $confirmedMatches = $confirmed !== null
                    && $norm($confirmed['faculty_guide_id'] ?? null) === $seen['faculty_guide_id']
                    && $norm($confirmed['ta_coordinator_id'] ?? null) === $seen['ta_coordinator_id'];
                if ($drift && !$confirmedMatches) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'needs_confirmation' => true,
                        'drift' => $drift,
                        'seen_roster' => $seen,
                        'message' => ($confirmed !== null
                                ? "The roster changed again after you confirmed. Please check it once more.\n\n" : '')
                            . $this->driftMessage($drift),
                    ];
                }

                // Re-validate at decision time. The requested faculty may have
                // been deactivated in the days since the student asked, and
                // writing an inactive id would break every routing query. Only
                // the fields actually being changed matter.
                foreach ($asked as $col => $fid) {
                    if ($fid !== null && !$this->isSelectableFaculty($fid)) {
                        throw new Exception("The requested {$fields[$col][0]} is no longer an active faculty "
                            . 'member. Reject this request and ask the student to raise a new one.');
                    }
                }

                foreach ($asked as $col => $fid) {
                    if (($live[$col] ? (int) $live[$col] : null) !== $fid) {
                        $applied[$col] = $fid;
                    }
                }
                if ($applied) {
                    $sets = implode(', ', array_map(function ($c) { return "$c = :$c"; }, array_keys($applied)));
                    $upd = $this->db->prepare("UPDATE students SET $sets WHERE id = :sid");
                    $params = [':sid' => $studentId];
                    foreach ($applied as $c => $v) {
                        $params[":$c"] = $v;
                    }
                    $upd->execute($params);

                    // Monthly attendance sign-offs snapshot their routing when
                    // the month opens. Any still waiting at a stage whose person
                    // just changed would otherwise sit with the old guide/TA
                    // forever. Same transaction: the roster and the sign-offs
                    // move together or not at all. (Leave applications read the
                    // guide/TA from students at query time; the edge cases that
                    // strands are handled just below.)
                    $reroute = (new AttendanceApproval($this->db))
                        ->rerouteForStudent($studentId, (int) $adminUserId);
                    $signoffNotify = $reroute['notify'];

                    // In-flight leave applications waiting on a TA who no longer
                    // exists (or on a guide now also the TA) move on to the HOD.
                    require_once __DIR__ . '/Approval.php';
                    $leaveRouting = (new Approval($this->db))->resettleAfterRoutingChange(
                        $studentId, (int) $adminUserId,
                        $live['faculty_guide_id'] ?? null, $live['ta_coordinator_id'] ?? null);
                }
            }

            $upd = $this->db->prepare(
                "UPDATE guide_ta_change_requests
                    SET status = :status, admin_remarks = :remarks,
                        decided_by = :admin, decided_at = NOW()
                  WHERE id = :id"
            );
            $upd->execute([
                ':status' => $approved ? 'approved' : 'rejected',
                ':remarks' => $adminRemarks !== null && trim($adminRemarks) !== ''
                    ? trim($adminRemarks) : null,
                ':admin' => (int) $adminUserId,
                ':id' => (int) $id,
            ]);

            $release = $this->releaseHeldLeaves((int) $id, $studentId, (int) $adminUserId);

            // Recorded against the admin who decided, naming the student and
            // saying exactly what was written — not what was asked for.
            $fmt = function ($v) { return $v === null ? 'none' : (int) $v; };
            $what = [];
            foreach ($applied as $col => $v) {
                $what[] = sprintf('%s %s -> %s', $col === 'faculty_guide_id' ? 'guide' : 'ta',
                    $fmt($live[$col] ? (int) $live[$col] : null), $fmt($v));
            }
            $desc = sprintf('Guide/TA change request ID %d for %s %s. ', $id, $student,
                    $approved ? 'approved' : 'rejected')
                . ($approved ? ($what ? 'Applied: ' . implode(', ', $what) . '. '
                                      : 'Nothing to apply (already in effect). ') : '')
                . ($approved && $drift ? 'Roster had changed since the request (admin confirmed): '
                    . implode(', ', array_map(function ($d) use ($fmt) {
                        return $d['label'] . ' ' . $fmt($d['was']) . ' -> ' . $fmt($d['now']);
                    }, $drift)) . '. ' : '')
                . 'Released ' . count($release['released']) . ' leave(s), cancelled '
                . count($release['cancelled']) . '.';
            logSystemAction($adminUserId, 'guide_ta_change_' . ($approved ? 'approved' : 'rejected'),
                'students', $desc);

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'ok' => true,
            'request' => $this->getById($id),
            'released' => $release['released'],
            'cancelled' => $release['cancelled'],
            'applied' => $applied,
            'drift' => $drift,
            'signoff_notify' => $signoffNotify,
            'leave_routing' => $leaveRouting,
        ];
    }

    /** "Name (roll, student_id N, user #U)" for audit entries. */
    private function studentLabel($studentId)
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.roll_number, u.id AS user_id, u.name
             FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = :sid"
        );
        $stmt->execute([':sid' => (int) $studentId]);
        $r = $stmt->fetch();
        return $r
            ? sprintf("student '%s' (%s, student_id %d, user #%d)", $r['name'], $r['roll_number'],
                (int) $r['id'], (int) $r['user_id'])
            : 'student_id ' . (int) $studentId;
    }

    /** What moved since the request, and what approving will now do — for the confirm prompt. */
    private function driftMessage(array $drift)
    {
        $ids = [];
        foreach ($drift as $d) {
            $ids[] = $d['was'];
            $ids[] = $d['now'];
            $ids[] = $d['want'];
        }
        $names = [];
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $u) {
                // Stored HTML-escaped; this text goes to a confirm() box.
                $names[(int) $u['id']] = html_entity_decode($u['name'], ENT_QUOTES, 'UTF-8');
            }
        }
        $n = function ($id) use ($names) {
            return $id === null ? 'nobody' : ($names[$id] ?? "user #$id");
        };

        $lines = [];
        foreach ($drift as $col => $d) {
            $line = "{$d['label']}: the request was based on {$n($d['was'])}, but it is now {$n($d['now'])}.";
            $line .= $d['asked']
                ? " Approving will change it to {$n($d['want'])}, replacing that edit."
                : ' The student did not ask to change this, so approving leaves it as it is now.';
            $lines[] = $line;
        }
        return "The office has changed this student's roster since the request was made.\n\n"
            . implode("\n", $lines)
            . "\n\nApprove anyway?";
    }

    /**
     * Move this request's held applications into the normal chain.
     *
     * Called inside decide()'s transaction, after the roster has been updated,
     * so it sees the routing the leaves will actually travel under.
     *
     * A student with no faculty guide even after the decision has nothing to
     * release into — the chain starts at the guide. Those applications are
     * cancelled with an explicit reason rather than left at pending_routing,
     * where no further event would ever reach them and the student would watch
     * a leave sit "pending" forever.
     *
     * Each application gets its own audit entry, against the admin whose
     * decision moved it, in the leave's approval history (module 'approvals',
     * "Ref ID: <leave id>") — before, nothing recorded who released or
     * cancelled it.
     */
    private function releaseHeldLeaves($requestId, $studentId, $actorUserId)
    {
        $routing = $this->currentRouting($studentId);
        $hasGuide = !empty($routing['faculty_guide_id']);

        $stmt = $this->db->prepare(
            "SELECT id FROM leave_applications
             WHERE routing_request_id = :rid AND status = 'pending_routing'"
        );
        $stmt->execute([':rid' => $requestId]);
        $ids = array_column($stmt->fetchAll(), 'id');

        $released = [];
        $cancelled = [];
        if (!$ids) {
            return ['released' => $released, 'cancelled' => $cancelled];
        }

        if ($hasGuide) {
            $upd = $this->db->prepare(
                "UPDATE leave_applications
                    SET status = 'pending_guide', updated_at = NOW()
                  WHERE id = :id AND status = 'pending_routing'"
            );
            foreach ($ids as $lid) {
                $upd->execute([':id' => (int) $lid]);
                $released[] = (int) $lid;
                logSystemAction($actorUserId, 'leave_released_from_routing', 'approvals', sprintf(
                    'Released to the faculty guide (user #%d) by the decision on guide/TA change request '
                    . 'ID %d, student_id %d (Ref ID: %d)',
                    (int) $routing['faculty_guide_id'], $requestId, $studentId, (int) $lid));
            }
        } else {
            $upd = $this->db->prepare(
                "UPDATE leave_applications
                    SET status = 'cancelled',
                        admin_remarks = CONCAT_WS(' ', admin_remarks, :note),
                        updated_at = NOW()
                  WHERE id = :id AND status = 'pending_routing'"
            );
            $note = 'Cancelled automatically: the student still has no Faculty Guide assigned, '
                  . 'so this application cannot enter the approval chain.';
            foreach ($ids as $lid) {
                $upd->execute([':id' => (int) $lid, ':note' => $note]);
                $cancelled[] = (int) $lid;
                logSystemAction($actorUserId, 'leave_cancelled_no_guide', 'approvals', sprintf(
                    'Cancelled: no Faculty Guide after the decision on guide/TA change request ID %d, '
                    . 'student_id %d (Ref ID: %d)', $requestId, $studentId, (int) $lid));
            }
        }

        return ['released' => $released, 'cancelled' => $cancelled];
    }

    /**
     * students row plus its user id, or null.
     *
     * @param bool $forUpdate take a row lock, serialising concurrent requests
     *                        for the same student until the transaction ends.
     */
    private function currentRouting($studentId, $forUpdate = false)
    {
        $stmt = $this->db->prepare(
            "SELECT s.id, s.user_id, s.faculty_guide_id, s.ta_coordinator_id
             FROM students s WHERE s.id = :sid" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':sid' => (int) $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Is this users.id an active faculty member? */
    private function isSelectableFaculty($userId)
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM users WHERE id = :id AND role = 'faculty' AND is_active = 1"
        );
        $stmt->execute([':id' => (int) $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
