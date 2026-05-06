<?php
/**
 * Logout Page
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Destroy session and redirect to login
logout();
header('Location: ' . BASE_URL . '/login.php');
exit();
