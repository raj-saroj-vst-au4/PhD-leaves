<?php
/**
 * /admin/ has no page of its own; send it to the admin dashboard. Monthly
 * report emails sent before Nov 2026 link to the bare /admin directory, which
 * answered 403 Forbidden without this file.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');
header('Location: ' . BASE_URL . '/admin/dashboard.php');
exit();
