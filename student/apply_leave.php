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
require_once __DIR__ . '/../classes/GuideTAChange.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Must run before the POST handler below so only students can submit applications.
requireRole('student');

$studentId = null;
$error = '';
$hasGuide = false;
$hasTA = false;
$hasRequiredAssignments = false;

$guideTAChange = new GuideTAChange();
// A change already with the office. Any application made now queues behind that
// one decision rather than opening a second competing request.
$pendingRoutingRequest = $guideTAChange->pendingForStudent(getCurrentStudentId());

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

        // A medical application needs its certificate. Only its presence is
        // checked here; it is stored after the rest of the form has passed
        // (below). It used to be moved into place first, so every submission
        // refused afterwards — a past date, an overlap, a routing error — left an
        // orphaned certificate on disk with no application pointing at it.
        $medicalFile = null;
        if ($leaveType === 'medical') {
            if (!isset($_FILES['medical_document']) || $_FILES['medical_document']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'A supporting medical document (PDF) is required for medical leave applications';
            } elseif ($_FILES['medical_document']['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed. Please try again.';
            } else {
                $medicalFile = $_FILES['medical_document'];
            }
        }

        // One-time token issued with each rendering of the form (see below the
        // header include). A second POST of the same rendering — a double click,
        // reloading the result, back and submit again — is refused here before
        // anything is stored. Leave::apply() refuses overlapping dates as well,
        // so this is what turns a duplicate into a clear message.
        $submissionToken = (string) ($_POST['submission_token'] ?? '');
        $issuedTokens = $_SESSION['apply_leave_tokens'] ?? [];

        // Who will review this application is settled before anything else — that
        // is the point of the confirmation step. A student with a pending change
        // is not asked again; their application simply queues behind it.
        $routingAnswer = sanitize($_POST['routing_ok'] ?? '');
        $wantsRoutingChange = !$pendingRoutingRequest && $routingAnswer === 'no';
        $newGuideId = $_POST['new_faculty_guide_id'] ?? '';
        $newTaId = $_POST['new_ta_coordinator_id'] ?? '';
        $routingReason = sanitize($_POST['routing_reason'] ?? '');

        // Validation
        if ($submissionToken === '' || !in_array($submissionToken, $issuedTokens, true)) {
            $error = 'This form has already been submitted (or has expired). Check your Leave History; '
                   . 'if the application is not there, fill in the form again.';
        } elseif ($error) {
            // Error already set from file upload
        } elseif (!$pendingRoutingRequest && $routingAnswer === '') {
            $error = 'Please confirm whether your Faculty Guide and TA Coordinator are correct.';
        } elseif (!$pendingRoutingRequest && $routingAnswer === 'yes' && !$hasRequiredAssignments) {
            // "These are correct" cannot be true when there is nothing assigned.
            $missing = !$hasGuide && !$hasTA ? 'Faculty Guide or TA Coordinator'
                     : (!$hasGuide ? 'Faculty Guide' : 'TA Coordinator');
            $error = "You have no $missing assigned, so they cannot be correct. "
                   . 'Choose "No, these need to be changed" and tell the office who they should be.';
        } elseif ($wantsRoutingChange && empty($newGuideId) && empty($newTaId)) {
            $error = 'Please select the Faculty Guide and/or TA Coordinator you should be assigned to.';
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
            $committed = false;
            try {
                $leave = new Leave();
                $studentId = getCurrentStudentId();
                $pdo = getDBConnection();

                // Into the private directory, never under public/: nginx serves
                // any non-.php file there to anyone with the URL. Only the stored
                // name is kept; it is served by api/files/download.php.
                if ($medicalFile) {
                    $supportingDocument = uploadFile($medicalFile, ['application/pdf'], MAX_UPLOAD_SIZE, MEDICAL_DOCS_DIR);
                }

                // The change request and the application it was raised on are
                // written together. If the application fails validation inside
                // Leave::apply, an orphaned pending request would otherwise be
                // left behind — and uk_one_pending would then block the student
                // from ever raising another.
                //
                // The student row is locked FIRST, before any plain read in the
                // transaction: it is the per-student mutex Leave::apply() relies on,
                // and a snapshot taken before it would miss an application another
                // tab has just committed. A deadlock (the admin deciding this
                // student's guide/TA change at the same instant) is retried once.
                for ($attempt = 1; ; $attempt++) {
                    $pdo->beginTransaction();
                    try {
                        $leave->lockStudent($studentId);

                        $routingRequestId = $pendingRoutingRequest
                            ? (int) $pendingRoutingRequest['id']
                            : null;

                        if ($wantsRoutingChange) {
                            $routingRequestId = $guideTAChange->create(
                                $studentId,
                                $newGuideId !== '' ? (int) $newGuideId : null,
                                $newTaId !== '' ? (int) $newTaId : null,
                                $routingReason
                            );
                        }

                        $result = $leave->apply($studentId, [
                            'leave_type' => $leaveType,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'reason' => $reason,
                            'supporting_document' => $supportingDocument,
                            'routing_request_id' => $routingRequestId
                        ]);
                        $pdo->commit();
                        $committed = true;
                        break;
                    } catch (Exception $inner) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($attempt === 1 && Leave::isDeadlock($inner)) {
                            continue;
                        }
                        if ($inner instanceof PDOException) {
                            // Never show the student a raw database error.
                            logError("Leave application failed: " . $inner->getMessage());
                            throw new Exception('Failed to submit leave application. Please try again.');
                        }
                        throw $inner;
                    }
                }

                // This rendering of the form is used up.
                $_SESSION['apply_leave_tokens'] = array_values(array_diff($issuedTokens, [$submissionToken]));

                if ($result['success']) {
                    // Send email notification to the first approver
                    try {
                        $leaveDetails = $leave->getById($result['leave_id']);
                        if ($leaveDetails) {
                            $emailService = new EmailService();

                            // A held application has no approver yet — the admin
                            // is the one who needs to hear about it, and only when
                            // this request is new. Queuing behind an existing
                            // request must not re-notify.
                            if ($leaveDetails['status'] === 'pending_routing') {
                                if ($wantsRoutingChange) {
                                    $emailService->sendGuideTAChangeRequest(
                                        $guideTAChange->getById($routingRequestId));
                                }
                            } elseif ($leaveDetails['status'] === 'pending_guide' && !empty($leaveDetails['guide_email'])) {
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
                    if (!empty($result['held_for_routing'])) {
                        $successMsg = "Leave application submitted ({$result['total_days']} days). "
                            . 'It is on hold until the office decides on your Faculty Guide / TA '
                            . 'Coordinator change; you will be emailed either way, and the '
                            . 'application is then sent for approval automatically.';
                    }
                    if (isset($result['warning'])) {
                        $successMsg .= " Warning: " . $result['warning'];
                    }
                    if (!empty($result['adjacent_leave_id'])) {
                        $successMsg .= " Note: This leave is adjacent to an existing leave and will be reviewed by admin.";
                    }
                    setFlashMessage('success', $successMsg);
                    // Post/Redirect/Get: shows the updated balances and a clear
                    // form, and reloading it does not post the application again.
                    header('Location: ' . BASE_URL . '/student/apply_leave.php');
                    exit();
                }

            } catch (Exception $e) {
                // Nothing recorded, so nothing may keep the stored certificate.
                if (!$committed && $supportingDocument !== null) {
                    deletePrivateFile(MEDICAL_DOCS_DIR, $supportingDocument);
                    $supportingDocument = null;
                }
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
if (!$studentId || empty($studentDetails)) {
    $userId = getCurrentUserId();
    $studentId = getCurrentStudentId();
    $userClass = new User();
    $studentDetails = $userClass->getStudentDetails($userId);
    $hasGuide = !empty($studentDetails['faculty_guide_id']);
    $hasTA = !empty($studentDetails['ta_coordinator_id']);
    $hasRequiredAssignments = $hasGuide && $hasTA;
}

// Only real, active faculty are offerable. Anything wider is what let a student
// name themselves as their own guide before this went through the office.
$selectableFaculty = $guideTAChange->selectableFaculty();

// What the student last answered, so a validation error does not silently reset
// their choice back to "correct" and hide the change they were making.
$postedRoutingAnswer = sanitize($_POST['routing_ok'] ?? '');
$postedGuideId = $_POST['new_faculty_guide_id'] ?? '';
$postedTaId = $_POST['new_ta_coordinator_id'] ?? '';
$postedRoutingReason = sanitize($_POST['routing_reason'] ?? '');
$changeSectionOpen = $postedRoutingAnswer === 'no' || (!$hasRequiredAssignments && !$pendingRoutingRequest);

// One-time token for this rendering of the form (checked in the POST handler).
// The last few stay valid so a student with two tabs open is not refused.
$submissionToken = bin2hex(random_bytes(16));
$_SESSION['apply_leave_tokens'] = array_slice(
    array_merge($_SESSION['apply_leave_tokens'] ?? [], [$submissionToken]), -10);

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

<?php
// Presentation only: initials for the reviewer avatars, and the rows of the
// balance sidebar. Nothing here feeds the form or the POST handler above.
$personInitials = static function ($name) {
    $parts = preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
    $parts = array_values(array_filter($parts, fn($p) => $p !== '' && !preg_match('/^(prof|dr|mr|mrs|ms)\.?$/i', $p)));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
};

$reviewers = [
    ['role' => 'Faculty Guide', 'assigned' => $hasGuide, 'name' => $studentDetails['guide_name'] ?? ''],
    ['role' => 'TA Coordinator', 'assigned' => $hasTA, 'name' => $studentDetails['ta_coordinator_name'] ?? ''],
];

$balanceRows = [
    ['id' => 'personal-balance', 'label' => 'Personal Leave', 'icon' => 'fa-user', 'chip' => 'chip-violet', 'bar' => 'bg-violet-500',
     'value' => (float) ($balance['personal_leaves'] ?? 0), 'allocation' => PERSONAL_LEAVE_ALLOCATION, 'pending' => $pendingPersonal ?? 0],
    ['id' => 'medical-balance', 'label' => 'Medical Leave', 'icon' => 'fa-heartbeat', 'chip' => 'chip-rose', 'bar' => 'bg-rose-500',
     'value' => (float) ($balance['medical_leaves'] ?? 0), 'allocation' => MEDICAL_LEAVE_ALLOCATION, 'pending' => $pendingMedical ?? 0],
    ['id' => 'academic-balance', 'label' => 'Academic Leave', 'icon' => 'fa-graduation-cap', 'chip' => 'chip-sky', 'bar' => 'bg-sky-500',
     'value' => (float) ($balance['academic_leaves'] ?? 0), 'allocation' => ACADEMIC_LEAVE_ALLOCATION, 'pending' => $pendingAcademic ?? 0],
];
?>

<a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="back-link">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
</a>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Student</p>
        <h1 class="page-title">Apply for Leave</h1>
        <p class="page-subtitle">Submit your leave application for approval</p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="btn btn-secondary">
            <i class="fas fa-history"></i> Leave History
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php if ($pendingRoutingRequest): ?>
    <!-- A change is already with the office: do not offer to raise a second. -->
    <div class="alert alert-warning mb-6">
        <i class="fas fa-hourglass-half mt-0.5"></i>
        <div>
            <h3 class="font-semibold">Guide / TA change awaiting the office</h3>
            <p class="mt-1">
                You asked for your Faculty Guide / TA Coordinator to be changed on
                <?php echo formatDate($pendingRoutingRequest['created_at']); ?> and the office has
                not decided yet. Anything you apply for now is held until they do, then sent for
                approval automatically. You will be emailed the decision.
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <!-- Application Form -->
    <div class="card lg:col-span-2">
        <form method="POST" action="" id="leaveForm" enctype="multipart/form-data">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="submission_token" value="<?php echo sanitize($submissionToken); ?>">

            <div class="card-body space-y-8">
                <?php if ($error): ?>
                    <div class="alert alert-error" role="alert">
                        <i class="fas fa-exclamation-circle mt-0.5"></i>
                        <p class="flex-1"><?php echo sanitize($error); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!$pendingRoutingRequest): ?>
                <!--
                    Who reviews this application, confirmed before it is made.
                    These two people are the whole approval chain, and a student
                    assigned to the wrong ones has no other way to say so — the
                    fields are read-only on the profile page precisely so that a
                    student cannot re-route their own approvals unsupervised.
                -->
                <section class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="icon-chip-sm chip-brand"><i class="fas fa-user-check"></i></span>
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">Who will review this application?</h2>
                            <p class="text-xs text-gray-500">Confirm your approvers before you apply.</p>
                        </div>
                    </div>

                    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <?php foreach ($reviewers as $reviewer): ?>
                            <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3.5 py-3">
                                <?php if ($reviewer['assigned']): ?>
                                    <span class="avatar h-9 w-9"><?php echo sanitize($personInitials($reviewer['name'])); ?></span>
                                <?php else: ?>
                                    <span class="icon-chip-sm chip-rose rounded-full"><i class="fas fa-user-slash"></i></span>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium <?php echo $reviewer['assigned'] ? 'text-gray-900' : 'text-rose-600'; ?>">
                                        <?php echo $reviewer['assigned'] ? sanitize($reviewer['name']) : 'Not assigned'; ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?php echo $reviewer['role']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 bg-white p-3.5 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60 has-[:checked]:ring-1 has-[:checked]:ring-brand-500 <?php echo $hasRequiredAssignments ? 'cursor-pointer hover:border-gray-300' : 'opacity-50 cursor-not-allowed'; ?>">
                            <input type="radio" name="routing_ok" value="yes" class="mt-0.5 h-4 w-4 shrink-0"
                                   onchange="toggleRoutingChange()"
                                   <?php echo $hasRequiredAssignments ? '' : 'disabled'; ?>
                                   <?php echo $postedRoutingAnswer === 'yes' ? 'checked' : ''; ?>
                                   required>
                            <span class="text-sm">
                                <span class="block font-medium text-gray-900">Yes, these are correct</span>
                                <span class="mt-0.5 block text-xs text-gray-500">Send my application to them.</span>
                                <?php if (!$hasRequiredAssignments): ?>
                                    <span class="mt-1 block text-xs text-rose-600">
                                        Not available: you have no
                                        <?php echo !$hasGuide && !$hasTA ? 'Faculty Guide or TA Coordinator'
                                            : (!$hasGuide ? 'Faculty Guide' : 'TA Coordinator'); ?> assigned.
                                    </span>
                                <?php endif; ?>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-white p-3.5 transition hover:border-gray-300 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/60 has-[:checked]:ring-1 has-[:checked]:ring-amber-500">
                            <input type="radio" name="routing_ok" value="no" class="mt-0.5 h-4 w-4 shrink-0"
                                   onchange="toggleRoutingChange()"
                                   <?php echo $changeSectionOpen ? 'checked' : ''; ?>
                                   required>
                            <span class="text-sm">
                                <span class="block font-medium text-gray-900">No, these need to be changed</span>
                                <span class="mt-0.5 block text-xs text-gray-500">
                                    The office approves the change first, then your application is
                                    sent for approval automatically.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div id="routingChangeSection" class="<?php echo $changeSectionOpen ? '' : 'hidden'; ?> mt-4 space-y-4 border-t border-gray-200 pt-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="new_faculty_guide_id" class="form-label">
                                    Faculty Guide should be
                                </label>
                                <select id="new_faculty_guide_id" name="new_faculty_guide_id" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php foreach ($selectableFaculty as $f): ?>
                                        <option value="<?php echo (int) $f['id']; ?>"
                                            <?php echo (string) $postedGuideId === (string) $f['id']
                                                || ($postedGuideId === '' && ($studentDetails['faculty_guide_id'] ?? '') == $f['id'])
                                                ? 'selected' : ''; ?>>
                                            <?php echo sanitize($f['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="new_ta_coordinator_id" class="form-label">
                                    TA Coordinator should be
                                </label>
                                <select id="new_ta_coordinator_id" name="new_ta_coordinator_id" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php foreach ($selectableFaculty as $f): ?>
                                        <option value="<?php echo (int) $f['id']; ?>"
                                            <?php echo (string) $postedTaId === (string) $f['id']
                                                || ($postedTaId === '' && ($studentDetails['ta_coordinator_id'] ?? '') == $f['id'])
                                                ? 'selected' : ''; ?>>
                                            <?php echo sanitize($f['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="routing_reason" class="form-label">
                                Why does this need changing? <span class="text-rose-500">*</span>
                            </label>
                            <textarea id="routing_reason" name="routing_reason" rows="2"
                                      minlength="10"
                                      class="form-textarea"
                                      placeholder="e.g. I changed supervisor in June; Prof. X is now my guide."><?php echo $postedRoutingReason; ?></textarea>
                            <p class="form-help">The office sees this when deciding.</p>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

                <section class="space-y-6">
                    <div class="flex items-start gap-3">
                        <span class="icon-chip-sm chip-violet"><i class="fas fa-calendar-alt"></i></span>
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">Leave details</h2>
                            <p class="text-xs text-gray-500">All fields marked <span class="text-rose-500">*</span> are required.</p>
                        </div>
                    </div>

                    <!-- Leave Type -->
                    <div>
                        <label for="leave_type" class="form-label">
                            Leave Type <span class="text-rose-500">*</span>
                        </label>
                        <select
                            id="leave_type"
                            name="leave_type"
                            class="form-select"
                            required
                            onchange="updateBalanceDisplay()"
                        >
                            <option value="">Select Leave Type</option>
                            <option value="personal">Personal Leave</option>
                            <option value="medical">Medical Leave</option>
                            <option value="academic">Academic/Official Leave</option>
                        </select>
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="start_date" class="form-label">
                                Start Date <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="start_date"
                                name="start_date"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="form-input"
                                required
                                onchange="calculateDays()"
                                >
                        </div>
                        <div>
                            <label for="end_date" class="form-label">
                                End Date <span class="text-rose-500">*</span>
                            </label>
                            <input
                                type="date"
                                id="end_date"
                                name="end_date"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="form-input"
                                required
                                onchange="calculateDays()"
                                >
                        </div>
                    </div>

                    <!-- Total Days Display -->
                    <div id="workingDaysDisplay" class="hidden">
                        <div class="flex items-center gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3">
                            <span class="icon-chip-sm chip-brand bg-white"><i class="fas fa-calendar-check"></i></span>
                            <p class="text-sm text-brand-900">
                                Total leave days: <span id="workingDaysCount" class="text-base font-semibold">0</span>
                                <span class="block text-xs text-brand-700 sm:ml-1 sm:inline">(includes weekends &amp; holidays)</span>
                            </p>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div>
                        <label for="reason" class="form-label">
                            Reason for Leave <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            id="reason"
                            name="reason"
                            rows="4"
                            class="form-textarea"
                            placeholder="Please provide a detailed reason for your leave..."
                            required
                            minlength="10"
                        ><?php echo sanitize($_POST['reason'] ?? ''); ?></textarea>
                        <p class="form-help">Minimum 10 characters required</p>
                    </div>

                    <!-- Medical Document Upload (shown only for medical leave) -->
                    <div id="medical_document_section" class="hidden">
                        <p class="form-label">
                            Supporting Medical Document <span class="text-rose-500">*</span>
                        </p>
                        <!-- The file input covers the whole zone (transparent), so a click
                             anywhere opens the picker and a file dropped on it lands in it. -->
                        <label for="medical_document" class="relative flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-gray-50/60 px-6 py-8 text-center transition hover:border-brand-400 hover:bg-brand-50/40 focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15">
                            <span class="icon-chip chip-rose mb-3"><i class="fas fa-file-pdf"></i></span>
                            <span class="text-sm text-gray-600">
                                <span class="font-semibold text-brand-600">Upload a PDF file</span> or drag and drop
                            </span>
                            <span class="mt-1 text-xs text-gray-500">PDF only, up to 5MB</span>
                            <span id="selected_file_name" class="mt-3 hidden rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200"></span>
                            <input
                                id="medical_document"
                                name="medical_document"
                                type="file"
                                accept=".pdf,application/pdf"
                                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                onchange="updateFileName(this)"
                                            >
                        </label>
                        <p class="mt-2 flex items-start gap-2 text-sm text-amber-700">
                            <i class="fas fa-info-circle mt-0.5"></i>
                            A medical certificate or supporting document is required for medical leave applications.
                        </p>
                    </div>
                </section>
            </div>

            <!-- Submit Button -->
            <div class="card-footer flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="btn btn-ghost">
                    Cancel
                </a>
                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >
                    <i class="fas fa-paper-plane"></i>Submit Application
                </button>
            </div>
        </form>
    </div>

    <!-- Leave Balance Sidebar -->
    <aside class="lg:col-span-1 lg:sticky lg:top-24">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Leave Balance</h3>
                    <p class="card-subtitle">Days available to you now</p>
                </div>
            </div>

            <div class="space-y-2 p-3 sm:p-4">
                <?php foreach ($balanceRows as $row): ?>
                    <?php $pct = $row['allocation'] > 0 ? max(0, min(100, $row['value'] / $row['allocation'] * 100)) : 0; ?>
                    <div class="rounded-xl p-3 transition" id="<?php echo $row['id']; ?>">
                        <div class="flex items-center gap-3">
                            <span class="icon-chip-sm <?php echo $row['chip']; ?>"><i class="fas <?php echo $row['icon']; ?>"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-700"><?php echo $row['label']; ?></p>
                                <p class="text-xs <?php echo $row['pending'] > 0 ? 'text-amber-700' : 'text-gray-500'; ?>">
                                    <?php echo $row['pending'] > 0 ? $row['pending'] . ' pending' : 'Available'; ?>
                                </p>
                            </div>
                            <p class="text-right">
                                <span class="text-xl font-semibold tracking-tight <?php echo $row['value'] < 0 ? 'text-rose-600' : 'text-gray-900'; ?>"><?php echo number_format($row['value'], 1); ?></span>
                                <span class="text-xs text-gray-400">/ <?php echo $row['allocation']; ?></span>
                            </p>
                        </div>
                        <div class="meter mt-2.5"><span class="<?php echo $row['bar']; ?>" style="width: <?php echo round($pct, 1); ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card-footer">
                <p class="flex items-start gap-2 text-xs text-gray-500">
                    <i class="fas fa-info-circle mt-0.5"></i>
                    Negative balances are allowed but may require additional approval.
                </p>
            </div>
        </div>
    </aside>
</div>

<script>
// Balance data from PHP
const balanceData = {
    personal: <?php echo $balance['personal_leaves'] ?? 0; ?>,
    medical: <?php echo $balance['medical_leaves'] ?? 0; ?>,
    academic: <?php echo $balance['academic_leaves'] ?? 0; ?>
};

/**
 * Show the guide/TA change fields only when the student says the routing is
 * wrong. The reason is required in that case and must NOT be required otherwise,
 * or the browser blocks submission on a hidden field with no visible explanation.
 */
function toggleRoutingChange() {
    const section = document.getElementById('routingChangeSection');
    if (!section) { return; }   // student already has a change with the office
    const wantsChange = document.querySelector('input[name="routing_ok"][value="no"]').checked;
    const reason = document.getElementById('routing_reason');
    section.classList.toggle('hidden', !wantsChange);
    if (wantsChange) {
        reason.setAttribute('required', 'required');
    } else {
        reason.removeAttribute('required');
    }
}

// Update balance display based on selected leave type
function updateBalanceDisplay() {
    const leaveType = document.getElementById('leave_type').value;
    const medicalDocSection = document.getElementById('medical_document_section');
    const medicalDocInput = document.getElementById('medical_document');

    // Reset all highlights
    document.getElementById('personal-balance').classList.remove('ring-1', 'ring-violet-300', 'bg-violet-50');
    document.getElementById('medical-balance').classList.remove('ring-1', 'ring-rose-300', 'bg-rose-50');
    document.getElementById('academic-balance').classList.remove('ring-1', 'ring-sky-300', 'bg-sky-50');

    // Highlight selected type
    if (leaveType === 'personal') {
        document.getElementById('personal-balance').classList.add('ring-1', 'ring-violet-300', 'bg-violet-50');
    } else if (leaveType === 'medical') {
        document.getElementById('medical-balance').classList.add('ring-1', 'ring-rose-300', 'bg-rose-50');
    } else if (leaveType === 'academic') {
        document.getElementById('academic-balance').classList.add('ring-1', 'ring-sky-300', 'bg-sky-50');
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

// Reflect the server-rendered state on load: a validation error re-renders the
// page with the change section open, and its reason field must be required again.
document.addEventListener('DOMContentLoaded', toggleRoutingChange);

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
