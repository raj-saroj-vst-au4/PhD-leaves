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
 *
 * Figures. They used to be frozen when the month was opened, so a leave approved
 * a week later, or an office override, never reached the row, and a disputed row
 * could never be put right. While a row is still pending its figures are now
 * recomputed from the attendance record (refreshPendingRows) — the same backend
 * report the stipend is paid against — and every change bumps figures_version
 * and is recorded in `history` and system_logs. A signer must sign what they
 * were shown: the page posts the version it rendered and act() refuses any
 * other. Once a row is approved its figures are final. A disputed row is put
 * back into the chain by an admin with reopen().
 */
class AttendanceApproval
{
    const PENDING = ['pending_ta', 'pending_guide'];

    private $db;

    /** @var callable|null fn(int $year, int $month): array — see setReportSource() */
    private $reportSource = null;
    /** Report fetches for this request, keyed 'YYYY-MM'. null = fetch failed. */
    private $reportCache = [];
    /** Why the last report fetch failed, for a page to show. */
    private $reportError = null;
    /** @var object|null anything with sendAttendanceApprovalRequest() */
    private $mailer = null;

    public function __construct($db = null)
    {
        $this->db = $db ?: getDBConnection();
    }

    // ------------------------------------------------------------ figures ---

    /**
     * Replace where recomputed figures come from (a CLI harness, a test).
     * The callable gets (year, month) and returns the report array
     * (['rows' => [...], 'working_days' => n, ...]) or throws.
     */
    public function setReportSource(callable $source)
    {
        $this->reportSource = $source;
        $this->reportCache = [];
        $this->reportError = null;
    }

    /** Replace the email sender (a CLI harness). */
    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /** Why the most recent report fetch failed, or null. */
    public function reportError()
    {
        return $this->reportError;
    }

    /**
     * The attendance backend's computed report for a month.
     *
     * Deliberately not recomputed in PHP: working days, and "excused = approved
     * leave OR office-excused", already live in the Python report module. Two
     * implementations would eventually disagree, and the sign-off has to match
     * the report the stipend is paid against.
     *
     * @param int    $timeout seconds; the web paths keep this short so a hung
     *                        backend degrades to "figures not re-checked"
     * @param string $caller  who is asking, carried in the ticket for the logs
     * @throws Exception when the backend is unreachable or returns nothing
     */
    public static function fetchBackendReport($year, $month, $timeout = 300,
                                              $caller = 'portal:attendance-signoff')
    {
        if (!defined('ATT_SHARED_SECRET') || !defined('ATT_BACKEND_URL')) {
            $cfg = __DIR__ . '/../config/attendance.php';
            if (!is_file($cfg)) {
                throw new Exception('attendance module is not configured (config/attendance.php missing)');
            }
            require_once $cfg;
        }

        // att_ticket() needs a logged-in web session, which the cron has not
        // got, and a faculty member's own ticket is not an admin one. The
        // payload format is identical (see config/attendance.php) so the
        // backend's verify_ticket accepts it; uid 0 marks it as the system
        // rather than a person. It is minted and spent here and never leaves
        // loopback.
        $payload = [
            'uid'   => 0,
            'role'  => 'admin',
            'sid'   => null,
            'name'  => $caller,
            'email' => ADMIN_EMAIL,
            'exp'   => time() + 300,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, ATT_SHARED_SECRET, true)), '+/', '-_'), '=');

        $url = rtrim(ATT_BACKEND_URL, '/') . '/api/admin/report/preview?year=' . (int) $year
             . '&month=' . (int) $month;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $body . '.' . $sig],
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $status !== 200) {
            throw new Exception("attendance backend returned HTTP $status" . ($err ? " ($err)" : ''));
        }
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['rows'])) {
            throw new Exception('attendance backend returned no student rows');
        }
        return $data;
    }

    /** One month's report, fetched at most once per request. null on failure. */
    public function monthReport($year, $month, $timeout = 20)
    {
        $key = sprintf('%04d-%02d', $year, $month);
        if (!array_key_exists($key, $this->reportCache)) {
            // One failure means the service is down for this request: do not
            // make a page wait out the timeout again for every other month.
            if ($this->reportError !== null) {
                return $this->reportCache[$key] = null;
            }
            try {
                $this->reportCache[$key] = $this->reportSource
                    ? call_user_func($this->reportSource, (int) $year, (int) $month)
                    : self::fetchBackendReport($year, $month, $timeout);
            } catch (Exception $e) {
                $this->reportCache[$key] = null;
                $this->reportError = $e->getMessage();
                logError("Attendance sign-off: could not fetch figures for $key: " . $e->getMessage());
            }
        }
        return $this->reportCache[$key];
    }

    /**
     * The figures a sign-off row carries, from one report row.
     *
     * The report's own totals are used as they are, with one exception: a
     * student who joined partway through the month. The report counts every
     * past working day with no record as absent, so without this a student
     * enrolled on the 20th would be sent for sign-off with nineteen days of
     * "absences" from before they were a student. Their days are recounted from
     * the report's own per-day codes (P/M/E/A/-), from the joining date on — the
     * classification stays the backend's, only the window is clipped. A code
     * other than those five (the report's 'N', not on the roll) means the report
     * itself did not count that day for the student, so it is left out of the
     * working days too.
     *
     * A report that already clips to each student's enrolment says so by
     * carrying `joining_date` on the row; its totals are then used untouched,
     * so the two can never disagree. The recount is the fallback for a backend
     * that predates that.
     */
    public static function figuresFromReportRow(array $row, $joiningDate = null)
    {
        $fig = [
            'working_days' => (int) ($row['working_days'] ?? 0),
            // NOT present + manual: the report's `manual` is a SUBSET of
            // `present` (its spreadsheet column reads "of which Manual"), so
            // adding them double-counted every office-marked day.
            'present_days' => (int) ($row['present'] ?? 0),
            'excused_days' => (int) ($row['excused'] ?? 0),
            'absent_days'  => (int) ($row['absent'] ?? 0),
            // The report returns null when nothing is accountable (e.g. a month
            // entirely covered by approved leave) and prints "n/a". Coercing that
            // to 0 would show a compliant student as 0%.
            'percentage'   => isset($row['percentage']) && $row['percentage'] !== null
                ? round((float) $row['percentage'], 2) : null,
        ];

        $perDay = $row['per_day'] ?? null;
        if (array_key_exists('joining_date', $row) || !$joiningDate || !is_array($perDay) || !$perDay) {
            return $fig;
        }
        $joiningDate = substr((string) $joiningDate, 0, 10);
        if (min(array_keys($perDay)) >= $joiningDate) {
            return $fig;                       // enrolled for the whole month
        }

        $working = $present = $excused = $absent = 0;
        foreach ($perDay as $day => $code) {
            if ($day < $joiningDate) {
                continue;
            }
            switch ($code) {
                case 'P':
                case 'M':
                    $present++;
                    break;
                case 'E':
                    $excused++;
                    break;
                case 'A':
                    $absent++;
                    break;
                case '-':
                    break;                     // future at generation time
                default:
                    continue 2;                // not a working day for this student
            }
            $working++;
        }
        $denominator = $present + $absent;
        return [
            'working_days' => $working,
            'present_days' => $present,
            'excused_days' => $excused,
            'absent_days'  => $absent,
            'percentage'   => $denominator ? round(100.0 * $present / $denominator, 2) : null,
        ];
    }

    /** The figure columns of a stored row, normalised for comparison. */
    private static function figuresOf(array $rec)
    {
        return [
            'working_days' => (int) $rec['working_days'],
            'present_days' => (int) $rec['present_days'],
            'excused_days' => (int) $rec['excused_days'],
            'absent_days'  => (int) $rec['absent_days'],
            'percentage'   => $rec['percentage'] === null ? null : round((float) $rec['percentage'], 2),
        ];
    }

    private static function figuresDiffer(array $a, array $b)
    {
        foreach (['working_days', 'present_days', 'excused_days', 'absent_days'] as $k) {
            if ((int) $a[$k] !== (int) $b[$k]) {
                return true;
            }
        }
        if (($a['percentage'] === null) !== ($b['percentage'] === null)) {
            return true;
        }
        return $a['percentage'] !== null && abs($a['percentage'] - $b['percentage']) > 0.004;
    }

    /** "20 working: 17 present, 2 excused, 1 absent (89.47%)" — for logs and history. */
    public static function describeFigures(array $f)
    {
        return sprintf('%d working: %d present, %d excused, %d absent (%s)',
            $f['working_days'], $f['present_days'], $f['excused_days'], $f['absent_days'],
            $f['percentage'] === null ? 'n/a' : number_format((float) $f['percentage'], 2) . '%');
    }

    /** The row's history as a list. */
    public static function historyOf(array $rec)
    {
        if (empty($rec['history'])) {
            return [];
        }
        $h = json_decode($rec['history'], true);
        return is_array($h) ? $h : [];
    }

    /**
     * The figures as they stood at a given version, from the history — used to
     * show a guide what the TA coordinator actually approved when the figures
     * have moved since.
     */
    public static function figuresAtVersion(array $rec, $version)
    {
        if ($version === null) {
            return null;
        }
        if ((int) $version === (int) $rec['figures_version']) {
            return self::figuresOf($rec);
        }
        foreach (self::historyOf($rec) as $e) {
            if (($e['event'] ?? '') === 'figures_changed'
                && (int) ($e['previous']['version'] ?? 0) === (int) $version) {
                return $e['previous'];
            }
        }
        return null;
    }

    /** student_id => joining_date for a set of students. */
    private function joiningDates(array $studentIds)
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if (!$studentIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $this->db->prepare("SELECT id, joining_date FROM students WHERE id IN ($in)");
        $stmt->execute($studentIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = $r['joining_date'];
        }
        return $out;
    }

    /**
     * The report row for one student, as sign-off figures, or null when the
     * report cannot be had or no longer lists the student (e.g. deactivated,
     * and the report does not cover them) — the stored figures then stand.
     */
    private function freshFigures($studentId, $year, $month, $joiningDate)
    {
        $report = $this->monthReport($year, $month);
        if (!$report) {
            return null;
        }
        foreach ($report['rows'] as $row) {
            if ((int) $row['student_id'] === (int) $studentId) {
                return self::figuresFromReportRow($row, $joiningDate);
            }
        }
        return null;
    }

    /**
     * Recompute the figures of every row given that is still pending, and
     * write back any that changed.
     *
     * Called when a faculty member opens their sign-off page, when they act
     * (before the version check), and from the admin's explicit re-check.
     * Approved and disputed rows are never touched: an approval is final, and
     * a dispute is reopened deliberately with reopen().
     *
     * @param array  $rows  att_month_approvals rows (at least id, student_id,
     *                      period_year, period_month, status)
     * @param int|null $actorUserId who triggered it, for the audit trail
     * @param string $why   short note for history/logs ("faculty page", …)
     * @return array ['checked' => int, 'changed' => int[] ids, 'error' => ?string]
     */
    public function refreshPendingRows(array $rows, $actorUserId, $why)
    {
        $pending = array_values(array_filter($rows, function ($r) {
            return in_array($r['status'], self::PENDING, true);
        }));
        $out = ['checked' => 0, 'changed' => [], 'error' => null];
        if (!$pending) {
            return $out;
        }
        $joining = $this->joiningDates(array_column($pending, 'student_id'));

        foreach ($pending as $r) {
            $year = (int) $r['period_year'];
            $month = (int) $r['period_month'];
            $fig = $this->freshFigures($r['student_id'], $year, $month,
                $joining[(int) $r['student_id']] ?? null);
            if ($fig === null) {
                if ($this->monthReport($year, $month) === null) {
                    $out['error'] = $this->reportError;
                }
                continue;
            }
            $out['checked']++;
            // Cheap pre-check against the row as read; applyFigures() checks
            // again under the row lock before writing.
            if (isset($r['working_days']) && !self::figuresDiffer(self::figuresOf($r), $fig)) {
                continue;
            }
            if ($this->applyFigures((int) $r['id'], $fig, $actorUserId, $why)) {
                $out['changed'][] = (int) $r['id'];
            }
        }
        return $out;
    }

    /**
     * refreshPendingRows() for a list of ids (the sign-off API). Limited to the
     * rows waiting on $actorUserId at $role: ids come from the browser, and a
     * faculty member's action should not recompute (and be logged against)
     * records that are not theirs.
     */
    public function refreshPendingIds(array $ids, $actorUserId, $role, $why)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !in_array($role, ['ta', 'guide'], true)) {
            return ['checked' => 0, 'changed' => [], 'error' => null];
        }
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM att_month_approvals WHERE id IN ($in) AND $column = ? AND status = ?"
        );
        $stmt->execute(array_merge($ids, [(int) $actorUserId, $status]));
        return $this->refreshPendingRows($stmt->fetchAll(), $actorUserId, $why);
    }

    /** refreshPendingRows() for every pending row of a month (admin re-check). */
    public function refreshPendingMonth($year, $month, $actorUserId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM att_month_approvals
             WHERE period_year = :y AND period_month = :m AND status IN ('pending_ta','pending_guide')"
        );
        $stmt->execute([':y' => $year, ':m' => $month]);
        return $this->refreshPendingRows($stmt->fetchAll(), $actorUserId, 'admin re-check');
    }

    /**
     * Compare a month's SIGNED (approved) rows with the attendance record as
     * it stands now, and flag those that no longer match.
     *
     * Signed figures are never rewritten — they are what was attested. But a
     * leave that was still at the HOD or the office when the month was signed
     * can be approved afterwards, and then the signature is on days that are no
     * longer absent. The flag (outdated_figures: the recomputed figures) is what
     * the admin overview shows, with a reopen button; reopen() re-checks it
     * before acting. A row that matches again has its flag cleared.
     *
     * @return array ['checked' => int, 'outdated' => int[] ids, 'error' => ?string]
     */
    public function checkSignedMonth($year, $month, $actorUserId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM att_month_approvals
             WHERE period_year = :y AND period_month = :m AND status = 'approved'"
        );
        $stmt->execute([':y' => $year, ':m' => $month]);
        $rows = $stmt->fetchAll();
        $out = ['checked' => 0, 'outdated' => [], 'error' => null];
        if (!$rows) {
            return $out;
        }
        $joining = $this->joiningDates(array_column($rows, 'student_id'));
        $flag = $this->db->prepare(
            "UPDATE att_month_approvals SET outdated_figures = :f WHERE id = :id AND status = 'approved'"
        );
        foreach ($rows as $r) {
            $fig = $this->freshFigures($r['student_id'], (int) $year, (int) $month,
                $joining[(int) $r['student_id']] ?? null);
            if ($fig === null) {
                if ($this->monthReport($year, $month) === null) {
                    $out['error'] = $this->reportError;
                }
                continue;
            }
            $out['checked']++;
            $was = $r['outdated_figures'] ? json_decode($r['outdated_figures'], true) : null;
            if (!self::figuresDiffer(self::figuresOf($r), $fig)) {
                if ($was !== null) {
                    $flag->execute([':f' => null, ':id' => (int) $r['id']]);
                }
                continue;
            }
            $out['outdated'][] = (int) $r['id'];
            $known = is_array($was) && !self::figuresDiffer(array_merge(self::figuresOf($r), $was), $fig);
            if ($known) {
                continue;                      // already flagged with these figures
            }
            $flag->execute([':f' => json_encode($fig + ['checked_at' => date('Y-m-d H:i:s')]),
                            ':id' => (int) $r['id']]);
            logSystemAction($actorUserId, 'attendance_signed_figures_outdated', 'attendance', sprintf(
                'Signed sign-off #%d (student_id=%d %04d-%02d) no longer matches the attendance record: '
                . 'signed %s, now %s',
                (int) $r['id'], (int) $r['student_id'], (int) $year, (int) $month,
                self::describeFigures(self::figuresOf($r)), self::describeFigures($fig)));
        }
        return $out;
    }

    /**
     * Write new figures to one pending row if they differ. Row-locked, so a
     * concurrent act() either sees the old version (and the signer's version
     * still matches) or the new one (and is refused) — never a half-update.
     *
     * @return bool whether the figures changed
     */
    private function applyFigures($id, array $fig, $actorUserId, $why)
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM att_month_approvals WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $rec = $stmt->fetch();

            $old = $rec ? self::figuresOf($rec) : null;
            if (!$rec || !in_array($rec['status'], self::PENDING, true) || !self::figuresDiffer($old, $fig)) {
                if ($own) {
                    $this->db->commit();
                }
                return false;
            }

            $history = self::historyOf($rec);
            $history[] = [
                'event'    => 'figures_changed',
                'at'       => date('Y-m-d H:i:s'),
                'by'       => $actorUserId ? (int) $actorUserId : null,
                'note'     => $why,
                'status'   => $rec['status'],
                'previous' => $old + ['version' => (int) $rec['figures_version']],
                'current'  => $fig + ['version' => (int) $rec['figures_version'] + 1],
            ];

            $upd = $this->db->prepare(
                "UPDATE att_month_approvals
                    SET working_days = :w, present_days = :p, excused_days = :e,
                        absent_days = :a, percentage = :pct,
                        figures_version = figures_version + 1,
                        figures_updated_at = NOW(),
                        history = :history
                  WHERE id = :id"
            );
            $upd->execute([
                ':w' => $fig['working_days'], ':p' => $fig['present_days'],
                ':e' => $fig['excused_days'], ':a' => $fig['absent_days'],
                ':pct' => $fig['percentage'],
                ':history' => json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':id' => $id,
            ]);

            logSystemAction($actorUserId, 'attendance_figures_updated', 'attendance', sprintf(
                'Sign-off figures recomputed (%s) for student_id=%d %04d-%02d, record #%d at %s: '
                . 'v%d %s -> v%d %s',
                $why, (int) $rec['student_id'], (int) $rec['period_year'], (int) $rec['period_month'],
                $id, $rec['status'],
                (int) $rec['figures_version'], self::describeFigures($old),
                (int) $rec['figures_version'] + 1, self::describeFigures($fig)));

            if ($own) {
                $this->db->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------ routing ---

    /**
     * How the stages lay out for a given TA and guide, by the rules the month
     * was opened under. null = nobody to route to.
     */
    private static function stagesFor($taId, $guideId)
    {
        // No TA assigned: that stage is skipped and the guide reviews first,
        // exactly as an unassigned TA behaves in the leave chain.
        $hasTa = !empty($taId);
        $hasGuide = !empty($guideId);

        // One person holding both roles is common here. Asking them to
        // approve the same month twice is pure friction and tells us
        // nothing, so collapse it to a single stage — the same reasoning
        // Leave::apply uses when guide and TA coordinator are one person.
        // blockingLeaves() accounts for this: such a TA's leaves wait at
        // pending_guide, not pending_ta.
        if ($hasTa && $hasGuide && (int) $taId === (int) $guideId) {
            $hasGuide = false;
        }

        if ($hasTa) {
            return ['status' => 'pending_ta', 'ta_status' => 'pending',
                    'guide_status' => $hasGuide ? 'pending' : 'skipped'];
        }
        if ($hasGuide) {
            return ['status' => 'pending_guide', 'ta_status' => 'skipped', 'guide_status' => 'pending'];
        }
        return null;
    }

    /** Open a month for sign-off: one row per student in the report. Idempotent.
     *
     * @param array $rows Rows from the attendance report (student_id, working_days,
     *                    present, manual, excused, absent, percentage, per_day).
     * @return array ['created' => int, 'skipped' => int, ...]
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
                $skipped++;                        // no longer a student record
                continue;
            }

            // Someone who joined after this month ended has no attendance to
            // answer for. The report counts every past working day with no record
            // as absent, so without this a student enrolled last week would be
            // sent for sign-off showing a full month of fabricated absences.
            // (A mid-month joiner is handled by figuresFromReportRow.)
            if (!empty($routing['joining_date']) && $routing['joining_date'] > $periodEnd) {
                $notYetJoined[] = $studentId;
                $skipped++;
                continue;
            }

            $stages = self::stagesFor($routing['ta_coordinator_id'], $routing['faculty_guide_id']);
            if ($stages === null) {
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

            $fig = self::figuresFromReportRow($row, $routing['joining_date'] ?? null);

            try {
                $stmt->execute([
                    ':year' => $year, ':month' => $month, ':student_id' => $studentId,
                    ':ta_id' => $routing['ta_coordinator_id'] ?: null,
                    ':guide_id' => $routing['faculty_guide_id'] ?: null,
                    ':working' => $fig['working_days'],
                    ':present' => $fig['present_days'],
                    ':excused' => $fig['excused_days'],
                    ':absent' => $fig['absent_days'],
                    ':pct' => $fig['percentage'],
                    ':status' => $stages['status'], ':ta_status' => $stages['ta_status'],
                    ':guide_status' => $stages['guide_status'],
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
     * Where this person is BOTH guide and TA coordinator for a student, a leave
     * pending at EITHER stage blocks. Both chains collapse for such a student:
     * the sign-off becomes a single TA stage (stagesFor) while the leave waits
     * at pending_guide and has its TA stage settled for it
     * (Approval::approveByGuide). Checking only the stage named in $role meant a
     * TA sign-off looked for pending_ta leaves that student never has, and
     * sailed through with their own undecided leave outstanding.
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
            "SELECT la.id, la.start_date, la.end_date, la.leave_type, la.total_days, la.status,
                    u.name AS student_name, s.roll_number
             FROM leave_applications la
             JOIN students s ON s.id = la.student_id
             JOIN users u ON u.id = s.user_id
             WHERE la.start_date <= :last AND la.end_date >= :first
               AND (($ownerCol = :uid AND la.status = :leave_status)
                    OR (s.faculty_guide_id = :uid_g AND s.ta_coordinator_id = :uid_t
                        AND la.status IN ('pending_guide','pending_ta')))
             ORDER BY la.start_date"
        );
        $stmt->execute([':uid' => $userId, ':uid_g' => $userId, ':uid_t' => $userId,
                        ':leave_status' => $leaveStatus, ':last' => $last, ':first' => $first]);
        return $stmt->fetchAll();
    }

    /**
     * For each row, the student's OWN leave applications that overlap the row's
     * month and are still in flight at any stage (Leave::PENDING_STATUSES).
     *
     * blockingLeaves() hard-blocks only on leaves waiting on the signer
     * themselves, and that is deliberately where the hard block stops. A leave
     * at the HOD, with the office (awaiting a Dean AP document), held for a
     * guide/TA change, or at the other faculty member's stage is not the
     * signer's to decide, and blocking on it would make their sign-off hostage
     * to someone else's queue — a month could not be closed while one
     * application sat with the HOD. It still matters: if it is approved later,
     * days the signer attested as absent become excused. So the signer is
     * WARNED (page and API), and a later approval is recoverable: the admin
     * re-check flags a signed row whose figures no longer match
     * (checkSignedMonth) and reopen() puts it back through sign-off.
     *
     * The case that prompted this: a guide who is also the TA approves a leave
     * as guide, it moves on to the HOD, the block lifts, and they sign the
     * month while those days still count as absent.
     *
     * @return array row id => [leave rows: id, status, leave_type, start_date, end_date]
     */
    public function pendingLeavesForRows(array $rows)
    {
        require_once __DIR__ . '/Leave.php';
        $statuses = Leave::PENDING_STATUSES;
        $out = [];
        $byPeriod = [];
        foreach ($rows as $r) {
            $byPeriod[(int) $r['period_year'] . '-' . (int) $r['period_month']][] = $r;
        }
        foreach ($byPeriod as $key => $periodRows) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $first = sprintf('%04d-%02d-01', $year, $month);
            $last = date('Y-m-t', strtotime($first));
            $studentIds = array_values(array_unique(array_map('intval', array_column($periodRows, 'student_id'))));
            $sin = implode(',', array_fill(0, count($studentIds), '?'));
            $stin = implode(',', array_fill(0, count($statuses), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, student_id, status, leave_type, start_date, end_date
                 FROM leave_applications
                 WHERE student_id IN ($sin) AND status IN ($stin)
                   AND start_date <= ? AND end_date >= ?
                 ORDER BY start_date"
            );
            $stmt->execute(array_merge($studentIds, $statuses, [$last, $first]));
            $byStudent = [];
            foreach ($stmt->fetchAll() as $l) {
                $byStudent[(int) $l['student_id']][] = $l;
            }
            foreach ($periodRows as $r) {
                if (!empty($byStudent[(int) $r['student_id']])) {
                    $out[(int) $r['id']] = $byStudent[(int) $r['student_id']];
                }
            }
        }
        return $out;
    }

    /** Where an in-flight leave is waiting, in words. */
    public static function leaveStageLabel($status)
    {
        $map = [
            'pending_guide'   => 'with the faculty guide',
            'pending_ta'      => 'with the TA coordinator',
            'pending_hod'     => 'with the HOD',
            'pending_admin'   => 'with the office (Dean AP document)',
            'pending_routing' => 'held for a guide/TA change',
        ];
        return $map[$status] ?? $status;
    }

    /**
     * Current guide/TA for a student, or null if there is no such student.
     *
     * Deliberately NOT limited to active accounts. Who is accountable for a
     * month is the attendance report's call: a student deactivated after the
     * month began still owes (and is paid for) the days they were enrolled, and
     * dropping them here meant those days were never signed off at all.
     */
    private function routingFor($studentId)
    {
        $stmt = $this->db->prepare(
            "SELECT s.faculty_guide_id, s.ta_coordinator_id, s.joining_date, u.is_active
             FROM students s JOIN users u ON u.id = s.user_id
             WHERE s.id = :id AND u.role = 'student'"
        );
        $stmt->execute([':id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Bring a student's in-flight sign-offs into line with their CURRENT guide
     * and TA coordinator. Call after any change to students.faculty_guide_id /
     * ta_coordinator_id (a guide/TA change request approved, or the office
     * editing the roster).
     *
     * The rows snapshot their routing so a reassignment cannot silently re-route
     * a stage that has already been ACTED on — but a stage still waiting has not
     * been acted on, and leaving it with the old guide meant it waited forever
     * on someone who is no longer responsible for the student. So:
     *
     *  - nothing signed yet (TA stage pending or skipped): the stages are laid
     *    out afresh from the current routing, exactly as openMonth would today;
     *  - TA already approved, waiting on the guide: only the guide stage moves.
     *    With no guide any more, the TA's approval completes it — act()'s own
     *    rule for a student with no guide — provided the TA approved the figures
     *    as they stand; if they have changed since, it goes back to the TA;
     *  - nobody assigned at all: both ids are cleared and the row stays pending,
     *    which the admin overview flags as "no TA/guide assigned" until the
     *    office fixes the roster (and calls this again).
     *
     * Safe inside the caller's transaction (it will not begin or commit its
     * own then). Sends no email: pass the result to notifyTargets() after the
     * caller has committed.
     *
     * @return array ['changed' => [ids], 'notify' => [[faculty_id, role, year, month], ...]]
     */
    public function rerouteForStudent($studentId, $actorUserId)
    {
        $out = ['changed' => [], 'notify' => []];
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT faculty_guide_id, ta_coordinator_id FROM students WHERE id = :id"
            );
            $stmt->execute([':id' => (int) $studentId]);
            $routing = $stmt->fetch();
            if (!$routing) {
                if ($own) {
                    $this->db->commit();
                }
                return $out;
            }
            $newTa = $routing['ta_coordinator_id'] ? (int) $routing['ta_coordinator_id'] : null;
            $newGuide = $routing['faculty_guide_id'] ? (int) $routing['faculty_guide_id'] : null;

            $stmt = $this->db->prepare(
                "SELECT * FROM att_month_approvals
                 WHERE student_id = :sid AND status IN ('pending_ta','pending_guide')
                 FOR UPDATE"
            );
            $stmt->execute([':sid' => (int) $studentId]);

            foreach ($stmt->fetchAll() as $rec) {
                $to = $this->reroutedState($rec, $newTa, $newGuide);
                $from = [
                    'status' => $rec['status'],
                    'ta_coordinator_id' => $rec['ta_coordinator_id'] ? (int) $rec['ta_coordinator_id'] : null,
                    'faculty_guide_id' => $rec['faculty_guide_id'] ? (int) $rec['faculty_guide_id'] : null,
                    'ta_status' => $rec['ta_status'],
                    'guide_status' => $rec['guide_status'],
                ];
                $compare = array_intersect_key($to, $from);
                if ($compare == $from && !isset($to['reset_ta'])) {
                    continue;
                }

                // Whoever the row now waits on, and whether that is a different
                // person (or stage) from before — only then is anyone emailed.
                $assignee = function ($state) {
                    if ($state['status'] === 'pending_ta') {
                        return ['ta', $state['ta_coordinator_id']];
                    }
                    if ($state['status'] === 'pending_guide') {
                        return ['guide', $state['faculty_guide_id']];
                    }
                    return [null, null];
                };
                [$oldRole, $oldWho] = $assignee($from);
                [$newRole, $newWho] = $assignee($to);
                $newDesk = $newRole !== null && ($newRole !== $oldRole || $newWho !== $oldWho
                                                 || isset($to['reset_ta']));

                $history = self::historyOf($rec);
                $event = [
                    'event' => 'rerouted',
                    'at'    => date('Y-m-d H:i:s'),
                    'by'    => $actorUserId ? (int) $actorUserId : null,
                    'from'  => $from,
                    'to'    => $compare,
                ];
                if (isset($to['reset_ta'])) {
                    // The TA's approval is being cleared; keep what it was.
                    $event['cleared_ta'] = [
                        'by' => $rec['ta_acted_by'] ? (int) $rec['ta_acted_by'] : null,
                        'at' => $rec['ta_acted_at'], 'remarks' => $rec['ta_remarks'],
                        'figures_version' => $rec['ta_figures_version'] !== null
                            ? (int) $rec['ta_figures_version'] : null,
                    ];
                }
                $history[] = $event;

                $sets = "status = :status, ta_coordinator_id = :ta, faculty_guide_id = :guide,
                         ta_status = :ta_status, guide_status = :guide_status, history = :history";
                if (isset($to['reset_ta'])) {
                    $sets .= ", ta_remarks = NULL, ta_acted_at = NULL, ta_acted_by = NULL,
                               ta_figures_version = NULL";
                }
                if ($newDesk) {
                    $sets .= ', notified_at = NULL';
                }
                $upd = $this->db->prepare("UPDATE att_month_approvals SET $sets WHERE id = :id");
                $upd->execute([
                    ':status' => $to['status'],
                    ':ta' => $to['ta_coordinator_id'],
                    ':guide' => $to['faculty_guide_id'],
                    ':ta_status' => $to['ta_status'],
                    ':guide_status' => $to['guide_status'],
                    ':history' => json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ':id' => (int) $rec['id'],
                ]);

                $fmt = function ($v) { return $v === null ? 'none' : (int) $v; };
                logSystemAction($actorUserId, 'attendance_approval_rerouted', 'attendance', sprintf(
                    'Sign-off record #%d (student_id=%d %04d-%02d) re-routed after a guide/TA change: '
                    . '%s ta=%s guide=%s -> %s ta=%s guide=%s%s',
                    (int) $rec['id'], (int) $rec['student_id'],
                    (int) $rec['period_year'], (int) $rec['period_month'],
                    $from['status'], $fmt($from['ta_coordinator_id']), $fmt($from['faculty_guide_id']),
                    $to['status'], $fmt($to['ta_coordinator_id']), $fmt($to['faculty_guide_id']),
                    isset($to['reset_ta']) ? ' (TA approval cleared: figures changed since it was given)' : ''));

                $out['changed'][] = (int) $rec['id'];
                if ($newDesk && $newWho) {
                    $out['notify'][] = [$newWho, $newRole, (int) $rec['period_year'], (int) $rec['period_month']];
                }
            }

            if ($own) {
                $this->db->commit();
            }
            return $out;
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Where a pending row should now be, given the current routing. */
    private function reroutedState(array $rec, $newTa, $newGuide)
    {
        if ($rec['ta_status'] !== 'approved') {
            // Nothing signed yet: lay the stages out afresh.
            $stages = self::stagesFor($newTa, $newGuide);
            if ($stages === null) {
                return ['status' => 'pending_ta', 'ta_coordinator_id' => null, 'faculty_guide_id' => null,
                        'ta_status' => 'pending', 'guide_status' => 'pending'];
            }
            return $stages + ['ta_coordinator_id' => $newTa, 'faculty_guide_id' => $newGuide];
        }

        // The TA stage is done and stays with whoever did it.
        $ta = $rec['ta_coordinator_id'] ? (int) $rec['ta_coordinator_id'] : null;
        $taSignedCurrent = (int) $rec['ta_figures_version'] === (int) $rec['figures_version'];
        // The new guide IS the person who already approved as TA: by the
        // collapse rule in stagesFor() one person holding both roles signs
        // once, and they have — provided it was these figures. Sending it back
        // to them as guide would ask the same person to approve the same
        // numbers twice. If the figures have moved since, they sign again.
        if ($newGuide && $taSignedCurrent && (int) $rec['ta_acted_by'] === $newGuide) {
            return ['status' => 'approved', 'ta_coordinator_id' => $ta, 'faculty_guide_id' => $newGuide,
                    'ta_status' => 'approved', 'guide_status' => 'skipped'];
        }
        if ($newGuide) {
            return ['status' => 'pending_guide', 'ta_coordinator_id' => $ta, 'faculty_guide_id' => $newGuide,
                    'ta_status' => 'approved', 'guide_status' => 'pending'];
        }
        if ($taSignedCurrent) {
            return ['status' => 'approved', 'ta_coordinator_id' => $ta, 'faculty_guide_id' => null,
                    'ta_status' => 'approved', 'guide_status' => 'skipped'];
        }
        // No guide to sign the figures the TA never saw: back to the TA.
        if ($newTa) {
            return ['status' => 'pending_ta', 'ta_coordinator_id' => $newTa, 'faculty_guide_id' => null,
                    'ta_status' => 'pending', 'guide_status' => 'skipped', 'reset_ta' => true];
        }
        return ['status' => 'pending_ta', 'ta_coordinator_id' => null, 'faculty_guide_id' => null,
                'ta_status' => 'pending', 'guide_status' => 'pending', 'reset_ta' => true];
    }

    // ------------------------------------------------------------- queries ---

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

    // ------------------------------------------------------------ decisions ---

    /**
     * Record one decision.
     *
     * @param string   $role           'ta' | 'guide' — the stage being acted on
     * @param string   $decision       'approve' | 'reject'
     * @param int|null $figuresVersion the figures_version the signer was shown;
     *                                 anything else is refused
     * @return array ['ok' => bool, 'message' => string, 'new_status' => string|null,
     *                'stale' => bool]
     */
    public function act($id, $userId, $role, $decision, $remarks, $figuresVersion = null)
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

            // A signature is on specific numbers. If the figures were recomputed
            // after this page was rendered (a leave approved, an office
            // override), the signer has not seen what they would be signing —
            // make them look again rather than record an attestation to figures
            // that were never on their screen. A missing version is a page from
            // before this check existed, and is treated the same way.
            $current = (int) $rec['figures_version'];
            if ($figuresVersion === null || $figuresVersion === '' || (int) $figuresVersion !== $current) {
                $this->db->rollBack();
                return ['ok' => false, 'stale' => true, 'new_status' => null,
                        'message' => 'The attendance figures for this student have changed since the page was '
                            . 'loaded (now ' . self::describeFigures(self::figuresOf($rec)) . '). '
                            . 'Reload the page and review them before signing.'];
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
            // A hand-off puts the row on a new desk, which has not been told yet.
            $notified = $newStatus === 'pending_guide' ? ', notified_at = NULL' : '';
            $upd = $this->db->prepare(
                "UPDATE att_month_approvals
                    SET status = :status,
                        {$stageCol}_status = :stage_status,
                        {$stageCol}_remarks = :remarks,
                        {$stageCol}_acted_at = NOW(),
                        {$stageCol}_acted_by = :uid,
                        {$stageCol}_figures_version = figures_version
                        $notified
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

            // The figures go in the entry: it is the record of what was attested.
            // Leaves still in flight elsewhere did not block (see
            // pendingLeavesForRows); say so, and put it on the record.
            $inFlight = $decision === 'approve' ? ($this->pendingLeavesForRows([$rec])[(int) $id] ?? []) : [];

            logSystemAction($userId, 'attendance_approval_' . $decision, 'attendance',
                sprintf('%s %s attendance for student_id=%d %04d-%02d (now %s); figures v%d: %s%s',
                    $role === 'ta' ? 'TA coordinator' : 'Faculty guide', $decision,
                    (int) $rec['student_id'], (int) $rec['period_year'], (int) $rec['period_month'],
                    $newStatus, $current, self::describeFigures(self::figuresOf($rec)),
                    $inFlight ? '; signed with leave(s) still pending: ' . implode(', ', array_map(
                        function ($l) { return '#' . (int) $l['id'] . ' ' . $l['status']; }, $inFlight)) : ''));

            return ['ok' => true, 'new_status' => $newStatus, 'pending_leaves' => $inFlight,
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

    /**
     * Put a disputed row — or a signed one whose figures turned out wrong —
     * back into the chain (admin).
     *
     * A dispute used to be terminal: the row stayed "rejected" for good, even
     * after the office had corrected the attendance underneath it, so the month
     * could never be signed. Reopening recomputes the figures from the record as
     * it now stands and sends the row to the first stage of its CURRENT routing
     * (TA coordinator, or the guide when there is no TA). The decisions being
     * cleared — who made them, when, and why — are kept in `history` and in
     * system_logs, since the stage columns are reset for the fresh round.
     *
     * An APPROVED row can be reopened only when its recomputed figures differ
     * from the ones that were signed: a leave that was still at the HOD (or
     * with the office, or at the other faculty member's stage) when the month
     * was signed, and approved afterwards, is the case this exists for — see
     * pendingLeavesForRows(). A signature on figures that are still true is not
     * the office's to undo.
     *
     * A deactivated guide/TA is treated as unassigned: routing a record to
     * someone who can no longer log in would leave it unsignable.
     *
     * Refused when the figures cannot be recomputed: reopening onto the same
     * figures that were disputed would just invite the same dispute.
     *
     * @return array ['ok' => bool, 'message' => string, 'notify' => [...]]
     */
    public function reopen($id, $adminUserId, $reason)
    {
        $reason = trim((string) $reason);
        if (strlen($reason) < 5) {
            return ['ok' => false, 'message' => 'Say why the record is being reopened (at least 5 characters).',
                    'notify' => []];
        }
        $rec = $this->getById($id);
        if (!$rec) {
            return ['ok' => false, 'message' => 'Record not found.', 'notify' => []];
        }
        if (!in_array($rec['status'], ['rejected', 'approved'], true)) {
            return ['ok' => false, 'message' => 'This record is still with the faculty; there is nothing to reopen.',
                    'notify' => []];
        }
        $wasStatus = $rec['status'];

        // Fetched before the transaction: an HTTP call must not hold row locks.
        $joining = $this->joiningDates([$rec['student_id']]);
        $fig = $this->freshFigures($rec['student_id'], (int) $rec['period_year'], (int) $rec['period_month'],
            $joining[(int) $rec['student_id']] ?? null);
        if ($fig === null) {
            return ['ok' => false, 'notify' => [],
                    'message' => 'Could not recompute the figures from the attendance record ('
                        . ($this->reportError ?: 'the student is not in this month\'s report')
                        . '). Nothing was changed; try again once the attendance service is available.'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, s.faculty_guide_id AS cur_guide, s.ta_coordinator_id AS cur_ta,
                        cg.is_active AS cur_guide_active, ct.is_active AS cur_ta_active
                 FROM att_month_approvals a JOIN students s ON s.id = a.student_id
                 LEFT JOIN users cg ON cg.id = s.faculty_guide_id
                 LEFT JOIN users ct ON ct.id = s.ta_coordinator_id
                 WHERE a.id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $id]);
            $rec = $stmt->fetch();
            if (!$rec || $rec['status'] !== $wasStatus) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'This record changed while you were looking at it; reload and '
                    . 'try again.', 'notify' => []];
            }

            $old = self::figuresOf($rec);
            $changed = self::figuresDiffer($old, $fig);
            if ($wasStatus === 'approved' && !$changed) {
                $this->db->rollBack();
                return ['ok' => false, 'notify' => [],
                        'message' => 'The signed figures still match the attendance record ('
                            . self::describeFigures($fig) . '), so there is nothing to correct.'];
            }
            $newVersion = (int) $rec['figures_version'] + ($changed ? 1 : 0);

            $ta = $rec['cur_ta'] && (int) $rec['cur_ta_active'] === 1 ? (int) $rec['cur_ta'] : null;
            $guide = $rec['cur_guide'] && (int) $rec['cur_guide_active'] === 1 ? (int) $rec['cur_guide'] : null;
            $stages = self::stagesFor($ta, $guide);
            if ($stages === null) {
                $this->db->rollBack();
                return ['ok' => false, 'notify' => [],
                        'message' => 'This student has no active Faculty Guide or TA Coordinator, so there is '
                            . 'nobody to send the record to. Assign one in Users first.'];
            }

            $history = self::historyOf($rec);
            $stage = function ($p) use ($rec) {
                return [
                    'status' => $rec[$p . '_status'],
                    'by' => $rec[$p . '_acted_by'] ? (int) $rec[$p . '_acted_by'] : null,
                    'at' => $rec[$p . '_acted_at'],
                    'remarks' => $rec[$p . '_remarks'],
                    'figures_version' => $rec[$p . '_figures_version'] !== null
                        ? (int) $rec[$p . '_figures_version'] : null,
                ];
            };
            $history[] = [
                'event'    => 'reopened',
                'at'       => date('Y-m-d H:i:s'),
                'by'       => (int) $adminUserId,
                'reason'   => $reason,
                'was'      => $wasStatus,
                'ta'       => $stage('ta'),
                'guide'    => $stage('guide'),
                'previous' => $old + ['version' => (int) $rec['figures_version']],
                'current'  => $fig + ['version' => $newVersion],
                'routed_to' => ['status' => $stages['status'], 'ta_coordinator_id' => $ta,
                                'faculty_guide_id' => $guide],
            ];

            $upd = $this->db->prepare(
                "UPDATE att_month_approvals
                    SET status = :status, ta_status = :ta_status, guide_status = :guide_status,
                        ta_coordinator_id = :ta, faculty_guide_id = :guide,
                        ta_remarks = NULL, ta_acted_at = NULL, ta_acted_by = NULL, ta_figures_version = NULL,
                        guide_remarks = NULL, guide_acted_at = NULL, guide_acted_by = NULL,
                        guide_figures_version = NULL,
                        working_days = :w, present_days = :p, excused_days = :e, absent_days = :a,
                        percentage = :pct,
                        figures_version = :v,
                        figures_updated_at = IF(:changed = 1, NOW(), figures_updated_at),
                        notified_at = NULL,
                        outdated_figures = NULL,
                        history = :history
                  WHERE id = :id"
            );
            $upd->execute([
                ':status' => $stages['status'], ':ta_status' => $stages['ta_status'],
                ':guide_status' => $stages['guide_status'],
                ':ta' => $ta, ':guide' => $guide,
                ':w' => $fig['working_days'], ':p' => $fig['present_days'], ':e' => $fig['excused_days'],
                ':a' => $fig['absent_days'], ':pct' => $fig['percentage'],
                ':v' => $newVersion, ':changed' => $changed ? 1 : 0,
                ':history' => json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':id' => $id,
            ]);

            // Whose decision is being set aside: the dispute, or the final signature.
            $cleared = $rec['guide_status'] === 'rejected' || $rec['guide_status'] === 'approved'
                ? $stage('guide') : $stage('ta');
            logSystemAction($adminUserId, 'attendance_approval_reopened', 'attendance', sprintf(
                'Reopened %s sign-off #%d for student_id=%d %04d-%02d -> %s. Reason: %s. '
                . 'Cleared %s by user #%s on %s ("%s"). Figures v%d %s -> v%d %s',
                $wasStatus === 'approved' ? 'signed' : 'disputed',
                $id, (int) $rec['student_id'], (int) $rec['period_year'], (int) $rec['period_month'],
                $stages['status'], $reason,
                $wasStatus === 'approved' ? 'approval' : 'dispute',
                $cleared['by'] ?? '?', $cleared['at'] ?? '?', (string) $cleared['remarks'],
                (int) $rec['figures_version'], self::describeFigures($old),
                $newVersion, self::describeFigures($fig)));

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $role = $stages['status'] === 'pending_ta' ? 'ta' : 'guide';
        return ['ok' => true,
                'message' => 'Reopened and sent to the ' . ($role === 'ta' ? 'TA coordinator' : 'faculty guide')
                    . ($changed ? ' with recomputed figures (' . self::describeFigures($fig) . ').'
                               : '. The figures are unchanged (' . self::describeFigures($fig) . ').'),
                'notify' => [[$role === 'ta' ? $ta : $guide, $role,
                              (int) $rec['period_year'], (int) $rec['period_month']]]];
    }

    // ------------------------------------------------------- notifications ---

    /**
     * Faculty who now have rows waiting at a given stage — used by the admin
     * "who to chase" list.
     *
     * Deactivated faculty are INCLUDED (is_active says which): a record waiting
     * on someone who can no longer log in is exactly the one the office must
     * act on, by reassigning the student's guide/TA. Filtering them out made
     * those records vanish from the list while nobody could ever sign them.
     */
    public function facultyAwaiting($year, $month, $role)
    {
        $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
        $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, u.email, u.is_active, COUNT(*) AS pending_count
             FROM att_month_approvals a
             JOIN users u ON u.id = a.$column
             WHERE a.period_year = :year AND a.period_month = :month
               AND a.status = :status
             GROUP BY u.id, u.name, u.email, u.is_active
             ORDER BY u.is_active ASC, u.name"
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

    /**
     * Email each faculty member the rows now waiting on them for a month that
     * nobody has told them about yet — one combined message per person and
     * stage — and mark those rows notified.
     *
     * notified_at is what makes this safe to call again: the cron's catch-up
     * run used to re-send the whole month to every faculty member; now a re-run
     * reaches only rows opened since (or whose earlier email failed).
     *
     * Rows are CLAIMED before the email goes out, not marked after it: the cron
     * and a TA hand-off (or two hand-offs) can run at the same moment, and both
     * would otherwise read the same un-notified rows and email them twice. The
     * claim is a locked read plus a stamp; whoever gets there second finds
     * nothing left to send. If the email then fails, the stamp is taken back
     * (only if it is still ours), so the next run retries.
     *
     * @param array|null $only restrict to these "role:faculty_id" keys
     * @return array ['sent' => ['ta' => n, 'guide' => n], 'emailed' => [...],
     *                'failed' => [...], 'inactive' => [...]]
     */
    public function notifyUnnotified($year, $month, ?array $only = null)
    {
        $result = ['sent' => ['ta' => 0, 'guide' => 0], 'emailed' => [], 'failed' => [], 'inactive' => []];
        $label = DateTime::createFromFormat('!Y-n-j', (int) $year . '-' . (int) $month . '-1')->format('F Y');

        foreach (['ta', 'guide'] as $role) {
            $column = $role === 'ta' ? 'ta_coordinator_id' : 'faculty_guide_id';
            $status = $role === 'ta' ? 'pending_ta' : 'pending_guide';
            $stmt = $this->db->prepare(
                "SELECT a.*, s.roll_number, u.name AS student_name
                 FROM att_month_approvals a
                 JOIN students s ON s.id = a.student_id
                 JOIN users u ON u.id = s.user_id
                 WHERE a.period_year = :year AND a.period_month = :month
                   AND a.status = :status AND a.notified_at IS NULL AND a.$column IS NOT NULL
                 ORDER BY s.roll_number"
            );
            $stmt->execute([':year' => $year, ':month' => $month, ':status' => $status]);

            $byFaculty = [];
            foreach ($stmt->fetchAll() as $r) {
                $byFaculty[(int) $r[$column]][] = $r;
            }

            foreach ($byFaculty as $facultyId => $rows) {
                if ($only !== null && !in_array("$role:$facultyId", $only, true)) {
                    continue;
                }
                $u = $this->db->prepare("SELECT id, name, email, is_active FROM users WHERE id = :id");
                $u->execute([':id' => $facultyId]);
                $faculty = $u->fetch();
                if (!$faculty || (int) $faculty['is_active'] !== 1) {
                    // Nobody to email. Reported rather than dropped: the admin
                    // overview flags these, and the cron names them.
                    $result['inactive'][] = ['faculty_id' => $facultyId, 'role' => $role, 'count' => count($rows)];
                    continue;
                }

                [$stamp, $claimed] = $this->claimForNotification(
                    array_map('intval', array_column($rows, 'id')), $status);
                if (!$claimed) {
                    continue;                  // another caller got there first
                }
                $rows = array_values(array_filter($rows, function ($r) use ($claimed) {
                    return in_array((int) $r['id'], $claimed, true);
                }));

                // A failed email must not fail whatever committed before it —
                // the work is still visible on the faculty member's page, and
                // the claim is released so the next cron run tries again.
                $ok = false;
                try {
                    $ok = $this->mailer()->sendAttendanceApprovalRequest($faculty, $role, $label, $rows);
                } catch (Exception $e) {
                    logError('Attendance sign-off: notification to user_id=' . $facultyId
                        . ' failed: ' . $e->getMessage());
                }
                if (!$ok) {
                    $in = implode(',', array_fill(0, count($claimed), '?'));
                    $this->db->prepare(
                        "UPDATE att_month_approvals SET notified_at = NULL
                         WHERE id IN ($in) AND notified_at = ?"
                    )->execute(array_merge($claimed, [$stamp]));
                    $result['failed'][] = ['faculty_id' => $facultyId, 'role' => $role];
                    continue;
                }

                $result['sent'][$role]++;
                $result['emailed'][] = ['faculty_id' => $facultyId, 'role' => $role, 'count' => count($rows)];
            }
        }
        return $result;
    }

    /**
     * Stamp notified_at on those of $ids still un-notified at $status, and say
     * which they were. Row-locked, so of two concurrent callers exactly one
     * claims each row.
     *
     * @return array [string $stamp, int[] $claimedIds]
     */
    private function claimForNotification(array $ids, $status)
    {
        if (!$ids) {
            return [null, []];
        }
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sel = $this->db->prepare(
                "SELECT id FROM att_month_approvals
                 WHERE id IN ($in) AND notified_at IS NULL AND status = ? FOR UPDATE"
            );
            $sel->execute(array_merge($ids, [$status]));
            $claimed = array_map('intval', array_column($sel->fetchAll(), 'id'));
            $stamp = date('Y-m-d H:i:s');
            if ($claimed) {
                $in = implode(',', array_fill(0, count($claimed), '?'));
                $this->db->prepare("UPDATE att_month_approvals SET notified_at = ? WHERE id IN ($in)")
                    ->execute(array_merge([$stamp], $claimed));
            }
            if ($own) {
                $this->db->commit();
            }
            return [$stamp, $claimed];
        } catch (Exception $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Months that still have a pending row nobody has been emailed about — an
     * email that failed, or a hand-off whose email failed. The cron retries
     * these on every run, including runs that find their own month already
     * open (and so would otherwise exit before emailing anything).
     *
     * @return array [[year, month], ...]
     */
    public function periodsWithUnnotified()
    {
        $rows = $this->db->query(
            "SELECT DISTINCT period_year, period_month FROM att_month_approvals
             WHERE status IN ('pending_ta','pending_guide') AND notified_at IS NULL
               AND ((status = 'pending_ta' AND ta_coordinator_id IS NOT NULL)
                 OR (status = 'pending_guide' AND faculty_guide_id IS NOT NULL))
             ORDER BY period_year, period_month"
        )->fetchAll();
        return array_map(function ($r) {
            return [(int) $r['period_year'], (int) $r['period_month']];
        }, $rows);
    }

    /**
     * Send the notifications a rerouteForStudent()/reopen()/hand-off produced.
     * Call after the caller's transaction has committed.
     *
     * @param array $targets [[faculty_id, role, year, month], ...]
     * @return int emails sent
     */
    public function notifyTargets(array $targets)
    {
        $byPeriod = [];
        foreach ($targets as $t) {
            [$facultyId, $role, $year, $month] = $t;
            if ($facultyId) {
                $byPeriod[$year . '-' . $month][] = "$role:" . (int) $facultyId;
            }
        }
        $sent = 0;
        foreach ($byPeriod as $period => $keys) {
            [$year, $month] = array_map('intval', explode('-', $period));
            $r = $this->notifyUnnotified($year, $month, array_values(array_unique($keys)));
            $sent += $r['sent']['ta'] + $r['sent']['guide'];
        }
        return $sent;
    }

    private function mailer()
    {
        if (!$this->mailer) {
            require_once __DIR__ . '/../includes/EmailService.php';
            $this->mailer = new EmailService();
        }
        return $this->mailer;
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
                    stu.name  AS student_name, stu.is_active AS student_active,
                    ta.name   AS ta_name,   ta.email   AS ta_email, ta.is_active AS ta_active,
                    g.name    AS guide_name, g.email   AS guide_email, g.is_active AS guide_active,
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
                    'active' => (int) $f['is_active'] === 1,
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
