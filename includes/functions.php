<?php
/**
 * Common Helper Functions
 * Reusable utility functions used across the application
 */

/**
 * Sanitize input to prevent XSS
 */
/**
 * Cache-busted URL for one of our own static assets (CSS/JS under public/).
 *
 * The web server sends no Cache-Control for /public files — only ETag and
 * Last-Modified — so browsers fall back to HEURISTIC caching and may reuse a
 * stale copy for hours without ever revalidating. On phones that meant students
 * kept running old attendance JavaScript after a deploy, so a shipped fix looked
 * like it had never arrived. Appending the file's mtime changes the URL whenever
 * the file changes: a deploy is always picked up, and unchanged files stay
 * cached. Vendored libraries are versioned by their own paths, but they get the
 * same treatment so an upgrade in place is never missed.
 */
function asset_url($relativePath) {
    $rel = ltrim($relativePath, '/');
    $mtime = @filemtime(__DIR__ . '/../' . $rel);
    return BASE_URL . '/' . $rel . ($mtime ? '?v=' . $mtime : '');
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate email domain
 */
function validateDomain($email) {
    $domain = substr(strrchr($email, "@"), 1);
    return $domain === ALLOWED_DOMAIN;
}

/**
 * Validate password strength
 * Must contain at least 8 characters, one uppercase, one lowercase, and one number
 */
function validatePassword($password) {
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password) === 1;
}

/**
 * Format date for display
 */
function formatDate($date, $format = DISPLAY_DATE_FORMAT) {
    if (empty($date)) {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = DISPLAY_DATETIME_FORMAT) {
    if (empty($datetime)) {
        return '-';
    }
    return date($format, strtotime($datetime));
}

/**
 * Get time ago string (e.g., "2 hours ago")
 */
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return formatDate($datetime);
    }
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        // Held before the chain starts: the student flagged their guide/TA as
        // wrong, and the office has to settle that before anyone can review.
        'pending_routing' => '<span class="badge badge-routing"><i class="fas fa-user-slash mr-1 text-xs"></i>Awaiting Guide/TA Change</span>',
        'pending_guide' => '<span class="badge badge-pending"><i class="fas fa-clock mr-1 text-xs"></i>Pending Guide</span>',
        'pending_ta' => '<span class="badge badge-pending"><i class="fas fa-clock mr-1 text-xs"></i>Pending TA</span>',
        'pending_hod' => '<span class="badge badge-pending"><i class="fas fa-clock mr-1 text-xs"></i>Pending HOD</span>',
        'pending_admin' => '<span class="badge badge-pending"><i class="fas fa-hourglass-half mr-1 text-xs"></i>Pending Admin</span>',
        'approved' => '<span class="badge badge-approved"><i class="fas fa-check mr-1 text-xs"></i>Approved</span>',
        'rejected' => '<span class="badge badge-rejected"><i class="fas fa-times mr-1 text-xs"></i>Rejected</span>',
        'cancelled' => '<span class="badge badge-cancelled"><i class="fas fa-ban mr-1 text-xs"></i>Cancelled</span>',
        'combined' => '<span class="badge badge-combined"><i class="fas fa-compress-arrows-alt mr-1 text-xs"></i>Combined</span>',
        'pending' => '<span class="badge badge-pending"><i class="fas fa-clock mr-1 text-xs"></i>Pending</span>'
    ];

    return $badges[$status] ?? '<span class="badge badge-cancelled">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

/**
 * Get leave type badge HTML
 */
function getLeaveTypeBadge($type) {
    $badges = [
        'personal' => '<span class="badge badge-personal"><i class="fas fa-user mr-1 text-xs"></i>Personal</span>',
        'medical' => '<span class="badge badge-medical"><i class="fas fa-heartbeat mr-1 text-xs"></i>Medical</span>',
        'academic' => '<span class="badge badge-academic"><i class="fas fa-graduation-cap mr-1 text-xs"></i>Academic</span>'
    ];

    return $badges[$type] ?? '<span class="badge badge-cancelled">' . ucfirst($type) . '</span>';
}

/**
 * Get payment status badge
 */
function getPaymentStatusBadge($status) {
    if (empty($status)) {
        return '-';
    }

    $badges = [
        'paid' => '<span class="badge badge-approved">Paid</span>',
        'partially_paid' => '<span class="badge badge-pending">Partially Paid</span>',
        'unpaid' => '<span class="badge badge-rejected">Unpaid</span>'
    ];

    return $badges[$status] ?? sanitize($status);
}

/**
 * Generate random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Flash message functions
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

function hasFlashMessage() {
    return isset($_SESSION['flash_message']);
}

/**
 * Display flash message HTML
 */
function displayFlashMessage() {
    if ($flash = getFlashMessage()) {
        $styles = [
            'success' => ['alert-success', 'fa-check-circle'],
            'error' => ['alert-error', 'fa-exclamation-circle'],
            'warning' => ['alert-warning', 'fa-exclamation-triangle'],
            'info' => ['alert-info', 'fa-info-circle']
        ];

        [$class, $icon] = $styles[$flash['type']] ?? $styles['info'];

        echo '<div class="alert ' . $class . ' mb-4" role="alert">';
        echo '<i class="fas ' . $icon . ' mt-0.5"></i>';
        echo '<p class="flex-1">' . sanitize($flash['message']) . '</p>';
        echo '</div>';
    }
}

/**
 * Redirect with message
 */
function redirectWithMessage($url, $type, $message) {
    setFlashMessage($type, $message);
    header('Location: ' . $url);
    exit();
}

/**
 * JSON response helper
 */
function jsonResponse($success, $message, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

/**
 * Validate date format (YYYY-MM-DD)
 */
function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Check if date is in future
 */
function isFutureDate($date) {
    return strtotime($date) > strtotime('today');
}

/**
 * Get academic year from date
 */
function getAcademicYear($date = null) {
    $date = $date ?? date('Y-m-d');
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));

    // Academic year starts in July
    if ($month >= 7) {
        return $year . '-' . ($year + 1);
    } else {
        return ($year - 1) . '-' . $year;
    }
}

/**
 * Get month name
 */
function getMonthName($month) {
    return date('F', mktime(0, 0, 0, $month, 1));
}

/**
 * Paginate array
 */
function paginate($items, $page = 1, $perPage = ITEMS_PER_PAGE) {
    $total = count($items);
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'current_page' => $page,
        'total_pages' => $totalPages,
        'total_items' => $total,
        'per_page' => $perPage
    ];
}

/**
 * Generate pagination HTML
 */
function renderPagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div class="flex justify-center mt-6">';
    $html .= '<nav class="inline-flex rounded-md shadow-sm -space-x-px">';

    // Previous button
    if ($currentPage > 1) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($currentPage - 1) . '" class="px-3 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Previous</a>';
    }

    // Page numbers
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i == $currentPage) {
            $html .= '<span class="px-4 py-2 border border-gray-300 bg-blue-50 text-sm font-medium text-blue-600">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . '?page=' . $i . '" class="px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $i . '</a>';
        }
    }

    // Next button
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $baseUrl . '?page=' . ($currentPage + 1) . '" class="px-3 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Next</a>';
    }

    $html .= '</nav>';
    $html .= '</div>';

    return $html;
}

/**
 * Upload file securely
 *
 * The stored name is random and its extension comes from the verified MIME
 * type, never from the client: production nginx hands ANY .php file under the
 * webroot to PHP-FPM, so keeping a client-chosen extension was a code-execution
 * path. The destination defaults to a private directory that is not web-served.
 *
 * @return string the stored file's basename (resolve it with privateFilePath())
 */
function uploadFile($file, $allowedTypes = ALLOWED_UPLOAD_TYPES, $maxSize = MAX_UPLOAD_SIZE, $destDir = UPLOAD_DIR) {
    // Check for upload errors
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('File upload error');
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds maximum allowed (' . ($maxSize / 1048576) . 'MB)');
    }

    // Check MIME type from the content, not the client's claim
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extensions = ['application/pdf' => 'pdf'];
    if (!in_array($mimeType, $allowedTypes, true) || !isset($extensions[$mimeType])) {
        throw new Exception('Invalid file type. Only PDF files are allowed.');
    }
    if ($mimeType === 'application/pdf') {
        $fh = fopen($file['tmp_name'], 'rb');
        $magic = $fh ? fread($fh, 5) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($magic !== '%PDF-') {
            throw new Exception('Invalid file type. Only PDF files are allowed.');
        }
    }

    if (!is_dir($destDir) && !@mkdir($destDir, 0770, true)) {
        throw new Exception('Failed to save uploaded file');
    }

    $filename = generateRandomString() . '.' . $extensions[$mimeType];
    $filepath = rtrim($destDir, '/') . '/' . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file');
    }
    @chmod($filepath, 0640);

    return $filename;
}

/**
 * Resolve a stored document reference to its file inside a private directory.
 *
 * Accepts both the bare name stored now and the legacy "uploads/medical_documents/x.pdf"
 * form still held by older rows; only the basename is used, so a stored value
 * can never walk out of $dir. Returns null if the file is not there.
 */
function privateFilePath($dir, $stored) {
    if ($stored === null || $stored === '') {
        return null;
    }
    $name = basename(str_replace('\\', '/', (string) $stored));
    if ($name === '' || $name[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        return null;
    }
    $path = rtrim($dir, '/') . '/' . $name;
    return is_file($path) ? $path : null;
}

/**
 * Delete a stored private document, ignoring references that do not resolve.
 */
function deletePrivateFile($dir, $stored) {
    $path = privateFilePath($dir, $stored);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Calculate leave balance with color indicator
 */
function getBalanceColor($balance) {
    if ($balance < 0) {
        return 'text-red-600';
    } elseif ($balance < 5) {
        return 'text-yellow-600';
    } else {
        return 'text-green-600';
    }
}

/**
 * Format leave balance display
 */
function formatBalance($balance) {
    $color = getBalanceColor($balance);
    return '<span class="font-semibold ' . $color . '">' . number_format($balance, 1) . '</span>';
}

/**
 * Get leave type label
 */
function getLeaveTypeLabel($type) {
    $labels = [
        'personal' => 'Personal Leave',
        'medical' => 'Medical Leave',
        'academic' => 'Academic/Official Leave'
    ];
    return $labels[$type] ?? ucfirst($type);
}

/**
 * Check if user agent is mobile
 */
function isMobile() {
    return preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|tablet|up\.browser|up\.link|webos|wos)/i", $_SERVER["HTTP_USER_AGENT"]);
}

/**
 * Generate URL with query parameters
 */
function buildUrl($base, $params = []) {
    if (empty($params)) {
        return $base;
    }
    return $base . '?' . http_build_query($params);
}

/**
 * Get current URL
 */
function getCurrentUrl() {
    return $_SERVER['REQUEST_URI'];
}

/**
 * Debug function (only in development)
 */
function dd($data) {
    if (ENABLE_DEBUG) {
        echo '<pre>';
        var_dump($data);
        echo '</pre>';
        die();
    }
}

/**
 * Log system action to system_logs table
 * @param int $userId The user ID performing the action
 * @param string $action The action being performed
 * @param string $module The module where the action occurred
 * @param string $description Description of the action
 */
function logSystemAction($userId, $action, $module, $description) {
    // An admin acting through "login as" must never leave a trail that reads as
    // the impersonated user's own doing: name the real actor on every entry.
    if (function_exists('isImpersonating') && isImpersonating()) {
        $description = rtrim((string) $description)
            . ' [performed by admin #' . (int) getImpersonatorId()
            . ' (' . getImpersonatorName() . ') while impersonating user #' . (int) getCurrentUserId() . ']';
    }
    try {
        $pdo = getDBConnection();
        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, :module, :description, :ip_address)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':module' => $module,
            ':description' => $description,
            // Behind the front proxy REMOTE_ADDR is 10.127.1.21 for everyone.
            ':ip_address' => (function_exists('getClientIp') ? getClientIp() : null) ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ]);
    } catch (PDOException $e) {
        // Log to file if database logging fails
        logError("System action logging failed: " . $e->getMessage());
    }
}
