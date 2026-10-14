<?php
/**
 * User Class
 * Handles all user-related operations
 */

// Re-settles in-flight leave applications when a student's guide/TA changes.
require_once __DIR__ . '/Approval.php';

class User {
    // bcrypt (cost 10) of a random throwaway string; see authenticate().
    const TIMING_DUMMY_HASH = '$2y$10$9baagrNDC4TFNcvvUMx26uTGi1bTL5WLLLbm3P/qhPq7mVbHhQM5O';

    private $db;
    private $id;
    private $name;
    private $email;
    private $role;
    private $is_active;

    // Leave applications re-settled by the last updateStudentGuideAndTA().
    private $leaveRouting = ['moved' => [], 'returned' => [], 'stranded' => [], 'notify' => []];
    // Sign-off notifications produced by the last updateStudentGuideAndTA()
    // that ran inside a caller's transaction (see getPendingSignoffNotifications()).
    private $pendingSignoffNotify = [];

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Create new user
     *
     * The users, students and leave_balances rows are written in one
     * transaction. They used to be three independent autocommits, so a failure
     * part-way (a duplicate roll number, an invalid guide) left a users row with
     * no student record: an account that could sign up but had no balance and
     * broke every student page, and whose email could not be re-invited.
     * If the caller already holds a transaction, we join it and leave the
     * commit/rollback to the caller.
     *
     * @param int|null $actorId users.id of the admin creating the account
     *                          (defaults to the logged-in user)
     */
    public function create($data, $actorId = null) {
        $ownsTransaction = false;
        try {
            // Validate email domain
            if (!$this->validateEmailDomain($data['email'])) {
                throw new Exception("Email must be from " . ALLOWED_DOMAIN . " domain");
            }

            // Generate invitation token
            $token = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $ownsTransaction = true;
            }

            $sql = "INSERT INTO users (name, email, role, invitation_token, token_expires_at, is_active)
                    VALUES (:name, :email, :role, :token, :token_expires_at, 1)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':role' => $data['role'],
                ':token' => $token,
                ':token_expires_at' => $tokenExpiry
            ]);

            $userId = $this->db->lastInsertId();

            // If student, create student record
            if ($data['role'] === 'student') {
                $this->createStudentRecord($userId, $data);
            }

            if ($ownsTransaction) {
                $this->db->commit();
                $ownsTransaction = false;
            }

            // Logged against the admin who did it; this used to be recorded
            // under the new account's own id, as if the user had created itself.
            $this->logAction($actorId ?? $this->currentActorId(), 'user_created', 'users', sprintf(
                "User '%s' <%s> (%s, user #%d) created%s",
                $data['name'], $data['email'], $data['role'], $userId,
                $data['role'] === 'student' ? ', roll number ' . ($data['roll_number'] ?? '') : ''
            ));

            return [
                'success' => true,
                'user_id' => $userId,
                'token' => $token
            ];

        } catch (Exception $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            logError("User creation failed: " . $e->getMessage());
            throw new Exception("Failed to create user: " . $e->getMessage());
        }
    }

    /**
     * Resend the invitation to an account that has never set a password (the
     * first email failed, or the link expired), with a fresh token.
     *
     * $send(string $token): bool delivers the email. The new token is stored
     * only once it has been sent: storing it first meant a failed send left the
     * user with a dead link, even when the one they already had was still
     * valid. Returns false (old token untouched) when the send fails.
     */
    public function resendInvitation($userId, callable $send) {
        $check = $this->db->prepare("SELECT id FROM users WHERE id = ? AND password IS NULL AND is_active = 1");
        $check->execute([$userId]);
        if (!$check->fetch()) {
            throw new Exception("This account is inactive or has already completed sign-up");
        }

        $token = bin2hex(random_bytes(32));
        if (!$send($token)) {
            return false;
        }

        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));
        $stmt = $this->db->prepare(
            "UPDATE users SET invitation_token = :token, token_expires_at = :expires
             WHERE id = :id AND password IS NULL AND is_active = 1");
        $stmt->execute([':token' => $token, ':expires' => $tokenExpiry, ':id' => $userId]);

        if ($stmt->rowCount() !== 1) {
            // Signed up or deactivated while the email was going out.
            throw new Exception("This account is inactive or has already completed sign-up");
        }
        return true;
    }

    /**
     * Create student record
     */
    private function createStudentRecord($userId, $data) {
        // Validate faculty guide and TA coordinator exist if provided
        // Convert empty strings to null
        $facultyGuideId = $data['faculty_guide_id'] ?? null;
        if ($facultyGuideId === '' || $facultyGuideId === 0) {
            $facultyGuideId = null;
        }

        $taCoordinatorId = $data['ta_coordinator_id'] ?? null;
        if ($taCoordinatorId === '' || $taCoordinatorId === 0) {
            $taCoordinatorId = null;
        }

        // Check if faculty guide exists (if provided)
        if ($facultyGuideId) {
            // role = 'faculty' matters as much as is_active: without it any
            // active user id was accepted here, so a hand-made POST could name
            // another student, the HOD or an admin as a guide/TA coordinator.
            $checkStmt = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'faculty' AND is_active = 1");
            $checkStmt->execute([$facultyGuideId]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Invalid faculty guide selected - not an active faculty member");
            }
        }

        // Check if TA coordinator exists (if provided)
        if ($taCoordinatorId) {
            // role = 'faculty' matters as much as is_active: without it any
            // active user id was accepted here, so a hand-made POST could name
            // another student, the HOD or an admin as a guide/TA coordinator.
            $checkStmt = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'faculty' AND is_active = 1");
            $checkStmt->execute([$taCoordinatorId]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Invalid TA coordinator selected - not an active faculty member");
            }
        }

        // Insert student record - using ? placeholders to be safer
        $sql = "INSERT INTO students (user_id, roll_number, faculty_guide_id, ta_coordinator_id, joining_date)
                VALUES (?, ?, ?, ?, ?)";

        $insertStmt = $this->db->prepare($sql);
        $insertStmt->execute([
            $userId,
            $data['roll_number'],
            $facultyGuideId,
            $taCoordinatorId,
            $data['joining_date']
        ]);

        // Get the last inserted ID immediately after insert
        $studentId = $this->db->lastInsertId();

        // Initialize leave balances (with optional custom balances for backdated students)
        $customBalances = $data['custom_leave_balances'] ?? null;
        $this->initializeLeaveBalance($studentId, $data['joining_date'], $customBalances);
    }

    /**
     * Initialize leave balance for new student
     * @param int $studentId The student ID
     * @param string $joiningDate The joining date
     * @param array|null $customBalances Optional custom balances for backdated students
     */
    private function initializeLeaveBalance($studentId, $joiningDate, $customBalances = null) {
        $isEligibleForAcademic = $this->isEligibleForAcademicLeave($joiningDate);
        $resetDate = date('Y-m-d');

        // Use custom balances if provided, otherwise use default allocations
        if ($customBalances !== null) {
            $personalLeaves = $customBalances['personal'];
            $medicalLeaves = $customBalances['medical'];
            $academicLeaves = $customBalances['academic'];
        } else {
            $personalLeaves = PERSONAL_LEAVE_ALLOCATION;
            $medicalLeaves = MEDICAL_LEAVE_ALLOCATION;
            $academicLeaves = $isEligibleForAcademic ? ACADEMIC_LEAVE_ALLOCATION : 0;
        }

        $sql = "INSERT INTO leave_balances (student_id, personal_leaves, medical_leaves, academic_leaves, last_reset_personal, last_reset_medical, last_reset_academic)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $studentId,
            $personalLeaves,
            $medicalLeaves,
            $academicLeaves,
            $resetDate,
            $resetDate,
            $resetDate
        ]);
    }

    /**
     * Check if student is eligible for academic leave (>1 year)
     */
    private function isEligibleForAcademicLeave($joiningDate) {
        $joiningTimestamp = strtotime($joiningDate);
        $eligibilityDate = strtotime('-' . ACADEMIC_LEAVE_ELIGIBILITY_MONTHS . ' months');
        return $joiningTimestamp <= $eligibilityDate;
    }

    /**
     * Validate email domain
     */
    private function validateEmailDomain($email) {
        $domain = substr(strrchr($email, "@"), 1);
        return $domain === ALLOWED_DOMAIN;
    }

    /**
     * Authenticate user
     */
    public function authenticate($email, $password) {
        try {
            $sql = "SELECT * FROM users WHERE email = :email AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':email' => $email]);

            $user = $stmt->fetch();

            // Always run one bcrypt verification. Skipping it when no account
            // matched (or the account has no password yet) made those replies
            // measurably faster, so response time revealed which emails have
            // active accounts. The dummy hash uses the same cost (10) as real ones.
            $hasPassword = $user && !empty($user['password']);
            $verified = password_verify(
                (string) $password,
                $hasPassword ? $user['password'] : self::TIMING_DUMMY_HASH
            );

            if ($hasPassword && $verified) {
                $this->loadUserData($user);
                $this->logAction($user['id'], 'login', 'auth', 'User logged in');
                return true;
            }

            return false;

        } catch (PDOException $e) {
            logError("Authentication failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load user data
     */
    private function loadUserData($user) {
        $this->id = $user['id'];
        $this->name = $user['name'];
        $this->email = $user['email'];
        $this->role = $user['role'];
        $this->is_active = $user['is_active'];
    }

    /**
     * Get user by ID
     */
    public function getById($id) {
        $sql = "SELECT * FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Get user by email
     */
    public function getByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        return $stmt->fetch();
    }

    /**
     * The account a sign-in or reset address refers to: the account with exactly
     * that email, or else the one active person a name-based IITB address points
     * at (see findByNameAlias). Returns [account row or null, true when it was
     * found through the name]. Both lookups always run, so an unknown address
     * costs the same work as a known one.
     */
    public function resolveAccount($email) {
        try {
            $exact = $this->getByEmail($email);
            $alias = $this->findByNameAlias($email);
        } catch (PDOException $e) {
            logError("Account lookup failed: " . $e->getMessage());
            return [null, false];
        }
        if ($exact) {
            return [$exact, false];
        }
        return $alias ? [$alias, true] : [null, false];
    }

    /**
     * The one active account whose holder's name matches a name-based address.
     *
     * Many accounts are registered under the roll-number address (25d1521@)
     * while students type their name-based one (roshni.tiwari@) — the same
     * IITB mailbox, but no account has it, so sign-in and password reset
     * silently failed. Matched on the letters of the address against the name:
     * first+last, last+first or the full name ("roshni.tiwari", "ananyagupta",
     * "kalro.arti"), else first...last with a middle part ("prashantrajmishra"
     * for "Prashant Mishra"). Exactly one match or nothing; roll-number
     * addresses are never guessed around.
     *
     * @return array|null the matched users row
     */
    public function findByNameAlias($email) {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        if (preg_match('/^\d{2}[a-z]\d{4}$/', $local)) {
            return null;
        }
        $typed = preg_replace('/[^a-z]/', '', $local);
        if (strlen($typed) < 5) {
            return null;
        }

        $honorifics = ['prof', 'dr', 'mr', 'mrs', 'ms', 'miss', 'shri', 'smt', 'sri'];
        $exact = [];
        $loose = [];
        foreach ($this->db->query("SELECT * FROM users WHERE is_active = 1")->fetchAll() as $u) {
            if (strtolower(substr(strrchr($u['email'], '@'), 1)) !== $domain) {
                continue;
            }
            $tokens = array_values(array_diff(
                preg_split('/[^a-z]+/', strtolower($u['name']), -1, PREG_SPLIT_NO_EMPTY),
                $honorifics
            ));
            if (count($tokens) < 2) {
                continue;   // a single name ("Renu") is too ambiguous to match on
            }
            $first = $tokens[0];
            $last = $tokens[count($tokens) - 1];
            if (in_array($typed, [implode('', $tokens), $first . $last, $last . $first], true)) {
                $exact[] = $u;
            } elseif (strlen($first) >= 3 && strlen($last) >= 3
                && strlen($typed) > strlen($first) + strlen($last)
                && ((str_starts_with($typed, $first) && str_ends_with($typed, $last))
                    || (str_starts_with($typed, $last) && str_ends_with($typed, $first)))) {
                $loose[] = $u;
            }
        }
        if (count($exact) === 1) {
            return $exact[0];
        }
        return (!$exact && count($loose) === 1) ? $loose[0] : null;
    }

    /**
     * Update user profile
     */
    public function updateProfile($userId, $data) {
        $sql = "UPDATE users SET name = :name, updated_at = NOW()";
        $params = [
            ':name' => $data['name'],
            ':id' => $userId
        ];

        if (isset($data['phone'])) {
            $sql .= ", phone = :phone";
            $params[':phone'] = $data['phone'];
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get user by token
     */
    public function getByToken($token) {
        // is_active: an account deactivated before its owner signed up must not
        // be able to finish sign-up with the old invitation link.
        $sql = "SELECT * FROM users WHERE invitation_token = :token AND token_expires_at > NOW() AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token' => $token]);
        return $stmt->fetch();
    }

    /**
     * Generate password reset token
     */
    public function generatePasswordResetToken($userId) {
        try {
            $token = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

            $sql = "UPDATE users SET password_reset_token = :token, password_reset_expires_at = :expires_at
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':token' => $token,
                ':expires_at' => $tokenExpiry,
                ':id' => $userId
            ]);

            if ($result) {
                $this->logAction($userId, 'password_reset_requested', 'auth', 'Password reset token generated');
                return [
                    'success' => true,
                    'token' => $token
                ];
            }

            return ['success' => false];

        } catch (PDOException $e) {
            logError("Password reset token generation failed: " . $e->getMessage());
            return ['success' => false];
        }
    }

    /**
     * Validate password reset token
     */
    public function validatePasswordResetToken($token) {
        try {
            $sql = "SELECT * FROM users WHERE password_reset_token = :token AND password_reset_expires_at > NOW() AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':token' => $token]);

            $user = $stmt->fetch();
            return $user ?: null;

        } catch (PDOException $e) {
            logError("Password reset token validation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Reset password with token validation
     */
    public function resetPassword($userId, $password, $token) {
        try {
            // Verify token is still valid
            $sql = "SELECT * FROM users WHERE id = :id AND password_reset_token = :token AND password_reset_expires_at > NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $userId,
                ':token' => $token
            ]);

            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Invalid or expired token'];
            }

            // Update password and clear reset token
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $sql = "UPDATE users SET password = :password, password_reset_token = NULL, password_reset_expires_at = NULL
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':password' => $hashedPassword,
                ':id' => $userId
            ]);

            if ($result) {
                $this->logAction($userId, 'password_reset_completed', 'auth', 'Password reset completed successfully');
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'Failed to reset password'];

        } catch (PDOException $e) {
            logError("Password reset failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }

    /**
     * Set password for user (signup completion)
     */
    public function setPassword($userId, $password) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $sql = "UPDATE users SET password = :password, invitation_token = NULL, token_expires_at = NULL
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':password' => $hashedPassword,
            ':id' => $userId
        ]);
    }

    /**
     * Update user
     */
    public function update($userId, $data) {
        try {
            $sql = "UPDATE users SET name = :name, email = :email, is_active = :is_active
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':name' => $data['name'],
                ':email' => $data['email'],
                ':is_active' => $data['is_active'],
                ':id' => $userId
            ]);

            if ($result) {
                $this->logAction($this->currentActorId(), 'user_updated', 'users',
                    "User '{$data['name']}' <{$data['email']}> (user #$userId) updated, is_active={$data['is_active']}");
            }

            return $result;

        } catch (PDOException $e) {
            logError("User update failed: " . $e->getMessage());
            throw new Exception("Failed to update user");
        }
    }

    /**
     * Update student's faculty guide and TA coordinator
     *
     * The roster change and the re-routing of the student's in-flight monthly
     * attendance sign-offs (AttendanceApproval::rerouteForStudent(), when that
     * exists) happen in one transaction: att_month_approvals snapshots the
     * routing when a month opens, so changing only the roster left a pending
     * sign-off waiting on the old guide/TA. If the re-route fails, the roster
     * change is rolled back too. When this call opened the transaction it also
     * sends the new approvers' notifications after committing; inside a
     * caller's transaction it leaves them to getPendingSignoffNotifications().
     *
     * @param int      $userId  the student's users.id
     * @param int|null $actorId users.id of the admin making the change
     *                          (defaults to the logged-in user)
     */
    public function updateStudentGuideAndTA($userId, $facultyGuideId, $taCoordinatorId, $actorId = null) {
        $actorId = $actorId ?? $this->currentActorId();
        $this->pendingSignoffNotify = [];
        $ownsTransaction = false;
        try {
            // Convert 0 and empty strings to null
            if ($facultyGuideId === '' || $facultyGuideId === 0) {
                $facultyGuideId = null;
            }
            if ($taCoordinatorId === '' || $taCoordinatorId === 0) {
                $taCoordinatorId = null;
            }

            // Validate faculty guide exists if provided
            if ($facultyGuideId) {
                // role = 'faculty' matters as much as is_active: without it any
            // active user id was accepted here, so a hand-made POST could name
            // another student, the HOD or an admin as a guide/TA coordinator.
            $checkStmt = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'faculty' AND is_active = 1");
                $checkStmt->execute([$facultyGuideId]);
                if (!$checkStmt->fetch()) {
                    throw new Exception("Invalid faculty guide selected - not an active faculty member");
                }
            }

            // Validate TA coordinator exists if provided
            if ($taCoordinatorId) {
                // role = 'faculty' matters as much as is_active: without it any
            // active user id was accepted here, so a hand-made POST could name
            // another student, the HOD or an admin as a guide/TA coordinator.
            $checkStmt = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'faculty' AND is_active = 1");
                $checkStmt->execute([$taCoordinatorId]);
                if (!$checkStmt->fetch()) {
                    throw new Exception("Invalid TA coordinator selected - not an active faculty member");
                }
            }

            // Read the current values before overwriting them, so the audit entry
            // can say what actually changed. It used to log the bare sentence
            // "Faculty guide and TA coordinator updated", which cannot answer the
            // one question anyone asks of it later: changed from what, to what?
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $ownsTransaction = true;
            }

            $studentStmt = $this->db->prepare(
                "SELECT s.id, s.faculty_guide_id, s.ta_coordinator_id, s.roll_number, u.name
                 FROM students s JOIN users u ON u.id = s.user_id
                 WHERE s.user_id = ? FOR UPDATE");
            $studentStmt->execute([$userId]);
            $student = $studentStmt->fetch();

            if (!$student) {
                throw new Exception("User is not a student");
            }

            $studentId = $student['id'];

            // Update student record
            $sql = "UPDATE students SET faculty_guide_id = ?, ta_coordinator_id = ? WHERE id = ?";
            $updateStmt = $this->db->prepare($sql);
            $result = $updateStmt->execute([$facultyGuideId, $taCoordinatorId, $studentId]);

            $oldGuide = $student['faculty_guide_id'] === null ? null : (int) $student['faculty_guide_id'];
            $oldTa = $student['ta_coordinator_id'] === null ? null : (int) $student['ta_coordinator_id'];
            $newGuide = $facultyGuideId ? (int) $facultyGuideId : null;
            $newTa = $taCoordinatorId ? (int) $taCoordinatorId : null;
            $routingChanged = ($oldGuide !== $newGuide) || ($oldTa !== $newTa);

            if ($result) {
                // Recorded against the admin who made the change, naming the
                // student. It used to be logged under the student's own user id,
                // so the audit trail read as if the student had re-routed their
                // own approvals.
                $names = $this->namesById(array_filter([$oldGuide, $newGuide, $oldTa, $newTa]));
                $fmt = function ($id) use ($names) {
                    return $id === null ? 'none' : sprintf("#%d %s", $id, $names[$id] ?? '?');
                };
                $this->logAction($actorId, 'student_guide_ta_updated', 'students', sprintf(
                    "Student '%s' (%s, user #%d, student_id %d): guide %s -> %s, TA %s -> %s",
                    $student['name'], $student['roll_number'], $userId, $studentId,
                    $fmt($oldGuide), $fmt($newGuide), $fmt($oldTa), $fmt($newTa)
                ));
            }

            $approval = null;
            $notify = [];
            if ($result && $routingChanged && ($approval = $this->attendanceApproval()) !== null) {
                $reroute = $approval->rerouteForStudent((int) $studentId, $actorId);
                $notify = is_array($reroute) ? ($reroute['notify'] ?? []) : [];
            }

            // In-flight leave applications: one waiting on a TA who no longer
            // exists (or on a guide who is now also the TA and already approved)
            // is settled on to the HOD, as approveByGuide() does for new ones.
            $this->leaveRouting = ['moved' => [], 'returned' => [], 'stranded' => [], 'notify' => []];
            if ($result && $routingChanged) {
                $this->leaveRouting = (new Approval($this->db))
                    ->resettleAfterRoutingChange((int) $studentId, $actorId, $oldGuide, $oldTa);
            }

            if ($ownsTransaction) {
                $this->db->commit();
                $ownsTransaction = false;
                // Emails only after the commit, so nobody is told about a
                // routing that was then rolled back.
                if (!empty($notify) && method_exists($approval, 'notifyTargets')) {
                    try {
                        $approval->notifyTargets($notify);
                    } catch (Exception $e) {
                        logError("Sign-off re-route notification failed for student_id $studentId: " . $e->getMessage());
                    }
                }
                if (!empty($this->leaveRouting['notify'])) {
                    Approval::sendRoutingNotifications($this->leaveRouting['notify']);
                }
            } else {
                $this->pendingSignoffNotify = $notify;
            }

            return $result;

        } catch (Exception $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            logError("Student guide and TA update failed: " . $e->getMessage());
            throw new Exception("Failed to update student guide and TA: " . $e->getMessage());
        }
    }

    /**
     * Notification targets from the last updateStudentGuideAndTA() that ran
     * inside the caller's transaction; pass them to
     * AttendanceApproval::notifyTargets() after committing.
     */
    public function getPendingSignoffNotifications() {
        return $this->pendingSignoffNotify;
    }

    /**
     * What the last updateStudentGuideAndTA() did to in-flight leave
     * applications: {moved: ids forwarded to the HOD, returned: ids sent back
     * to the guide (nobody had approved them), stranded: ids at
     * pending_guide with no guide left, notify: for Approval::sendRoutingNotifications()
     * when the caller owns the transaction}.
     */
    public function getLeaveRoutingResult() {
        return $this->leaveRouting;
    }

    /**
     * AttendanceApproval, if this install has rerouteForStudent() (it re-routes
     * pending monthly sign-offs after a roster change); null otherwise, so the
     * roster edit still works on an install without it.
     */
    private function attendanceApproval() {
        $file = __DIR__ . '/AttendanceApproval.php';
        if (!class_exists('AttendanceApproval') && is_file($file)) {
            require_once $file;
        }
        if (!class_exists('AttendanceApproval') || !method_exists('AttendanceApproval', 'rerouteForStudent')) {
            return null;
        }
        return new AttendanceApproval($this->db);
    }

    /**
     * users.id => name for the given ids (for readable audit entries).
     */
    private function namesById(array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->execute($ids);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[(int) $row['id']] = $row['name'];
        }
        return $names;
    }

    /**
     * Delete user (soft delete by deactivating)
     *
     * Deactivation sets users.is_active = 0, which is what login and the
     * per-request session check read, so the account's sessions end too.
     *
     * @param int|null $actorId users.id of the admin (defaults to the logged-in user)
     */
    public function delete($userId, $actorId = null) {
        $user = $this->getById($userId);
        $sql = "UPDATE users SET is_active = 0 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([':id' => $userId]);

        if ($result && $user) {
            $this->logAction($actorId ?? $this->currentActorId(), 'user_deactivated', 'users', sprintf(
                "User '%s' <%s> (%s, user #%d) deactivated",
                $user['name'], $user['email'], $user['role'], $userId
            ));
        }
        return $result;
    }

    /**
     * Work still routed to a faculty member: active students assigned to them
     * as guide or TA coordinator, leave applications waiting at their guide/TA
     * stage, and monthly attendance sign-offs waiting on them. Deactivating the
     * account does not move any of it, so the admin is told what to reassign.
     * The sign-off count is skipped where the attendance tables do not exist.
     *
     * @return array ['students' => int, 'leaves' => int, 'signoffs' => int]
     */
    public function facultyWorkload($userId) {
        $userId = (int) $userId;
        $count = function ($sql, array $params) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };

        $out = [
            'students' => $count(
                "SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id
                 WHERE u.is_active = 1 AND (s.faculty_guide_id = ? OR s.ta_coordinator_id = ?)",
                [$userId, $userId]),
            'leaves' => $count(
                "SELECT COUNT(*) FROM leave_applications la JOIN students s ON s.id = la.student_id
                 WHERE (la.status = 'pending_guide' AND s.faculty_guide_id = ?)
                    OR (la.status = 'pending_ta' AND s.ta_coordinator_id = ?)",
                [$userId, $userId]),
            'signoffs' => 0,
        ];

        $hasSignoffs = $this->db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'att_month_approvals'")->fetchColumn();
        if ($hasSignoffs) {
            $out['signoffs'] = $count(
                "SELECT COUNT(*) FROM att_month_approvals
                 WHERE (status = 'pending_ta' AND ta_coordinator_id = ?)
                    OR (status = 'pending_guide' AND faculty_guide_id = ?)",
                [$userId, $userId]);
        }
        return $out;
    }

    /**
     * What stops this account from being permanently deleted.
     *
     * Deleting a users row cascades to students and from there to
     * leave_applications, leave_adjustments and every att_* table keyed on the
     * student. A cascading delete does not fire triggers, so it also bypassed the
     * append-only guard on att_records: one click erased a student's whole
     * attendance and leave history. Approver references are SET NULL, which
     * would silently strip the name from sign-offs they gave. Anyone with any of
     * this history can only be deactivated.
     *
     * Tables or columns missing on this install (attendance not migrated yet)
     * are skipped. Returns a list of human-readable reasons; empty = deletable.
     */
    public function hardDeleteBlockers($userId) {
        $userId = (int) $userId;
        $stmt = $this->db->prepare("SELECT id FROM students WHERE user_id = ?");
        $stmt->execute([$userId]);
        $studentId = $stmt->fetchColumn();
        $studentId = $studentId === false ? null : (int) $studentId;

        // [label, table, columns, keyed by]
        $checks = [
            ['leave application(s)', 'leave_applications', ['student_id'], 'student'],
            ['leave balance adjustment(s)', 'leave_adjustments', ['student_id'], 'student'],
            ['attendance record(s)', 'att_records', ['student_id'], 'student'],
            ['attendance attempt(s)', 'att_attempts', ['student_id'], 'student'],
            ['face template(s)', 'att_face_templates', ['student_id'], 'student'],
            ['registered attendance device(s)', 'att_devices', ['student_id'], 'student'],
            ['attendance audit entr(ies)', 'att_audit', ['student_id'], 'student'],
            ['monthly attendance sign-off(s)', 'att_month_approvals', ['student_id'], 'student'],
            ['guide/TA change request(s)', 'guide_ta_change_requests', ['student_id'], 'student'],

            ['student(s) assigned to them as guide or TA coordinator', 'students',
                ['faculty_guide_id', 'ta_coordinator_id'], 'user'],
            ['monthly attendance sign-off(s) routed to or signed by them', 'att_month_approvals',
                ['faculty_guide_id', 'ta_coordinator_id', 'guide_acted_by', 'ta_acted_by'], 'user'],
            ['attendance record(s) entered by them', 'att_records', ['created_by_user_id'], 'user'],
            ['attendance audit entr(ies) by them', 'att_audit', ['user_id'], 'user'],
            ['guide/TA change request(s) naming them', 'guide_ta_change_requests',
                ['current_guide_id', 'current_ta_id', 'requested_guide_id', 'requested_ta_id'], 'user'],
        ];

        $existing = [];
        $stmt = $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()");
        foreach ($stmt->fetchAll() as $row) {
            $existing[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = true;
        }

        $blockers = [];
        foreach ($checks as [$label, $table, $columns, $keyedBy]) {
            $id = $keyedBy === 'student' ? $studentId : $userId;
            if ($id === null) {
                continue;
            }
            $columns = array_values(array_filter($columns, fn($c) => isset($existing["$table.$c"])));
            if (empty($columns)) {
                continue;
            }
            $where = implode(' OR ', array_map(fn($c) => "`$c` = ?", $columns));
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
            $countStmt->execute(array_fill(0, count($columns), $id));
            $count = (int) $countStmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = "$count $label";
            }
        }

        // Leave approvals have no approver column: the only record of who
        // approved at each stage is the system_logs row, which a delete would
        // orphan (user_id SET NULL).
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM system_logs WHERE user_id = ? AND module IN ('approvals', 'attendance')");
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            $blockers[] = "$count approval/sign-off action(s) in the audit log";
        }

        return $blockers;
    }

    /**
     * Permanently delete an already-deactivated account that has no history.
     * Throws with a readable reason when the delete is refused.
     *
     * @param int|null $actorId users.id of the admin (defaults to the logged-in user)
     */
    public function hardDelete($userId, $actorId = null) {
        $actorId = $actorId ?? $this->currentActorId();
        $ownsTransaction = false;
        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $ownsTransaction = true;
            }

            $stmt = $this->db->prepare(
                "SELECT u.id, u.name, u.email, u.role, u.is_active, s.roll_number
                 FROM users u LEFT JOIN students s ON s.user_id = u.id
                 WHERE u.id = ? FOR UPDATE");
            $stmt->execute([(int) $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                throw new Exception("User not found");
            }
            if ($user['role'] === 'admin') {
                throw new Exception("Cannot delete admin users");
            }
            if ((int) $user['is_active'] === 1) {
                throw new Exception("Deactivate the account before deleting it permanently");
            }

            $blockers = $this->hardDeleteBlockers($userId);
            if (!empty($blockers)) {
                throw new Exception(self::deleteRefusal($user['name'], implode(', ', $blockers)));
            }

            try {
                $del = $this->db->prepare("DELETE FROM users WHERE id = ?");
                $del->execute([(int) $userId]);
            } catch (PDOException $e) {
                // A RESTRICT foreign key (e.g. on the att_* tables) caught
                // something the checks above do not know about.
                if ($e->getCode() === '23000') {
                    throw new Exception(self::deleteRefusal($user['name'], 'records that reference this account'));
                }
                throw $e;
            }

            $this->logAction($actorId, 'user_deleted', 'users', sprintf(
                "User '%s' <%s> (%s, user #%d%s) permanently deleted",
                $user['name'], $user['email'], $user['role'], $userId,
                $user['roll_number'] !== null ? ', roll number ' . $user['roll_number'] : ''
            ));

            if ($ownsTransaction) {
                $this->db->commit();
            }
            return true;

        } catch (Exception $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private static function deleteRefusal($name, $what) {
        return "'" . html_entity_decode($name, ENT_QUOTES, 'UTF-8') . "' cannot be permanently deleted: "
            . "the account has $what, and deleting it would erase that history. "
            . "Keep the account deactivated instead; that already blocks sign-in.";
    }

    /**
     * Get all users by role
     */
    public function getAllByRole($role, $activeOnly = true) {
        $sql = "SELECT * FROM users WHERE role = :role";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':role' => $role]);
        return $stmt->fetchAll();
    }

    /**
     * Get all students with their details
     */
    public function getAllStudents($activeOnly = true) {
        $sql = "SELECT u.*, s.roll_number, s.joining_date, s.faculty_guide_id, s.ta_coordinator_id,
                       fg.name as guide_name, tc.name as ta_coordinator_name,
                       lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
                FROM users u
                JOIN students s ON u.id = s.user_id
                LEFT JOIN users fg ON s.faculty_guide_id = fg.id
                LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
                LEFT JOIN leave_balances lb ON s.id = lb.student_id
                WHERE u.role = 'student'";

        if ($activeOnly) {
            $sql .= " AND u.is_active = 1";
        }

        $sql .= " ORDER BY s.roll_number ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get students assigned to a faculty member (as guide or TA coordinator)
     */
    public function getStudentsForFaculty($facultyId, $role = 'guide') {
        $sql = "SELECT u.id, u.name, u.email, u.is_active,
                       s.id as student_id, s.roll_number, s.joining_date,
                       s.faculty_guide_id, s.ta_coordinator_id,
                       fg.name as guide_name, tc.name as ta_coordinator_name,
                       lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
                FROM users u
                JOIN students s ON u.id = s.user_id
                LEFT JOIN users fg ON s.faculty_guide_id = fg.id
                LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
                LEFT JOIN leave_balances lb ON s.id = lb.student_id
                WHERE u.role = 'student' AND u.is_active = 1 AND ";

        if ($role === 'guide') {
            $sql .= "s.faculty_guide_id = :faculty_id";
        } else {
            $sql .= "s.ta_coordinator_id = :faculty_id";
        }

        $sql .= " ORDER BY s.roll_number ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':faculty_id' => $facultyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get student details by user ID
     */
    public function getStudentDetails($userId) {
        $sql = "SELECT u.*, s.id as student_id, s.roll_number, s.joining_date,
                       s.faculty_guide_id, s.ta_coordinator_id,
                       fg.name as guide_name, fg.email as guide_email,
                       tc.name as ta_coordinator_name, tc.email as ta_coordinator_email,
                       lb.personal_leaves, lb.medical_leaves, lb.academic_leaves
                FROM users u
                JOIN students s ON u.id = s.user_id
                LEFT JOIN users fg ON s.faculty_guide_id = fg.id
                LEFT JOIN users tc ON s.ta_coordinator_id = tc.id
                LEFT JOIN leave_balances lb ON s.id = lb.student_id
                WHERE u.id = :user_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetch();
    }

    /**
     * The users.id of whoever is performing the current request, or null (CLI).
     */
    private function currentActorId() {
        return function_exists('getCurrentUserId') ? getCurrentUserId() : null;
    }

    /**
     * Log user action
     *
     * $userId is the ACTOR (who did it), not the account acted on; name the
     * affected account in $description. Goes through logSystemAction() when it
     * is loaded so impersonated sessions are annotated with the real admin.
     */
    private function logAction($userId, $action, $module, $description) {
        if (function_exists('logSystemAction')) {
            logSystemAction($userId, $action, $module, $description);
            return;
        }

        // Cron scripts load this class without includes/functions.php.
        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, :module, :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':module' => $module,
            ':description' => $description,
            // REMOTE_ADDR is the front proxy for every request on production;
            // getClientIp() (includes/auth.php) resolves the real client.
            ':ip_address' => (function_exists('getClientIp') ? getClientIp() : null) ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ]);
    }

    // Getters
    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getEmail() { return $this->email; }
    public function getRole() { return $this->role; }
    public function isActive() { return $this->is_active; }
}
