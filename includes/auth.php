<?php
/**
 * Authentication Middleware
 * Handles session management and access control
 *
 * Every web entry point loads this file (directly or through header.php) before
 * producing output, so the transport and session rules live here, once:
 *   1. enforceHttps()            - cleartext requests never reach a session
 *   2. startAppSession()         - cookie flags set before EVERY session_start
 *   3. validateSessionIdentity() - a session is only as good as the account
 *                                  behind it right now, not at login time
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../config/database.php';
}

// This app's own session cookie (name, and path BASE_URL/). www.som.iitb.ac.in
// hosts other PHP apps; with the default PHPSESSID on path=/ each app's
// strict-mode session rejected and overwrote the other's cookie, logging people
// out of both. The rename logs everyone out once at deploy. A leftover legacy
// PHPSESSID is left alone: from here there is no telling whether this app or a
// neighbour set it.
if (!defined('APP_SESSION_NAME')) {
    define('APP_SESSION_NAME', 'PHDTIMEOFF_SID');
}

enforceHttps();
startAppSession();
validateSessionIdentity();

/**
 * Did the browser's leg of this request travel over TLS?
 *
 * Either PHP was reached over HTTPS itself, or the request came from one of the
 * front proxies that terminate TLS and forward over plain HTTP (production:
 * www.som.iitb.ac.in -> chanakya). X-Forwarded-Proto is deliberately NOT
 * consulted: any client can send it, and the proxy's address is the only thing
 * here a client cannot forge.
 */
function isSecureRequest(): bool {
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    return isTlsProxy(normaliseIp((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
}

/**
 * The TLS-terminating front proxies (config/constants.php, overridable per host
 * in config/site.php). Read defensively: this file runs on every request, so a
 * missing or malformed constant must degrade to "no proxy", never fatal.
 */
function tlsTerminatingProxies(): array {
    return defined('TLS_TERMINATING_PROXIES') && is_array(TLS_TERMINATING_PROXIES)
        ? TLS_TERMINATING_PROXIES : [];
}

function isTlsProxy(string $ip): bool {
    return $ip !== '' && in_array($ip, tlsTerminatingProxies(), true);
}

/**
 * Canonical text form of an address for comparisons: an IPv4-mapped IPv6
 * address (::ffff:10.127.1.21, as a dual-stack listener reports IPv4 peers)
 * becomes plain IPv4, so it matches the proxy list and the office allow-list.
 */
function normaliseIp(string $ip): string {
    $ip = trim($ip);
    if (stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $v4;
        }
    }
    return $ip;
}

/**
 * Refuse to serve the portal over cleartext when it is configured for HTTPS.
 *
 * Production answered on http://10.127.1.1/phdtimeoff/ as well as through the
 * HTTPS front proxy, so a password or session cookie could cross the campus
 * network in the clear. A GET/HEAD is redirected to the same path on
 * SITE_DOMAIN. Any other method is refused rather than redirected: a 302 turns
 * a POST into a GET and silently drops the form, which reads as "it saved"
 * when nothing happened.
 *
 * No HSTS header: www.som.iitb.ac.in hosts other applications and a host-wide
 * policy is not this app's to set.
 */
function enforceHttps(): void {
    if (PHP_SAPI === 'cli' || !defined('FORCE_HTTPS') || !FORCE_HTTPS
        || !defined('SITE_DOMAIN') || isSecureRequest()) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method === 'HEAD') {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        // Only an origin-form path may be appended to SITE_DOMAIN; anything
        // else (absolute-form, empty) falls back to the portal root.
        if ($uri === '' || $uri[0] !== '/') {
            $uri = BASE_URL . '/';
        }
        header('Location: ' . SITE_DOMAIN . $uri, true, 302);
        header('Cache-Control: no-store');
        exit();
    }

    http_response_code(403);
    header('Cache-Control: no-store');
    $message = 'This portal must be used over HTTPS. Please open ' . SITE_DOMAIN . BASE_URL . '/ and try again.';
    if (wantsJsonResponse()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'ok' => false, 'code' => 'HTTPS_REQUIRED', 'message' => $message]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit();
}

/**
 * Start the PHP session with hardened cookie settings. Idempotent.
 *
 * The session cookie used to go out with no HttpOnly, Secure or SameSite flag,
 * so any XSS could read it from document.cookie and replay it. Several pages
 * also called session_start() themselves; they now call this instead, but as
 * this file is always loaded first the session is normally already open here.
 */
function startAppSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name(APP_SESSION_NAME);
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        // Refuse IDs this server never issued (session fixation), and never
        // accept an ID from the URL.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (defined('BASE_URL') ? BASE_URL : '') . '/',
            'domain'   => '',
            'secure'   => isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

/**
 * Per-request record of what validateSessionIdentity() concluded.
 * null (no identity in the session) | 'valid' | 'timeout' | 'revoked'
 * | 'unverified' (the check itself failed; the session is kept but not honoured).
 */
function authSessionState(?string $set = null): ?string {
    static $state = null;
    if ($set !== null) {
        $state = $set;
    }
    return $state;
}

/**
 * A fingerprint of the account's current password hash.
 *
 * Stored in the session at login and compared on every request, so changing or
 * resetting a password ends every other session on that account. The bcrypt
 * hash itself is not copied into the session store.
 */
function passwordFingerprint($passwordHash): string {
    return hash('sha256', 'phdtimeoff-session|' . (string) $passwordHash);
}

/**
 * Re-check the logged-in identity against the database on every request.
 *
 * A session used to be trusted for as long as its cookie lived: deactivating an
 * account, changing its role or resetting its password left every open session
 * working. This runs at bootstrap for every entry point, so it also covers the
 * endpoints that only call isLoggedIn() or read $_SESSION directly, and it
 * applies the idle timeout there too (the attendance forwarder skipped it).
 *
 * One primary-key query; two rows while an admin is impersonating, because both
 * the impersonated account AND the admin behind it must still be valid.
 */
function validateSessionIdentity(): void {
    if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
        return;
    }

    $timeout = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 3600;
    if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity'] > $timeout)) {
        endSessionIdentity('timeout');
        return;
    }

    $userId = (int) $_SESSION['user_id'];
    $impersonatorId = isImpersonating() ? (int) $_SESSION['impersonator']['user_id'] : 0;

    try {
        $ids = $impersonatorId > 0 ? [$userId, $impersonatorId] : [$userId];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDBConnection()->prepare(
            "SELECT id, role, is_active, password FROM users WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }
    } catch (Throwable $e) {
        // Fail closed for this request only: an unexpected query error must not
        // log everyone out, but nor may it wave an unchecked session through.
        logError('Session validation failed: ' . $e->getMessage(), ['user_id' => $userId]);
        authSessionState('unverified');
        return;
    }

    $problem = sessionAccountProblem($rows[$userId] ?? null, $_SESSION['user_role'], $_SESSION['pw_fp'] ?? null);
    if ($problem === null && $impersonatorId > 0) {
        $admin = $rows[$impersonatorId] ?? null;
        $problem = sessionAccountProblem($admin, 'admin', $_SESSION['impersonator']['pw_fp'] ?? null);
        if ($problem !== null) {
            $problem = 'impersonating admin #' . $impersonatorId . ' ' . $problem;
        }
    }

    if ($problem !== null) {
        logError('Session ended for user #' . $userId . ': ' . $problem);
        endSessionIdentity('revoked');
        return;
    }

    authSessionState('valid');
}

/**
 * Why this users row can no longer back a session, or null if it still can.
 * Every session is created by loginUser(), which records the fingerprint, so a
 * session without one is not trusted.
 */
function sessionAccountProblem(?array $row, string $expectedRole, ?string $fingerprint): ?string {
    if ($row === null) {
        return 'account no longer exists';
    }
    if ((int) $row['is_active'] !== 1) {
        return 'account deactivated';
    }
    if ($row['role'] !== $expectedRole) {
        return "role changed from {$expectedRole} to {$row['role']}";
    }
    if ($fingerprint === null) {
        return 'no password fingerprint in the session';
    }
    if (!hash_equals($fingerprint, passwordFingerprint($row['password']))) {
        return 'password changed since sign-in';
    }
    return null;
}

/**
 * Drop the identity from the current session but keep the session itself open,
 * so the rest of this request (a login form storing its captcha, a flash
 * message) still has somewhere to write.
 */
function endSessionIdentity(string $reason): void {
    $_SESSION = [];
    authSessionState($reason);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role'])
        && authSessionState() !== 'unverified';
}

/**
 * Require login - redirect to login page if not authenticated
 *
 * The idle timeout and the account re-check already ran at bootstrap
 * (validateSessionIdentity); a session that failed either has no identity left.
 *
 * $touch: does this request count as the user being active? Page loads and
 * user-initiated POSTs do. Background polls must pass false: the attendance
 * pages poll every 30-60 s and refresh their ticket every 8 min, and when those
 * reset the idle clock an unattended admin console never timed out.
 */
function requireLogin($touch = true) {
    if (!isLoggedIn()) {
        expiredSessionResponse(authSessionState() !== null);
    }

    if ($touch) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * End the request for a caller with no valid session.
 *
 * A 302 to the login page is right for a browser navigation but wrong for a
 * fetch()/XHR: the JSON caller silently follows the redirect, receives the login
 * page's HTML, fails to parse it and reports "an error occurred" — which tells
 * the user nothing about having been logged out. So answer JSON callers with a
 * 401 and a message the existing handlers already surface.
 */
function expiredSessionResponse($wasTimeout) {
    if (wantsJsonResponse()) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'ok'      => false,   // the attendance forwarder's callers read `ok`
            'code'    => 'SESSION_EXPIRED',
            'message' => $wasTimeout
                ? 'Your session has expired. Please sign in again.'
                : 'You are not signed in. Please sign in again.',
            'login_url' => BASE_URL . '/login.php?timeout=1',
        ]);
        exit();
    }

    if (!headers_sent()) {
        header('Location: ' . BASE_URL . '/login.php' . ($wasTimeout ? '?timeout=1' : ''));
        exit();
    }

    // Output already started (a page that checks auth too late). A redirect is no
    // longer possible, so bounce with a meta refresh rather than leaving a
    // half-rendered page that looks like broken navigation.
    $url = htmlspecialchars(BASE_URL . '/login.php' . ($wasTimeout ? '?timeout=1' : ''), ENT_QUOTES);
    echo '<meta http-equiv="refresh" content="0;url=' . $url . '">'
       . '<p style="font-family:sans-serif;padding:1rem">Your session has expired. '
       . '<a href="' . $url . '">Sign in again</a>.</p>';
    exit();
}

/** Does this caller want JSON rather than an HTML redirect? */
function wantsJsonResponse() {
    // Anything under /api/ is a JSON endpoint by convention in this project, and
    // so is the attendance forwarder under /attapi/.
    // Exception: api/files/ serves documents opened in a tab or an iframe, where
    // a raw JSON 401 is a dead end; those get the login redirect like a page.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ((strpos($script, '/api/') !== false && strpos($script, '/api/files/') === false)
        || strpos($script, '/attapi/') !== false) {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return $accept !== '' && strpos($accept, 'application/json') !== false
        && strpos($accept, 'text/html') === false;
}

/**
 * Require specific role(s)
 * @param string|array $allowedRoles Single role or array of allowed roles
 */
function requireRole($allowedRoles) {
    requireLogin();

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    if (!in_array($_SESSION['user_role'], $allowedRoles)) {
        http_response_code(403);
        die('Access Denied: You do not have permission to access this page.');
    }
}

/**
 * Check if current user has specific role
 */
function hasRole($role) {
    return isLoggedIn() && $_SESSION['user_role'] === $role;
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user name
 */
function getCurrentUserName() {
    return $_SESSION['user_name'] ?? null;
}

/**
 * Get current user email
 */
function getCurrentUserEmail() {
    return $_SESSION['user_email'] ?? null;
}

/**
 * Get current student ID (if user is student)
 */
function getCurrentStudentId() {
    return $_SESSION['student_id'] ?? null;
}

/**
 * Login user and create session
 */
function loginUser($user, $studentId = null) {
    // Replace the previous identity wholesale. Stopping an impersonation of a
    // student used to leave that student's student_id behind in the admin's
    // session (and so in the attendance ticket's `sid`). impersonate.php
    // attaches its stash AFTER this call.
    // The CSRF token is rotated with the identity too.
    unset($_SESSION['student_id'], $_SESSION['impersonator'], $_SESSION['csrf_token']);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    // Callers pass the full users row; see validateSessionIdentity().
    $_SESSION['pw_fp'] = passwordFingerprint($user['password'] ?? '');

    if ($studentId) {
        $_SESSION['student_id'] = $studentId;
    }

    // Regenerate session ID for security
    session_regenerate_id(true);
    authSessionState('valid');
}

/**
 * Logout user and destroy session
 */
function logout() {
    $_SESSION = [];

    // Expire the cookie with the same path/domain/flags it was set with, or the
    // browser treats it as a different cookie and keeps the original.
    if (isset($_COOKIE[session_name()]) && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * The caller's real IP address, or null when it cannot be established.
 *
 * Behind the production front proxy REMOTE_ADDR is 10.127.1.21 for everybody,
 * so a per-IP limit on it would lock the whole campus out together. From a
 * TLS-terminating proxy the address is taken ONLY from the header that proxy
 * overwrites on every request (ATT_REAL_IP_HEADER, X-Real-IP on production),
 * never from X-Forwarded-For, which a client can append to. From anywhere else
 * REMOTE_ADDR is the client. Null (proxy without the header) means callers
 * must skip per-IP decisions rather than pin them on the proxy.
 */
function getClientIp(): ?string {
    $remote = normaliseIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') {
        return null;
    }
    if (!isTlsProxy($remote)) {
        return $remote;
    }

    // The header name lives with the attendance config (gitignored, per host).
    if (!defined('ATT_REAL_IP_HEADER') && is_file(__DIR__ . '/../config/attendance.php')) {
        require_once __DIR__ . '/../config/attendance.php';
    }
    $header = defined('ATT_REAL_IP_HEADER') ? trim((string) ATT_REAL_IP_HEADER) : '';
    if ($header === '') {
        return null;
    }
    $value = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '');
    $candidate = normaliseIp(explode(',', $value)[0]);
    if ($candidate === '' || isTlsProxy($candidate)
        || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    return $candidate;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token input field
 */
function getCSRFField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * Check CSRF token from POST request
 */
function checkCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
            http_response_code(403);
            die('CSRF token validation failed');
        }
    }
}

/**
 * Get redirect URL based on user role
 */
function getRoleHomePage($role) {
    switch ($role) {
        case 'student':
            return BASE_URL . '/student/dashboard.php';
        case 'faculty':
            return BASE_URL . '/faculty/dashboard.php';
        case 'hod':
            return BASE_URL . '/hod/dashboard.php';
        case 'admin':
            return BASE_URL . '/admin/dashboard.php';
        default:
            return BASE_URL . '/login.php';
    }
}

/**
 * Check if the current session is an admin impersonating another user
 */
function isImpersonating() {
    return isset($_SESSION['impersonator']) && !empty($_SESSION['impersonator']['user_id']);
}

/**
 * Get the original admin's user ID when impersonating
 */
function getImpersonatorId() {
    return $_SESSION['impersonator']['user_id'] ?? null;
}

/**
 * Get the original admin's name when impersonating
 */
function getImpersonatorName() {
    return $_SESSION['impersonator']['user_name'] ?? null;
}

/**
 * Block an action when the current session is an impersonation.
 * Used to prevent admins-as-other-users from changing passwords,
 * performing admin-only actions, etc.
 */
function blockIfImpersonating($message = 'This action is not permitted while impersonating another user.') {
    if (isImpersonating()) {
        http_response_code(403);
        die($message);
    }
}

/**
 * Check if current user can access leave application
 * Used to prevent unauthorized access to leave details
 */
function canAccessLeave($leave, $userId, $userRole) {
    // Admin and HOD can access all
    if (in_array($userRole, ['admin', 'hod'])) {
        return true;
    }

    // Student can access their own leaves
    if ($userRole === 'student' && $leave['user_id'] == $userId) {
        return true;
    }

    // Faculty can access if they are guide or TA coordinator
    if ($userRole === 'faculty') {
        if ($leave['faculty_guide_id'] == $userId || $leave['ta_coordinator_id'] == $userId) {
            return true;
        }
    }

    return false;
}
