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

<div class="page-header">
    <div>
        <p class="page-eyebrow">Head of Department</p>
        <h1 class="page-title">Pending HOD Approvals</h1>
        <p class="page-subtitle">Review leave applications with Guide &amp; TA recommendations</p>
    </div>
    <?php if ($pendingCount > 0): ?>
        <span class="badge badge-pending self-start px-3 py-1 text-sm sm:self-auto">
            <i class="fas fa-inbox"></i><?php echo $pendingCount; ?> Pending
        </span>
    <?php endif; ?>
</div>

<?php displayFlashMessage(); ?>

<?php if (empty($pendingApprovals)): ?>
    <div class="card">
        <div class="px-6 py-14 text-center">
            <div class="empty-state-icon h-14 w-14 bg-emerald-50 text-2xl text-emerald-600 ring-emerald-100">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-900">All caught up!</h3>
            <p class="empty-state-description">No pending applications for HOD approval at this time.</p>
            <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="btn btn-secondary btn-sm mt-5">
                <i class="fas fa-list"></i>View All Leave Applications
            </a>
        </div>
    </div>
<?php else: ?>
    <?php
    // Timeline marker classes, icon and badge per prior-approval status (presentation only).
    $timelineMarker = [
        'approved' => ['bg-emerald-50 text-emerald-600 ring-emerald-200', 'fa-check', '<span class="badge badge-approved"><i class="fas fa-check"></i>Approved</span>'],
        'rejected' => ['bg-rose-50 text-rose-600 ring-rose-200', 'fa-times', '<span class="badge badge-rejected"><i class="fas fa-times"></i>Rejected</span>'],
        'pending'  => ['bg-amber-50 text-amber-600 ring-amber-200', 'fa-clock', '<span class="badge badge-pending"><i class="fas fa-clock"></i>Pending</span>'],
    ];
    ?>
    <div class="space-y-5">
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
            $guideMarker = $guideStep ? ($timelineMarker[$guideStep['status']] ?? $timelineMarker['pending']) : null;
            $taMarker    = $taStep ? ($timelineMarker[$taStep['status']] ?? $timelineMarker['pending']) : null;
            ?>
            <div class="card overflow-hidden">
                <div class="h-1 bg-blue-500" aria-hidden="true"></div>

                <div class="card-body">
                    <!-- Top row: student, leave type, status -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="icon-chip chip-brand"><i class="fas fa-user-graduate"></i></span>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-900 sm:text-lg">
                                    <?php echo sanitize($leave['student_name']); ?>
                                </h3>
                                <p class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 text-sm text-gray-500">
                                    <span class="font-medium text-gray-700"><?php echo sanitize($leave['roll_number']); ?></span>
                                    <span class="hidden text-gray-300 sm:inline" aria-hidden="true">&middot;</span>
                                    <span class="min-w-0 max-w-full truncate"><?php echo sanitize($leave['student_email']); ?></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-shrink-0 flex-wrap items-center gap-2 pl-14 sm:justify-end sm:pl-0">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            <?php echo getStatusBadge($leave['status']); ?>
                            <?php if ($isExtended): ?>
                                <span class="badge badge-rejected">
                                    <i class="fas fa-exclamation-triangle"></i>Extended (>15 days)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Meta: dates, days, applied, waiting -->
                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-calendar text-gray-400"></i>
                            <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fas fa-hourglass-half text-gray-400"></i>
                            <span><strong class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></strong> days</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-paper-plane text-gray-400"></i>
                            Applied <?php echo formatDate($leave['created_at']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5 font-medium text-amber-700" title="Pending since">
                            <i class="far fa-clock"></i><?php echo timeAgo($leave['created_at']); ?>
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-5 lg:gap-6">
                        <!-- Main column: reason, document, prior approvals -->
                        <div class="space-y-5 lg:col-span-3">
                            <!-- Reason for Leave -->
                            <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Reason for leave</p>
                                <p class="text-sm leading-relaxed text-gray-700"><?php echo nl2br(sanitize($leave['reason'])); ?></p>
                            </div>

                            <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                                <!-- Medical Certificate -->
                                <div class="flex flex-col gap-3 rounded-xl border border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-3">
                                        <span class="icon-chip-sm chip-rose"><i class="fas fa-file-pdf"></i></span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">Medical Certificate</p>
                                            <p class="text-xs text-gray-500">PDF document attached by student</p>
                                        </div>
                                    </div>
                                    <?php // Served only through the access-checked endpoint, never by path. ?>
                                    <button type="button" data-pdf-url="<?php echo htmlspecialchars(BASE_URL . '/api/files/download.php?leave_id=' . (int) $leave['id'] . '&doc=medical', ENT_QUOTES, 'UTF-8'); ?>" onclick="openPdfViewer(this.dataset.pdfUrl)" class="btn btn-secondary btn-sm w-full sm:w-auto">
                                        <i class="fas fa-eye"></i>View Document
                                    </button>
                                </div>
                            <?php endif; ?>

                            <!-- Guide & TA Approval Section -->
                            <div>
                                <p class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500">Prior approvals &amp; remarks</p>
                                <ol>
                                    <!-- Faculty Guide -->
                                    <li class="relative flex gap-3 pb-5">
                                        <span class="absolute bottom-0 left-4 top-9 -ml-px w-px bg-gray-200" aria-hidden="true"></span>
                                        <span class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs ring-1 ring-inset <?php echo $guideMarker ? $guideMarker[0] : 'bg-gray-50 text-gray-400 ring-gray-200'; ?>">
                                            <i class="fas <?php echo $guideMarker ? $guideMarker[1] : 'fa-minus'; ?>"></i>
                                        </span>
                                        <div class="min-w-0 flex-1 pt-1">
                                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                                <p class="text-sm font-semibold text-gray-900">Faculty Guide</p>
                                                <?php if ($guideMarker) echo $guideMarker[2]; ?>
                                            </div>
                                            <?php if ($guideStep && !empty($guideStep['approver_name'])): ?>
                                                <p class="mt-0.5 text-xs text-gray-500">
                                                    <span class="font-medium text-gray-700"><?php echo sanitize($guideStep['approver_name']); ?></span>
                                                    <?php if (!empty($guideStep['approved_at'])): ?>
                                                        &middot; <?php echo formatDateTime($guideStep['approved_at']); ?>
                                                    <?php endif; ?>
                                                </p>
                                                <?php if (!empty($guideStep['remarks'])): ?>
                                                    <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm italic text-gray-700 ring-1 ring-inset ring-gray-100">
                                                        "<?php echo nl2br(sanitize($guideStep['remarks'])); ?>"
                                                    </p>
                                                <?php else: ?>
                                                    <p class="mt-1 text-sm italic text-gray-400">No remarks provided</p>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="mt-0.5 text-sm italic text-gray-400">No Faculty Guide assigned</p>
                                            <?php endif; ?>
                                        </div>
                                    </li>

                                    <!-- TA Coordinator -->
                                    <li class="relative flex gap-3 pb-5">
                                        <span class="absolute bottom-0 left-4 top-9 -ml-px w-px bg-gray-200" aria-hidden="true"></span>
                                        <span class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs ring-1 ring-inset <?php echo $taMarker ? $taMarker[0] : 'bg-gray-50 text-gray-400 ring-gray-200'; ?>">
                                            <i class="fas <?php echo $taMarker ? $taMarker[1] : 'fa-minus'; ?>"></i>
                                        </span>
                                        <div class="min-w-0 flex-1 pt-1">
                                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                                <p class="text-sm font-semibold text-gray-900">TA Coordinator</p>
                                                <?php if ($taMarker) echo $taMarker[2]; ?>
                                            </div>
                                            <?php if ($taStep && !empty($taStep['approver_name'])): ?>
                                                <p class="mt-0.5 text-xs text-gray-500">
                                                    <span class="font-medium text-gray-700"><?php echo sanitize($taStep['approver_name']); ?></span>
                                                    <?php if (!empty($taStep['approved_at'])): ?>
                                                        &middot; <?php echo formatDateTime($taStep['approved_at']); ?>
                                                    <?php endif; ?>
                                                </p>

                                                <!-- TA Duty Confirmation Status -->
                                                <?php if ($taStep['status'] === 'approved'): ?>
                                                    <div class="mt-2">
                                                        <?php if (!empty($taStep['no_duty_confirmed'])): ?>
                                                            <span class="badge badge-approved">
                                                                <i class="fas fa-check-circle"></i>No TA duties during leave period
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-pending">
                                                                <i class="fas fa-info-circle"></i>TA duties may be affected
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($taStep['remarks'])): ?>
                                                    <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm italic text-gray-700 ring-1 ring-inset ring-gray-100">
                                                        "<?php echo nl2br(sanitize($taStep['remarks'])); ?>"
                                                    </p>
                                                <?php else: ?>
                                                    <p class="mt-1 text-sm italic text-gray-400">No remarks provided</p>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p class="mt-0.5 text-sm italic text-gray-400">No TA Coordinator assigned</p>
                                            <?php endif; ?>
                                        </div>
                                    </li>

                                    <!-- HOD: this decision -->
                                    <li class="relative flex gap-3">
                                        <span class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-blue-50 text-xs text-blue-600 ring-2 ring-inset ring-blue-200">
                                            <i class="fas fa-user-check"></i>
                                        </span>
                                        <div class="min-w-0 flex-1 pt-1.5">
                                            <p class="text-sm font-semibold text-gray-900">HOD <span class="font-normal text-gray-500">&middot; awaiting your decision</span></p>
                                        </div>
                                    </li>
                                </ol>
                            </div>
                        </div>

                        <!-- Side column: balance, extended-leave note -->
                        <div class="space-y-4 lg:col-span-2">
                            <!-- Leave Balance Info -->
                            <div class="rounded-xl border p-4 <?php echo $balanceAfterApproval < 0 ? 'border-rose-200 bg-rose-50/60' : 'border-gray-200 bg-white'; ?>">
                                <p class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                                    <i class="fas fa-wallet text-gray-400"></i>Leave Balance
                                    <span class="font-normal text-gray-500">(<?php echo ucfirst($leave['leave_type']); ?> Leave)</span>
                                </p>
                                <dl class="mt-3 space-y-2 text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-gray-500">Current balance</dt>
                                        <dd class="font-semibold tabular-nums <?php echo $currentBalance < 0 ? 'text-rose-600' : 'text-gray-900'; ?>">
                                            <?php echo number_format($currentBalance, 1); ?> days
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <dt class="text-gray-500">Requested</dt>
                                        <dd class="font-semibold tabular-nums text-amber-700">
                                            -<?php echo $leave['total_days']; ?> days
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-2">
                                        <dt class="font-medium text-gray-700">After approval</dt>
                                        <dd class="text-base font-semibold tabular-nums <?php echo $balanceAfterApproval < 0 ? 'text-rose-600' : 'text-emerald-600'; ?>">
                                            <?php echo number_format($balanceAfterApproval, 1); ?> days
                                        </dd>
                                    </div>
                                </dl>
                                <?php if ($balanceAfterApproval < 0): ?>
                                    <p class="mt-3 flex items-start gap-2 text-xs font-medium text-rose-700">
                                        <i class="fas fa-exclamation-triangle mt-0.5"></i>
                                        <span>Approving this leave will result in a negative balance.</span>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Extended Leave Note -->
                            <?php if ($isExtended): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mt-0.5"></i>
                                    <div>
                                        <p class="font-semibold">Extended Leave Notice</p>
                                        <p class="mt-1">
                                            This leave exceeds 15 working days. After HOD approval,
                                            <?php if ($leave['leave_type'] === 'academic'): ?>
                                                it will be forwarded to the Admin for Dean AP approval.
                                            <?php else: ?>
                                                you must specify the payment status for the student.
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card-footer flex items-center gap-2 sm:justify-end">
                    <button
                        type="button"
                        onclick="showHODApprovalModal(<?php echo $leave['id']; ?>, 'reject', <?php echo $isExtended ? 'true' : 'false'; ?>)"
                        class="btn btn-soft-danger flex-1 sm:flex-none"
                    >
                        <i class="fas fa-times"></i>Reject
                    </button>
                    <button
                        type="button"
                        onclick="showHODApprovalModal(<?php echo $leave['id']; ?>, 'approve', <?php echo $isExtended ? 'true' : 'false'; ?>)"
                        class="btn btn-primary flex-1 sm:flex-none"
                    >
                        <i class="fas fa-check"></i>Approve as HOD
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- HOD Approval Modal -->
<div id="hodApprovalModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel max-h-[90vh] overflow-y-auto rounded-b-none sm:rounded-b-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-4 rounded-t-2xl border-b border-gray-100 bg-white px-5 py-4 sm:px-6">
            <h3 class="text-base font-semibold text-gray-900 sm:text-lg" id="hodModalTitle">HOD Approval</h3>
            <button type="button" onclick="closeHODModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch; keeps main.js from adding a btn-loading
             state that nothing removes when the API errors -->
        <form id="hodApprovalForm" class="noloading">
            <input type="hidden" id="hodLeaveId" name="leave_id">
            <input type="hidden" id="hodActionType" name="action">
            <input type="hidden" name="role" value="hod">
            <?php echo getCSRFField(); ?>

            <div class="space-y-4 px-5 py-5 sm:px-6">
                <div>
                    <label for="hodRemarks" class="form-label">
                        HOD Remarks <span class="text-rose-500">*</span>
                    </label>
                    <textarea
                        id="hodRemarks"
                        name="remarks"
                        rows="4"
                        class="form-textarea"
                        placeholder="Please provide your remarks as HOD (minimum 5 characters)..."
                        required
                        minlength="5"
                    ></textarea>
                    <p class="form-help">Your remarks will be visible to the student</p>
                </div>

                <div id="paymentStatusSection" class="hidden">
                    <label for="paymentStatus" class="form-label">
                        Payment Status for Extended Leave <span class="text-rose-500">*</span>
                    </label>
                    <select
                        id="paymentStatus"
                        name="payment_status"
                        class="form-select"
                    >
                        <option value="">Select Payment Status</option>
                        <option value="paid">Paid - Full stipend continues</option>
                        <option value="partially_paid">Partially Paid - Reduced stipend</option>
                        <option value="unpaid">Unpaid - No stipend during leave</option>
                    </select>
                    <p class="form-help">Required for leaves longer than 15 days</p>
                </div>

                <div id="extendedLeaveNote" class="alert alert-info hidden">
                    <i class="fas fa-info-circle mt-0.5"></i>
                    <p>
                        <strong class="font-semibold">Note:</strong> This is an extended academic leave (>15 days). After your approval, it will require Dean AP approval through Admin.
                    </p>
                </div>

                <div id="rejectWarning" class="alert alert-error hidden">
                    <i class="fas fa-exclamation-triangle mt-0.5"></i>
                    <p>
                        <strong class="font-semibold">Warning:</strong> You are about to reject this leave application. The student will be notified of this decision.
                    </p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button
                    type="button"
                    onclick="closeHODModal()"
                    class="btn btn-secondary"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    id="hodSubmitBtn"
                    class="btn btn-primary"
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
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check"></i>Approve';

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
        submitBtn.className = 'btn btn-danger';
        submitBtn.innerHTML = '<i class="fas fa-times"></i>Reject';
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
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Processing...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            // Show the outcome here, then reload. It used to be handed to the
            // reloaded page as ?success=<text> and written into innerHTML there,
            // so any crafted link ran script in the HOD's session.
            closeHODModal();
            showSuccessToast(result.message);
            setTimeout(() => {
                window.location.href = '<?php echo BASE_URL; ?>/hod/pending_approvals.php';
            }, 1500);
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

// Success toast. The message goes in as text, never as markup.
function showSuccessToast(message) {
    const toast = document.createElement('div');
    toast.className = 'fixed top-20 right-4 z-50 flex max-w-sm items-center gap-2.5 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-medium text-white shadow-lg';
    const icon = document.createElement('i');
    icon.className = 'fas fa-check-circle';
    const text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(icon);
    toast.appendChild(text);
    document.body.appendChild(toast);

    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.transition = 'opacity 0.5s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 500);
    }, 3000);
}

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
    <div class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-gray-900 px-4 py-3 sm:px-6">
        <h3 class="flex items-center gap-3 text-sm font-semibold text-white sm:text-base">
            <span class="icon-chip-sm bg-white/10 text-rose-300 ring-white/10"><i class="fas fa-file-medical"></i></span>
            Medical Certificate
        </h3>
        <button type="button" onclick="closePdfViewer()" class="btn-icon h-9 w-9 text-gray-300 hover:bg-white/10 hover:text-white" title="Close" aria-label="Close">
            <i class="fas fa-times text-lg"></i>
        </button>
    </div>
    <div class="flex-1 min-h-0 bg-gray-800">
        <iframe id="pdfFrame" src="" class="w-full h-full border-0"></iframe>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
