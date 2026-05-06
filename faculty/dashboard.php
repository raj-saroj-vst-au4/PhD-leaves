<?php
/**
 * Faculty Dashboard
 */

$pageTitle = 'Faculty Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('faculty');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';

$userId = getCurrentUserId();
$userName = getCurrentUserName();
$leaveClass = new Leave();
$userClass = new User();

// Get pending approvals as guide
$pendingAsGuide = $leaveClass->getPendingForFaculty($userId, 'guide');

// Get pending approvals as TA coordinator
$pendingAsTA = $leaveClass->getPendingForFaculty($userId, 'ta_coordinator');

// Get students under this faculty
$allStudents = $userClass->getAllStudents(true);
$myStudentsAsGuide = array_filter($allStudents, fn($s) => $s['faculty_guide_id'] == $userId);
$myStudentsAsTA = array_filter($allStudents, fn($s) => $s['ta_coordinator_id'] == $userId);

// Get recent approvals (last 10)
$recentApprovals = array_slice(
    array_merge(
        $leaveClass->getPendingForFaculty($userId, 'guide'),
        $leaveClass->getPendingForFaculty($userId, 'ta_coordinator')
    ), 0, 10
);
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Welcome, <?php echo sanitize(explode(' ', $userName)[0]); ?>!</h1>
    <p class="text-gray-600 mt-2">Faculty Dashboard</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <!-- Pending as Guide -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Pending (Guide)</p>
                <p class="text-3xl font-bold text-orange-600"><?php echo count($pendingAsGuide); ?></p>
            </div>
            <div class="p-3 bg-orange-100 rounded-full">
                <i class="fas fa-user-tie text-2xl text-orange-600"></i>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=guide" class="text-sm text-orange-600 hover:text-orange-800 mt-3 inline-block">
            Review applications <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <!-- Pending as TA Coordinator -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Pending (TA Coordinator)</p>
                <p class="text-3xl font-bold text-blue-600"><?php echo count($pendingAsTA); ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-full">
                <i class="fas fa-chalkboard-teacher text-2xl text-blue-600"></i>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=ta" class="text-sm text-blue-600 hover:text-blue-800 mt-3 inline-block">
            Review applications <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <!-- Students as Guide -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">My Students (Guide)</p>
                <p class="text-3xl font-bold text-green-600"><?php echo count($myStudentsAsGuide); ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-full">
                <i class="fas fa-users text-2xl text-green-600"></i>
            </div>
        </div>
    </div>

    <!-- Students as TA Coordinator -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">My Students (TA)</p>
                <p class="text-3xl font-bold text-purple-600"><?php echo count($myStudentsAsTA); ?></p>
            </div>
            <div class="p-3 bg-purple-100 rounded-full">
                <i class="fas fa-user-graduate text-2xl text-purple-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Pending as Guide -->
    <?php if (count($pendingAsGuide) > 0): ?>
    <div class="bg-gradient-to-r from-orange-500 to-red-600 rounded-lg shadow-md p-6 text-white">
        <h2 class="text-2xl font-bold mb-2">
            <i class="fas fa-exclamation-circle mr-2"></i>
            Pending Guide Approvals
        </h2>
        <p class="mb-4 opacity-90">You have <?php echo count($pendingAsGuide); ?> leave application(s) waiting for your review as faculty guide.</p>
        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=guide" class="inline-block bg-white text-orange-600 font-semibold px-6 py-3 rounded-lg hover:bg-gray-100 transition-colors">
            <i class="fas fa-clipboard-check mr-2"></i>Review Now
        </a>
    </div>
    <?php endif; ?>

    <!-- Pending as TA Coordinator -->
    <?php if (count($pendingAsTA) > 0): ?>
    <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-lg shadow-md p-6 text-white">
        <h2 class="text-2xl font-bold mb-2">
            <i class="fas fa-exclamation-circle mr-2"></i>
            Pending TA Approvals
        </h2>
        <p class="mb-4 opacity-90">You have <?php echo count($pendingAsTA); ?> leave application(s) waiting for your review as TA coordinator.</p>
        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=ta" class="inline-block bg-white text-blue-600 font-semibold px-6 py-3 rounded-lg hover:bg-gray-100 transition-colors">
            <i class="fas fa-clipboard-check mr-2"></i>Review Now
        </a>
    </div>
    <?php endif; ?>

    <?php if (count($pendingAsGuide) == 0 && count($pendingAsTA) == 0): ?>
    <div class="lg:col-span-2 bg-green-50 border border-green-200 rounded-lg p-8 text-center">
        <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">All Caught Up!</h3>
        <p class="text-gray-600">You have no pending leave applications to review at this time.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Activity -->
<div class="bg-white rounded-lg shadow-md">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-xl font-bold text-gray-900">Recent Leave Applications</h2>
    </div>
    <div class="overflow-x-auto">
        <?php if (empty($recentApprovals)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-inbox text-4xl mb-3"></i>
                <p>No recent applications</p>
            </div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($recentApprovals as $leave): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo sanitize($leave['student_email']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo sanitize($leave['roll_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo formatDate($leave['start_date']); ?> - <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">
                                <?php echo $leave['total_days']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?id=<?php echo $leave['id']; ?>" class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
