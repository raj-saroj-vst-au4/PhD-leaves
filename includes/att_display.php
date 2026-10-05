<?php
/**
 * Office display screen credential (admin/attendance_display.php).
 *
 * The code screen runs all day on the office TV, polling every few seconds.
 * It used to ride on the admin's portal session, and since every poll reset the
 * idle clock, the browser on that TV held a live admin session all day: anyone
 * walking up to it had the whole admin console.
 *
 * Now, when an admin or HOD opens the screen, the page is handed a signed
 * display token instead. The token is good for exactly one thing — reading the
 * rotating strict-mode code through attapi (GET /admin/strict-code) — for at
 * most ATT_DISPLAY_TTL seconds. Display polls never touch the portal session,
 * so that session times out after an hour of no real use like any other, and
 * the screen keeps running on its token. Each poll re-checks the account: a
 * deactivated, demoted or re-passworded account stops the screen at once.
 *
 * Needs ATT_SHARED_SECRET (config/attendance.php). The signing key is derived
 * from it for this purpose only, so a display token can never pass as a
 * backend ticket or the other way round.
 */

define('ATT_DISPLAY_TTL', 16 * 3600);   // one working day, generously

function attDisplayKey(): string {
    return hash_hmac('sha256', 'phdtimeoff office display token v1', ATT_SHARED_SECRET, true);
}

function attDisplayB64(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Mint a token for this user; $pwFp is their session's password fingerprint. */
function attDisplayTokenMint(int $userId, string $pwFp): string {
    $body = attDisplayB64(json_encode([
        'uid' => $userId,
        'fp'  => $pwFp,
        'exp' => time() + ATT_DISPLAY_TTL,
    ]));
    return $body . '.' . attDisplayB64(hash_hmac('sha256', $body, attDisplayKey(), true));
}

/**
 * The users row (id, name, email, role) behind a still-valid token, or null.
 * One primary-key query.
 */
function attDisplayTokenIdentity(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }
    [$body, $sig] = $parts;
    if (!hash_equals(attDisplayB64(hash_hmac('sha256', $body, attDisplayKey(), true)), $sig)) {
        return null;
    }
    $claims = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($claims) || !isset($claims['uid'], $claims['fp'], $claims['exp'])
        || (int) $claims['exp'] < time()) {
        return null;
    }

    try {
        $stmt = getDBConnection()->prepare(
            "SELECT id, name, email, role, is_active, password FROM users WHERE id = ?"
        );
        $stmt->execute([(int) $claims['uid']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError('Display token check failed: ' . $e->getMessage());
        return null;
    }
    if (!$row || (int) $row['is_active'] !== 1 || !in_array($row['role'], ['admin', 'hod'], true)
        || !hash_equals((string) $claims['fp'], passwordFingerprint($row['password']))) {
        return null;
    }
    unset($row['password'], $row['is_active']);
    return $row;
}
