<?php
/**
 * Change Password Page
 * Allows users to change their password
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Block password changes while impersonating — changing another user's
// password while wearing their identity would be indistinguishable from
// account takeover.
blockIfImpersonating('Password changes are not permitted while impersonating another user. Return to your admin account first.');

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate inputs
        if (empty($current_password)) {
            $errors[] = 'Current password is required.';
        }

        if (empty($new_password)) {
            $errors[] = 'New password is required.';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        }

        if ($new_password !== $confirm_password) {
            $errors[] = 'New password and confirm password do not match.';
        }

        if (empty($errors)) {
            try {
                $pdo = getDBConnection();

                // Get current user's password hash
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();

                if (!$user) {
                    $errors[] = 'User not found.';
                } elseif (!password_verify($current_password, $user['password'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    // Update password
                    $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_password_hash, $_SESSION['user_id']]);

                    // Log the password change
                    logSystemAction(
                        $_SESSION['user_id'],
                        'PASSWORD_CHANGED',
                        'auth',
                        'User changed their password'
                    );

                    $success = 'Password changed successfully!';

                    // Clear form
                    $_POST = [];
                }
            } catch (PDOException $e) {
                logError("Password change error: " . $e->getMessage());
                $errors[] = 'An error occurred while changing password. Please try again.';
            }
        }
    }
}

// Generate new CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$pageTitle = 'Change Password';
include __DIR__ . '/includes/header.php';
?>

        <div class="max-w-2xl mx-auto">
            <!-- Page Header -->
            <div class="mb-6">
                <h1 class="text-3xl font-bold text-gray-900">Change Password</h1>
                <p class="text-gray-600 mt-2">Update your account password</p>
            </div>

            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-green-500"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-green-700"><?php echo htmlspecialchars($success); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Please fix the following errors:</h3>
                        <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change Password Form -->
            <div class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="px-6 py-8">
                    <form method="POST" action="" id="changePasswordForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- Current Password -->
                        <div class="mb-6">
                            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Current Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent pr-10"
                                    placeholder="Enter your current password"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('current_password')"
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                >
                                    <i class="fas fa-eye" id="current_password_icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- New Password -->
                        <div class="mb-6">
                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">
                                New Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    required
                                    minlength="8"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent pr-10"
                                    placeholder="Enter new password (min 8 characters)"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('new_password')"
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                >
                                    <i class="fas fa-eye" id="new_password_icon"></i>
                                </button>
                            </div>
                            <p class="mt-2 text-sm text-gray-500">
                                <i class="fas fa-info-circle"></i> Password must be at least 8 characters long
                            </p>
                        </div>

                        <!-- Confirm Password -->
                        <div class="mb-6">
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Confirm New Password <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    required
                                    minlength="8"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent pr-10"
                                    placeholder="Re-enter new password"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('confirm_password')"
                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                                >
                                    <i class="fas fa-eye" id="confirm_password_icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Strength Indicator -->
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm font-medium text-gray-700">Password Strength</span>
                                <span id="strength_text" class="text-sm text-gray-500">Not evaluated</span>
                            </div>
                            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                <div id="strength_bar" class="h-full w-0 transition-all duration-300"></div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex items-center justify-between pt-4 border-t border-gray-200">
                            <a
                                href="<?php echo BASE_URL . '/' . $_SESSION['role']; ?>/dashboard.php"
                                class="text-gray-600 hover:text-gray-900 font-medium"
                            >
                                <i class="fas fa-arrow-left mr-2"></i>Cancel
                            </a>
                            <button
                                type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition duration-150 ease-in-out"
                            >
                                <i class="fas fa-key mr-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Security Tips -->
            <div class="mt-6 bg-blue-50 border-l-4 border-blue-500 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-shield-alt text-blue-500"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-800">Password Security Tips</h3>
                        <ul class="mt-2 text-sm text-blue-700 list-disc list-inside space-y-1">
                            <li>Use a mix of uppercase, lowercase, numbers, and special characters</li>
                            <li>Avoid using personal information or common words</li>
                            <li>Don't reuse passwords from other accounts</li>
                            <li>Change your password regularly</li>
                            <li>Never share your password with anyone</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');

            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strength_bar');
            const strengthText = document.getElementById('strength_text');

            let strength = 0;
            let strengthLabel = 'Weak';
            let strengthColor = 'bg-red-500';

            // Length check
            if (password.length >= 8) strength += 20;
            if (password.length >= 12) strength += 10;

            // Character type checks
            if (/[a-z]/.test(password)) strength += 20;
            if (/[A-Z]/.test(password)) strength += 20;
            if (/[0-9]/.test(password)) strength += 15;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 15;

            // Determine strength level
            if (strength >= 80) {
                strengthLabel = 'Strong';
                strengthColor = 'bg-green-500';
            } else if (strength >= 60) {
                strengthLabel = 'Good';
                strengthColor = 'bg-yellow-500';
            } else if (strength >= 40) {
                strengthLabel = 'Fair';
                strengthColor = 'bg-orange-500';
            } else if (password.length > 0) {
                strengthLabel = 'Weak';
                strengthColor = 'bg-red-500';
            } else {
                strengthLabel = 'Not evaluated';
                strength = 0;
            }

            strengthBar.style.width = strength + '%';
            strengthBar.className = 'h-full transition-all duration-300 ' + strengthColor;
            strengthText.textContent = strengthLabel;
            strengthText.className = 'text-sm font-medium ' + strengthColor.replace('bg-', 'text-');
        });

        // Confirm password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;

            if (confirmPassword.length > 0) {
                if (newPassword === confirmPassword) {
                    this.classList.remove('border-red-500');
                    this.classList.add('border-green-500');
                } else {
                    this.classList.remove('border-green-500');
                    this.classList.add('border-red-500');
                }
            } else {
                this.classList.remove('border-red-500', 'border-green-500');
            }
        });

        // Form validation
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New password and confirm password do not match!');
                return false;
            }

            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
        });
    </script>

<?php include __DIR__ . '/includes/footer.php'; ?>
