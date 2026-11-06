<?php
/**
 * Faculty Pending Approvals
 * Unified view: shows pending items from BOTH roles (Faculty Guide + TA Coordinator)
 * in a single prioritized feed so nothing gets missed behind a tab.
 */

$pageTitle = 'Pending Approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole('faculty');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$userId = getCurrentUserId();
$leaveClass = new Leave();
$approvalClass = new Approval();

// Pending applications for both roles
$pendingAsGuide = $leaveClass->getPendingForFaculty($userId, 'guide');
$pendingAsTA    = $leaveClass->getPendingForFaculty($userId, 'ta_coordinator');

foreach ($pendingAsGuide as &$_l) { $_l['_role'] = 'guide'; } unset($_l);
foreach ($pendingAsTA    as &$_l) { $_l['_role'] = 'ta';    } unset($_l);

// Merge & sort newest-first
$pendingAll = array_merge($pendingAsGuide, $pendingAsTA);
usort($pendingAll, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// History
$historyAsGuide = $leaveClass->getHistoryForFaculty($userId, 'guide');
$historyAsTA    = $leaveClass->getHistoryForFaculty($userId, 'ta_coordinator');

foreach ($historyAsGuide as &$_l) { $_l['_role'] = 'guide'; } unset($_l);
foreach ($historyAsTA    as &$_l) { $_l['_role'] = 'ta';    } unset($_l);

$historyAll = array_merge($historyAsGuide, $historyAsTA);
usort($historyAll, function ($a, $b) {
    $aTime = $a['_role'] === 'guide' ? ($a['guide_approved_at'] ?? '') : ($a['ta_approved_at'] ?? '');
    $bTime = $b['_role'] === 'guide' ? ($b['guide_approved_at'] ?? '') : ($b['ta_approved_at'] ?? '');
    return strtotime($bTime) - strtotime($aTime);
});

$guideCount = count($pendingAsGuide);
$taCount    = count($pendingAsTA);
$totalCount = $guideCount + $taCount;
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <p class="page-eyebrow">Faculty</p>
        <h1 class="page-title">Pending Approvals</h1>
        <p class="page-subtitle">
            Leave applications waiting on your decision &mdash; across <strong class="font-semibold text-gray-700">both</strong> of your roles.
        </p>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Summary Strip -->
<?php if ($totalCount > 0): ?>
<div class="mb-6 grid grid-cols-3 gap-3 sm:gap-4">
    <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-slate hidden sm:inline-flex"><i class="fas fa-inbox"></i></span>
        <div class="min-w-0">
            <p class="stat-label text-xs sm:text-sm">Total pending</p>
            <p class="stat-value"><?php echo $totalCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
        <span class="icon-chip hidden bg-orange-50 text-orange-600 ring-orange-100 sm:inline-flex"><i class="fas fa-user-tie"></i></span>
        <div class="min-w-0">
            <p class="stat-label text-xs sm:text-sm">As Faculty Guide</p>
            <p class="stat-value"><?php echo $guideCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-3 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-brand hidden sm:inline-flex"><i class="fas fa-chalkboard-teacher"></i></span>
        <div class="min-w-0">
            <p class="stat-label text-xs sm:text-sm">As TA Coordinator</p>
            <p class="stat-value"><?php echo $taCount; ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filter Pills (client-side, defaults to All) -->
<?php if ($totalCount > 0): ?>
<div class="mb-4 flex items-center gap-3 sm:mb-6">
    <span class="hidden text-xs font-medium uppercase tracking-wide text-gray-500 sm:inline">Filter</span>
    <div class="inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" role="group" aria-label="Filter by role">
        <button type="button"
                data-filter="all"
                class="filter-pill active inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition hover:text-gray-900 bg-white text-gray-900 shadow-sm">
            <span>All</span>
            <span class="rounded-md bg-gray-200/80 px-1.5 text-xs font-semibold tabular-nums text-gray-700"><?php echo $totalCount; ?></span>
        </button>
        <button type="button"
                data-filter="guide"
                class="filter-pill inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition hover:text-gray-900 text-gray-600">
            <i class="fas fa-user-tie text-orange-500"></i>
            <span class="sm:hidden">Guide</span><span class="hidden sm:inline">Faculty Guide</span>
            <span class="rounded-md bg-orange-100 px-1.5 text-xs font-semibold tabular-nums text-orange-800"><?php echo $guideCount; ?></span>
        </button>
        <button type="button"
                data-filter="ta"
                class="filter-pill inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition hover:text-gray-900 text-gray-600">
            <i class="fas fa-chalkboard-teacher text-blue-500"></i>
            <span class="sm:hidden">TA Coord.</span><span class="hidden sm:inline">TA Coordinator</span>
            <span class="rounded-md bg-blue-100 px-1.5 text-xs font-semibold tabular-nums text-blue-800"><?php echo $taCount; ?></span>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Applications List -->
<?php if (empty($pendingAll)): ?>
    <div class="card">
        <div class="px-6 py-14 text-center">
            <div class="empty-state-icon h-14 w-14 bg-emerald-50 text-2xl text-emerald-600 ring-emerald-100">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-900">All caught up!</h3>
            <p class="empty-state-description">No pending applications in either of your roles.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 gap-4 sm:gap-5" id="pendingList">
        <?php foreach ($pendingAll as $leave): ?>
            <?php
                $isGuide     = $leave['_role'] === 'guide';
                $accentBar   = $isGuide ? 'bg-orange-400' : 'bg-blue-500';
                $chipClass   = $isGuide ? 'bg-orange-50 text-orange-600 ring-orange-100' : 'chip-brand';
                $roleBadge   = $isGuide
                    ? '<span class="badge bg-orange-50 text-orange-700 ring-orange-200"><i class="fas fa-user-tie"></i>Faculty Guide</span>'
                    : '<span class="badge badge-brand"><i class="fas fa-chalkboard-teacher"></i>TA Coordinator</span>';
                $roleLong    = $isGuide ? 'Faculty Guide' : 'TA Coordinator';
            ?>
            <div class="pending-card card overflow-hidden"
                 data-role="<?php echo $leave['_role']; ?>">
                <!-- Role accent: guide and TA items are told apart at a glance -->
                <div class="h-1 <?php echo $accentBar; ?>" aria-hidden="true"></div>

                <div class="card-body">
                    <!-- Top row: student, leave type, status -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="icon-chip <?php echo $chipClass; ?>"><i class="fas fa-user-graduate"></i></span>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-900 sm:text-lg">
                                    <?php echo sanitize($leave['student_name']); ?>
                                </h3>
                                <p class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 text-sm text-gray-500">
                                    <span class="font-medium text-gray-700"><?php echo sanitize($leave['roll_number']); ?></span>
                                    <span class="hidden text-gray-300 sm:inline" aria-hidden="true">&middot;</span>
                                    <span class="min-w-0 max-w-full truncate"><?php echo sanitize($leave['student_email']); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-shrink-0 flex-wrap items-center gap-2 pl-14 sm:pl-0">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            <?php echo getStatusBadge($leave['status']); ?>
                        </div>
                    </div>

                    <!-- Meta: dates, days, applied -->
                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-calendar text-gray-400"></i>
                            <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fas fa-hourglass-half text-gray-400"></i>
                            <span><strong class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></strong> days</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-paper-plane text-gray-400"></i>
                            Applied <?php echo formatDate($leave['created_at']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-gray-500">
                            <i class="far fa-clock text-gray-400"></i><?php echo timeAgo($leave['created_at']); ?>
                        </span>
                    </div>

                    <!-- Reason -->
                    <div class="mt-4 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                        <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Reason</p>
                        <p class="whitespace-pre-line text-sm leading-relaxed text-gray-700"><?php echo sanitize($leave['reason']); ?></p>
                    </div>

                    <!-- Medical Certificate -->
                    <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                        <div class="mt-3 flex flex-col gap-3 rounded-xl border border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="icon-chip-sm chip-rose"><i class="fas fa-file-medical"></i></span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Medical certificate attached</p>
                                    <p class="text-xs text-gray-500">Uploaded by the student</p>
                                </div>
                            </div>
                            <?php // Served only through the access-checked endpoint, never by path. ?>
                            <button type="button"
                                    data-pdf-url="<?php echo htmlspecialchars(BASE_URL . '/api/files/download.php?leave_id=' . (int) $leave['id'] . '&doc=medical', ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="openPdfViewer(this.dataset.pdfUrl)"
                                    class="btn btn-secondary btn-sm w-full sm:w-auto">
                                <i class="fas fa-eye"></i>View document
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="card-footer flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="flex items-center gap-2 text-sm text-gray-500">
                        <?php echo $roleBadge; ?>
                        <span class="hidden sm:inline">Action needed from you as <strong class="font-semibold text-gray-700"><?php echo $roleLong; ?></strong></span>
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button"
                                onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'reject', '<?php echo $leave['_role']; ?>')"
                                class="btn btn-soft-danger flex-1 sm:flex-none">
                            <i class="fas fa-times"></i>Reject
                        </button>
                        <button type="button"
                                onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'approve', '<?php echo $leave['_role']; ?>')"
                                class="btn btn-success flex-1 sm:flex-none">
                            <i class="fas fa-check"></i>Approve as <?php echo $isGuide ? 'Guide' : 'TA Coord.'; ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Empty filter state (shown when current filter matches nothing) -->
    <div id="emptyFilterState" class="empty-state hidden">
        <div class="empty-state-icon"><i class="fas fa-filter"></i></div>
        <p class="empty-state-title">Nothing in this filter</p>
        <p class="empty-state-description">Try <strong class="font-semibold text-gray-700">All</strong> to see every pending item.</p>
    </div>
<?php endif; ?>

<!-- Approval History -->
<div class="mb-4 mt-10 sm:mt-14">
    <h2 class="text-lg font-semibold text-gray-900 sm:text-xl">Approval History</h2>
    <p class="mt-0.5 text-sm text-gray-500">Your past decisions across both roles</p>
</div>

<?php if (empty($historyAll)): ?>
    <div class="empty-state">
        <div class="empty-state-icon"><i class="fas fa-history"></i></div>
        <p class="empty-state-title">No history yet</p>
        <p class="empty-state-description">You haven't approved or rejected any applications yet.</p>
    </div>
<?php else: ?>
    <div class="card overflow-hidden">
        <?php
        // Pre-compute display data for each history row
        $historyData = [];
        foreach ($historyAll as $leave) {
            $isGuide = $leave['_role'] === 'guide';
            if ($isGuide) {
                $decision = $leave['guide_status'];
                $remarks  = $leave['guide_remarks'];
                $actedOn  = $leave['guide_approved_at'];
            } else {
                $decision = $leave['ta_status'];
                $remarks  = $leave['ta_remarks'];
                $actedOn  = $leave['ta_approved_at'];
            }
            $decisionBadge = $decision === 'approved'
                ? '<span class="badge badge-approved"><i class="fas fa-check"></i>Approved</span>'
                : '<span class="badge badge-rejected"><i class="fas fa-times"></i>Rejected</span>';
            $roleChip = $isGuide
                ? '<span class="badge bg-orange-50 text-orange-700 ring-orange-200"><i class="fas fa-user-tie"></i>Guide</span>'
                : '<span class="badge badge-brand"><i class="fas fa-chalkboard-teacher"></i>TA Coord.</span>';
            $historyData[] = [
                'leave'         => $leave,
                'decision'      => $decision,
                'remarks'       => $remarks,
                'actedOn'       => $actedOn,
                'decisionBadge' => $decisionBadge,
                'roleChip'      => $roleChip,
                'role'          => $leave['_role'],
            ];
        }
        ?>

        <!-- Mobile Card View (< md) -->
        <div class="block divide-y divide-gray-100 md:hidden">
            <?php foreach ($historyData as $item):
                $leave = $item['leave'];
            ?>
                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-gray-900">
                                <?php echo sanitize($leave['student_name']); ?>
                            </p>
                            <p class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></p>
                        </div>
                        <div class="flex flex-shrink-0 flex-col items-end gap-1">
                            <?php echo $item['decisionBadge']; ?>
                            <?php echo $item['roleChip']; ?>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                        <span class="text-xs text-gray-500">Overall</span>
                        <?php echo getStatusBadge($leave['status']); ?>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600">
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-calendar text-gray-400"></i>
                            <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fas fa-hourglass-half text-gray-400"></i>
                            <span><strong class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></strong> days</span>
                        </span>
                    </div>
                    <?php if (!empty($item['remarks'])): ?>
                        <p class="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
                            <span class="text-gray-500">Remarks:</span>
                            <?php echo sanitize($item['remarks']); ?>
                        </p>
                    <?php endif; ?>
                    <p class="mt-3 text-xs text-gray-400">
                        Acted on <?php echo $item['actedOn'] ? formatDate($item['actedOn']) : '-'; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden overflow-x-auto md:block">
            <table id="historyTable" class="table-modern w-full">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Role</th>
                        <th>Type</th>
                        <th class="hidden lg:table-cell">Duration</th>
                        <th>Days</th>
                        <th>Decision</th>
                        <th>Status</th>
                        <th class="hidden xl:table-cell">Remarks</th>
                        <th class="hidden lg:table-cell">Acted On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historyData as $item):
                        $leave = $item['leave'];
                    ?>
                        <tr>
                            <td>
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td><?php echo $item['roleChip']; ?></td>
                            <td><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="hidden whitespace-nowrap lg:table-cell">
                                <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="tabular-nums"><?php echo $leave['total_days']; ?></td>
                            <td><?php echo $item['decisionBadge']; ?></td>
                            <td><?php echo getStatusBadge($leave['status']); ?></td>
                            <td class="hidden max-w-xs text-gray-600 xl:table-cell">
                                <?php if (!empty($item['remarks'])): ?>
                                    <span class="block max-w-[200px] truncate" title="<?php echo sanitize($item['remarks']); ?>">
                                        <?php echo sanitize($item['remarks']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="italic text-gray-400">Not Required</span>
                                <?php endif; ?>
                            </td>
                            <td class="hidden whitespace-nowrap text-xs text-gray-500 lg:table-cell">
                                <?php echo $item['actedOn'] ? formatDate($item['actedOn']) : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel max-h-[90vh] overflow-y-auto rounded-b-none sm:rounded-b-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-4 rounded-t-2xl border-b border-gray-100 bg-white px-5 py-4 sm:px-6">
            <h3 class="text-base font-semibold text-gray-900 sm:text-lg" id="modalTitle">Approve Leave</h3>
            <button type="button" onclick="closeApprovalModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: this form is submitted via fetch; the global handler in main.js
     would otherwise add a btn-loading state nothing removes when the API errors -->
        <form id="approvalForm" class="noloading">
            <input type="hidden" id="leaveId" name="leave_id">
            <input type="hidden" id="actionType" name="action">
            <input type="hidden" id="roleType" name="role">
            <?php echo getCSRFField(); ?>

            <div class="space-y-4 px-5 py-5 sm:px-6">
                <!-- Acting-as banner inside modal so the role is clear at decision time -->
                <div id="actingAsBanner" class="mb-4 px-3 py-2 rounded-lg text-sm flex items-center"></div>

                <div>
                    <label for="remarks" class="form-label">
                        <span id="remarksLabel">Remarks</span>
                        <span id="remarksRequired" class="text-rose-500">*</span>
                        <span id="remarksOptional" class="font-normal text-gray-400">(optional)</span>
                    </label>
                    <textarea
                        id="remarks"
                        name="remarks"
                        rows="3"
                        class="form-textarea"
                        placeholder="Please provide your remarks..."
                    ></textarea>
                </div>

                <div id="taDutySection" class="hidden">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-gray-50/70 p-3 transition hover:border-gray-300">
                        <input type="checkbox" id="noDutyConfirm" name="no_duty_confirmed" value="1"
                               class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-gray-300">
                        <span class="text-sm text-gray-700">
                            I confirm that the student has no TA duties during this period
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button type="button" onclick="closeApprovalModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" id="submitBtn" class="btn btn-primary">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// ---------- Filter pills ----------
(function () {
    const pills    = document.querySelectorAll('.filter-pill');
    const cards    = document.querySelectorAll('.pending-card');
    const emptyMsg = document.getElementById('emptyFilterState');

    function setActive(btn) {
        pills.forEach(p => {
            p.classList.remove('active', 'bg-white', 'text-gray-900', 'shadow-sm');
            p.classList.add('text-gray-600');
        });
        btn.classList.add('active', 'bg-white', 'text-gray-900', 'shadow-sm');
        btn.classList.remove('text-gray-600');
    }

    function applyFilter(filter) {
        let visible = 0;
        cards.forEach(card => {
            const show = (filter === 'all') || (card.dataset.role === filter);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (emptyMsg) {
            emptyMsg.classList.toggle('hidden', visible !== 0);
        }
    }

    pills.forEach(btn => {
        btn.addEventListener('click', () => {
            setActive(btn);
            applyFilter(btn.dataset.filter);
        });
    });
})();

// ---------- Approval Modal ----------
function showApprovalModal(leaveId, action, role) {
    document.getElementById('leaveId').value   = leaveId;
    document.getElementById('actionType').value = action;
    document.getElementById('roleType').value   = role;
    document.getElementById('remarks').value    = '';
    document.getElementById('noDutyConfirm').checked = false;

    const modal     = document.getElementById('approvalModal');
    const title     = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const taSect    = document.getElementById('taDutySection');
    const banner    = document.getElementById('actingAsBanner');

    const roleLong = role === 'guide' ? 'Faculty Guide' : 'TA Coordinator';
    const roleIcon = role === 'guide' ? 'fa-user-tie' : 'fa-chalkboard-teacher';
    const bannerCls = role === 'guide'
        ? 'bg-orange-50 text-orange-800 ring-orange-200'
        : 'bg-blue-50 text-blue-800 ring-blue-200';

    banner.className = 'flex items-center rounded-xl px-3.5 py-2.5 text-sm ring-1 ring-inset ' + bannerCls;
    banner.innerHTML = '<i class="fas ' + roleIcon + ' mr-2"></i>You are acting as <strong class="mx-1">' + roleLong + '</strong>';

    // Remarks are needed only to reject: they are the reason the student reads.
    const remarks  = document.getElementById('remarks');
    const rejecting = action !== 'approve';
    remarks.required = rejecting;
    remarks.minLength = rejecting ? 5 : 0;
    remarks.placeholder = rejecting
        ? 'Why is this leave being rejected? The student will see this (at least 5 characters).'
        : 'Any note for the student or the next approver (optional)';
    document.getElementById('remarksLabel').textContent = rejecting ? 'Reason for rejection' : 'Remarks';
    document.getElementById('remarksRequired').classList.toggle('hidden', !rejecting);
    document.getElementById('remarksOptional').classList.toggle('hidden', rejecting);

    if (action === 'approve') {
        title.textContent = 'Approve Leave (' + roleLong + ')';
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check"></i>Approve';
        taSect.classList.toggle('hidden', role !== 'ta');
    } else {
        title.textContent = 'Reject Leave (' + roleLong + ')';
        submitBtn.className = 'btn btn-danger';
        submitBtn.innerHTML = '<i class="fas fa-times"></i>Reject';
        taSect.classList.add('hidden');
    }

    modal.classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}

document.getElementById('approvalForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    const action   = formData.get('action');
    const role     = formData.get('role');

    if (action !== 'approve' && String(formData.get('remarks') || '').trim().length < 5) {
        alert('Please give a reason for rejecting (at least 5 characters).');
        return;
    }

    if (action === 'approve' && role === 'ta') {
        if (!document.getElementById('noDutyConfirm').checked) {
            alert('Please confirm that the student has no TA duties during this period');
            return;
        }
    }

    const submitBtn = document.getElementById('submitBtn');
    const original  = submitBtn.innerHTML;
    submitBtn.disabled  = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Processing...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
            method: 'POST',
            body:   formData
        });
        const result = await response.json();

        if (result.success) {
            // Shown here, then reload. The message used to ride along as
            // ?success=<text> and be echoed into the reloaded page's script, so a
            // crafted link could put any text in front of a faculty member.
            alert(result.message);
            window.location.href = '<?php echo BASE_URL; ?>/faculty/pending_approvals.php';
        } else {
            alert('Error: ' + result.message);
            submitBtn.disabled  = false;
            submitBtn.innerHTML = original;
        }
    } catch (err) {
        alert('An error occurred. Please try again.');
        submitBtn.disabled  = false;
        submitBtn.innerHTML = original;
    }
});

// Close modal on outside click
document.getElementById('approvalModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeApprovalModal();
});

// ---------- PDF Viewer ----------
function openPdfViewer(pdfUrl) {
    document.getElementById('pdfFrame').src = pdfUrl;
    document.getElementById('pdfModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closePdfViewer() {
    document.getElementById('pdfModal').classList.add('hidden');
    document.getElementById('pdfFrame').src = '';
    document.body.style.overflow = 'auto';
}
document.getElementById('pdfModal')?.addEventListener('click', function (e) {
    if (e.target === this) closePdfViewer();
});

// ---------- History DataTable ----------
$(document).ready(function () {
    if ($('#historyTable').length && $('#historyTable').is(':visible')) {
        $('#historyTable').DataTable({
            order: [[8, 'desc']],
            pageLength: 10,
            language: {
                emptyTable: "No history found",
                search: "Search:"
            }
        });
    }
});
</script>

<!-- PDF Viewer Modal (Full Screen) -->
<div id="pdfModal" class="hidden fixed inset-0 bg-gray-900 z-50 flex flex-col">
    <div class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-gray-900 px-4 py-3 sm:px-6">
        <h3 class="flex items-center gap-3 text-sm font-semibold text-white sm:text-base">
            <span class="icon-chip-sm bg-white/10 text-rose-300 ring-white/10"><i class="fas fa-file-medical"></i></span>
            Medical Certificate
        </h3>
        <button type="button" onclick="closePdfViewer()" class="btn-icon h-9 w-9 text-gray-300 hover:bg-white/10 hover:text-white" title="Close" aria-label="Close">
            <i class="fas fa-times text-lg"></i>
        </button>
    </div>
    <div class="flex-1 min-h-0 bg-gray-800">
        <iframe id="pdfFrame" src="" class="w-full h-full border-0"></iframe>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>