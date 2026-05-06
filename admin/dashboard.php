<?php
/**
 * Admin Dashboard
 */

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';

$leaveClass = new Leave();
$userClass = new User();

// Get all data
$allLeaves = $leaveClass->getAll([]);
$allStudents = $userClass->getAllStudents(true);
$allFaculty = $userClass->getAllByRole('faculty', true);
$pendingAdmin = $leaveClass->getPendingForAdmin();

// Statistics
$totalStudents = count($allStudents);
$totalFaculty = count($allFaculty);
$totalApplications = count($allLeaves);
$pendingCount = count($pendingAdmin);

// Recent applications (last 10)
$recentApplications = array_slice($allLeaves, 0, 10);

// By status
$approved = count(array_filter($allLeaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($allLeaves, fn($l) => $l['status'] === 'rejected'));
$pending = $totalApplications - $approved - $rejected;
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Admin Dashboard</h1>
    <p class="text-gray-600 mt-2">System Overview & Management</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <p class="text-sm opacity-90">Total Students</p>
                <p class="text-4xl font-bold"><?php echo $totalStudents; ?></p>
            </div>
            <i class="fas fa-user-graduate text-5xl opacity-30"></i>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/users.php?role=student" class="text-sm opacity-90 hover:opacity-100">
            Manage Students →
        </a>
    </div>

    <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <p class="text-sm opacity-90">Faculty Members</p>
                <p class="text-4xl font-bold"><?php echo $totalFaculty; ?></p>
            </div>
            <i class="fas fa-chalkboard-teacher text-5xl opacity-30"></i>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/users.php?role=faculty" class="text-sm opacity-90 hover:opacity-100">
            Manage Faculty →
        </a>
    </div>

    <div class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <p class="text-sm opacity-90">Leave Applications</p>
                <p class="text-4xl font-bold"><?php echo $totalApplications; ?></p>
            </div>
            <i class="fas fa-file-alt text-5xl opacity-30"></i>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="text-sm opacity-90 hover:opacity-100">
            View All →
        </a>
    </div>

    <div class="bg-gradient-to-br from-orange-500 to-red-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <p class="text-sm opacity-90">Pending Admin</p>
                <p class="text-4xl font-bold"><?php echo $pendingCount; ?></p>
            </div>
            <i class="fas fa-clock text-5xl opacity-30"></i>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/pending_approvals.php" class="text-sm opacity-90 hover:opacity-100">
            Review Now →
        </a>
    </div>
</div>

<!-- Alert for Pending Approvals -->
<?php if ($pendingCount > 0): ?>
    <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-8">
        <div class="flex items-center justify-between">
            <div class="flex items-start">
                <i class="fas fa-bell text-orange-600 text-xl mr-3 mt-0.5"></i>
                <div>
                    <p class="text-sm font-semibold text-orange-800 mb-1">Action Required</p>
                    <p class="text-sm text-orange-700">
                        You have <strong><?php echo $pendingCount; ?></strong> extended leave application(s) awaiting final admin approval.
                    </p>
                </div>
            </div>
            <a href="<?php echo BASE_URL; ?>/admin/pending_approvals.php"
               class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 whitespace-nowrap">
                Review Now
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <a href="<?php echo BASE_URL; ?>/admin/users.php?action=invite" class="block bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center mb-3">
            <div class="p-3 bg-blue-100 rounded-lg mr-4">
                <i class="fas fa-user-plus text-2xl text-blue-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Invite User</h3>
        </div>
        <p class="text-sm text-gray-600">Send invitation to new users</p>
    </a>

    <a href="<?php echo BASE_URL; ?>/admin/holidays.php" class="block bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center mb-3">
            <div class="p-3 bg-purple-100 rounded-lg mr-4">
                <i class="fas fa-calendar-alt text-2xl text-purple-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">Manage Holidays</h3>
        </div>
        <p class="text-sm text-gray-600">Add and update institute holidays</p>
    </a>

    <a href="<?php echo BASE_URL; ?>/admin/analytics.php" class="block bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow">
        <div class="flex items-center mb-3">
            <div class="p-3 bg-green-100 rounded-lg mr-4">
                <i class="fas fa-chart-line text-2xl text-green-600"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900">View Analytics</h3>
        </div>
        <p class="text-sm text-gray-600">System statistics and reports</p>
    </a>
</div>

<!-- Leave Status Overview -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Leave Status Overview</h3>
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-3 h-3 rounded-full bg-green-500 mr-3"></div>
                    <span class="text-gray-700">Approved</span>
                </div>
                <span class="font-semibold text-gray-900"><?php echo $approved; ?></span>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-3 h-3 rounded-full bg-red-500 mr-3"></div>
                    <span class="text-gray-700">Rejected</span>
                </div>
                <span class="font-semibold text-gray-900"><?php echo $rejected; ?></span>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="w-3 h-3 rounded-full bg-yellow-500 mr-3"></div>
                    <span class="text-gray-700">Pending</span>
                </div>
                <span class="font-semibold text-gray-900"><?php echo $pending; ?></span>
            </div>
        </div>
    </div>

    <!-- Recent Applications -->
    <div class="lg:col-span-2 bg-white rounded-lg shadow-md">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Recent Applications</h3>
        </div>
        <div class="p-6">
            <?php if (empty($recentApplications)): ?>
                <p class="text-center text-gray-500 py-4">No applications yet</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach (array_slice($recentApplications, 0, 5) as $leave): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div class="flex-1">
                                <p class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></p>
                                <p class="text-xs text-gray-500">
                                    <?php echo getLeaveTypeLabel($leave['leave_type']); ?> • <?php echo $leave['total_days']; ?> days
                                </p>
                            </div>
                            <?php echo getStatusBadge($leave['status']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
