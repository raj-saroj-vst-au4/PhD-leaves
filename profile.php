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

<div class="max-w-4xl mx-auto">
    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">My Profile</h1>
        <p class="text-gray-600 mt-2">View and update your profile information</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-sm text-red-800">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="text-sm text-green-800">
                <i class="fas fa-check-circle mr-2"></i><?php echo $success; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Card -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md p-6 text-center">
                <div class="w-24 h-24 bg-blue-100 rounded-full mx-auto flex items-center justify-center mb-4">
                    <i class="fas fa-user text-blue-600 text-4xl"></i>
                </div>
                <h2 class="text-xl font-semibold text-gray-900"><?php echo sanitize($user['name']); ?></h2>
                <p class="text-gray-600 mt-1"><?php echo sanitize($user['email']); ?></p>
                <span class="inline-block mt-3 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm font-medium">
                    <?php echo ucfirst($userRole); ?>
                </span>

                <?php if ($roleDetails && isset($roleDetails['roll_number'])): ?>
                    <p class="text-gray-500 text-sm mt-3">
                        <i class="fas fa-id-card mr-1"></i>
                        <?php echo sanitize($roleDetails['roll_number']); ?>
                    </p>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-t border-gray-200">
                    <p class="text-xs text-gray-500">
                        Member since <?php echo date('M Y', strtotime($user['created_at'])); ?>
                    </p>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bg-white rounded-lg shadow-md p-6 mt-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Links</h3>
                <ul class="space-y-2">
                    <li>
                        <a href="<?php echo BASE_URL; ?>/change-password.php" class="flex items-center text-gray-700 hover:text-blue-600">
                            <i class="fas fa-key mr-3 text-gray-400"></i>
                            Change Password
                        </a>
                    </li>
                    <?php if ($userRole === 'student'): ?>
                        <li>
                            <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="flex items-center text-gray-700 hover:text-blue-600">
                                <i class="fas fa-home mr-3 text-gray-400"></i>
                                Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="flex items-center text-gray-700 hover:text-blue-600">
                                <i class="fas fa-history mr-3 text-gray-400"></i>
                                Leave History
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Profile Form -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Profile Information</h3>
                </div>
                <form method="POST" class="p-6 space-y-6">
                    <?php echo getCSRFField(); ?>

                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                            Full Name <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            value="<?php echo sanitize($user['name']); ?>"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            required
                        >
                    </div>

                    <!-- Email (Read-only) -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            Email Address
                        </label>
                        <input
                            type="email"
                            id="email"
                            value="<?php echo sanitize($user['email']); ?>"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                            disabled
                        >
                        <p class="mt-1 text-xs text-gray-500">Email cannot be changed</p>
                    </div>

                    <!-- Phone -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">
                            Phone Number
                        </label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            value="<?php echo sanitize($user['phone'] ?? ''); ?>"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter your phone number"
                        >
                    </div>

                    <!-- Role (Read-only) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Role
                        </label>
                        <input
                            type="text"
                            value="<?php echo ucfirst($userRole); ?>"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                            disabled
                        >
                    </div>

                    <?php if ($roleDetails): ?>
                        <!-- Student-specific Information -->
                        <?php if ($userRole === 'student'): ?>
                            <div class="pt-4 border-t border-gray-200">
                                <h4 class="text-md font-semibold text-gray-900 mb-4">Academic Information</h4>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Roll Number</label>
                                        <input
                                            type="text"
                                            value="<?php echo sanitize($roleDetails['roll_number'] ?? 'N/A'); ?>"
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                                            disabled
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Joining Date</label>
                                        <input
                                            type="text"
                                            value="<?php echo $roleDetails['joining_date'] ? date('d M Y', strtotime($roleDetails['joining_date'])) : 'N/A'; ?>"
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                                            disabled
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Faculty Guide</label>
                                        <input
                                            type="text"
                                            value="<?php echo sanitize($roleDetails['guide_name'] ?? 'Not assigned'); ?>"
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                                            disabled
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">TA Coordinator</label>
                                        <input
                                            type="text"
                                            value="<?php echo sanitize($roleDetails['ta_coordinator_name'] ?? 'Not assigned'); ?>"
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-50"
                                            disabled
                                        >
                                    </div>
                                </div>

                                <div class="mt-4 p-4 bg-blue-50 border-l-4 border-blue-400 rounded">
                                    <p class="text-sm text-blue-900">
                                        <i class="fas fa-info-circle mr-1"></i>
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
                    <div class="flex justify-end pt-4">
                        <button
                            type="submit"
                            class="px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                        >
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
