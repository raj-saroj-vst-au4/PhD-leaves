<?php
/**
 * Reverse proxy from the portal to the attendance backend.
 *
 * The Python service listens on loopback only, so this is the sole way in from
 * a browser. Keeping the hop in PHP means the module deploys unchanged on
 * Apache+mod_php (dev) and nginx+PHP-FPM (production) with no web-server
 * configuration and no root access.
 *
 * Routing: the target path arrives URL-encoded in ?p=, e.g.
 *   /phdtimeoff/attapi/index.php?p=%2Fadmin%2Ftoday%3Fday%3D2026-07-29
 * This avoids PATH_INFO, which needs server-specific rewrite rules.
 *
 * SECURITY — the office-WiFi gate depends on att_client_ip() below. The IP is
 * resolved server-side and a client-supplied X-Client-IP / X-Forwarded-For is
 * never trusted. When the portal sits behind a reverse proxy that does not
 * publish the caller's address, the IP is deliberately left unresolved and the
 * backend refuses to mark attendance (fail closed) rather than letting everyone
 * through on the proxy's address.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/attendance.php';
require_once __DIR__ . '/../includes/att_display.php';

header('Cache-Control: no-store');

function fail(int $status, string $code, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'code' => $code, 'message' => $message]);
    exit();
}

/**
 * The caller's real address, or null when it cannot be established.
 *
 * A direct connection is authoritative. Behind a configured trusted proxy the
 * address can only come from a header that the proxy itself overwrites on every
 * request (ATT_REAL_IP_HEADER) — never from one a client may append to.
 */
function att_client_ip(): ?string {
    $remote   = normaliseIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $trusted  = defined('ATT_TRUSTED_PROXIES') ? ATT_TRUSTED_PROXIES : [];

    if ($remote === '') {
        return null;
    }
    if (!in_array($remote, $trusted, true)) {
        return $remote;                       // straight from the client
    }

    $header = defined('ATT_REAL_IP_HEADER') ? trim((string) ATT_REAL_IP_HEADER) : '';
    if ($header === '') {
        return null;                          // proxy hides the client: fail closed
    }
    $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '';
    $candidate = normaliseIp(explode(',', (string) $value)[0]);

    // A proxy's own address (or loopback) is not a client address.
    if ($candidate === '' || $candidate === '127.0.0.1' || $candidate === '::1'
        || in_array($candidate, $trusted, true)) {
        return null;
    }
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
}

/**
 * Canonical form of a requested backend path, or null if it is not one.
 *
 * The /jobs block used to test the raw string, so ?p=/./jobs/cleanup or
 * ?p=//jobs/cleanup sailed past it and reached the maintenance endpoints (only
 * the backend's job token stopped them). The path is now reduced to plain
 * segments FIRST — one percent-decoding pass, empty and "." segments dropped —
 * and anything that cannot be reduced cleanly (a second layer of encoding,
 * "..", backslashes or any other character) is refused. Checks then run on, and
 * the backend receives, exactly that canonical path.
 */
function att_normalise_path(string $path): ?string {
    if (str_contains($path, '%')) {
        $path = rawurldecode($path);
        if (str_contains($path, '%')) {
            return null;                      // double-encoded: refuse, do not guess
        }
    }
    if (!preg_match('#^/[A-Za-z0-9/_.-]*$#', $path)) {
        return null;                          // also rejects "\" and control bytes
    }
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }
    return $segments ? '/' . implode('/', $segments) : null;
}

// ---------------------------------------------------------------- routing ---
$raw = (string) ($_GET['p'] ?? ($_SERVER['PATH_INFO'] ?? ''));
$parts = explode('?', $raw, 2);
$path  = att_normalise_path($parts[0]);
$query = $parts[1] ?? '';

// Only the areas the browser pages actually use. Maintenance jobs (/jobs/*)
// belong to the host's systemd timers over loopback and are never forwarded;
// the explicit check below is kept so the refusal says why.
const ATT_WEB_ROUTES = ['admin', 'enroll', 'mark', 'my', 'netcheck'];
$area = $path === null ? '' : explode('/', $path)[1];
if ($area === 'jobs') {
    fail(403, 'FORBIDDEN', 'Not available over the web.');
}
if (!in_array($area, ATT_WEB_ROUTES, true)) {
    fail(404, 'NOT_FOUND', 'Unknown endpoint.');
}
// ?p= arrives percent-decoded, so re-encode whatever cannot sit raw in a URL
// (spaces, control bytes, '#', non-ASCII) before it is handed to curl.
$query = (string) preg_replace_callback('/[^\x21-\x7e]|#/', fn($m) => rawurlencode($m[0]), $query);

// The backend only has GET and POST routes. Anything else is refused here: a
// HEAD in particular used to be forwarded as a custom method, and curl then sat
// waiting for a body that never comes until the 120 s timeout, holding a PHP
// worker (and the session lock) the whole time.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    fail(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
}

// ------------------------------------------------------------------- auth ---
// Two ways in.
//
// The office code screen presents a display token (includes/att_display.php)
// and may only read the rotating strict-mode code. It does not use, and so does
// not keep alive, the portal session of the browser it runs in. The backend
// ticket for it is minted here, server-side, for the token's account; whatever
// Authorization header the browser sent is ignored.
//
// Everything else needs a live portal session, under the same rules as every
// portal page: idle timeout and account re-check (requireLogin answers JSON
// here). Only a POST — a user action: marking, enrolling, an admin change —
// counts as activity. GETs are the pages' background polls (every 30-60 s) and
// must not keep an unattended console signed in forever.
$displayTicket = null;
$displayToken = (string) ($_SERVER['HTTP_X_DISPLAY_TOKEN'] ?? '');
if ($displayToken !== '') {
    session_write_close();
    if ($path !== '/admin/strict-code' || $method !== 'GET') {
        fail(403, 'FORBIDDEN', 'The display screen can only read the attendance code.');
    }
    $who = attDisplayTokenIdentity($displayToken);
    if ($who === null) {
        fail(401, 'DISPLAY_EXPIRED', 'This screen has expired. Reload the page and sign in again.');
    }
    // att_ticket() reads the identity from $_SESSION. The session is already
    // closed, so this in-memory identity is never written back to it.
    $_SESSION = [
        'user_id'    => (int) $who['id'],
        'user_name'  => $who['name'],
        'user_email' => $who['email'],
        'user_role'  => $who['role'],
    ];
    $displayTicket = att_ticket();
    $_SESSION = [];
} else {
    requireLogin($method === 'POST');
    // Nothing below reads or writes the session. Release its lock now, or every
    // other request from this browser queues behind the backend call (face
    // inference can take seconds).
    session_write_close();
}

$url = rtrim(ATT_BACKEND_URL, '/') . '/api' . $path . ($query !== '' ? '?' . $query : '');

// ---------------------------------------------------------------- headers ---
// mod_php hides Authorization unless .htaccess republishes it; nginx/PHP-FPM
// passes it straight through. Check every place it can surface.
$authorization = '';
if (function_exists('apache_request_headers')) {
    foreach (apache_request_headers() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) { $authorization = $value; break; }
    }
}
if ($authorization === '') {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}
if ($displayTicket !== null) {
    $authorization = 'Bearer ' . $displayTicket;
}

$headers = [];
$clientIp = att_client_ip();
if ($clientIp !== null) {
    $headers[] = 'X-Client-IP: ' . $clientIp;
}   // else: omitted on purpose — the backend then refuses to mark (fail closed)
if ($authorization !== '')               { $headers[] = 'Authorization: ' . $authorization; }
// Only the device-binding cookie the backend issues. The whole Cookie header
// used to be relayed, handing the portal's own session ID to another service.
if (preg_match('/(?:^|;)\s*att_device=([^;]*)/', (string) ($_SERVER['HTTP_COOKIE'] ?? ''), $m)
    && trim($m[1]) !== '') {
    $headers[] = 'Cookie: att_device=' . trim($m[1]);
}
if (!empty($_SERVER['HTTP_USER_AGENT'])) { $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']; }

// ------------------------------------------------------------------- body ---
$body = null;

if ($method === 'POST') {
    if (!empty($_FILES)) {
        // PHP has already parsed the multipart upload, so it is re-encoded here.
        // Files arrive as frames[] (PHP collapses repeated scalar names) and are
        // re-emitted as repeated "frames" parts, which is what the API expects.
        $boundary = '----att' . bin2hex(random_bytes(8));
        $chunks = [];
        foreach ($_POST as $name => $value) {
            if (!is_scalar($value)) { continue; }
            $chunks[] = "--$boundary\r\nContent-Disposition: form-data; name=\""
                . rtrim((string) $name, '[]') . "\"\r\n\r\n" . $value . "\r\n";
        }
        foreach ($_FILES as $field => $file) {
            $field = rtrim((string) $field, '[]');
            $names = is_array($file['tmp_name']) ? $file['tmp_name'] : [$file['tmp_name']];
            foreach ($names as $i => $tmp) {
                if (!is_uploaded_file((string) $tmp)) { continue; }
                $chunks[] = "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"; "
                    . "filename=\"f$i.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"
                    . (string) file_get_contents((string) $tmp) . "\r\n";
            }
        }
        $chunks[] = "--$boundary--\r\n";
        $body = implode('', $chunks);
        $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
    } elseif (!empty($_POST)) {
        $body = http_build_query($_POST);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $body = (string) file_get_contents('php://input');
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
        }
    }
}

// ------------------------------------------------------------------ proxy ---
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    // POSTs run face inference on CPU (seconds, more under load) and monthly
    // report generation; GETs are lookups and file downloads over loopback.
    CURLOPT_TIMEOUT        => $method === 'POST' ? 120 : 30,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_FOLLOWLOCATION => false,
    // Relay only the response headers the browser needs: the device-binding
    // cookie issued at enrollment, and content type/disposition for downloads.
    CURLOPT_HEADERFUNCTION => function ($ch, $header) {
        $trimmed = trim($header);
        if ($trimmed !== '' && preg_match('#^(Set-Cookie|Content-Type|Content-Disposition):#i', $trimmed)) {
            header($trimmed, false);
        }
        return strlen($header);
    },
]);
if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($response === false || $status === 0) {
    logError('Attendance backend unreachable: ' . $error, ['path' => $path]);
    fail(503, 'BACKEND_DOWN',
        'The attendance service is not responding. Please inform the SOM office.');
}

http_response_code($status);
echo $response;
