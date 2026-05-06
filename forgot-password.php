<?php
/**
 * Forgot Password Page
 * Allows users to request password reset via email
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/includes/EmailService.php';

// If already logged in, redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . getRoleHomePage(getCurrentUserRole()));
    exit();
}

$error = '';
$success = '';
$email = '';

// Handle forgot password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');

    // Validate input
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!validateDomain($email)) {
        $error = 'Please use your @' . ALLOWED_DOMAIN . ' email address';
    } else {
        try {
            $user = new User();

            // Check if user exists and is active
            $userData = $user->getByEmail($email);

            if ($userData && $userData['is_active']) {
                // Generate password reset token
                $result = $user->generatePasswordResetToken($userData['id']);

                if ($result['success']) {
                    // Send password reset email
                    $emailService = new EmailService();
                    $emailSent = $emailService->sendPasswordReset(
                        $email,
                        $userData['name'],
                        $result['token']
                    );

                    if ($emailSent) {
                        $success = 'Password reset link has been sent to your email. Please check your inbox (and spam folder) for the link.';
                        // Clear the form
                        $email = '';
                    } else {
                        $error = 'Failed to send reset link. Please try again later.';
                    }
                } else {
                    $error = 'An error occurred. Please try again.';
                }
            } else {
                // For security, show generic message even if user doesn't exist
                $success = 'If an account exists with this email, a password reset link will be sent to it.';
                $email = '';
            }
        } catch (Exception $e) {
            logError("Forgot password error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

$pageTitle = 'Forgot Password';
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

        <!-- Forgot Password Card -->
        <div class="bg-white rounded-lg shadow-xl p-8">
            <h2 class="text-2xl font-semibold text-gray-900 mb-2">Reset Password</h2>
            <p class="text-gray-600 text-sm mb-6">Enter your email address and we'll send you a link to reset your password.</p>

            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo sanitize($error); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-800">
                        <i class="fas fa-check-circle mr-2"></i><?php echo sanitize($success); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo sanitize($email); ?>"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="your.name@iitb.ac.in"
                            required
                            autofocus
                        >
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>Use your @iitb.ac.in email address
                    </p>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    <i class="fas fa-paper-plane mr-2"></i>Send Reset Link
                </button>
            </form>

            <!-- Back to Login -->
            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    Remember your password?
                    <a href="<?php echo BASE_URL; ?>/login.php" class="text-blue-600 hover:text-blue-500 font-medium">
                        Sign in
                    </a>
                </p>
            </div>

            <!-- Help Text -->
            <div class="mt-6 pt-6 border-t border-gray-200">
                <p class="text-xs text-gray-500 text-center">
                    <i class="fas fa-shield-alt mr-1 text-blue-600"></i>
                    Your account security is important. The reset link will expire in <?php echo TOKEN_EXPIRY_HOURS; ?> hours.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </div>

</body>
</html>
