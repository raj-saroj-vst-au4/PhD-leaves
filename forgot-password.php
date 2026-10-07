<?php
/**
 * Forgot Password Page
 * Allows users to request password reset via email
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
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
$pendingReset = null;   // account to email once the page has been delivered

// Handle forgot password form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');

    // Validate input. The CSRF check comes first so that nothing is recorded
    // for a blind cross-site POST: the per-IP limit below is shared by everyone
    // behind one address, and must not be something any web page can exhaust.
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'The form had expired. Please try again.';
    } elseif (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (!validateDomain($email)) {
        $error = 'Please use your @' . ALLOWED_DOMAIN . ' email address';
    } else {
        /*
         * One answer for every well-formed address. This page used to say "link
         * has been sent" for a registered active account, "if an account exists"
         * for anything else, and "failed to send" on a mail error, so it told
         * anyone which addresses had accounts. It also mailed a fresh link on
         * every submit, which made it an inbox-flooding tool. Now the reply never
         * varies — registered or not, active or not, throttled or not — and the
         * limits below decide silently whether a mail actually goes out.
         */
        $emailKey = authAttemptEmailKey($email);
        $clientIp = getClientIp();

        /*
         * Every well-formed request does the same database work whether or not
         * the address has an account (one insert, the same counts, one update),
         * so response time does not tell the two apart either.
         *
         * Per IP, insert-then-count: this request's row is written first and
         * counted with the rest, so a parallel burst cannot all pass the check.
         * Office egress addresses (attendance allow-list) are exempt: everyone
         * on the office WiFi shares one.
         */
        $attemptId = authAttemptRecord('reset_request', $emailKey, $clientIp);
        $ipFlooding = authAttemptCount(['reset_request', 'reset_sent'], 'ip', authLimitedIp($clientIp),
            RESET_REQUEST_WINDOW_SECONDS) > RESET_REQUEST_MAX_PER_IP;

        // Per account, the "was a mail sent recently?" check and the marking of
        // this request as the one that sends happen under a per-address lock, so
        // parallel submits for one address cannot each send a mail.
        $lockKey = 'reset:' . $emailKey;
        $locked = authLock($lockKey);
        try {
            $sentRecently = authAttemptCount('reset_sent', 'email', $emailKey, RESET_EMAIL_MIN_INTERVAL_SECONDS);
            $sentToday = authAttemptCount('reset_sent', 'email', $emailKey, 86400);

            $user = new User();
            $userData = $user->getByEmail($email);

            $send = $locked && !$ipFlooding && $sentRecently === 0 && $sentToday < RESET_EMAIL_MAX_PER_DAY
                && $userData && (int) $userData['is_active'] === 1;
            authAttemptSetAction($attemptId, $send ? 'reset_sent' : 'reset_request');
            if ($send) {
                $pendingReset = $userData;
            }
        } catch (Exception $e) {
            logError("Forgot password error: " . $e->getMessage());
        } finally {
            if ($locked) {
                authUnlock($lockKey);
            }
        }

        $success = 'If an active account exists for this address, a password reset link has been sent to it. '
                 . 'Please check your inbox (and spam folder). If nothing arrives within a few minutes, '
                 . 'wait ' . intdiv(RESET_EMAIL_MIN_INTERVAL_SECONDS, 60) . ' minutes before asking again.';
        $email = '';
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
            <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay" width="463" height="200" class="mx-auto h-16 w-auto">
            <span class="mt-4 block text-xl font-semibold tracking-tight text-gray-900"><?php echo SITE_NAME; ?></span>
            <span class="mt-1 block text-sm text-gray-500">SJM School of Management, IIT Bombay</span>
        </a>

        <!-- Forgot Password Card -->
        <div class="card p-6 shadow-lg sm:p-8">
            <span class="icon-chip chip-brand"><i class="fas fa-key"></i></span>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-gray-900">Reset password</h1>
            <p class="mt-1.5 text-sm text-gray-500">Enter your email address and we'll send you a link to reset your password.</p>

            <?php if ($error): ?>
                <div class="alert alert-error mt-6" role="alert">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <p><?php echo sanitize($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success mt-6" role="status">
                    <i class="fas fa-check-circle mt-0.5"></i>
                    <p><?php echo sanitize($success); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="mt-6 space-y-5">
                <?php echo getCSRFField(); ?>
                <!-- Email Field -->
                <div>
                    <label for="email" class="form-label">Email address</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo sanitize($email); ?>"
                            class="form-input py-3 pl-10"
                            placeholder="your.name@iitb.ac.in"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                    <p class="form-help">
                        Enter the exact address your portal invitation was sent to &mdash; the one you
                        sign in with. For many students this is the roll-number address
                        (e.g. <span class="font-mono">25d1503@iitb.ac.in</span>), not a name-based one.
                        A link is only sent if that address has an account.
                    </p>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary btn-lg w-full">
                    <i class="fas fa-paper-plane"></i>Send reset link
                </button>
            </form>

            <!-- Back to Login -->
            <p class="mt-6 text-center text-sm text-gray-600">
                Remember your password?
                <a href="<?php echo BASE_URL; ?>/login.php" class="font-semibold text-blue-600 hover:text-blue-700">Sign in</a>
            </p>

            <!-- Help Text -->
            <div class="mt-6 flex items-start gap-2.5 border-t border-gray-100 pt-6 text-xs text-gray-500">
                <i class="fas fa-shield-alt mt-0.5 text-blue-600"></i>
                <p>Your account security is important. The reset link will expire in <?php echo TOKEN_EXPIRY_HOURS; ?> hours.</p>
            </div>
        </div>

        <!-- Footer -->
        <p class="mt-8 text-center text-sm text-gray-500">&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
    </div>
</main>

</body>
</html>
<?php
/*
 * Send the reset mail only AFTER the response is complete. Minting the token
 * and talking to SMTP take long enough that doing it inline made a registered
 * address answer measurably slower than an unknown one. PHP-FPM (production)
 * can hand the page to the client first; elsewhere the mail still goes, just
 * without that timing cover.
 */
if ($pendingReset !== null) {
    session_write_close();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    ignore_user_abort(true);

    try {
        $resetUser = new User();
        $result = $resetUser->generatePasswordResetToken($pendingReset['id']);
        if (!empty($result['success'])) {
            $emailService = new EmailService();
            if (!$emailService->sendPasswordReset($pendingReset['email'], $pendingReset['name'], $result['token'])) {
                logError('Password reset email could not be sent', ['user_id' => (int) $pendingReset['id']]);
            }
        } else {
            logError('Password reset token could not be generated', ['user_id' => (int) $pendingReset['id']]);
        }
    } catch (Throwable $e) {
        logError('Forgot password error: ' . $e->getMessage(), ['user_id' => (int) $pendingReset['id']]);
    }
}
