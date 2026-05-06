<?php
/**
 * User Class
 * Handles all user-related operations
 */

class User {
    private $db;
    private $id;
    private $name;
    private $email;
    private $role;
    private $is_active;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Create new user
     */
    public function create($data) {
        try {
            // Validate email domain
            if (!$this->validateEmailDomain($data['email'])) {
                throw new Exception("Email must be from " . ALLOWED_DOMAIN . " domain");
            }

            // Generate invitation token
            $token = bin2hex(random_bytes(32));
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_HOURS . ' hours'));

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

            // Log action
            $this->logAction($userId, 'user_created', 'users', "User {$data['email']} created");

            return [
                'success' => true,
                'user_id' => $userId,
                'token' => $token
            ];

        } catch (Exception $e) {
            logError("User creation failed: " . $e->getMessage());
            throw new Exception("Failed to create user: " . $e->getMessage());
        }
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
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $checkStmt->execute([$facultyGuideId]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Invalid faculty guide selected - user does not exist or is inactive");
            }
        }

        // Check if TA coordinator exists (if provided)
        if ($taCoordinatorId) {
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
            $checkStmt->execute([$taCoordinatorId]);
            if (!$checkStmt->fetch()) {
                throw new Exception("Invalid TA coordinator selected - user does not exist or is inactive");
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

            if ($user && password_verify($password, $user['password'])) {
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
        $sql = "SELECT * FROM users WHERE invitation_token = :token AND token_expires_at > NOW()";
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
                $this->logAction($userId, 'user_updated', 'users', "User ID $userId updated");
            }

            return $result;

        } catch (PDOException $e) {
            logError("User update failed: " . $e->getMessage());
            throw new Exception("Failed to update user");
        }
    }

    /**
     * Update student's faculty guide and TA coordinator
     */
    public function updateStudentGuideAndTA($userId, $facultyGuideId, $taCoordinatorId) {
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
                $checkStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
                $checkStmt->execute([$facultyGuideId]);
                if (!$checkStmt->fetch()) {
                    throw new Exception("Invalid faculty guide selected - user does not exist or is inactive");
                }
            }

            // Validate TA coordinator exists if provided
            if ($taCoordinatorId) {
                $checkStmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
                $checkStmt->execute([$taCoordinatorId]);
                if (!$checkStmt->fetch()) {
                    throw new Exception("Invalid TA coordinator selected - user does not exist or is inactive");
                }
            }

            // Get student ID from user ID
            $studentStmt = $this->db->prepare("SELECT id FROM students WHERE user_id = ?");
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

            if ($result) {
                $this->logAction($userId, 'student_guide_ta_updated', 'students', "Faculty guide and TA coordinator updated");
            }

            return $result;

        } catch (Exception $e) {
            logError("Student guide and TA update failed: " . $e->getMessage());
            throw new Exception("Failed to update student guide and TA: " . $e->getMessage());
        }
    }

    /**
     * Delete user (soft delete by deactivating)
     */
    public function delete($userId) {
        $sql = "UPDATE users SET is_active = 0 WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $userId]);
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
     * Log user action
     */
    private function logAction($userId, $action, $module, $description) {
        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, :module, :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':module' => $module,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    // Getters
    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getEmail() { return $this->email; }
    public function getRole() { return $this->role; }
    public function isActive() { return $this->is_active; }
}
