<?php
/**
 * Admin - Pending Approvals
 * Handle final approval for extended leaves (>15 days) requiring Dean AP approval
 */

$pageTitle = 'Pending Admin Approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$leaveClass = new Leave();
$approvalClass = new Approval();

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    checkCSRF();

    try {
        $leaveId = (int)($_POST['leave_id'] ?? 0);

        if (!$leaveId) {
            throw new Exception('Invalid leave ID');
        }

        if (!isset($_FILES['dean_document']) || $_FILES['dean_document']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a document to upload');
        }

        // Upload file
        $filename = uploadFile($_FILES['dean_document']);

        // Update leave application with document path
        $db = Database::getInstance()->getConnection();
        $sql = "UPDATE leave_applications SET dean_ap_document = :document WHERE id = :leave_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':document' => $filename,
            ':leave_id' => $leaveId
        ]);

        setFlashMessage('success', 'Dean AP document uploaded successfully');
        header('Location: ' . BASE_URL . '/admin/pending_approvals.php');
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get pending leaves that need admin approval
$pendingLeaves = $leaveClass->getPendingForAdmin();
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Pending Admin Approvals</h1>
    <p class="text-gray-600 mt-2">Extended leaves requiring Dean AP approval</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Info Box -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
    <div class="flex items-start">
        <i class="fas fa-info-circle text-blue-600 text-xl mr-3 mt-0.5"></i>
        <div>
            <p class="text-sm text-blue-800 font-medium mb-1">About Admin Approvals</p>
            <p class="text-sm text-blue-700">
                Extended academic leaves (>15 days) require Dean AP approval document.
                Upload the signed Dean AP approval document before approving the leave application.
            </p>
        </div>
    </div>
</div>

<!-- Pending Count -->
<?php if (count($pendingLeaves) > 0): ?>
    <div class="bg-white rounded-lg shadow-md p-4 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Applications Pending Admin Approval</p>
                <p class="text-3xl font-bold text-orange-600"><?php echo count($pendingLeaves); ?></p>
            </div>
            <i class="fas fa-clipboard-check text-6xl text-orange-100"></i>
        </div>
    </div>
<?php endif; ?>

<!-- Pending Applications -->
<?php if (empty($pendingLeaves)): ?>
    <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">All Clear!</h3>
        <p class="text-gray-600">No applications pending admin approval</p>
    </div>
<?php else: ?>
    <div class="space-y-6">
        <?php foreach ($pendingLeaves as $leave): ?>
            <?php
            $approvalChain = $approvalClass->getApprovalChain($leave['id']);
            $isExtended = $leave['total_days'] > EXTENDED_LEAVE_THRESHOLD;
            $hasDocument = !empty($leave['dean_ap_document']);
            ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-bold">Leave Application #<?php echo $leave['id']; ?></h3>
                            <p class="text-sm opacity-90 mt-1">
                                Submitted <?php echo timeAgo($leave['created_at']); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="px-3 py-1 bg-white bg-opacity-20 rounded-full text-sm font-semibold">
                                <?php echo $leave['total_days']; ?> Days
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <div class="p-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Student Info -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 mb-3 uppercase">Student Details</h4>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Name:</span>
                                    <span class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Roll Number:</span>
                                    <span class="font-medium text-gray-900"><?php echo sanitize($leave['roll_number']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Email:</span>
                                    <span class="font-medium text-gray-900 text-sm"><?php echo sanitize($leave['student_email']); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Leave Details -->
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 mb-3 uppercase">Leave Details</h4>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Type:</span>
                                    <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">From:</span>
                                    <span class="font-medium text-gray-900"><?php echo formatDate($leave['start_date']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">To:</span>
                                    <span class="font-medium text-gray-900"><?php echo formatDate($leave['end_date']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-600">Duration:</span>
                                    <span class="font-bold text-orange-600"><?php echo $leave['total_days']; ?> working days</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-2 uppercase">Reason</h4>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-gray-700"><?php echo nl2br(sanitize($leave['reason'])); ?></p>
                        </div>
                    </div>

                    <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                        <!-- Medical Certificate -->
                        <div class="mb-6">
                            <h4 class="text-sm font-semibold text-gray-700 mb-2 uppercase">Medical Certificate</h4>
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <i class="fas fa-file-pdf text-red-500 text-2xl mr-3"></i>
                                        <div>
                                            <p class="font-medium text-gray-900">Supporting Document</p>
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

                    <!-- Approval Chain -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3 uppercase">Approval Chain</h4>
                        <div class="space-y-3">
                            <?php foreach ($approvalChain as $approval): ?>
                                <div class="flex items-start p-3 <?php echo $approval['action'] === 'approved' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> rounded-lg">
                                    <div class="flex-shrink-0 mr-3">
                                        <?php if ($approval['action'] === 'approved'): ?>
                                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="font-semibold text-gray-900"><?php echo sanitize($approval['approver_name']); ?></span>
                                            <span class="text-xs text-gray-500"><?php echo timeAgo($approval['created_at']); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-600 mb-1"><?php echo ucfirst(str_replace('_', ' ', $approval['role'])); ?></p>
                                        <?php if (!empty($approval['remarks'])): ?>
                                            <p class="text-sm text-gray-700 mt-2 italic">"<?php echo sanitize($approval['remarks']); ?>"</p>
                                        <?php endif; ?>
                                        <?php if ($approval['role'] === 'hod' && !empty($approval['payment_status'])): ?>
                                            <div class="mt-2">
                                                <span class="text-xs text-gray-600">Payment Status: </span>
                                                <?php echo getPaymentStatusBadge($approval['payment_status']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Extended Leave Notice -->
                    <?php if ($isExtended): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-lg mr-3 mt-0.5"></i>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-yellow-800 mb-1">Extended Leave (>15 days)</p>
                                    <p class="text-sm text-yellow-700">
                                        This application requires Dean AP approval document before final approval.
                                        Payment Status: <?php echo getPaymentStatusBadge($leave['payment_status'] ?? 'unpaid'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Dean AP Document -->
                    <div class="mb-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-3 uppercase">Dean AP Approval Document</h4>
                        <?php if ($hasDocument): ?>
                            <div class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center">
                                    <i class="fas fa-file-pdf text-red-600 text-2xl mr-3"></i>
                                    <div>
                                        <p class="font-medium text-gray-900">Document Uploaded</p>
                                        <p class="text-sm text-gray-600"><?php echo sanitize($leave['dean_ap_document']); ?></p>
                                    </div>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/public/uploads/<?php echo sanitize($leave['dean_ap_document']); ?>"
                                   target="_blank"
                                   class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-download mr-2"></i>View
                                </a>
                            </div>
                        <?php else: ?>
                            <form method="POST" enctype="multipart/form-data" class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                                <?php echo getCSRFField(); ?>
                                <input type="hidden" name="action" value="upload_document">
                                <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">

                                <div class="flex items-center space-x-4">
                                    <div class="flex-1">
                                        <input type="file"
                                               name="dean_document"
                                               accept=".pdf"
                                               required
                                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                        <p class="text-xs text-gray-500 mt-1">Upload Dean AP approval document (PDF only, max 5MB)</p>
                                    </div>
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                        <i class="fas fa-upload mr-2"></i>Upload
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
                        <button onclick="rejectLeave(<?php echo $leave['id']; ?>)"
                                class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i class="fas fa-times mr-2"></i>Reject
                        </button>
                        <button onclick="approveLeave(<?php echo $leave['id']; ?>)"
                                class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                                <?php if (!$hasDocument): ?>disabled title="Upload Dean AP document first"<?php endif; ?>>
                            <i class="fas fa-check mr-2"></i>Final Approve
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900" id="modalTitle">Confirm Approval</h3>
        </div>
        <!-- noloading: submitted via fetch; keeps main.js from adding a btn-loading
             state that nothing removes when the API errors -->
        <form id="approvalForm" class="p-6 noloading">
            <?php echo getCSRFField(); ?>
            <input type="hidden" id="actionLeaveId" name="leave_id">
            <input type="hidden" id="actionType" name="action">
            <input type="hidden" name="role" value="admin">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Remarks *</label>
                <textarea id="actionRemarks"
                          name="remarks"
                          rows="4"
                          required
                          minlength="5"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                          placeholder="Enter your remarks (minimum 5 characters)..."></textarea>
            </div>

            <div class="flex items-center justify-end space-x-3">
                <button type="button"
                        onclick="closeApprovalModal()"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    Cancel
                </button>
                <button type="submit"
                        id="submitBtn"
                        class="px-6 py-2 rounded-lg text-white">
                    <i class="fas fa-check mr-2"></i>Confirm
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
    document.getElementById('submitBtn').className = 'px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700';
    document.getElementById('approvalModal').classList.remove('hidden');
}

function rejectLeave(leaveId) {
    document.getElementById('modalTitle').textContent = 'Reject Application';
    document.getElementById('actionLeaveId').value = leaveId;
    document.getElementById('actionType').value = 'reject';
    document.getElementById('actionRemarks').placeholder = 'Enter rejection reason...';
    document.getElementById('submitBtn').className = 'px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700';
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
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

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
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-yellow-500',
        info: 'bg-blue-500'
    };

    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>
            <span>${message}</span>
        </div>
    `;

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
