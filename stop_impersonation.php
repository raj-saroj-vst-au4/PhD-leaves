<?php
/**
 * Stop Impersonation
 * Restores the original admin session. Accessible to the currently-impersonating
 * session regardless of which role they are wearing, because the banner that
 * exposes this link is shown in that context.
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

if (!isImpersonating()) {
    // Nothing to restore — send them to their dashboard.
    header('Location: ' . getRoleHomePage(getCurrentUserRole()));
    exit();
}

// Require POST + CSRF to prevent CSRF-based forced-stop (low risk but cheap).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}
checkCSRF();

$admin = $_SESSION['impersonator'];
$impersonatedId   = $_SESSION['user_id'] ?? null;
$impersonatedName = $_SESSION['user_name'] ?? null;

$userClass = new User();
$adminUser = $userClass->getById($admin['user_id']);

if (!$adminUser || $adminUser['role'] !== 'admin' || empty($adminUser['is_active'])) {
    // Admin account has been deleted/deactivated/demoted mid-impersonation.
    // Refuse to restore; force a clean logout.
    logSystemAction(
        $admin['user_id'],
        'impersonation_stop_failed',
        'admin',
        "Attempted to stop impersonation but admin account no longer valid (ID: {$admin['user_id']})"
    );
    logout();
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

// Audit log first, attributed to the admin.
logSystemAction(
    $admin['user_id'],
    'impersonation_stopped',
    'admin',
    "Admin '{$admin['user_name']}' (ID: {$admin['user_id']}) stopped impersonating '{$impersonatedName}' (ID: {$impersonatedId})"
);

// Restore admin session. loginUser() drops the impersonated identity (including
// a student's student_id), clears the stash, re-records the admin's password
// fingerprint from the fresh row and rotates the session ID.
loginUser($adminUser);

// Belt and braces: the stash must never survive a restore.
unset($_SESSION['impersonator']);

header('Location: ' . BASE_URL . '/admin/users.php');
exit();
