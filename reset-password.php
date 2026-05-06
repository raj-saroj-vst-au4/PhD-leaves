<?php
/**
 * Reset Password Page
 * Allows users to reset their password using a valid reset token
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/tailwind/tailwind.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center px-4">

    <div class="max-w-md w-full">
        <!-- Logo and Title -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-white rounded-full shadow-lg mb-4">
                <i class="fas fa-graduation-cap text-4xl text-blue-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo SITE_NAME; ?></h1>
            <p class="text-gray-600">IIT Bombay</p>
        </div>

        <!-- Reset Password Card -->
        <div class="bg-white rounded-lg shadow-xl p-8">
            <h2 class="text-2xl font-semibold text-gray-900 mb-2">Create New Password</h2>

            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo sanitize($error); ?>
                    </p>
                </div>
                <?php if (!$userData): ?>
                    <div class="mt-6 text-center">
                        <p class="text-sm text-gray-600 mb-4">
                            Request a new password reset link:
                        </p>
                        <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="inline-block text-blue-600 hover:text-blue-500 font-medium">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Forgot Password
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-800">
                        <i class="fas fa-check-circle mr-2"></i><?php echo sanitize($success); ?>
                    </p>
                </div>
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600 mb-4">
                        Redirecting to login page in 3 seconds...
                    </p>
                    <a href="<?php echo BASE_URL; ?>/login.php" class="inline-block text-blue-600 hover:text-blue-500 font-medium">
                        <i class="fas fa-sign-in-alt mr-2"></i>Log in now
                    </a>
                </div>
            <?php elseif ($userData): ?>
                <form method="POST" action="" class="space-y-6">
                    <!-- New Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            New Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="At least 8 characters"
                                required
                                autofocus
                            >
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>Must contain uppercase, lowercase, and numbers
                        </p>
                    </div>

                    <!-- Confirm Password Field -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                            Confirm Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Re-enter your password"
                                required
                            >
                        </div>
                    </div>

                    <!-- Password Requirements -->
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm font-medium text-blue-900 mb-2">Password Requirements:</p>
                        <ul class="text-xs text-blue-800 space-y-1">
                            <li><i class="fas fa-check mr-2"></i>At least 8 characters long</li>
                            <li><i class="fas fa-check mr-2"></i>One uppercase letter (A-Z)</li>
                            <li><i class="fas fa-check mr-2"></i>One lowercase letter (a-z)</li>
                            <li><i class="fas fa-check mr-2"></i>One number (0-9)</li>
                        </ul>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        <i class="fas fa-sync-alt mr-2"></i>Reset Password
                    </button>
                </form>
            <?php endif; ?>

            <!-- Footer Links -->
            <?php if (!$success): ?>
                <div class="mt-6 pt-6 border-t border-gray-200 text-center">
                    <p class="text-xs text-gray-500">
                        <a href="<?php echo BASE_URL; ?>/login.php" class="text-blue-600 hover:text-blue-500">
                            Back to Login
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </div>

</body>
</html>
