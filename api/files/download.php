<?php
/**
 * API: Download a leave application's private document
 *
 *   api/files/download.php?leave_id=<id>&doc=medical   student's medical certificate
 *   api/files/download.php?leave_id=<id>&doc=dean_ap   admin's signed Dean AP approval
 *
 * The ONLY way these files are served. They live under PRIVATE_DIR, which the
 * web server never hands out, because production nginx serves every non-.php
 * file under the webroot as static bytes and ignores .htaccess: the old
 * public/uploads/ copies were readable by anyone who had (or guessed) the URL,
 * with no login. Here the caller must be signed in and allowed to see the leave
 * itself (canAccessLeave(): the student, their guide / TA coordinator, the HOD,
 * or an admin).
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

/** Plain-text refusal. This is opened in a tab or an iframe, not by fetch(). */
function downloadRefuse($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $message;
    exit();
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    downloadRefuse(405, 'Method not allowed');
}

// Which document, where it is kept, which column names it, and what the
// browser is told to call it. Nothing about the path comes from the request.
$documents = [
    'medical' => ['dir' => MEDICAL_DOCS_DIR, 'column' => 'supporting_document', 'name' => 'medical-certificate'],
    'dean_ap' => ['dir' => DEAN_AP_DOCS_DIR, 'column' => 'admin_document_path', 'name' => 'dean-ap-approval'],
];

$leaveId = (int) ($_GET['leave_id'] ?? 0);
$doc = (string) ($_GET['doc'] ?? '');

if ($leaveId <= 0 || !isset($documents[$doc])) {
    downloadRefuse(400, 'Invalid document request');
}
$spec = $documents[$doc];

// The same fields canAccessLeave() reads from Leave::getById().
$db = getDBConnection();
$stmt = $db->prepare(
    "SELECT la.id, la.supporting_document, la.admin_document_path,
            s.user_id, s.faculty_guide_id, s.ta_coordinator_id
     FROM leave_applications la
     JOIN students s ON la.student_id = s.id
     WHERE la.id = :id"
);
$stmt->execute([':id' => $leaveId]);
$leave = $stmt->fetch();

if (!$leave) {
    downloadRefuse(404, 'Document not found');
}

if (!canAccessLeave($leave, getCurrentUserId(), getCurrentUserRole())) {
    downloadRefuse(403, 'You do not have access to this document');
}

// privateFilePath() uses only the stored value's basename, so legacy rows that
// still say "uploads/medical_documents/x.pdf" resolve inside the private
// directory and no stored value can walk out of it.
$path = privateFilePath($spec['dir'], $leave[$spec['column']]);
if ($path === null) {
    downloadRefuse(404, 'Document not found');
}

// Don't hold the session lock while streaming: a large PDF on a slow link would
// otherwise stall every other request from this user's browser.
session_write_close();

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = $spec['name'] . '-' . (int) $leave['id'] . '.pdf';

// Uploads are checked to be PDFs, but the type is still forced and sniffing
// disabled, so no file is ever rendered as HTML on this origin.
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($path);
}
exit();
