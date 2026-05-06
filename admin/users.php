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

$userClass = new User();
$emailService = new EmailService();

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
        $result = $userClass->create($data);

        if ($result['success']) {
            // Send invitation email
            try {
                $emailService->sendInvitation($email, $name, $result['token']);
                setFlashMessage('success', "User created and invitation sent to $email");
            } catch (Exception $e) {
                setFlashMessage('warning', "User created but email failed to send: " . $e->getMessage());
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
        if ($userId === $_SESSION['user_id']) {
            throw new Exception("You cannot delete your own account");
        }

        // If user is inactive, do a permanent deletion (hard delete)
        // Otherwise, just deactivate (soft delete)
        $db = getDBConnection();

        if ($user['is_active'] == 0) {
            // Hard delete - permanently remove inactive user
            // This will cascade delete related records (students, leaves, etc.)
            $sql = "DELETE FROM users WHERE id = :id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([':id' => $userId]);
            $deleteType = 'permanently deleted';
        } else {
            // Soft delete - just deactivate active user
            $result = $userClass->delete($userId);
            $deleteType = 'deactivated';
        }

        if ($result) {
            setFlashMessage('success', "User '{$user['name']}' has been $deleteType successfully");
        } else {
            throw new Exception("Failed to delete user");
        }

        // Refresh page after 1 second to show updated list with flash message
        $redirect_url = BASE_URL . '/admin/users.php';
        if ($roleFilter) {
            $redirect_url .= '?role=' . urlencode($roleFilter);
        }
        header('Refresh: 1; URL=' . $redirect_url);
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        // Refresh page after 1 second to show error message
        $redirect_url = BASE_URL . '/admin/users.php';
        if ($roleFilter) {
            $redirect_url .= '?role=' . urlencode($roleFilter);
        }
        header('Refresh: 1; URL=' . $redirect_url);
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
        if ($userId === $_SESSION['user_id']) {
            throw new Exception("You cannot deactivate your own account");
        }

        $db = getDBConnection();

        if ($user['is_active']) {
            // Deactivate student
            if (empty($reason)) {
                throw new Exception("Please provide a reason for deactivation");
            }

            $sql = "UPDATE users SET is_active = 0 WHERE id = :id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([':id' => $userId]);

            if ($result) {
                // Log the deactivation with reason
                $logSql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                           VALUES (:admin_id, 'student_deactivated', 'users', :description, :ip)";
                $logStmt = $db->prepare($logSql);
                $logStmt->execute([
                    ':admin_id' => $_SESSION['user_id'],
                    ':description' => "Student '{$user['name']}' (ID: $userId) deactivated. Reason: $reason",
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                setFlashMessage('success', "Student '{$user['name']}' has been deactivated. They will no longer receive leave balance updates.");
            } else {
                throw new Exception("Failed to deactivate student");
            }
        } else {
            // Reactivate student
            $sql = "UPDATE users SET is_active = 1 WHERE id = :id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute([':id' => $userId]);

            if ($result) {
                // Log the reactivation
                $logSql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                           VALUES (:admin_id, 'student_reactivated', 'users', :description, :ip)";
                $logStmt = $db->prepare($logSql);
                $logStmt->execute([
                    ':admin_id' => $_SESSION['user_id'],
                    ':description' => "Student '{$user['name']}' (ID: $userId) reactivated",
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                setFlashMessage('success', "Student '{$user['name']}' has been reactivated.");
            } else {
                throw new Exception("Failed to reactivate student");
            }
        }

        $redirectRole = sanitize($_GET['role'] ?? '');
        header('Location: ' . BASE_URL . '/admin/users.php' . ($redirectRole ? '?role=' . urlencode($redirectRole) : ''));
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        header('Location: ' . BASE_URL . '/admin/users.php');
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

        // Update the guide and TA
        $result = $userClass->updateStudentGuideAndTA($userId, $facultyGuideId, $taCoordinatorId);

        if ($result) {
            setFlashMessage('success', "Student's faculty guide and TA coordinator updated successfully");
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
        $leaveClass->adjustBalance($student['id'], $leaveType, $adjustmentAmount, $remarks, $_SESSION['user_id']);

        $direction = $adjustmentAmount > 0 ? 'increased' : 'decreased';
        logSystemAction(
            $_SESSION['user_id'],
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
requireRole('admin');

// Get role filter (must be after sanitize is loaded from header)
$roleFilter = sanitize($_GET['role'] ?? '');

// Fetch users based on filter
if ($roleFilter && in_array($roleFilter, ['student', 'faculty', 'hod', 'admin'])) {
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

<div class="mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div class="mb-4 md:mb-0">
            <h1 class="text-3xl font-bold text-gray-900">User Management</h1>
            <p class="text-gray-600 mt-2">Manage system users and send invitations</p>
        </div>
        <button onclick="showInviteModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-user-plus mr-2"></i>Invite User
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Students</p>
                <p class="text-3xl font-bold text-blue-600 mt-1"><?php echo $studentCount; ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
                <i class="fas fa-user-graduate text-3xl text-blue-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Faculty</p>
                <p class="text-3xl font-bold text-purple-600 mt-1"><?php echo $facultyCount; ?></p>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
                <i class="fas fa-chalkboard-teacher text-3xl text-purple-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">HOD</p>
                <p class="text-3xl font-bold text-green-600 mt-1"><?php echo $hodCount; ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
                <i class="fas fa-user-tie text-3xl text-green-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Total</p>
                <p class="text-3xl font-bold text-orange-600 mt-1"><?php echo $totalUsers; ?></p>
            </div>
            <div class="p-3 bg-orange-100 rounded-lg">
                <i class="fas fa-users text-3xl text-orange-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- Role Filter -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap gap-2">
        <a href="?" class="px-4 py-2 <?php echo $roleFilter === '' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> rounded-lg text-sm font-medium transition-colors">
            All Users
        </a>
        <a href="?role=student" class="px-4 py-2 <?php echo $roleFilter === 'student' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> rounded-lg text-sm font-medium transition-colors">
            Students (<?php echo $studentCount; ?>)
        </a>
        <a href="?role=faculty" class="px-4 py-2 <?php echo $roleFilter === 'faculty' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> rounded-lg text-sm font-medium transition-colors">
            Faculty (<?php echo $facultyCount; ?>)
        </a>
        <a href="?role=hod" class="px-4 py-2 <?php echo $roleFilter === 'hod' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> rounded-lg text-sm font-medium transition-colors">
            HOD (<?php echo $hodCount; ?>)
        </a>
    </div>
</div>

<!-- Users Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($users)): ?>
        <div class="p-12 text-center">
            <i class="fas fa-users text-6xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">No Users Found</h3>
            <p class="text-gray-600 mb-6">Start by inviting your first user to the system</p>
            <button onclick="showInviteModal()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-user-plus mr-2"></i>Invite First User
            </button>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="usersTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Created</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-4">
                                <div class="font-medium text-gray-900"><?php echo sanitize($user['name']); ?></div>
                                <div class="text-xs text-gray-500 md:hidden"><?php echo sanitize($user['email']); ?></div>
                            </td>
                            <td class="px-4 py-4 hidden md:table-cell">
                                <div class="text-sm text-gray-600 break-all"><?php echo sanitize($user['email']); ?></div>
                            </td>
                            <td class="px-4 py-4">
                                <?php
                                $roleColors = [
                                    'student' => 'bg-blue-100 text-blue-800',
                                    'faculty' => 'bg-purple-100 text-purple-800',
                                    'hod' => 'bg-green-100 text-green-800',
                                    'admin' => 'bg-red-100 text-red-800'
                                ];
                                $colorClass = $roleColors[$user['role']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $colorClass; ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                                <?php if (!$user['is_active']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 sm:hidden ml-1">
                                        <i class="fas fa-times-circle"></i>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 hidden sm:table-cell">
                                <?php if ($user['is_active']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                        <i class="fas fa-check-circle mr-1"></i>Active
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                        <i class="fas fa-times-circle mr-1"></i>Inactive
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-500 hidden lg:table-cell">
                                <?php echo formatDate($user['created_at']); ?>
                            </td>
                            <td class="px-4 py-4 text-sm font-medium">
                                <div class="flex flex-wrap gap-2">
                                    <button onclick="openViewUserDetails(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors" title="View User Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($user['role'] !== 'admin' && $user['is_active']): ?>
                                        <button onclick="confirmImpersonate(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>', '<?php echo addslashes($user['role']); ?>')" class="p-2 text-yellow-600 hover:text-yellow-900 hover:bg-yellow-50 rounded transition-colors" title="Impersonate User">
                                            <i class="fas fa-user-secret"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($user['role'] === 'student'): ?>
                                        <button onclick="openEditGuideTA(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded transition-colors" title="Edit Faculty Guide and TA">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="openAdjustBalanceModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded transition-colors" title="Adjust Leave Balance">
                                            <i class="fas fa-balance-scale"></i>
                                        </button>
                                        <?php if ($user['is_active']): ?>
                                            <button onclick="openDeactivateModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-orange-600 hover:text-orange-900 hover:bg-orange-50 rounded transition-colors" title="Deactivate Student">
                                                <i class="fas fa-user-slash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="confirmReactivate(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors" title="Reactivate Student">
                                                <i class="fas fa-user-check"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo addslashes($user['name']); ?>')" class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded transition-colors" title="Delete User">
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
<div id="inviteModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-plus mr-2 text-blue-600"></i>Invite New User
            </h3>
            <button onclick="closeInviteModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="inviteForm" class="p-6">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="invite">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Enter full name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Email <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="user@iitb.ac.in">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Role <span class="text-red-500">*</span>
                </label>
                <select name="role" id="roleSelect" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" onchange="toggleStudentFields()">
                    <option value="">Select Role</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="hod">HOD</option>
                </select>
            </div>

            <!-- Student-Specific Fields -->
            <div id="studentFields" class="hidden">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <p class="text-sm text-blue-800 font-medium mb-2">
                        <i class="fas fa-info-circle mr-1"></i>Student Information
                    </p>
                    <p class="text-xs text-blue-700">Please provide additional details for student registration</p>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Roll Number <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="roll_number" id="rollNumber" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., 21D170001">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Joining Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" name="joining_date" id="joiningDate" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Faculty Guide
                            </label>
                            <select name="faculty_guide_id" id="facultyGuide" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Select Guide (Optional)</option>
                                <?php foreach ($allFaculty as $faculty): ?>
                                    <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                TA Coordinator
                            </label>
                            <select name="ta_coordinator_id" id="taCoordinator" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Select Coordinator (Optional)</option>
                                <?php foreach ($allFaculty as $faculty): ?>
                                    <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Leave Balance Fields (shown when joining date is 1+ month in the past) -->
                    <div id="leaveBalanceFields" class="hidden mt-4">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                            <p class="text-sm text-yellow-800 font-medium mb-2">
                                <i class="fas fa-exclamation-triangle mr-1"></i>Backdated Joining Date Detected
                            </p>
                            <p class="text-xs text-yellow-700">Since the joining date is more than 1 month in the past, please specify the remaining leave balances for this student.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Personal Leave Balance <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="personal_leave_balance" id="personalLeaveBalance" step="0.5" min="0" max="200" value="<?php echo PERSONAL_LEAVE_ALLOCATION; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">Max: 200 days</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Medical Leave Balance <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="medical_leave_balance" id="medicalLeaveBalance" step="0.5" min="0" max="150" value="<?php echo MEDICAL_LEAVE_ALLOCATION; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">Max: 150 days</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Academic Leave Balance <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="academic_leave_balance" id="academicLeaveBalance" step="0.5" min="0" max="<?php echo ACADEMIC_LEAVE_ALLOCATION; ?>" value="<?php echo ACADEMIC_LEAVE_ALLOCATION; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <p class="text-xs text-gray-500 mt-1">Max: <?php echo ACADEMIC_LEAVE_ALLOCATION; ?> days</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end space-x-3 pt-6 mt-6 border-t border-gray-200">
                <button type="button" onclick="closeInviteModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-paper-plane mr-2"></i>Send Invitation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View User Details Modal -->
<div id="viewUserModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-circle mr-2 text-green-600"></i>User Details
            </h3>
        </div>

        <!-- Modal Body -->
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Name</label>
                <p class="mt-1 text-gray-900" id="viewUserName">-</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Email</label>
                <p class="mt-1 text-gray-900" id="viewUserEmail">-</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Role</label>
                <p class="mt-1" id="viewUserRole">-</p>
            </div>

            <div id="viewStudentDetails" class="hidden pt-4 border-t border-gray-200 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Faculty Guide</label>
                    <p class="mt-1 text-gray-900" id="viewGuideName">Not assigned</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">TA Coordinator</label>
                    <p class="mt-1 text-gray-900" id="viewTAName">Not assigned</p>
                </div>
            </div>

            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeViewUserModal()" class="flex-1 px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                    <i class="fas fa-times mr-2"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Faculty Guide and TA Modal -->
<div id="editGuideTAModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-graduate mr-2 text-blue-600"></i>Edit Faculty Guide and TA
            </h3>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <form method="POST" id="editGuideTAForm">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="update_guide_ta">
                <input type="hidden" name="user_id" id="editUserId">

                <div class="mb-4">
                    <label for="editFacultyGuide" class="block text-sm font-medium text-gray-700 mb-1">
                        Faculty Guide
                    </label>
                    <select name="faculty_guide_id" id="editFacultyGuide" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="0">-- Select Faculty Guide (Optional) --</option>
                        <?php foreach ($allFaculty as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-6">
                    <label for="editTACoordinator" class="block text-sm font-medium text-gray-700 mb-1">
                        TA Coordinator
                    </label>
                    <select name="ta_coordinator_id" id="editTACoordinator" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="0">-- Select TA Coordinator (Optional) --</option>
                        <?php foreach ($allFaculty as $faculty): ?>
                            <option value="<?php echo $faculty['id']; ?>"><?php echo sanitize($faculty['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-save mr-2"></i>Update
                    </button>
                    <button type="button" onclick="closeEditGuideTAModal()" class="flex-1 px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition-colors">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-exclamation-triangle mr-2 text-red-600"></i>Confirm Deletion
            </h3>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <p class="text-gray-700 mb-2">Are you sure you want to delete this user?</p>
            <p class="text-sm text-gray-600 mb-4">
                <strong>Name:</strong> <span id="deleteUserName"></span>
            </p>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                <p class="text-sm text-red-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    This action cannot be undone. All user data will be permanently deleted.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                <i class="fas fa-times mr-2"></i>Cancel
            </button>
            <form method="POST" id="deleteForm" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="deleteUserId">
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    <i class="fas fa-trash-alt mr-2"></i>Delete User
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Deactivate Student Modal -->
<div id="deactivateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-slash mr-2 text-orange-600"></i>Deactivate Student
            </h3>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="deactivateForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="toggle_activation">
            <input type="hidden" name="user_id" id="deactivateUserId">

            <div class="p-6">
                <p class="text-gray-700 mb-2">You are about to deactivate:</p>
                <p class="text-lg font-semibold text-gray-900 mb-4" id="deactivateUserName"></p>

                <div class="bg-orange-50 border border-orange-200 rounded-lg p-3 mb-4">
                    <p class="text-sm text-orange-800">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>Effect:</strong> Deactivated students cannot log in and will not receive leave balance updates during the cron job reset.
                    </p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Reason for Deactivation <span class="text-red-500">*</span>
                    </label>
                    <select name="deactivation_reason" id="deactivationReason" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
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
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeDeactivateModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors">
                    <i class="fas fa-user-slash mr-2"></i>Deactivate Student
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Student Confirmation Modal -->
<div id="reactivateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-check mr-2 text-green-600"></i>Reactivate Student
            </h3>
        </div>

        <!-- Modal Body -->
        <div class="p-6">
            <p class="text-gray-700 mb-2">Are you sure you want to reactivate this student?</p>
            <p class="text-lg font-semibold text-gray-900 mb-4" id="reactivateUserName"></p>

            <div class="bg-green-50 border border-green-200 rounded-lg p-3">
                <p class="text-sm text-green-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>Effect:</strong> The student will be able to log in again and will receive leave balance updates during the next cron job reset.
                </p>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
            <button type="button" onclick="closeReactivateModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                <i class="fas fa-times mr-2"></i>Cancel
            </button>
            <form method="POST" id="reactivateForm" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="toggle_activation">
                <input type="hidden" name="user_id" id="reactivateUserId">
                <input type="hidden" name="deactivation_reason" value="">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fas fa-user-check mr-2"></i>Reactivate Student
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Adjust Leave Balance Modal -->
<div id="adjustBalanceModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-balance-scale mr-2 text-indigo-600"></i>Adjust Leave Balance
            </h3>
        </div>

        <!-- Modal Body -->
        <form method="POST" id="adjustBalanceForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="user_id" id="adjustUserId">

            <div class="p-6">
                <p class="text-gray-700 mb-1">Adjusting balance for:</p>
                <p class="text-lg font-semibold text-gray-900 mb-4" id="adjustUserName"></p>

                <!-- Current Balances Display -->
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4">
                    <p class="text-sm font-medium text-gray-700 mb-2">Current Balances:</p>
                    <div class="grid grid-cols-3 gap-2 text-center text-sm">
                        <div>
                            <p class="text-gray-500">Personal</p>
                            <p class="font-semibold" id="currentPersonal">-</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Medical</p>
                            <p class="font-semibold" id="currentMedical">-</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Academic</p>
                            <p class="font-semibold" id="currentAcademic">-</p>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Leave Type <span class="text-red-500">*</span>
                    </label>
                    <select name="leave_type" id="adjustLeaveType" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <option value="">Select Leave Type</option>
                        <option value="personal">Personal</option>
                        <option value="medical">Medical</option>
                        <option value="academic">Academic</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Adjustment Amount (days) <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="adjustment_amount" id="adjustAmount" required step="0.5" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="e.g., 5 to add, -3 to deduct">
                    <p class="text-xs text-gray-500 mt-1">Use positive values to increase, negative to decrease</p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Remarks <span class="text-red-500">*</span>
                    </label>
                    <textarea name="remarks" id="adjustRemarks" required rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="Explain the reason for this adjustment..."></textarea>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                <button type="button" onclick="closeAdjustBalanceModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    <i class="fas fa-check mr-2"></i>Apply Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Impersonate Confirmation Modal -->
<div id="impersonateModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-user-secret mr-2 text-yellow-600"></i>Confirm Impersonation
            </h3>
        </div>

        <div class="p-6">
            <p class="text-gray-700 mb-2">You are about to impersonate:</p>
            <p class="text-lg font-semibold text-gray-900 mb-1" id="impersonateUserName"></p>
            <p class="text-sm text-gray-600 mb-4">Role: <span id="impersonateUserRole"></span></p>

            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-2">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    You will be logged in as this user until you click "Return to admin" in the banner at the top of the page. All actions taken while impersonating will be logged against your admin account.
                </p>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                <p class="text-sm text-red-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Password changes and admin actions are blocked while impersonating.
                </p>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
            <button type="button" onclick="closeImpersonateModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                <i class="fas fa-times mr-2"></i>Cancel
            </button>
            <form method="POST" action="<?php echo BASE_URL; ?>/admin/impersonate.php" class="inline">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="user_id" id="impersonateUserId">
                <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    <i class="fas fa-user-secret mr-2"></i>Impersonate
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
        order: [[4, 'desc']],
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
                    '✏️ Edit Faculty Guide and TA - ' + data.name;

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
function confirmDelete(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
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