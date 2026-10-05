<?php
/**
 * Admin — monthly attendance sign-off status.
 *
 * Oversight: where every student's month has got to, and which faculty member
 * is holding it up. Admins deliberately cannot approve on a faculty member's
 * behalf — the sign-off is their attestation, and a proxy approval would make
 * the audit trail a fiction. To correct the underlying attendance an admin uses
 * Attendance → override, which supersedes the record properly.
 *
 * What the office CAN do here (admin only, not the HOD's read-only view):
 *   - re-check a month's pending figures against the attendance record now,
 *     rather than waiting for a faculty member to open their page;
 *   - reopen a disputed record once the dispute is resolved: its figures are
 *     recomputed and it goes back to the first stage (TA, or the guide when
 *     there is no TA). The dispute it clears is kept in the record's history;
 *   - the same for a SIGNED record the re-check found no longer matches the
 *     attendance record (typically a leave that was still at the HOD when the
 *     month was signed, approved afterwards).
 * Records waiting on a deactivated faculty member are flagged: nobody can sign
 * them until the student's guide/TA is reassigned in Users.
 */

// POST handlers run before header.php: they redirect.
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/AttendanceApproval.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    checkCSRF();
    // Reopening rewrites who a record waits on and clears a faculty member's
    // recorded dispute; it must be traceable to the admin who really did it.
    blockIfImpersonating('Sign-off records cannot be changed while impersonating another user.');

    $back = BASE_URL . '/admin/attendance_approvals.php';
    $period = $_POST['period'] ?? '';
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        $back .= '?period=' . $period;
    }

    $approval = new AttendanceApproval();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'reopen') {
            $result = $approval->reopen((int) ($_POST['id'] ?? 0), getCurrentUserId(),
                sanitize($_POST['reason'] ?? ''));
            if ($result['ok']) {
                $sent = $approval->notifyTargets($result['notify']);
                setFlashMessage('success', $result['message']
                    . ($sent ? ' They have been emailed.' : ' (The notification email could not be sent; '
                        . 'it is on their sign-off page.)'));
            } else {
                setFlashMessage('error', $result['message']);
            }
        } elseif ($action === 'refresh' && preg_match('/^(\d{4})-(\d{2})$/', $period, $pm)) {
            $result = $approval->refreshPendingMonth((int) $pm[1], (int) $pm[2], getCurrentUserId());
            $signed = $approval->checkSignedMonth((int) $pm[1], (int) $pm[2], getCurrentUserId());
            $error = $result['error'] ?? $signed['error'];
            if ($error !== null && $result['checked'] + $signed['checked'] === 0) {
                setFlashMessage('error', 'Could not reach the attendance service to recompute the figures: '
                    . $error);
            } else {
                $n = count($result['changed']);
                $o = count($signed['outdated']);
                setFlashMessage($o ? 'warning' : 'success',
                    "Re-checked {$result['checked']} pending record(s) against the attendance record: "
                    . ($n ? "$n had changed and were updated." : 'none had changed.')
                    . " Checked {$signed['checked']} signed record(s): "
                    . ($o ? "$o no longer match what was signed — they are marked below and can be reopened."
                          : 'all still match.'));
            }
        } else {
            setFlashMessage('error', 'Unknown action.');
        }
    } catch (Exception $e) {
        logError('Attendance sign-off admin action failed: ' . $e->getMessage());
        setFlashMessage('error', 'Could not complete that. Please try again.');
    }
    header('Location: ' . $back);
    exit();
}

$pageTitle = 'Attendance Sign-off Status';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'hod']);

$approval = new AttendanceApproval();
$periods = $approval->availablePeriods();
$canManage = getCurrentUserRole() === 'admin';

// Selected period: ?period=YYYY-MM, defaulting to the newest that exists.
$sel = sanitize($_GET['period'] ?? '');
$year = $month = null;
if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $sel, $m)) {
    $year = (int) $m[1];
    $month = (int) $m[2];
} elseif ($periods) {
    $year = (int) $periods[0]['period_year'];
    $month = (int) $periods[0]['period_month'];
}

$rows = $summary = $outstanding = [];
$periodLabel = '—';
$periodKey = '';
$userNames = [];
if ($year && $month) {
    $rows = $approval->getMonthOverview($year, $month);
    $summary = $approval->monthSummary($year, $month);
    $outstanding = $approval->outstandingByFaculty($year, $month);
    $periodLabel = DateTime::createFromFormat('!Y-n-j', "$year-$month-1")->format('F Y');
    $periodKey = $year . '-' . sprintf('%02d', $month);

    // Names for the people a record's history mentions.
    $ids = [];
    foreach ($rows as $r) {
        foreach (AttendanceApproval::historyOf($r) as $e) {
            $ids[] = $e['by'] ?? null;
            $ids[] = $e['ta']['by'] ?? null;
            $ids[] = $e['guide']['by'] ?? null;
        }
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDBConnection()->prepare("SELECT id, name FROM users WHERE id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $u) {
            $userNames[(int) $u['id']] = $u['name'];
        }
    }
}

/** Badge for the overall state of one student-month. */
function signoffBadge($status)
{
    $map = [
        'pending_ta'    => ['badge-pending', 'fa-chalkboard-teacher', 'Awaiting TA'],
        'pending_guide' => ['badge-routing', 'fa-user-tie', 'Awaiting Guide'],
        'approved'      => ['badge-approved', 'fa-check', 'Approved'],
        'rejected'      => ['badge-rejected', 'fa-flag', 'Disputed'],
    ];
    [$cls, $icon, $label] = $map[$status] ?? ['badge-neutral', null, $status];
    return '<span class="badge ' . $cls . '">'
        . ($icon ? '<i class="fas ' . $icon . ' text-[10px]"></i>' : '')
        . $label . '</span>';
}

/** Per-stage cell: decision, who made it, and when. */
function stageCell($status, $actorName, $actedAt, $remarks)
{
    if ($status === 'skipped') {
        return '<span class="text-xs text-gray-400">not applicable</span>';
    }
    if ($status === 'pending') {
        return '<span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-700">'
            . '<span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>pending</span>';
    }
    $icon = $status === 'approved'
        ? '<i class="fas fa-check text-emerald-600 mr-1"></i>'
        : '<i class="fas fa-flag text-rose-600 mr-1"></i>';
    $out = '<div class="text-xs text-gray-600">' . $icon . ($status === 'approved' ? 'approved' : 'disputed');
    if ($actorName) {
        $out .= ' by ' . sanitize($actorName);
    }
    if ($actedAt) {
        $out .= '<div class="mt-0.5 text-gray-400">' . formatDate($actedAt) . '</div>';
    }
    $out .= '</div>';
    if ($remarks) {
        $out .= '<div class="mt-1.5 max-w-xs rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs italic text-gray-600 ring-1 ring-inset ring-gray-100">&ldquo;'
            . sanitize($remarks) . '&rdquo;</div>';
    }
    return $out;
}

/** One line per history event: figure changes, reopens, re-routes. */
function historyLines(array $row, array $names)
{
    $who = function ($id) use ($names) {
        return $id ? sanitize($names[(int) $id] ?? ('user #' . (int) $id)) : 'system';
    };
    $lines = [];
    foreach (AttendanceApproval::historyOf($row) as $e) {
        $when = formatDate($e['at'] ?? '');
        switch ($e['event'] ?? '') {
            case 'figures_changed':
                $p = $e['previous'] ?? [];
                $c = $e['current'] ?? [];
                $lines[] = "$when: figures recomputed (" . sanitize($e['note'] ?? '') . ') &mdash; '
                    . (int) ($p['present_days'] ?? 0) . '/' . (int) ($p['excused_days'] ?? 0) . '/'
                    . (int) ($p['absent_days'] ?? 0) . ' &rarr; '
                    . (int) ($c['present_days'] ?? 0) . '/' . (int) ($c['excused_days'] ?? 0) . '/'
                    . (int) ($c['absent_days'] ?? 0) . ' <span class="text-gray-400">(P/E/A)</span>';
                break;
            case 'reopened':
                $signed = ($e['was'] ?? 'rejected') === 'approved';
                $g = $e['guide']['status'] ?? '';
                $d = $g === 'rejected' || ($signed && $g === 'approved') ? $e['guide'] : ($e['ta'] ?? []);
                $lines[] = "$when: reopened by " . $who($e['by'] ?? null) . ' &mdash; &ldquo;'
                    . sanitize($e['reason'] ?? '') . '&rdquo;. Cleared '
                    . ($signed ? 'the approval' : 'the dispute') . ' by '
                    . $who($d['by'] ?? null) . ($d['remarks'] ?? '' ? ': &ldquo;' . sanitize($d['remarks']) . '&rdquo;' : '');
                break;
            case 'rerouted':
                $lines[] = "$when: re-routed after a guide/TA change by " . $who($e['by'] ?? null)
                    . ' (' . sanitize($e['from']['status'] ?? '') . ' &rarr; '
                    . sanitize($e['to']['status'] ?? '') . ')';
                break;
        }
    }
    return $lines;
}
?>

<?php
// Presentation only: initials for avatars (names are stored HTML-escaped, so
// decode before taking letters; the result is sanitize()d on output).
$initialsOf = function ($name) {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
};
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Attendance</p>
        <h1 class="page-title">Attendance Sign-off Status</h1>
        <p class="page-subtitle">
            Monthly attendance approvals: TA coordinator first, then faculty guide.
        </p>
    </div>
    <?php if ($periods): ?>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <form method="get" class="flex items-center gap-2">
            <label for="signoffPeriod" class="text-sm font-medium text-gray-500">Period</label>
            <select name="period" id="signoffPeriod" onchange="this.form.submit()"
                    class="form-select py-2 sm:w-56">
                <?php foreach ($periods as $p):
                    $key = $p['period_year'] . '-' . sprintf('%02d', $p['period_month']);
                    $lbl = DateTime::createFromFormat('!Y-n-j',
                        $p['period_year'] . '-' . $p['period_month'] . '-1')->format('F Y');
                ?>
                    <option value="<?php echo $key; ?>"
                        <?php echo ($year . '-' . sprintf('%02d', $month)) === $key ? 'selected' : ''; ?>>
                        <?php echo $lbl; ?> (<?php echo (int) $p['total']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($canManage && $summary['total'] > 0): ?>
        <form method="post">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="refresh">
            <input type="hidden" name="period" value="<?php echo $periodKey; ?>">
            <button type="submit" class="btn btn-secondary w-full sm:w-auto"
                    title="Recompute pending records' figures from the attendance record now (approved leave, office overrides), and flag signed records that no longer match">
                <i class="fas fa-sync-alt"></i>Re-check figures
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php displayFlashMessage(); ?>

<?php
// Pending records whose current reviewer can no longer log in: nobody will
// ever sign these until the roster is fixed, and reassigning moves them.
$heldByInactive = array_values(array_filter($rows, function ($r) {
    return ($r['status'] === 'pending_ta' && !empty($r['ta_coordinator_id']) && (int) $r['ta_active'] !== 1)
        || ($r['status'] === 'pending_guide' && !empty($r['faculty_guide_id']) && (int) $r['guide_active'] !== 1);
}));
?>
<?php if ($heldByInactive): ?>
    <div class="alert alert-warning mb-6">
        <i class="fas fa-user-slash mt-0.5"></i>
        <p class="flex-1">
            <span class="font-semibold"><?php echo count($heldByInactive); ?> record(s) are waiting on a deactivated
            faculty member</span> and cannot be signed. Reassign the student&rsquo;s Faculty Guide / TA Coordinator in
            <a href="<?php echo BASE_URL; ?>/admin/users.php" class="font-medium underline">Users</a>;
            their pending sign-offs move to the new person automatically.
        </p>
    </div>
<?php endif; ?>

<?php if (!$periods): ?>
    <div class="card">
        <div class="p-5 sm:p-6">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="far fa-calendar-check"></i></div>
                <h3 class="empty-state-title">No months opened yet</h3>
                <p class="empty-state-description">
                    Attendance is opened for sign-off automatically on the 1st of each month.
                    Until the first run there is nothing to show here.
                </p>
            </div>
        </div>
    </div>
<?php else: ?>

    <!-- Summary -->
    <?php $signedPct = $summary['total'] ? round(100 * $summary['approved'] / $summary['total']) : 0; ?>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-5">
        <div class="stat-tile col-span-2 gap-3 p-4 sm:p-5 md:col-span-1">
            <span class="icon-chip chip-brand"><i class="fas fa-users"></i></span>
            <div class="min-w-0 flex-1">
                <p class="stat-label">Students</p>
                <p class="stat-value"><?php echo (int) $summary['total']; ?></p>
                <div class="meter mt-2" title="<?php echo $signedPct; ?>% fully signed off">
                    <span class="bg-emerald-500" style="width:<?php echo $signedPct; ?>%"></span>
                </div>
                <p class="stat-meta"><?php echo $signedPct; ?>% signed off</p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-4 sm:p-5">
            <span class="icon-chip chip-amber hidden sm:inline-flex"><i class="fas fa-chalkboard-teacher"></i></span>
            <div class="min-w-0">
                <p class="stat-label">Awaiting TA</p>
                <p class="stat-value"><?php echo (int) $summary['pending_ta']; ?></p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-4 sm:p-5">
            <span class="icon-chip hidden bg-orange-50 text-orange-600 ring-orange-100 sm:inline-flex"><i class="fas fa-user-tie"></i></span>
            <div class="min-w-0">
                <p class="stat-label">Awaiting Guide</p>
                <p class="stat-value"><?php echo (int) $summary['pending_guide']; ?></p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-4 sm:p-5">
            <span class="icon-chip chip-emerald hidden sm:inline-flex"><i class="fas fa-check"></i></span>
            <div class="min-w-0">
                <p class="stat-label">Approved</p>
                <p class="stat-value"><?php echo (int) $summary['approved']; ?></p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-4 sm:p-5">
            <span class="icon-chip chip-rose hidden sm:inline-flex"><i class="fas fa-flag"></i></span>
            <div class="min-w-0">
                <p class="stat-label">Disputed</p>
                <p class="stat-value <?php echo (int) $summary['rejected'] > 0 ? 'text-rose-600' : ''; ?>"><?php echo (int) $summary['rejected']; ?></p>
            </div>
        </div>
    </div>

    <?php if (!empty($outstanding)): ?>
    <!-- Who to chase -->
    <section class="card mb-6">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-amber"><i class="fas fa-hourglass-half"></i></span>
                <div>
                    <h2 class="card-title">Still waiting on</h2>
                    <p class="card-subtitle">Faculty holding records for <?php echo $periodLabel; ?></p>
                </div>
            </div>
        </div>
        <ul class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-3">
            <?php foreach ($outstanding as $o):
                $isTaRole = $o['role'] === 'ta';
            ?>
                <li class="flex items-center gap-3 rounded-xl border border-gray-200 px-3.5 py-3">
                    <span class="avatar h-9 w-9 bg-none ring-1 ring-inset <?php echo $isTaRole ? 'bg-brand-50 text-brand-700 ring-brand-100' : 'bg-orange-50 text-orange-700 ring-orange-100'; ?>"><?php echo sanitize($initialsOf($o['name'])); ?></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900"><?php echo sanitize($o['name']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $o['role'] === 'ta' ? 'TA Coordinator' : 'Faculty Guide'; ?></p>
                        <?php if (!$o['active']): ?>
                            <span class="badge badge-rejected mt-1" title="Cannot log in: reassign the student's guide/TA in Users">
                                <i class="fas fa-user-slash text-[10px]"></i>deactivated &mdash; reassign
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="inline-flex min-w-[2rem] justify-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-800"
                          title="Records waiting on them">
                        <?php echo (int) $o['pending_count']; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="card-footer text-xs text-gray-500">
            Faculty are emailed when their stage opens. Sign-off is theirs to give &mdash;
            to correct the underlying attendance instead, use
            <a href="<?php echo BASE_URL; ?>/admin/attendance.php" class="font-medium text-brand-700 hover:underline">Attendance &rarr; override</a>.
        </div>
    </section>
    <?php endif; ?>

    <!-- Detail -->
    <section class="card overflow-hidden">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-slate"><i class="far fa-calendar-alt"></i></span>
                <div>
                    <h2 class="card-title"><?php echo $periodLabel; ?></h2>
                    <p class="card-subtitle">Every student&rsquo;s record for the month &mdash; disputed and pending first</p>
                </div>
            </div>
        </div>
        <?php if (empty($rows)): ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <p class="empty-state-title">Nothing recorded for <?php echo $periodLabel; ?></p>
                </div>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="signoffTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Roll No.</th>
                        <th class="!text-center">Present</th>
                        <th class="!text-center">Excused</th>
                        <th class="!text-center">Absent</th>
                        <th>Status</th>
                        <th>TA stage</th>
                        <th>Guide stage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="min-w-[16rem] <?php echo $r['status'] === 'rejected' ? 'shadow-[inset_3px_0_0_0_#fb7185]' : ''; ?>">
                            <div class="flex items-start gap-3">
                                <span class="avatar mt-0.5 h-8 w-8 bg-none ring-1 ring-inset <?php echo empty($r['student_active']) ? 'bg-gray-100 text-gray-500 ring-gray-200' : 'bg-brand-50 text-brand-700 ring-brand-100'; ?>"><?php echo sanitize($initialsOf($r['student_name'])); ?></span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5 font-medium text-gray-900">
                                        <?php echo sanitize($r['student_name']); ?>
                                        <?php if (empty($r['student_active'])): ?>
                                            <span class="badge badge-neutral">deactivated</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo (int) $r['working_days']; ?> working days
                                        <?php if (!empty($r['figures_updated_at'])): ?>
                                            &middot; <span class="whitespace-nowrap text-brand-700" title="Recomputed from the attendance record after the month was opened"><i class="fas fa-sync-alt mr-0.5 text-[10px]"></i>figures updated <?php echo formatDate($r['figures_updated_at']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php $lines = historyLines($r, $userNames); if ($lines): ?>
                                        <details class="mt-1.5 text-xs text-gray-600">
                                            <summary class="cursor-pointer font-medium text-gray-500 hover:text-gray-800">History (<?php echo count($lines); ?>)</summary>
                                            <ul class="mt-1.5 max-w-md list-disc space-y-1 pl-4">
                                                <?php foreach ($lines as $line): ?><li><?php echo $line; ?></li><?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-gray-500"><?php echo sanitize($r['roll_number']); ?></td>
                        <td class="text-center font-medium tabular-nums text-emerald-700"><?php echo (int) $r['present_days']; ?></td>
                        <td class="text-center tabular-nums text-amber-700"><?php echo (int) $r['excused_days']; ?></td>
                        <td class="text-center tabular-nums <?php echo (int) $r['absent_days'] > 0 ? 'font-bold text-rose-700' : 'text-gray-400'; ?>">
                            <?php echo (int) $r['absent_days']; ?>
                        </td>
                        <td>
                            <?php echo signoffBadge($r['status']); ?>
                            <?php
                            // A signed record the re-check found no longer matches.
                            $outdated = $r['status'] === 'approved' && !empty($r['outdated_figures'])
                                ? json_decode($r['outdated_figures'], true) : null;
                            if ($outdated): ?>
                                <div class="mt-1.5 text-xs text-amber-700" title="Checked <?php echo sanitize($outdated['checked_at'] ?? ''); ?>">
                                    <i class="fas fa-exclamation-triangle mr-0.5 text-[10px]"></i>record now says
                                    <?php echo (int) $outdated['present_days']; ?>/<?php echo (int) $outdated['excused_days']; ?>/<?php echo (int) $outdated['absent_days']; ?>
                                    <span class="text-gray-400">(P/E/A)</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($canManage && ($r['status'] === 'rejected' || $outdated)): ?>
                                <div class="mt-2">
                                    <button type="button"
                                            onclick="attaReopen(<?php echo (int) $r['id']; ?>, <?php echo htmlspecialchars(json_encode(html_entity_decode($r['student_name'], ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES); ?>, '<?php echo $r['status'] === 'rejected' ? 'disputed' : 'signed'; ?>')"
                                            class="btn btn-secondary btn-sm">
                                        <i class="fas fa-undo"></i>Reopen
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="min-w-[10rem]">
                            <?php if (empty($r['ta_coordinator_id']) && $r['ta_status'] === 'pending'): ?>
                                <span class="badge badge-rejected"><i class="fas fa-exclamation-circle text-[10px]"></i>no TA assigned</span>
                            <?php else: ?>
                                <div class="mb-1 text-sm font-medium text-gray-700"><?php echo sanitize($r['ta_name'] ?: '—'); ?></div>
                                <?php if ($r['ta_status'] === 'pending' && !empty($r['ta_coordinator_id']) && (int) $r['ta_active'] !== 1): ?>
                                    <span class="badge badge-rejected mb-1"><i class="fas fa-user-slash text-[10px]"></i>deactivated</span>
                                <?php endif; ?>
                                <?php echo stageCell($r['ta_status'], $r['ta_acted_by_name'], $r['ta_acted_at'], $r['ta_remarks']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="min-w-[10rem]">
                            <?php if (empty($r['faculty_guide_id']) && $r['guide_status'] !== 'skipped'): ?>
                                <span class="badge badge-rejected"><i class="fas fa-exclamation-circle text-[10px]"></i>no guide assigned</span>
                            <?php else: ?>
                                <div class="mb-1 text-sm font-medium text-gray-700"><?php echo sanitize($r['guide_name'] ?: '—'); ?></div>
                                <?php if ($r['guide_status'] === 'pending' && !empty($r['faculty_guide_id']) && (int) $r['guide_active'] !== 1): ?>
                                    <span class="badge badge-rejected mb-1"><i class="fas fa-user-slash text-[10px]"></i>deactivated</span>
                                <?php endif; ?>
                                <?php echo stageCell($r['guide_status'], $r['guide_acted_by_name'], $r['guide_acted_at'], $r['guide_remarks']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Reopen modal: clearing a faculty member's dispute needs a recorded reason -->
<div id="reopenModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel rounded-b-none sm:rounded-b-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-undo"></i></span>
                <h3 class="text-base font-semibold text-gray-900" id="reopenTitle">Reopen disputed record</h3>
            </div>
            <button type="button" onclick="attaCloseReopen()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="reopen">
            <input type="hidden" name="period" value="<?php echo $periodKey; ?>">
            <input type="hidden" name="id" id="reopenId">
            <div class="space-y-4 px-5 py-5 sm:px-6">
                <p class="text-sm text-gray-600">
                    <b id="reopenStudent" class="font-semibold text-gray-900"></b>'s figures will be recomputed from the attendance record as it stands now,
                    and the record goes back to the first reviewer (TA coordinator, or the faculty guide if there is no TA)
                    for a fresh sign-off. <span id="reopenKept">The dispute is kept in the record's history.</span>
                </p>
                <div>
                    <label for="reopenReason" class="form-label">
                        <span id="reopenAsk">How was the dispute resolved?</span> <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="reason" id="reopenReason" rows="3" required minlength="5"
                              class="form-textarea"
                              placeholder="e.g. conference days 12-14 marked excused via Attendance override"></textarea>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button type="button" onclick="attaCloseReopen()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-undo"></i>Reopen</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function attaReopen(id, studentName, mode) {
    const signed = mode === 'signed';
    document.getElementById('reopenId').value = id;
    document.getElementById('reopenStudent').textContent = studentName;
    document.getElementById('reopenTitle').textContent = signed ? 'Reopen signed record' : 'Reopen disputed record';
    document.getElementById('reopenKept').textContent = signed
        ? 'The signatures it clears are kept in the record\'s history.'
        : 'The dispute is kept in the record\'s history.';
    document.getElementById('reopenAsk').textContent = signed
        ? 'What changed since it was signed?' : 'How was the dispute resolved?';
    document.getElementById('reopenModal').classList.remove('hidden');
}
function attaCloseReopen() {
    const m = document.getElementById('reopenModal');
    if (m) m.classList.add('hidden');
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') attaCloseReopen(); });
if (document.getElementById('reopenModal')) {
    document.getElementById('reopenModal').addEventListener('click', function (e) {
        if (e.target === this) attaCloseReopen();
    });
}

$(document).ready(function () {
    if ($('#signoffTable').length) {
        $('#signoffTable').DataTable({
            pageLength: 25,
            order: [],                       // keep the server's "needs attention first" order
            columnDefs: [{ orderable: false, targets: [6, 7] }],
            language: { emptyTable: 'Nothing recorded for this period' }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
