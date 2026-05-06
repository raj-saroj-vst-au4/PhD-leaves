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

<div class="mb-4 sm:mb-6">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">All Leave Applications</h1>
    <p class="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">View and manage all department leave applications</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-4 sm:p-6 mb-4 sm:mb-6">
    <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">Status</label>
            <select name="status" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg text-sm sm:text-base">
                <option value="">All Status</option>
                <option value="pending_guide" <?php echo $filterStatus === 'pending_guide' ? 'selected' : ''; ?>>Pending Guide</option>
                <option value="pending_ta" <?php echo $filterStatus === 'pending_ta' ? 'selected' : ''; ?>>Pending TA</option>
                <option value="pending_hod" <?php echo $filterStatus === 'pending_hod' ? 'selected' : ''; ?>>Pending HOD</option>
                <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">Type</label>
            <select name="type" class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg text-sm sm:text-base">
                <option value="">All Types</option>
                <option value="personal" <?php echo $filterType === 'personal' ? 'selected' : ''; ?>>Personal</option>
                <option value="medical" <?php echo $filterType === 'medical' ? 'selected' : ''; ?>>Medical</option>
                <option value="academic" <?php echo $filterType === 'academic' ? 'selected' : ''; ?>>Academic</option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm sm:text-base">
                <i class="fas fa-filter mr-2"></i>Filter
            </button>
        </div>
        <div class="flex items-end">
            <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="w-full text-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm sm:text-base">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </div>
    </form>
</div>

<!-- Leave Applications -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($allLeaves)): ?>
        <div class="empty-state m-4 sm:m-6">
            <i class="fas fa-inbox empty-state-icon text-5xl sm:text-6xl"></i>
            <p class="empty-state-title">No leave applications found</p>
            <p class="empty-state-description">No applications match your current filters, or there are no leave applications in the system yet.</p>
        </div>
    <?php else: ?>
        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden">
            <div class="p-3 sm:p-4 bg-gray-50 border-b border-gray-200">
                <p class="text-sm text-gray-600 font-medium"><?php echo count($allLeaves); ?> application(s) found</p>
            </div>
            <div class="divide-y divide-gray-200">
                <?php foreach ($allLeaves as $leave): ?>
                    <div class="p-3 sm:p-4 hover:bg-gray-50">
                        <div class="flex items-start justify-between mb-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900 text-sm sm:text-base truncate"><?php echo sanitize($leave['student_name']); ?></p>
                                <p class="text-xs sm:text-sm text-gray-500"><?php echo sanitize($leave['roll_number']); ?></p>
                            </div>
                            <div class="ml-2 flex-shrink-0">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1 mt-2 text-xs sm:text-sm">
                            <div>
                                <span class="text-gray-500">Type:</span>
                                <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            </div>
                            <div>
                                <span class="text-gray-500">Days:</span>
                                <span class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></span>
                            </div>
                            <div class="col-span-2 mt-1">
                                <span class="text-gray-500">Dates:</span>
                                <span class="text-gray-900"><?php echo formatDate($leave['start_date']); ?> — <?php echo formatDate($leave['end_date']); ?></span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-3 pt-2 border-t border-gray-100">
                            <span class="text-xs text-gray-400"><?php echo timeAgo($leave['created_at']); ?></span>
                            <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm font-medium">
                                <i class="fas fa-eye mr-1"></i>View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="leavesTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Applied</th>
                        <th class="px-4 lg:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($allLeaves as $leave): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-gray-900 text-sm"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo formatDate($leave['start_date']); ?><br>to <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                <?php echo $leave['total_days']; ?>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-sm text-gray-500 hidden lg:table-cell">
                                <?php echo timeAgo($leave['created_at']); ?>
                            </td>
                            <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-sm">
                                <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" class="text-blue-600 hover:text-blue-800">
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
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg sm:text-xl font-semibold text-gray-900">Leave Application Details</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 p-1">
                <i class="fas fa-times text-lg sm:text-xl"></i>
            </button>
        </div>
        <div id="modalContent" class="px-4 sm:px-6 py-3 sm:py-4">
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
    document.getElementById('modalContent').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-4xl text-blue-600"></i></div>';

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
        <div class="space-y-4 sm:space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">Leave Type</p>
                    <p class="font-semibold text-sm sm:text-base">${leave.leave_type_label}</p>
                </div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">Status</p>
                    <p>${leave.status_badge}</p>
                </div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">Start Date</p>
                    <p class="font-semibold text-sm sm:text-base">${leave.start_date_formatted}</p>
                </div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">End Date</p>
                    <p class="font-semibold text-sm sm:text-base">${leave.end_date_formatted}</p>
                </div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">Total Days</p>
                    <p class="font-semibold text-sm sm:text-base">${leave.total_days}</p>
                </div>
                <div>
                    <p class="text-xs sm:text-sm text-gray-600">Applied On</p>
                    <p class="font-semibold text-sm sm:text-base">${leave.created_at_formatted}</p>
                </div>
            </div>

            <div>
                <p class="text-xs sm:text-sm text-gray-600 mb-1 sm:mb-2">Reason</p>
                <p class="p-2 sm:p-3 bg-gray-50 rounded-lg text-sm sm:text-base">${leave.reason}</p>
            </div>

            ${leave.approval_chain ? `
            <div>
                <h4 class="font-semibold text-gray-900 mb-2 sm:mb-3 text-sm sm:text-base">Approval Chain</h4>
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
