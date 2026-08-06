<?php
/**
 * Returns a fresh attendance API ticket for the logged-in portal user.
 * Polled silently by the attendance pages so tickets can stay short-lived.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/attendance.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit();
}

echo json_encode(['ok' => true, 'ticket' => att_ticket()]);
