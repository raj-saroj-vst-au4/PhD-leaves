<?php
/**
 * Student Leave History
 * View all leave applications with status and approval chain
 */

// NOTE: POST handler must come BEFORE header.php include to avoid "headers already sent" error
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';
require_once __DIR__ . '/../includes/EmailService.php';

requireRole('student');

// Cancel one of the student's own leaves (until the day before it starts).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    checkCSRF();
    // An admin "logged in as" the student would be recorded as the student
    // cancelling; the office cancels from Leave Management instead.
    blockIfImpersonating('Leave cannot be cancelled while impersonating a student. '
        . 'Use Leave Management to cancel it as the office.');

    $leaveId = (int) ($_POST['leave_id'] ?? 0);
    $reason = sanitize($_POST['reason'] ?? '');
    try {
        $before = (new Leave())->getById($leaveId);
        $result = (new Approval())->cancelByStudent($leaveId, getCurrentUserId(), $reason);

        if ($result['previous_status'] !== 'approved') {
            $message = 'Your leave application has been cancelled.';
        } elseif ($result['refunded_days'] > 0) {
            $message = "Your leave has been cancelled and {$result['refunded_days']} day(s) returned to your "
                . "{$before['leave_type']} leave balance.";
        } else {
            $message = 'Your leave has been cancelled. Its days were not returned to your academic leave balance: '
                . 'they came out of the allocation before the latest half-yearly reset, which has since been replaced.';
        }
        setFlashMessage('success', $message);

        // Committed; the emails are best-effort.
        try {
            (new EmailService())->sendStudentCancellation($before, $reason, $result['refunded_days']);
        } catch (Throwable $emailEx) {
            logError("Student cancellation email failed for leave $leaveId: " . $emailEx->getMessage());
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/student/leave_history.php');
    exit;
}

$pageTitle = 'Leave History';
require_once __DIR__ . '/../includes/header.php';

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

<div class="page-header">
    <div>
        <p class="page-eyebrow">Student</p>
        <h1 class="page-title">Leave History</h1>
        <p class="page-subtitle">View all your leave applications</p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="btn btn-primary">
            <i class="fas fa-plus"></i>Apply Leave
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Leave Applications Table -->
<div class="card overflow-hidden">
    <!-- Filters -->
    <form method="GET" class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:flex-wrap sm:items-end sm:px-6">
        <div class="sm:w-60">
            <label for="filter_status" class="form-label">Status</label>
            <select name="status" id="filter_status" class="form-select">
                <option value="">All Status</option>
                <option value="pending_routing" <?php echo $filterStatus === 'pending_routing' ? 'selected' : ''; ?>>Awaiting Guide/TA Change</option>
                <option value="pending_guide" <?php echo $filterStatus === 'pending_guide' ? 'selected' : ''; ?>>Pending Guide</option>
                <option value="pending_ta" <?php echo $filterStatus === 'pending_ta' ? 'selected' : ''; ?>>Pending TA</option>
                <option value="pending_hod" <?php echo $filterStatus === 'pending_hod' ? 'selected' : ''; ?>>Pending HOD</option>
                <option value="pending_admin" <?php echo $filterStatus === 'pending_admin' ? 'selected' : ''; ?>>Pending Admin</option>
                <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                <option value="combined" <?php echo $filterStatus === 'combined' ? 'selected' : ''; ?>>Combined into another leave</option>
            </select>
        </div>
        <div class="sm:w-48">
            <label for="filter_type" class="form-label">Type</label>
            <select name="type" id="filter_type" class="form-select">
                <option value="">All Types</option>
                <option value="personal" <?php echo $filterType === 'personal' ? 'selected' : ''; ?>>Personal</option>
                <option value="medical" <?php echo $filterType === 'medical' ? 'selected' : ''; ?>>Medical</option>
                <option value="academic" <?php echo $filterType === 'academic' ? 'selected' : ''; ?>>Academic</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary flex-1 sm:flex-none">
                <i class="fas fa-filter"></i>Filter
            </button>
            <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="btn btn-ghost flex-1 sm:flex-none">
                <i class="fas fa-times"></i>Clear
            </a>
        </div>
        <?php if (!empty($adjustments)): ?>
            <p class="flex items-center gap-2 text-xs text-gray-500 sm:ml-auto sm:self-center">
                <span class="inline-block h-2.5 w-2.5 rounded-full bg-indigo-200 ring-1 ring-indigo-300"></span>
                Tinted rows are balance adjustments made by the office.
            </p>
        <?php endif; ?>
    </form>

    <?php if (empty($timeline)): ?>
        <div class="empty-state m-5 sm:m-6">
            <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
            <p class="empty-state-title">No leave applications found</p>
            <p class="empty-state-description">You haven't submitted any leave applications yet, or no applications match your current filters.</p>
            <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="btn btn-primary mt-5">
                <i class="fas fa-plus"></i>Apply for Leave
            </a>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern" id="leaveTable">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeline as $item): ?>
                        <?php if ($item['type'] === 'leave'): ?>
                            <?php $leave = $item['data']; ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="block font-medium text-gray-900"><?php echo formatDate($leave['start_date']); ?></span>
                                    <span class="block text-xs text-gray-500">to <?php echo formatDate($leave['end_date']); ?></span>
                                </td>
                                <td class="whitespace-nowrap font-semibold text-gray-900">
                                    <?php echo $leave['total_days']; ?>
                                </td>
                                <td class="max-w-xs truncate text-gray-600">
                                    <?php echo sanitize(substr($leave['reason'], 0, 50)) . (strlen($leave['reason']) > 50 ? '...' : ''); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?php echo getStatusBadge($leave['status']); ?>
                                </td>
                                <td class="whitespace-nowrap text-gray-500">
                                    <?php echo timeAgo($leave['created_at']); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="flex gap-2">
                                        <button onclick="viewLeaveDetails(<?php echo (int) $leave['id']; ?>)" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if (Leave::studentCanCancel($leave)): ?>
                                            <?php /* Data for openCancelModal(), which sets it with
                                               textContent: plain text, not HTML. */ ?>
                                            <button onclick="openCancelModal(<?php echo htmlspecialchars(json_encode([
                                                'id' => (int) $leave['id'],
                                                'leave_type' => getLeaveTypeLabel($leave['leave_type']),
                                                'dates' => formatDate($leave['start_date']) . ' to ' . formatDate($leave['end_date']),
                                                'days' => (int) $leave['total_days'],
                                                'approved' => $leave['status'] === 'approved',
                                                'refund' => $leaveClass->cancellationRefund($leave),
                                                'last_day' => formatDate(date('Y-m-d', strtotime($leave['start_date'] . ' -1 day')))
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>)" class="btn btn-soft-danger btn-sm">
                                                <i class="fas fa-ban"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $adj = $item['data']; ?>
                            <?php
                                $adjAmount = (float)$adj['adjustment_amount'];
                                $adjColor = $adjAmount > 0 ? 'text-emerald-600' : 'text-rose-600';
                                $adjPrefix = $adjAmount > 0 ? '+' : '';
                            ?>
                            <tr class="bg-indigo-50/40 hover:bg-indigo-50">
                                <td class="whitespace-nowrap">
                                    <?php echo getLeaveTypeBadge($adj['leave_type']); ?>
                                </td>
                                <td class="whitespace-nowrap font-medium text-indigo-700">
                                    <i class="fas fa-balance-scale mr-1"></i>Balance Adjustment
                                </td>
                                <td class="whitespace-nowrap font-semibold <?php echo $adjColor; ?>">
                                    <?php echo $adjPrefix . number_format($adjAmount, 1); ?>
                                </td>
                                <td class="max-w-xs truncate text-gray-600">
                                    <?php echo sanitize(substr($adj['remarks'], 0, 50)) . (strlen($adj['remarks']) > 50 ? '...' : ''); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="badge bg-indigo-50 text-indigo-700 ring-indigo-200">
                                        <i class="fas fa-user-shield mr-1 text-xs"></i>Admin Adjusted
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-gray-500">
                                    <?php echo timeAgo($adj['created_at']); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?php /* viewAdjustmentDetails() puts these into innerHTML, so
                                       the free text goes as HTML escaped exactly once (remarks
                                       are stored escaped by sanitize(); decode first). */ ?>
                                    <button onclick="viewAdjustmentDetails(<?php echo htmlspecialchars(json_encode([
                                        'leave_type' => getLeaveTypeLabel($adj['leave_type']),
                                        'amount' => $adjPrefix . number_format($adjAmount, 1),
                                        'remarks' => sanitize(html_entity_decode((string) $adj['remarks'], ENT_QUOTES, 'UTF-8')),
                                        'adjusted_by' => sanitize($adj['adjusted_by_name'] ?? 'Admin'),
                                        'date' => formatDateTime($adj['created_at'])
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>)" class="btn btn-sm bg-white text-indigo-700 shadow-sm ring-1 ring-inset ring-indigo-200 hover:bg-indigo-50">
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
<div id="leaveModal" class="hidden modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="leaveModalTitle">
    <div class="modal-content" style="max-width: 48rem;">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-file-alt"></i></span>
                <h3 id="leaveModalTitle" class="text-lg font-semibold text-gray-900">Leave Application Details</h3>
            </div>
            <button type="button" onclick="closeModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="modalContent" class="px-6 py-5">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Cancel Leave Modal -->
<div id="cancelModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle">
    <div class="modal-panel max-w-md">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-rose"><i class="fas fa-ban"></i></span>
                <h3 id="cancelModalTitle" class="text-lg font-semibold text-gray-900">Cancel Leave</h3>
            </div>
            <button type="button" onclick="closeCancelModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="cancelForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="leave_id" id="cancelLeaveId">

            <div class="space-y-4 px-6 py-5">
                <div>
                    <p class="font-semibold text-gray-900" id="cancelLeaveType"></p>
                    <p class="mt-1 text-sm text-gray-500"><span id="cancelLeaveDates"></span> · <span id="cancelLeaveDays"></span></p>
                </div>

                <div>
                    <label for="cancelReason" class="form-label">
                        Reason for cancelling <span class="text-rose-500">*</span>
                    </label>
                    <textarea id="cancelReason"
                              name="reason"
                              rows="3"
                              required
                              minlength="5"
                              class="form-textarea"
                              placeholder="Why are you cancelling this leave? (at least 5 characters)"></textarea>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle mt-0.5"></i>
                    <p id="cancelBalanceNote"></p>
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mt-0.5"></i>
                    <p>This cannot be undone: to take this leave later you will have to apply again. Whoever has already approved it, or is reviewing it now, will be told by email.</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button" onclick="closeCancelModal()" class="btn btn-secondary">Keep Leave</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i>Cancel Leave
                </button>
            </div>
        </form>
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
    document.getElementById('modalContent').innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-spinner fa-spin text-3xl text-brand-600"></i></div>';

    fetch(`<?php echo BASE_URL; ?>/api/leaves/details.php?id=${leaveId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayLeaveDetails(data.data);
            } else {
                document.getElementById('modalContent').innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mt-0.5"></i><p>Error loading details</p></div>';
            }
        })
        .catch(error => {
            document.getElementById('modalContent').innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle mt-0.5"></i><p>Error loading details</p></div>';
        });
}

function displayLeaveDetails(leave) {
    let html = `
        <div class="space-y-6">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave Type</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.leave_type_label}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Status</dt>
                    <dd class="mt-1">${leave.status_badge}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Days</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.total_days}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Start Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.start_date_formatted}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">End Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.end_date_formatted}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Applied On</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${leave.created_at_formatted}</dd>
                </div>
            </dl>

            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Reason</p>
                <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-inset ring-gray-100">${leave.reason}</p>
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

function viewAdjustmentDetails(data) {
    document.getElementById('leaveModal').classList.remove('hidden');

    let amountClass = parseFloat(data.amount) > 0 ? 'text-emerald-600' : 'text-rose-600';
    let html = `
        <div class="space-y-6">
            <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3.5 text-sm font-medium text-indigo-800">
                <i class="fas fa-balance-scale mt-0.5"></i>
                <p>This is an admin balance adjustment, not a leave application.</p>
            </div>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave Type</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${data.leave_type}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Adjustment Amount</dt>
                    <dd class="mt-1 text-sm font-semibold ${amountClass}">${data.amount} days</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Adjusted By</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${data.adjusted_by}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Date</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-900">${data.date}</dd>
                </div>
            </dl>
            <div>
                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Remarks</p>
                <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 ring-1 ring-inset ring-gray-100">${data.remarks}</p>
            </div>
        </div>
    `;
    document.getElementById('modalContent').innerHTML = html;
}

function closeModal() {
    document.getElementById('leaveModal').classList.add('hidden');
}

function openCancelModal(leave) {
    document.getElementById('cancelLeaveId').value = leave.id;
    document.getElementById('cancelLeaveType').textContent = leave.leave_type;
    document.getElementById('cancelLeaveDates').textContent = leave.dates;
    document.getElementById('cancelLeaveDays').textContent = leave.days + (leave.days === 1 ? ' day' : ' days');
    let note;
    if (!leave.approved) {
        note = 'This leave has not been approved yet, so nothing has been deducted from your balance.';
    } else if (leave.refund > 0) {
        note = leave.refund + (leave.refund === 1 ? ' day' : ' days') + ' will be returned to your leave balance.';
    } else {
        note = 'These days came out of your academic leave allocation before the latest half-yearly reset, '
             + 'so they cannot be returned to your current balance.';
    }
    document.getElementById('cancelBalanceNote').textContent =
        note + ' You can cancel until ' + leave.last_day + '.';
    document.getElementById('cancelReason').value = '';
    document.getElementById('cancelModal').classList.remove('hidden');
    document.getElementById('cancelReason').focus();
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

document.getElementById('cancelModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeCancelModal();
    }
});

// Close modal on outside click
document.getElementById('leaveModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeCancelModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
