<?php
/**
 * HOD Analytics & Reports
 */

$pageTitle = 'Department Analytics';
require_once __DIR__ . '/../includes/header.php';
requireRole('hod');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/User.php';

$leaveClass = new Leave();
$userClass = new User();

// Get year filter
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Get all leaves for the year
$startDate = "$year-01-01";
$endDate = "$year-12-31";
$leaves = $leaveClass->getAll(['start_date' => $startDate, 'end_date' => $endDate]);

// Calculate statistics
$totalApplications = count($leaves);
$approved = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($leaves, fn($l) => $l['status'] === 'rejected'));
$pending = $totalApplications - $approved - $rejected;

// By leave type
$personal = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'personal'));
$medical = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'medical'));
$academic = count(array_filter($leaves, fn($l) => $l['leave_type'] === 'academic'));

// Monthly breakdown
$monthlyData = [];
foreach ($leaves as $leave) {
    $month = date('M', strtotime($leave['created_at']));
    if (!isset($monthlyData[$month])) {
        $monthlyData[$month] = 0;
    }
    $monthlyData[$month]++;
}

// Total leave days
$totalDays = array_sum(array_map(fn($l) => $l['total_days'], array_filter($leaves, fn($l) => $l['status'] === 'approved')));

// Rates
$approvalRate = $totalApplications > 0 ? round(($approved / $totalApplications) * 100, 1) : 0;
$rejectionRate = $totalApplications > 0 ? round(($rejected / $totalApplications) * 100, 1) : 0;

// Fetch recent activity logs
$db = getDBConnection();
$activityLogsSql = "SELECT sl.*, u.name as user_name, u.email as user_email, u.role as user_role
                    FROM system_logs sl
                    LEFT JOIN users u ON sl.user_id = u.id
                    ORDER BY sl.created_at DESC
                    LIMIT 50";
$activityLogsStmt = $db->prepare($activityLogsSql);
$activityLogsStmt->execute();
$activityLogs = $activityLogsStmt->fetchAll();
?>

<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Department Analytics</h1>
            <p class="text-gray-600 mt-1 sm:mt-2">Leave statistics and trends</p>
        </div>
        <div class="flex-shrink-0">
            <select onchange="window.location.href='?year='+this.value" class="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600">Total Applications</p>
                <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $totalApplications; ?></p>
            </div>
            <i class="fas fa-file-alt text-3xl sm:text-4xl text-blue-200"></i>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600">Approved</p>
                <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo $approved; ?></p>
            </div>
            <i class="fas fa-check-circle text-3xl sm:text-4xl text-green-200"></i>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600">Rejected</p>
                <p class="text-2xl sm:text-3xl font-bold text-red-600"><?php echo $rejected; ?></p>
            </div>
            <i class="fas fa-times-circle text-3xl sm:text-4xl text-red-200"></i>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600">Total Days</p>
                <p class="text-2xl sm:text-3xl font-bold text-purple-600"><?php echo $totalDays; ?></p>
            </div>
            <i class="fas fa-calendar-day text-3xl sm:text-4xl text-purple-200"></i>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
    <!-- Leave Type Distribution -->
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4">Leave Type Distribution</h3>
        <div class="chart-container-responsive">
            <canvas id="typeChart"></canvas>
        </div>
    </div>

    <!-- Monthly Trend -->
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4">Monthly Applications</h3>
        <div class="chart-container-responsive">
            <canvas id="monthlyChart"></canvas>
        </div>
    </div>
</div>

<!-- Key Metrics & System Health -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mt-6 sm:mt-8">
    <!-- Key Metrics -->
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4">
            <i class="fas fa-analytics text-orange-600 mr-2"></i>Key Metrics
        </h3>
        <div class="space-y-3 sm:space-y-4">
            <div class="flex justify-between items-center p-3 sm:p-4 bg-gradient-to-r from-green-50 to-green-100 rounded-lg">
                <span class="text-sm sm:text-base text-gray-700 font-medium">Approval Rate</span>
                <span class="text-xl sm:text-2xl font-bold text-green-600"><?php echo $approvalRate; ?>%</span>
            </div>
            <div class="flex justify-between items-center p-3 sm:p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-lg">
                <span class="text-sm sm:text-base text-gray-700 font-medium">Rejection Rate</span>
                <span class="text-xl sm:text-2xl font-bold text-red-600"><?php echo $rejectionRate; ?>%</span>
            </div>
            <div class="flex justify-between items-center p-3 sm:p-4 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg">
                <span class="text-sm sm:text-base text-gray-700 font-medium">Pending</span>
                <span class="text-xl sm:text-2xl font-bold text-yellow-600"><?php echo $pending; ?></span>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-3 sm:mb-4">
            <i class="fas fa-heartbeat text-red-600 mr-2"></i>System Health
        </h3>
        <div class="space-y-3 sm:space-y-4">
            <div class="flex items-center justify-between p-3 sm:p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-database text-xl sm:text-2xl text-blue-600 mr-2 sm:mr-3"></i>
                    <span class="text-sm sm:text-base text-gray-700 font-medium">Database</span>
                </div>
                <span class="px-2 sm:px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i><span class="hidden sm:inline">Healthy</span><span class="sm:hidden">OK</span>
                </span>
            </div>
            <div class="flex items-center justify-between p-3 sm:p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-envelope text-xl sm:text-2xl text-purple-600 mr-2 sm:mr-3"></i>
                    <span class="text-sm sm:text-base text-gray-700 font-medium">Email</span>
                </div>
                <span class="px-2 sm:px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i><span class="hidden sm:inline">Active</span><span class="sm:hidden">OK</span>
                </span>
            </div>
            <div class="flex items-center justify-between p-3 sm:p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-clock text-xl sm:text-2xl text-green-600 mr-2 sm:mr-3"></i>
                    <span class="text-sm sm:text-base text-gray-700 font-medium">Cron Jobs</span>
                </div>
                <span class="px-2 sm:px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i><span class="hidden sm:inline">Scheduled</span><span class="sm:hidden">OK</span>
                </span>
            </div>
            <div class="flex items-center justify-between p-3 sm:p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-shield-alt text-xl sm:text-2xl text-orange-600 mr-2 sm:mr-3"></i>
                    <span class="text-sm sm:text-base text-gray-700 font-medium">Security</span>
                </div>
                <span class="px-2 sm:px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i><span class="hidden sm:inline">Enabled</span><span class="sm:hidden">OK</span>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Activity Log -->
<div class="bg-white rounded-lg shadow-md mt-6 sm:mt-8">
    <div class="px-3 sm:px-6 py-3 sm:py-4 border-b border-gray-200 flex justify-between items-center">
        <h3 class="text-sm sm:text-lg font-semibold text-gray-900">
            <i class="fas fa-history text-indigo-600 mr-1 sm:mr-2"></i>Recent Activity Log
        </h3>
        <span class="text-xs sm:text-sm text-gray-500">Last 50</span>
    </div>
    <?php if (empty($activityLogs)): ?>
        <div class="p-6 sm:p-8 text-center">
            <i class="fas fa-clipboard-list text-3xl sm:text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500 text-sm">No activity logs yet</p>
        </div>
    <?php else: ?>
        <?php
        // Pre-compute action colors for reuse in both views
        function getActionColor($action) {
            return match(true) {
                str_contains($action, 'create') || str_contains($action, 'add') => 'bg-green-100 text-green-800',
                str_contains($action, 'delete') || str_contains($action, 'remove') => 'bg-red-100 text-red-800',
                str_contains($action, 'update') || str_contains($action, 'edit') => 'bg-blue-100 text-blue-800',
                str_contains($action, 'login') => 'bg-indigo-100 text-indigo-800',
                str_contains($action, 'approve') => 'bg-emerald-100 text-emerald-800',
                str_contains($action, 'reject') => 'bg-orange-100 text-orange-800',
                str_contains($action, 'password') => 'bg-yellow-100 text-yellow-800',
                default => 'bg-gray-100 text-gray-800'
            };
        }
        function getRoleIcon($role) {
            return match($role ?? '') {
                'admin' => 'fa-user-shield text-red-500',
                'hod' => 'fa-user-tie text-green-500',
                'faculty' => 'fa-chalkboard-teacher text-purple-500',
                'student' => 'fa-user-graduate text-blue-500',
                default => 'fa-user text-gray-500'
            };
        }
        ?>

        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden" style="max-height: 500px; overflow-y: auto;">
            <div class="divide-y divide-gray-200">
                <?php foreach ($activityLogs as $log): ?>
                    <div class="p-3 sm:p-4">
                        <div class="flex items-start justify-between mb-1">
                            <div class="flex items-center min-w-0 flex-1">
                                <i class="fas <?php echo getRoleIcon($log['user_role']); ?> mr-2 flex-shrink-0 text-sm"></i>
                                <span class="text-sm font-medium text-gray-900 truncate"><?php echo sanitize($log['user_name'] ?? 'System'); ?></span>
                            </div>
                            <span class="text-xs text-gray-400 ml-2 flex-shrink-0 whitespace-nowrap"><?php echo timeAgo($log['created_at']); ?></span>
                        </div>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full <?php echo getActionColor($log['action']); ?>">
                                <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                            </span>
                            <span class="text-xs text-gray-500 capitalize"><?php echo sanitize($log['module']); ?></span>
                        </div>
                        <?php if (!empty($log['description'])): ?>
                            <p class="text-xs text-gray-600 mt-1 line-clamp-2"><?php echo sanitize($log['description']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto" style="max-height: 400px; overflow-y: auto;">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Module</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden xl:table-cell">IP Address</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($activityLogs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-3 text-sm text-gray-500">
                                <span title="<?php echo formatDateTime($log['created_at']); ?>" class="whitespace-nowrap">
                                    <?php echo timeAgo($log['created_at']); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center">
                                    <i class="fas <?php echo getRoleIcon($log['user_role']); ?> mr-2 flex-shrink-0"></i>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-gray-900 truncate">
                                            <?php echo sanitize($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <?php if ($log['user_email']): ?>
                                            <div class="text-xs text-gray-500 truncate hidden lg:block"><?php echo sanitize($log['user_email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo getActionColor($log['action']); ?> whitespace-nowrap">
                                    <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-600 hidden lg:table-cell">
                                <span class="capitalize"><?php echo sanitize($log['module']); ?></span>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-600 max-w-xs truncate" title="<?php echo sanitize($log['description']); ?>">
                                <?php echo sanitize($log['description']); ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-500 font-mono hidden xl:table-cell">
                                <?php echo sanitize($log['ip_address'] ?? '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
// Leave Type Chart
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: ['Personal', 'Medical', 'Academic'],
        datasets: [{
            data: [<?php echo $personal; ?>, <?php echo $medical; ?>, <?php echo $academic; ?>],
            backgroundColor: ['#9333EA', '#DC2626', '#2563EB']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

// Monthly Chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [{
            label: 'Applications',
            data: [
                <?php
                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                foreach ($months as $m) {
                    echo ($monthlyData[$m] ?? 0) . ',';
                }
                ?>
            ],
            backgroundColor: '#3B82F6'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
