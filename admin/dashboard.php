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

// Recent applications (last 10)
$recentApplications = array_slice($allLeaves, 0, 10);

// Statistics. 'combined' rows are superseded duplicates (combineLeaves()
// inserts one application spanning the originals), so they are left out of
// the counts to avoid doubling them.
$countedLeaves = array_filter($allLeaves, fn($l) => $l['status'] !== 'combined');
$totalStudents = count($allStudents);
$totalFaculty = count($allFaculty);
$totalApplications = count($countedLeaves);
$pendingCount = count($pendingAdmin);

// By status. Pending = still in the approval chain; "total - approved -
// rejected" also counted cancelled and combined applications as pending.
$approved = count(array_filter($countedLeaves, fn($l) => $l['status'] === 'approved'));
$rejected = count(array_filter($countedLeaves, fn($l) => $l['status'] === 'rejected'));
$pending = count(array_filter($countedLeaves, fn($l) => in_array($l['status'], Leave::PENDING_STATUSES, true)));
?>


<?php
// ---- View helpers (presentation only) -------------------------------------
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// Initials for a person's avatar: first letter of the first two words.
$initialsOf = function ($name) {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
};

// Status overview: share of the decided + in-flight applications.
$statusRows = [
    ['label' => 'Approved', 'count' => $approved, 'dot' => 'bg-emerald-500'],
    ['label' => 'Pending',  'count' => $pending,  'dot' => 'bg-amber-500'],
    ['label' => 'Rejected', 'count' => $rejected, 'dot' => 'bg-rose-500'],
];
$statusTotal = $approved + $pending + $rejected;
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Administrator</p>
        <h1 class="page-title"><?php echo $greeting; ?>, <?php echo sanitize($userName); ?></h1>
        <p class="page-subtitle">
            <?php echo date('l, j F Y'); ?>
            <span class="mx-1.5 text-gray-300">&middot;</span>
            System overview &amp; management
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?php echo BASE_URL; ?>/admin/users.php?action=invite" class="btn btn-secondary">
            <i class="fas fa-user-plus"></i> Invite user
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/pending_approvals.php" class="btn btn-primary">
            <i class="fas fa-clipboard-check"></i> Pending approvals
            <?php if ($pendingCount > 0): ?>
                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-xs font-semibold leading-none ring-1 ring-inset ring-white/30"><?php echo $pendingCount; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Grid -->
<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-brand"><i class="fas fa-user-graduate"></i></span>
        <div>
            <p class="stat-label">Total students</p>
            <p class="stat-value"><?php echo $totalStudents; ?></p>
            <a href="<?php echo BASE_URL; ?>/admin/users.php?role=student" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Manage students <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-violet"><i class="fas fa-chalkboard-teacher"></i></span>
        <div>
            <p class="stat-label">Faculty members</p>
            <p class="stat-value"><?php echo $totalFaculty; ?></p>
            <a href="<?php echo BASE_URL; ?>/admin/users.php?role=faculty" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Manage faculty <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-emerald"><i class="fas fa-file-alt"></i></span>
        <div>
            <p class="stat-label">Leave applications</p>
            <p class="stat-value"><?php echo $totalApplications; ?></p>
            <a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                View all <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-amber"><i class="fas fa-clock"></i></span>
        <div>
            <p class="stat-label">Pending admin</p>
            <p class="stat-value"><?php echo $pendingCount; ?></p>
            <a href="<?php echo BASE_URL; ?>/admin/pending_approvals.php" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Review now <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>
</div>

<!-- Alert for Pending Approvals -->
<?php if ($pendingCount > 0): ?>
    <div class="alert alert-warning mb-8" role="status">
        <i class="fas fa-bell mt-0.5 text-amber-500"></i>
        <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-semibold text-amber-900">Action required</p>
                <p class="mt-0.5 text-amber-800">
                    You have <strong><?php echo $pendingCount; ?></strong> extended leave application(s) awaiting final admin approval.
                </p>
            </div>
            <a href="<?php echo BASE_URL; ?>/admin/pending_approvals.php" class="btn btn-warning btn-sm self-start sm:self-auto">
                Review now <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <a href="<?php echo BASE_URL; ?>/admin/users.php?action=invite" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-brand"><i class="fas fa-user-plus"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Invite user</p>
            <p class="mt-1 text-sm text-gray-500">Send invitation to new users</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/admin/holidays.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-violet"><i class="fas fa-calendar-alt"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Manage holidays</p>
            <p class="mt-1 text-sm text-gray-500">Add and update institute holidays</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/admin/attendance_approvals.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-sky"><i class="fas fa-user-check"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Attendance sign-off</p>
            <p class="mt-1 text-sm text-gray-500">Track the monthly attendance sign-off</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/admin/analytics.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-emerald"><i class="fas fa-chart-line"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">View analytics</p>
            <p class="mt-1 text-sm text-gray-500">System statistics and reports</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>
</div>

<!-- Leave Status Overview & Recent Applications -->
<div class="grid gap-6 lg:grid-cols-3">
    <div class="card self-start">
        <div class="card-header">
            <div>
                <h2 class="card-title">Leave status overview</h2>
                <p class="card-subtitle">All applications to date</p>
            </div>
        </div>
        <div class="card-body">
            <?php if ($statusTotal > 0): ?>
                <div class="mb-5 flex h-2 w-full overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
                    <?php foreach ($statusRows as $row): ?>
                        <?php if ($row['count'] > 0): ?>
                            <span class="h-full <?php echo $row['dot']; ?>" style="width: <?php echo round($row['count'] / $statusTotal * 100, 1); ?>%"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <ul class="space-y-3 text-sm">
                <?php foreach ($statusRows as $row): ?>
                    <li class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2.5 text-gray-700"><span class="h-2.5 w-2.5 rounded-full <?php echo $row['dot']; ?>"></span><?php echo $row['label']; ?></span>
                        <span class="flex items-baseline gap-2">
                            <span class="font-semibold tabular-nums text-gray-900"><?php echo $row['count']; ?></span>
                            <?php if ($statusTotal > 0): ?>
                                <span class="w-10 text-right text-xs tabular-nums text-gray-400"><?php echo round($row['count'] / $statusTotal * 100); ?>%</span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Recent Applications -->
    <div class="card lg:col-span-2">
        <div class="card-header">
            <div>
                <h2 class="card-title">Recent applications</h2>
                <p class="card-subtitle">The latest leave requests from all students</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                View all <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </div>
        <?php if (empty($recentApplications)): ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <p class="empty-state-title">No applications yet</p>
                    <p class="empty-state-description">Leave applications will appear here as students submit them.</p>
                </div>
            </div>
        <?php else: ?>
            <ul class="divide-y divide-gray-100">
                <?php foreach (array_slice($recentApplications, 0, 5) as $leave): ?>
                    <li class="flex items-start gap-3 px-5 py-3.5 transition-colors hover:bg-gray-50/70 sm:items-center sm:px-6">
                        <span class="avatar h-9 w-9 bg-none bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($leave['student_name'])); ?></span>
                        <div class="min-w-0 flex-1 sm:flex sm:items-center sm:justify-between sm:gap-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    <?php echo getLeaveTypeLabel($leave['leave_type']); ?> &middot; <?php echo $leave['total_days']; ?> days
                                    <span class="mx-1 text-gray-300">&middot;</span>
                                    <?php echo formatDate($leave['start_date']); ?>
                                </p>
                            </div>
                            <div class="mt-2 shrink-0 sm:mt-0">
                                <?php echo getStatusBadge($leave['status']); ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
