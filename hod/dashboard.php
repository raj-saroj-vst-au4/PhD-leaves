<?php
/**
 * HOD Dashboard
 */

$pageTitle = 'HOD Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole('hod');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';

$userName = getCurrentUserName();
$leaveClass = new Leave();
$userClass = new User();

// Get pending approvals
$pendingApprovals = $leaveClass->getPendingForHOD();

// Get all leave applications (last 30 days)
$recentLeaves = $leaveClass->getAll([
    'start_date' => date('Y-m-d', strtotime('-30 days'))
]);

// Calculate statistics
$totalApplications = count($recentLeaves);
$approved = count(array_filter($recentLeaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($recentLeaves, fn($l) => $l['status'] === 'rejected'));
$pending = count(array_filter($recentLeaves, fn($l) => in_array($l['status'], ['pending_guide', 'pending_ta', 'pending_hod', 'pending_admin'])));

// By leave type
$personal = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'personal'));
$medical = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'medical'));
$academic = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'academic'));
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Welcome, <?php echo sanitize(explode(' ', $userName)[0]); ?>!</h1>
    <p class="text-gray-600 mt-2">Head of Department Dashboard</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Overview -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <!-- Pending Approvals -->
    <div class="bg-gradient-to-br from-orange-500 to-red-600 text-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between mb-2">
            <div>
                <p class="text-sm opacity-90">Pending HOD Review</p>
                <p class="text-4xl font-bold"><?php echo count($pendingApprovals); ?></p>
            </div>
            <i class="fas fa-clock text-4xl opacity-30"></i>
        </div>
        <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="text-sm opacity-90 hover:opacity-100 flex items-center mt-3">
            Review Now <i class="fas fa-arrow-right ml-2"></i>
        </a>
    </div>

    <!-- Total Applications (Last 30 days) -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Applications (30d)</p>
                <p class="text-3xl font-bold text-blue-600"><?php echo $totalApplications; ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-full">
                <i class="fas fa-file-alt text-2xl text-blue-600"></i>
            </div>
        </div>
    </div>

    <!-- Approved -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Approved</p>
                <p class="text-3xl font-bold text-green-600"><?php echo $approved; ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-full">
                <i class="fas fa-check-circle text-2xl text-green-600"></i>
            </div>
        </div>
    </div>

    <!-- Rejected -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600">Rejected</p>
                <p class="text-3xl font-bold text-red-600"><?php echo $rejected; ?></p>
            </div>
            <div class="p-3 bg-red-100 rounded-full">
                <i class="fas fa-times-circle text-2xl text-red-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- Leave Type Distribution -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Leave Type Distribution (Last 30 days)</h3>
        <div class="space-y-4">
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-gray-700">Personal</span>
                    <span class="text-sm font-bold text-purple-600"><?php echo $personal; ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-purple-600 h-2 rounded-full" style="width: <?php echo $totalApplications > 0 ? ($personal / $totalApplications * 100) : 0; ?>%"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-gray-700">Medical</span>
                    <span class="text-sm font-bold text-red-600"><?php echo $medical; ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-red-600 h-2 rounded-full" style="width: <?php echo $totalApplications > 0 ? ($medical / $totalApplications * 100) : 0; ?>%"></div>
                </div>
            </div>
            <div>
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-gray-700">Academic</span>
                    <span class="text-sm font-bold text-blue-600"><?php echo $academic; ?></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $totalApplications > 0 ? ($academic / $totalApplications * 100) : 0; ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="block bg-orange-50 border-2 border-orange-200 rounded-lg p-6 hover:bg-orange-100 transition-colors">
            <div class="flex items-center mb-3">
                <div class="p-3 bg-orange-200 rounded-lg mr-4">
                    <i class="fas fa-clipboard-check text-2xl text-orange-700"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Pending Approvals</h4>
                    <p class="text-sm text-gray-600"><?php echo count($pendingApprovals); ?> waiting</p>
                </div>
            </div>
            <p class="text-sm text-gray-600">Review and approve leave applications</p>
        </a>

        <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="block bg-blue-50 border-2 border-blue-200 rounded-lg p-6 hover:bg-blue-100 transition-colors">
            <div class="flex items-center mb-3">
                <div class="p-3 bg-blue-200 rounded-lg mr-4">
                    <i class="fas fa-list text-2xl text-blue-700"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">All Leaves</h4>
                    <p class="text-sm text-gray-600"><?php echo $totalApplications; ?> in 30 days</p>
                </div>
            </div>
            <p class="text-sm text-gray-600">View all department leave records</p>
        </a>

        <a href="<?php echo BASE_URL; ?>/hod/analytics.php" class="block bg-green-50 border-2 border-green-200 rounded-lg p-6 hover:bg-green-100 transition-colors">
            <div class="flex items-center mb-3">
                <div class="p-3 bg-green-200 rounded-lg mr-4">
                    <i class="fas fa-chart-bar text-2xl text-green-700"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Analytics</h4>
                    <p class="text-sm text-gray-600">View reports</p>
                </div>
            </div>
            <p class="text-sm text-gray-600">Department leave statistics & trends</p>
        </a>

        <a href="<?php echo BASE_URL; ?>/hod/holidays.php" class="block bg-purple-50 border-2 border-purple-200 rounded-lg p-6 hover:bg-purple-100 transition-colors">
            <div class="flex items-center mb-3">
                <div class="p-3 bg-purple-200 rounded-lg mr-4">
                    <i class="fas fa-calendar-alt text-2xl text-purple-700"></i>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-900">Holidays</h4>
                    <p class="text-sm text-gray-600">Manage calendar</p>
                </div>
            </div>
            <p class="text-sm text-gray-600">View and manage institute holidays</p>
        </a>
    </div>
</div>

<!-- Recent Pending Applications -->
<?php if (!empty($pendingApprovals)): ?>
<div class="bg-white rounded-lg shadow-md">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-xl font-bold text-gray-900">Pending HOD Approvals</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Applied</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach (array_slice($pendingApprovals, 0, 10) as $leave): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
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
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo timeAgo($leave['created_at']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php?id=<?php echo $leave['id']; ?>" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-eye"></i> Review
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($pendingApprovals) > 10): ?>
        <div class="px-6 py-4 border-t border-gray-200 text-center">
            <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="text-blue-600 hover:text-blue-800 font-medium">
                View All <?php echo count($pendingApprovals); ?> Pending Approvals <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
