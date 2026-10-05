<?php
/**
 * Attempt counting for sign-in and password-reset rate limits.
 *
 * Backed by the auth_attempts table (database/migrations/2026_09_23_login_security.sql).
 * Times are compared with the database's own NOW() on both sides, so the PHP and
 * MariaDB clocks/zones never have to agree.
 *
 * Every function fails OPEN: if the table is missing or a query errors, the
 * limit is skipped and the error logged. A forgotten migration must not turn
 * into "nobody can sign in".
 *
 * Only real attempts are ever recorded. The login and forgot-password forms
 * check their CSRF token first, and a wrong captcha is not recorded at all, so
 * a page that fires blind cross-site POSTs (or a script that never solves a
 * captcha) cannot run up anyone's counters.
 */

// Sign-in: failures inside the window before further attempts are refused.
define('LOGIN_FAIL_WINDOW_SECONDS', 15 * 60);
define('LOGIN_FAIL_MAX_PER_EMAIL', 5);
define('LOGIN_FAIL_MAX_PER_IP', 20);

// Forgot-password.
define('RESET_REQUEST_WINDOW_SECONDS', 15 * 60);
define('RESET_REQUEST_MAX_PER_IP', 10);
define('RESET_EMAIL_MIN_INTERVAL_SECONDS', 10 * 60);
define('RESET_EMAIL_MAX_PER_DAY', 5);

/**
 * How many rows of $action (one action or a list) exist for this email (or IP)
 * within the last $windowSeconds. $field is 'email' or 'ip'. A null/empty
 * subject counts as 0.
 */
function authAttemptCount($action, string $field, ?string $subject, int $windowSeconds): int {
    if ($subject === null || $subject === '' || !in_array($field, ['email', 'ip'], true)) {
        return 0;
    }
    $actions = (array) $action;
    $in = implode(',', array_fill(0, count($actions), '?'));
    try {
        $stmt = getDBConnection()->prepare(
            "SELECT COUNT(*) FROM auth_attempts
              WHERE action IN ($in) AND {$field} = ? AND created_at > NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute(array_merge($actions, [$subject, $windowSeconds]));
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        logError('Rate limit check failed (limit skipped): ' . $e->getMessage());
        return 0;
    }
}

/** Record one attempt and return its row id (null if it could not be stored). */
function authAttemptRecord(string $action, ?string $email, ?string $ip): ?int {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO auth_attempts (action, email, ip) VALUES (?, ?, ?)");
        $stmt->execute([$action, $email !== '' ? $email : null, $ip !== '' ? $ip : null]);
        $id = (int) $db->lastInsertId();

        // Opportunistic pruning: about one write in fifty. Nothing reads rows
        // older than a day (the longest window is RESET_EMAIL_MAX_PER_DAY).
        if (random_int(1, 50) === 1) {
            $db->exec("DELETE FROM auth_attempts WHERE created_at < NOW() - INTERVAL 1 DAY");
        }
        return $id;
    } catch (PDOException $e) {
        logError('Rate limit record failed: ' . $e->getMessage());
        return null;
    }
}

/** Re-label one recorded attempt (forgot-password marks the one that mails). */
function authAttemptSetAction(?int $id, string $action): void {
    if ($id === null) {
        return;
    }
    try {
        getDBConnection()->prepare("UPDATE auth_attempts SET action = ? WHERE id = ?")->execute([$action, $id]);
    } catch (PDOException $e) {
        logError('Rate limit update failed: ' . $e->getMessage());
    }
}

/** Remove one recorded attempt. */
function authAttemptDelete(?int $id): void {
    if ($id === null) {
        return;
    }
    try {
        getDBConnection()->prepare("DELETE FROM auth_attempts WHERE id = ?")->execute([$id]);
    } catch (PDOException $e) {
        logError('Rate limit delete failed: ' . $e->getMessage());
    }
}

/** Forget an email's rows of one action (e.g. its failed sign-ins after a success). */
function authAttemptClear(string $action, string $email): void {
    try {
        $stmt = getDBConnection()->prepare("DELETE FROM auth_attempts WHERE action = ? AND email = ?");
        $stmt->execute([$action, $email]);
    } catch (PDOException $e) {
        logError('Rate limit clear failed: ' . $e->getMessage());
    }
}

/** Normalise an email for use as a rate-limit key. */
function authAttemptEmailKey(string $email): string {
    return strtolower(trim($email));
}

/**
 * Is this client IP one of the office egress addresses in the attendance
 * allow-list? Everyone on the office WiFi shares that address, so a per-IP
 * limit there would let one person (or one malicious page someone opens) lock
 * the whole office out. Those addresses rely on the per-email limits only.
 * Fails to "not exempt" if the attendance tables are absent.
 */
function authIpIsExempt(?string $ip): bool {
    static $cache = [];
    if ($ip === null || $ip === '') {
        return false;
    }
    if (!array_key_exists($ip, $cache)) {
        try {
            $stmt = getDBConnection()->prepare("SELECT 1 FROM att_allowed_ips WHERE is_active = 1 AND ip = ? LIMIT 1");
            $stmt->execute([$ip]);
            $cache[$ip] = (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $cache[$ip] = false;
        }
    }
    return $cache[$ip];
}

/** The IP to apply per-IP limits to: null when unknown or office-exempt. */
function authLimitedIp(?string $ip): ?string {
    return authIpIsExempt($ip) ? null : $ip;
}

/**
 * Reserve a sign-in attempt against the limits, BEFORE the password is checked.
 *
 * Insert-then-count: the attempt is written first as a failure and the window
 * is counted with it included. Parallel requests therefore all see each other's
 * rows, and at most LOGIN_FAIL_MAX_PER_EMAIL of them can get through, where a
 * check-then-insert let a burst of N parallel guesses all pass the check.
 *
 * Returns the row id to keep (as the failure) or clear (on success), or false
 * when the email or IP is locked out; the refused request's row is removed
 * again so hammering a locked account does not keep extending the lock.
 */
function loginReserveAttempt(string $emailKey, ?string $ip) {
    $id = authAttemptRecord('login_fail', $emailKey, $ip);
    $limitedIp = authLimitedIp($ip);

    $locked = authAttemptCount('login_fail', 'email', $emailKey, LOGIN_FAIL_WINDOW_SECONDS) > LOGIN_FAIL_MAX_PER_EMAIL
        || authAttemptCount('login_fail', 'ip', $limitedIp, LOGIN_FAIL_WINDOW_SECONDS) > LOGIN_FAIL_MAX_PER_IP;
    if ($locked) {
        authAttemptDelete($id);
        return false;
    }
    return $id;
}

/**
 * A named MariaDB/MySQL advisory lock, held by this connection until released
 * (or the connection closes). The name is hashed to stay inside the 64-char
 * limit. Returns false if it could not be had within $timeout seconds.
 */
function authLock(string $key, int $timeout = 2): bool {
    try {
        $stmt = getDBConnection()->prepare("SELECT GET_LOCK(?, ?)");
        $stmt->execute(['phdtimeoff:rl:' . sha1($key), $timeout]);
        return (int) $stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        logError('Rate limit lock failed: ' . $e->getMessage());
        return false;
    }
}

function authUnlock(string $key): void {
    try {
        getDBConnection()->prepare("SELECT RELEASE_LOCK(?)")->execute(['phdtimeoff:rl:' . sha1($key)]);
    } catch (PDOException $e) {
        logError('Rate limit unlock failed: ' . $e->getMessage());
    }
}
