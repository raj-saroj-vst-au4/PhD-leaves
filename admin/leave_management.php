<?php
/**
 * Admin - Leave Management
 */

$pageTitle = 'Leave Management';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$leaveClass = new Leave();

// Get filters
$filterStatus = sanitize($_GET['status'] ?? '');

$filters = [];
if ($filterStatus) $filters['status'] = $filterStatus;

// Get leaves
$leaves = $leaveClass->getAll($filters);
$pendingAdmin = $leaveClass->getPendingForAdmin();
// A 'combined' row is superseded by the leave it was merged into, which carries
// the same days, so it is not counted as an application of its own — unless
// combined rows are exactly what the admin filtered for.
$applicationCount = $filterStatus === 'combined'
    ? count($leaves)
    : count(array_filter($leaves, fn($l) => $l['status'] !== 'combined'));
$adjacentLeaves = $leaveClass->getLeavesWithAdjacentFlags();
?>

<?php
// Presentation only: avatar initials, and the status filter segments.
$personInitials = static function ($name) {
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
};
$statusFilters = [
    '' => 'All',
    'pending_admin' => 'Pending Admin',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
    'combined' => 'Combined',
    'pending_guide' => 'Pending Guide',
    'pending_ta' => 'Pending TA',
    'pending_hod' => 'Pending HOD',
];
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Leave Management</h1>
        <p class="page-subtitle">View and manage all leave applications</p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/admin/monthly_leaves.php" class="btn btn-secondary">
            <i class="fas fa-calendar-alt"></i>Monthly View
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php if (!empty($adjacentLeaves)): ?>
<!-- Adjacent Leaves Alert -->
<div class="alert alert-warning mb-6 flex-col sm:flex-row sm:items-center">
    <div class="flex flex-1 items-start gap-3">
        <i class="fas fa-exclamation-triangle mt-0.5"></i>
        <div>
            <h3 class="font-semibold">Adjacent Leaves Detected</h3>
            <p class="mt-0.5">
                <?php echo count($adjacentLeaves); ?> leave application(s) are adjacent to other leaves (separated only by weekends/holidays).
                These may need to be combined to prevent gaming the system.
            </p>
        </div>
    </div>
    <a href="<?php echo BASE_URL; ?>/admin/adjacent_leaves.php" class="btn btn-warning w-full sm:w-auto">
        <i class="fas fa-compress-arrows-alt"></i>Review &amp; Combine
    </a>
</div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="stat-tile">
        <span class="icon-chip chip-brand"><i class="fas fa-file-alt"></i></span>
        <div>
            <p class="stat-label">Total Applications</p>
            <p class="stat-value"><?php echo $applicationCount; ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-amber"><i class="fas fa-hourglass-half"></i></span>
        <div>
            <p class="stat-label">Pending Admin</p>
            <p class="stat-value"><?php echo count($pendingAdmin); ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-emerald"><i class="fas fa-check-circle"></i></span>
        <div>
            <p class="stat-label">Approved</p>
            <p class="stat-value"><?php echo count(array_filter($leaves, fn($l) => $l['status'] === 'approved')); ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-rose"><i class="fas fa-times-circle"></i></span>
        <div>
            <p class="stat-label">Rejected</p>
            <p class="stat-value"><?php echo count(array_filter($leaves, fn($l) => $l['status'] === 'rejected')); ?></p>
        </div>
    </div>
</div>

<!-- Leaves Table -->
<div class="card overflow-hidden">
    <!-- Status Filter -->
    <div class="border-b border-gray-100 px-4 py-3 sm:px-5">
        <div class="overflow-x-auto">
            <nav class="inline-flex whitespace-nowrap rounded-xl bg-gray-100 p-1" aria-label="Filter by status">
                <?php foreach ($statusFilters as $value => $label): ?>
                    <?php $active = $filterStatus === $value; ?>
                    <a href="<?php echo $value === '' ? '?' : '?status=' . $value; ?>"
                       class="inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium <?php echo $active ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'; ?>"
                       <?php echo $active ? 'aria-current="page"' : ''; ?>>
                        <?php echo $label; ?>
                        <?php if ($value === 'pending_admin' && count($pendingAdmin) > 0): ?>
                            <span class="ml-1.5 rounded-full bg-amber-100 px-1.5 text-xs font-semibold text-amber-800"><?php echo count($pendingAdmin); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>

    <?php if (empty($leaves)): ?>
        <div class="empty-state m-5 sm:m-6">
            <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
            <p class="empty-state-title">No leave applications found</p>
            <p class="empty-state-description">Nothing matches this filter.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern" id="leavesTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaves as $leave): ?>
                        <?php
                        $isPending = in_array($leave['status'], Leave::PENDING_STATUSES);
                        ?>
                        <tr>
                            <td class="whitespace-nowrap font-medium text-gray-500">#<?php echo $leave['id']; ?></td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <span class="avatar" aria-hidden="true"><?php echo sanitize($personInitials($leave['student_name'])); ?></span>
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="whitespace-nowrap text-gray-900">
                                <?php echo formatDate($leave['start_date']); ?> <span class="text-gray-400">&ndash;</span> <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="whitespace-nowrap font-semibold text-gray-900"><?php echo $leave['total_days']; ?></td>
                            <td class="whitespace-nowrap"><?php echo getStatusBadge($leave['status']); ?></td>
                            <td class="whitespace-nowrap text-gray-500"><?php echo timeAgo($leave['created_at']); ?></td>
                            <td class="whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <button onclick="viewLeaveDetails(<?php echo (int) $leave['id']; ?>)"
                                            class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i>View
                                    </button>
                                    <?php if ($isPending): ?>
                                        <?php /* The name travels in a data attribute, not inside the
                                           JS string: the HTML parser decodes &#039; before the
                                           handler runs, so an apostrophe in a student's name
                                           broke out of the string (stored XSS against admins). */ ?>
                                        <button data-leave-id="<?php echo (int) $leave['id']; ?>"
                                                data-student-name="<?php echo sanitize($leave['student_name']); ?>"
                                                onclick="showCancelModal(this.dataset.leaveId, this.dataset.studentName)"
                                                class="btn btn-soft-danger btn-sm">
                                            <i class="fas fa-ban"></i>Cancel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle">
    <div class="modal-panel max-w-md">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-rose"><i class="fas fa-ban"></i></span>
                <h3 id="cancelModalTitle" class="text-lg font-semibold text-gray-900">Cancel Leave Application</h3>
            </div>
            <button type="button" onclick="closeCancelModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch; keeps main.js from adding a btn-loading
             state that nothing removes when the API errors -->
        <form id="cancelForm" class="noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" id="cancelLeaveId" name="leave_id">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="role" value="admin">

            <div class="space-y-4 px-6 py-5">
                <div>
                    <p class="text-sm text-gray-500">
                        You are about to cancel the leave application for:
                    </p>
                    <p class="mt-1 font-semibold text-gray-900" id="cancelStudentName"></p>
                </div>

                <div>
                    <label for="cancelRemarks" class="form-label">
                        Reason for Cancellation <span class="text-rose-500">*</span>
                    </label>
                    <textarea id="cancelRemarks"
                              name="remarks"
                              rows="4"
                              required
                              minlength="5"
                              class="form-textarea"
                              placeholder="Enter reason for cancellation (minimum 5 characters)..."></textarea>
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mt-0.5"></i>
                    <p><strong class="font-semibold">Warning:</strong> This action will cancel the leave application and notify the student. This cannot be undone.</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button"
                        onclick="closeCancelModal()"
                        class="btn btn-secondary">
                    Close
                </button>
                <button type="submit"
                        id="cancelSubmitBtn"
                        class="btn btn-danger">
                    <i class="fas fa-ban"></i>Cancel Leave
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Leave Details Modal -->
<div id="detailsModal" class="hidden modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
    <div class="modal-content" style="max-width: 48rem;">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-file-alt"></i></span>
                <h3 id="detailsModalTitle" class="text-lg font-semibold text-gray-900">Leave Application Details</h3>
            </div>
            <button type="button" onclick="closeDetailsModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="detailsContent" class="max-h-[80vh] overflow-y-auto px-6 py-5">
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#leavesTable').DataTable({
        responsive: true,
        order: [[0, 'desc']],
        pageLength: 25,
        columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting on Actions column
        ]
    });
});

function viewLeaveDetails(leaveId) {
    document.getElementById('detailsModal').classList.remove('hidden');
    document.getElementById('detailsContent').innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-brand-600"></i></div>';

    fetch(`<?php echo BASE_URL; ?>/api/leaves/details.php?id=${leaveId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLeaveDetails(data.data);
            } else {
                document.getElementById('detailsContent').innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mt-0.5"></i><p>Error loading details</p></div>';
            }
        })
        .catch(error => {
            document.getElementById('detailsContent').innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mt-0.5"></i><p>Error loading details</p></div>';
        });
}

function displayLeaveDetails(leave) {
    let html = `
        <div class="space-y-6">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                <div class="col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Student</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.student_name || ''}${leave.roll_number ? ' <span class="text-sm text-gray-500 font-normal">(' + leave.roll_number + ')</span>' : ''}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Status</dt>
                    <dd class="mt-1">${leave.status_badge}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave Type</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.leave_type_label}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Days</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.total_days}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Applied On</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.created_at_formatted}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Start Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.start_date_formatted}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">End Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.end_date_formatted}</dd>
                </div>
            </dl>

            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Reason</p>
                <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-inset ring-gray-100">${leave.reason}</p>
            </div>

            ${leave.approval_chain ? `
            <div>
                <h4 class="mb-3 text-sm font-semibold text-gray-900">Approval Progress</h4>
                ${leave.approval_chain}
            </div>
            ` : ''}
        </div>
    `;
    document.getElementById('detailsContent').innerHTML = html;
}

function closeDetailsModal() {
    document.getElementById('detailsModal').classList.add('hidden');
}

// Close details modal on outside click
document.getElementById('detailsModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDetailsModal();
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDetailsModal();
        closeCancelModal();
    }
});

function showCancelModal(leaveId, studentName) {
    document.getElementById('cancelLeaveId').value = leaveId;
    document.getElementById('cancelStudentName').textContent = studentName;
    document.getElementById('cancelRemarks').value = '';
    document.getElementById('cancelModal').classList.remove('hidden');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
    document.getElementById('cancelForm').reset();
}

// Handle cancel form submission
document.getElementById('cancelForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = document.getElementById('cancelSubmitBtn');
    const originalText = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

    fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showFlashMessage('success', data.message);
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showFlashMessage('error', data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        showFlashMessage('error', 'An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
