<?php
/**
 * Sign-in captcha image.
 *
 * Every fetch issues a new code into the session and returns it as a PNG; the
 * login form's "new image" link simply fetches again. The code itself never
 * appears in any HTML or JSON.
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/captcha.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit();
}

if (!captchaEnabled()) {
    http_response_code(503);
    exit();
}

$code = captchaIssue();
// Release the session lock before the (comparatively slow) drawing.
session_write_close();

$png = captchaRenderPng($code);
header('Content-Type: image/png');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($png));
echo $png;
