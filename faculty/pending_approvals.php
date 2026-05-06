<?php
/**
 * Faculty Pending Approvals
 */

$pageTitle = 'Pending Approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole('faculty');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$userId = getCurrentUserId();
$leaveClass = new Leave();
$approvalClass = new Approval();

// Determine role (guide or ta)
$activeRole = sanitize($_GET['role'] ?? 'guide');
if (!in_array($activeRole, ['guide', 'ta'])) {
    $activeRole = 'guide';
}

// Get pending applications
$pendingAsGuide = $leaveClass->getPendingForFaculty($userId, 'guide');
$pendingAsTA = $leaveClass->getPendingForFaculty($userId, 'ta_coordinator');

$activeApplications = ($activeRole === 'guide') ? $pendingAsGuide : $pendingAsTA;

// Get approval/rejection history
$historyAsGuide = $leaveClass->getHistoryForFaculty($userId, 'guide');
$historyAsTA = $leaveClass->getHistoryForFaculty($userId, 'ta_coordinator');
$activeHistory = ($activeRole === 'guide') ? $historyAsGuide : $historyAsTA;

// Handle single application view
$selectedLeave = null;
if (isset($_GET['id'])) {
    $leaveId = (int)$_GET['id'];
    $selectedLeave = $leaveClass->getById($leaveId);

    // Verify access
    if (!$selectedLeave || !canAccessLeave($selectedLeave, $userId, 'faculty')) {
        setFlashMessage('error', 'Access denied or leave not found');
        header('Location: ' . BASE_URL . '/faculty/pending_approvals.php');
        exit();
    }
}
?>

<div class="mb-4 sm:mb-6">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Pending Approvals</h1>
    <p class="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">Review and approve leave applications</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Role Tabs -->
<div class="mb-4 sm:mb-6">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-4 sm:space-x-8 overflow-x-auto">
            <a href="?role=guide" class="<?php echo $activeRole === 'guide' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-3 sm:py-4 px-1 border-b-2 font-medium text-sm sm:text-base">
                <i class="fas fa-user-tie mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">As </span>Faculty Guide
                <?php if (count($pendingAsGuide) > 0): ?>
                    <span class="ml-1 sm:ml-2 bg-orange-100 text-orange-800 py-0.5 px-2 rounded-full text-xs font-medium">
                        <?php echo count($pendingAsGuide); ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="?role=ta" class="<?php echo $activeRole === 'ta' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-3 sm:py-4 px-1 border-b-2 font-medium text-sm sm:text-base">
                <i class="fas fa-chalkboard-teacher mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">As </span>TA Coordinator
                <?php if (count($pendingAsTA) > 0): ?>
                    <span class="ml-1 sm:ml-2 bg-blue-100 text-blue-800 py-0.5 px-2 rounded-full text-xs font-medium">
                        <?php echo count($pendingAsTA); ?>
                    </span>
                <?php endif; ?>
            </a>
        </nav>
    </div>
</div>

<!-- Applications List -->
<?php if (empty($activeApplications)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
        <i class="fas fa-check-circle text-5xl sm:text-6xl text-green-500 mb-3 sm:mb-4"></i>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-2">All Caught Up!</h3>
        <p class="text-gray-600 text-sm sm:text-base">
            No pending applications as <?php echo $activeRole === 'guide' ? 'Faculty Guide' : 'TA Coordinator'; ?>
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 gap-4 sm:gap-6">
        <?php foreach ($activeApplications as $leave): ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden border-l-4 <?php echo $activeRole === 'guide' ? 'border-orange-500' : 'border-blue-500'; ?>">
                <div class="p-3 sm:p-4 md:p-6">
                    <div class="flex items-start justify-between mb-3 sm:mb-4">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-1">
                                <?php echo sanitize($leave['student_name']); ?>
                                <span class="block sm:inline text-sm font-normal text-gray-500 sm:ml-2">
                                    (<?php echo sanitize($leave['roll_number']); ?>)
                                </span>
                            </h3>
                            <p class="text-xs sm:text-sm text-gray-600 truncate"><?php echo sanitize($leave['student_email']); ?></p>
                        </div>
                        <div class="ml-2 flex-shrink-0">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4 mb-3 sm:mb-4">
                        <div class="col-span-2 sm:col-span-1">
                            <p class="text-xs text-gray-500">Duration</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base">
                                <?php echo formatDate($leave['start_date']); ?> to <?php echo formatDate($leave['end_date']); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Total Days</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base"><?php echo $leave['total_days']; ?> days</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Applied On</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base"><?php echo formatDate($leave['created_at']); ?></p>
                        </div>
                    </div>

                    <div class="mb-3 sm:mb-4">
                        <p class="text-xs text-gray-500 mb-1">Reason</p>
                        <p class="text-sm text-gray-700 bg-gray-50 p-2 sm:p-3 rounded">
                            <?php echo nl2br(sanitize($leave['reason'])); ?>
                        </p>
                    </div>

                    <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                        <div class="mb-3 sm:mb-4 p-2 sm:p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div class="flex items-center">
                                    <i class="fas fa-file-medical text-blue-600 text-lg mr-2"></i>
                                    <span class="text-xs sm:text-sm font-medium text-blue-800">Medical Certificate Attached</span>
                                </div>
                                <button type="button" onclick="openPdfViewer('<?php echo BASE_URL; ?>/public/<?php echo sanitize($leave['supporting_document']); ?>')" class="px-3 py-1.5 bg-blue-600 text-white text-xs sm:text-sm rounded-lg hover:bg-blue-700 transition-colors w-full sm:w-auto text-center">
                                    <i class="fas fa-eye mr-1"></i>View Document
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-center justify-end space-x-2 sm:space-x-3 pt-2 border-t border-gray-100 sm:border-0 sm:pt-0">
                        <button onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'reject', '<?php echo $activeRole; ?>')" class="flex-1 sm:flex-none px-3 sm:px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors text-sm">
                            <i class="fas fa-times mr-1 sm:mr-2"></i>Reject
                        </button>
                        <button onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'approve', '<?php echo $activeRole; ?>')" class="flex-1 sm:flex-none px-3 sm:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm">
                            <i class="fas fa-check mr-1 sm:mr-2"></i>Approve
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Approval/Rejection History -->
<div class="pt-8 sm:pt-14 mb-4 sm:mb-6">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">Approval History</h2>
    <p class="text-gray-600 text-xs sm:text-sm">Your past approvals and rejections as <?php echo $activeRole === 'guide' ? 'Faculty Guide' : 'TA Coordinator'; ?></p>
</div>

<?php if (empty($activeHistory)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 text-center">
        <i class="fas fa-history text-3xl sm:text-4xl text-gray-300 mb-3"></i>
        <h3 class="text-base sm:text-lg font-semibold text-gray-700 mb-1">No History Yet</h3>
        <p class="text-gray-500 text-xs sm:text-sm">
            You haven't approved or rejected any applications as <?php echo $activeRole === 'guide' ? 'Faculty Guide' : 'TA Coordinator'; ?> yet.
        </p>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <?php
        // Pre-compute decision data for both views
        $historyData = [];
        foreach ($activeHistory as $leave) {
            if ($activeRole === 'guide') {
                $decision = $leave['guide_status'];
                $remarks = $leave['guide_remarks'];
                $actedOn = $leave['guide_approved_at'];
            } else {
                $decision = $leave['ta_status'];
                $remarks = $leave['ta_remarks'];
                $actedOn = $leave['ta_approved_at'];
            }
            $decisionBadge = $decision === 'approved'
                ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Approved</span>'
                : '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Rejected</span>';
            $historyData[] = [
                'leave' => $leave,
                'decision' => $decision,
                'remarks' => $remarks,
                'actedOn' => $actedOn,
                'decisionBadge' => $decisionBadge,
            ];
        }
        ?>

        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden divide-y divide-gray-200">
            <?php foreach ($historyData as $item):
                $leave = $item['leave'];
            ?>
                <div class="p-3 sm:p-4">
                    <div class="flex items-start justify-between mb-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 text-sm sm:text-base truncate"><?php echo sanitize($leave['student_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></p>
                        </div>
                        <div class="ml-2 flex-shrink-0">
                            <?php echo $item['decisionBadge']; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:text-sm mt-2">
                        <div>
                            <span class="text-gray-500">Type:</span>
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                        </div>
                        <div>
                            <span class="text-gray-500">Days:</span>
                            <span class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></span>
                        </div>
                        <div class="col-span-2 mt-1">
                            <span class="text-gray-500">Duration:</span>
                            <span class="text-gray-900"><?php echo formatDate($leave['start_date']); ?> — <?php echo formatDate($leave['end_date']); ?></span>
                        </div>
                        <div class="col-span-2 mt-1">
                            <span class="text-gray-500">Overall:</span>
                            <?php echo getStatusBadge($leave['status']); ?>
                        </div>
                        <?php if (!empty($item['remarks'])): ?>
                            <div class="col-span-2 mt-1">
                                <span class="text-gray-500">Remarks:</span>
                                <span class="text-gray-700"><?php echo sanitize($item['remarks']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                        <span class="text-xs text-gray-400"><?php echo $item['actedOn'] ? formatDate($item['actedOn']) : '-'; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto">
            <table id="historyTable" class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Duration</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Decision</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">Remarks</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Acted On</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($historyData as $item):
                        $leave = $item['leave'];
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 lg:px-4 py-3">
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td class="px-3 lg:px-4 py-3"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700 hidden lg:table-cell">
                                <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700"><?php echo $leave['total_days']; ?></td>
                            <td class="px-3 lg:px-4 py-3"><?php echo $item['decisionBadge']; ?></td>
                            <td class="px-3 lg:px-4 py-3"><?php echo getStatusBadge($leave['status']); ?></td>
                            <td class="px-3 lg:px-4 py-3 text-gray-600 max-w-xs hidden xl:table-cell">
                                <?php if (!empty($item['remarks'])): ?>
                                    <span class="truncate block max-w-[200px]" title="<?php echo sanitize($item['remarks']); ?>">
                                        <?php echo sanitize($item['remarks']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Not Required</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-500 text-xs whitespace-nowrap hidden lg:table-cell">
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
<div id="approvalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end sm:items-center justify-center sm:p-4">
    <div class="bg-white rounded-t-lg sm:rounded-lg shadow-xl w-full sm:max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 flex justify-between items-center sticky top-0 bg-white z-10">
            <h3 class="text-lg sm:text-xl font-semibold text-gray-900" id="modalTitle">Approve Leave</h3>
            <button onclick="closeApprovalModal()" class="text-gray-400 hover:text-gray-600 p-1">
                <i class="fas fa-times text-lg sm:text-xl"></i>
            </button>
        </div>
        <form id="approvalForm" class="p-4 sm:p-6">
            <input type="hidden" id="leaveId" name="leave_id">
            <input type="hidden" id="actionType" name="action">
            <input type="hidden" id="roleType" name="role">
            <?php echo getCSRFField(); ?>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">
                    Remarks <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="remarks"
                    name="remarks"
                    rows="3"
                    class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base"
                    placeholder="Please provide your remarks..."
                    required
                ></textarea>
            </div>

            <div id="taDutySection" class="hidden mb-4">
                <label class="flex items-start sm:items-center">
                    <input type="checkbox" id="noDutyConfirm" name="no_duty_confirmed" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mt-0.5 sm:mt-0">
                    <span class="ml-2 text-xs sm:text-sm text-gray-700">
                        I confirm that the student has no TA duties during this period
                    </span>
                </label>
            </div>

            <div class="flex items-center justify-end space-x-2 sm:space-x-3">
                <button type="button" onclick="closeApprovalModal()" class="px-3 sm:px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">
                    Cancel
                </button>
                <button type="submit" id="submitBtn" class="px-3 sm:px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showApprovalModal(leaveId, action, role) {
    document.getElementById('leaveId').value = leaveId;
    document.getElementById('actionType').value = action;
    document.getElementById('roleType').value = role;
    document.getElementById('remarks').value = '';
    document.getElementById('noDutyConfirm').checked = false;

    const modal = document.getElementById('approvalModal');
    const title = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const taDutySection = document.getElementById('taDutySection');

    if (action === 'approve') {
        title.textContent = role === 'guide' ? 'Approve Leave (Faculty Guide)' : 'Approve Leave (TA Coordinator)';
        submitBtn.className = 'px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700';
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Approve';

        // Show TA duty confirmation only for TA coordinator approvals
        if (role === 'ta') {
            taDutySection.classList.remove('hidden');
        } else {
            taDutySection.classList.add('hidden');
        }
    } else {
        title.textContent = 'Reject Leave';
        submitBtn.className = 'px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700';
        submitBtn.innerHTML = '<i class="fas fa-times mr-2"></i>Reject';
        taDutySection.classList.add('hidden');
    }

    modal.classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}

document.getElementById('approvalForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const action = formData.get('action');
    const role = formData.get('role');

    // Validate TA duty confirmation
    if (action === 'approve' && role === 'ta') {
        if (!document.getElementById('noDutyConfirm').checked) {
            alert('Please confirm that the student has no TA duties during this period');
            return;
        }
    }

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            window.location.href = '<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=<?php echo $activeRole; ?>&success=' + encodeURIComponent(result.message);
        } else {
            alert('Error: ' + result.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = action === 'approve' ? '<i class="fas fa-check mr-2"></i>Approve' : '<i class="fas fa-times mr-2"></i>Reject';
        }
    } catch (error) {
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = action === 'approve' ? '<i class="fas fa-check mr-2"></i>Approve' : '<i class="fas fa-times mr-2"></i>Reject';
    }
});

// Close modal on outside click
document.getElementById('approvalModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeApprovalModal();
    }
});

// Show success message from URL parameter
<?php if (isset($_GET['success'])): ?>
    setTimeout(() => {
        alert('<?php echo sanitize($_GET['success']); ?>');
    }, 100);
<?php endif; ?>

// PDF Viewer functions
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

// Close PDF modal on outside click
document.getElementById('pdfModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closePdfViewer();
    }
});

// Initialize DataTable for history (only on md+ where table is visible)
$(document).ready(function() {
    if ($('#historyTable').length && $('#historyTable').is(':visible')) {
        $('#historyTable').DataTable({
            order: [[7, 'desc']],
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
    <div class="px-4 py-3 bg-gray-800 flex justify-between items-center flex-shrink-0">
        <h3 class="text-lg font-semibold text-white">
            <i class="fas fa-file-medical text-blue-400 mr-2"></i>Medical Certificate
        </h3>
        <button onclick="closePdfViewer()" class="text-gray-300 hover:text-white p-2 hover:bg-gray-700 rounded-full transition-colors">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    <div class="flex-1 min-h-0">
        <iframe id="pdfFrame" src="" class="w-full h-full border-0"></iframe>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
