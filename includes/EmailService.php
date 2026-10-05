<?php
/**
 * EmailService Class
 * Handles all email notifications using PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/constants.php';

class EmailService {
    private $db;
    private $mailer;
    private $maxRetries = 3;
    // Why the mailer could not be set up, or null if it was. A mailer that failed
    // to initialise must make every send report failure, not carry on as if the
    // configuration were fine.
    private $initError = null;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
        $this->initMailer();
    }

    /**
     * Get absolute URL for email links
     * Uses SITE_DOMAIN from config for consistent URLs in emails
     */
    private function getAbsoluteUrl($path = '') {
        return SITE_DOMAIN . BASE_URL . $path;
    }

    /**
     * Initialize PHPMailer with SMTP configuration
     */
    private function initMailer() {
        $this->mailer = new PHPMailer(true);

        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USER;
            $this->mailer->Password = SMTP_PASS;
            $this->mailer->SMTPSecure = SMTP_ENCRYPTION;
            $this->mailer->Port = SMTP_PORT;

            // Sender info
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';

        } catch (Throwable $e) {
            $this->initError = $e->getMessage();
            logError("PHPMailer initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Text for an HTML email body, escaped exactly once.
     *
     * Names, reasons and remarks are stored already HTML-escaped (sanitize() on
     * input) but not consistently, so escaping again showed students "&#039;" and
     * "&amp;" in their mail, while passing them raw let markup through. Decoding
     * first and then escaping gives the same safe result for either kind of row.
     */
    private function text($value, $multiline = false) {
        $plain = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
        return $multiline ? nl2br($html) : $html;
    }

    /**
     * Send signup invitation email
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $token Invitation token
     * @return bool true only when the SMTP server accepted the message; false on
     *              any failure (mailer not configured, SMTP refused, all retries
     *              exhausted). It does not throw for delivery failures, so the
     *              caller must check the return value before telling anyone the
     *              invitation was sent. Every outcome is recorded in email_logs.
     */
    public function sendInvitation($email, $name, $token) {
        $subject = "Invitation to Join PhD Time-Off Portal";
        $signupLink = $this->getAbsoluteUrl("/signup.php?token=" . $token);

        $placeholders = [
            '{NAME}' => $name,
            '{SIGNUP_LINK}' => $signupLink,
            '{EXPIRY_HOURS}' => TOKEN_EXPIRY_HOURS,
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('invitation.html', $placeholders);

        return $this->sendEmail($email, $subject, $body, 'invitation', null, $name);
    }

    /**
     * Send password reset email
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $token Password reset token
     * @return bool Success status
     */
    public function sendPasswordReset($email, $name, $token) {
        $subject = "Password Reset Request - " . SITE_NAME;
        $resetLink = $this->getAbsoluteUrl("/reset-password.php?token=" . $token);

        $placeholders = [
            '{NAME}' => $name,
            '{RESET_LINK}' => $resetLink,
            '{EXPIRY_HOURS}' => TOKEN_EXPIRY_HOURS,
            '{SITE_NAME}' => SITE_NAME,
            '{YEAR}' => date('Y'),
            '{ADMIN_EMAIL}' => ADMIN_EMAIL ?? 'support@iitb.ac.in'
        ];

        $body = $this->loadTemplate('password_reset.html', $placeholders);

        return $this->sendEmail($email, $subject, $body, 'password_reset', null, $name);
    }

    /**
     * Send leave application notification to guide
     * @param array $leave Leave application data
     * @param string $recipientType 'guide', 'ta_coordinator', or 'hod'
     * @return bool Success status
     */
    public function sendLeaveApplication($leave, $recipientType = 'guide') {
        $subject = "New Leave Application - {$leave['student_name']} ({$leave['roll_number']})";

        // Determine recipient
        if ($recipientType === 'guide') {
            $recipientEmail = $leave['guide_email'];
            $recipientName = $leave['guide_name'];
            $actionUrl = $this->getAbsoluteUrl("/faculty/pending_approvals.php");
        } elseif ($recipientType === 'ta_coordinator') {
            $recipientEmail = $leave['ta_coordinator_email'];
            $recipientName = $leave['ta_coordinator_name'];
            $actionUrl = $this->getAbsoluteUrl("/faculty/pending_approvals.php");
        } else {
            // HOD
            $recipientEmail = $this->getHODEmail();
            $recipientName = $this->getHODName();
            $actionUrl = $this->getAbsoluteUrl("/hod/pending_approvals.php");
        }

        if (!$recipientEmail) {
            logError("Cannot send leave application notification: recipient email not found");
            return false;
        }

        $leaveTypeLabel = ucfirst($leave['leave_type']) . ' Leave';

        $placeholders = [
            '{RECIPIENT_NAME}' => $this->text($recipientName),
            '{STUDENT_NAME}' => $this->text($leave['student_name']),
            '{ROLL_NUMBER}' => $this->text($leave['roll_number']),
            '{LEAVE_TYPE}' => $leaveTypeLabel,
            '{START_DATE}' => date('d M Y', strtotime($leave['start_date'])),
            '{END_DATE}' => date('d M Y', strtotime($leave['end_date'])),
            '{TOTAL_DAYS}' => $leave['total_days'],
            '{REASON}' => $this->text($leave['reason'], true),
            '{ACTION_URL}' => $actionUrl,
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('leave_application.html', $placeholders);

        return $this->sendEmail($recipientEmail, $subject, $body, 'leave_application', $leave['id'], $recipientName);
    }

    /**
     * Send guide approval notification to TA coordinator
     * @param array $leave Leave application data
     * @return bool Success status
     */
    public function sendGuideApproval($leave) {
        return $this->sendLeaveApplication($leave, 'ta_coordinator');
    }

    /**
     * Send TA coordinator approval notification to HOD
     * @param array $leave Leave application data
     * @return bool Success status
     */
    public function sendTAApproval($leave) {
        return $this->sendLeaveApplication($leave, 'hod');
    }

    /**
     * Send HOD approval notification to student
     * @param array $leave Leave application data
     * @return bool Success status
     */
    public function sendHODApproval($leave) {
        $subject = "Leave Application Approved - " . ucfirst($leave['leave_type']) . " Leave";

        $placeholders = [
            '{STUDENT_NAME}' => $this->text($leave['student_name']),
            '{LEAVE_TYPE}' => ucfirst($leave['leave_type']) . ' Leave',
            '{START_DATE}' => date('d M Y', strtotime($leave['start_date'])),
            '{END_DATE}' => date('d M Y', strtotime($leave['end_date'])),
            '{TOTAL_DAYS}' => $leave['total_days'],
            '{DASHBOARD_URL}' => $this->getAbsoluteUrl("/student/dashboard.php"),
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('approval_notification.html', $placeholders);

        return $this->sendEmail($leave['student_email'], $subject, $body, 'approval', $leave['id'], $leave['student_name']);
    }

    /**
     * Send rejection notification to student
     * @param array $leave Leave application data — read AFTER the decision, so the
     *                     stage's stored remarks are present
     * @param string $rejectBy Who rejected (guide, ta_coordinator, hod, admin)
     * @param string|null $customRemarks The remarks the approver just entered; used in
     *                     preference to the stored ones. Callers pass them: the
     *                     leave row they held was often read before the decision,
     *                     so every student was told "No remarks provided".
     * @param bool $isCancellation Whether this is a cancellation (affects email subject/wording)
     * @return bool Success status
     */
    public function sendRejection($leave, $rejectBy, $customRemarks = null, $isCancellation = false) {
        // Use appropriate subject based on whether it's cancellation or rejection
        if ($isCancellation) {
            $subject = "Leave Application Cancelled - " . ucfirst($leave['leave_type']) . " Leave";
            $rejectorLabel = 'Admin (Cancelled)';
        } else {
            $subject = "Leave Application Rejected - " . ucfirst($leave['leave_type']) . " Leave";
            $rejectorLabel = ucfirst(str_replace('_', ' ', $rejectBy));
        }

        // Get rejection/cancellation remarks
        $remarks = '';
        if ($customRemarks !== null && trim((string) $customRemarks) !== '') {
            $remarks = $customRemarks;
        } elseif ($rejectBy === 'guide' && !empty($leave['guide_remarks'])) {
            $remarks = $leave['guide_remarks'];
        } elseif ($rejectBy === 'ta_coordinator' && !empty($leave['ta_remarks'])) {
            $remarks = $leave['ta_remarks'];
        } elseif ($rejectBy === 'hod' && !empty($leave['hod_remarks'])) {
            $remarks = $leave['hod_remarks'];
        } elseif ($rejectBy === 'admin' && !empty($leave['admin_remarks'])) {
            $remarks = $leave['admin_remarks'];
        }

        $placeholders = [
            '{STUDENT_NAME}' => $this->text($leave['student_name']),
            '{LEAVE_TYPE}' => ucfirst($leave['leave_type']) . ' Leave',
            '{START_DATE}' => date('d M Y', strtotime($leave['start_date'])),
            '{END_DATE}' => date('d M Y', strtotime($leave['end_date'])),
            '{TOTAL_DAYS}' => $leave['total_days'],
            '{REJECTED_BY}' => $rejectorLabel,
            '{REMARKS}' => trim((string) $remarks) !== '' ? $this->text($remarks, true) : 'No remarks provided',
            '{DASHBOARD_URL}' => $this->getAbsoluteUrl("/student/dashboard.php"),
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('rejection_notification.html', $placeholders);

        $emailType = $isCancellation ? 'cancellation' : 'rejection';
        return $this->sendEmail($leave['student_email'], $subject, $body, $emailType, $leave['id'], $leave['student_name']);
    }

    /**
     * Send admin notification for adjacent leave detection
     * @param array $leave New leave application data
     * @param array $adjacentLeave Adjacent leave data
     * @return bool Success status
     */
    public function sendAdjacentLeaveNotification($leave, $adjacentLeave) {
        $admins = $this->getAdminEmails();

        if (empty($admins)) {
            logError("Cannot send adjacent leave notification: no admin emails found");
            return false;
        }

        $subject = "Adjacent Leave Alert - {$leave['student_name']} ({$leave['roll_number']})";

        $body = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #e67e22;'>⚠️ Adjacent Leave Detected</h2>
                <p>A new leave application has been submitted that is adjacent to an existing leave (separated only by weekends/holidays).</p>

                <div style='background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #856404;'>New Leave Application</h3>
                    <table style='width: 100%;'>
                        <tr><td><strong>Student:</strong></td><td>{$leave['student_name']} ({$leave['roll_number']})</td></tr>
                        <tr><td><strong>Leave Type:</strong></td><td>" . ucfirst($leave['leave_type']) . "</td></tr>
                        <tr><td><strong>Dates:</strong></td><td>" . date('d M Y', strtotime($leave['start_date'])) . " - " . date('d M Y', strtotime($leave['end_date'])) . "</td></tr>
                        <tr><td><strong>Days:</strong></td><td>{$leave['total_days']}</td></tr>
                    </table>
                </div>

                <div style='background: #d1ecf1; border: 1px solid #17a2b8; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #0c5460;'>Adjacent Existing Leave</h3>
                    <table style='width: 100%;'>
                        <tr><td><strong>Leave ID:</strong></td><td>#{$adjacentLeave['id']}</td></tr>
                        <tr><td><strong>Leave Type:</strong></td><td>" . ucfirst($adjacentLeave['leave_type']) . "</td></tr>
                        <tr><td><strong>Dates:</strong></td><td>" . date('d M Y', strtotime($adjacentLeave['start_date'])) . " - " . date('d M Y', strtotime($adjacentLeave['end_date'])) . "</td></tr>
                        <tr><td><strong>Status:</strong></td><td>{$adjacentLeave['status']}</td></tr>
                    </table>
                </div>

                <p style='color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px;'>
                    <strong>Action Required:</strong> These leaves may need to be combined. Please review in the admin panel.
                </p>

                <p style='margin-top: 20px;'>
                    <a href='" . $this->getAbsoluteUrl("/admin/adjacent_leaves.php") . "'
                       style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                        Review Adjacent Leaves
                    </a>
                </p>
            </div>
        </body>
        </html>";

        $success = true;
        foreach ($admins as $admin) {
            $result = $this->sendEmail($admin['email'], $subject, $body, 'adjacent_leave_alert', $leave['id'], $admin['name']);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send admin notification for extended leave requiring Dean AP approval
     * @param array $leave Leave application data
     * @return bool Success status
     */
    public function sendAdminNotification($leave) {
        $admins = $this->getAdminEmails();

        if (empty($admins)) {
            logError("Cannot send admin notification: no admin emails found");
            return false;
        }

        $subject = "Action Required: Extended Leave Application - {$leave['student_name']}";

        $placeholders = [
            '{STUDENT_NAME}' => $this->text($leave['student_name']),
            '{ROLL_NUMBER}' => $this->text($leave['roll_number']),
            '{LEAVE_TYPE}' => ucfirst($leave['leave_type']) . ' Leave',
            '{START_DATE}' => date('d M Y', strtotime($leave['start_date'])),
            '{END_DATE}' => date('d M Y', strtotime($leave['end_date'])),
            '{TOTAL_DAYS}' => $leave['total_days'],
            '{REASON}' => $this->text($leave['reason'], true),
            '{ACTION_URL}' => $this->getAbsoluteUrl("/admin/pending_approvals.php"),
            '{EXTENDED_THRESHOLD}' => EXTENDED_LEAVE_THRESHOLD,
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('admin_action_required.html', $placeholders);

        $success = true;
        foreach ($admins as $admin) {
            $result = $this->sendEmail($admin['email'], $subject, $body, 'admin_notification', $leave['id'], $admin['name']);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send monthly attendance reminder to students
     * @param array $students Array of student data
     * @return array Results with success count
     */
    public function sendMonthlyReminder($students) {
        $month = date('F Y');
        $subject = "Monthly Attendance Reminder - $month";

        $successCount = 0;
        $failedCount = 0;

        foreach ($students as $student) {
            $placeholders = [
                '{STUDENT_NAME}' => $student['name'],
                '{MONTH}' => $month,
                '{DASHBOARD_URL}' => $this->getAbsoluteUrl("/student/dashboard.php"),
                '{PERSONAL_BALANCE}' => $student['personal_leaves'] ?? 0,
                '{MEDICAL_BALANCE}' => $student['medical_leaves'] ?? 0,
                '{ACADEMIC_BALANCE}' => $student['academic_leaves'] ?? 0,
                '{SITE_NAME}' => SITE_NAME
            ];

            $body = $this->loadTemplate('monthly_reminder.html', $placeholders);

            if ($this->sendEmail($student['email'], $subject, $body, 'monthly_reminder', null, $student['name'])) {
                $successCount++;
            } else {
                $failedCount++;
            }

            // Small delay to avoid overwhelming SMTP server
            usleep(100000); // 0.1 second
        }

        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'total' => count($students)
        ];
    }

    /**
     * Send a daily reminder to a faculty member listing pending leave applications
     * from their students that are awaiting their action.
     *
     * @param array $faculty Faculty user row (id, name, email)
     * @param string $role 'guide' or 'ta_coordinator'
     * @param array $pendingLeaves Rows with student_name, roll_number, leave_type,
     *                             start_date, end_date, total_days, created_at
     * @return bool Success status
     */
    public function sendPendingLeavesReminder($faculty, $role, $pendingLeaves) {
        if (empty($pendingLeaves)) {
            return false;
        }

        $roleLabel = $role === 'guide' ? 'Faculty Guide' : 'TA Coordinator';
        $count = count($pendingLeaves);
        $subject = "Reminder: $count pending leave approval(s) - " . SITE_NAME;

        $today = new DateTime('today');
        $rowsHtml = '';
        foreach ($pendingLeaves as $leave) {
            $createdAt = new DateTime($leave['created_at']);
            $waitingDays = (int)$today->diff($createdAt)->days;
            $waitingLabel = $waitingDays === 0 ? 'today' : ($waitingDays === 1 ? '1 day' : "$waitingDays days");

            $rowsHtml .= '<tr>'
                . '<td>' . $this->text($leave['student_name']) . '</td>'
                . '<td>' . $this->text($leave['roll_number']) . '</td>'
                . '<td>' . htmlspecialchars(ucfirst($leave['leave_type'])) . '</td>'
                . '<td>' . date('d M Y', strtotime($leave['start_date'])) . ' &ndash; ' . date('d M Y', strtotime($leave['end_date'])) . '</td>'
                . '<td>' . (int)$leave['total_days'] . '</td>'
                . '<td class="waiting">' . $waitingLabel . '</td>'
                . '</tr>';
        }

        $placeholders = [
            '{RECIPIENT_NAME}' => $faculty['name'],
            '{ROLE_LABEL}' => $roleLabel,
            '{PENDING_COUNT}' => $count,
            '{PENDING_ROWS}' => $rowsHtml,
            '{ACTION_URL}' => $this->getAbsoluteUrl("/faculty/pending_approvals.php"),
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('pending_leaves_reminder.html', $placeholders);

        return $this->sendEmail($faculty['email'], $subject, $body, 'pending_leaves_reminder', null, $faculty['name']);
    }

    /**
     * Ask one faculty member to sign off a month of attendance for ALL of their
     * students, in a single combined email.
     *
     * @param array  $faculty ['id','name','email']
     * @param string $role    'ta' | 'guide' — the stage they are being asked about
     * @param string $period  e.g. "July 2026"
     * @param array  $rows    att_month_approvals rows joined with student name/roll
     * @return bool
     */
    public function sendAttendanceApprovalRequest($faculty, $role, $period, $rows)
    {
        if (empty($rows)) {
            return false;
        }

        $roleLabel = $role === 'ta' ? 'TA Coordinator' : 'Faculty Guide';
        $count = count($rows);
        $subject = "Attendance sign-off: $period — $count student(s) - " . SITE_NAME;

        // The guide reviews only after the TA has confirmed, so say so — it
        // explains why the guide is seeing this now and what it already carries.
        if ($role === 'ta') {
            $stageNote = 'You are the first reviewer. After you approve, each record goes to the '
                       . 'student&rsquo;s faculty guide for final sign-off.';
        } else {
            // Do not claim a TA review that never happened: these students reach
            // the guide directly precisely because they have no TA coordinator.
            $noTaReview = true;
            foreach ($rows as $r) {
                if (($r['ta_status'] ?? '') === 'approved') {
                    $noTaReview = false;
                    break;
                }
            }
            $stageNote = $noTaReview
                ? 'These students have no TA coordinator assigned, so they come straight to you. '
                  . 'Yours is the only sign-off.'
                : 'The TA coordinator has already reviewed these records. Yours is the final sign-off.';
        }

        $rowsHtml = '';
        foreach ($rows as $r) {
            // Flag anything with an absence so it draws the eye — that is the
            // whole point of a human reviewing the month.
            $absent = (int) $r['absent_days'];
            $pctRaw = $r['percentage'];
            $rowStyle = $absent > 0 ? ' style="background:#fef2f2"' : '';
            $rowsHtml .= '<tr' . $rowStyle . '>'
                . '<td>' . $this->text($r['student_name']) . '</td>'
                . '<td>' . $this->text($r['roll_number']) . '</td>'
                . '<td style="text-align:center">' . (int) $r['working_days'] . '</td>'
                . '<td style="text-align:center">' . (int) $r['present_days'] . '</td>'
                . '<td style="text-align:center">' . (int) $r['excused_days'] . '</td>'
                . '<td style="text-align:center"><b>' . $absent . '</b></td>'
                . '<td style="text-align:center">'
                . ($pctRaw === null ? 'n/a' : number_format((float) $pctRaw, 1) . '%') . '</td>'
                . '</tr>';
        }

        $placeholders = [
            '{RECIPIENT_NAME}' => $faculty['name'],
            '{ROLE_LABEL}'     => $roleLabel,
            '{PERIOD}'         => $period,
            '{STUDENT_COUNT}'  => $count,
            '{STAGE_NOTE}'     => $stageNote,
            '{REPORT_ROWS}'    => $rowsHtml,
            '{ACTION_URL}'     => $this->getAbsoluteUrl('/faculty/attendance_approvals.php'),
            '{SITE_NAME}'      => SITE_NAME,
        ];

        $body = $this->loadTemplate('attendance_approval_request.html', $placeholders);

        return $this->sendEmail($faculty['email'], $subject, $body,
            'attendance_approval_request', null, $faculty['name']);
    }

    /**
     * Tell the admins a student has asked for their guide / TA to be corrected.
     *
     * This is time-sensitive in a way an ordinary notification is not: the
     * student's leave application is HELD until someone decides, so the email
     * says so plainly rather than reading as something to get to eventually.
     *
     * @param array $request A row from GuideTAChange::getById()
     * @return bool
     */
    public function sendGuideTAChangeRequest($request)
    {
        if (empty($request)) {
            return false;
        }
        $admins = $this->getAdminEmails();
        if (empty($admins)) {
            logError('Cannot send guide/TA change request: no admin emails found');
            return false;
        }

        $subject = 'Approval needed: guide/TA change - ' . $request['student_name']
                 . ' - ' . SITE_NAME;

        // "unchanged" rather than a repeated name: the admin needs to see at a
        // glance which of the two is actually being asked about.
        $fmt = function ($current, $requested) {
            $current = $current ?: 'Not assigned';
            $requested = $requested ?: 'None';
            return $current === $requested
                ? $this->text($current) . ' <i>(unchanged)</i>'
                : $this->text($current) . ' &rarr; <b>' . $this->text($requested) . '</b>';
        };

        $placeholders = [
            '{STUDENT_NAME}'  => $this->text($request['student_name']),
            '{ROLL_NUMBER}'   => $this->text($request['roll_number']),
            '{GUIDE_CHANGE}'  => $fmt($request['current_guide_name'], $request['requested_guide_name']),
            '{TA_CHANGE}'     => $fmt($request['current_ta_name'], $request['requested_ta_name']),
            '{REASON}'        => $this->text($request['reason'], true),
            '{ACTION_URL}'    => $this->getAbsoluteUrl('/admin/guide_ta_requests.php'),
            '{SITE_NAME}'     => SITE_NAME,
        ];

        $body = $this->loadTemplate('guide_ta_change_request.html', $placeholders);

        $success = true;
        foreach ($admins as $admin) {
            if (!$this->sendEmail($admin['email'], $subject, $body,
                    'guide_ta_change_request', $request['id'], $admin['name'])) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Tell the student what the office decided, and what happened to the
     * applications that were waiting on it.
     *
     * @param array $request   A row from GuideTAChange::getById(), post-decision
     * @param array $released  Leave ids now in the approval chain
     * @param array $cancelled Leave ids cancelled for want of a guide
     * @return bool
     */
    public function sendGuideTAChangeDecision($request, array $released = [], array $cancelled = [])
    {
        if (empty($request) || empty($request['student_email'])) {
            return false;
        }

        $approved = $request['status'] === 'approved';
        $subject = ($approved ? 'Approved' : 'Not approved')
                 . ': your guide/TA change request - ' . SITE_NAME;

        if ($approved) {
            $outcome = 'The office has <b>approved</b> your request. Your records now show:';
            $detail = '<ul>'
                . '<li>Faculty Guide: <b>'
                . $this->text($request['requested_guide_name'] ?: 'Not assigned') . '</b></li>'
                . '<li>TA Coordinator: <b>'
                . $this->text($request['requested_ta_name'] ?: 'Not assigned') . '</b></li>'
                . '</ul>';
        } else {
            // Say what IS in force, not just that the answer was no — otherwise
            // the student is left guessing who their approver now is.
            $outcome = 'The office has <b>not approved</b> your request. '
                     . 'Your Faculty Guide and TA Coordinator are unchanged:';
            $detail = '<ul>'
                . '<li>Faculty Guide: <b>'
                . $this->text($request['current_guide_name'] ?: 'Not assigned') . '</b></li>'
                . '<li>TA Coordinator: <b>'
                . $this->text($request['current_ta_name'] ?: 'Not assigned') . '</b></li>'
                . '</ul>';
        }

        if ($released) {
            $detail .= '<p>' . count($released) . ' leave application(s) held for this decision '
                     . 'have now been sent for approval.</p>';
        }
        if ($cancelled) {
            $detail .= '<p style="color:#b91c1c">' . count($cancelled) . ' leave application(s) '
                     . 'could not be sent for approval because you still have no Faculty Guide '
                     . 'assigned, and have been cancelled. Please contact the PhD office.</p>';
        }

        $remarks = trim((string) ($request['admin_remarks'] ?? ''));

        $placeholders = [
            '{STUDENT_NAME}' => $this->text($request['student_name']),
            '{OUTCOME}'      => $outcome,
            '{DETAIL}'       => $detail,
            '{REMARKS}'      => $remarks !== ''
                ? '<div class="note"><b>Office remarks:</b><br>' . $this->text($remarks, true) . '</div>'
                : '',
            '{ACTION_URL}'   => $this->getAbsoluteUrl('/student/leave_history.php'),
            '{SITE_NAME}'    => SITE_NAME,
        ];

        $body = $this->loadTemplate('guide_ta_change_decision.html', $placeholders);

        return $this->sendEmail($request['student_email'], $subject, $body,
            'guide_ta_change_decision', $request['id'], $request['student_name']);
    }

    /**
     * Send monthly report to admins
     * @param array $admins Array of admin data
     * @param array $reportData Report statistics
     * @return bool Success status
     */
    public function sendMonthlyReport($admins, $reportData) {
        $month = date('F Y', strtotime($reportData['month']));
        $subject = "Monthly Leave Report - $month";

        $placeholders = [
            '{MONTH}' => $month,
            '{TOTAL_APPLICATIONS}' => $reportData['total_applications'] ?? 0,
            '{APPROVED_APPLICATIONS}' => $reportData['approved_applications'] ?? 0,
            '{REJECTED_APPLICATIONS}' => $reportData['rejected_applications'] ?? 0,
            '{PENDING_APPLICATIONS}' => $reportData['pending_applications'] ?? 0,
            '{PERSONAL_LEAVES}' => $reportData['personal_leaves'] ?? 0,
            '{MEDICAL_LEAVES}' => $reportData['medical_leaves'] ?? 0,
            '{ACADEMIC_LEAVES}' => $reportData['academic_leaves'] ?? 0,
            '{EXTENDED_LEAVES}' => $reportData['extended_leaves'] ?? 0,
            '{REPORT_URL}' => $this->getAbsoluteUrl("/admin"),
            '{SITE_NAME}' => SITE_NAME
        ];

        $body = $this->loadTemplate('monthly_report.html', $placeholders);

        $success = true;
        foreach ($admins as $admin) {
            $result = $this->sendEmail($admin['email'], $subject, $body, 'monthly_report', null, $admin['name']);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Send leave balance reset notification to a student
     * @param array $student Student data with name, email, joining_date
     * @param bool $academicEligible Whether student is eligible for academic leave
     * @return bool Success status
     */
    public function sendBalanceReset($student, $academicEligible, $resetDate = null) {
        // Name the cycle by the reset date, not today: a catch-up run for
        // 1 July sent in September must still say "July 2026".
        $month = date('F Y', strtotime($resetDate ?? 'today'));
        $subject = "Leave Balance Reset - $month";

        // Names are stored HTML-escaped; decode once so htmlspecialchars below
        // does not print "&amp;#039;".
        $name = html_entity_decode((string) $student['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = "Dear " . $name . ",\n\n";
        $message .= "Your leave balances have been reset for the new cycle ($month).\n\n";
        $message .= "New Balances:\n";
        $message .= "- Personal Leave: +" . PERSONAL_LEAVE_ALLOCATION . " days (carries forward)\n";
        $message .= "- Medical Leave: +" . MEDICAL_LEAVE_ALLOCATION . " days (carries forward)\n";

        if ($academicEligible) {
            $message .= "- Academic Leave: Reset to " . ACADEMIC_LEAVE_ALLOCATION . " days (does not carry forward)\n";
        } else {
            $message .= "- Academic Leave: Not yet eligible (available after " . ACADEMIC_LEAVE_ELIGIBILITY_MONTHS . " months)\n";
        }

        $message .= "\nYou can view your updated balances in the portal.\n\n";
        $message .= "Best regards,\n";
        $message .= SITE_NAME;

        $body = '<div style="font-family: sans-serif; line-height: 1.6;">'
              . nl2br(htmlspecialchars($message))
              . '</div>';

        return $this->sendEmail($student['email'], $subject, $body, 'leave_reset', null, $name);
    }

    /**
     * Core email sending method with retry logic
     * @param string $email Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string $emailType Type of email for logging
     * @param int|null $referenceId Reference ID (e.g., leave_id)
     * @param string|null $recipientName Recipient name
     * @return bool true only if the SMTP server accepted the message. Never throws:
     *              every failure is logged (error log + email_logs) and returned
     *              as false.
     */
    private function sendEmail($email, $subject, $body, $emailType, $referenceId = null, $recipientName = null) {
        $attempts = 0;
        $lastError = null;

        // Retrying cannot fix a mailer that was never configured.
        if ($this->initError !== null) {
            $this->logEmail($email, $subject, $emailType, $referenceId, 'failed',
                'Mailer not initialised: ' . $this->initError);
            return false;
        }

        while ($attempts < $this->maxRetries) {
            try {
                $attempts++;

                // Clear previous recipients
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();

                // Set recipient
                if ($recipientName) {
                    $this->mailer->addAddress($email, $recipientName);
                } else {
                    $this->mailer->addAddress($email);
                }

                // Set subject and body
                $this->mailer->Subject = $subject;
                $this->mailer->Body = $body;
                $this->mailer->AltBody = strip_tags($body);

                // Send email
                $result = $this->mailer->send();

                if ($result) {
                    // Log success
                    $this->logEmail($email, $subject, $emailType, $referenceId, 'sent', null);
                    return true;
                }

            } catch (Throwable $e) {
                // Throwable, not just PHPMailer's Exception: anything else escaping
                // here would reach callers that only look at the return value.
                $lastError = $e->getMessage();
                logError("Email send attempt $attempts failed: " . $lastError, [
                    'email' => $email,
                    'type' => $emailType,
                    'reference_id' => $referenceId
                ]);

                // Wait before retry (exponential backoff)
                if ($attempts < $this->maxRetries) {
                    sleep(pow(2, $attempts)); // 2, 4, 8 seconds
                }
            }
        }

        // All attempts failed - log failure
        $this->logEmail($email, $subject, $emailType, $referenceId, 'failed', $lastError);
        return false;
    }

    /**
     * Log email attempt to database
     */
    private function logEmail($recipientEmail, $subject, $emailType, $referenceId, $status, $errorMessage) {
        try {
            // Get recipient user ID if exists
            $recipientId = null;
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute([':email' => $recipientEmail]);
            $user = $stmt->fetch();
            if ($user) {
                $recipientId = $user['id'];
            }

            $sql = "INSERT INTO email_logs
                    (recipient_id, recipient_email, subject, email_type, reference_id, status, error_message)
                    VALUES
                    (:recipient_id, :recipient_email, :subject, :email_type, :reference_id, :status, :error_message)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':recipient_id' => $recipientId,
                ':recipient_email' => $recipientEmail,
                ':subject' => $subject,
                ':email_type' => $emailType,
                ':reference_id' => $referenceId,
                ':status' => $status,
                ':error_message' => $errorMessage
            ]);

        } catch (PDOException $e) {
            logError("Failed to log email: " . $e->getMessage());
        }
    }

    /**
     * Load email template and replace placeholders
     */
    private function loadTemplate($templateName, $placeholders = []) {
        $templatePath = EMAIL_TEMPLATES_DIR . $templateName;

        if (!file_exists($templatePath)) {
            logError("Email template not found: $templatePath");
            return "Email template not found";
        }

        $template = file_get_contents($templatePath);

        foreach ($placeholders as $key => $value) {
            $template = str_replace($key, $value, $template);
        }

        return $template;
    }

    /**
     * Get HOD email
     */
    private function getHODEmail() {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE role = 'hod' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $hod = $stmt->fetch();
        return $hod ? $hod['email'] : null;
    }

    /**
     * Get HOD name
     */
    private function getHODName() {
        $stmt = $this->db->prepare("SELECT name FROM users WHERE role = 'hod' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $hod = $stmt->fetch();
        return $hod ? $hod['name'] : 'HOD';
    }

    /**
     * Get all admin emails
     */
    private function getAdminEmails() {
        $stmt = $this->db->prepare("SELECT id, name, email FROM users WHERE role = 'admin' AND is_active = 1");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Test email configuration
     */
    public function testConnection() {
        try {
            $this->mailer->SMTPDebug = SMTP::DEBUG_CONNECTION;
            return $this->mailer->smtpConnect();
        } catch (Exception $e) {
            logError("SMTP connection test failed: " . $e->getMessage());
            return false;
        }
    }
}
