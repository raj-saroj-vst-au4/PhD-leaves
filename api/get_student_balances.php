<?php
/**
 * API: Get Student Leave Balances
 * Returns current leave balances for a student (admin only)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// requireLogin() applies the idle timeout (JSON 401 for /api/); then admin only.
requireLogin();
if (getCurrentUserRole() !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit;
}

try {
    $db = getDBConnection();

    $sql = "SELECT lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
            FROM leave_balances lb
            JOIN students s ON lb.student_id = s.id
            WHERE s.user_id = :user_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $balances = $stmt->fetch();

    if (!$balances) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Student balances not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'personal_leaves' => number_format($balances['personal_leaves'], 1),
        'medical_leaves' => number_format($balances['medical_leaves'], 1),
        'academic_leaves' => number_format($balances['academic_leaves'], 1)
    ]);

} catch (Exception $e) {
    logError("Get student balances error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
