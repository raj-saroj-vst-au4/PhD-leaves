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

// Same rules as any page (idle timeout, account re-check); answers a JSON 401
// with ok:false when they fail, which the polling scripts already tolerate.
// A silent background refresh (every 8 min) is not user activity, so it does
// not reset the idle clock.
requireLogin(false);

$ticket = att_ticket();
// Read-only from here on: do not hold the session lock while responding.
session_write_close();

echo json_encode(['ok' => true, 'ticket' => $ticket]);
