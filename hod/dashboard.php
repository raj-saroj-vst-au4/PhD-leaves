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
// 'combined' rows are superseded duplicates (combineLeaves() inserts one
// application spanning the originals), so counting them doubles the totals.
$recentLeaves = array_filter($recentLeaves, fn($l) => $l['status'] !== 'combined');

// Calculate statistics
$totalApplications = count($recentLeaves);
$approved = count(array_filter($recentLeaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($recentLeaves, fn($l) => $l['status'] === 'rejected'));
$pending = count(array_filter($recentLeaves, fn($l) => in_array($l['status'], Leave::PENDING_STATUSES, true)));

// By leave type
$personal = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'personal'));
$medical = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'medical'));
$academic = count(array_filter($recentLeaves, fn($l) => $l['leave_type'] === 'academic'));
?>


<?php
// ---- View helpers (presentation only) -------------------------------------
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$pendingHodCount = count($pendingApprovals);

// Initials for a person's avatar: first letter of the first two words.
$initialsOf = function ($name) {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
};

$typeRows = [
    ['label' => 'Personal', 'count' => $personal, 'bar' => 'bg-violet-500'],
    ['label' => 'Medical',  'count' => $medical,  'bar' => 'bg-rose-500'],
    ['label' => 'Academic', 'count' => $academic, 'bar' => 'bg-sky-500'],
];
$statusRows = [
    ['label' => 'Approved', 'count' => $approved, 'dot' => 'bg-emerald-500'],
    ['label' => 'Pending',  'count' => $pending,  'dot' => 'bg-amber-500'],
    ['label' => 'Rejected', 'count' => $rejected, 'dot' => 'bg-rose-500'],
];
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Head of Department</p>
        <h1 class="page-title"><?php echo $greeting; ?>, <?php echo sanitize(explode(' ', $userName)[0]); ?></h1>
        <p class="page-subtitle">
            <?php echo date('l, j F Y'); ?>
            <span class="mx-1.5 text-gray-300">&middot;</span>
            Department leave activity at a glance
        </p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="btn btn-primary">
            <i class="fas fa-clipboard-check"></i> Review approvals
            <?php if ($pendingHodCount > 0): ?>
                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-xs font-semibold leading-none ring-1 ring-inset ring-white/30"><?php echo $pendingHodCount; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Overview -->
<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <!-- Pending Approvals -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-amber"><i class="fas fa-clock"></i></span>
        <div>
            <p class="stat-label">Pending HOD review</p>
            <p class="stat-value"><?php echo count($pendingApprovals); ?></p>
            <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Review now <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <!-- Total Applications (Last 30 days) -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-brand"><i class="fas fa-file-alt"></i></span>
        <div>
            <p class="stat-label">Applications</p>
            <p class="stat-value"><?php echo $totalApplications; ?></p>
            <p class="stat-meta">Last 30 days</p>
        </div>
    </div>

    <!-- Approved -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-emerald"><i class="fas fa-check-circle"></i></span>
        <div>
            <p class="stat-label">Approved</p>
            <p class="stat-value"><?php echo $approved; ?></p>
            <p class="stat-meta">Last 30 days</p>
        </div>
    </div>

    <!-- Rejected -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-rose"><i class="fas fa-times-circle"></i></span>
        <div>
            <p class="stat-label">Rejected</p>
            <p class="stat-value"><?php echo $rejected; ?></p>
            <p class="stat-meta">Last 30 days</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-amber"><i class="fas fa-clipboard-check"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Pending approvals</p>
            <p class="mt-1 text-sm text-gray-500">Review and approve leave applications</p>
            <p class="mt-2"><span class="badge <?php echo count($pendingApprovals) > 0 ? 'badge-pending' : 'badge-neutral'; ?>"><?php echo count($pendingApprovals); ?> waiting</span></p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-brand"><i class="fas fa-list"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">All leaves</p>
            <p class="mt-1 text-sm text-gray-500">View all department leave records</p>
            <p class="mt-2"><span class="badge badge-neutral"><?php echo $totalApplications; ?> in 30 days</span></p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/hod/analytics.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-emerald"><i class="fas fa-chart-bar"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Analytics</p>
            <p class="mt-1 text-sm text-gray-500">Department leave statistics &amp; trends</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/hod/holidays.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-violet"><i class="fas fa-calendar-alt"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Holidays</p>
            <p class="mt-1 text-sm text-gray-500">View and manage institute holidays</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <!-- Recent Pending Applications -->
    <div class="card overflow-hidden lg:col-span-2">
        <div class="card-header">
            <div>
                <h2 class="card-title">Pending HOD approvals</h2>
                <p class="card-subtitle">Applications waiting on your decision</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                View all <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </div>
        <?php if (!empty($pendingApprovals)): ?>
            <div class="overflow-x-auto">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Applied</th>
                            <th class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($pendingApprovals, 0, 10) as $leave): ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="avatar bg-none bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($leave['student_name'])); ?></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="text-gray-700"><?php echo formatDate($leave['start_date']); ?> <span class="text-gray-300">&ndash;</span> <?php echo formatDate($leave['end_date']); ?></div>
                                    <div class="text-xs text-gray-500"><span class="font-semibold text-gray-700"><?php echo $leave['total_days']; ?></span> days</div>
                                </td>
                                <td class="whitespace-nowrap text-gray-500">
                                    <?php echo timeAgo($leave['created_at']); ?>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php?id=<?php echo $leave['id']; ?>" class="btn btn-secondary btn-sm">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($pendingApprovals) > 10): ?>
                <div class="card-footer text-center">
                    <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                        View all <?php echo count($pendingApprovals); ?> pending approvals <i class="fas fa-arrow-right text-xs"></i>
                    </a>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-check-circle text-emerald-500"></i></div>
                    <p class="empty-state-title">All caught up</p>
                    <p class="empty-state-description">No leave applications are waiting for HOD review right now.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Leave Type Distribution & Status -->
    <div class="card self-start">
        <div class="card-header">
            <div>
                <h2 class="card-title">Last 30 days</h2>
                <p class="card-subtitle">Leave types and outcomes</p>
            </div>
        </div>
        <div class="card-body space-y-6">
            <div>
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Leave type distribution</p>
                <div class="space-y-4">
                    <?php foreach ($typeRows as $row): ?>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-700"><?php echo $row['label']; ?></span>
                                <span class="font-semibold tabular-nums text-gray-900"><?php echo $row['count']; ?></span>
                            </div>
                            <div class="meter">
                                <span class="<?php echo $row['bar']; ?>" style="width: <?php echo $totalApplications > 0 ? ($row['count'] / $totalApplications * 100) : 0; ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="border-t border-gray-100 pt-5">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">Status overview</p>
                <ul class="space-y-3 text-sm">
                    <?php foreach ($statusRows as $row): ?>
                        <li class="flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2.5 text-gray-700"><span class="h-2.5 w-2.5 rounded-full <?php echo $row['dot']; ?>"></span><?php echo $row['label']; ?></span>
                            <span class="font-semibold tabular-nums text-gray-900"><?php echo $row['count']; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
