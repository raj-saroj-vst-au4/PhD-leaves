<?php
/**
 * Index Page - Redirects to appropriate dashboard based on user role
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// If user is logged in, redirect to their dashboard
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    header('Location: ' . getRoleHomePage($role));
    exit();
}

// Otherwise redirect to login page
header('Location: ' . BASE_URL . '/login.php');
exit();
