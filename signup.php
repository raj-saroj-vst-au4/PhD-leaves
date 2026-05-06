<?php
/**
 * Signup Page - Complete registration with invitation token
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
$success = false;
$token = sanitize($_GET['token'] ?? '');
$userData = null;

// Verify token
if (empty($token)) {
    $error = 'Invalid or missing invitation token';
} else {
    try {
        $user = new User();
        $userData = $user->getByToken($token);

        if (!$userData) {
            $error = 'Invalid or expired invitation token. Please contact administrator.';
        }
    } catch (Exception $e) {
        logError("Token verification error: " . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userData) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirmPassword)) {
        $error = 'Please enter and confirm your password';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'Password must contain at least one uppercase letter';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'Password must contain at least one lowercase letter';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'Password must contain at least one number';
    } else {
        try {
            $user = new User();

            if ($user->setPassword($userData['id'], $password)) {
                $success = true;
                setFlashMessage('success', 'Account created successfully! Please login with your credentials.');

                // Redirect to login after 2 seconds
                header('refresh:2;url=' . BASE_URL . '/login.php');
            } else {
                $error = 'Failed to create account. Please try again.';
            }
        } catch (Exception $e) {
            logError("Signup error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

$pageTitle = 'Complete Registration';
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
                <i class="fas fa-user-plus text-4xl text-blue-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo SITE_NAME; ?></h1>
            <p class="text-gray-600">Complete Your Registration</p>
        </div>

        <!-- Signup Card -->
        <div class="bg-white rounded-lg shadow-xl p-8">

            <?php if ($success): ?>
                <div class="text-center">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-6xl text-green-500"></i>
                    </div>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Account Created!</h2>
                    <p class="text-gray-600 mb-4">Your account has been successfully created.</p>
                    <p class="text-sm text-gray-500">Redirecting to login page...</p>
                </div>

            <?php elseif ($error && !$userData): ?>
                <div class="text-center">
                    <div class="mb-4">
                        <i class="fas fa-exclamation-triangle text-6xl text-red-500"></i>
                    </div>
                    <h2 class="text-2xl font-semibold text-gray-900 mb-4">Invalid Token</h2>
                    <p class="text-gray-600 mb-4"><?php echo sanitize($error); ?></p>
                    <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="text-blue-600 hover:text-blue-500">
                        Contact Administrator
                    </a>
                </div>

            <?php else: ?>
                <h2 class="text-2xl font-semibold text-gray-900 mb-2">Set Your Password</h2>
                <p class="text-sm text-gray-600 mb-6">Welcome, <strong><?php echo sanitize($userData['name']); ?></strong>!</p>

                <?php if ($error): ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                        <p class="text-sm text-red-800">
                            <i class="fas fa-exclamation-circle mr-2"></i><?php echo sanitize($error); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- User Info -->
                <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Name:</span>
                            <span class="font-medium"><?php echo sanitize($userData['name']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Email:</span>
                            <span class="font-medium"><?php echo sanitize($userData['email']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Role:</span>
                            <span class="font-medium capitalize"><?php echo sanitize($userData['role']); ?></span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" class="space-y-6">
                    <!-- Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            Password <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter your password"
                                required
                                autofocus
                            >
                            <button
                                type="button"
                                onclick="togglePassword('password')"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm Password Field -->
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                            Confirm Password <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="block w-full pl-10 pr-10 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Confirm your password"
                                required
                            >
                            <button
                                type="button"
                                onclick="togglePassword('confirm_password')"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="confirm_password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Requirements -->
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm font-medium text-blue-900 mb-2">Password Requirements:</p>
                        <ul class="text-xs text-blue-800 space-y-1">
                            <li><i class="fas fa-check text-blue-600 mr-2"></i>At least 8 characters long</li>
                            <li><i class="fas fa-check text-blue-600 mr-2"></i>At least one uppercase letter</li>
                            <li><i class="fas fa-check text-blue-600 mr-2"></i>At least one lowercase letter</li>
                            <li><i class="fas fa-check text-blue-600 mr-2"></i>At least one number</li>
                        </ul>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        <i class="fas fa-check mr-2"></i>Create Account
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-icon');

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
    </script>

</body>
</html>
