<?php
/**
 * Faculty — monthly attendance sign-off.
 *
 * Unified view of both stages, because one faculty member is typically TA
 * coordinator for some students and guide for others; splitting them into
 * separate pages hides work behind a tab. Mirrors faculty/pending_approvals.php
 * for leaves so the two feel like the same job.
 */

$pageTitle = 'Attendance Sign-off';
require_once __DIR__ . '/../includes/header.php';
requireRole(['faculty', 'hod']);

require_once __DIR__ . '/../classes/AttendanceApproval.php';

$userId = getCurrentUserId();
$approval = new AttendanceApproval();

$pendingAsTA    = $approval->getPendingForFaculty($userId, 'ta');
$pendingAsGuide = $approval->getPendingForFaculty($userId, 'guide');

// Recompute the figures of everything waiting on this person before showing
// them. They used to be frozen when the month was opened, so a leave approved
// (or an office correction made) afterwards never reached the screen people
// sign from. Each row carries the version shown here, and the API refuses a
// decision on any other.
$refresh = $approval->refreshPendingRows(array_merge($pendingAsTA, $pendingAsGuide),
    $userId, 'faculty sign-off page');
if ($refresh['changed']) {
    $pendingAsTA    = $approval->getPendingForFaculty($userId, 'ta');
    $pendingAsGuide = $approval->getPendingForFaculty($userId, 'guide');
}
$refreshFailed = $refresh['error'] !== null;
// Each student's own leaves for the month still in flight at any stage. Only
// those waiting on THIS person block approval (blockingLeaves); the rest — at
// the HOD, with the office, at the other faculty member — are shown per row so
// nobody signs without knowing those days may yet turn from absent to excused.
$inFlight = $approval->pendingLeavesForRows(array_merge($pendingAsTA, $pendingAsGuide));
// Two role queries concatenated arrive in no useful order, so sort newest month
// first before rendering (DataTables is told not to re-order).
$history = array_merge(
    $approval->getHistoryForFaculty($userId, 'ta'),
    $approval->getHistoryForFaculty($userId, 'guide')
);
usort($history, function ($a, $b) {
    return [(int) $b['period_year'], (int) $b['period_month'], $a['roll_number']]
       <=> [(int) $a['period_year'], (int) $a['period_month'], $b['roll_number']];
});

$taCount    = count($pendingAsTA);
$guideCount = count($pendingAsGuide);
$totalCount = $taCount + $guideCount;

/** Group rows by period so each month can be approved in one go. */
function groupByPeriod(array $rows)
{
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['period_year'] . '-' . sprintf('%02d', $r['period_month']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'label' => DateTime::createFromFormat('!Y-n-j',
                    $r['period_year'] . '-' . $r['period_month'] . '-1')->format('F Y'),
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $r;
    }
    return $groups;
}

function periodLabel($row)
{
    return DateTime::createFromFormat('!Y-n-j',
        $row['period_year'] . '-' . $row['period_month'] . '-1')->format('M Y');
}
?>

<?php
// Presentation only: initials for the student avatars (names are stored
// HTML-escaped, so decode before taking letters; the result is sanitize()d).
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
        <h1 class="page-title">Attendance Sign-off</h1>
        <p class="page-subtitle">
            Monthly attendance for your students. As <strong class="font-semibold text-gray-700">TA Coordinator</strong> you review first;
            the <strong class="font-semibold text-gray-700">Faculty Guide</strong> gives the final approval.
        </p>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php if ($refreshFailed && $totalCount > 0): ?>
    <div class="alert alert-warning mb-6">
        <i class="fas fa-exclamation-triangle mt-0.5"></i>
        <p class="flex-1">
            The figures below could not be re-checked against the attendance record just now, so they are
            as last computed. A leave approved since then may not be reflected yet &mdash; if in doubt, try
            again later before approving.
        </p>
    </div>
<?php endif; ?>

<?php if ($totalCount === 0): ?>
    <div class="card mb-8">
        <div class="px-6 py-14 text-center">
            <div class="empty-state-icon h-14 w-14 bg-emerald-50 text-2xl text-emerald-600 ring-emerald-100">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Nothing waiting</h3>
            <p class="empty-state-description">No attendance months are awaiting your sign-off.</p>
        </div>
    </div>
<?php else: ?>
    <div class="mb-6 grid grid-cols-3 gap-3 sm:gap-4">
        <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
            <span class="icon-chip chip-slate hidden sm:inline-flex"><i class="fas fa-inbox"></i></span>
            <div class="min-w-0">
                <p class="stat-label text-xs sm:text-sm">Awaiting you</p>
                <p class="stat-value"><?php echo $totalCount; ?></p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
            <span class="icon-chip chip-brand hidden sm:inline-flex"><i class="fas fa-chalkboard-teacher"></i></span>
            <div class="min-w-0">
                <p class="stat-label text-xs sm:text-sm">As TA Coordinator</p>
                <p class="stat-value"><?php echo $taCount; ?></p>
            </div>
        </div>
        <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
            <span class="icon-chip hidden bg-orange-50 text-orange-600 ring-orange-100 sm:inline-flex"><i class="fas fa-user-tie"></i></span>
            <div class="min-w-0">
                <p class="stat-label text-xs sm:text-sm">As Faculty Guide</p>
                <p class="stat-value"><?php echo $guideCount; ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// Both stages render identically apart from wording and colour.
$stages = [
    ['role' => 'ta', 'rows' => $pendingAsTA, 'label' => 'TA Coordinator',
     'icon' => 'fa-chalkboard-teacher',
     'bar' => 'bg-blue-500', 'chip' => 'chip-brand', 'badge' => 'badge-brand',
     'note' => 'You are the first reviewer. Approving sends each record to the student’s faculty guide.'],
    ['role' => 'guide', 'rows' => $pendingAsGuide, 'label' => 'Faculty Guide',
     'icon' => 'fa-user-tie',
     'bar' => 'bg-orange-400', 'chip' => 'bg-orange-50 text-orange-600 ring-orange-100',
     'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
     'note' => 'The TA coordinator has already reviewed these. Yours is the final sign-off.'],
];

foreach ($stages as $stage):
    if (empty($stage['rows'])) continue;
    foreach (groupByPeriod($stage['rows']) as $key => $group):
        $formId = 'grp-' . $stage['role'] . '-' . $key;
        // A leave still awaiting this person for this month could turn absences
        // into excused days, so approval is barred until it is decided. Shown
        // here as well as enforced server-side, so the button explains itself.
        $first = $group['rows'][0];
        $blockers = $approval->blockingLeaves($userId, $stage['role'],
            (int) $first['period_year'], (int) $first['period_month']);
        $blocked = !empty($blockers);
?>
    <!-- No overflow-hidden here: it would stop the selection toolbar sticking. -->
    <section class="card mb-6">
        <div class="h-1 rounded-t-2xl <?php echo $stage['bar']; ?>" aria-hidden="true"></div>
        <div class="card-header">
            <div class="flex min-w-0 items-start gap-3">
                <span class="icon-chip <?php echo $stage['chip']; ?>"><i class="fas <?php echo $stage['icon']; ?>"></i></span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="card-title"><?php echo $group['label']; ?></h2>
                        <span class="badge <?php echo $stage['badge']; ?>">
                            <i class="fas <?php echo $stage['icon']; ?>"></i><?php echo $stage['label']; ?>
                        </span>
                        <span class="badge badge-neutral"><?php echo count($group['rows']); ?> student(s)</span>
                    </div>
                    <p class="card-subtitle"><?php echo $stage['note']; ?></p>
                </div>
            </div>
        </div>

        <?php if ($blocked): ?>
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6">
                <div class="alert alert-warning">
                    <i class="fas fa-lock mt-0.5"></i>
                    <div class="min-w-0 flex-1 space-y-2">
                        <p>
                            <span class="font-semibold">Approval is locked for this month.</span>
                            You have <?php echo count($blockers); ?> leave application(s) awaiting your decision that
                            overlap <?php echo $group['label']; ?>. Approving one would move days from
                            <em>absent</em> to <em>excused</em>, so the attendance figures below could still change.
                        </p>
                        <ul class="list-disc space-y-0.5 pl-5 text-xs sm:text-sm">
                            <?php foreach ($blockers as $b): ?>
                                <li>
                                    <span class="font-medium"><?php echo sanitize($b['student_name']); ?></span>
                                    (<?php echo sanitize($b['roll_number']); ?>) &mdash;
                                    <?php echo sanitize(ucfirst($b['leave_type'])); ?>,
                                    <?php echo formatDate($b['start_date']); ?> to <?php echo formatDate($b['end_date']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="flex flex-col gap-2 pt-1 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-amber-800">
                                You can still <b>dispute</b> a record if you know it is wrong.
                            </p>
                            <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php"
                               class="btn btn-secondary btn-sm self-start sm:self-auto">
                                Go to leave approvals <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form id="<?php echo $formId; ?>">
            <!-- Selection toolbar: stays under the navbar while this month's list scrolls -->
            <div class="sticky top-16 z-10 flex items-center justify-between gap-3 border-b border-gray-100 bg-white/95 px-5 py-2.5 backdrop-blur sm:px-6">
                <label class="inline-flex cursor-pointer items-center gap-2.5 text-sm font-medium text-gray-600">
                    <input type="checkbox" checked
                           onchange="attaToggleAll('<?php echo $formId; ?>', this.checked)"
                           title="Select all" class="h-4 w-4 rounded border-gray-300">
                    Select all
                </label>
                <?php if ($blocked): ?>
                    <span class="btn btn-sm cursor-not-allowed bg-gray-100 text-gray-400 ring-1 ring-inset ring-gray-200"
                          title="Clear your pending leave approvals for this month first">
                        <i class="fas fa-lock"></i>Approve selected
                    </span>
                <?php else: ?>
                    <button type="button"
                            onclick="attaApproveGroup('<?php echo $formId; ?>', '<?php echo $stage['role']; ?>')"
                            class="btn btn-success btn-sm">
                        <i class="fas fa-check"></i>Approve selected
                    </button>
                <?php endif; ?>
            </div>

            <ul class="divide-y divide-gray-100">
                <?php foreach ($group['rows'] as $r):
                    $absent = (int) $r['absent_days'];
                    $version = (int) $r['figures_version'];
                    // The guide should know when the numbers in front of
                    // them are not the ones the TA coordinator approved.
                    $taSawOther = $stage['role'] === 'guide' && $r['ta_status'] === 'approved'
                        && $r['ta_figures_version'] !== null
                        && (int) $r['ta_figures_version'] !== $version;
                    $taFigures = $taSawOther
                        ? AttendanceApproval::figuresAtVersion($r, $r['ta_figures_version']) : null;
                    // Pending leaves not already listed in the lock banner above.
                    $blockerIds = array_map('intval', array_column($blockers, 'id'));
                    $rowLeaves = array_values(array_filter($inFlight[(int) $r['id']] ?? [],
                        function ($l) use ($blockerIds) { return !in_array((int) $l['id'], $blockerIds, true); }));
                ?>
                <li class="px-5 py-4 sm:px-6 <?php echo $absent > 0 ? 'bg-rose-50/40' : ''; ?>">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-6">
                        <!-- Student -->
                        <div class="flex min-w-0 items-center gap-3 lg:w-72 lg:shrink-0">
                            <input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>"
                                   data-v="<?php echo $version; ?>" data-warn="<?php echo count($rowLeaves); ?>" checked
                                   class="h-4 w-4 shrink-0 rounded border-gray-300"
                                   aria-label="Select <?php echo sanitize($r['student_name']); ?>">
                            <span class="avatar h-9 w-9 bg-none bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($r['student_name'])); ?></span>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo sanitize($r['roll_number']); ?></p>
                                <?php if (!empty($r['figures_updated_at'])): ?>
                                    <p class="mt-0.5 text-xs text-brand-700"
                                       title="Recomputed from the attendance record after the month was opened (e.g. a leave approved since)">
                                        <i class="fas fa-sync-alt mr-1 text-[10px]"></i>figures updated <?php echo formatDate($r['figures_updated_at']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Figures -->
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 pl-7 lg:pl-0">
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-2.5 py-1 text-xs ring-1 ring-inset ring-gray-200">
                                <span class="text-gray-500">Working</span>
                                <span class="font-semibold tabular-nums text-gray-800"><?php echo (int) $r['working_days']; ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs ring-1 ring-inset ring-emerald-100">
                                <span class="text-emerald-700">Present</span>
                                <span class="font-semibold tabular-nums text-emerald-800"><?php echo (int) $r['present_days']; ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs ring-1 ring-inset ring-amber-100">
                                <span class="text-amber-700">Excused</span>
                                <span class="font-semibold tabular-nums text-amber-800"><?php echo (int) $r['excused_days']; ?></span>
                            </span>
                            <?php if ($absent > 0): ?>
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-2.5 py-1 text-xs ring-1 ring-inset ring-rose-200">
                                    <span class="text-rose-700">Absent</span>
                                    <span class="font-bold tabular-nums text-rose-700"><?php echo $absent; ?></span>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-white px-2.5 py-1 text-xs ring-1 ring-inset ring-gray-200">
                                    <span class="text-gray-400">Absent</span>
                                    <span class="font-semibold tabular-nums text-gray-400"><?php echo $absent; ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="inline-flex items-center gap-2 pl-1">
                                <?php if ($r['percentage'] === null): ?>
                                    <span class="text-xs text-gray-400">n/a</span>
                                <?php else: ?>
                                    <span class="meter w-16" aria-hidden="true">
                                        <span class="<?php echo $absent > 0 ? 'bg-amber-500' : 'bg-emerald-500'; ?>"
                                              style="width:<?php echo max(0, min(100, (float) $r['percentage'])); ?>%"></span>
                                    </span>
                                    <span class="text-xs font-semibold tabular-nums text-gray-700"><?php echo number_format((float) $r['percentage'], 1) . '%'; ?></span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="flex shrink-0 items-center gap-2 pl-7 lg:justify-end lg:pl-0">
                            <?php if ($blocked): ?>
                                <span class="btn btn-sm cursor-not-allowed bg-gray-100 text-gray-400 ring-1 ring-inset ring-gray-200"
                                      title="Clear your pending leave approvals for this month first"><i class="fas fa-lock"></i>Approve</span>
                            <?php else: ?>
                                <button type="button"
                                        onclick="attaAct(<?php echo (int) $r['id']; ?>, '<?php echo $stage['role']; ?>', 'approve', <?php echo $version; ?>, <?php echo count($rowLeaves); ?>)"
                                        class="btn btn-success btn-sm"><i class="fas fa-check"></i>Approve</button>
                            <?php endif; ?>
                            <?php
                            // A JSON string literal, not a sanitize()d name in quotes: the
                            // HTML parser decodes &#039; back to ' before the JS runs,
                            // so a name with an apostrophe broke out of a quoted
                            // string (stored XSS against faculty). Names are stored
                            // HTML-escaped, so decode first and let textContent show it.
                            $jsName = htmlspecialchars(json_encode(html_entity_decode($r['student_name'], ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
                            ?>
                            <button type="button"
                                    onclick="attaDispute(<?php echo (int) $r['id']; ?>, '<?php echo $stage['role']; ?>', <?php echo $jsName; ?>, <?php echo $version; ?>)"
                                    class="btn btn-soft-danger btn-sm"><i class="fas fa-flag"></i>Dispute</button>
                        </div>
                    </div>

                    <?php if ($rowLeaves): ?>
                        <div class="alert alert-warning mt-3 px-3 py-2 text-xs sm:ml-[4.75rem]">
                            <i class="fas fa-hourglass-half mt-0.5"></i>
                            <p class="flex-1">
                                <span class="font-semibold">Leave still pending for this month</span> &mdash;
                                <?php foreach ($rowLeaves as $i => $l): ?><?php echo $i ? '; ' : ''; ?>
                                    <?php echo formatDate($l['start_date']); ?> to <?php echo formatDate($l['end_date']); ?>,
                                    <?php echo sanitize(AttendanceApproval::leaveStageLabel($l['status'])); ?><?php
                                endforeach; ?>.
                                Those days count as <em>absent</em> in these figures until it is approved. You can
                                sign now; if it is approved later the office will reopen the record to correct it.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($taSawOther): ?>
                        <div class="alert alert-warning mt-3 px-3 py-2 text-xs sm:ml-[4.75rem]">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            <p class="flex-1">
                                <span class="font-semibold">Figures changed since the TA coordinator approved</span><?php
                                if ($taFigures): ?>
                                    &mdash; they saw <?php echo (int) $taFigures['present_days']; ?> present,
                                    <?php echo (int) $taFigures['excused_days']; ?> excused,
                                    <?php echo (int) $taFigures['absent_days']; ?> absent.<?php
                                endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </form>
        <div class="card-footer flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-gray-500">
            <span class="inline-flex items-start gap-1.5">
                <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
                <span><b class="font-semibold text-gray-700">Present</b> = marked in person or recorded by the office</span>
            </span>
            <span class="inline-flex items-start gap-1.5">
                <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-amber-500" aria-hidden="true"></span>
                <span><b class="font-semibold text-gray-700">Excused</b> = approved leave or office exemption</span>
            </span>
            <span class="inline-flex items-start gap-1.5">
                <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                <span><b class="font-semibold text-gray-700">Absent</b> = a working day with neither. Rows with an absence are highlighted.</span>
            </span>
        </div>
    </section>
<?php
    endforeach;
endforeach;
?>

<?php if (!empty($history)): ?>
<section class="card mt-8 overflow-hidden">
    <div class="card-header">
        <div class="flex items-center gap-3">
            <span class="icon-chip-sm chip-slate"><i class="fas fa-history"></i></span>
            <div>
                <h2 class="card-title">Sign-off history</h2>
                <p class="card-subtitle">Months you have already actioned</p>
            </div>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm" id="signoffHistory">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Student</th>
                    <th class="!text-center">Absent</th>
                    <th>Outcome</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h):
                    // Whichever stage this row represents for this user.
                    $isTa = (int) $h['ta_acted_by'] === (int) $userId;
                    $stageStatus = $isTa ? $h['ta_status'] : $h['guide_status'];
                    $remarks = $isTa ? $h['ta_remarks'] : $h['guide_remarks'];
                ?>
                <tr>
                    <td class="whitespace-nowrap font-medium text-gray-900"><?php echo periodLabel($h); ?></td>
                    <td>
                        <div class="font-medium text-gray-900"><?php echo sanitize($h['student_name']); ?></div>
                        <div class="text-xs text-gray-500"><?php echo sanitize($h['roll_number']); ?></div>
                    </td>
                    <td class="text-center tabular-nums <?php echo (int) $h['absent_days'] > 0 ? 'font-semibold text-rose-700' : 'text-gray-400'; ?>"><?php echo (int) $h['absent_days']; ?></td>
                    <td>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <?php if ($stageStatus === 'approved'): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"><i class="fas fa-check"></i>you approved</span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-rose-700"><i class="fas fa-flag"></i>you disputed</span>
                            <?php endif; ?>
                            <i class="fas fa-arrow-right text-[10px] text-gray-300" aria-hidden="true"></i>
                            <?php echo getStatusBadge($h['status']); ?>
                        </div>
                    </td>
                    <td class="max-w-md text-gray-600"><?php echo $remarks ? sanitize($remarks) : '<span class="text-gray-400">&mdash;</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Dispute modal: a rejection always needs a reason, so it cannot be a one-tap action -->
<div id="disputeModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel rounded-b-none sm:rounded-b-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-rose"><i class="fas fa-flag"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Dispute attendance</h3>
            </div>
            <button type="button" onclick="attaCloseDispute()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch, so main.js must not add a spinner it never clears -->
        <form id="disputeForm" class="noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="id" id="disputeId">
            <input type="hidden" name="role" id="disputeRole">
            <input type="hidden" name="figures_version" id="disputeVersion">
            <input type="hidden" name="action" value="reject">
            <div class="space-y-4 px-5 py-5 sm:px-6">
                <p class="text-sm text-gray-600">
                    Flagging <b id="disputeStudent" class="font-semibold text-gray-900"></b>'s attendance for this month. The office will follow it up;
                    the record is not approved.
                </p>
                <div>
                    <label for="disputeRemarks" class="form-label">
                        What is wrong? <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="remarks" id="disputeRemarks" rows="3" required minlength="5"
                              class="form-textarea"
                              placeholder="e.g. student was at a conference 12-14 July with prior approval"></textarea>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button type="button" onclick="attaCloseDispute()" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="disputeSubmit" class="btn btn-danger">Submit dispute</button>
            </div>
        </form>
    </div>
</div>

<script>
const ATTA_ENDPOINT = '<?php echo BASE_URL; ?>/api/attendance_approvals/process.php';
const ATTA_CSRF = '<?php echo generateCSRFToken(); ?>';

function attaToggleAll(formId, checked) {
    document.querySelectorAll('#' + formId + ' input[name="ids[]"]').forEach((cb) => { cb.checked = checked; });
}

async function attaPost(body) {
    const res = await fetch(ATTA_ENDPOINT, { method: 'POST', body });
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
    return data;
}

/** Approve every ticked row in one group. */
async function attaApproveGroup(formId, role) {
    const form = document.getElementById(formId);
    const picked = Array.from(form.querySelectorAll('input[name="ids[]"]:checked'));
    if (!picked.length) { alert('No students selected.'); return; }
    const warned = picked.filter((cb) => cb.dataset.warn !== '0').length;
    if (!confirm('Approve attendance for ' + picked.length + ' student(s)?'
        + (warned ? '\n\n' + warned + ' of them still have a leave pending for this month; '
            + 'those days are counted as absent in what you are signing.' : ''))) return;

    const body = new FormData();
    body.append('csrf_token', ATTA_CSRF);
    body.append('role', role);
    body.append('action', 'approve');
    // Each record goes with the figures version on screen; the server refuses
    // any whose figures have moved since.
    picked.forEach((cb) => {
        body.append('ids[]', cb.value);
        body.append('v[' + cb.value + ']', cb.dataset.v);
    });

    const data = await attaPost(body);
    alert(data.message || (data.success ? 'Done.' : 'Could not record that.'));
    if (data.success || (data.data && data.data.stale)) location.reload();
}

async function attaAct(id, role, action, version, warn) {
    if (action === 'approve' && !confirm('Approve this student\'s attendance for the month?'
        + (warn ? '\n\nA leave for this month is still pending; those days are counted as absent '
            + 'in what you are signing.' : ''))) return;
    const body = new FormData();
    body.append('csrf_token', ATTA_CSRF);
    body.append('role', role);
    body.append('action', action);
    body.append('id', id);
    body.append('figures_version', version);
    const data = await attaPost(body);
    alert(data.message || (data.success ? 'Done.' : 'Could not record that.'));
    if (data.success || (data.data && data.data.stale)) location.reload();
}

function attaDispute(id, role, studentName, version) {
    document.getElementById('disputeId').value = id;
    document.getElementById('disputeRole').value = role;
    document.getElementById('disputeVersion').value = version;
    document.getElementById('disputeStudent').textContent = studentName;
    document.getElementById('disputeRemarks').value = '';
    document.getElementById('disputeModal').classList.remove('hidden');
}
function attaCloseDispute() {
    document.getElementById('disputeModal').classList.add('hidden');
}
document.getElementById('disputeModal').addEventListener('click', function (e) {
    if (e.target === this) attaCloseDispute();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') attaCloseDispute(); });

document.getElementById('disputeForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('disputeSubmit');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting…';
    try {
        const data = await attaPost(new FormData(this));
        alert(data.message || 'Could not record that.');
        // A stale record needs the fresh figures on screen before anything else.
        if (data.success || (data.data && data.data.stale)) { location.reload(); return; }
    } catch (err) {
        alert('Something went wrong. Please try again.');
    }
    btn.disabled = false;
    btn.innerHTML = original;
});

$(document).ready(function () {
    if ($('#signoffHistory').length && $('#signoffHistory').is(':visible')) {
        $('#signoffHistory').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'No history yet' } });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
