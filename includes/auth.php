<?php
/**
 * Authentication Middleware
 * Handles session management and access control
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

/**
 * Require login - redirect to login page if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit();
    }

    // Check session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        logout();
        header('Location: ' . BASE_URL . '/login.php?timeout=1');
        exit();
    }

    $_SESSION['last_activity'] = time();
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
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();

    if ($studentId) {
        $_SESSION['student_id'] = $studentId;
    }

    // Regenerate session ID for security
    session_regenerate_id(true);
}

/**
 * Logout user and destroy session
 */
function logout() {
    $_SESSION = [];

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    session_destroy();
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
