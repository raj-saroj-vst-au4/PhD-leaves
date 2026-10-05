<?php
/**
 * Admin - Impersonate User
 * Allows an admin to temporarily switch into another user's session
 * for support / debugging purposes.
 *
 * The original admin identity is preserved in $_SESSION['impersonator']
 * so that stop_impersonation.php can restore it.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/User.php';

// Must be POST + admin + CSRF-valid.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

requireRole('admin');
checkCSRF();

// Can't start impersonating while already impersonating — prevents nesting.
if (isImpersonating()) {
    setFlashMessage('error', 'You are already impersonating a user. Stop the current impersonation first.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

$targetUserId = (int)($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
    setFlashMessage('error', 'Invalid user ID.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

$userClass = new User();
$target = $userClass->getById($targetUserId);

if (!$target) {
    setFlashMessage('error', 'User not found.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

// Guardrails.
if ((int)$target['id'] === (int)$_SESSION['user_id']) {
    setFlashMessage('error', 'You cannot impersonate yourself.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

if ($target['role'] === 'admin') {
    setFlashMessage('error', 'Impersonating another admin is not permitted.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

if (empty($target['is_active'])) {
    setFlashMessage('error', 'Cannot impersonate an inactive user.');
    header('Location: ' . BASE_URL . '/admin/users.php');
    exit();
}

// Preserve original admin identity. pw_fp lets every request of the
// impersonation re-check the ADMIN too (validateSessionIdentity in auth.php):
// deactivating, demoting or re-passwording the admin ends the impersonation.
$adminSnapshot = [
    'user_id'    => $_SESSION['user_id'],
    'user_name'  => $_SESSION['user_name'] ?? null,
    'user_email' => $_SESSION['user_email'] ?? null,
    'user_role'  => $_SESSION['user_role'] ?? null,
    'pw_fp'      => $_SESSION['pw_fp'] ?? null,
    'started_at' => time(),
];

// Audit log BEFORE swapping session so the action is attributed to the admin.
logSystemAction(
    $adminSnapshot['user_id'],
    'impersonation_started',
    'admin',
    "Admin '{$adminSnapshot['user_name']}' (ID: {$adminSnapshot['user_id']}) started impersonating '{$target['name']}' (ID: {$target['id']}, role: {$target['role']})"
);

// Resolve student_id if target is a student — mirrors login.php behavior.
$studentId = null;
if ($target['role'] === 'student') {
    $studentDetails = $userClass->getStudentDetails($target['id']);
    $studentId = $studentDetails['student_id'] ?? null;
}

// Swap identity. loginUser() rotates the session ID and records the target's
// password fingerprint, so the target is re-validated like any other session.
loginUser($target, $studentId);

// Attach impersonator stash AFTER loginUser (which clears this key).
$_SESSION['impersonator'] = $adminSnapshot;

// Redirect to the target user's home page.
header('Location: ' . getRoleHomePage($target['role']));
exit();
