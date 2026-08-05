<?php
/**
 * HOD Pending Approvals
 * Review and approve/reject leave applications pending HOD approval
 * Displays Guide & TA remarks for informed decision making
 */

$pageTitle = 'Pending Approvals - HOD';
require_once __DIR__ . '/../includes/header.php';
requireRole('hod');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$leaveClass = new Leave();
$approvalClass = new Approval();

// Get pending applications for HOD
$pendingApprovals = $leaveClass->getPendingForHOD();
$pendingCount = count($pendingApprovals);
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Pending HOD Approvals</h1>
            <p class="text-gray-600 mt-2">Review leave applications with Guide & TA recommendations</p>
        </div>
        <?php if ($pendingCount > 0): ?>
            <div class="bg-indigo-100 text-indigo-800 px-4 py-2 rounded-full font-semibold">
                <?php echo $pendingCount; ?> Pending
            </div>
        <?php endif; ?>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php if (empty($pendingApprovals)): ?>
    <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">All Caught Up!</h3>
        <p class="text-gray-600">No pending applications for HOD approval at this time.</p>
        <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="inline-block mt-4 text-indigo-600 hover:text-indigo-800">
            <i class="fas fa-list mr-1"></i> View All Leave Applications
        </a>
    </div>
<?php else: ?>
    <div class="space-y-6">
        <?php foreach ($pendingApprovals as $leave): ?>
            <?php
            $isExtended = $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD;
            $chainResult = $approvalClass->getApprovalChain($leave['id']);
            $approvalChain = $chainResult['approval_chain'] ?? [];

            // Get student's leave balance for the applied leave type
            $studentBalance = $leaveClass->getBalance($leave['student_id']);
            $leaveField = $leave['leave_type'] . '_leaves';
            $currentBalance = $studentBalance[$leaveField] ?? 0;
            $balanceAfterApproval = $currentBalance - $leave['total_days'];

            // Extract Guide and TA info from the chain
            $guideStep = null;
            $taStep = null;
            foreach ($approvalChain as $step) {
                if ($step['stage'] === 'Faculty Guide') {
                    $guideStep = $step;
                } elseif ($step['stage'] === 'TA Coordinator') {
                    $taStep = $step;
                }
            }
            ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden border-l-4 border-indigo-500">
                <!-- Header Section -->
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-indigo-600 text-xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">
                                        <?php echo sanitize($leave['student_name']); ?>
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        <span class="font-medium"><?php echo sanitize($leave['roll_number']); ?></span>
                                        <span class="mx-2">|</span>
                                        <?php echo sanitize($leave['student_email']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            <?php if ($isExtended): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Extended (>15 days)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <!-- Leave Details Grid -->
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">From</p>
                            <p class="font-semibold text-gray-900"><?php echo formatDate($leave['start_date']); ?></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">To</p>
                            <p class="font-semibold text-gray-900"><?php echo formatDate($leave['end_date']); ?></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Total Days</p>
                            <p class="font-semibold text-gray-900 text-xl"><?php echo $leave['total_days']; ?></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Applied On</p>
                            <p class="font-semibold text-gray-900"><?php echo formatDate($leave['created_at']); ?></p>
                        </div>
                        <div class="bg-gray-50 p-3 rounded-lg">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Pending Since</p>
                            <p class="font-semibold text-orange-600"><?php echo timeAgo($leave['created_at']); ?></p>
                        </div>
                    </div>

                    <!-- Leave Balance Info -->
                    <div class="mb-6 p-4 rounded-lg border <?php echo $balanceAfterApproval < 0 ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'; ?>">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-wallet mr-2 text-gray-400"></i>Leave Balance (<?php echo ucfirst($leave['leave_type']); ?> Leave)
                        </h4>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="text-center">
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Current Balance</p>
                                <p class="font-bold text-xl <?php echo $currentBalance < 0 ? 'text-red-600' : 'text-gray-900'; ?>">
                                    <?php echo number_format($currentBalance, 1); ?> days
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Requested</p>
                                <p class="font-bold text-xl text-orange-600">
                                    -<?php echo $leave['total_days']; ?> days
                                </p>
                            </div>
                            <div class="text-center">
                                <p class="text-xs text-gray-500 uppercase tracking-wide">After Approval</p>
                                <p class="font-bold text-xl <?php echo $balanceAfterApproval < 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                    <?php echo number_format($balanceAfterApproval, 1); ?> days
                                </p>
                            </div>
                        </div>
                        <?php if ($balanceAfterApproval < 0): ?>
                            <div class="mt-3 flex items-center text-sm text-red-700">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <span>Approving this leave will result in a negative balance.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Reason for Leave -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-comment-alt mr-2 text-gray-400"></i>Reason for Leave
                        </h4>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <p class="text-gray-700"><?php echo nl2br(sanitize($leave['reason'])); ?></p>
                        </div>
                    </div>

                    <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                        <!-- Medical Certificate -->
                        <div class="mb-6">
                            <h4 class="text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-file-medical mr-2 text-gray-400"></i>Supporting Document
                            </h4>
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-file-pdf text-red-500 text-2xl mr-3"></i>
                                        <div>
                                            <p class="font-medium text-gray-900">Medical Certificate</p>
                                            <p class="text-sm text-gray-600">PDF document attached by student</p>
                                        </div>
                                    </div>
                                    <button type="button" onclick="openPdfViewer('<?php echo BASE_URL; ?>/public/<?php echo sanitize($leave['supporting_document']); ?>')" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-eye mr-2"></i>View Document
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Guide & TA Approval Section -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3">
                            <i class="fas fa-clipboard-check mr-2 text-gray-400"></i>Prior Approvals & Remarks
                        </h4>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Faculty Guide Remarks -->
                            <div class="border rounded-lg overflow-hidden <?php echo ($guideStep && $guideStep['status'] === 'approved') ? 'border-green-200' : (($guideStep && $guideStep['status'] === 'rejected') ? 'border-red-200' : 'border-gray-200'); ?>">
                                <div class="px-4 py-3 <?php echo ($guideStep && $guideStep['status'] === 'approved') ? 'bg-green-50' : (($guideStep && $guideStep['status'] === 'rejected') ? 'bg-red-50' : 'bg-orange-50'); ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <i class="fas fa-chalkboard-teacher text-orange-600 mr-2"></i>
                                            <span class="font-semibold text-gray-900">Faculty Guide</span>
                                        </div>
                                        <?php if ($guideStep): ?>
                                            <?php if ($guideStep['status'] === 'approved'): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                    <i class="fas fa-check mr-1"></i>Approved
                                                </span>
                                            <?php elseif ($guideStep['status'] === 'rejected'): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                    <i class="fas fa-times mr-1"></i>Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                    <i class="fas fa-clock mr-1"></i>Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="px-4 py-3 bg-white">
                                    <?php if ($guideStep && !empty($guideStep['approver_name'])): ?>
                                        <p class="text-sm text-gray-600 mb-2">
                                            <i class="fas fa-user mr-1"></i>
                                            <strong><?php echo sanitize($guideStep['approver_name']); ?></strong>
                                            <?php if (!empty($guideStep['approved_at'])): ?>
                                                <span class="text-xs text-gray-500 ml-2">
                                                    <?php echo formatDateTime($guideStep['approved_at']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($guideStep['remarks'])): ?>
                                            <div class="bg-gray-50 p-3 rounded border-l-4 border-orange-400">
                                                <p class="text-sm text-gray-700 italic">
                                                    "<?php echo nl2br(sanitize($guideStep['remarks'])); ?>"
                                                </p>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-400 italic">No remarks provided</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-sm text-gray-400 italic">No Faculty Guide assigned</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- TA Coordinator Remarks -->
                            <div class="border rounded-lg overflow-hidden <?php echo ($taStep && $taStep['status'] === 'approved') ? 'border-green-200' : (($taStep && $taStep['status'] === 'rejected') ? 'border-red-200' : 'border-gray-200'); ?>">
                                <div class="px-4 py-3 <?php echo ($taStep && $taStep['status'] === 'approved') ? 'bg-green-50' : (($taStep && $taStep['status'] === 'rejected') ? 'bg-red-50' : 'bg-blue-50'); ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <i class="fas fa-user-tie text-blue-600 mr-2"></i>
                                            <span class="font-semibold text-gray-900">TA Coordinator</span>
                                        </div>
                                        <?php if ($taStep): ?>
                                            <?php if ($taStep['status'] === 'approved'): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                    <i class="fas fa-check mr-1"></i>Approved
                                                </span>
                                            <?php elseif ($taStep['status'] === 'rejected'): ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                    <i class="fas fa-times mr-1"></i>Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                    <i class="fas fa-clock mr-1"></i>Pending
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="px-4 py-3 bg-white">
                                    <?php if ($taStep && !empty($taStep['approver_name'])): ?>
                                        <p class="text-sm text-gray-600 mb-2">
                                            <i class="fas fa-user mr-1"></i>
                                            <strong><?php echo sanitize($taStep['approver_name']); ?></strong>
                                            <?php if (!empty($taStep['approved_at'])): ?>
                                                <span class="text-xs text-gray-500 ml-2">
                                                    <?php echo formatDateTime($taStep['approved_at']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </p>

                                        <!-- TA Duty Confirmation Status -->
                                        <?php if ($taStep['status'] === 'approved'): ?>
                                            <div class="mb-2">
                                                <?php if (!empty($taStep['no_duty_confirmed'])): ?>
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded bg-green-100 text-green-800">
                                                        <i class="fas fa-check-circle mr-1"></i>No TA duties during leave period
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded bg-yellow-100 text-yellow-800">
                                                        <i class="fas fa-info-circle mr-1"></i>TA duties may be affected
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($taStep['remarks'])): ?>
                                            <div class="bg-gray-50 p-3 rounded border-l-4 border-blue-400">
                                                <p class="text-sm text-gray-700 italic">
                                                    "<?php echo nl2br(sanitize($taStep['remarks'])); ?>"
                                                </p>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-400 italic">No remarks provided</p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="text-sm text-gray-400 italic">No TA Coordinator assigned</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Extended Leave Note -->
                    <?php if ($isExtended): ?>
                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex items-start">
                                <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-3"></i>
                                <div>
                                    <p class="text-sm font-semibold text-blue-800">Extended Leave Notice</p>
                                    <p class="text-sm text-blue-700 mt-1">
                                        This leave exceeds 15 working days. After HOD approval,
                                        <?php if ($leave['leave_type'] === 'academic'): ?>
                                            it will be forwarded to the Admin for Dean AP approval.
                                        <?php else: ?>
                                            you must specify the payment status for the student.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button
                            onclick="showHODApprovalModal(<?php echo $leave['id']; ?>, 'reject', <?php echo $isExtended ? 'true' : 'false'; ?>)"
                            class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors font-medium"
                        >
                            <i class="fas fa-times mr-2"></i>Reject
                        </button>
                        <button
                            onclick="showHODApprovalModal(<?php echo $leave['id']; ?>, 'approve', <?php echo $isExtended ? 'true' : 'false'; ?>)"
                            class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium"
                        >
                            <i class="fas fa-check mr-2"></i>Approve as HOD
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- HOD Approval Modal -->
<div id="hodApprovalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center sticky top-0 bg-white">
            <h3 class="text-xl font-semibold text-gray-900" id="hodModalTitle">HOD Approval</h3>
            <button onclick="closeHODModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch; keeps main.js from adding a btn-loading
             state that nothing removes when the API errors -->
        <form id="hodApprovalForm" class="p-6 noloading">
            <input type="hidden" id="hodLeaveId" name="leave_id">
            <input type="hidden" id="hodActionType" name="action">
            <input type="hidden" name="role" value="hod">
            <?php echo getCSRFField(); ?>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-comment mr-1"></i>
                    HOD Remarks <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="hodRemarks"
                    name="remarks"
                    rows="4"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Please provide your remarks as HOD (minimum 5 characters)..."
                    required
                    minlength="5"
                ></textarea>
                <p class="text-xs text-gray-500 mt-1">Your remarks will be visible to the student</p>
            </div>

            <div id="paymentStatusSection" class="hidden mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-money-bill-wave mr-1"></i>
                    Payment Status for Extended Leave <span class="text-red-500">*</span>
                </label>
                <select
                    id="paymentStatus"
                    name="payment_status"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500"
                >
                    <option value="">Select Payment Status</option>
                    <option value="paid">Paid - Full stipend continues</option>
                    <option value="partially_paid">Partially Paid - Reduced stipend</option>
                    <option value="unpaid">Unpaid - No stipend during leave</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Required for leaves longer than 15 days</p>
            </div>

            <div id="extendedLeaveNote" class="hidden mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Note:</strong> This is an extended academic leave (>15 days). After your approval, it will require Dean AP approval through Admin.
                </p>
            </div>

            <div id="rejectWarning" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-sm text-red-800">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Warning:</strong> You are about to reject this leave application. The student will be notified of this decision.
                </p>
            </div>

            <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                <button
                    type="button"
                    onclick="closeHODModal()"
                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    id="hodSubmitBtn"
                    class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
                >
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showHODApprovalModal(leaveId, action, isExtended) {
    document.getElementById('hodLeaveId').value = leaveId;
    document.getElementById('hodActionType').value = action;
    document.getElementById('hodRemarks').value = '';
    document.getElementById('paymentStatus').value = '';

    const modal = document.getElementById('hodApprovalModal');
    const title = document.getElementById('hodModalTitle');
    const submitBtn = document.getElementById('hodSubmitBtn');
    const paymentSection = document.getElementById('paymentStatusSection');
    const extendedNote = document.getElementById('extendedLeaveNote');
    const rejectWarning = document.getElementById('rejectWarning');

    // Reset all sections
    paymentSection.classList.add('hidden');
    extendedNote.classList.add('hidden');
    rejectWarning.classList.add('hidden');

    if (action === 'approve') {
        title.textContent = 'Approve Leave as HOD';
        submitBtn.className = 'px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors';
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Approve';

        // Show payment status for extended leaves
        if (isExtended) {
            paymentSection.classList.remove('hidden');
            extendedNote.classList.remove('hidden');
            document.getElementById('paymentStatus').required = true;
        } else {
            document.getElementById('paymentStatus').required = false;
        }
    } else {
        title.textContent = 'Reject Leave as HOD';
        submitBtn.className = 'px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors';
        submitBtn.innerHTML = '<i class="fas fa-times mr-2"></i>Reject';
        rejectWarning.classList.remove('hidden');
        document.getElementById('paymentStatus').required = false;
    }

    modal.classList.remove('hidden');
    document.getElementById('hodRemarks').focus();
}

function closeHODModal() {
    document.getElementById('hodApprovalModal').classList.add('hidden');
}

// Close modal on outside click
document.getElementById('hodApprovalModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeHODModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeHODModal();
    }
});

document.getElementById('hodApprovalForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = document.getElementById('hodSubmitBtn');
    const action = formData.get('action');
    const remarks = formData.get('remarks').trim();

    // Validate remarks
    if (remarks.length < 5) {
        alert('Please provide remarks with at least 5 characters.');
        document.getElementById('hodRemarks').focus();
        return;
    }

    // Validate payment status for extended leaves if approving
    const paymentSection = document.getElementById('paymentStatusSection');
    if (action === 'approve' && !paymentSection.classList.contains('hidden')) {
        const paymentStatus = formData.get('payment_status');
        if (!paymentStatus) {
            alert('Please select a payment status for extended leave.');
            document.getElementById('paymentStatus').focus();
            return;
        }
    }

    // Disable submit button and show loading
    submitBtn.disabled = true;
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Redirect with success message
            window.location.href = '<?php echo BASE_URL; ?>/hod/pending_approvals.php?success=' + encodeURIComponent(result.message);
        } else {
            alert('Error: ' + result.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    } catch (error) {
        alert('An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
});

// Show success message from URL parameter
<?php if (isset($_GET['success'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Create success toast
    const toast = document.createElement('div');
    toast.className = 'fixed top-20 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center';
    toast.innerHTML = '<i class="fas fa-check-circle mr-2"></i><?php echo sanitize($_GET['success']); ?>';
    document.body.appendChild(toast);

    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.transition = 'opacity 0.5s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
});
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
