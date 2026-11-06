<?php
/**
 * Login Page
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/captcha.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/includes/attendance_today.php';

// If already logged in, redirect to dashboard (students: Attendance first while it is due)
if (isLoggedIn()) {
    header('Location: ' . (getCurrentUserRole() === 'student'
        ? studentLandingPage(getCurrentStudentId())
        : getRoleHomePage(getCurrentUserRole())));
    exit();
}

$error = '';
$timeout = isset($_GET['timeout']) ? true : false;
$useCaptcha = captchaEnabled();

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $clientIp = getClientIp();

    // Nothing is recorded against any limit until the form is proven to be our
    // own (CSRF). Otherwise any web page opened on the office WiFi could fire
    // blank cross-site POSTs and lock the whole office's shared IP out of
    // sign-in.
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'The sign-in form had expired. Please try again.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Please enter both email and password';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!validateDomain($email)) {
        $error = 'Please use your @' . ALLOWED_DOMAIN . ' email address';
    } else {
        try {
            $user = new User();

            // A name-based address (roshni.tiwari@) signs in to the account it
            // belongs to (25d1521@), see User::resolveAccount. The password is
            // checked against that account and the failure limit counts against
            // it, so spelling variants of one person's address share one budget.
            [$account, $byName] = $user->resolveAccount($email);
            $signInEmail = $account['email'] ?? $email;
            $emailKey = authAttemptEmailKey($signInEmail);

            // The captcha is asked for only after repeated failures (for this
            // account, from this IP, or earlier in this browser session). When it
            // is, it is checked and consumed BEFORE anything is recorded or the
            // password is looked at, so unsolved tries count for nothing.
            $captchaNeeded = $useCaptcha && (!empty($_SESSION['login_captcha'])
                || loginCaptchaNeeded($emailKey, $clientIp));
            if ($captchaNeeded) {
                $_SESSION['login_captcha'] = true;
            }

            if ($captchaNeeded && !isset($_POST['captcha'])) {
                $error = 'Several sign-in attempts have failed. Please also complete the security check below.';
            } elseif ($captchaNeeded && !captchaVerify((string) $_POST['captcha'])) {
                $error = 'The security code did not match. Please type the characters shown in the new image.';
            } elseif (($attemptId = loginReserveAttempt($emailKey, $clientIp)) === false) {
                // Locked: the password is not even looked at, so a guesser learns
                // nothing from further tries. Same message whether or not the address
                // has an account.
                $error = 'Too many failed sign-in attempts. Please wait 15 minutes and try again.';
            } elseif ($user->authenticate($signInEmail, $password)) {
                // Also removes the attempt reserved above.
                authAttemptClear('login_fail', $emailKey);
                unset($_SESSION['login_captcha']);
                $userData = $user->getByEmail($signInEmail);
                if ($byName) {
                    logSystemAction($userData['id'], 'login_by_name', 'auth', sprintf(
                        'Signed in as %s, matched to this account (%s) by name',
                        html_entity_decode($email, ENT_QUOTES, 'UTF-8'), $userData['email']
                    ));
                }

                // Get student ID if user is a student
                $studentId = null;
                if ($userData['role'] === 'student') {
                    $studentDetails = $user->getStudentDetails($userData['id']);
                    $studentId = $studentDetails['student_id'] ?? null;
                }

                // Create session
                loginUser($userData, $studentId);

                // Redirect to the role's home. A student whose attendance is due
                // today lands on the Attendance page: marking comes first.
                header('Location: ' . ($userData['role'] === 'student'
                    ? studentLandingPage($studentId)
                    : getRoleHomePage($userData['role'])));
                exit();
            } else {
                // The reserved attempt stays on record as this failure.
                $error = 'Invalid email or password';
                if ($useCaptcha && loginCaptchaNeeded($emailKey, $clientIp)) {
                    $_SESSION['login_captcha'] = true;   // the next try needs it
                }
            }
        } catch (Exception $e) {
            logError("Login error: " . $e->getMessage());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// The captcha code is issued by captcha.php when the browser fetches the image,
// so every render — success, failure, or fresh visit — shows a new one. The
// query string only defeats browser caching; the code never appears in this HTML.
$captchaSrc = BASE_URL . '/captcha.php?t=' . bin2hex(random_bytes(4));

// Shown only once it will be asked for: after failures in this session, or
// when this (non-office) IP has failed repeatedly. Until then the form is just
// email and password.
$showCaptcha = $useCaptcha && (!empty($_SESSION['login_captcha'])
    || loginCaptchaNeeded(null, getClientIp()));

$pageTitle = 'Login';
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
            <p class="mt-4 text-base text-blue-100">Shailesh J. Mehta School of Management, IIT Bombay</p>

            <ul class="mt-10 space-y-6">
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/20">
                        <i class="fas fa-calendar-check"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Apply &amp; track leave</p>
                        <p class="mt-0.5 text-sm text-blue-100">Submit a request in a minute and follow it through to a decision.</p>
                    </div>
                </li>
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/20">
                        <i class="fas fa-route"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Multi-stage approvals</p>
                        <p class="mt-0.5 text-sm text-blue-100">Guide, TA coordinator and HOD sign off in turn, with every step on record.</p>
                    </div>
                </li>
                <li class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/20">
                        <i class="fas fa-user-check"></i>
                    </span>
                    <div>
                        <p class="font-semibold">Face-verified attendance</p>
                        <p class="mt-0.5 text-sm text-blue-100">Mark attendance on campus with a quick face check.</p>
                    </div>
                </li>
            </ul>
        </div>

        <p class="relative text-sm text-blue-100/80">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
    </aside>

    <!-- Sign-in -->
    <main class="relative flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6 lg:px-12">
        <!-- SJMSOM atrium photo behind the card, lightly darkened so the white card stands out -->
        <div class="pointer-events-none absolute inset-0 bg-cover bg-center" style="background-image: url('<?php echo asset_url('public/images/login-bg.jpg'); ?>')" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-slate-900/55 via-slate-900/35 to-slate-900/60" aria-hidden="true"></div>

        <div class="relative w-full max-w-md">
            <!-- Brand mark (below lg, where the panel is hidden) -->
            <div class="mb-8 text-center lg:hidden">
                <span class="inline-flex rounded-2xl bg-white px-4 py-3 shadow-lg">
                    <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay" width="463" height="200" class="h-14 w-auto">
                </span>
                <p class="mt-4 text-xl font-semibold tracking-tight text-white"><?php echo SITE_NAME; ?></p>
                <p class="mt-1 text-sm text-blue-100">SJM School of Management, IIT Bombay</p>
            </div>

            <!-- Login Card -->
            <div class="card p-6 shadow-lg sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Sign in</h1>
                <p class="mt-1.5 text-sm text-gray-500">Use your IITB email address and portal password.</p>

                <?php if ($timeout): ?>
                    <div class="alert alert-warning mt-6" role="status">
                        <i class="fas fa-clock mt-0.5"></i>
                        <p>Your session has expired. Please login again.</p>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error mt-6" role="alert">
                        <i class="fas fa-exclamation-circle mt-0.5"></i>
                        <p><?php echo sanitize($error); ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="mt-6 space-y-5">
                    <?php echo getCSRFField(); ?>
                    <!-- Email Field -->
                    <div>
                        <label for="email" class="form-label">IITB email address</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                <i class="fas fa-envelope"></i>
                            </span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?php echo sanitize($_POST['email'] ?? ''); ?>"
                                class="form-input py-3 pl-10"
                                placeholder="your.name@iitb.ac.in"
                                autocomplete="username"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div>
                        <label for="password" class="form-label">Password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                                <i class="fas fa-lock"></i>
                            </span>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-input py-3 pl-10"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >
                        </div>
                        <p class="form-help">
                            Your portal password, set from your invitation or a reset email &mdash; not your
                            IITB LDAP / webmail password. Never set one? Use <em>Forgot password?</em> below.
                        </p>
                    </div>

                    <?php if ($showCaptcha): ?>
                    <!-- Image Captcha -->
                    <div>
                        <label for="captcha" class="form-label">Security check</label>
                        <div class="flex items-center gap-2">
                            <img
                                id="captcha-image"
                                src="<?php echo sanitize($captchaSrc); ?>"
                                width="190" height="60"
                                alt="Security code image"
                                class="h-12 w-auto shrink-0 rounded-lg border border-gray-200 bg-gray-50"
                            >
                            <button type="button" id="captcha-refresh" class="btn-icon h-10 w-10 shrink-0 text-gray-400 hover:text-blue-600" title="Show a new image" aria-label="Show a new image">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <input
                                type="text"
                                id="captcha"
                                name="captcha"
                                maxlength="<?php echo CAPTCHA_LENGTH; ?>"
                                class="form-input min-w-0 flex-1 py-3 text-center font-semibold uppercase tracking-widest"
                                placeholder="Code"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                required
                            >
                        </div>
                        <p class="form-help">Type the characters shown (not case-sensitive).</p>
                    </div>
                    <?php endif; ?>

                    <!-- Remember Me and Forgot Password -->
                    <div class="flex items-center justify-between gap-4">
                        <label for="remember" class="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
                            <input
                                id="remember"
                                name="remember"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300"
                            >
                            Remember me
                        </label>
                        <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="text-sm font-semibold text-blue-600 hover:text-blue-700">
                            Forgot password?
                        </a>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-sign-in-alt"></i>Sign in
                    </button>
                </form>

                <!-- Help Text -->
                <div class="mt-8 border-t border-gray-100 pt-6 text-center">
                    <p class="text-sm text-gray-600">
                        New user? Check your email for an invitation link.
                    </p>
                    <p class="mt-1.5 text-xs text-gray-500">
                        Need help? Contact <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="font-medium text-blue-600 hover:text-blue-700"><?php echo ADMIN_EMAIL; ?></a>
                    </p>
                </div>
            </div>

            <!-- Footer (the brand panel carries it on large screens) -->
            <p class="mt-8 text-center text-sm text-blue-100 lg:hidden">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </main>
</div>

    <?php if ($showCaptcha): ?>
    <script>
        // Fetching the image again issues a new code server-side.
        document.getElementById('captcha-refresh').addEventListener('click', function () {
            var img = document.getElementById('captcha-image');
            img.src = img.src.split('?')[0] + '?t=' + Date.now();
            document.getElementById('captcha').value = '';
            document.getElementById('captcha').focus();
        });
    </script>
    <?php endif; ?>
</body>
</html>
