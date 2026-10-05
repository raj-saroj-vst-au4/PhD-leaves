<?php
/**
 * User Profile Page
 * View and update user profile information
 */

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';
requireLogin();

require_once __DIR__ . '/classes/User.php';

$userClass = new User();
$userId = getCurrentUserId();
$userRole = getCurrentUserRole();

// Get user details
$user = $userClass->getById($userId);

if (!$user) {
    setFlashMessage('error', 'User not found');
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Get role-specific details
$roleDetails = null;
$allFaculty = [];
if ($userRole === 'student') {
    $roleDetails = $userClass->getStudentDetails($userId);
    // Get all active faculty for Guide/TA selection
    $allFaculty = $userClass->getAllByRole('faculty', true);
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkCSRF();

        $name = sanitize($_POST['name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');

        if (empty($name)) {
            $error = 'Name is required';
        } elseif (strlen($name) < 2) {
            $error = 'Name must be at least 2 characters';
        } else {
            $result = $userClass->updateProfile($userId, [
                'name' => $name,
                'phone' => $phone
            ]);

            /*
             * Guide and TA coordinator are deliberately NOT accepted here.
             *
             * This handler used to pass whatever the student posted straight to
             * updateStudentGuideAndTA(), which let a student re-route their own
             * approval chain: swap in a different guide while an application was
             * sitting at pending_guide, set guide == TA to collapse a stage, or
             * blank both and drop out of monthly attendance sign-off. The select
             * boxes only listed faculty, but nothing stopped a hand-made POST
             * naming any active user at all.
             *
             * A correction is now raised from the leave application form and
             * decided by an admin — see classes/GuideTAChange.php.
             */

            if ($result) {
                $success = 'Profile updated successfully';
                // Refresh user data
                $user = $userClass->getById($userId);
                // Refresh student details
                if ($userRole === 'student') {
                    $roleDetails = $userClass->getStudentDetails($userId);
                }
                // Update session name
                $_SESSION['user_name'] = $name;
            } else {
                $error = 'Failed to update profile';
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<div class="max-w-5xl mx-auto">
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <p class="page-eyebrow">Account</p>
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">View and update your profile information</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error mb-6" role="alert">
            <i class="fas fa-exclamation-circle mt-0.5"></i>
            <p><?php echo sanitize($error); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success mb-6" role="status">
            <i class="fas fa-check-circle mt-0.5"></i>
            <p><?php echo sanitize($success); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $profileInitials = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $user['name'], ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $profileInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Card -->
        <div class="lg:col-span-1 space-y-6">
            <div class="card overflow-hidden text-center">
                <div class="h-20 bg-gradient-to-br from-blue-600 to-violet-600"></div>
                <div class="-mt-10 px-6 pb-6">
                    <span class="avatar h-20 w-20 text-2xl ring-4 ring-white"><?php echo sanitize($profileInitials ?: '?'); ?></span>
                    <h2 class="mt-3 text-lg font-semibold text-gray-900"><?php echo sanitize($user['name']); ?></h2>
                    <p class="mt-0.5 text-sm text-gray-500 break-all"><?php echo sanitize($user['email']); ?></p>
                    <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                        <span class="badge badge-brand"><?php echo sanitize(ucfirst($userRole)); ?></span>
                        <?php if ($roleDetails && isset($roleDetails['roll_number'])): ?>
                            <span class="badge badge-neutral">
                                <i class="fas fa-id-card"></i><?php echo sanitize($roleDetails['roll_number']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-footer text-xs text-gray-500">
                    Member since <?php echo date('M Y', strtotime($user['created_at'])); ?>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="card p-2">
                <p class="px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Quick links</p>
                <a href="<?php echo BASE_URL; ?>/change-password.php" class="menu-item">
                    <span class="icon-chip-sm chip-slate"><i class="fas fa-key"></i></span>
                    <span class="flex-1">Change Password</span>
                    <i class="fas fa-chevron-right text-xs text-gray-300"></i>
                </a>
                <?php if ($userRole === 'student'): ?>
                    <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="menu-item">
                        <span class="icon-chip-sm chip-brand"><i class="fas fa-home"></i></span>
                        <span class="flex-1">Dashboard</span>
                        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="menu-item">
                        <span class="icon-chip-sm chip-violet"><i class="fas fa-history"></i></span>
                        <span class="flex-1">Leave History</span>
                        <i class="fas fa-chevron-right text-xs text-gray-300"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Form -->
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Profile Information</h3>
                        <p class="card-subtitle">Your name and phone number are shown to approvers.</p>
                    </div>
                </div>
                <form method="POST" class="card-body space-y-5">
                    <?php echo getCSRFField(); ?>

                    <!-- Name -->
                    <div>
                        <label for="name" class="form-label">
                            Full Name <span class="text-rose-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?php echo sanitize($user['name']); ?>"
                            class="form-input"
                            required
                        >
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <!-- Email (Read-only) -->
                        <div>
                            <label for="email" class="form-label">
                                Email Address
                            </label>
                            <input
                                type="email"
                                id="email"
                                value="<?php echo sanitize($user['email']); ?>"
                                class="form-input"
                                disabled
                            >
                            <p class="form-help">Email cannot be changed</p>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label for="phone" class="form-label">
                                Phone Number
                            </label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?php echo sanitize($user['phone'] ?? ''); ?>"
                                class="form-input"
                                placeholder="Enter your phone number"
                            >
                        </div>
                    </div>

                    <?php if ($roleDetails): ?>
                        <!-- Student-specific Information -->
                        <?php if ($userRole === 'student'): ?>
                            <div class="pt-5 border-t border-gray-100">
                                <h4 class="text-sm font-semibold text-gray-900">Academic Information</h4>
                                <p class="mt-0.5 mb-4 text-sm text-gray-500">Maintained by the PhD office.</p>

                                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                        <dt class="text-xs font-medium text-gray-500">Roll Number</dt>
                                        <dd class="mt-0.5 text-sm font-semibold text-gray-900"><?php echo sanitize($roleDetails['roll_number'] ?? 'N/A'); ?></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                        <dt class="text-xs font-medium text-gray-500">Joining Date</dt>
                                        <dd class="mt-0.5 text-sm font-semibold text-gray-900"><?php echo $roleDetails['joining_date'] ? date('d M Y', strtotime($roleDetails['joining_date'])) : 'N/A'; ?></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                        <dt class="text-xs font-medium text-gray-500">Faculty Guide</dt>
                                        <dd class="mt-0.5 text-sm font-semibold <?php echo empty($roleDetails['guide_name']) ? 'text-gray-400' : 'text-gray-900'; ?>"><?php echo sanitize($roleDetails['guide_name'] ?? 'Not assigned'); ?></dd>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 px-4 py-3 ring-1 ring-inset ring-gray-100">
                                        <dt class="text-xs font-medium text-gray-500">TA Coordinator</dt>
                                        <dd class="mt-0.5 text-sm font-semibold <?php echo empty($roleDetails['ta_coordinator_name']) ? 'text-gray-400' : 'text-gray-900'; ?>"><?php echo sanitize($roleDetails['ta_coordinator_name'] ?? 'Not assigned'); ?></dd>
                                    </div>
                                </dl>

                                <div class="alert alert-info mt-4">
                                    <i class="fas fa-info-circle mt-0.5"></i>
                                    <p>
                                        Your Faculty Guide and TA Coordinator decide who approves your
                                        leave, so they are set by the office rather than edited here.
                                        If either is wrong, you can request a correction when you
                                        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="font-semibold underline">apply for leave</a>
                                        &mdash; the office reviews the change before your application is routed.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
