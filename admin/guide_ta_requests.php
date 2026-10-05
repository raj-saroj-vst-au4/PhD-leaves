<?php
/**
 * Admin — guide / TA change requests.
 *
 * Students cannot edit their own Faculty Guide or TA Coordinator: those two
 * decide the entire approval chain, so a student who could set them could also
 * re-route, shorten or opt out of their own approvals. A correction is raised
 * with a leave application instead, and settled here.
 *
 * Anything pending is blocking a student's leave application, so the queue is
 * ordered oldest first and says how many applications each request is holding.
 */

$pageTitle = 'Guide / TA Change Requests';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/GuideTAChange.php';

$changes = new GuideTAChange();
$pending = $changes->listRequests('pending');
$history = $changes->listRequests('', 100);
// listRequests('') returns everything; the decided ones are what history means.
$history = array_values(array_filter($history, fn($r) => $r['status'] !== 'pending'));

/** "Prof. A → Prof. B", or the current value when it is not being changed. */
function routingChangeCell($currentName, $requestedName)
{
    $current = $currentName ?: 'Not assigned';
    $requested = $requestedName ?: 'None';
    if ($current === $requested) {
        return '<span class="text-gray-600">' . sanitize($current)
             . ' <span class="text-xs text-gray-400">(unchanged)</span></span>';
    }
    return '<span class="text-gray-400 line-through decoration-gray-300">' . sanitize($current) . '</span>'
         . ' <i class="fas fa-arrow-right mx-1.5 text-xs text-brand-500"></i>'
         . '<span class="font-semibold text-gray-900">' . sanitize($requested) . '</span>';
}

/**
 * Flag a field the office has changed since the student raised the request.
 * Approving applies only what the student asked to change, and asks for
 * confirmation when the roster has moved underneath the request.
 */
function rosterDriftNote($snapshotId, $liveId, $liveName)
{
    if ((int) $snapshotId === (int) $liveId) {
        return '';
    }
    return '<div class="mt-2 flex items-start gap-1.5 text-xs font-medium text-amber-700"><i class="fas fa-exclamation-triangle mt-0.5"></i>'
         . '<span>now ' . sanitize($liveName ?: 'Not assigned') . ' (changed since the request)</span></div>';
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
$tabBase = 'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition hover:text-gray-900';
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Guide / TA Change Requests</h1>
        <p class="page-subtitle max-w-3xl">
            Students raise these when applying for leave. While a request is pending, that
            student&rsquo;s leave application is held and nobody can review it &mdash; deciding
            here releases it either way.
        </p>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Status filter (client-side): one panel at a time -->
<div class="mb-6 inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" role="tablist" aria-label="Filter requests by status">
    <button type="button" role="tab" id="gtcTab-pending" data-gtc-tab="pending" aria-selected="true"
            onclick="gtcShowTab('pending')"
            class="<?php echo $tabBase; ?> bg-white text-gray-900 shadow-sm">
        <i class="fas fa-hourglass-half text-amber-500"></i>
        <span>Awaiting decision</span>
        <span class="rounded-md px-1.5 text-xs font-semibold tabular-nums <?php echo $pending ? 'bg-orange-100 text-orange-800' : 'bg-gray-200/80 text-gray-600'; ?>"><?php echo count($pending); ?></span>
    </button>
    <button type="button" role="tab" id="gtcTab-decided" data-gtc-tab="decided" aria-selected="false"
            onclick="gtcShowTab('decided')"
            class="<?php echo $tabBase; ?> text-gray-600">
        <i class="fas fa-history text-gray-400"></i>
        <span>Decided</span>
        <span class="rounded-md bg-gray-200/80 px-1.5 text-xs font-semibold tabular-nums text-gray-600"><?php echo count($history); ?></span>
    </button>
</div>

<!-- Pending queue -->
<div data-gtc-panel="pending" role="tabpanel" aria-labelledby="gtcTab-pending">
    <?php if (!$pending): ?>
        <div class="card">
            <div class="px-6 py-14 text-center">
                <div class="empty-state-icon h-14 w-14 bg-emerald-50 text-2xl text-emerald-600 ring-emerald-100">
                    <i class="fas fa-check"></i>
                </div>
                <h3 class="text-base font-semibold text-gray-900">Nothing waiting</h3>
                <p class="empty-state-description">No leave application is being held.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="space-y-4 sm:space-y-5">
        <?php foreach ($pending as $r):
            $held = (int) $r['held_leaves'];
            $guideDrift = rosterDriftNote($r['current_guide_id'], $r['live_guide_id'], $r['live_guide_name']);
            $taDrift = rosterDriftNote($r['current_ta_id'], $r['live_ta_id'], $r['live_ta_name']);
            // A JSON string literal: addslashes() inside a quoted JS
            // string does not cover newlines or other terminators, and
            // the HTML parser decodes entities before the JS runs.
            $jsName = htmlspecialchars(json_encode(html_entity_decode($r['student_name'], ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES);
        ?>
            <article class="card">
                <div class="card-body">
                    <!-- Who, and what it is holding -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="avatar h-10 w-10 bg-none bg-brand-50 text-sm text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($r['student_name'])); ?></span>
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-semibold text-gray-900"><?php echo sanitize($r['student_name']); ?></h3>
                                <p class="flex flex-wrap items-center gap-x-2 text-sm text-gray-500">
                                    <span class="font-medium text-gray-700"><?php echo sanitize($r['roll_number']); ?></span>
                                    <span class="text-gray-300" aria-hidden="true">&middot;</span>
                                    <span><i class="far fa-clock mr-1 text-gray-400"></i>asked <?php echo timeAgo($r['created_at']); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 pl-[3.25rem] sm:pl-0">
                            <?php if ($held > 0): ?>
                                <span class="badge badge-routing"><i class="fas fa-pause-circle text-[10px]"></i><?php echo $held; ?> leave application(s) held</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">No leave held</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Current -> requested -->
                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 px-4 py-3">
                            <p class="mb-1.5 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">
                                <i class="fas fa-user-tie text-orange-500"></i>Faculty Guide
                            </p>
                            <div class="text-sm"><?php echo routingChangeCell($r['current_guide_name'], $r['requested_guide_name']); ?></div>
                            <?php echo $guideDrift; ?>
                        </div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3">
                            <p class="mb-1.5 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">
                                <i class="fas fa-chalkboard-teacher text-brand-500"></i>TA Coordinator
                            </p>
                            <div class="text-sm"><?php echo routingChangeCell($r['current_ta_name'], $r['requested_ta_name']); ?></div>
                            <?php echo $taDrift; ?>
                        </div>
                    </div>

                    <!-- The student's reason -->
                    <blockquote class="mt-4 rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                        <p class="mb-1 flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">
                            <i class="fas fa-quote-left text-gray-400"></i>Student&rsquo;s reason
                        </p>
                        <p class="text-sm leading-relaxed text-gray-700"><?php echo nl2br(sanitize($r['reason'])); ?></p>
                    </blockquote>

                    <?php if ($guideDrift !== '' || $taDrift !== ''): ?>
                        <div class="alert alert-warning mt-4">
                            <i class="fas fa-exclamation-triangle mt-0.5"></i>
                            <p class="flex-1">
                                <span class="font-semibold">The roster has changed since this request was raised.</span>
                                Approving applies only what the student asked to change; you will be shown what moved
                                and asked to confirm first.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer flex items-center justify-end gap-2">
                    <button type="button" onclick="gtcReject(<?php echo (int) $r['id']; ?>, <?php echo $jsName; ?>)"
                            class="btn btn-soft-danger btn-sm">
                        <i class="fas fa-times"></i>Reject
                    </button>
                    <button type="button" onclick="gtcAct(<?php echo (int) $r['id']; ?>, 'approve', <?php echo $jsName; ?>)"
                            class="btn btn-success btn-sm">
                        <i class="fas fa-check"></i>Approve
                    </button>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Decided -->
<div data-gtc-panel="decided" role="tabpanel" aria-labelledby="gtcTab-decided" class="hidden">
    <section class="card overflow-hidden">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-slate"><i class="fas fa-history"></i></span>
                <div>
                    <h2 class="card-title">Decided</h2>
                    <p class="card-subtitle">Most recent decisions, newest first</p>
                </div>
            </div>
        </div>
        <?php if (!$history): ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <p class="empty-state-title">Nothing decided yet</p>
                </div>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table id="gtcHistory" class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Faculty Guide</th>
                        <th>TA Coordinator</th>
                        <th>Decision</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $r): ?>
                    <tr>
                        <td>
                            <div class="font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo sanitize($r['roll_number']); ?></div>
                        </td>
                        <td class="min-w-[12rem]"><?php echo routingChangeCell($r['current_guide_name'], $r['requested_guide_name']); ?></td>
                        <td class="min-w-[12rem]"><?php echo routingChangeCell($r['current_ta_name'], $r['requested_ta_name']); ?></td>
                        <td class="whitespace-nowrap">
                            <?php if ($r['status'] === 'approved'): ?>
                                <span class="badge badge-approved"><i class="fas fa-check text-[10px]"></i>Approved</span>
                            <?php else: ?>
                                <span class="badge badge-rejected"><i class="fas fa-times text-[10px]"></i>Rejected</span>
                            <?php endif; ?>
                            <div class="mt-1 text-xs text-gray-400">
                                <?php echo sanitize($r['decided_by_name'] ?? '—'); ?>,
                                <?php echo formatDate($r['decided_at']); ?>
                            </div>
                        </td>
                        <td class="max-w-md text-gray-600">
                            <?php echo $r['admin_remarks'] ? nl2br(sanitize($r['admin_remarks'])) : '<span class="text-gray-400">&mdash;</span>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Reject modal -->
<div id="gtcRejectModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel rounded-b-none sm:rounded-b-2xl">
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-rose"><i class="fas fa-times"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Reject guide / TA change</h3>
            </div>
            <button type="button" onclick="gtcCloseReject()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch, so main.js must not add a spinner it never clears -->
        <form id="gtcRejectForm" class="noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="request_id" id="gtcRejectId">
            <input type="hidden" name="action" value="reject">
            <div class="space-y-4 px-5 py-5 sm:px-6">
                <p class="text-sm text-gray-600">
                    <b id="gtcRejectStudent" class="font-semibold text-gray-900"></b> keeps their current Faculty Guide and TA Coordinator,
                    and any leave application held for this request is sent to them for approval.
                </p>
                <div>
                    <label for="gtcRejectRemarks" class="form-label">
                        Why? <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="remarks" id="gtcRejectRemarks" rows="3" required minlength="5"
                              class="form-textarea"
                              placeholder="e.g. Prof. X is still your guide of record; the supervisor change has not been filed with the office."></textarea>
                    <p class="form-help">The student is emailed this.</p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button type="button" onclick="gtcCloseReject()" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="gtcRejectSubmit" class="btn btn-danger">Reject request</button>
            </div>
        </form>
    </div>
</div>

<script>
// Presentation only: the status filter shows one panel at a time.
function gtcShowTab(name) {
    document.querySelectorAll('[data-gtc-tab]').forEach((b) => {
        const on = b.dataset.gtcTab === name;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        ['bg-white', 'text-gray-900', 'shadow-sm'].forEach((c) => b.classList.toggle(c, on));
        b.classList.toggle('text-gray-600', !on);
    });
    document.querySelectorAll('[data-gtc-panel]').forEach((p) => {
        p.classList.toggle('hidden', p.dataset.gtcPanel !== name);
    });
    // The history table was laid out while hidden; size its columns now.
    if (name === 'decided' && window.jQuery && $.fn.dataTable && $.fn.dataTable.isDataTable('#gtcHistory')) {
        $('#gtcHistory').DataTable().columns.adjust();
    }
}
</script>

<script>
const GTC_ENDPOINT = '<?php echo BASE_URL; ?>/api/routing_requests/process.php';
const GTC_CSRF = '<?php echo generateCSRFToken(); ?>';

async function gtcPost(body) {
    const res = await fetch(GTC_ENDPOINT, { method: 'POST', body });
    try { return await res.json(); } catch (e) { return {}; }
}

async function gtcAct(id, action, studentName) {
    if (action === 'approve' && !confirm(
        'Approve the guide/TA change for ' + studentName + '?\n\n'
        + 'What they asked to change is updated, any held leave application is sent for approval, '
        + 'and pending attendance sign-offs move to the new approver.')) {
        return;
    }
    const body = new FormData();
    body.append('csrf_token', GTC_CSRF);
    body.append('request_id', id);
    body.append('action', action);
    let data = await gtcPost(body);
    // The roster changed after the student asked: show the admin what moved
    // and what approving will now do, and only proceed on an explicit yes —
    // which names the roster they were shown, so the server can ask again if
    // it moves before this lands.
    while (!data.success && data.data && data.data.needs_confirmation) {
        if (!confirm(data.message)) return;
        body.set('confirm_drift', '1');
        body.set('confirm_guide_id', data.data.seen_guide_id ?? '');
        body.set('confirm_ta_id', data.data.seen_ta_id ?? '');
        data = await gtcPost(body);
    }
    alert(data.message || (data.success ? 'Done.' : 'Could not record that.'));
    if (data.success) location.reload();
}

function gtcReject(id, studentName) {
    document.getElementById('gtcRejectId').value = id;
    document.getElementById('gtcRejectStudent').textContent = studentName;
    document.getElementById('gtcRejectRemarks').value = '';
    document.getElementById('gtcRejectModal').classList.remove('hidden');
}
function gtcCloseReject() {
    document.getElementById('gtcRejectModal').classList.add('hidden');
}
document.getElementById('gtcRejectModal').addEventListener('click', function (e) {
    if (e.target === this) gtcCloseReject();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') gtcCloseReject(); });

document.getElementById('gtcRejectForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('gtcRejectSubmit');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting…';
    try {
        const data = await gtcPost(new FormData(this));
        alert(data.message || 'Could not record that.');
        if (data.success) { location.reload(); return; }
    } catch (err) {
        alert('Something went wrong. Please try again.');
    }
    btn.disabled = false;
    btn.innerHTML = original;
});

$(document).ready(function () {
    if ($('#gtcHistory tbody tr').length) {
        $('#gtcHistory').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Nothing decided yet' } });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
