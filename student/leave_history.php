<?php
/**
 * Student Leave History
 * View all leave applications with status and approval chain
 */

$pageTitle = 'Leave History';
require_once __DIR__ . '/../includes/header.php';
requireRole('student');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$studentId = getCurrentStudentId();
$leaveClass = new Leave();
$approvalClass = new Approval();

// Get filter parameters
$filterStatus = sanitize($_GET['status'] ?? '');
$filterType = sanitize($_GET['type'] ?? '');

// Build filters
$filters = [];
if ($filterStatus) $filters['status'] = $filterStatus;
if ($filterType) $filters['leave_type'] = $filterType;

// Get leave applications
$leaves = $leaveClass->getByStudent($studentId, $filters);

// Get leave balance adjustments (shown when no status filter is active)
$adjustments = [];
if (empty($filterStatus)) {
    $adjustments = $leaveClass->getAdjustments($studentId);
}

// Build unified timeline merging leaves and adjustments
$timeline = [];

foreach ($leaves as $leave) {
    $timeline[] = [
        'type' => 'leave',
        'data' => $leave,
        'sort_date' => $leave['created_at']
    ];
}

foreach ($adjustments as $adj) {
    if (empty($filterType) || $filterType === $adj['leave_type']) {
        $timeline[] = [
            'type' => 'adjustment',
            'data' => $adj,
            'sort_date' => $adj['created_at']
        ];
    }
}

// Sort by date descending
usort($timeline, function($a, $b) {
    return strtotime($b['sort_date']) - strtotime($a['sort_date']);
});
?>

<div class="mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Leave History</h1>
            <p class="text-gray-600 mt-2">View all your leave applications</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-plus mr-2"></i>Apply Leave
            </a>
        </div>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
            <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <option value="">All Status</option>
                <option value="pending_routing" <?php echo $filterStatus === 'pending_routing' ? 'selected' : ''; ?>>Awaiting Guide/TA Change</option>
                <option value="pending_guide" <?php echo $filterStatus === 'pending_guide' ? 'selected' : ''; ?>>Pending Guide</option>
                <option value="pending_ta" <?php echo $filterStatus === 'pending_ta' ? 'selected' : ''; ?>>Pending TA</option>
                <option value="pending_hod" <?php echo $filterStatus === 'pending_hod' ? 'selected' : ''; ?>>Pending HOD</option>
                <option value="pending_admin" <?php echo $filterStatus === 'pending_admin' ? 'selected' : ''; ?>>Pending Admin</option>
                <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
            <select name="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <option value="">All Types</option>
                <option value="personal" <?php echo $filterType === 'personal' ? 'selected' : ''; ?>>Personal</option>
                <option value="medical" <?php echo $filterType === 'medical' ? 'selected' : ''; ?>>Medical</option>
                <option value="academic" <?php echo $filterType === 'academic' ? 'selected' : ''; ?>>Academic</option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-filter mr-2"></i>Filter
            </button>
        </div>
        <div class="flex items-end">
            <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="w-full text-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                <i class="fas fa-times mr-2"></i>Clear
            </a>
        </div>
    </form>
</div>

<!-- Leave Applications Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($timeline)): ?>
        <div class="empty-state m-6">
            <i class="fas fa-inbox empty-state-icon text-6xl"></i>
            <p class="empty-state-title">No leave applications found</p>
            <p class="empty-state-description">You haven't submitted any leave applications yet, or no applications match your current filters.</p>
            <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="mt-4 inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-plus mr-2"></i>Apply for Leave
            </a>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="leaveTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applied</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($timeline as $item): ?>
                        <?php if ($item['type'] === 'leave'): ?>
                            <?php $leave = $item['data']; ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo formatDate($leave['start_date']); ?><br>
                                    <span class="text-gray-500">to</span> <?php echo formatDate($leave['end_date']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                    <?php echo $leave['total_days']; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700 max-w-xs truncate">
                                    <?php echo sanitize(substr($leave['reason'], 0, 50)) . (strlen($leave['reason']) > 50 ? '...' : ''); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo getStatusBadge($leave['status']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo timeAgo($leave['created_at']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button onclick="viewLeaveDetails(<?php echo $leave['id']; ?>)" class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $adj = $item['data']; ?>
                            <?php
                                $adjAmount = (float)$adj['adjustment_amount'];
                                $adjColor = $adjAmount > 0 ? 'text-green-600' : 'text-red-600';
                                $adjPrefix = $adjAmount > 0 ? '+' : '';
                            ?>
                            <tr class="hover:bg-indigo-50 bg-indigo-50/30">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo getLeaveTypeBadge($adj['leave_type']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-indigo-600 font-medium">
                                    <i class="fas fa-balance-scale mr-1"></i>Balance Adjustment
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?php echo $adjColor; ?>">
                                    <?php echo $adjPrefix . number_format($adjAmount, 1); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700 max-w-xs truncate">
                                    <?php echo sanitize(substr($adj['remarks'], 0, 50)) . (strlen($adj['remarks']) > 50 ? '...' : ''); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full" style="background-color: #e0e7ff; color: #4338ca;">
                                        <i class="fas fa-user-shield mr-1"></i>Admin Adjusted
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo timeAgo($adj['created_at']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button onclick="viewAdjustmentDetails(<?php echo htmlspecialchars(json_encode([
                                        'leave_type' => getLeaveTypeLabel($adj['leave_type']),
                                        'amount' => $adjPrefix . number_format($adjAmount, 1),
                                        'remarks' => $adj['remarks'],
                                        'adjusted_by' => $adj['adjusted_by_name'] ?? 'Admin',
                                        'date' => formatDateTime($adj['created_at'])
                                    ]), ENT_QUOTES, 'UTF-8'); ?>)" class="text-indigo-600 hover:text-indigo-800">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Leave Details Modal -->
<div id="leaveModal" class="hidden modal-backdrop">
    <div class="modal-content" style="max-width: 48rem;">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-xl font-semibold text-gray-900">Leave Application Details</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="modalContent" class="px-6 py-4">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<script>
// Initialize DataTable
$(document).ready(function() {
    $('#leaveTable').DataTable({
        responsive: true,
        order: [[5, 'desc']],
        pageLength: 25,
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries"
        }
    });
});

function viewLeaveDetails(leaveId) {
    document.getElementById('leaveModal').classList.remove('hidden');
    document.getElementById('modalContent').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-4xl text-blue-600"></i></div>';

    fetch(`<?php echo BASE_URL; ?>/api/leaves/details.php?id=${leaveId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLeaveDetails(data.data);
            } else {
                document.getElementById('modalContent').innerHTML = '<div class="text-center text-red-600 py-8">Error loading details</div>';
            }
        })
        .catch(error => {
            document.getElementById('modalContent').innerHTML = '<div class="text-center text-red-600 py-8">Error loading details</div>';
        });
}

function displayLeaveDetails(leave) {
    let html = `
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Leave Type</p>
                    <p class="font-semibold">${leave.leave_type_label}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Status</p>
                    <p>${leave.status_badge}</p>
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
                    <p class="text-sm text-gray-600">Total Days</p>
                    <p class="font-semibold">${leave.total_days}</p>
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
                <h4 class="font-semibold text-gray-900 mb-3">Approval Chain</h4>
                ${leave.approval_chain}
            </div>
            ` : ''}
        </div>
    `;
    document.getElementById('modalContent').innerHTML = html;
}

function viewAdjustmentDetails(data) {
    document.getElementById('leaveModal').classList.remove('hidden');

    let amountClass = parseFloat(data.amount) > 0 ? 'text-green-600' : 'text-red-600';
    let html = `
        <div class="space-y-6">
            <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                <p class="text-sm text-indigo-800 font-medium">
                    <i class="fas fa-balance-scale mr-1"></i>This is an admin balance adjustment, not a leave application.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Leave Type</p>
                    <p class="font-semibold">${data.leave_type}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Adjustment Amount</p>
                    <p class="font-semibold ${amountClass}">${data.amount} days</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Adjusted By</p>
                    <p class="font-semibold">${data.adjusted_by}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Date</p>
                    <p class="font-semibold">${data.date}</p>
                </div>
            </div>
            <div>
                <p class="text-sm text-gray-600 mb-2">Remarks</p>
                <p class="p-3 bg-gray-50 rounded-lg">${data.remarks}</p>
            </div>
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
