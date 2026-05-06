<?php
/**
 * Get Student Details API
 * Returns student's faculty guide and TA coordinator information
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || getCurrentUserRole() !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get user ID from query parameter
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

try {
    $db = getDBConnection();

    // Get student details including guide and TA
    $sql = "SELECT u.id, u.name, u.email, u.role,
                   s.faculty_guide_id, s.ta_coordinator_id,
                   fg.name as guide_name, tc.name as ta_name
            FROM users u
            LEFT JOIN students s ON u.id = s.user_id
            LEFT JOIN users fg ON s.faculty_guide_id = fg.id
            LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
            WHERE u.id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    // Return user details
    echo json_encode([
        'success' => true,
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'faculty_guide_id' => $user['faculty_guide_id'] ?? 0,
        'guide_name' => $user['guide_name'] ?? '',
        'ta_coordinator_id' => $user['ta_coordinator_id'] ?? 0,
        'ta_name' => $user['ta_name'] ?? ''
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
