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

// Every leave that touches the year. start_date/end_date only match leaves
// lying wholly inside the range, so a leave from 28 Dec to 3 Jan appeared in
// neither year.
$startDate = "$year-01-01";
$endDate = "$year-12-31";
$leaves = $leaveClass->getAll(['overlaps_from' => $startDate, 'overlaps_to' => $endDate]);

// 'combined' rows are superseded duplicates (combineLeaves() inserts one
// application spanning the originals), so counting them doubles the totals.
$leaves = array_values(array_filter($leaves, fn($l) => $l['status'] !== 'combined'));

// Calculate statistics
$totalApplications = count($leaves);
$approved = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($leaves, fn($l) => $l['status'] === 'rejected'));
// Only applications still in the approval chain; "total - approved - rejected"
// also counted cancelled and combined applications as pending.
$pending = count(array_filter($leaves, fn($l) => in_array($l['status'], Leave::PENDING_STATUSES, true)));

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

// Total approved leave days in this year: a leave spanning New Year counts
// only its days inside the selected year (total_days counts calendar days).
$daysInYear = fn($l) => (new DateTime(max($l['start_date'], $startDate)))
    ->diff(new DateTime(min($l['end_date'], $endDate)))->days + 1;
$totalDays = array_sum(array_map($daysInYear, array_filter($leaves, fn($l) => $l['status'] === 'approved')));

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

<div class="page-header">
    <div>
        <p class="page-eyebrow">Department</p>
        <h1 class="page-title">Department Analytics</h1>
        <p class="page-subtitle">Leave statistics and trends</p>
    </div>
    <div class="flex items-center gap-2">
        <label for="analyticsYear" class="text-sm font-medium text-gray-500">Year</label>
        <select id="analyticsYear" onchange="window.location.href='?year='+this.value" class="form-select w-full sm:w-32">
            <?php for ($y = date('Y') - 2; $y <= date('Y'); $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<!-- Stats -->
<div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-brand hidden sm:inline-flex"><i class="fas fa-file-alt"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Total Applications</p>
            <p class="stat-value"><?php echo $totalApplications; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-emerald hidden sm:inline-flex"><i class="fas fa-check-circle"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Approved</p>
            <p class="stat-value"><?php echo $approved; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-rose hidden sm:inline-flex"><i class="fas fa-times-circle"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Rejected</p>
            <p class="stat-value"><?php echo $rejected; ?></p>
        </div>
    </div>
    <div class="stat-tile gap-3 p-4 sm:gap-4 sm:p-5">
        <span class="icon-chip chip-violet hidden sm:inline-flex"><i class="fas fa-calendar-day"></i></span>
        <div class="min-w-0">
            <p class="stat-label">Total Days</p>
            <p class="stat-value"><?php echo $totalDays; ?></p>
            <p class="stat-meta">Approved leave</p>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Leave Type Distribution -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Leave Type Distribution</h3>
                <p class="card-subtitle">Applications in <?php echo $year; ?> by leave type</p>
            </div>
            <span class="icon-chip-sm chip-violet"><i class="fas fa-chart-pie"></i></span>
        </div>
        <div class="card-body">
            <div class="relative h-64 sm:h-72">
                <canvas id="typeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Monthly Trend -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Monthly Applications</h3>
                <p class="card-subtitle">Applications submitted per month in <?php echo $year; ?></p>
            </div>
            <span class="icon-chip-sm chip-brand"><i class="fas fa-chart-bar"></i></span>
        </div>
        <div class="card-body">
            <div class="relative h-64 sm:h-72">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics & System Health -->
<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <!-- Key Metrics -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Key Metrics</h3>
                <p class="card-subtitle">Applications in <?php echo $year; ?></p>
            </div>
            <span class="icon-chip-sm chip-amber"><i class="fas fa-chart-line"></i></span>
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
            <div class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 px-4 py-3">
                <span class="flex items-center gap-2 text-sm font-medium text-gray-600"><i class="fas fa-hourglass-half text-amber-500"></i>Pending</span>
                <span class="text-xl font-semibold tabular-nums text-gray-900"><?php echo $pending; ?></span>
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
                    <span class="text-sm font-medium text-gray-700">Email</span>
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

<!-- Activity Log -->
<div class="card mt-6 overflow-hidden">
    <div class="card-header">
        <div>
            <h3 class="card-title">Recent Activity Log</h3>
            <p class="card-subtitle">Last 50 actions</p>
        </div>
        <span class="icon-chip-sm chip-sky"><i class="fas fa-history"></i></span>
    </div>
    <?php if (empty($activityLogs)): ?>
        <div class="p-5 sm:p-6">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-clipboard-list"></i></div>
                <p class="empty-state-title">No activity logs yet</p>
            </div>
        </div>
    <?php else: ?>
        <?php
        // Pre-compute action colors for reuse in both views
        function getActionColor($action) {
            return match(true) {
                str_contains($action, 'create') || str_contains($action, 'add') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                str_contains($action, 'delete') || str_contains($action, 'remove') => 'bg-rose-50 text-rose-700 ring-rose-200',
                str_contains($action, 'update') || str_contains($action, 'edit') => 'bg-brand-50 text-brand-700 ring-brand-200',
                str_contains($action, 'login') => 'bg-sky-50 text-sky-700 ring-sky-200',
                str_contains($action, 'approve') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                str_contains($action, 'reject') => 'bg-orange-50 text-orange-700 ring-orange-200',
                str_contains($action, 'password') => 'bg-amber-50 text-amber-800 ring-amber-200',
                default => 'bg-gray-100 text-gray-700 ring-gray-200'
            };
        }
        function getRoleIcon($role) {
            return match($role ?? '') {
                'admin' => 'fa-user-shield text-rose-500',
                'hod' => 'fa-user-tie text-emerald-500',
                'faculty' => 'fa-chalkboard-teacher text-violet-500',
                'student' => 'fa-user-graduate text-brand-500',
                default => 'fa-user text-gray-400'
            };
        }
        ?>

        <!-- Mobile Card View (< md) -->
        <div class="block max-h-[500px] overflow-y-auto md:hidden">
            <div class="divide-y divide-gray-100">
                <?php foreach ($activityLogs as $log): ?>
                    <div class="px-4 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-1 items-center gap-2">
                                <i class="fas <?php echo getRoleIcon($log['user_role']); ?> w-4 flex-shrink-0 text-center text-sm"></i>
                                <span class="truncate text-sm font-medium text-gray-900"><?php echo sanitize($log['user_name'] ?? 'System'); ?></span>
                            </div>
                            <span class="flex-shrink-0 whitespace-nowrap text-xs text-gray-400"><?php echo timeAgo($log['created_at']); ?></span>
                        </div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="badge <?php echo getActionColor($log['action']); ?>">
                                <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                            </span>
                            <span class="text-xs capitalize text-gray-500"><?php echo sanitize($log['module']); ?></span>
                        </div>
                        <?php if (!empty($log['description'])): ?>
                            <p class="mt-1.5 line-clamp-2 text-xs text-gray-600"><?php echo sanitize($log['description']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden max-h-[420px] overflow-auto md:block">
            <table class="table-modern">
                <thead class="sticky top-0 z-10">
                    <tr>
                        <th class="bg-gray-50">Time</th>
                        <th class="bg-gray-50">User</th>
                        <th class="bg-gray-50">Action</th>
                        <th class="hidden bg-gray-50 lg:table-cell">Module</th>
                        <th class="bg-gray-50">Description</th>
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
                                    <i class="fas <?php echo getRoleIcon($log['user_role']); ?> w-4 flex-shrink-0 text-center"></i>
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-gray-900">
                                            <?php echo sanitize($log['user_name'] ?? 'System'); ?>
                                        </div>
                                        <?php if ($log['user_email']): ?>
                                            <div class="hidden truncate text-xs text-gray-500 lg:block"><?php echo sanitize($log['user_email']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo getActionColor($log['action']); ?>">
                                    <?php echo sanitize(str_replace('_', ' ', ucwords($log['action']))); ?>
                                </span>
                            </td>
                            <td class="hidden text-gray-600 lg:table-cell">
                                <span class="capitalize"><?php echo sanitize($log['module']); ?></span>
                            </td>
                            <td class="max-w-xs truncate text-gray-600" title="<?php echo sanitize($log['description']); ?>">
                                <?php echo sanitize($log['description']); ?>
                            </td>
                            <td class="hidden font-mono text-xs text-gray-500 xl:table-cell">
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
// Chart styling: the portal's Inter + slate ink, hairline grids, brand
// palette (leave types match their badges).
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.color = '#64748b';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.boxPadding = 4;

// Leave Type Chart
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
                    padding: 16
                }
            }
        }
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
            backgroundColor: '#2d4fe3',
            hoverBackgroundColor: '#253ed0',
            borderRadius: 6,
            maxBarThickness: 28
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            // One series: the card title names it.
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                border: { display: false },
                ticks: { precision: 0 },
                grid: { color: '#f1f5f9' }
            },
            x: {
                border: { color: '#e2e8f0' },
                grid: { display: false },
                ticks: { maxRotation: 0 }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
