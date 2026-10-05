<?php
/**
 * Admin - User Management
 * Complete user management with invitation system and delete functionality
 */

// NOTE: POST handlers must come BEFORE header.php include to avoid "headers already sent" error
// This is because header.php outputs HTML, and header() calls must happen before any output

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../includes/EmailService.php';

// Must run before the POST handlers below — with the check only after header.php,
// any authenticated user could drive these admin mutations.
requireRole('admin');

$userClass = new User();
$emailService = new EmailService();
$adminId = (int) getCurrentUserId();

// Role filter from the query string. Set here, before the POST handlers, which
// use it to send the admin back to the same filtered list (the forms post to
// the current URL, so ?role= survives). It used to be assigned only after
// header.php, so the delete handler read an undefined variable.
$roleFilter = $_GET['role'] ?? '';
if (!in_array($roleFilter, ['student', 'faculty', 'hod', 'admin'], true)) {
    $roleFilter = '';
}
$listUrl = BASE_URL . '/admin/users.php' . ($roleFilter !== '' ? '?role=' . urlencode($roleFilter) : '');

/**
 * A stored user name as a safe HTML attribute value.
 *
 * Names are stored HTML-escaped (sanitize() on input), so a name typed as
 * O'Brien is stored as O&#039;Brien. The row buttons used to put it into
 * onclick="fn('...')" through addslashes(), which does nothing to &#039; — and
 * the browser decodes the entity back to ' before running the handler, so a
 * profile name could close the JS string and run script in the admin's session.
 * Now the name goes into a data-* attribute (decoded once, re-escaped once) and
 * the onclick code is a constant that reads it from this.dataset.
 */
function userAttr($value) {
    return htmlspecialchars(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
}

/** A stored (HTML-escaped) name as plain text, for flash messages, which escape on display. */
function plainName($value) {
    return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Handle user invitation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invite') {
    try {
        checkCSRF();

        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? '');

        // Validation
        if (empty($name) || empty($email) || empty($role)) {
            throw new Exception("All fields are required");
        }

        if (!validateEmail($email)) {
            throw new Exception("Invalid email format");
        }

        if (!validateDomain($email)) {
            throw new Exception("Email must be from " . ALLOWED_DOMAIN . " domain");
        }

        if (!in_array($role, ['student', 'faculty', 'hod'])) {
            throw new Exception("Invalid role selected");
        }

        // Prepare user data
        $data = [
            'name' => $name,
            'email' => $email,
            'role' => $role
        ];

        // Add student-specific fields
        if ($role === 'student') {
            $data['roll_number'] = sanitize($_POST['roll_number'] ?? '');
            $data['joining_date'] = sanitize($_POST['joining_date'] ?? '');
            // Convert 0 to null for optional faculty guide and TA coordinator
            $facultyGuideId = (int)($_POST['faculty_guide_id'] ?? 0);
            $taCoordinatorId = (int)($_POST['ta_coordinator_id'] ?? 0);
            $data['faculty_guide_id'] = ($facultyGuideId > 0) ? $facultyGuideId : null;
            $data['ta_coordinator_id'] = ($taCoordinatorId > 0) ? $taCoordinatorId : null;

            if (empty($data['roll_number']) || empty($data['joining_date'])) {
                throw new Exception("Roll number and joining date are required for students");
            }

            // Check if joining date is more than 1 month in the past - custom leave balances required
            $joiningTimestamp = strtotime($data['joining_date']);
            $oneMonthAgo = strtotime('-1 month');

            if ($joiningTimestamp <= $oneMonthAgo) {
                // Custom leave balances provided
                $personalBalance = isset($_POST['personal_leave_balance']) ? (float)$_POST['personal_leave_balance'] : null;
                $medicalBalance = isset($_POST['medical_leave_balance']) ? (float)$_POST['medical_leave_balance'] : null;
                $academicBalance = isset($_POST['academic_leave_balance']) ? (float)$_POST['academic_leave_balance'] : null;

                // Validate balances
                if ($personalBalance === null || $medicalBalance === null || $academicBalance === null) {
                    throw new Exception("Leave balances are required for backdated joining dates");
                }

                if ($personalBalance < 0 || $personalBalance > 200) {
                    throw new Exception("Personal leave balance must be between 0 and 200");
                }
                if ($medicalBalance < 0 || $medicalBalance > 150) {
                    throw new Exception("Medical leave balance must be between 0 and 150");
                }
                if ($academicBalance < 0 || $academicBalance > ACADEMIC_LEAVE_ALLOCATION) {
                    throw new Exception("Academic leave balance must be between 0 and " . ACADEMIC_LEAVE_ALLOCATION);
                }

                $data['custom_leave_balances'] = [
                    'personal' => $personalBalance,
                    'medical' => $medicalBalance,
                    'academic' => $academicBalance
                ];
            }
        }

        // Create user
        $result = $userClass->create($data, $adminId);

        if ($result['success']) {
            // Send invitation email. sendInvitation() reports failure by
            // returning false (after its retries), not by throwing, so the
            // return value has to be checked: it used to be ignored and a
            // failed send was reported as "invitation sent".
            $resendHint = " The account was created. Use the resend-invitation button (paper plane) on their row to try again.";
            try {
                if ($emailService->sendInvitation($email, $name, $result['token'])) {
                    setFlashMessage('success', "User created and invitation sent to " . plainName($email));
                } else {
                    setFlashMessage('warning', "The invitation email to " . plainName($email) . " could not be sent." . $resendHint);
                }
            } catch (Exception $e) {
                setFlashMessage('warning', "The invitation email to " . plainName($email) . " failed: " . $e->getMessage() . "." . $resendHint);
            }
        } else {
            throw new Exception("Failed to create user");
        }

        // Refresh page after 1 second to show updated list with flash message
        header('Refresh: 1; URL=' . BASE_URL . '/admin/users.php');
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        // Refresh page after 1 second to show error message
        header('Refresh: 1; URL=' . BASE_URL . '/admin/users.php');
        exit();
    }
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        checkCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            throw new Exception("Invalid user ID");
        }

        // Get user details before deletion
        $user = $userClass->getById($userId);

        if (!$user) {
            throw new Exception("User not found");
        }

        // Prevent deleting admin users
        if ($user['role'] === 'admin') {
            throw new Exception("Cannot delete admin users");
        }

        // Prevent self-deletion
        if ($userId === $adminId) {
            throw new Exception("You cannot delete your own account");
        }

        // An active user is only deactivated (soft delete). An already-inactive
        // user is permanently deleted, but only if the account has no leave,
        // attendance or approval history: User::hardDelete() refuses otherwise
        // (a cascading delete used to erase all of it, bypassing the
        // append-only attendance triggers) and logs the deletion.
        if ($user['is_active'] == 0) {
            $result = $userClass->hardDelete($userId, $adminId);
            $deleteType = 'permanently deleted';
        } else {
            $result = $userClass->delete($userId, $adminId);
            $deleteType = 'deactivated';
        }

        // Deactivating a faculty member moves none of their work: students
        // still name them as guide/TA, and leaves and sign-offs waiting on them
        // would stall unseen. Not blocked, but the admin is told what to reassign.
        $workload = ($result && $deleteType === 'deactivated' && $user['role'] === 'faculty')
            ? $userClass->facultyWorkload($userId) : null;
        $stranded = [];
        if ($workload) {
            if ($workload['students'] > 0) {
                $stranded[] = $workload['students'] . " active student(s) still assigned to them as guide/TA coordinator";
            }
            if ($workload['leaves'] > 0) {
                $stranded[] = $workload['leaves'] . " leave application(s) waiting at their guide/TA stage";
            }
            if ($workload['signoffs'] > 0) {
                $stranded[] = $workload['signoffs'] . " monthly attendance sign-off(s) waiting on them";
            }
        }

        if ($result && !empty($stranded)) {
            setFlashMessage('warning', "User '" . plainName($user['name']) . "' has been deactivated, but there are "
                . implode(', ', $stranded) . ". Reassign those students' Faculty Guide / TA Coordinator "
                . "(Edit Faculty Guide and TA on each student) so nothing is left waiting on an inactive account.");
        } elseif ($result) {
            setFlashMessage('success', "User '" . plainName($user['name']) . "' has been $deleteType successfully");
        } else {
            throw new Exception("Failed to delete user");
        }

        // Refresh page after 1 second to show updated list with flash message
        header('Refresh: 1; URL=' . $listUrl);
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        // Refresh page after 1 second to show error message
        header('Refresh: 1; URL=' . $listUrl);
        exit();
    }
}

// Resend the invitation to an account that has not completed sign-up (the
// first email failed, or the link expired). A fresh token replaces the old one
// only once the email has actually been sent.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_invite') {
    try {
        checkCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? $userClass->getById($userId) : null;
        if (!$user) {
            throw new Exception("User not found");
        }
        if (!empty($user['password'])) {
            throw new Exception("'" . plainName($user['name']) . "' has already completed sign-up; they can use 'Forgot password' on the login page");
        }
        if (!$user['is_active']) {
            throw new Exception("Reactivate the account before resending the invitation");
        }

        // The new token replaces the old one only if the email went out; on a
        // failed send the link the user may already have keeps working.
        $sent = $userClass->resendInvitation($userId, function ($token) use ($emailService, $user) {
            return (bool) $emailService->sendInvitation($user['email'], $user['name'], $token);
        });

        logSystemAction($adminId, $sent ? 'invitation_resent' : 'invitation_resend_failed', 'users', sprintf(
            "Invitation to '%s' <%s> (%s, user #%d) %s",
            $user['name'], $user['email'], $user['role'], $userId, $sent ? 'resent' : 'could not be sent'
        ));

        if ($sent) {
            setFlashMessage('success', "Invitation resent to " . plainName($user['email']));
        } else {
            setFlashMessage('warning', "The invitation email to " . plainName($user['email']) . " could not be sent; their previous invitation link (if still valid) keeps working. Check the mail settings and try again.");
        }
        header('Location: ' . $listUrl);
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        header('Location: ' . $listUrl);
        exit();
    }
}

// Handle toggle student activation (deactivate for passing out/dropping out, or reactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_activation') {
    try {
        checkCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = sanitize($_POST['deactivation_reason'] ?? '');

        if ($userId <= 0) {
            throw new Exception("Invalid user ID");
        }

        // Get user details
        $user = $userClass->getById($userId);

        if (!$user) {
            throw new Exception("User not found");
        }

        // Only allow toggling students
        if ($user['role'] !== 'student') {
            throw new Exception("This action is only available for students");
        }

        // Prevent self-deactivation
        if ($userId === $adminId) {
            throw new Exception("You cannot deactivate your own account");
        }

        $db = getDBConnection();

        // users.is_active is what login and the per-request session check read,
        // so flipping it here also ends the student's existing sessions.
        if ($user['is_active']) {
            // Deactivate student
            if (empty($reason)) {
                throw new Exception("Please provide a reason for deactivation");
            }

            $sql = "UPDATE users SET is_active = 0 WHERE id = :id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([':id' => $userId]);

            if ($result) {
                // Through logSystemAction(), like every other audit entry, so
                // the entry gets the same actor handling (impersonation note).
                logSystemAction($adminId, 'student_deactivated', 'users',
                    "Student '{$user['name']}' <{$user['email']}> (user #$userId) deactivated. Reason: $reason");

                setFlashMessage('success', "Student '" . plainName($user['name']) . "' has been deactivated. They will no longer receive leave balance updates.");
            } else {
                throw new Exception("Failed to deactivate student");
            }
        } else {
            // Reactivate student
            $sql = "UPDATE users SET is_active = 1 WHERE id = :id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([':id' => $userId]);

            if ($result) {
                logSystemAction($adminId, 'student_reactivated', 'users',
                    "Student '{$user['name']}' <{$user['email']}> (user #$userId) reactivated");

                setFlashMessage('success', "Student '" . plainName($user['name']) . "' has been reactivated.");
            } else {
                throw new Exception("Failed to reactivate student");
            }
        }

        header('Location: ' . $listUrl);
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        header('Location: ' . $listUrl);
        exit();
    }
}

// Handle updating student's faculty guide and TA coordinator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_guide_ta') {
    try {
        checkCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);
        $facultyGuideId = (int)($_POST['faculty_guide_id'] ?? 0);
        $taCoordinatorId = (int)($_POST['ta_coordinator_id'] ?? 0);

        if ($userId <= 0) {
            throw new Exception("Invalid user ID");
        }

        // Get user details to verify it's a student
        $user = $userClass->getById($userId);
        if (!$user) {
            throw new Exception("User not found");
        }

        if ($user['role'] !== 'student') {
            throw new Exception("Only students can have faculty guides and TA coordinators");
        }

        // Update the guide and TA (logged against this admin; also moves the
        // student's pending attendance sign-offs to the new approvers)
        $result = $userClass->updateStudentGuideAndTA($userId, $facultyGuideId, $taCoordinatorId, $adminId);

        if ($result) {
            $message = "Student's faculty guide and TA coordinator updated successfully.";
            $routing = $userClass->getLeaveRoutingResult();
            if (!empty($routing['moved'])) {
                $message .= ' ' . count($routing['moved']) . ' leave application(s) waiting at the TA stage '
                          . 'were forwarded to the HOD.';
            }
            if (!empty($routing['returned'])) {
                $message .= ' ' . count($routing['returned']) . ' application(s) no faculty member had approved yet '
                          . 'were sent back to the Faculty Guide.';
            }
            if (!empty($routing['stranded'])) {
                // Not auto-approved: a guide's decision is not the office's to make.
                setFlashMessage('warning', $message . ' ' . count($routing['stranded']) . ' application(s) are '
                    . 'waiting for a Faculty Guide and this student now has none — assign one or cancel them.');
            } else {
                setFlashMessage('success', $message);
            }
        } else {
            throw new Exception("Failed to update student guide and TA");
        }

        // Refresh page after 1 second to show updated information
        header('Refresh: 1; URL=' . BASE_URL . '/admin/users.php');
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        // Refresh page after 1 second to show error message
        header('Refresh: 1; URL=' . BASE_URL . '/admin/users.php');
        exit();
    }
}

// Handle leave balance adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_balance') {
    try {
        checkCSRF();

        $userId = (int)($_POST['user_id'] ?? 0);
        $leaveType = sanitize($_POST['leave_type'] ?? '');
        $adjustmentAmount = (float)($_POST['adjustment_amount'] ?? 0);
        $remarks = sanitize($_POST['remarks'] ?? '');

        if ($userId <= 0) {
            throw new Exception("Invalid user ID");
        }

        if (!in_array($leaveType, ['personal', 'medical', 'academic'])) {
            throw new Exception("Invalid leave type");
        }

        if ($adjustmentAmount == 0) {
            throw new Exception("Adjustment amount cannot be zero");
        }

        if (abs($adjustmentAmount) > 200) {
            throw new Exception("Adjustment amount is out of range");
        }

        if (empty($remarks)) {
            throw new Exception("Remarks are required for balance adjustments");
        }

        $user = $userClass->getById($userId);
        if (!$user) {
            throw new Exception("User not found");
        }
        if ($user['role'] !== 'student') {
            throw new Exception("Balance adjustments can only be made for students");
        }

        $db = getDBConnection();
        $studentStmt = $db->prepare("SELECT id FROM students WHERE user_id = :user_id");
        $studentStmt->execute([':user_id' => $userId]);
        $student = $studentStmt->fetch();

        if (!$student) {
            throw new Exception("Student record not found");
        }

        $leaveClass = new Leave();
        $leaveClass->adjustBalance($student['id'], $leaveType, $adjustmentAmount, $remarks, $adminId);

        $direction = $adjustmentAmount > 0 ? 'increased' : 'decreased';
        logSystemAction(
            $adminId,
            'leave_balance_adjusted',
            'users',
            "Admin {$direction} {$leaveType} leave balance for '{$user['name']}' (ID: {$userId}) by {$adjustmentAmount} days. Remarks: {$remarks}"
        );

        $sign = $adjustmentAmount > 0 ? '+' : '';
        setFlashMessage('success', ucfirst($leaveType) . " leave balance for '{$user['name']}' adjusted by {$sign}{$adjustmentAmount} days.");
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        header('Location: ' . BASE_URL . '/admin/users.php');
        exit();
    }
}

// Now include header.php which outputs HTML and sets up the page layout
$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';

// Fetch users based on filter ($roleFilter is validated at the top of the file)
if ($roleFilter !== '') {
    $users = $userClass->getAllByRole($roleFilter, false);
} else {
    // Get all users except admin
    $users = array_merge(
        $userClass->getAllByRole('student', false),
        $userClass->getAllByRole('faculty', false),
        $userClass->getAllByRole('hod', false)
    );
}

// Get faculty for dropdowns
$allFaculty = $userClass->getAllByRole('faculty', true);

// Calculate statistics
$studentCount = count($userClass->getAllByRole('student', false));
$facultyCount = count($userClass->getAllByRole('faculty', false));
$hodCount = count($userClass->getAllByRole('hod', false));
$totalUsers = $studentCount + $facultyCount + $hodCount;
$activeCount = count(array_filter($users, fn($u) => $u['is_active']));
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle">Manage system users and send invitations</p>
    </div>
    <div class="flex gap-2">
        <button onclick="showInviteModal()" class="btn btn-primary w-full sm:w-auto">
            <i class="fas fa-user-plus"></i>Invite User
        </button>
    </div>
</div>

<!-- Statistics -->
<div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-brand"><i class="fas fa-user-graduate"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Students</p>
            <p class="stat-value"><?php echo $studentCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-violet"><i class="fas fa-chalkboard-teacher"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Faculty</p>
            <p class="stat-value"><?php echo $facultyCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-emerald"><i class="fas fa-user-tie"></i></span>
        <div class="min-w-0">
            <p class="stat-label">HOD</p>
            <p class="stat-value"><?php echo $hodCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-amber"><i class="fas fa-users"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Total</p>
            <p class="stat-value"><?php echo $totalUsers; ?></p>
        </div>
    </div>
</div>

<?php
$segBase = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-sm font-medium transition sm:gap-2 sm:px-3';
$segOn   = $segBase . ' bg-white text-gray-900 shadow-sm';
$segOff  = $segBase . ' text-gray-600 hover:text-gray-900';
$segCount = 'hidden rounded-md bg-gray-200/80 px-1.5 text-xs font-semibold tabular-nums text-gray-700 sm:inline';
$listTitle = [
    ''        => 'All users',
    'student' => 'Students',
    'faculty' => 'Faculty',
    'hod'     => 'Heads of department',
    'admin'   => 'Administrators',
][$roleFilter];
?>
<!-- Users -->
<div class="card overflow-hidden">
    <div class="card-header flex-col items-start sm:flex-row sm:items-center">
        <div>
            <h2 class="card-title"><?php echo $listTitle; ?></h2>
            <p class="card-subtitle"><?php echo count($users); ?> account<?php echo count($users) === 1 ? '' : 's'; ?> &middot; <?php echo $activeCount; ?> active</p>
        </div>
        <!-- Role Filter -->
        <nav class="inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" aria-label="Filter by role">
            <a href="?" class="<?php echo $roleFilter === '' ? $segOn : $segOff; ?>">
                All <span class="<?php echo $segCount; ?>"><?php echo $totalUsers; ?></span>
            </a>
            <a href="?role=student" class="<?php echo $roleFilter === 'student' ? $segOn : $segOff; ?>">
                Students <span class="<?php echo $segCount; ?>"><?php echo $studentCount; ?></span>
            </a>
            <a href="?role=faculty" class="<?php echo $roleFilter === 'faculty' ? $segOn : $segOff; ?>">
                Faculty <span class="<?php echo $segCount; ?>"><?php echo $facultyCount; ?></span>
            </a>
            <a href="?role=hod" class="<?php echo $roleFilter === 'hod' ? $segOn : $segOff; ?>">
                HOD <span class="<?php echo $segCount; ?>"><?php echo $hodCount; ?></span>
            </a>
        </nav>
    </div>

    <?php if (empty($users)): ?>
        <div class="p-5 sm:p-6">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-users"></i></div>
                <h3 class="empty-state-title">No users found</h3>
                <p class="empty-state-description">Start by inviting your first user to the system.</p>
                <button onclick="showInviteModal()" class="btn btn-primary mt-5">
                    <i class="fas fa-user-plus"></i>Invite First User
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="usersTable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th class="hidden sm:table-cell">Status</th>
                        <th class="hidden lg:table-cell">Created</th>
                        <th class="!text-right" data-orderable="false">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $roleStyles = [
                        'student' => ['chip' => 'chip-brand',   'badge' => 'badge-brand',                                 'label' => 'Student'],
                        'faculty' => ['chip' => 'chip-violet',  'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',  'label' => 'Faculty'],
                        'hod'     => ['chip' => 'chip-emerald', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'label' => 'HOD'],
                        'admin'   => ['chip' => 'chip-rose',    'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',        'label' => 'Admin'],
                    ];
                    ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $roleStyle = $roleStyles[$user['role']] ?? ['chip' => 'chip-slate', 'badge' => 'badge-neutral', 'label' => ucfirst($user['role'])];
                        // Up to two initials from the decoded name, re-escaped for output.
                        $nameWords = preg_split('/\s+/', trim(plainName($user['name'])), -1, PREG_SPLIT_NO_EMPTY);
                        $initials = '';
                        foreach (array_slice($nameWords, 0, 2) as $word) {
                            $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="icon-chip-sm <?php echo $roleStyle['chip']; ?> rounded-full text-xs font-semibold <?php echo $user['is_active'] ? '' : 'opacity-60'; ?>" aria-hidden="true"><?php echo htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-gray-900"><?php echo sanitize($user['name']); ?></div>
                                        <div class="truncate text-xs text-gray-500"><?php echo sanitize($user['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="badge <?php echo $roleStyle['badge']; ?>"><?php echo $roleStyle['label']; ?></span>
                                <?php if (!$user['is_active']): ?>
                                    <span class="badge badge-neutral ml-1 sm:hidden" title="Inactive">
                                        <i class="fas fa-times-circle"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="hidden whitespace-nowrap sm:table-cell">
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-approved">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active
                                    </span>
                                    <?php if (empty($user['password'])): ?>
                                        <div class="mt-1 text-xs text-gray-500"><i class="fas fa-paper-plane mr-1 text-sky-500"></i>Sign-up pending</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-neutral">
                                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="hidden whitespace-nowrap text-gray-500 lg:table-cell" data-order="<?php echo htmlspecialchars((string) $user['created_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo formatDate($user['created_at']); ?>
                            </td>
                            <td class="text-right">
                                <?php
                                // Row data for the modals, as attributes: the onclick
                                // handlers below are constant code (see userAttr()).
                                $rowAttrs = 'data-user-id="' . (int) $user['id'] . '"'
                                    . ' data-user-name="' . userAttr($user['name']) . '"'
                                    . ' data-user-role="' . userAttr($user['role']) . '"'
                                    . ' data-user-active="' . ($user['is_active'] ? '1' : '0') . '"';
                                ?>
                                <div class="inline-flex flex-nowrap items-center justify-end gap-1">
                                    <button <?php echo $rowAttrs; ?> onclick="openViewUserDetails(this.dataset.userId, this.dataset.userName)" class="btn-icon hover:bg-brand-50 hover:text-brand-600" title="View User Details" aria-label="View User Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($user['role'] !== 'admin' && $user['is_active']): ?>
                                        <button <?php echo $rowAttrs; ?> onclick="confirmImpersonate(this.dataset.userId, this.dataset.userName, this.dataset.userRole)" class="btn-icon hover:bg-amber-50 hover:text-amber-600" title="Impersonate User" aria-label="Impersonate User">
                                            <i class="fas fa-user-secret"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($user['is_active'] && empty($user['password'])): ?>
                                        <form method="POST" class="inline-flex">
                                            <?php echo getCSRFField(); ?>
                                            <input type="hidden" name="action" value="resend_invite">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                            <button type="submit" class="btn-icon hover:bg-sky-50 hover:text-sky-600" title="Resend Invitation (sign-up not completed)" aria-label="Resend Invitation">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($user['role'] === 'student'): ?>
                                        <button <?php echo $rowAttrs; ?> onclick="openEditGuideTA(this.dataset.userId, this.dataset.userName)" class="btn-icon hover:bg-brand-50 hover:text-brand-600" title="Edit Faculty Guide and TA" aria-label="Edit Faculty Guide and TA">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button <?php echo $rowAttrs; ?> onclick="openAdjustBalanceModal(this.dataset.userId, this.dataset.userName)" class="btn-icon hover:bg-violet-50 hover:text-violet-600" title="Adjust Leave Balance" aria-label="Adjust Leave Balance">
                                            <i class="fas fa-balance-scale"></i>
                                        </button>
                                        <?php if ($user['is_active']): ?>
                                            <button <?php echo $rowAttrs; ?> onclick="openDeactivateModal(this.dataset.userId, this.dataset.userName)" class="btn-icon hover:bg-orange-50 hover:text-orange-600" title="Deactivate Student" aria-label="Deactivate Student">
                                                <i class="fas fa-user-slash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button <?php echo $rowAttrs; ?> onclick="confirmReactivate(this.dataset.userId, this.dataset.userName)" class="btn-icon hover:bg-emerald-50 hover:text-emerald-600" title="Reactivate Student" aria-label="Reactivate Student">
                                                <i class="fas fa-user-check"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button <?php echo $rowAttrs; ?> onclick="confirmDelete(this.dataset.userId, this.dataset.userName, this.dataset.userActive === '1')" class="btn-icon hover:bg-rose-50 hover:text-rose-600" title="<?php echo $user['is_active'] ? 'Deactivate User' : 'Delete User Permanently'; ?>" aria-label="<?php echo $user['is_active'] ? 'Deactivate User' : 'Delete User Permanently'; ?>">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Invite Modal -->
<div id="inviteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-h-[92vh] max-w-2xl overflow-y-auto">
        <!-- Modal Header -->
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-user-plus"></i></span>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Invite New User</h3>
                    <p class="text-sm text-gray-500">They will get an email link to set a password.</p>
                </div>
            </div>
            <button type="button" onclick="closeInviteModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="inviteForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="invite">

            <div class="space-y-4 px-6 py-5">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="form-label">
                            Name <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="name" required class="form-input" placeholder="Enter full name">
                    </div>
                    <div>
                        <label class="form-label">
                            Email <span class="text-rose-500">*</span>
                        </label>
                        <input type="email" name="email" required class="form-input" placeholder="user@iitb.ac.in">
                    </div>
                </div>

                <div>
                    <label class="form-label">
                        Role <span class="text-rose-500">*</span>
                    </label>
                    <select name="role" id="roleSelect" required class="form-select" onchange="toggleStudentFields()">
                        <option value="">Select Role</option>
                        <option value="student">Student</option>
                        <option value="faculty">Faculty</option>
                        <option value="hod">HOD</option>
                    </select>
                </div>

                <!-- Student-Specific Fields -->
                <div id="studentFields" class="hidden">
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle mt-0.5"></i>
                        <div>
                            <p class="font-medium">Student Information</p>
                            <p class="mt-0.5 text-xs">Please provide additional details for student registration</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="form-label">
                                    Roll Number <span class="text-rose-500">*</span>
                                </label>
                                <input type="text" name="roll_number" id="rollNumber" class="form-input" placeholder="e.g., 21D170001">
                            </div>
                            <div>
                                <label class="form-label">
                                    Joining Date <span class="text-rose-500">*</span>
                                </label>
                                <input type="date" name="joining_date" id="joiningDate" class="form-input">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="form-label">
                                    Faculty Guide
                                </label>
                                <select name="faculty_guide_id" id="facultyGuide" class="form-select">
                                    <option value="">Select Guide (Optional)</option>
                                    <?php foreach ($allFaculty as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">
                                    TA Coordinator
                                </label>
                                <select name="ta_coordinator_id" id="taCoordinator" class="form-select">
                                    <option value="">Select Coordinator (Optional)</option>
                                    <?php foreach ($allFaculty as $faculty): ?>
                                        <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Leave Balance Fields (shown when joining date is 1+ month in the past) -->
                        <div id="leaveBalanceFields" class="hidden pt-2">
                            <div class="alert alert-warning mb-4">
                                <i class="fas fa-exclamation-triangle mt-0.5"></i>
                                <div>
                                    <p class="font-medium">Backdated Joining Date Detected</p>
                                    <p class="mt-0.5 text-xs">Since the joining date is more than 1 month in the past, please specify the remaining leave balances for this student.</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div>
                                    <label class="form-label">
                                        Personal Leave Balance <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="number" name="personal_leave_balance" id="personalLeaveBalance" step="0.5" min="0" max="200" value="<?php echo PERSONAL_LEAVE_ALLOCATION; ?>" class="form-input">
                                    <p class="form-help">Max: 200 days</p>
                                </div>
                                <div>
                                    <label class="form-label">
                                        Medical Leave Balance <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="number" name="medical_leave_balance" id="medicalLeaveBalance" step="0.5" min="0" max="150" value="<?php echo MEDICAL_LEAVE_ALLOCATION; ?>" class="form-input">
                                    <p class="form-help">Max: 150 days</p>
                                </div>
                                <div>
                                    <label class="form-label">
                                        Academic Leave Balance <span class="text-rose-500">*</span>
                                    </label>
                                    <input type="number" name="academic_leave_balance" id="academicLeaveBalance" step="0.5" min="0" max="<?php echo ACADEMIC_LEAVE_ALLOCATION; ?>" value="<?php echo ACADEMIC_LEAVE_ALLOCATION; ?>" class="form-input">
                                    <p class="form-help">Max: <?php echo ACADEMIC_LEAVE_ALLOCATION; ?> days</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button" onclick="closeInviteModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i>Send Invitation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View User Details Modal -->
<div id="viewUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <!-- Modal Header -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-user-circle"></i></span>
                <h3 class="text-base font-semibold text-gray-900">User Details</h3>
            </div>
            <button type="button" onclick="closeViewUserModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="divide-y divide-gray-100 px-6 py-2 text-sm">
            <div class="flex items-start justify-between gap-4 py-3">
                <span class="text-gray-500">Name</span>
                <p class="text-right font-medium text-gray-900" id="viewUserName">-</p>
            </div>

            <div class="flex items-start justify-between gap-4 py-3">
                <span class="text-gray-500">Email</span>
                <p class="break-all text-right text-gray-900" id="viewUserEmail">-</p>
            </div>

            <div class="flex items-start justify-between gap-4 py-3">
                <span class="text-gray-500">Role</span>
                <p class="text-right text-gray-900" id="viewUserRole">-</p>
            </div>

            <div id="viewStudentDetails" class="hidden divide-y divide-gray-100">
                <div class="flex items-start justify-between gap-4 py-3">
                    <span class="text-gray-500">Faculty Guide</span>
                    <p class="text-right text-gray-900" id="viewGuideName">Not assigned</p>
                </div>

                <div class="flex items-start justify-between gap-4 py-3">
                    <span class="text-gray-500">TA Coordinator</span>
                    <p class="text-right text-gray-900" id="viewTAName">Not assigned</p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
            <button type="button" onclick="closeViewUserModal()" class="btn btn-secondary">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Edit Faculty Guide and TA Modal -->
<div id="editGuideTAModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <!-- Modal Header (the h3 text is replaced with the student's name when the modal opens) -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex min-w-0 items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-user-graduate"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Edit Faculty Guide and TA</h3>
            </div>
            <button type="button" onclick="closeEditGuideTAModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="editGuideTAForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="update_guide_ta">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="space-y-4 px-6 py-5">
                <div>
                    <label for="editFacultyGuide" class="form-label">
                        Faculty Guide
                    </label>
                    <select name="faculty_guide_id" id="editFacultyGuide" class="form-select">
                        <option value="0">-- Select Faculty Guide (Optional) --</option>
                        <?php foreach ($allFaculty as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="editTACoordinator" class="form-label">
                        TA Coordinator
                    </label>
                    <select name="ta_coordinator_id" id="editTACoordinator" class="form-select">
                        <option value="0">-- Select TA Coordinator (Optional) --</option>
                        <?php foreach ($allFaculty as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button" onclick="closeEditGuideTAModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <!-- Modal Header -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-rose"><i class="fas fa-exclamation-triangle"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Confirm Deletion</h3>
            </div>
            <button type="button" onclick="closeDeleteModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="space-y-4 px-6 py-5">
            <p class="font-medium text-gray-900" id="deleteQuestion">Are you sure you want to delete this user?</p>
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                <span class="text-gray-500">Name:</span> <span class="font-medium text-gray-900" id="deleteUserName"></span>
            </div>
            <!-- An active account is only deactivated; an inactive one is deleted
                 permanently, and only if it has no leave/attendance/approval history. -->
            <div class="alert alert-warning" id="deleteNoteActive">
                <i class="fas fa-info-circle mt-0.5"></i>
                <p>
                    The account is active, so it will be <strong>deactivated</strong>: sign-in is blocked and
                    open sessions end. Its records are kept. Delete again afterwards to remove it permanently.
                </p>
            </div>
            <div class="alert alert-error hidden" id="deleteNoteInactive">
                <i class="fas fa-info-circle mt-0.5"></i>
                <p>
                    This permanently deletes the account and cannot be undone. It is refused if the account has any
                    leave applications, attendance records, approvals or sign-offs; such accounts stay deactivated.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
            <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary">
                Cancel
            </button>
            <form method="POST" id="deleteForm" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="deleteUserId">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash-alt"></i>Delete User
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Deactivate Student Modal -->
<div id="deactivateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <!-- Modal Header -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm bg-orange-50 text-orange-600 ring-orange-100"><i class="fas fa-user-slash"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Deactivate Student</h3>
            </div>
            <button type="button" onclick="closeDeactivateModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="deactivateForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="toggle_activation">
            <input type="hidden" name="user_id" id="deactivateUserId">

            <div class="space-y-4 px-6 py-5">
                <div>
                    <p class="text-sm text-gray-500">You are about to deactivate:</p>
                    <p class="mt-0.5 text-lg font-semibold text-gray-900" id="deactivateUserName"></p>
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-info-circle mt-0.5"></i>
                    <p>
                        <strong>Effect:</strong> Deactivated students cannot log in and will not receive leave balance updates during the cron job reset.
                    </p>
                </div>

                <div>
                    <label class="form-label">
                        Reason for Deactivation <span class="text-rose-500">*</span>
                    </label>
                    <select name="deactivation_reason" id="deactivationReason" required class="form-select">
                        <option value="">Select a reason</option>
                        <option value="Passed Out - Completed PhD">Passed Out - Completed PhD</option>
                        <option value="Dropped Out - Voluntary withdrawal">Dropped Out - Voluntary withdrawal</option>
                        <option value="Dropped Out - Academic termination">Dropped Out - Academic termination</option>
                        <option value="Transfer to another institution">Transfer to another institution</option>
                        <option value="Long-term leave of absence">Long-term leave of absence</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button" onclick="closeDeactivateModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn bg-orange-600 text-white shadow-sm hover:bg-orange-700">
                    <i class="fas fa-user-slash"></i>Deactivate Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Student Confirmation Modal -->
<div id="reactivateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <!-- Modal Header -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-emerald"><i class="fas fa-user-check"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Reactivate Student</h3>
            </div>
            <button type="button" onclick="closeReactivateModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="space-y-4 px-6 py-5">
            <div>
                <p class="text-sm text-gray-500">Are you sure you want to reactivate this student?</p>
                <p class="mt-0.5 text-lg font-semibold text-gray-900" id="reactivateUserName"></p>
            </div>

            <div class="alert alert-success">
                <i class="fas fa-info-circle mt-0.5"></i>
                <p>
                    <strong>Effect:</strong> The student will be able to log in again and will receive leave balance updates during the next cron job reset.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
            <button type="button" onclick="closeReactivateModal()" class="btn btn-secondary">
                Cancel
            </button>
            <form method="POST" id="reactivateForm" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="toggle_activation">
                <input type="hidden" name="user_id" id="reactivateUserId">
                <input type="hidden" name="deactivation_reason" value="">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-user-check"></i>Reactivate Student
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Adjust Leave Balance Modal -->
<div id="adjustBalanceModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-h-[92vh] max-w-md overflow-y-auto">
        <!-- Modal Header -->
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-violet"><i class="fas fa-balance-scale"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Adjust Leave Balance</h3>
            </div>
            <button type="button" onclick="closeAdjustBalanceModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="adjustBalanceForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="user_id" id="adjustUserId">

            <div class="space-y-4 px-6 py-5">
                <div>
                    <p class="text-sm text-gray-500">Adjusting balance for:</p>
                    <p class="mt-0.5 text-lg font-semibold text-gray-900" id="adjustUserName"></p>
                </div>

                <!-- Current Balances Display -->
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Current Balances</p>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl border border-gray-200 px-2 py-2.5">
                            <p class="flex items-center justify-center gap-1.5 text-xs text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span>Personal</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900" id="currentPersonal">-</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 px-2 py-2.5">
                            <p class="flex items-center justify-center gap-1.5 text-xs text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>Medical</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900" id="currentMedical">-</p>
                        </div>
                        <div class="rounded-xl border border-gray-200 px-2 py-2.5">
                            <p class="flex items-center justify-center gap-1.5 text-xs text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>Academic</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-gray-900" id="currentAcademic">-</p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label">
                        Leave Type <span class="text-rose-500">*</span>
                    </label>
                    <select name="leave_type" id="adjustLeaveType" required class="form-select">
                        <option value="">Select Leave Type</option>
                        <option value="personal">Personal</option>
                        <option value="medical">Medical</option>
                        <option value="academic">Academic</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">
                        Adjustment Amount (days) <span class="text-rose-500">*</span>
                    </label>
                    <input type="number" name="adjustment_amount" id="adjustAmount" required step="0.5" class="form-input" placeholder="e.g., 5 to add, -3 to deduct">
                    <p class="form-help">Use positive values to increase, negative to decrease</p>
                </div>

                <div>
                    <label class="form-label">
                        Remarks <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="remarks" id="adjustRemarks" required rows="3" class="form-textarea" placeholder="Explain the reason for this adjustment..."></textarea>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button" onclick="closeAdjustBalanceModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn bg-violet-600 text-white shadow-sm hover:bg-violet-700">
                    <i class="fas fa-check"></i>Apply Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Impersonate Confirmation Modal -->
<div id="impersonateModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm">
    <div class="modal-panel max-w-md">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-amber"><i class="fas fa-user-secret"></i></span>
                <h3 class="text-base font-semibold text-gray-900">Confirm Impersonation</h3>
            </div>
            <button type="button" onclick="closeImpersonateModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="space-y-4 px-6 py-5">
            <div>
                <p class="text-sm text-gray-500">You are about to impersonate:</p>
                <p class="mt-0.5 text-lg font-semibold text-gray-900" id="impersonateUserName"></p>
                <p class="text-sm text-gray-500">Role: <span class="font-medium text-gray-700" id="impersonateUserRole"></span></p>
            </div>

            <div class="alert alert-warning">
                <i class="fas fa-info-circle mt-0.5"></i>
                <p>
                    You will be logged in as this user until you click "Return to admin" in the banner at the top of the page. All actions taken while impersonating will be logged against your admin account.
                </p>
            </div>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle mt-0.5"></i>
                <p>
                    Password changes and admin actions are blocked while impersonating.
                </p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
            <button type="button" onclick="closeImpersonateModal()" class="btn btn-secondary">
                Cancel
            </button>
            <form method="POST" action="<?php echo BASE_URL; ?>/admin/impersonate.php" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="user_id" id="impersonateUserId">
                <button type="submit" class="btn btn-warning">
                    <i class="fas fa-user-secret"></i>Impersonate
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Initialize DataTable if users exist
<?php if (!empty($users)): ?>
$(document).ready(function() {
    $('#usersTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[3, 'desc']],   // Created (Name and Email share the first column)
        language: {
            search: "Search users:",
            lengthMenu: "Show _MENU_ users per page",
            info: "Showing _START_ to _END_ of _TOTAL_ users",
            infoEmpty: "No users found",
            infoFiltered: "(filtered from _MAX_ total users)"
        }
    });
});
<?php endif; ?>

// Modal functions
function showInviteModal() {
    document.getElementById('inviteModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeInviteModal() {
    document.getElementById('inviteModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('inviteForm').reset();
    document.getElementById('studentFields').classList.add('hidden');
    document.getElementById('leaveBalanceFields').classList.add('hidden');
    // Reset leave balance fields to required = false
    setLeaveBalanceFieldsRequired(false);
}

// Toggle student fields based on role selection
function toggleStudentFields() {
    const role = document.getElementById('roleSelect').value;
    const studentFields = document.getElementById('studentFields');
    const rollNumber = document.getElementById('rollNumber');
    const joiningDate = document.getElementById('joiningDate');

    if (role === 'student') {
        studentFields.classList.remove('hidden');
        rollNumber.required = true;
        joiningDate.required = true;
        // Check if joining date already has a value
        checkJoiningDateForLeaveBalances();
    } else {
        studentFields.classList.add('hidden');
        rollNumber.required = false;
        joiningDate.required = false;
        // Hide leave balance fields when not a student
        document.getElementById('leaveBalanceFields').classList.add('hidden');
        setLeaveBalanceFieldsRequired(false);
    }
}

// Check if joining date is more than 1 month in the past
function checkJoiningDateForLeaveBalances() {
    const joiningDateInput = document.getElementById('joiningDate');
    const leaveBalanceFields = document.getElementById('leaveBalanceFields');
    const joiningDateValue = joiningDateInput.value;

    if (!joiningDateValue) {
        leaveBalanceFields.classList.add('hidden');
        setLeaveBalanceFieldsRequired(false);
        return;
    }

    const joiningDate = new Date(joiningDateValue);
    const today = new Date();

    // Calculate the date 1 month ago
    const oneMonthAgo = new Date();
    oneMonthAgo.setMonth(oneMonthAgo.getMonth() - 1);

    // If joining date is 1 month or more in the past, show leave balance fields
    if (joiningDate <= oneMonthAgo) {
        leaveBalanceFields.classList.remove('hidden');
        setLeaveBalanceFieldsRequired(true);
    } else {
        leaveBalanceFields.classList.add('hidden');
        setLeaveBalanceFieldsRequired(false);
    }
}

// Set leave balance fields required attribute
function setLeaveBalanceFieldsRequired(required) {
    document.getElementById('personalLeaveBalance').required = required;
    document.getElementById('medicalLeaveBalance').required = required;
    document.getElementById('academicLeaveBalance').required = required;
}

// Add event listener for joining date changes
document.getElementById('joiningDate').addEventListener('change', checkJoiningDateForLeaveBalances);

// Close modal on escape key - handled in a single handler at the bottom of script


// View User Details modal functions
function openViewUserDetails(userId, userName) {
    fetch('<?php echo BASE_URL; ?>/api/get_student_details.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Populate view modal
                document.getElementById('viewUserName').textContent = data.name || '-';
                document.getElementById('viewUserEmail').textContent = data.email || '-';
                document.getElementById('viewUserRole').textContent = data.role ? data.role.charAt(0).toUpperCase() + data.role.slice(1) : '-';

                // Show student details if user is a student
                const studentDetailsDiv = document.getElementById('viewStudentDetails');
                if (data.role === 'student') {
                    document.getElementById('viewGuideName').textContent = data.guide_name || 'Not assigned';
                    document.getElementById('viewTAName').textContent = data.ta_name || 'Not assigned';
                    studentDetailsDiv.classList.remove('hidden');
                } else {
                    studentDetailsDiv.classList.add('hidden');
                }

                // Open modal
                document.getElementById('viewUserModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading user details');
        });
}

function closeViewUserModal() {
    document.getElementById('viewUserModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close view modal on backdrop click
document.getElementById('viewUserModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeViewUserModal();
    }
});

// Edit Guide and TA modal functions
function openEditGuideTA(userId, userName) {
    document.getElementById('editUserId').value = userId;

    // Fetch current student details
    fetch('<?php echo BASE_URL; ?>/api/get_student_details.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Set modal title with student name
                document.querySelector('#editGuideTAModal h3').textContent =
                    'Edit Faculty Guide and TA - ' + data.name;

                // Pre-populate with current values
                document.getElementById('editFacultyGuide').value = data.faculty_guide_id || '0';
                document.getElementById('editTACoordinator').value = data.ta_coordinator_id || '0';

                // Open modal
                document.getElementById('editGuideTAModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                alert('Error loading student details: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // Fallback: open modal with empty values
            document.getElementById('editFacultyGuide').value = '0';
            document.getElementById('editTACoordinator').value = '0';
            document.getElementById('editGuideTAModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });
}

function closeEditGuideTAModal() {
    document.getElementById('editGuideTAModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('editGuideTAForm').reset();
}

// Close edit modal on escape key (already handled globally)
// Close edit modal on backdrop click
document.getElementById('editGuideTAModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeEditGuideTAModal();
    }
});

// Delete modal functions
function confirmDelete(userId, userName, isActive) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteQuestion').textContent = isActive
        ? 'Deactivate this user?' : 'Permanently delete this user?';
    document.getElementById('deleteNoteActive').classList.toggle('hidden', !isActive);
    document.getElementById('deleteNoteInactive').classList.toggle('hidden', isActive);
    document.getElementById('deleteModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close delete modal on backdrop click
document.getElementById('deleteModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeDeleteModal();
    }
});

// Deactivate modal functions
function openDeactivateModal(userId, userName) {
    document.getElementById('deactivateUserId').value = userId;
    document.getElementById('deactivateUserName').textContent = userName;
    document.getElementById('deactivationReason').value = '';
    document.getElementById('deactivateModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDeactivateModal() {
    document.getElementById('deactivateModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close deactivate modal on backdrop click
document.getElementById('deactivateModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeDeactivateModal();
    }
});

// Reactivate modal functions
function confirmReactivate(userId, userName) {
    document.getElementById('reactivateUserId').value = userId;
    document.getElementById('reactivateUserName').textContent = userName;
    document.getElementById('reactivateModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeReactivateModal() {
    document.getElementById('reactivateModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close reactivate modal on backdrop click
document.getElementById('reactivateModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeReactivateModal();
    }
});

// Adjust Balance modal functions
function openAdjustBalanceModal(userId, userName) {
    document.getElementById('adjustUserId').value = userId;
    document.getElementById('adjustUserName').textContent = userName;
    document.getElementById('adjustLeaveType').value = '';
    document.getElementById('adjustAmount').value = '';
    document.getElementById('adjustRemarks').value = '';
    document.getElementById('currentPersonal').textContent = '-';
    document.getElementById('currentMedical').textContent = '-';
    document.getElementById('currentAcademic').textContent = '-';

    // Fetch current balances
    fetch('<?php echo BASE_URL; ?>/api/get_student_balances.php?id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('currentPersonal').textContent = data.personal_leaves;
                document.getElementById('currentMedical').textContent = data.medical_leaves;
                document.getElementById('currentAcademic').textContent = data.academic_leaves;
            }
        })
        .catch(error => {
            console.error('Error fetching balances:', error);
        });

    document.getElementById('adjustBalanceModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeAdjustBalanceModal() {
    document.getElementById('adjustBalanceModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('adjustBalanceForm').reset();
}

// Close adjust balance modal on backdrop click
document.getElementById('adjustBalanceModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeAdjustBalanceModal();
    }
});

// Impersonate modal functions
function confirmImpersonate(userId, userName, userRole) {
    document.getElementById('impersonateUserId').value = userId;
    document.getElementById('impersonateUserName').textContent = userName;
    document.getElementById('impersonateUserRole').textContent =
        userRole.charAt(0).toUpperCase() + userRole.slice(1);
    document.getElementById('impersonateModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeImpersonateModal() {
    document.getElementById('impersonateModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close impersonate modal on backdrop click
document.getElementById('impersonateModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeImpersonateModal();
    }
});

// Update escape key handler to include new modals
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeInviteModal();
        closeDeleteModal();
        closeViewUserModal();
        closeEditGuideTAModal();
        closeDeactivateModal();
        closeReactivateModal();
        closeAdjustBalanceModal();
        closeImpersonateModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>