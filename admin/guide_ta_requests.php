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
        return '<span class="text-gray-500">' . sanitize($current)
             . ' <span class="text-xs">(unchanged)</span></span>';
    }
    return '<span class="text-gray-500 line-through">' . sanitize($current) . '</span>'
         . ' <i class="fas fa-arrow-right text-xs text-gray-400 mx-1"></i>'
         . '<span class="font-semibold text-gray-900">' . sanitize($requested) . '</span>';
}
?>

<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Guide / TA Change Requests</h1>
        <p class="text-gray-600 mt-2">
            Students raise these when applying for leave. While a request is pending, that
            student&rsquo;s leave application is held and nobody can review it &mdash; deciding
            here releases it either way.
        </p>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Pending queue -->
    <div class="bg-white rounded-lg shadow-md mb-8">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">
                Awaiting your decision
                <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-medium <?php echo $pending ? 'bg-orange-100 text-orange-800' : 'bg-gray-100 text-gray-600'; ?>">
                    <?php echo count($pending); ?>
                </span>
            </h2>
        </div>

        <?php if (!$pending): ?>
            <p class="px-6 py-8 text-center text-gray-500">
                <i class="fas fa-check-circle text-green-500 mr-1"></i>
                Nothing waiting. No leave application is being held.
            </p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Faculty Guide</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">TA Coordinator</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Held</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 text-sm">
                    <?php foreach ($pending as $r): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($r['roll_number']); ?></div>
                                <div class="text-xs text-gray-400">asked <?php echo timeAgo($r['created_at']); ?></div>
                            </td>
                            <td class="px-4 py-3"><?php echo routingChangeCell($r['current_guide_name'], $r['requested_guide_name']); ?></td>
                            <td class="px-4 py-3"><?php echo routingChangeCell($r['current_ta_name'], $r['requested_ta_name']); ?></td>
                            <td class="px-4 py-3 text-gray-600 max-w-xs"><?php echo nl2br(sanitize($r['reason'])); ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php $held = (int) $r['held_leaves']; ?>
                                <span class="<?php echo $held > 0 ? 'font-semibold text-orange-700' : 'text-gray-400'; ?>">
                                    <?php echo $held; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button onclick="gtcAct(<?php echo (int) $r['id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($r['student_name']), ENT_QUOTES); ?>')"
                                        class="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700">
                                    <i class="fas fa-check mr-1"></i>Approve
                                </button>
                                <button onclick="gtcReject(<?php echo (int) $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['student_name']), ENT_QUOTES); ?>')"
                                        class="px-3 py-1.5 bg-red-600 text-white rounded text-xs hover:bg-red-700 ml-1">
                                    <i class="fas fa-times mr-1"></i>Reject
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Decided -->
    <div class="bg-white rounded-lg shadow-md">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Decided</h2>
        </div>
        <div class="p-4">
            <table id="gtcHistory" class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Faculty Guide</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">TA Coordinator</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Decision</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($history as $r): ?>
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></div>
                            <div class="text-xs text-gray-500"><?php echo sanitize($r['roll_number']); ?></div>
                        </td>
                        <td class="px-4 py-3"><?php echo routingChangeCell($r['current_guide_name'], $r['requested_guide_name']); ?></td>
                        <td class="px-4 py-3"><?php echo routingChangeCell($r['current_ta_name'], $r['requested_ta_name']); ?></td>
                        <td class="px-4 py-3">
                            <?php if ($r['status'] === 'approved'): ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Approved</span>
                            <?php else: ?>
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Rejected</span>
                            <?php endif; ?>
                            <div class="text-xs text-gray-400 mt-1">
                                <?php echo sanitize($r['decided_by_name'] ?? '—'); ?>,
                                <?php echo formatDate($r['decided_at']); ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            <?php echo $r['admin_remarks'] ? nl2br(sanitize($r['admin_remarks'])) : '<span class="text-gray-400">&mdash;</span>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div id="gtcRejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-5 py-3 border-b border-gray-200">
            <h3 class="font-semibold text-gray-900">Reject guide / TA change</h3>
        </div>
        <!-- noloading: submitted via fetch, so main.js must not add a spinner it never clears -->
        <form id="gtcRejectForm" class="p-5 noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="request_id" id="gtcRejectId">
            <input type="hidden" name="action" value="reject">
            <p class="text-sm text-gray-600 mb-3">
                <b id="gtcRejectStudent"></b> keeps their current Faculty Guide and TA Coordinator,
                and any leave application held for this request is sent to them for approval.
            </p>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Why? <span class="text-red-500">*</span>
            </label>
            <textarea name="remarks" id="gtcRejectRemarks" rows="3" required minlength="5"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500"
                      placeholder="e.g. Prof. X is still your guide of record; the supervisor change has not been filed with the office."></textarea>
            <p class="mt-1 text-xs text-gray-500">The student is emailed this.</p>
            <div class="flex items-center justify-end gap-2 mt-4">
                <button type="button" onclick="gtcCloseReject()"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">Cancel</button>
                <button type="submit" id="gtcRejectSubmit"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm">Reject request</button>
            </div>
        </form>
    </div>
</div>

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
        + 'Their records are updated and any held leave application is sent for approval.')) {
        return;
    }
    const body = new FormData();
    body.append('csrf_token', GTC_CSRF);
    body.append('request_id', id);
    body.append('action', action);
    const data = await gtcPost(body);
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
