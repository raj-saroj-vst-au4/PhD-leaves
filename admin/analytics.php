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

// Get all data. 'combined' rows are superseded duplicates (combineLeaves()
// inserts one application spanning the originals), so counting them would
// double the totals.
$allLeaves = array_values(array_filter($leaveClass->getAll([]), fn($l) => $l['status'] !== 'combined'));
$allStudents = $userClass->getAllStudents(true);
$allFaculty = $userClass->getAllByRole('faculty', true);

// Calculate statistics
$totalStudents = count($allStudents);
$totalFaculty = count($allFaculty);
$totalLeaves = count($allLeaves);
$approvedCount = count(array_filter($allLeaves, fn($l) => $l['status'] === 'approved'));
$rejectedCount = count(array_filter($allLeaves, fn($l) => $l['status'] === 'rejected'));
// Only applications still in the approval chain. "Everything neither approved
// nor rejected" also counted cancelled (and combined) applications as pending.
$pendingCount = count(array_filter($allLeaves, fn($l) => in_array($l['status'], Leave::PENDING_STATUSES, true)));

// Leave type statistics
$personal = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'personal'));
$medical = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'medical'));
$academic = count(array_filter($allLeaves, fn($l) => $l['leave_type'] === 'academic'));

// Calculate rates
$approvalRate = $totalLeaves > 0 ? round(($approvedCount / $totalLeaves) * 100, 1) : 0;
$rejectionRate = $totalLeaves > 0 ? round(($rejectedCount / $totalLeaves) * 100, 1) : 0;
$avgPerStudent = $totalStudents > 0 ? round($totalLeaves / $totalStudents, 1) : 0;

// Monthly data (for trend chart): the last 12 calendar months. Step back from
// the 1st of this month: strtotime("-$i months") from today overflows on the
// 29th-31st (31 March minus one month is "31 February" = 3 March), so on those
// days some months repeated, overwrote each other, and five vanished from the chart.
$monthlyData = [];
$firstOfThisMonth = new DateTime('first day of this month');
for ($i = 11; $i >= 0; $i--) {
    $month = (clone $firstOfThisMonth)->modify("-$i months")->format('Y-m');
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
        } elseif (in_array($status, Leave::PENDING_STATUSES, true)) {
            $monthlyData[$month]['pending']++;
        }
    }
}
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">System Analytics</h1>
        <p class="page-subtitle">Comprehensive statistics and insights</p>
    </div>
</div>

<!-- Overview -->
<div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-brand hidden sm:inline-flex"><i class="fas fa-user-graduate"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Active Students</p>
            <p class="stat-value"><?php echo $totalStudents; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-sky hidden sm:inline-flex"><i class="fas fa-file-alt"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Total Applications</p>
            <p class="stat-value"><?php echo $totalLeaves; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-emerald hidden sm:inline-flex"><i class="fas fa-check-circle"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Approved</p>
            <p class="stat-value"><?php echo $approvedCount; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-rose hidden sm:inline-flex"><i class="fas fa-times-circle"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Rejected</p>
            <p class="stat-value"><?php echo $rejectedCount; ?></p>
        </div>
    </div>
</div>

<?php if ($totalLeaves === 0): ?>
    <!-- Empty State -->
    <div class="card mb-6 p-5 sm:p-6">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-chart-pie"></i></div>
            <h3 class="empty-state-title">No data available</h3>
            <p class="empty-state-description">Analytics will appear once leave applications are submitted</p>
        </div>
    </div>
<?php else: ?>
    <!-- Charts Row 1 -->
    <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Leave Type Distribution -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Leave Type Distribution</h3>
                    <p class="card-subtitle">All applications by leave type</p>
                </div>
                <span class="icon-chip-sm chip-violet"><i class="fas fa-chart-pie"></i></span>
            </div>
            <div class="card-body">
                <div class="relative h-64 sm:h-72">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Application Status</h3>
                    <p class="card-subtitle">Approved, rejected and still in the approval chain</p>
                </div>
                <span class="icon-chip-sm chip-emerald"><i class="fas fa-tasks"></i></span>
            </div>
            <div class="card-body">
                <div class="relative h-64 sm:h-72">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Trend Chart -->
    <div class="card mb-6">
        <div class="card-header">
            <div>
                <h3 class="card-title">Monthly Trend</h3>
                <p class="card-subtitle">Applications by month submitted, last 12 months</p>
            </div>
            <span class="icon-chip-sm chip-brand"><i class="fas fa-chart-line"></i></span>
        </div>
        <div class="card-body">
            <div class="relative h-72 sm:h-80">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Key Metrics & System Health -->
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Key Metrics -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Key Metrics</h3>
                <p class="card-subtitle">Across all applications</p>
            </div>
            <span class="icon-chip-sm chip-amber"><i class="fas fa-chart-bar"></i></span>
        </div>
        <div class="card-body space-y-5">
            <div>
                <div class="flex items-baseline justify-between gap-4">
                    <span class="text-sm font-medium text-gray-600">Approval Rate</span>
                    <span class="text-xl font-semibold tabular-nums text-gray-900"><?php echo $approvalRate; ?>%</span>
                </div>
                <div class="meter mt-2"><span class="bg-emerald-500" style="width: <?php echo min(100, max(0, (float) $approvalRate)); ?>%"></span></div>
            </div>
            <div>
                <div class="flex items-baseline justify-between gap-4">
                    <span class="text-sm font-medium text-gray-600">Rejection Rate</span>
                    <span class="text-xl font-semibold tabular-nums text-gray-900"><?php echo $rejectionRate; ?>%</span>
                </div>
                <div class="meter mt-2"><span class="bg-rose-500" style="width: <?php echo min(100, max(0, (float) $rejectionRate)); ?>%"></span></div>
            </div>
            <div class="grid grid-cols-1 gap-3 pt-1 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <p class="flex items-center gap-2 text-xs font-medium text-gray-500"><i class="fas fa-user-graduate text-brand-500"></i>Avg Applications/Student</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900"><?php echo $avgPerStudent; ?></p>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <p class="flex items-center gap-2 text-xs font-medium text-gray-500"><i class="fas fa-hourglass-half text-amber-500"></i>Pending Applications</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900"><?php echo $pendingCount; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- System Health -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">System Health</h3>
                <p class="card-subtitle">Core services</p>
            </div>
            <span class="icon-chip-sm chip-rose"><i class="fas fa-heartbeat"></i></span>
        </div>
        <div class="divide-y divide-gray-100 px-5 sm:px-6">
            <div class="flex items-center justify-between gap-3 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip-sm chip-brand"><i class="fas fa-database"></i></span>
                    <span class="text-sm font-medium text-gray-700">Database</span>
                </div>
                <span class="badge badge-approved"><i class="fas fa-check-circle"></i>Healthy</span>
            </div>
            <div class="flex items-center justify-between gap-3 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip-sm chip-violet"><i class="fas fa-envelope"></i></span>
                    <span class="text-sm font-medium text-gray-700">Email Service</span>
                </div>
                <span class="badge badge-approved"><i class="fas fa-check-circle"></i>Active</span>
            </div>
            <div class="flex items-center justify-between gap-3 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip-sm chip-emerald"><i class="fas fa-clock"></i></span>
                    <span class="text-sm font-medium text-gray-700">Cron Jobs</span>
                </div>
                <span class="badge badge-approved"><i class="fas fa-check-circle"></i>Scheduled</span>
            </div>
            <div class="flex items-center justify-between gap-3 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="icon-chip-sm chip-amber"><i class="fas fa-shield-alt"></i></span>
                    <span class="text-sm font-medium text-gray-700">Security</span>
                </div>
                <span class="badge badge-approved"><i class="fas fa-check-circle"></i>Enabled</span>
            </div>
        </div>
    </div>
</div>

<!-- Admin Activity Log -->
<div class="card mt-6 overflow-hidden">
    <div class="card-header">
        <div>
            <h3 class="card-title">Recent Activity Log</h3>
            <p class="card-subtitle">Last 50 actions</p>
        </div>
        <span class="icon-chip-sm chip-sky"><i class="fas fa-history"></i></span>
    </div>
    <div class="max-h-[420px] overflow-auto">
        <?php if (empty($activityLogs)): ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
                    <p class="empty-state-title">No activity logs yet</p>
                </div>
            </div>
        <?php else: ?>
            <table class="table-modern">
                <thead class="sticky top-0 z-10">
                    <tr>
                        <th class="bg-gray-50">Time</th>
                        <th class="bg-gray-50">User</th>
                        <th class="bg-gray-50">Action</th>
                        <th class="hidden bg-gray-50 lg:table-cell">Module</th>
                        <th class="hidden bg-gray-50 md:table-cell">Description</th>
                        <th class="hidden bg-gray-50 xl:table-cell">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activityLogs as $log): ?>
                        <tr>
                            <td class="text-gray-500">
                                <span title="<?php echo formatDateTime($log['created_at']); ?>" class="whitespace-nowrap">
                                    <?php echo timeAgo($log['created_at']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <?php
                                    $roleIcon = match($log['user_role'] ?? '') {
                                        'admin' => 'fa-user-shield text-rose-500',
                                        'hod' => 'fa-user-tie text-emerald-500',
                                        'faculty' => 'fa-chalkboard-teacher text-violet-500',
                                        'student' => 'fa-user-graduate text-brand-500',
                                        default => 'fa-user text-gray-400'
                                    };
                                    ?>
                                    <i class="fas <?php echo $roleIcon; ?> w-4 flex-shrink-0 text-center"></i>
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-gray-900">
                                            <?php echo sanitize($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <?php if ($log['user_email']): ?>
                                            <div class="hidden truncate text-xs text-gray-500 sm:block"><?php echo sanitize($log['user_email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php
                                $actionColor = match(true) {
                                    str_contains($log['action'], 'create') || str_contains($log['action'], 'add') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    str_contains($log['action'], 'delete') || str_contains($log['action'], 'remove') => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    str_contains($log['action'], 'update') || str_contains($log['action'], 'edit') => 'bg-brand-50 text-brand-700 ring-brand-200',
                                    str_contains($log['action'], 'login') => 'bg-sky-50 text-sky-700 ring-sky-200',
                                    str_contains($log['action'], 'approve') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    str_contains($log['action'], 'reject') => 'bg-orange-50 text-orange-700 ring-orange-200',
                                    str_contains($log['action'], 'password') => 'bg-amber-50 text-amber-800 ring-amber-200',
                                    default => 'bg-gray-100 text-gray-700 ring-gray-200'
                                };
                                ?>
                                <span class="badge <?php echo $actionColor; ?>">
                                    <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                                </span>
                            </td>
                            <td class="hidden text-gray-600 lg:table-cell">
                                <span class="capitalize"><?php echo sanitize($log['module']); ?></span>
                            </td>
                            <td class="hidden max-w-xs truncate text-gray-600 md:table-cell" title="<?php echo sanitize($log['description']); ?>">
                                <?php echo sanitize($log['description']); ?>
                            </td>
                            <td class="hidden font-mono text-xs text-gray-500 xl:table-cell">
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
// Chart styling shared by the three charts: the portal's Inter + slate ink,
// hairline grids, brand palette (leave types match their badges).
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.boxPadding = 4;

const percentTooltip = {
    callbacks: {
        label: function(context) {
            let label = context.label || '';
            let value = context.parsed || 0;
            let total = context.dataset.data.reduce((a, b) => a + b, 0);
            let percentage = ((value / total) * 100).toFixed(1);
            return label + ': ' + value + ' (' + percentage + '%)';
        }
    }
};

// Type Distribution Chart
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: ['Personal', 'Medical', 'Academic'],
        datasets: [{
            data: [<?php echo $personal; ?>, <?php echo $medical; ?>, <?php echo $academic; ?>],
            backgroundColor: ['#7c3aed', '#e11d48', '#0284c7'],
            hoverOffset: 6,
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 16,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: percentTooltip
        }
    }
});

// Status Distribution Chart
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Rejected', 'Pending'],
        datasets: [{
            data: [<?php echo $approvedCount; ?>, <?php echo $rejectedCount; ?>, <?php echo $pendingCount; ?>],
            backgroundColor: ['#059669', '#e11d48', '#d97706'],
            hoverOffset: 6,
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 16,
                    font: {
                        size: 12
                    }
                }
            },
            tooltip: percentTooltip
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
                borderColor: '#059669',
                backgroundColor: 'rgba(5, 150, 105, 0.12)',
                pointBackgroundColor: '#059669',
                pointStyle: 'circle',
                tension: 0.4,
                cubicInterpolationMode: 'monotone',
                fill: true
            },
            {
                label: 'Rejected',
                data: [<?php echo implode(', ', array_column($monthlyData, 'rejected')); ?>],
                borderColor: '#e11d48',
                backgroundColor: 'rgba(225, 29, 72, 0.12)',
                pointBackgroundColor: '#e11d48',
                pointStyle: 'rectRot',
                tension: 0.4,
                cubicInterpolationMode: 'monotone',
                fill: true
            },
            {
                label: 'Pending',
                data: [<?php echo implode(', ', array_column($monthlyData, 'pending')); ?>],
                borderColor: '#d97706',
                backgroundColor: 'rgba(217, 119, 6, 0.12)',
                pointBackgroundColor: '#d97706',
                pointStyle: 'triangle',
                tension: 0.4,
                cubicInterpolationMode: 'monotone',
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
        elements: {
            line: { borderWidth: 2 },
            point: { radius: 3, hoverRadius: 5, borderColor: '#ffffff', borderWidth: 1.5 }
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 16
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
                border: { display: false },
                ticks: {
                    stepSize: 1,
                    precision: 0
                },
                grid: {
                    color: '#f1f5f9'
                }
            },
            x: {
                border: { color: '#e2e8f0' },
                grid: {
                    display: false
                },
                ticks: { maxRotation: 0 }
            }
        }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
