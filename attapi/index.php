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
    $remote   = $_SERVER['REMOTE_ADDR'] ?? '';
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
    $candidate = trim(explode(',', (string) $value)[0]);

    // A proxy's own address (or loopback) is not a client address.
    if ($candidate === '' || $candidate === '127.0.0.1' || $candidate === '::1'
        || in_array($candidate, $trusted, true)) {
        return null;
    }
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
}

// ---------------------------------------------------------------- routing ---
$raw = (string) ($_GET['p'] ?? ($_SERVER['PATH_INFO'] ?? ''));
$parts = explode('?', $raw, 2);
$path  = $parts[0];
$query = $parts[1] ?? '';

if ($path === '' || $path === '/' || !preg_match('#^/[A-Za-z0-9/_.-]*$#', $path)
    || str_contains($path, '..')) {
    fail(404, 'NOT_FOUND', 'Unknown endpoint.');
}
// Maintenance jobs belong to the host's systemd timers over loopback only.
if (str_starts_with($path, '/jobs')) {
    fail(403, 'FORBIDDEN', 'Not available over the web.');
}
// The backend authenticates by HMAC ticket, but an anonymous request has no
// business reaching it at all.
if (!isLoggedIn()) {
    fail(401, 'NOT_LOGGED_IN', 'Your session has expired. Please sign in again.');
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

$headers = [];
$clientIp = att_client_ip();
if ($clientIp !== null) {
    $headers[] = 'X-Client-IP: ' . $clientIp;
}   // else: omitted on purpose — the backend then refuses to mark (fail closed)
if ($authorization !== '')               { $headers[] = 'Authorization: ' . $authorization; }
if (!empty($_SERVER['HTTP_COOKIE']))     { $headers[] = 'Cookie: ' . $_SERVER['HTTP_COOKIE']; }
if (!empty($_SERVER['HTTP_USER_AGENT'])) { $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']; }

// ------------------------------------------------------------------- body ---
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = null;

if ($method !== 'GET' && $method !== 'HEAD') {
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
    CURLOPT_TIMEOUT        => 120,     // face inference on CPU can take seconds
    CURLOPT_CONNECTTIMEOUT => 5,
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
