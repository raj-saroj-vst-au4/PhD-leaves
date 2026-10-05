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

<?php
// ---- View helpers (presentation only) -------------------------------------
$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$pendingTotal = count($pendingAsGuide) + count($pendingAsTA);

// Initials for a person's avatar: first letter of the first two words.
$initialsOf = function ($name) {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
};
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Faculty</p>
        <h1 class="page-title"><?php echo $greeting; ?>, <?php echo sanitize(explode(' ', $userName)[0]); ?></h1>
        <p class="page-subtitle">
            <?php echo date('l, j F Y'); ?>
            <span class="mx-1.5 text-gray-300">&middot;</span>
            <?php echo $pendingTotal > 0 ? 'Some applications are waiting on you.' : 'You’re all caught up.'; ?>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="btn btn-primary">
            <i class="fas fa-clipboard-check"></i> Review approvals
            <?php if ($pendingTotal > 0): ?>
                <span class="inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-white/20 px-1.5 py-0.5 text-xs font-semibold leading-none ring-1 ring-inset ring-white/30"><?php echo $pendingTotal; ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Stats Cards -->
<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <!-- Pending as Guide -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-amber"><i class="fas fa-user-tie"></i></span>
        <div>
            <p class="stat-label">Pending as guide</p>
            <p class="stat-value"><?php echo count($pendingAsGuide); ?></p>
            <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=guide" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Review applications <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <!-- Pending as TA Coordinator -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-brand"><i class="fas fa-chalkboard-teacher"></i></span>
        <div>
            <p class="stat-label">Pending as TA coordinator</p>
            <p class="stat-value"><?php echo count($pendingAsTA); ?></p>
            <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?role=ta" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700">
                Review applications <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <!-- Students as Guide -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-emerald"><i class="fas fa-users"></i></span>
        <div>
            <p class="stat-label">My students (guide)</p>
            <p class="stat-value"><?php echo count($myStudentsAsGuide); ?></p>
            <p class="stat-meta">Active students you guide</p>
        </div>
    </div>

    <!-- Students as TA Coordinator -->
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-violet"><i class="fas fa-user-graduate"></i></span>
        <div>
            <p class="stat-label">My students (TA)</p>
            <p class="stat-value"><?php echo count($myStudentsAsTA); ?></p>
            <p class="stat-meta">Active students you coordinate</p>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-brand"><i class="fas fa-clipboard-check"></i></span>
        <div class="min-w-0 flex-1">
            <p class="flex items-center gap-2 font-semibold text-gray-900">
                Leave approvals
                <?php if ($pendingTotal > 0): ?>
                    <span class="badge badge-pending"><?php echo $pendingTotal; ?> waiting</span>
                <?php endif; ?>
            </p>
            <p class="mt-1 text-sm text-gray-500">Review applications waiting on you as guide or TA coordinator.</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/faculty/attendance_approvals.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-emerald"><i class="fas fa-user-check"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">Attendance sign-off</p>
            <p class="mt-1 text-sm text-gray-500">Sign off your students’ monthly attendance.</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>

    <a href="<?php echo BASE_URL; ?>/faculty/my_students.php" class="card card-hover group flex items-start gap-4 p-5">
        <span class="icon-chip chip-violet"><i class="fas fa-user-graduate"></i></span>
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-900">My students</p>
            <p class="mt-1 text-sm text-gray-500">See the students you guide or coordinate, and their balances.</p>
        </div>
        <i class="fas fa-arrow-right mt-1 text-sm text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600"></i>
    </a>
</div>

<div class="grid gap-6 lg:grid-cols-4">
    <!-- Recent Activity -->
    <div class="card overflow-hidden lg:col-span-3">
        <div class="card-header">
            <div>
                <h2 class="card-title">Recent leave applications</h2>
                <p class="card-subtitle">Applications currently awaiting your review</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                View all <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </div>
        <?php if (empty($recentApprovals)): ?>
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                    <p class="empty-state-title">No recent applications</p>
                    <p class="empty-state-description">New leave applications from your students will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th class="relative text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentApprovals as $leave): ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="avatar bg-none bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($leave['student_name'])); ?></span>
                                        <div class="min-w-0">
                                            <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo sanitize($leave['roll_number']); ?>
                                                <span class="mx-0.5 text-gray-300">&middot;</span>
                                                <?php echo sanitize($leave['student_email']); ?>
                                            </div>
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
                                <td class="whitespace-nowrap">
                                    <?php echo getStatusBadge($leave['status']); ?>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php?id=<?php echo $leave['id']; ?>" class="btn-icon hover:text-brand-600" title="View application" aria-label="View application">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Review queue -->
    <div class="card self-start">
        <div class="card-header">
            <div>
                <h2 class="card-title">Your review queue</h2>
                <p class="card-subtitle">Leave applications at your stage</p>
            </div>
        </div>
        <div class="card-body">
            <ul class="space-y-3 text-sm">
                <li class="flex items-center justify-between gap-3">
                    <span class="flex items-center gap-2.5 text-gray-700"><span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>As faculty guide</span>
                    <span class="font-semibold tabular-nums text-gray-900"><?php echo count($pendingAsGuide); ?></span>
                </li>
                <li class="flex items-center justify-between gap-3">
                    <span class="flex items-center gap-2.5 text-gray-700"><span class="h-2.5 w-2.5 rounded-full bg-brand-500"></span>As TA coordinator</span>
                    <span class="font-semibold tabular-nums text-gray-900"><?php echo count($pendingAsTA); ?></span>
                </li>
            </ul>

            <?php if ($pendingTotal == 0): ?>
                <div class="mt-5 flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3 ring-1 ring-inset ring-emerald-100">
                    <i class="fas fa-check-circle text-lg text-emerald-500"></i>
                    <div>
                        <p class="text-sm font-semibold text-emerald-800">All caught up!</p>
                        <p class="text-xs text-emerald-700">You have no pending leave applications to review at this time.</p>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="btn btn-primary mt-5 w-full">
                    <i class="fas fa-clipboard-check"></i> Review now
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
