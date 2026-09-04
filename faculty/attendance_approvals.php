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

<div class="mb-6">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Attendance Sign-off</h1>
    <p class="text-gray-600 mt-2 text-sm sm:text-base">
        Monthly attendance for your students. As <strong>TA Coordinator</strong> you review first;
        the <strong>Faculty Guide</strong> gives the final approval.
    </p>
</div>

<?php displayFlashMessage(); ?>

<?php if ($totalCount === 0): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
        <i class="fas fa-check-circle text-5xl sm:text-6xl text-green-500 mb-4"></i>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-2">Nothing waiting</h3>
        <p class="text-gray-600 text-sm sm:text-base">
            No attendance months are awaiting your sign-off.
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-2 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">
                <i class="fas fa-chalkboard-teacher text-blue-500 mr-1"></i>As TA Coordinator
            </p>
            <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $taCount; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-orange-500 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">
                <i class="fas fa-user-tie text-orange-500 mr-1"></i>As Faculty Guide
            </p>
            <p class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo $guideCount; ?></p>
        </div>
    </div>
<?php endif; ?>

<?php
// Both stages render identically apart from wording and colour.
$stages = [
    ['role' => 'ta', 'rows' => $pendingAsTA, 'label' => 'TA Coordinator',
     'icon' => 'fa-chalkboard-teacher', 'accent' => 'blue',
     'note' => 'You are the first reviewer. Approving sends each record to the student’s faculty guide.'],
    ['role' => 'guide', 'rows' => $pendingAsGuide, 'label' => 'Faculty Guide',
     'icon' => 'fa-user-tie', 'accent' => 'orange',
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
    <div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden border-l-4 border-l-<?php echo $stage['accent']; ?>-500">
        <div class="bg-<?php echo $stage['accent']; ?>-50 px-4 sm:px-6 py-3 border-b border-gray-100">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-<?php echo $stage['accent']; ?>-100 text-<?php echo $stage['accent']; ?>-800">
                        <i class="fas <?php echo $stage['icon']; ?> mr-1"></i><?php echo $stage['label']; ?>
                    </span>
                    <span class="ml-2 font-semibold text-gray-900"><?php echo $group['label']; ?></span>
                    <span class="text-sm text-gray-500">
                        &mdash; <?php echo count($group['rows']); ?> student(s)
                    </span>
                </div>
                <div class="flex gap-2">
                    <?php if ($blocked): ?>
                        <span class="px-3 sm:px-4 py-2 bg-gray-200 text-gray-500 rounded-lg text-sm font-medium cursor-not-allowed"
                              title="Clear your pending leave approvals for this month first">
                            <i class="fas fa-lock mr-1"></i>Approve selected
                        </span>
                    <?php else: ?>
                        <button type="button"
                                onclick="attaApproveGroup('<?php echo $formId; ?>', '<?php echo $stage['role']; ?>')"
                                class="px-3 sm:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                            <i class="fas fa-check mr-1"></i>Approve selected
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-xs text-gray-600 mt-2"><?php echo $stage['note']; ?></p>
            <?php if ($blocked): ?>
                <div class="mt-3 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                    <p class="text-xs text-yellow-800">
                        <i class="fas fa-lock mr-1"></i>
                        <b>Approval is locked for this month.</b>
                        You have <?php echo count($blockers); ?> leave application(s) awaiting your decision that
                        overlap <?php echo $group['label']; ?>. Approving one would move days from
                        <em>absent</em> to <em>excused</em>, so the attendance figures below could still change.
                    </p>
                    <ul class="mt-2 ml-4 text-xs text-yellow-800" style="list-style:disc">
                        <?php foreach ($blockers as $b): ?>
                            <li>
                                <?php echo sanitize($b['student_name']); ?>
                                (<?php echo sanitize($b['roll_number']); ?>) &mdash;
                                <?php echo sanitize(ucfirst($b['leave_type'])); ?>,
                                <?php echo formatDate($b['start_date']); ?> to <?php echo formatDate($b['end_date']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php"
                       class="inline-block mt-2 text-xs font-semibold text-yellow-900 underline">
                        Go to leave approvals &rarr;
                    </a>
                    <p class="text-xs text-yellow-700 mt-2">
                        You can still <b>dispute</b> a record if you know it is wrong.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <form id="<?php echo $formId; ?>">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 text-left">
                                <input type="checkbox" checked
                                       onchange="attaToggleAll('<?php echo $formId; ?>', this.checked)"
                                       title="Select all">
                            </th>
                            <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No.</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">Working</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Excused</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">%</th>
                            <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($group['rows'] as $r):
                            $absent = (int) $r['absent_days'];
                        ?>
                        <tr class="<?php echo $absent > 0 ? 'bg-red-50' : ''; ?>">
                            <td class="px-3 py-3">
                                <input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>" checked>
                            </td>
                            <td class="px-3 py-3 font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></td>
                            <td class="px-3 py-3 text-gray-500"><?php echo sanitize($r['roll_number']); ?></td>
                            <td class="px-3 py-3 text-center hidden sm:table-cell"><?php echo (int) $r['working_days']; ?></td>
                            <td class="px-3 py-3 text-center text-green-700 font-medium"><?php echo (int) $r['present_days']; ?></td>
                            <td class="px-3 py-3 text-center text-amber-700"><?php echo (int) $r['excused_days']; ?></td>
                            <td class="px-3 py-3 text-center <?php echo $absent > 0 ? 'text-red-700 font-bold' : 'text-gray-400'; ?>">
                                <?php echo $absent; ?>
                            </td>
                            <td class="px-3 py-3 text-center hidden sm:table-cell">
                                <?php echo $r['percentage'] === null
                                    ? '<span class="text-gray-400">n/a</span>'
                                    : number_format((float) $r['percentage'], 1) . '%'; ?>
                            </td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                <?php if ($blocked): ?>
                                    <span class="text-gray-400 text-xs mr-3" title="Clear your pending leave approvals for this month first">approve</span>
                                <?php else: ?>
                                    <button type="button"
                                            onclick="attaAct(<?php echo (int) $r['id']; ?>, '<?php echo $stage['role']; ?>', 'approve')"
                                            class="text-green-700 hover:underline text-xs font-medium mr-3">approve</button>
                                <?php endif; ?>
                                <button type="button"
                                        onclick="attaDispute(<?php echo (int) $r['id']; ?>, '<?php echo $stage['role']; ?>', '<?php echo sanitize($r['student_name']); ?>')"
                                        class="text-red-600 hover:underline text-xs font-medium">dispute</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <div class="px-4 sm:px-6 py-3 bg-gray-50 text-xs text-gray-500">
            <b>Present</b> = marked in person or recorded by the office &middot;
            <b>Excused</b> = approved leave or office exemption &middot;
            <b>Absent</b> = a working day with neither. Rows with an absence are highlighted.
        </div>
    </div>
<?php
    endforeach;
endforeach;
?>

<?php if (!empty($history)): ?>
<div class="pt-6 mb-4">
    <h2 class="text-xl font-bold text-gray-900 mb-1">Sign-off history</h2>
    <p class="text-gray-600 text-xs sm:text-sm">Months you have already actioned</p>
</div>
<div class="bg-white rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full text-sm" id="signoffHistory">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Outcome</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($history as $h):
                // Whichever stage this row represents for this user.
                $isTa = (int) $h['ta_acted_by'] === (int) $userId;
                $stageStatus = $isTa ? $h['ta_status'] : $h['guide_status'];
                $remarks = $isTa ? $h['ta_remarks'] : $h['guide_remarks'];
            ?>
            <tr>
                <td class="px-4 py-3 font-medium"><?php echo periodLabel($h); ?></td>
                <td class="px-4 py-3">
                    <?php echo sanitize($h['student_name']); ?>
                    <span class="text-xs text-gray-500">(<?php echo sanitize($h['roll_number']); ?>)</span>
                </td>
                <td class="px-4 py-3 text-center"><?php echo (int) $h['absent_days']; ?></td>
                <td class="px-4 py-3">
                    <?php if ($stageStatus === 'approved'): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">approved</span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">disputed</span>
                    <?php endif; ?>
                    <span class="ml-1"><?php echo getStatusBadge($h['status']); ?></span>
                </td>
                <td class="px-4 py-3 text-gray-600"><?php echo $remarks ? sanitize($remarks) : '<span class="text-gray-400">&mdash;</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Dispute modal: a rejection always needs a reason, so it cannot be a one-tap action -->
<div id="disputeModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end sm:items-center justify-center sm:p-4">
    <div class="bg-white rounded-t-lg sm:rounded-lg shadow-xl w-full sm:max-w-lg">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-200 bg-red-50">
            <h3 class="text-lg font-semibold text-red-800"><i class="fas fa-flag mr-2"></i>Dispute attendance</h3>
        </div>
        <!-- noloading: submitted via fetch, so main.js must not add a spinner it never clears -->
        <form id="disputeForm" class="p-4 sm:p-6 noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="id" id="disputeId">
            <input type="hidden" name="role" id="disputeRole">
            <input type="hidden" name="action" value="reject">
            <p class="text-sm text-gray-600 mb-3">
                Flagging <b id="disputeStudent"></b>'s attendance for this month. The office will follow it up;
                the record is not approved.
            </p>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                What is wrong? <span class="text-red-500">*</span>
            </label>
            <textarea name="remarks" id="disputeRemarks" rows="3" required minlength="5"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500"
                      placeholder="e.g. student was at a conference 12-14 July with prior approval"></textarea>
            <div class="flex items-center justify-end gap-2 mt-4">
                <button type="button" onclick="attaCloseDispute()"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">Cancel</button>
                <button type="submit" id="disputeSubmit"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">Submit dispute</button>
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
    if (!confirm('Approve attendance for ' + picked.length + ' student(s)?')) return;

    const body = new FormData();
    body.append('csrf_token', ATTA_CSRF);
    body.append('role', role);
    body.append('action', 'approve');
    picked.forEach((cb) => body.append('ids[]', cb.value));

    const data = await attaPost(body);
    alert(data.message || (data.success ? 'Done.' : 'Could not record that.'));
    if (data.success) location.reload();
}

async function attaAct(id, role, action) {
    if (action === 'approve' && !confirm('Approve this student\'s attendance for the month?')) return;
    const body = new FormData();
    body.append('csrf_token', ATTA_CSRF);
    body.append('role', role);
    body.append('action', action);
    body.append('id', id);
    const data = await attaPost(body);
    alert(data.message || (data.success ? 'Done.' : 'Could not record that.'));
    if (data.success) location.reload();
}

function attaDispute(id, role, studentName) {
    document.getElementById('disputeId').value = id;
    document.getElementById('disputeRole').value = role;
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
        if (data.success) { location.reload(); return; }
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
