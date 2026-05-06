<?php
/**
 * Admin - System Analytics Dashboard
 * Comprehensive analytics with charts and statistics
 */

$pageTitle = 'System Analytics';
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

// Calculate statistics
$totalStudents = count($allStudents);
$totalFaculty = count($allFaculty);
$totalLeaves = count($allLeaves);
$approvedCount = count(array_filter($allLeaves, fn($l) => $l['status'] === 'approved'));
$rejectedCount = count(array_filter($allLeaves, fn($l) => $l['status'] === 'rejected'));
$pendingCount = $totalLeaves - $approvedCount - $rejectedCount;

// Leave type statistics
$personal = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'personal'));
$medical = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'medical'));
$academic = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'academic'));

// Calculate rates
$approvalRate = $totalLeaves > 0 ? round(($approvedCount / $totalLeaves) * 100, 1) : 0;
$rejectionRate = $totalLeaves > 0 ? round(($rejectedCount / $totalLeaves) * 100, 1) : 0;
$avgPerStudent = $totalStudents > 0 ? round($totalLeaves / $totalStudents, 1) : 0;

// Monthly data (for trend chart)
$monthlyData = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $monthlyData[$month] = [
        'approved' => 0,
        'rejected' => 0,
        'pending' => 0
    ];
}

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

// Fill monthly data
foreach ($allLeaves as $leave) {
    $month = date('Y-m', strtotime($leave['created_at']));
    if (isset($monthlyData[$month])) {
        $status = $leave['status'];
        if ($status === 'approved') {
            $monthlyData[$month]['approved']++;
        } elseif ($status === 'rejected') {
            $monthlyData[$month]['rejected']++;
        } else {
            $monthlyData[$month]['pending']++;
        }
    }
}
?>

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">System Analytics</h1>
    <p class="text-gray-600 mt-2">Comprehensive statistics and insights</p>
</div>

<!-- Overview Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wide">Active Students</p>
                <p class="text-4xl font-bold mt-1"><?php echo $totalStudents; ?></p>
            </div>
            <i class="fas fa-user-graduate text-5xl opacity-30"></i>
        </div>
    </div>

    <div class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wide">Total Applications</p>
                <p class="text-4xl font-bold mt-1"><?php echo $totalLeaves; ?></p>
            </div>
            <i class="fas fa-file-alt text-5xl opacity-30"></i>
        </div>
    </div>

    <div class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wide">Approved</p>
                <p class="text-4xl font-bold mt-1"><?php echo $approvedCount; ?></p>
            </div>
            <i class="fas fa-check-circle text-5xl opacity-30"></i>
        </div>
    </div>

    <div class="bg-gradient-to-br from-red-500 to-red-600 text-white rounded-lg shadow-lg p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm opacity-90 uppercase tracking-wide">Rejected</p>
                <p class="text-4xl font-bold mt-1"><?php echo $rejectedCount; ?></p>
            </div>
            <i class="fas fa-times-circle text-5xl opacity-30"></i>
        </div>
    </div>
</div>

<?php if ($totalLeaves === 0): ?>
    <!-- Empty State -->
    <div class="bg-white rounded-lg shadow-md p-12 text-center mb-8">
        <i class="fas fa-chart-pie text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Data Available</h3>
        <p class="text-gray-600">Analytics will appear once leave applications are submitted</p>
    </div>
<?php else: ?>
    <!-- Charts Row 1 -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Leave Type Distribution -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-chart-pie text-blue-600 mr-2"></i>Leave Type Distribution
            </h3>
            <div style="position: relative; height: 250px;">
                <canvas id="typeChart"></canvas>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-chart-donut text-purple-600 mr-2"></i>Application Status
            </h3>
            <div style="position: relative; height: 250px;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Monthly Trend Chart -->
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-chart-line text-green-600 mr-2"></i>Monthly Trend (Last 12 Months)
        </h3>
        <div style="position: relative; height: 300px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
<?php endif; ?>

<!-- Key Metrics & System Health -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Key Metrics -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-analytics text-orange-600 mr-2"></i>Key Metrics
        </h3>
        <div class="space-y-4">
            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-green-50 to-green-100 rounded-lg">
                <span class="text-gray-700 font-medium">Approval Rate</span>
                <span class="text-2xl font-bold text-green-600"><?php echo $approvalRate; ?>%</span>
            </div>
            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-lg">
                <span class="text-gray-700 font-medium">Rejection Rate</span>
                <span class="text-2xl font-bold text-red-600"><?php echo $rejectionRate; ?>%</span>
            </div>
            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg">
                <span class="text-gray-700 font-medium">Avg Applications/Student</span>
                <span class="text-2xl font-bold text-blue-600"><?php echo $avgPerStudent; ?></span>
            </div>
            <div class="flex justify-between items-center p-4 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg">
                <span class="text-gray-700 font-medium">Pending Applications</span>
                <span class="text-2xl font-bold text-yellow-600"><?php echo $pendingCount; ?></span>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="bg-white rounded-lg shadow-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-heartbeat text-red-600 mr-2"></i>System Health
        </h3>
        <div class="space-y-4">
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-database text-2xl text-blue-600 mr-3"></i>
                    <span class="text-gray-700 font-medium">Database</span>
                </div>
                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>Healthy
                </span>
            </div>
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-envelope text-2xl text-purple-600 mr-3"></i>
                    <span class="text-gray-700 font-medium">Email Service</span>
                </div>
                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>Active
                </span>
            </div>
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-clock text-2xl text-green-600 mr-3"></i>
                    <span class="text-gray-700 font-medium">Cron Jobs</span>
                </div>
                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>Scheduled
                </span>
            </div>
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-shield-alt text-2xl text-orange-600 mr-3"></i>
                    <span class="text-gray-700 font-medium">Security</span>
                </div>
                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>Enabled
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Admin Activity Log -->
<div class="bg-white rounded-lg shadow-md mt-8">
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <h3 class="text-lg font-semibold text-gray-900">
            <i class="fas fa-history text-indigo-600 mr-2"></i>Recent Activity Log
        </h3>
        <span class="text-sm text-gray-500">Last 50 actions</span>
    </div>
    <div class="overflow-x-auto" style="max-height: 400px; overflow-y: auto;">
        <?php if (empty($activityLogs)): ?>
            <div class="p-8 text-center">
                <i class="fas fa-clipboard-list text-4xl text-gray-300 mb-3"></i>
                <p class="text-gray-500">No activity logs yet</p>
            </div>
        <?php else: ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Module</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Description</th>
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
                                    <?php
                                    $roleIcon = match($log['user_role'] ?? '') {
                                        'admin' => 'fa-user-shield text-red-500',
                                        'hod' => 'fa-user-tie text-green-500',
                                        'faculty' => 'fa-chalkboard-teacher text-purple-500',
                                        'student' => 'fa-user-graduate text-blue-500',
                                        default => 'fa-user text-gray-500'
                                    };
                                    ?>
                                    <i class="fas <?php echo $roleIcon; ?> mr-2 flex-shrink-0"></i>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-gray-900 truncate">
                                            <?php echo sanitize($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <?php if ($log['user_email']): ?>
                                            <div class="text-xs text-gray-500 truncate hidden sm:block"><?php echo sanitize($log['user_email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">
                                <?php
                                $actionColor = match(true) {
                                    str_contains($log['action'], 'create') || str_contains($log['action'], 'add') => 'bg-green-100 text-green-800',
                                    str_contains($log['action'], 'delete') || str_contains($log['action'], 'remove') => 'bg-red-100 text-red-800',
                                    str_contains($log['action'], 'update') || str_contains($log['action'], 'edit') => 'bg-blue-100 text-blue-800',
                                    str_contains($log['action'], 'login') => 'bg-indigo-100 text-indigo-800',
                                    str_contains($log['action'], 'approve') => 'bg-emerald-100 text-emerald-800',
                                    str_contains($log['action'], 'reject') => 'bg-orange-100 text-orange-800',
                                    str_contains($log['action'], 'password') => 'bg-yellow-100 text-yellow-800',
                                    default => 'bg-gray-100 text-gray-800'
                                };
                                ?>
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $actionColor; ?> whitespace-nowrap">
                                    <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                                </span>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-600 hidden lg:table-cell">
                                <span class="capitalize"><?php echo sanitize($log['module']); ?></span>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-600 max-w-xs truncate hidden md:table-cell" title="<?php echo sanitize($log['description']); ?>">
                                <?php echo sanitize($log['description']); ?>
                            </td>
                            <td class="px-3 py-3 text-sm text-gray-500 font-mono hidden xl:table-cell">
                                <?php echo sanitize($log['ip_address'] ?? '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalLeaves > 0): ?>
<script>
// Type Distribution Chart
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: ['Personal', 'Medical', 'Academic'],
        datasets: [{
            data: [<?php echo $personal; ?>, <?php echo $medical; ?>, <?php echo $academic; ?>],
            backgroundColor: ['#9333EA', '#DC2626', '#2563EB'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.parsed || 0;
                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                        let percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ' + value + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// Status Distribution Chart
new Chart(document.getElementById('statusChart'), {
    type: 'pie',
    data: {
        labels: ['Approved', 'Rejected', 'Pending'],
        datasets: [{
            data: [<?php echo $approvedCount; ?>, <?php echo $rejectedCount; ?>, <?php echo $pendingCount; ?>],
            backgroundColor: ['#10B981', '#EF4444', '#F59E0B'],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.parsed || 0;
                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                        let percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ' + value + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});

// Monthly Trend Chart
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: [<?php
            $labels = [];
            foreach ($monthlyData as $month => $data) {
                $labels[] = "'" . date('M Y', strtotime($month)) . "'";
            }
            echo implode(', ', $labels);
        ?>],
        datasets: [
            {
                label: 'Approved',
                data: [<?php echo implode(', ', array_column($monthlyData, 'approved')); ?>],
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Rejected',
                data: [<?php echo implode(', ', array_column($monthlyData, 'rejected')); ?>],
                borderColor: '#EF4444',
                backgroundColor: 'rgba(239, 68, 68, 0.1)',
                tension: 0.4,
                fill: true
            },
            {
                label: 'Pending',
                data: [<?php echo implode(', ', array_column($monthlyData, 'pending')); ?>],
                borderColor: '#F59E0B',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.4,
                fill: true
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true
                }
            },
            tooltip: {
                callbacks: {
                    title: function(context) {
                        return context[0].label;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
