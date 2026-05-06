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
$adjacentLeaves = $leaveClass->getLeavesWithAdjacentFlags();
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Leave Management</h1>
    <p class="text-gray-600 mt-2">View and manage all leave applications</p>
</div>

<?php displayFlashMessage(); ?>

<?php if (!empty($adjacentLeaves)): ?>
<!-- Adjacent Leaves Alert -->
<div class="mb-6 p-4 bg-orange-50 border-l-4 border-orange-500 rounded-lg">
    <div class="flex items-center justify-between">
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-orange-600 mt-1 mr-3"></i>
            <div>
                <h3 class="font-semibold text-orange-800">Adjacent Leaves Detected</h3>
                <p class="text-sm text-orange-700 mt-1">
                    <?php echo count($adjacentLeaves); ?> leave application(s) are adjacent to other leaves (separated only by weekends/holidays).
                    These may need to be combined to prevent gaming the system.
                </p>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/adjacent_leaves.php"
           class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 whitespace-nowrap">
            <i class="fas fa-compress-arrows-alt mr-2"></i>Review & Combine
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Total Applications</p>
        <p class="text-3xl font-bold text-gray-900"><?php echo count($leaves); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Pending Admin</p>
        <p class="text-3xl font-bold text-orange-600"><?php echo count($pendingAdmin); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Approved</p>
        <p class="text-3xl font-bold text-green-600"><?php echo count(array_filter($leaves, fn($l) => $l['status'] === 'approved')); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600">Rejected</p>
        <p class="text-3xl font-bold text-red-600"><?php echo count(array_filter($leaves, fn($l) => $l['status'] === 'rejected')); ?></p>
    </div>
</div>

<!-- Status Filter -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap gap-2">
        <a href="?" class="px-4 py-2 <?php echo $filterStatus === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">All</a>
        <a href="?status=pending_admin" class="px-4 py-2 <?php echo $filterStatus === 'pending_admin' ? 'bg-orange-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">
            Pending Admin <?php if (count($pendingAdmin) > 0): ?><span class="ml-1 px-2 py-0.5 bg-white bg-opacity-30 rounded-full"><?php echo count($pendingAdmin); ?></span><?php endif; ?>
        </a>
        <a href="?status=approved" class="px-4 py-2 <?php echo $filterStatus === 'approved' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Approved</a>
        <a href="?status=rejected" class="px-4 py-2 <?php echo $filterStatus === 'rejected' ? 'bg-red-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Rejected</a>
        <a href="?status=cancelled" class="px-4 py-2 <?php echo $filterStatus === 'cancelled' ? 'bg-gray-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Cancelled</a>
        <a href="?status=combined" class="px-4 py-2 <?php echo $filterStatus === 'combined' ? 'bg-purple-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Combined</a>
        <a href="?status=pending_guide" class="px-4 py-2 <?php echo $filterStatus === 'pending_guide' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Pending Guide</a>
        <a href="?status=pending_ta" class="px-4 py-2 <?php echo $filterStatus === 'pending_ta' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Pending TA</a>
        <a href="?status=pending_hod" class="px-4 py-2 <?php echo $filterStatus === 'pending_hod' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">Pending HOD</a>
    </div>
</div>

<!-- Leaves Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($leaves)): ?>
        <div class="p-12 text-center text-gray-500">
            <i class="fas fa-inbox text-6xl mb-4"></i>
            <p>No leave applications found</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="leavesTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Applied</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($leaves as $leave): ?>
                        <?php
                        $isPending = in_array($leave['status'], ['pending_guide', 'pending_ta', 'pending_hod', 'pending_admin']);
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">#<?php echo $leave['id']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo formatDate($leave['start_date']); ?> - <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900"><?php echo $leave['total_days']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo getStatusBadge($leave['status']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo timeAgo($leave['created_at']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                                <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)"
                                        class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors">
                                    <i class="fas fa-eye mr-1"></i>View
                                </button>
                                <?php if ($isPending): ?>
                                    <button onclick="showCancelModal(<?php echo $leave['id']; ?>, '<?php echo sanitize($leave['student_name']); ?>')"
                                            class="px-3 py-1 bg-red-100 text-red-700 rounded hover:bg-red-200 transition-colors">
                                        <i class="fas fa-ban mr-1"></i>Cancel
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <h3 class="text-xl font-semibold text-red-800">
                <i class="fas fa-ban mr-2"></i>Cancel Leave Application
            </h3>
        </div>
        <form id="cancelForm" class="p-6">
            <?php echo getCSRFField(); ?>
            <input type="hidden" id="cancelLeaveId" name="leave_id">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="role" value="admin">

            <div class="mb-4">
                <p class="text-sm text-gray-600 mb-2">
                    You are about to cancel the leave application for:
                </p>
                <p class="font-semibold text-gray-900" id="cancelStudentName"></p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason for Cancellation <span class="text-red-500">*</span>
                </label>
                <textarea id="cancelRemarks"
                          name="remarks"
                          rows="4"
                          required
                          minlength="5"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                          placeholder="Enter reason for cancellation (minimum 5 characters)..."></textarea>
            </div>

            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Warning:</strong> This action will cancel the leave application and notify the student. This cannot be undone.
                </p>
            </div>

            <div class="flex items-center justify-end space-x-3">
                <button type="button"
                        onclick="closeCancelModal()"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    Close
                </button>
                <button type="submit"
                        id="cancelSubmitBtn"
                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-ban mr-2"></i>Cancel Leave
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Leave Details Modal -->
<div id="detailsModal" class="hidden modal-backdrop">
    <div class="modal-content" style="max-width: 48rem;">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-xl font-semibold text-gray-900">Leave Application Details</h3>
            <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="detailsContent" class="px-6 py-4 max-h-[80vh] overflow-y-auto">
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
    document.getElementById('detailsContent').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-4xl text-blue-600"></i></div>';

    fetch(`<?php echo BASE_URL; ?>/api/leaves/details.php?id=${leaveId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLeaveDetails(data.data);
            } else {
                document.getElementById('detailsContent').innerHTML = '<div class="text-center text-red-600 py-8">Error loading details</div>';
            }
        })
        .catch(error => {
            document.getElementById('detailsContent').innerHTML = '<div class="text-center text-red-600 py-8">Error loading details</div>';
        });
}

function displayLeaveDetails(leave) {
    let html = `
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Student</p>
                    <p class="font-semibold">${leave.student_name || ''}${leave.roll_number ? ' <span class="text-sm text-gray-500 font-normal">(' + leave.roll_number + ')</span>' : ''}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Status</p>
                    <p>${leave.status_badge}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Leave Type</p>
                    <p class="font-semibold">${leave.leave_type_label}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Total Days</p>
                    <p class="font-semibold">${leave.total_days}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Start Date</p>
                    <p class="font-semibold">${leave.start_date_formatted}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">End Date</p>
                    <p class="font-semibold">${leave.end_date_formatted}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Applied On</p>
                    <p class="font-semibold">${leave.created_at_formatted}</p>
                </div>
            </div>

            <div>
                <p class="text-sm text-gray-600 mb-2">Reason</p>
                <p class="p-3 bg-gray-50 rounded-lg">${leave.reason}</p>
            </div>

            ${leave.approval_chain ? `
            <div>
                <h4 class="font-semibold text-gray-900 mb-3">Approval Progress</h4>
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
