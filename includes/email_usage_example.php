<?php
/**
 * EmailService Usage Examples
 *
 * This file demonstrates how to use the EmailService class
 * for sending various types of email notifications in the PhD TimeOff system.
 */

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/../config/database.php';

// Initialize EmailService
$emailService = new EmailService();

// ============================================================================
// Example 1: Send Invitation Email
// ============================================================================
/*
$result = $emailService->sendInvitation(
    'student@iitb.ac.in',           // Email address
    'John Doe',                      // Student name
    'abc123xyz456'                   // Invitation token
);

if ($result) {
    echo "Invitation sent successfully!\n";
} else {
    echo "Failed to send invitation.\n";
}
*/

// ============================================================================
// Example 2: Send Leave Application Notification to Guide
// ============================================================================
/*
// Fetch leave application data with student and guide information
$leaveId = 1;
$leave = new Leave();
$leaveData = $leave->getById($leaveId);

$result = $emailService->sendLeaveApplication($leaveData, 'guide');

if ($result) {
    echo "Leave application notification sent to guide!\n";
}
*/

// ============================================================================
// Example 3: Send Approval Notification (After Guide Approval)
// ============================================================================
/*
// After guide approves, notify TA coordinator
$result = $emailService->sendGuideApproval($leaveData);

if ($result) {
    echo "Guide approval notification sent to TA coordinator!\n";
}
*/

// ============================================================================
// Example 4: Send Approval Notification (After TA Approval)
// ============================================================================
/*
// After TA coordinator approves, notify HOD
$result = $emailService->sendTAApproval($leaveData);

if ($result) {
    echo "TA approval notification sent to HOD!\n";
}
*/

// ============================================================================
// Example 5: Send Final Approval to Student (After HOD Approval)
// ============================================================================
/*
$result = $emailService->sendHODApproval($leaveData);

if ($result) {
    echo "Approval notification sent to student!\n";
}
*/

// ============================================================================
// Example 6: Send Rejection Notification
// ============================================================================
/*
$result = $emailService->sendRejection($leaveData, 'guide');
// Other options: 'ta_coordinator', 'hod', 'admin'

if ($result) {
    echo "Rejection notification sent to student!\n";
}
*/

// ============================================================================
// Example 7: Send Admin Notification (Extended Leave)
// ============================================================================
/*
// Automatically called when HOD approves leave > 15 days
$result = $emailService->sendAdminNotification($leaveData);

if ($result) {
    echo "Admin notification sent for extended leave!\n";
}
*/

// ============================================================================
// Example 8: Send Monthly Reminder to All Students
// ============================================================================
/*
// Get all active students with their leave balances
$db = getDBConnection();
$stmt = $db->query("
    SELECT u.id, u.name, u.email,
           lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
    FROM users u
    JOIN students s ON u.id = s.user_id
    JOIN leave_balances lb ON s.id = lb.student_id
    WHERE u.is_active = 1 AND u.role = 'student'
");
$students = $stmt->fetchAll();

$result = $emailService->sendMonthlyReminder($students);

echo "Monthly reminders sent:\n";
echo "- Success: {$result['success']}\n";
echo "- Failed: {$result['failed']}\n";
echo "- Total: {$result['total']}\n";
*/

// ============================================================================
// Example 9: Send Monthly Report to Admins
// ============================================================================
/*
// Get admin users
$db = getDBConnection();
$stmt = $db->query("
    SELECT id, name, email
    FROM users
    WHERE role = 'admin' AND is_active = 1
");
$admins = $stmt->fetchAll();

// Prepare report data
$reportData = [
    'month' => date('Y-m-01', strtotime('-1 month')),
    'total_applications' => 45,
    'approved_applications' => 35,
    'rejected_applications' => 5,
    'pending_applications' => 5,
    'personal_leaves' => 20,
    'medical_leaves' => 15,
    'academic_leaves' => 10,
    'extended_leaves' => 3
];

$result = $emailService->sendMonthlyReport($admins, $reportData);

if ($result) {
    echo "Monthly report sent to all admins!\n";
}
*/

// ============================================================================
// Example 10: Test SMTP Connection
// ============================================================================
/*
$isConnected = $emailService->testConnection();

if ($isConnected) {
    echo "SMTP connection successful!\n";
} else {
    echo "SMTP connection failed. Check configuration.\n";
}
*/

// ============================================================================
// Integration with Approval Workflow
// ============================================================================
/*
// In your Approval class methods, add email notifications:

class Approval {
    private $emailService;

    public function __construct() {
        $this->emailService = new EmailService();
    }

    public function guideApprove($leaveId, $remarks = null) {
        // ... existing approval logic ...

        // After successful approval
        $leave = (new Leave())->getById($leaveId);

        // Notify TA coordinator
        $this->emailService->sendGuideApproval($leave);

        return true;
    }

    public function guideReject($leaveId, $remarks) {
        // ... existing rejection logic ...

        // After successful rejection
        $leave = (new Leave())->getById($leaveId);

        // Notify student
        $this->emailService->sendRejection($leave, 'guide');

        return true;
    }
}
*/

// ============================================================================
// Email Logging
// ============================================================================
/*
// All emails are automatically logged to the email_logs table
// You can query the logs like this:

$db = getDBConnection();

// Get all emails sent today
$stmt = $db->query("
    SELECT * FROM email_logs
    WHERE DATE(sent_at) = CURDATE()
    ORDER BY sent_at DESC
");
$todaysEmails = $stmt->fetchAll();

// Get failed emails
$stmt = $db->query("
    SELECT * FROM email_logs
    WHERE status = 'failed'
    ORDER BY sent_at DESC
    LIMIT 50
");
$failedEmails = $stmt->fetchAll();

// Get emails for specific application
$stmt = $db->prepare("
    SELECT * FROM email_logs
    WHERE email_type IN ('leave_application', 'approval', 'rejection')
    AND reference_id = :leave_id
    ORDER BY sent_at ASC
");
$stmt->execute([':leave_id' => $leaveId]);
$applicationEmails = $stmt->fetchAll();
*/

?>
