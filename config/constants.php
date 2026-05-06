<?php
/**
 * System Constants and Configuration
 */

// Site Configuration
define('SITE_DOMAIN', 'https://www.som.iitb.ac.in'); // Full domain URL (no trailing slash)
define('BASE_URL', '/phdtimeoff');
define('SITE_NAME', 'PhD Time-Off Portal');
define('ADMIN_EMAIL', 'raj.saroj@iitb.ac.in');
define('ALLOWED_DOMAIN', 'iitb.ac.in');

// Security Settings
define('TOKEN_EXPIRY_HOURS', 48);
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// Leave Allocation Constants
define('PERSONAL_LEAVE_ALLOCATION', 15);
define('MEDICAL_LEAVE_ALLOCATION', 5);
define('ACADEMIC_LEAVE_ALLOCATION', 25);
define('EXTENDED_LEAVE_THRESHOLD', 15);

// Academic Leave Eligibility (in months)
define('ACADEMIC_LEAVE_ELIGIBILITY_MONTHS', 12);

// File Upload Settings
define('MAX_UPLOAD_SIZE', 5242880); // 5MB in bytes
define('ALLOWED_UPLOAD_TYPES', ['application/pdf']);
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/');

// Date Settings
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd M Y');
define('DISPLAY_DATETIME_FORMAT', 'd M Y h:i A');

// Pagination
define('ITEMS_PER_PAGE', 25);

// Logging
define('LOG_FILE', __DIR__ . '/../logs/error.log');
define('ENABLE_DEBUG', false);

/**
 * Custom Error Logger
 */
function logError($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? json_encode($context) : '';
    $logMessage = "[$timestamp] $message $contextStr\n";
    error_log($logMessage, 3, LOG_FILE);
}
