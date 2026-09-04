<?php
/**
 * Student Dashboard
 * Main dashboard showing leave balances, pending applications, and recent activity
 */

$pageTitle = 'Dashboard';

// Require student role
require_once __DIR__ . '/../includes/header.php';
requireRole('student');

// Initialize classes
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Holiday.php';

$userClass = new User();
$leaveClass = new Leave();
$holidayClass = new Holiday();

// Get current user ID
$userId = getCurrentUserId();

// Get student details with leave balances
$studentDetails = $userClass->getStudentDetails($userId);

if (!$studentDetails) {
    setFlashMessage('error', 'Student details not found. Please contact administrator.');
    header('Location: ' . BASE_URL . '/logout.php');
    exit();
}

$studentId = $studentDetails['student_id'];

// Get leave balances
$balance = $leaveClass->getBalance($studentId);

// Get pending leave days
$pendingPersonal = $leaveClass->getPendingLeaveDays($studentId, 'personal');
$pendingMedical = $leaveClass->getPendingLeaveDays($studentId, 'medical');
$pendingAcademic = $leaveClass->getPendingLeaveDays($studentId, 'academic');

// Calculate available balances
$availablePersonal = ($balance['personal_leaves'] ?? 0) - $pendingPersonal;
$availableMedical = ($balance['medical_leaves'] ?? 0) - $pendingMedical;
$availableAcademic = ($balance['academic_leaves'] ?? 0) - $pendingAcademic;

// Get recent leave applications (last 5)
$recentLeaves = $leaveClass->getByStudent($studentId, []);
$recentLeaves = array_slice($recentLeaves, 0, 5);

// Get pending applications count
$pendingCount = count(array_filter($recentLeaves, function($leave) {
    return in_array($leave['status'], Leave::PENDING_STATUSES);
}));

// Get upcoming holidays
$upcomingHolidays = $holidayClass->getUpcoming(5);

// Check if eligible for academic leave
$isEligibleForAcademic = true; // Will be checked in the Leave class when applying
$joiningDate = $studentDetails['joining_date'];
$monthsSinceJoining = (time() - strtotime($joiningDate)) / (30 * 24 * 60 * 60);
$isEligibleForAcademic = $monthsSinceJoining >= ACADEMIC_LEAVE_ELIGIBILITY_MONTHS;

// Check if student has required guide and TA assignments
$hasGuide = !empty($studentDetails['faculty_guide_id']);
$hasTA = !empty($studentDetails['ta_coordinator_id']);
$hasRequiredAssignments = $hasGuide && $hasTA;

?>

<!-- Dashboard Header -->
<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Welcome, <?php echo sanitize($studentDetails['name']); ?>!</h1>
    <p class="mt-2 text-gray-600">Roll Number: <?php echo sanitize($studentDetails['roll_number']); ?></p>
</div>

<!-- Missing Guide/TA Warning -->
<?php if (!$hasRequiredAssignments): ?>
    <div class="mb-8 p-4 bg-red-50 border-l-4 border-red-500 rounded-lg">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-600 text-lg mt-0.5"></i>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800">Incomplete Profile Setup</h3>
                <div class="mt-2 text-sm text-red-700">
                    <p>Before you can apply for leave, you need the following assignments:</p>
                    <ul class="list-disc list-inside mt-2">
                        <?php if (!$hasGuide): ?>
                            <li>Faculty Guide</li>
                        <?php endif; ?>
                        <?php if (!$hasTA): ?>
                            <li>TA Coordinator</li>
                        <?php endif; ?>
                    </ul>
                    <p class="mt-2">Please contact staff PhD coordinator to complete these assignments.</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Leave Balance Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <!-- Personal Leave Card -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden card-hover stat-card">
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm">Personal Leave</p>
                    <p class="text-white text-3xl font-bold"><?php echo number_format($balance['personal_leaves'] ?? 0, 1); ?></p>
                </div>
                <i class="fas fa-user-clock text-white text-4xl opacity-50"></i>
            </div>
        </div>
        <div class="px-6 py-4">
            <div class="mb-2">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Available</span>
                    <span class="font-semibold <?php echo getBalanceColor($availablePersonal); ?>"><?php echo number_format($availablePersonal, 1); ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-purple-600 h-2 rounded-full progress-bar" style="width: <?php echo min(100, max(0, ($balance['personal_leaves'] / PERSONAL_LEAVE_ALLOCATION) * 100)); ?>%"></div>
                </div>
            </div>
            <?php if ($pendingPersonal > 0): ?>
                <p class="text-xs text-yellow-600 mt-2">
                    <i class="fas fa-info-circle"></i> <?php echo $pendingPersonal; ?> days pending approval
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Medical Leave Card -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden card-hover stat-card">
        <div class="bg-gradient-to-r from-red-500 to-red-600 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-100 text-sm">Medical Leave</p>
                    <p class="text-white text-3xl font-bold"><?php echo number_format($balance['medical_leaves'] ?? 0, 1); ?></p>
                </div>
                <i class="fas fa-heartbeat text-white text-4xl opacity-50"></i>
            </div>
        </div>
        <div class="px-6 py-4">
            <div class="mb-2">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Available</span>
                    <span class="font-semibold <?php echo getBalanceColor($availableMedical); ?>"><?php echo number_format($availableMedical, 1); ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-red-600 h-2 rounded-full progress-bar" style="width: <?php echo min(100, max(0, ($balance['medical_leaves'] / MEDICAL_LEAVE_ALLOCATION) * 100)); ?>%"></div>
                </div>
            </div>
            <?php if ($pendingMedical > 0): ?>
                <p class="text-xs text-yellow-600 mt-2">
                    <i class="fas fa-info-circle"></i> <?php echo $pendingMedical; ?> days pending approval
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Academic Leave Card -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden card-hover stat-card">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Academic Leave</p>
                    <p class="text-white text-3xl font-bold"><?php echo number_format($balance['academic_leaves'] ?? 0, 1); ?></p>
                </div>
                <i class="fas fa-graduation-cap text-white text-4xl opacity-50"></i>
            </div>
        </div>
        <div class="px-6 py-4">
            <div class="mb-2">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Available</span>
                    <span class="font-semibold <?php echo getBalanceColor($availableAcademic); ?>"><?php echo number_format($availableAcademic, 1); ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full progress-bar" style="width: <?php echo min(100, max(0, ($balance['academic_leaves'] / ACADEMIC_LEAVE_ALLOCATION) * 100)); ?>%"></div>
                </div>
            </div>
            <?php if (!$isEligibleForAcademic): ?>
                <p class="text-xs text-red-600 mt-2">
                    <i class="fas fa-lock"></i> Available after 1 year
                </p>
            <?php elseif ($pendingAcademic > 0): ?>
                <p class="text-xs text-yellow-600 mt-2">
                    <i class="fas fa-info-circle"></i> <?php echo $pendingAcademic; ?> days pending approval
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions & Stats Row -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <!-- Quick Apply Leave Button -->
    <div class="md:col-span-2">
        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="block">
            <div class="bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg shadow-md p-6 hover:from-green-600 hover:to-green-700 transition-all">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold">Apply for Leave</h3>
                        <p class="text-green-100 mt-1">Submit a new leave application</p>
                    </div>
                    <i class="fas fa-paper-plane text-4xl opacity-50"></i>
                </div>
            </div>
        </a>
    </div>

    <!-- Pending Applications Card -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm">Pending Applications</p>
                <p class="text-3xl font-bold text-yellow-600"><?php echo $pendingCount; ?></p>
            </div>
            <i class="fas fa-clock text-yellow-500 text-3xl opacity-50"></i>
        </div>
    </div>

    <!-- Total Applications Card -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-600 text-sm">Total Applications</p>
                <p class="text-3xl font-bold text-blue-600"><?php echo count($recentLeaves); ?></p>
            </div>
            <i class="fas fa-file-alt text-blue-500 text-3xl opacity-50"></i>
        </div>
    </div>
</div>

<!-- Recent Leave Applications & Upcoming Holidays -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Leave Applications -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-md">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-900">Recent Leave Applications</h2>
                <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            <div class="overflow-x-auto">
                <?php if (empty($recentLeaves)): ?>
                    <div class="empty-state m-6">
                        <i class="fas fa-inbox empty-state-icon text-5xl"></i>
                        <p class="empty-state-title">No leave applications yet</p>
                        <p class="empty-state-description">Get started by submitting your first leave application.</p>
                        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="mt-3 inline-flex items-center text-blue-600 hover:text-blue-800 font-medium">
                            Apply for your first leave <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Applied</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recentLeaves as $leave): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo formatDate($leave['start_date']); ?> to <?php echo formatDate($leave['end_date']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $leave['total_days']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo getStatusBadge($leave['status']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo timeAgo($leave['created_at']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming Holidays -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow-md">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-900">Upcoming Holidays</h2>
                <a href="<?php echo BASE_URL; ?>/student/holidays.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    View All <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            <div class="px-6 py-4">
                <?php if (empty($upcomingHolidays)): ?>
                    <div class="text-center text-gray-500 py-4">
                        <i class="fas fa-calendar-times text-3xl mb-2"></i>
                        <p class="text-sm">No upcoming holidays</p>
                    </div>
                <?php else: ?>
                    <ul class="space-y-3">
                        <?php foreach ($upcomingHolidays as $holiday): ?>
                            <li class="border-l-4 border-blue-500 pl-3 py-2">
                                <p class="text-sm font-semibold text-gray-900"><?php echo sanitize($holiday['holiday_name']); ?></p>
                                <p class="text-xs text-gray-600 mt-1">
                                    <i class="fas fa-calendar-day mr-1"></i>
                                    <?php echo formatDate($holiday['holiday_date']); ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guide & TA Coordinator Info -->
        <div class="bg-white rounded-lg shadow-md mt-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-900">Your Coordinators</h2>
            </div>
            <div class="px-6 py-4">
                <!-- Faculty Guide -->
                <div class="mb-4">
                    <p class="text-xs text-gray-500 uppercase">Faculty Guide</p>
                    <p class="text-sm font-semibold text-gray-900 mt-1">
                        <?php echo sanitize($studentDetails['guide_name'] ?? 'Not Assigned'); ?>
                    </p>
                    <?php if (!empty($studentDetails['guide_email'])): ?>
                        <p class="text-xs text-gray-600 mt-1">
                            <i class="fas fa-envelope mr-1"></i>
                            <a href="mailto:<?php echo sanitize($studentDetails['guide_email']); ?>" class="text-blue-600 hover:underline">
                                <?php echo sanitize($studentDetails['guide_email']); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- TA Coordinator -->
                <div>
                    <p class="text-xs text-gray-500 uppercase">TA Coordinator</p>
                    <p class="text-sm font-semibold text-gray-900 mt-1">
                        <?php echo sanitize($studentDetails['ta_coordinator_name'] ?? 'Not Assigned'); ?>
                    </p>
                    <?php if (!empty($studentDetails['ta_coordinator_email'])): ?>
                        <p class="text-xs text-gray-600 mt-1">
                            <i class="fas fa-envelope mr-1"></i>
                            <a href="mailto:<?php echo sanitize($studentDetails['ta_coordinator_email']); ?>" class="text-blue-600 hover:underline">
                                <?php echo sanitize($studentDetails['ta_coordinator_email']); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
