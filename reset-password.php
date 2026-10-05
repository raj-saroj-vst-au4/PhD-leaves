<?php
/**
 * Reset Password Page
 * Allows users to reset their password using a valid reset token
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/classes/User.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . getRoleHomePage(getCurrentUserRole()));
    exit();
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$userData = null;

// Validate token
if (empty($token)) {
    $error = 'Password reset link is missing. Please request a new one.';
} else {
    try {
        $user = new User();
        $userData = $user->validatePasswordResetToken($token);

        if (!$userData) {
            $error = 'This password reset link is invalid or has expired. Please request a new one.';
        }
    } catch (Exception $e) {
        logError("Reset password validation error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userData) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate passwords
    if (empty($password) || empty($confirmPassword)) {
        $error = 'Please enter and confirm your password';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (!validatePassword($password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, and one number';
    } else {
        try {
            $user = new User();
            $result = $user->resetPassword($userData['id'], $password, $token);

            if ($result['success']) {
                // Whoever locked this address out by guessing, the owner has now
                // proved control of the mailbox: let them sign in straight away.
                // (Their other open sessions end on their own: the password
                // fingerprint no longer matches, see validateSessionIdentity.)
                authAttemptClear('login_fail', authAttemptEmailKey($userData['email']));
                $success = 'Your password has been reset successfully. You can now log in with your new password.';
                $userData = null; // Clear token validation

                // Redirect to login after 3 seconds
                header('Refresh: 3; URL=' . BASE_URL . '/login.php');
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        } catch (Exception $e) {
            logError("Reset password error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

$pageTitle = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('public/vendor/tailwind/tailwind.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('public/css/custom.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

<main class="relative flex min-h-screen flex-col items-center justify-center px-4 py-10 sm:px-6">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-72 bg-gradient-to-b from-blue-100/60 to-transparent" aria-hidden="true"></div>

    <div class="relative w-full max-w-md">
        <!-- Brand mark -->
        <a href="<?php echo BASE_URL; ?>/login.php" class="mb-8 block text-center">
            <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-700 via-blue-600 to-violet-600 text-2xl text-white shadow-lg">
                <i class="fas fa-graduation-cap"></i>
            </span>
            <span class="mt-4 block text-xl font-semibold tracking-tight text-gray-900"><?php echo SITE_NAME; ?></span>
            <span class="mt-1 block text-sm text-gray-500">SJM School of Management, IIT Bombay</span>
        </a>

        <!-- Reset Password Card -->
        <div class="card p-6 shadow-lg sm:p-8">
            <span class="icon-chip chip-brand"><i class="fas fa-lock"></i></span>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-gray-900">Create new password</h1>
            <?php if ($userData): ?>
                <p class="mt-1.5 text-sm text-gray-500">Choose a strong password you don't use anywhere else.</p>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error mt-6" role="alert">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <p><?php echo sanitize($error); ?></p>
                </div>
                <?php if (!$userData): ?>
                    <div class="mt-6 text-center">
                        <p class="mb-3 text-sm text-gray-600">
                            Request a new password reset link:
                        </p>
                        <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>Back to Forgot Password
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success mt-6" role="status">
                    <i class="fas fa-check-circle mt-0.5"></i>
                    <p><?php echo sanitize($success); ?></p>
                </div>
                <div class="mt-6 text-center">
                    <p class="mb-3 text-sm text-gray-600">
                        Redirecting to login page in 3 seconds...
                    </p>
                    <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary w-full">
                        <i class="fas fa-sign-in-alt"></i>Log in now
                    </a>
                </div>
            <?php elseif ($userData): ?>
                <form method="POST" action="" class="mt-6 space-y-5">
                    <!-- New Password Field -->
                    <div>
                        <label for="password" class="form-label">New password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-input py-3 pl-10"
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <!-- Confirm Password Field -->
                    <div>
                        <label for="confirm_password" class="form-label">Confirm password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="form-input py-3 pl-10"
                                placeholder="Re-enter your password"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                    </div>

                    <!-- Password Requirements -->
                    <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Password requirements</p>
                        <ul class="mt-3 grid gap-2 text-sm text-gray-700 sm:grid-cols-2">
                            <li class="flex items-center gap-2"><i class="fas fa-check-circle text-gray-300"></i>At least 8 characters</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check-circle text-gray-300"></i>One uppercase letter (A-Z)</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check-circle text-gray-300"></i>One lowercase letter (a-z)</li>
                            <li class="flex items-center gap-2"><i class="fas fa-check-circle text-gray-300"></i>One number (0-9)</li>
                        </ul>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-sync-alt"></i>Reset password
                    </button>
                </form>
            <?php endif; ?>

            <!-- Footer Links -->
            <?php if (!$success): ?>
                <div class="mt-6 border-t border-gray-100 pt-6 text-center">
                    <a href="<?php echo BASE_URL; ?>/login.php" class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-blue-700">
                        <i class="fas fa-arrow-left text-xs"></i>Back to Login
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <p class="mt-8 text-center text-sm text-gray-500">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
    </div>
</main>

</body>
</html>
