<?php
/**
 * Admin - Pending Approvals
 * Handle final approval for extended leaves (>15 days) requiring Dean AP approval
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

requireRole('admin');

$leaveClass = new Leave();
$approvalClass = new Approval();

// Handle document upload. Before header.php, so the redirect can still be sent.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    checkCSRF();
    blockIfImpersonating();

    $storedName = null;
    try {
        $leaveId = (int)($_POST['leave_id'] ?? 0);

        if (!$leaveId) {
            throw new Exception('Invalid leave ID');
        }

        if (!isset($_FILES['dean_document']) || $_FILES['dean_document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a document to upload');
        }

        // Saved under a random name whose .pdf extension comes from the checked
        // content, in a private directory. This used to keep the client's own
        // extension under public/uploads/, where production nginx runs any .php.
        $storedName = uploadFile($_FILES['dean_document'], ALLOWED_UPLOAD_TYPES, MAX_UPLOAD_SIZE, DEAN_AP_DOCS_DIR);

        // Attached under the leave's row lock, and only while it is still
        // waiting for the admin.
        $previous = $approvalClass->attachDeanAPDocument($leaveId, getCurrentUserId(), $storedName);
        $storedName = null;
        if ($previous) {
            deletePrivateFile(DEAN_AP_DOCS_DIR, $previous);
        }

        setFlashMessage('success', 'Dean AP document uploaded successfully');

    } catch (Exception $e) {
        // Don't leave a saved file behind that no application points at.
        if ($storedName !== null) {
            deletePrivateFile(DEAN_AP_DOCS_DIR, $storedName);
        }
        setFlashMessage('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/admin/pending_approvals.php');
    exit();
}

$pageTitle = 'Pending Admin Approvals';
require_once __DIR__ . '/../includes/header.php';

// Get pending leaves that need admin approval
$pendingLeaves = $leaveClass->getPendingForAdmin();

/** Documents are served only through the access-checked download endpoint. */
function adminDocumentUrl($leaveId, $doc) {
    return BASE_URL . '/api/files/download.php?leave_id=' . (int) $leaveId . '&doc=' . urlencode($doc);
}
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Pending Admin Approvals</h1>
        <p class="page-subtitle">Extended leaves requiring Dean AP approval</p>
    </div>
    <?php if (count($pendingLeaves) > 0): ?>
        <span class="badge badge-pending self-start px-3 py-1 text-sm sm:self-auto" title="Applications Pending Admin Approval">
            <i class="fas fa-clipboard-check"></i><?php echo count($pendingLeaves); ?> Pending
        </span>
    <?php endif; ?>
</div>

<?php displayFlashMessage(); ?>

<!-- Info Box -->
<div class="alert alert-info mb-6">
    <i class="fas fa-info-circle mt-0.5"></i>
    <div>
        <p class="font-semibold">About Admin Approvals</p>
        <p class="mt-0.5">
            Extended academic leaves (>15 days) require Dean AP approval document.
            Upload the signed Dean AP approval document before approving the leave application.
        </p>
    </div>
</div>

<!-- Pending Applications -->
<?php if (empty($pendingLeaves)): ?>
    <div class="card">
        <div class="px-6 py-14 text-center">
            <div class="empty-state-icon h-14 w-14 bg-emerald-50 text-2xl text-emerald-600 ring-emerald-100">
                <i class="fas fa-check"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-900">All clear!</h3>
            <p class="empty-state-description">No applications pending admin approval</p>
        </div>
    </div>
<?php else: ?>
    <?php
    // Timeline marker classes and icon per approval-chain status (presentation only).
    $timelineMarker = [
        'approved' => ['bg-emerald-50 text-emerald-600 ring-emerald-200', 'fa-check'],
        'rejected' => ['bg-rose-50 text-rose-600 ring-rose-200', 'fa-times'],
        'pending'  => ['bg-amber-50 text-amber-600 ring-amber-200', 'fa-clock'],
    ];
    ?>
    <div class="space-y-5">
        <?php foreach ($pendingLeaves as $leave): ?>
            <?php
            // getApprovalChain() returns an envelope; the stages are under
            // 'approval_chain'. Looping over the envelope itself hit a string
            // ('current_status') and threw a TypeError on the first long leave.
            $chainResult = $approvalClass->getApprovalChain($leave['id']);
            $approvalChain = $chainResult['approval_chain'] ?? [];
            $isExtended = $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD;
            // The Dean AP document lives in admin_document_path (there is no
            // dean_ap_document column), and must actually be on disk.
            $hasDocument = privateFilePath(DEAN_AP_DOCS_DIR, $leave['admin_document_path'] ?? null) !== null;
            ?>
            <div class="card overflow-hidden">
                <div class="h-1 bg-amber-400" aria-hidden="true"></div>

                <div class="card-body">
                    <!-- Top row: application, student, leave type, status -->
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="icon-chip chip-amber"><i class="fas fa-user-graduate"></i></span>
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-gray-500">Leave Application #<?php echo $leave['id']; ?></p>
                                <h3 class="text-base font-semibold text-gray-900 sm:text-lg"><?php echo sanitize($leave['student_name']); ?></h3>
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
                        </div>
                    </div>

                    <!-- Meta: dates, duration, submitted -->
                    <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-600">
                        <span class="inline-flex items-center gap-1.5">
                            <i class="far fa-calendar text-gray-400"></i>
                            <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <i class="fas fa-hourglass-half text-gray-400"></i>
                            <span><strong class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></strong> working days</span>
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-gray-500">
                            <i class="far fa-clock text-gray-400"></i>Submitted <?php echo timeAgo($leave['created_at']); ?>
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-5 lg:gap-6">
                        <!-- Main column: reason, certificate, approval chain -->
                        <div class="space-y-5 lg:col-span-3">
                            <!-- Reason -->
                            <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                <p class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Reason</p>
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
                                    <button type="button" data-pdf-url="<?php echo htmlspecialchars(adminDocumentUrl($leave['id'], 'medical'), ENT_QUOTES, 'UTF-8'); ?>" onclick="openPdfViewer(this.dataset.pdfUrl)" class="btn btn-secondary btn-sm w-full sm:w-auto">
                                        <i class="fas fa-eye"></i>View Document
                                    </button>
                                </div>
                            <?php endif; ?>

                            <!-- Approval Chain -->
                            <div>
                                <p class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-500">Approval Chain</p>
                                <ol>
                                    <?php foreach ($approvalChain as $step): ?>
                                        <?php
                                        $stepStatus = $step['status'] ?? 'pending';
                                        $stepMarker = $timelineMarker[$stepStatus] ?? $timelineMarker['pending'];
                                        ?>
                                        <li class="group relative flex gap-3 pb-5 last:pb-0">
                                            <span class="absolute bottom-0 left-4 top-9 -ml-px w-px bg-gray-200 group-last:hidden" aria-hidden="true"></span>
                                            <span class="relative flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs ring-1 ring-inset <?php echo $stepMarker[0]; ?>">
                                                <i class="fas <?php echo $stepMarker[1]; ?>"></i>
                                            </span>
                                            <div class="min-w-0 flex-1 pt-1">
                                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5">
                                                    <span class="text-sm font-semibold text-gray-900"><?php echo sanitize($step['stage']); ?></span>
                                                    <?php if (!empty($step['approved_at'])): ?>
                                                        <span class="text-xs text-gray-500"><?php echo formatDateTime($step['approved_at']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($step['approver_name'])): ?>
                                                    <p class="mt-0.5 text-xs text-gray-500">
                                                        <?php echo sanitize($step['approver_name']); ?>
                                                        <?php if (!empty($step['auto_approved'])): ?>
                                                            &middot; settled by the Guide's approval (same person)
                                                        <?php endif; ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (!empty($step['remarks'])): ?>
                                                    <p class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm italic text-gray-700 ring-1 ring-inset ring-gray-100">"<?php echo sanitize($step['remarks']); ?>"</p>
                                                <?php endif; ?>
                                                <?php if ($step['stage'] === 'HOD' && !empty($step['payment_status'])): ?>
                                                    <div class="mt-2 flex items-center gap-2">
                                                        <span class="text-xs text-gray-500">Payment Status:</span>
                                                        <?php echo getPaymentStatusBadge($step['payment_status']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>

                        <!-- Side column: extended-leave notice, Dean AP document -->
                        <div class="space-y-4 lg:col-span-2">
                            <!-- Extended Leave Notice -->
                            <?php if ($isExtended): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mt-0.5 text-amber-500"></i>
                                    <div class="min-w-0">
                                        <p class="font-semibold">Extended Leave (>15 days)</p>
                                        <p class="mt-1">
                                            This application requires Dean AP approval document before final approval.
                                        </p>
                                        <p class="mt-2 flex flex-wrap items-center gap-2">
                                            <span>Payment Status:</span>
                                            <?php echo getPaymentStatusBadge($leave['hod_payment_status'] ?? null); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Dean AP Document -->
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500">Dean AP Approval Document</p>
                                <?php if ($hasDocument): ?>
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="icon-chip-sm chip-emerald"><i class="fas fa-file-pdf"></i></span>
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-gray-900">Document Uploaded</p>
                                                <p class="text-xs text-gray-500">Signed Dean AP approval (PDF)</p>
                                            </div>
                                        </div>
                                        <a href="<?php echo htmlspecialchars(adminDocumentUrl($leave['id'], 'dean_ap'), ENT_QUOTES, 'UTF-8'); ?>"
                                           target="_blank" rel="noopener"
                                           class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i>View
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" enctype="multipart/form-data" class="rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-4">
                                        <?php echo getCSRFField(); ?>
                                        <input type="hidden" name="action" value="upload_document">
                                        <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">

                                        <label for="dean_document_<?php echo (int) $leave['id']; ?>" class="flex items-center gap-2 text-sm font-medium text-gray-700">
                                            <i class="fas fa-file-upload text-gray-400"></i>Upload signed Dean AP approval
                                        </label>
                                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                            <input type="file"
                                                   id="dean_document_<?php echo (int) $leave['id']; ?>"
                                                   name="dean_document"
                                                   accept=".pdf"
                                                   required
                                                   class="block w-full min-w-0 flex-1 rounded-lg text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-white file:px-3 file:py-2 file:text-sm file:font-semibold file:text-gray-700 file:shadow-sm file:ring-1 file:ring-inset file:ring-gray-300 hover:file:bg-gray-50">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-upload"></i>Upload
                                            </button>
                                        </div>
                                        <p class="form-help">PDF only, max 5MB</p>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="card-footer flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <?php if (!$hasDocument): ?>
                        <p class="flex items-center gap-2 text-sm text-gray-500">
                            <i class="fas fa-lock text-gray-400"></i>Upload the Dean AP document to enable final approval
                        </p>
                    <?php endif; ?>
                    <div class="flex items-center gap-2 sm:ml-auto">
                        <button type="button" onclick="rejectLeave(<?php echo $leave['id']; ?>)"
                                class="btn btn-soft-danger flex-1 sm:flex-none">
                            <i class="fas fa-times"></i>Reject
                        </button>
                        <button type="button" onclick="approveLeave(<?php echo $leave['id']; ?>)"
                                class="btn btn-success flex-1 sm:flex-none"
                                <?php if (!$hasDocument): ?>disabled title="Upload Dean AP document first"<?php endif; ?>>
                            <i class="fas fa-check"></i>Final Approve
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 backdrop-blur-sm sm:items-center sm:p-4">
    <div class="modal-panel max-w-md rounded-b-none sm:rounded-b-2xl">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6">
            <h3 class="text-base font-semibold text-gray-900 sm:text-lg" id="modalTitle">Confirm Approval</h3>
            <button type="button" onclick="closeApprovalModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- noloading: submitted via fetch; keeps main.js from adding a btn-loading
             state that nothing removes when the API errors -->
        <form id="approvalForm" class="noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" id="actionLeaveId" name="leave_id">
            <input type="hidden" id="actionType" name="action">
            <input type="hidden" name="role" value="admin">

            <div class="px-5 py-5 sm:px-6">
                <label for="actionRemarks" class="form-label">Remarks <span class="text-rose-500">*</span></label>
                <textarea id="actionRemarks"
                          name="remarks"
                          rows="4"
                          required
                          minlength="5"
                          class="form-textarea"
                          placeholder="Enter your remarks (minimum 5 characters)..."></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 rounded-b-none border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:rounded-b-2xl sm:px-6">
                <button type="button"
                        onclick="closeApprovalModal()"
                        class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit"
                        id="submitBtn"
                        class="btn btn-primary">
                    <i class="fas fa-check"></i>Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function approveLeave(leaveId) {
    document.getElementById('modalTitle').textContent = 'Final Approval';
    document.getElementById('actionLeaveId').value = leaveId;
    document.getElementById('actionType').value = 'approve';
    document.getElementById('actionRemarks').placeholder = 'Enter approval remarks (e.g., Approved with Dean AP document)...';
    document.getElementById('submitBtn').className = 'btn btn-success';
    document.getElementById('approvalModal').classList.remove('hidden');
}

function rejectLeave(leaveId) {
    document.getElementById('modalTitle').textContent = 'Reject Application';
    document.getElementById('actionLeaveId').value = leaveId;
    document.getElementById('actionType').value = 'reject';
    document.getElementById('actionRemarks').placeholder = 'Enter rejection reason...';
    document.getElementById('submitBtn').className = 'btn btn-danger';
    document.getElementById('approvalModal').classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
    document.getElementById('approvalForm').reset();
}

// Handle form submission via AJAX
document.getElementById('approvalForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Processing...';

    fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification(data.message, 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        showNotification('An error occurred. Please try again.', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

function showNotification(message, type) {
    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-rose-600',
        warning: 'bg-amber-500',
        info: 'bg-blue-600'
    };

    const notification = document.createElement('div');
    notification.className = `fixed top-20 right-4 ${colors[type]} z-50 max-w-sm rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg animate-fade-in`;
    notification.innerHTML = `
        <div class="flex items-center gap-2.5">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span></span>
        </div>
    `;
    // The message is text from the server (it can quote stored values); never
    // parse it as markup.
    notification.querySelector('span').textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.remove();
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
