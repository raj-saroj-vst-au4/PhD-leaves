<?php
/**
 * Change Password Page
 * Allows users to change their password
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// auth.php has already opened the session with the hardened cookie settings.
startAppSession();

// Full check (idle timeout included) BEFORE the POST handler below: header.php
// enforces it too, but only after this page has already acted on the form.
requireLogin();

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

                    // Every OTHER session on this account now fails its password
                    // fingerprint check and is signed out; keep this one, under a
                    // fresh session ID.
                    $_SESSION['pw_fp'] = passwordFingerprint($new_password_hash);
                    session_regenerate_id(true);

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
            <div class="page-header">
                <div>
                    <p class="page-eyebrow">Account</p>
                    <h1 class="page-title">Change Password</h1>
                    <p class="page-subtitle">Update your account password</p>
                </div>
            </div>

            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="alert alert-success mb-6" role="status">
                <i class="fas fa-check-circle mt-0.5"></i>
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-error mb-6" role="alert">
                <i class="fas fa-exclamation-circle mt-0.5"></i>
                <div>
                    <p class="font-semibold">Please fix the following errors:</p>
                    <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change Password Form -->
            <div class="card">
                <div class="card-header justify-start">
                    <span class="icon-chip chip-brand"><i class="fas fa-key"></i></span>
                    <div>
                        <h2 class="card-title">Set a new password</h2>
                        <p class="card-subtitle">You stay signed in here; other sessions on your account are signed out.</p>
                    </div>
                </div>
                <form method="POST" action="" id="changePasswordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="card-body space-y-5">
                        <!-- Current Password -->
                        <div>
                            <label for="current_password" class="form-label">
                                Current Password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    required
                                    autocomplete="current-password"
                                    class="form-input pl-10 pr-12"
                                    placeholder="Enter your current password"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('current_password')"
                                    class="btn-icon absolute right-1.5 top-1/2 -translate-y-1/2"
                                    title="Show or hide password"
                                    aria-label="Show or hide password"
                                >
                                    <i class="fas fa-eye" id="current_password_icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- New Password -->
                        <div>
                            <label for="new_password" class="form-label">
                                New Password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                    class="form-input pl-10 pr-12"
                                    placeholder="Enter a new password"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('new_password')"
                                    class="btn-icon absolute right-1.5 top-1/2 -translate-y-1/2"
                                    title="Show or hide password"
                                    aria-label="Show or hide password"
                                >
                                    <i class="fas fa-eye" id="new_password_icon"></i>
                                </button>
                            </div>
                            <p class="form-help">Password must be at least 8 characters long</p>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="confirm_password" class="form-label">
                                Confirm New Password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    required
                                    minlength="8"
                                    autocomplete="new-password"
                                    class="form-input pl-10 pr-12"
                                    placeholder="Re-enter new password"
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('confirm_password')"
                                    class="btn-icon absolute right-1.5 top-1/2 -translate-y-1/2"
                                    title="Show or hide password"
                                    aria-label="Show or hide password"
                                >
                                    <i class="fas fa-eye" id="confirm_password_icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Strength Indicator -->
                        <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Password strength</span>
                                <span id="strength_text" class="text-sm text-gray-500">Not evaluated</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-gray-200">
                                <div id="strength_bar" class="h-full w-0 transition-all duration-300"></div>
                            </div>
                            <ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2" id="strength_checklist">
                                <li class="flex items-center gap-2 text-gray-500" data-check="length"><i class="fas fa-check-circle text-gray-300"></i>At least 8 characters</li>
                                <li class="flex items-center gap-2 text-gray-500" data-check="case"><i class="fas fa-check-circle text-gray-300"></i>Upper and lower case</li>
                                <li class="flex items-center gap-2 text-gray-500" data-check="number"><i class="fas fa-check-circle text-gray-300"></i>A number</li>
                                <li class="flex items-center gap-2 text-gray-500" data-check="symbol"><i class="fas fa-check-circle text-gray-300"></i>A symbol</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="card-footer flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <a
                            href="<?php echo sanitize(getRoleHomePage(getCurrentUserRole())); ?>"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>
                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-key"></i>Change Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Security Tips -->
            <div class="card card-body mt-6">
                <div class="flex items-start gap-4">
                    <span class="icon-chip-sm chip-emerald"><i class="fas fa-shield-alt"></i></span>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Password Security Tips</h3>
                        <ul class="mt-3 space-y-2 text-sm text-gray-600">
                            <li class="flex items-start gap-2"><i class="fas fa-check mt-1 text-xs text-emerald-500"></i>Use a mix of uppercase, lowercase, numbers, and special characters</li>
                            <li class="flex items-start gap-2"><i class="fas fa-check mt-1 text-xs text-emerald-500"></i>Avoid using personal information or common words</li>
                            <li class="flex items-start gap-2"><i class="fas fa-check mt-1 text-xs text-emerald-500"></i>Don't reuse passwords from other accounts</li>
                            <li class="flex items-start gap-2"><i class="fas fa-check mt-1 text-xs text-emerald-500"></i>Change your password regularly</li>
                            <li class="flex items-start gap-2"><i class="fas fa-check mt-1 text-xs text-emerald-500"></i>Never share your password with anyone</li>
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

            // Tick off the checklist under the meter (same tests as the score above).
            const checks = {
                length: password.length >= 8,
                case: /[a-z]/.test(password) && /[A-Z]/.test(password),
                number: /[0-9]/.test(password),
                symbol: /[^a-zA-Z0-9]/.test(password)
            };
            document.querySelectorAll('#strength_checklist [data-check]').forEach(function(item) {
                const met = checks[item.dataset.check];
                item.classList.toggle('text-emerald-700', met);
                item.classList.toggle('text-gray-500', !met);
                const mark = item.querySelector('i');
                mark.classList.toggle('text-emerald-500', met);
                mark.classList.toggle('text-gray-300', !met);
            });
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
