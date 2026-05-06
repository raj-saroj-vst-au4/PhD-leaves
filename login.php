<?php
/**
 * Login Page
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
$timeout = isset($_GET['timeout']) ? true : false;

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $captchaInput = trim($_POST['captcha'] ?? '');
    $expectedCaptcha = $_SESSION['captcha_answer'] ?? null;

    // Captcha must be verified BEFORE we spend cycles on password_verify.
    // Also clear it immediately to prevent replay of the same answer.
    unset($_SESSION['captcha_answer']);

    if ($expectedCaptcha === null || $captchaInput === '' || (int)$captchaInput !== (int)$expectedCaptcha) {
        $error = 'Incorrect answer to the math question. Please try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!validateDomain($email)) {
        $error = 'Please use your @' . ALLOWED_DOMAIN . ' email address';
    } else {
        try {
            $user = new User();

            if ($user->authenticate($email, $password)) {
                $userData = $user->getByEmail($email);

                // Get student ID if user is a student
                $studentId = null;
                if ($userData['role'] === 'student') {
                    $studentDetails = $user->getStudentDetails($userData['id']);
                    $studentId = $studentDetails['student_id'] ?? null;
                }

                // Create session
                loginUser($userData, $studentId);

                // Redirect to appropriate dashboard
                header('Location: ' . getRoleHomePage($userData['role']));
                exit();
            } else {
                $error = 'Invalid email or password';
            }
        } catch (Exception $e) {
            logError("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// Generate a fresh math captcha for this render. Done AFTER POST handling so
// every submit — success, failure, or fresh visit — sees a new question.
$captchaA = random_int(1, 9);
$captchaB = random_int(1, 9);
if (random_int(0, 1) === 0) {
    $captchaOp = '+';
    $_SESSION['captcha_answer'] = $captchaA + $captchaB;
} else {
    // Force a >= b so the subtraction result stays non-negative.
    if ($captchaB > $captchaA) {
        [$captchaA, $captchaB] = [$captchaB, $captchaA];
    }
    $captchaOp = '−';
    $_SESSION['captcha_answer'] = $captchaA - $captchaB;
}
$captchaQuestion = "$captchaA $captchaOp $captchaB";

$pageTitle = 'Login';
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

        <!-- Login Card -->
        <div class="bg-white rounded-lg shadow-xl p-8">
            <h2 class="text-2xl font-semibold text-gray-900 mb-6">Sign In</h2>

            <?php if ($timeout): ?>
                <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-clock mr-2"></i>Your session has expired. Please login again.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo sanitize($error); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <!-- Email Field -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                        IITB Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo sanitize($_POST['email'] ?? ''); ?>"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="your.name@iitb.ac.in"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <!-- Password Field -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                        Password
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
                            placeholder="Enter your password"
                            required
                        >
                    </div>
                </div>

                <!-- Math Captcha -->
                <div>
                    <label for="captcha" class="block text-sm font-medium text-gray-700 mb-2">
                        Security check: what is <span class="font-semibold"><?php echo sanitize($captchaQuestion); ?></span>?
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-calculator text-gray-400"></i>
                        </div>
                        <input
                            type="text"
                            inputmode="numeric"
                            pattern="-?[0-9]*"
                            id="captcha"
                            name="captcha"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Answer"
                            autocomplete="off"
                            required
                        >
                    </div>
                </div>

                <!-- Remember Me and Forgot Password -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input
                            id="remember"
                            name="remember"
                            type="checkbox"
                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        >
                        <label for="remember" class="ml-2 block text-sm text-gray-700">
                            Remember me
                        </label>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="text-sm font-medium text-blue-600 hover:text-blue-500">
                        Forgot password?
                    </a>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                </button>
            </form>

            <!-- Help Text -->
            <div class="mt-6 text-center">
                <p class="text-sm text-gray-600">
                    New user? Check your email for an invitation link.
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    Need help? Contact <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="text-blue-600 hover:text-blue-500"><?php echo ADMIN_EMAIL; ?></a>
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
