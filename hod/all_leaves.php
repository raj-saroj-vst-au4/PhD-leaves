<?php
/**
 * HOD - All Leave Applications
 */

$pageTitle = 'All Leave Applications';
require_once __DIR__ . '/../includes/header.php';
requireRole('hod');

require_once __DIR__ . '/../classes/Leave.php';

$leaveClass = new Leave();

// Get filters
$filterStatus = sanitize($_GET['status'] ?? '');
$filterType = sanitize($_GET['type'] ?? '');
$filterStudent = sanitize($_GET['student'] ?? '');

$filters = [];
if ($filterStatus) $filters['status'] = $filterStatus;
if ($filterType) $filters['leave_type'] = $filterType;

// Get all leaves
$allLeaves = $leaveClass->getAll($filters);
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Department</p>
        <h1 class="page-title">All Leave Applications</h1>
        <p class="page-subtitle">View and manage all department leave applications</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-6">
    <form method="GET" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 sm:p-5 lg:flex lg:items-end lg:gap-4">
        <div class="lg:w-64">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All Status</option>
                <option value="pending_routing" <?php echo $filterStatus === 'pending_routing' ? 'selected' : ''; ?>>Awaiting Guide/TA Change</option>
                <option value="pending_guide" <?php echo $filterStatus === 'pending_guide' ? 'selected' : ''; ?>>Pending Guide</option>
                <option value="pending_ta" <?php echo $filterStatus === 'pending_ta' ? 'selected' : ''; ?>>Pending TA</option>
                <option value="pending_hod" <?php echo $filterStatus === 'pending_hod' ? 'selected' : ''; ?>>Pending HOD</option>
                <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div class="lg:w-56">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
                <option value="">All Types</option>
                <option value="personal" <?php echo $filterType === 'personal' ? 'selected' : ''; ?>>Personal</option>
                <option value="medical" <?php echo $filterType === 'medical' ? 'selected' : ''; ?>>Medical</option>
                <option value="academic" <?php echo $filterType === 'academic' ? 'selected' : ''; ?>>Academic</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
            <button type="submit" class="btn btn-secondary flex-1 lg:flex-none">
                <i class="fas fa-filter"></i>Filter
            </button>
            <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="btn btn-ghost flex-1 lg:flex-none">
                <i class="fas fa-times"></i>Clear
            </a>
        </div>
    </form>
</div>

<!-- Leave Applications -->
<div class="card overflow-hidden">
    <?php if (empty($allLeaves)): ?>
        <div class="p-5 sm:p-6">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                <p class="empty-state-title">No leave applications found</p>
                <p class="empty-state-description">No applications match your current filters, or there are no leave applications in the system yet.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden">
            <div class="border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-medium text-gray-500"><?php echo count($allLeaves); ?> application(s) found</p>
            </div>
            <div class="divide-y divide-gray-100">
                <?php foreach ($allLeaves as $leave): ?>
                    <div class="px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></p>
                            </div>
                            <div class="flex-shrink-0">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-600">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            <span class="inline-flex items-center gap-1.5">
                                <i class="fas fa-calendar text-gray-400"></i>
                                <?php echo formatDate($leave['start_date']); ?> — <?php echo formatDate($leave['end_date']); ?>
                            </span>
                            <span><strong class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></strong> days</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3">
                            <span class="text-xs text-gray-400"><?php echo timeAgo($leave['created_at']); ?></span>
                            <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" class="btn btn-secondary btn-sm">
                                <i class="fas fa-eye"></i>View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full text-sm" id="leavesTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th class="hidden lg:table-cell">Applied</th>
                        <th class="!text-right" data-orderable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allLeaves as $leave): ?>
                        <tr>
                            <td class="whitespace-nowrap">
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td class="whitespace-nowrap">
                                <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            </td>
                            <td class="whitespace-nowrap text-gray-700">
                                <?php echo formatDate($leave['start_date']); ?><br><span class="text-xs text-gray-500">to <?php echo formatDate($leave['end_date']); ?></span>
                            </td>
                            <td class="whitespace-nowrap font-semibold tabular-nums text-gray-900">
                                <?php echo $leave['total_days']; ?>
                            </td>
                            <td class="whitespace-nowrap">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </td>
                            <td class="hidden whitespace-nowrap text-gray-500 lg:table-cell" data-order="<?php echo htmlspecialchars((string) $leave['created_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo timeAgo($leave['created_at']); ?>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Leave Details Modal -->
<div id="leaveModal" class="hidden modal-backdrop">
    <div class="modal-content" style="max-width: 48rem;">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-file-alt"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Leave Application Details</h3>
            </div>
            <button onclick="closeModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="modalContent" class="px-5 py-5 sm:px-6">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    if ($('#leavesTable').length && $('#leavesTable').is(':visible')) {
        $('#leavesTable').DataTable({
            responsive: true,
            order: [[5, 'desc']],
            pageLength: 25
        });
    }
});

function viewLeaveDetails(leaveId) {
    document.getElementById('leaveModal').classList.remove('hidden');
    document.getElementById('modalContent').innerHTML = '<div class="py-10 text-center"><i class="fas fa-spinner fa-spin text-3xl text-brand-600"></i></div>';

    fetch(`<?php echo BASE_URL; ?>/api/leaves/details.php?id=${leaveId}`, {
        credentials: 'same-origin'
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayLeaveDetails(data.data);
            } else {
                document.getElementById('modalContent').innerHTML = '<div class="text-center text-red-600 py-8"><i class="fas fa-exclamation-circle text-4xl mb-3"></i><p>Error: ' + (data.message || 'Unknown error') + '</p></div>';
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            document.getElementById('modalContent').innerHTML = '<div class="text-center text-red-600 py-8"><i class="fas fa-exclamation-circle text-4xl mb-3"></i><p>Error loading details: ' + error.message + '</p></div>';
        });
}

function displayLeaveDetails(leave) {
    let html = `
        <div class="space-y-5">
            <div class="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave Type</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">${leave.leave_type_label}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Status</p>
                    <p class="mt-1">${leave.status_badge}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Days</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">${leave.total_days}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Start Date</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">${leave.start_date_formatted}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">End Date</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">${leave.end_date_formatted}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Applied On</p>
                    <p class="mt-1 text-sm font-medium text-gray-900">${leave.created_at_formatted}</p>
                </div>
            </div>

            <div>
                <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">Reason</p>
                <p class="rounded-xl bg-gray-50 p-3 text-sm text-gray-700">${leave.reason}</p>
            </div>

            ${leave.approval_chain ? `
            <div>
                <h4 class="mb-3 text-sm font-semibold text-gray-900">Approval Chain</h4>
                ${leave.approval_chain}
            </div>
            ` : ''}
        </div>
    `;
    document.getElementById('modalContent').innerHTML = html;
}

function closeModal() {
    document.getElementById('leaveModal').classList.add('hidden');
}

// Close modal on outside click
document.getElementById('leaveModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
