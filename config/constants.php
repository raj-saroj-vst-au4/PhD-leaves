<?php
/**
 * System Constants and Configuration
 */

// Every date the app computes is an Indian calendar date. Production PHP has no
// date.timezone of its own (CLI unset -> UTC, FPM GMT) while cron and MariaDB run
// on IST, so without this date() lags NOW() by 5h30m: the 00:00 IST reset on
// 1 July saw "06-30" and exited, and every date near midnight was the day before.
// Every entry point loads this file first, so this is the one place to pin it.
define('APP_TIMEZONE', 'Asia/Kolkata');
date_default_timezone_set(APP_TIMEZONE);

// Per-host overrides. This file is committed with PRODUCTION values and is
// deployed like any other file; a dev box (or any host that differs) defines its
// own values first in config/site.php, which is gitignored — copy
// config/site-example.php. Each overridable constant below is guarded.
if (is_file(__DIR__ . '/site.php')) {
    require_once __DIR__ . '/site.php';
}

// Site Configuration
if (!defined('SITE_DOMAIN')) {
    define('SITE_DOMAIN', 'https://www.som.iitb.ac.in'); // Full domain URL (no trailing slash)
}
define('BASE_URL', '/phdtimeoff');
define('SITE_NAME', 'PhD Time-Off Portal');
define('ADMIN_EMAIL', 'raj.saroj@iitb.ac.in');
define('ALLOWED_DOMAIN', 'iitb.ac.in');

// Security Settings
define('TOKEN_EXPIRY_HOURS', 48);
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// Serve the portal over HTTPS only. Derived from SITE_DOMAIN so a dev box on
// plain http:// keeps working. A cleartext request is redirected to SITE_DOMAIN.
define('FORCE_HTTPS', strpos(SITE_DOMAIN, 'https://') === 0);

// Front-end proxies that terminate TLS and then reach this server over plain
// HTTP. A request from one of these counts as secure (the browser leg was
// HTTPS); a request from anywhere else must arrive over HTTPS itself.
// www.som.iitb.ac.in (10.127.1.21) proxies to the portal on production.
// Loopback is deliberately NOT listed: on a shared host any local process could
// otherwise claim to be the proxy and choose its own client IP.
if (!defined('TLS_TERMINATING_PROXIES')) {
    define('TLS_TERMINATING_PROXIES', ['10.127.1.21']);
}

// Leave Allocation Constants
define('PERSONAL_LEAVE_ALLOCATION', 15);
define('MEDICAL_LEAVE_ALLOCATION', 5);
define('ACADEMIC_LEAVE_ALLOCATION', 25);
define('EXTENDED_LEAVE_THRESHOLD', 15);

// Academic Leave Eligibility (in months)
define('ACADEMIC_LEAVE_ELIGIBILITY_MONTHS', 12);

// Private runtime data: medical certificates, Dean AP documents, logs and the
// monthly register CSVs. None of it may be reachable by URL. Production nginx
// serves every non-.php file under the webroot as static bytes and ignores
// .htaccess, so the old public/uploads/ and logs/ were downloadable without a
// login. A dot-directory is refused by production nginx (`location ~ /\.`) and
// by the .htaccess inside it on Apache. Point this outside the webroot where the
// host allows it. Files are served only through api/files/download.php.
if (!defined('PRIVATE_DIR')) {
    define('PRIVATE_DIR', __DIR__ . '/../.private/');
}
define('MEDICAL_DOCS_DIR', PRIVATE_DIR . 'medical_documents/');
define('DEAN_AP_DOCS_DIR', PRIVATE_DIR . 'dean_ap_documents/');
define('LOG_DIR', PRIVATE_DIR . 'logs/');

// File Upload Settings
define('MAX_UPLOAD_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_UPLOAD_TYPES', ['application/pdf']);
define('UPLOAD_DIR', DEAN_AP_DOCS_DIR);

// Date Settings
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd M Y');
define('DISPLAY_DATETIME_FORMAT', 'd M Y h:i A');

// Pagination
define('ITEMS_PER_PAGE', 25);

// Logging
define('LOG_FILE', LOG_DIR . 'error.log');
if (!defined('ENABLE_DEBUG')) {
    define('ENABLE_DEBUG', false);
}

/**
 * Custom Error Logger
 */
function logError($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context) : '';
    $logMessage = "[$timestamp] $message $contextStr\n";
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0770, true);
    }
    if (@error_log($logMessage, 3, LOG_FILE) === false) {
        // Never lose the message just because the private log dir is missing.
        error_log(trim($logMessage));
    }
}
