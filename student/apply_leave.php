<?php
/**
 * Apply for Leave
 */

// NOTE: POST handler must come BEFORE header.php include to avoid "headers already sent" error

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Must run before the POST handler below so only students can submit applications.
requireRole('student');

$studentId = null;
$error = '';
$hasGuide = false;
$hasTA = false;
$hasRequiredAssignments = false;

// Handle form submission BEFORE outputting HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkCSRF();

        // Get student ID and check if they have guide and TA
        $userId = getCurrentUserId();
        $userClass = new User();
        $studentDetails = $userClass->getStudentDetails($userId);
        $hasGuide = !empty($studentDetails['faculty_guide_id']);
        $hasTA = !empty($studentDetails['ta_coordinator_id']);
        $hasRequiredAssignments = $hasGuide && $hasTA;

        $leaveType = sanitize($_POST['leave_type'] ?? '');
        $startDate = sanitize($_POST['start_date'] ?? '');
        $endDate = sanitize($_POST['end_date'] ?? '');
        $reason = sanitize($_POST['reason'] ?? '');
        $supportingDocument = null;

        // Handle medical document upload
        if ($leaveType === 'medical') {
            if (!isset($_FILES['medical_document']) || $_FILES['medical_document']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'A supporting medical document (PDF) is required for medical leave applications';
            } elseif ($_FILES['medical_document']['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed. Please try again.';
            } else {
                $file = $_FILES['medical_document'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                // Validate file type
                if ($mimeType !== 'application/pdf') {
                    $error = 'Only PDF files are allowed for medical documents';
                }
                // Validate file size (5MB max)
                elseif ($file['size'] > 5 * 1024 * 1024) {
                    $error = 'File size must be less than 5MB';
                } else {
                    // Generate unique filename
                    $uploadDir = __DIR__ . '/../public/uploads/medical_documents/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $uniqueName = uniqid('medical_', true) . '_' . time() . '.pdf';
                    $uploadPath = $uploadDir . $uniqueName;

                    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                        $supportingDocument = 'uploads/medical_documents/' . $uniqueName;
                    } else {
                        $error = 'Failed to save the uploaded file. Please try again.';
                    }
                }
            }
        }

        // Validation
        if ($error) {
            // Error already set from file upload
        } elseif (!$hasRequiredAssignments) {
            if (!$hasGuide && !$hasTA) {
                $error = 'You cannot apply for leave without a Faculty Guide and TA Coordinator assigned. Please contact staff PhD Coordinator.';
            } elseif (!$hasGuide) {
                $error = 'You cannot apply for leave without a Faculty Guide assigned. Please contact staff PhD Coordinator.';
            } else {
                $error = 'You cannot apply for leave without a TA Coordinator assigned. Please contact staff PhD Coordinator.';
            }
        } elseif (empty($leaveType) || empty($startDate) || empty($endDate) || empty($reason)) {
            $error = 'All fields are required';
        } elseif (!in_array($leaveType, ['personal', 'medical', 'academic'])) {
            $error = 'Invalid leave type';
        } elseif (!isValidDate($startDate) || !isValidDate($endDate)) {
            $error = 'Invalid date format';
        } elseif (strtotime($endDate) < strtotime($startDate)) {
            $error = 'End date cannot be before start date';
        } elseif (strtotime($startDate) < strtotime('today')) {
            $error = 'Start date cannot be in the past';
        } elseif (strlen($reason) < 5) {
            $error = 'Reason must be at least 5 characters';
        } else {
            try {
                $leave = new Leave();
                $studentId = getCurrentStudentId();

                $result = $leave->apply($studentId, [
                    'leave_type' => $leaveType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $reason,
                    'supporting_document' => $supportingDocument
                ]);

                if ($result['success']) {
                    // Send email notification to the first approver
                    try {
                        $leaveDetails = $leave->getById($result['leave_id']);
                        if ($leaveDetails) {
                            $emailService = new EmailService();

                            // Determine who to notify based on the leave status
                            if ($leaveDetails['status'] === 'pending_guide' && !empty($leaveDetails['guide_email'])) {
                                $emailService->sendLeaveApplication($leaveDetails, 'guide');
                            } elseif ($leaveDetails['status'] === 'pending_ta' && !empty($leaveDetails['ta_coordinator_email'])) {
                                $emailService->sendLeaveApplication($leaveDetails, 'ta_coordinator');
                            } elseif ($leaveDetails['status'] === 'pending_hod') {
                                $emailService->sendLeaveApplication($leaveDetails, 'hod');
                            }

                            // If adjacent leave detected, notify admin
                            if (!empty($result['adjacent_leave_id'])) {
                                $adjacentLeave = $leave->getById($result['adjacent_leave_id']);
                                if ($adjacentLeave) {
                                    $emailService->sendAdjacentLeaveNotification($leaveDetails, $adjacentLeave);
                                }
                            }
                        }
                    } catch (Exception $emailEx) {
                        // Log email error but don't fail the application
                        logError("Failed to send leave notification email: " . $emailEx->getMessage());
                    }

                    $successMsg = "Leave application submitted successfully! ({$result['total_days']} days)";
                    if (isset($result['warning'])) {
                        $successMsg .= " Warning: " . $result['warning'];
                    }
                    if (!empty($result['adjacent_leave_id'])) {
                        $successMsg .= " Note: This leave is adjacent to an existing leave and will be reviewed by admin.";
                    }
                    setFlashMessage('success', $successMsg);
                    // Refresh current page after 1 second to show updated balances and clear form
                    header('Refresh: 1; URL=' . BASE_URL . '/student/apply_leave.php');
                    exit();
                }

            } catch (Exception $e) {
                logError("Leave application error: " . $e->getMessage());
                $error = $e->getMessage();
            }
        }
    } catch (Exception $e) {
        logError("Leave application error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Now include header.php which outputs HTML
$pageTitle = 'Apply for Leave';
require_once __DIR__ . '/../includes/header.php';

// Get student ID and details if not already fetched (when form wasn't submitted)
if (!$studentId) {
    $userId = getCurrentUserId();
    $studentId = getCurrentStudentId();
    $userClass = new User();
    $studentDetails = $userClass->getStudentDetails($userId);
    $hasGuide = !empty($studentDetails['faculty_guide_id']);
    $hasTA = !empty($studentDetails['ta_coordinator_id']);
    $hasRequiredAssignments = $hasGuide && $hasTA;
}

// Get current leave balances
try {
    $leave = new Leave();
    $balance = $leave->getBalance($studentId);
    $pendingPersonal = $leave->getPendingLeaveDays($studentId, 'personal');
    $pendingMedical = $leave->getPendingLeaveDays($studentId, 'medical');
    $pendingAcademic = $leave->getPendingLeaveDays($studentId, 'academic');
} catch (Exception $e) {
    logError("Balance fetch error: " . $e->getMessage());
}
?>

<div class="container mx-auto px-4 py-8 max-w-4xl">
    <!-- Header -->
    <div class="mb-8">
        <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="text-blue-600 hover:text-blue-700 mb-4 inline-block">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
        <h1 class="text-3xl font-bold text-gray-900">Apply for Leave</h1>
        <p class="text-gray-600 mt-2">Submit your leave application for approval</p>
    </div>

    <?php displayFlashMessage(); ?>

    <!-- Missing Guide/TA Alert -->
    <?php if (!$hasRequiredAssignments): ?>
        <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-red-600 text-lg mt-0.5"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Missing Required Assignments</h3>
                    <div class="mt-2 text-sm text-red-700">
                        <p>You cannot apply for leave until the following are assigned:</p>
                        <ul class="list-disc list-inside mt-2">
                            <?php if (!$hasGuide): ?>
                                <li>Faculty Guide</li>
                            <?php endif; ?>
                            <?php if (!$hasTA): ?>
                                <li>TA Coordinator</li>
                            <?php endif; ?>
                        </ul>
                        <p class="mt-2">Please contact Staff Phd Coordinator to get these assignments completed.</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Application Form -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-md p-6">
                <?php if ($error): ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <p class="text-sm text-red-800">
                            <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="leaveForm" class="space-y-6" enctype="multipart/form-data">
                    <?php echo getCSRFField(); ?>

                    <!-- Leave Type -->
                    <div>
                        <label for="leave_type" class="block text-sm font-medium text-gray-700 mb-2">
                            Leave Type <span class="text-red-500">*</span>
                        </label>
                        <select
                            id="leave_type"
                            name="leave_type"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            required
                            onchange="updateBalanceDisplay()"
                            <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                        >
                            <option value="">Select Leave Type</option>
                            <option value="personal">Personal Leave</option>
                            <option value="medical">Medical Leave</option>
                            <option value="academic">Academic/Official Leave</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                                Start Date <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                required
                                onchange="calculateDays()"
                                <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                            >
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                End Date <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                required
                                onchange="calculateDays()"
                                <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                            >
                        </div>
                    </div>

                    <!-- Total Days Display -->
                    <div id="workingDaysDisplay" class="hidden p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-900">
                            <i class="fas fa-calendar-check mr-2"></i>
                            Total Leave Days: <span id="workingDaysCount" class="font-bold">0</span>
                            <span class="text-xs text-blue-700 ml-2">(includes weekends & holidays)</span>
                        </p>
                    </div>

                    <!-- Reason -->
                    <div>
                        <label for="reason" class="block text-sm font-medium text-gray-700 mb-2">
                            Reason for Leave <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            id="reason"
                            name="reason"
                            rows="4"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Please provide a detailed reason for your leave..."
                            required
                            minlength="10"
                            <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                        ><?php echo sanitize($_POST['reason'] ?? ''); ?></textarea>
                        <p class="mt-1 text-sm text-gray-500">Minimum 10 characters required</p>
                    </div>

                    <!-- Medical Document Upload (shown only for medical leave) -->
                    <div id="medical_document_section" class="hidden">
                        <label for="medical_document" class="block text-sm font-medium text-gray-700 mb-2">
                            Supporting Medical Document <span class="text-red-500">*</span>
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-blue-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <i class="fas fa-file-pdf text-4xl text-gray-400 mb-3"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label for="medical_document" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                        <span>Upload a PDF file</span>
                                        <input
                                            id="medical_document"
                                            name="medical_document"
                                            type="file"
                                            accept=".pdf,application/pdf"
                                            class="sr-only"
                                            onchange="updateFileName(this)"
                                            <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                                        >
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">PDF only, up to 5MB</p>
                                <p id="selected_file_name" class="text-sm text-green-600 font-medium mt-2 hidden"></p>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-amber-600">
                            <i class="fas fa-info-circle mr-1"></i>
                            A medical certificate or supporting document is required for medical leave applications.
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center justify-between pt-4">
                        <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="text-gray-600 hover:text-gray-800">
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors <?php echo !$hasRequiredAssignments ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                            <?php echo !$hasRequiredAssignments ? 'disabled' : ''; ?>
                        >
                            <i class="fas fa-paper-plane mr-2"></i>Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Leave Balance Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md p-6 sidebar-sticky">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Leave Balance</h3>

                <!-- Personal Leave -->
                <div class="mb-4 p-3 bg-purple-50 rounded-lg" id="personal-balance">
                    <p class="text-sm font-medium text-gray-700 mb-1">Personal Leave</p>
                    <p class="text-2xl font-bold text-purple-600">
                        <?php echo number_format($balance['personal_leaves'] ?? 0, 1); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo $pendingPersonal > 0 ? $pendingPersonal . ' pending' : 'Available'; ?>
                    </p>
                </div>

                <!-- Medical Leave -->
                <div class="mb-4 p-3 bg-red-50 rounded-lg" id="medical-balance">
                    <p class="text-sm font-medium text-gray-700 mb-1">Medical Leave</p>
                    <p class="text-2xl font-bold text-red-600">
                        <?php echo number_format($balance['medical_leaves'] ?? 0, 1); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo $pendingMedical > 0 ? $pendingMedical . ' pending' : 'Available'; ?>
                    </p>
                </div>

                <!-- Academic Leave -->
                <div class="mb-4 p-3 bg-blue-50 rounded-lg" id="academic-balance">
                    <p class="text-sm font-medium text-gray-700 mb-1">Academic Leave</p>
                    <p class="text-2xl font-bold text-blue-600">
                        <?php echo number_format($balance['academic_leaves'] ?? 0, 1); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php echo $pendingAcademic > 0 ? $pendingAcademic . ' pending' : 'Available'; ?>
                    </p>
                </div>

                <div class="pt-4 border-t border-gray-200">
                    <p class="text-xs text-gray-600">
                        <i class="fas fa-info-circle mr-1"></i>
                        Negative balances are allowed but may require additional approval.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Balance data from PHP
const balanceData = {
    personal: <?php echo $balance['personal_leaves'] ?? 0; ?>,
    medical: <?php echo $balance['medical_leaves'] ?? 0; ?>,
    academic: <?php echo $balance['academic_leaves'] ?? 0; ?>
};

// Update balance display based on selected leave type
function updateBalanceDisplay() {
    const leaveType = document.getElementById('leave_type').value;
    const medicalDocSection = document.getElementById('medical_document_section');
    const medicalDocInput = document.getElementById('medical_document');

    // Reset all highlights
    document.getElementById('personal-balance').classList.remove('ring-2', 'ring-purple-500');
    document.getElementById('medical-balance').classList.remove('ring-2', 'ring-red-500');
    document.getElementById('academic-balance').classList.remove('ring-2', 'ring-blue-500');

    // Highlight selected type
    if (leaveType === 'personal') {
        document.getElementById('personal-balance').classList.add('ring-2', 'ring-purple-500');
    } else if (leaveType === 'medical') {
        document.getElementById('medical-balance').classList.add('ring-2', 'ring-red-500');
    } else if (leaveType === 'academic') {
        document.getElementById('academic-balance').classList.add('ring-2', 'ring-blue-500');
    }

    // Show/hide medical document upload section
    if (leaveType === 'medical') {
        medicalDocSection.classList.remove('hidden');
        medicalDocInput.setAttribute('required', 'required');
    } else {
        medicalDocSection.classList.add('hidden');
        medicalDocInput.removeAttribute('required');
        medicalDocInput.value = ''; // Clear any selected file
        document.getElementById('selected_file_name').classList.add('hidden');
    }
}

// Update file name display when a file is selected
function updateFileName(input) {
    const fileNameDisplay = document.getElementById('selected_file_name');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        // Validate file type
        if (file.type !== 'application/pdf') {
            alert('Please upload a PDF file only.');
            input.value = '';
            fileNameDisplay.classList.add('hidden');
            return;
        }
        // Validate file size (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB.');
            input.value = '';
            fileNameDisplay.classList.add('hidden');
            return;
        }
        fileNameDisplay.textContent = 'Selected: ' + file.name;
        fileNameDisplay.classList.remove('hidden');
    } else {
        fileNameDisplay.classList.add('hidden');
    }
}

// Calculate total leave days (including weekends and holidays)
function calculateDays() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;

    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);

        if (end >= start) {
            // Calculate total calendar days (inclusive of both dates)
            const diffTime = end - start;
            const days = Math.floor(diffTime / (1000 * 60 * 60 * 24)) + 1;

            document.getElementById('workingDaysCount').textContent = days;
            document.getElementById('workingDaysDisplay').classList.remove('hidden');
        }
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
