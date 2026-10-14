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
    <link rel="stylesheet" href="<?php echo asset_url('public/vendor/tailwind/tailwind.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('public/css/custom.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

<div class="flex min-h-screen w-full">

    <!-- Brand panel (large screens only; phones get the mark above the card) -->
    <aside class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-violet-600 p-12 text-white lg:flex xl:p-16">
        <!-- Decorative grid and glows -->
        <svg class="pointer-events-none absolute inset-0 h-full w-full text-white opacity-[0.07]" aria-hidden="true">
            <defs>
                <pattern id="brand-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                    <path d="M40 0H0V40" fill="none" stroke="currentColor" stroke-width="1"></path>
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#brand-grid)"></rect>
        </svg>
        <div class="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-white/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-40 -left-24 h-[28rem] w-[28rem] rounded-full bg-violet-400/30 blur-3xl" aria-hidden="true"></div>

        <div class="relative">
            <span class="inline-flex rounded-2xl bg-white px-4 py-3 shadow-lg shadow-blue-900/20">
                <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay" width="463" height="200" class="h-14 w-auto">
            </span>
        </div>

        <div class="relative max-w-md">
            <p class="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl"><?php echo SITE_NAME; ?></p>
            <p class="mt-4 text-base text-blue-100">You&rsquo;ve been invited. Set a password to activate your account &mdash; it takes less than a minute.</p>

            <ol class="mt-10 space-y-6">
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/20">
                        <i class="fas fa-envelope-open-text"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Invitation received</p>
                        <p class="mt-0.5 text-sm text-blue-100">The office created your account and sent you this link.</p>
                    </div>
                </li>
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20 ring-1 ring-inset ring-white/40">
                        <i class="fas fa-key"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Choose a password</p>
                        <p class="mt-0.5 text-sm text-blue-100">Pick something strong that you don&rsquo;t use anywhere else.</p>
                    </div>
                </li>
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/20">
                        <i class="fas fa-sign-in-alt"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Sign in</p>
                        <p class="mt-0.5 text-sm text-blue-100">Use your IITB email address and your new password.</p>
                    </div>
                </li>
            </ol>
        </div>

        <p class="relative text-sm text-blue-100/80">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
    </aside>

    <!-- Registration -->
    <main class="relative flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6 lg:px-12">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-blue-100/50 to-transparent lg:hidden" aria-hidden="true"></div>

        <div class="relative w-full max-w-md">
            <!-- Brand mark (below lg, where the panel is hidden) -->
            <div class="mb-8 text-center lg:hidden">
                <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay" width="463" height="200" class="mx-auto h-16 w-auto">
                <p class="mt-4 text-xl font-semibold tracking-tight text-gray-900"><?php echo SITE_NAME; ?></p>
                <p class="mt-1 text-sm text-gray-500">Complete your registration</p>
            </div>

            <!-- Signup Card -->
            <div class="card p-6 shadow-lg sm:p-8">

                <?php if ($success): ?>
                    <div class="py-4 text-center">
                        <span class="mx-auto inline-flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-3xl text-emerald-500 ring-8 ring-emerald-50/60">
                            <i class="fas fa-check-circle"></i>
                        </span>
                        <h1 class="mt-6 text-2xl font-semibold tracking-tight text-gray-900">Account created!</h1>
                        <p class="mt-2 text-sm text-gray-600">Your account has been successfully created.</p>
                        <p class="mt-6 inline-flex items-center gap-2 text-sm text-gray-500">
                            <i class="fas fa-circle-notch fa-spin text-brand-500"></i> Redirecting to login page...
                        </p>
                        <div class="mt-6">
                            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary w-full">
                                <i class="fas fa-sign-in-alt"></i> Go to sign in
                            </a>
                        </div>
                    </div>

                <?php elseif ($error && !$userData): ?>
                    <div class="py-4 text-center">
                        <span class="mx-auto inline-flex h-16 w-16 items-center justify-center rounded-full bg-rose-50 text-3xl text-rose-500 ring-8 ring-rose-50/60">
                            <i class="fas fa-exclamation-triangle"></i>
                        </span>
                        <h1 class="mt-6 text-2xl font-semibold tracking-tight text-gray-900">Invalid Token</h1>
                        <p class="mt-2 text-sm text-gray-600"><?php echo sanitize($error); ?></p>
                        <div class="mt-8 flex flex-col gap-2 sm:flex-row sm:justify-center">
                            <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="btn btn-primary">
                                <i class="fas fa-envelope"></i> Contact Administrator
                            </a>
                            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-secondary">
                                Back to sign in
                            </a>
                        </div>
                    </div>

                <?php else: ?>
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Set your password</h1>
                    <p class="mt-1.5 text-sm text-gray-500">Welcome, <strong class="font-semibold text-gray-900"><?php echo sanitize($userData['name']); ?></strong>! Choose a password to activate your account.</p>
                    <p class="mt-2 text-sm text-gray-500">This password is for this portal only &mdash; it is separate from your IITB LDAP / webmail password.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-error mt-6" role="alert">
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <p><?php echo sanitize($error); ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- User Info -->
                    <dl class="mt-6 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-gray-50/70 text-sm">
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-gray-500">Name</dt>
                            <dd class="truncate font-medium text-gray-900"><?php echo sanitize($userData['name']); ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-gray-500">Sign-in email</dt>
                            <dd class="truncate font-medium text-gray-900"><?php echo sanitize($userData['email']); ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-gray-500">Role</dt>
                            <dd><span class="badge badge-brand capitalize"><?php echo sanitize($userData['role']); ?></span></dd>
                        </div>
                    </dl>

                    <form method="POST" action="" class="mt-6 space-y-5">
                        <!-- Password Field -->
                        <div>
                            <label for="password" class="form-label">
                                Password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-input py-3 pl-10 pr-11"
                                    placeholder="Enter your password"
                                    autocomplete="new-password"
                                    required
                                    autofocus
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('password')"
                                    class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3.5 text-gray-400 transition hover:text-gray-600 focus:outline-none focus-visible:text-brand-600"
                                    title="Show or hide password"
                                    aria-label="Show or hide password"
                                >
                                    <i class="fas fa-eye" id="password-icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Confirm Password Field -->
                        <div>
                            <label for="confirm_password" class="form-label">
                                Confirm password <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    class="form-input py-3 pl-10 pr-11"
                                    placeholder="Confirm your password"
                                    autocomplete="new-password"
                                    required
                                >
                                <button
                                    type="button"
                                    onclick="togglePassword('confirm_password')"
                                    class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3.5 text-gray-400 transition hover:text-gray-600 focus:outline-none focus-visible:text-brand-600"
                                    title="Show or hide password"
                                    aria-label="Show or hide password"
                                >
                                    <i class="fas fa-eye" id="confirm_password-icon"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Requirements -->
                        <div class="rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3.5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Password requirements</p>
                            <ul class="mt-2 space-y-1.5 text-xs text-brand-900">
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-brand-500"></i>At least 8 characters long</li>
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-brand-500"></i>At least one uppercase letter</li>
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-brand-500"></i>At least one lowercase letter</li>
                                <li class="flex items-center gap-2"><i class="fas fa-check-circle text-brand-500"></i>At least one number</li>
                            </ul>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-primary btn-lg w-full">
                            <i class="fas fa-check"></i>Create account
                        </button>
                    </form>

                    <div class="mt-8 border-t border-gray-100 pt-6 text-center">
                        <p class="text-sm text-gray-600">
                            Already set a password? <a href="<?php echo BASE_URL; ?>/login.php" class="font-semibold text-blue-600 hover:text-blue-700">Sign in</a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer (the brand panel carries it on large screens) -->
            <p class="mt-8 text-center text-sm text-gray-500 lg:hidden">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </main>
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
