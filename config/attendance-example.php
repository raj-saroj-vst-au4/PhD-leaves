<?php
/**
 * Attendance module configuration (example)
 *
 * Copy to `config/attendance.php` and fill in. `config/attendance.php` is
 * gitignored, like database.php.
 *
 * The PHP portal owns login; the Python backend (Docker, loopback-only) owns
 * the marking gates and the face engine. The two trust each other through the
 * short-lived HMAC tickets minted below — the secret here MUST equal
 * ATT_SHARED_SECRET in attendance/.env.
 */

// Generate with: openssl rand -hex 32
define('ATT_SHARED_SECRET', 'CHANGE_ME');

// Ticket lifetime. Short: the JS refreshes it silently via api/attendance_ticket.php.
define('ATT_TICKET_TTL', 900);

// Where the Python backend listens. Loopback only — never expose this port.
define('ATT_BACKEND_URL', 'http://127.0.0.1:3070');

// Where the browser reaches the API: the PHP forwarder, which needs no
// web-server configuration on either Apache or nginx.
define('ATT_API_BASE', BASE_URL . '/attapi/index.php?p=');

/**
 * Reverse proxies in front of this portal.
 *
 * When a request arrives from one of these addresses, REMOTE_ADDR is the proxy
 * rather than the student, so it must never be used as the client address —
 * otherwise every student would appear to come from one IP and the office-WiFi
 * gate would pass (or fail) everyone at once.
 *
 * On this deployment www.som.iitb.ac.in (10.127.1.21) proxies to the portal.
 */
define('ATT_TRUSTED_PROXIES', ['10.127.1.21', '127.0.0.1', '::1']);

/**
 * Header carrying the student's real IP when behind a trusted proxy.
 *
 * MUST name a header the proxy OVERWRITES on every request (e.g. nginx
 * `proxy_set_header X-Real-IP $remote_addr;`). Never use X-Forwarded-For if the
 * proxy appends to it — the first element would be attacker-supplied.
 *
 * Leave empty until the upstream proxy is configured to send it: with no
 * header, marking fails closed instead of trusting the proxy's own address.
 */
define('ATT_REAL_IP_HEADER', '');

/**
 * Mint an HMAC ticket for the currently logged-in portal user.
 * Payload: uid, role, sid (students.id when role=student), name, email, exp.
 */
function att_ticket(): ?string {
    if (!isLoggedIn()) {
        return null;
    }
    $payload = [
        'uid'   => (int) getCurrentUserId(),
        'role'  => getCurrentUserRole(),
        'sid'   => getCurrentStudentId() ? (int) getCurrentStudentId() : null,
        'name'  => getCurrentUserName(),
        'email' => getCurrentUserEmail(),
        'exp'   => time() + ATT_TICKET_TTL,
    ];
    $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, ATT_SHARED_SECRET, true)), '+/', '-_'), '=');
    return $body . '.' . $sig;
}
