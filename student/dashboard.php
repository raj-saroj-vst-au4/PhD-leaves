<?php
/**
 * Student Dashboard
 * Main dashboard showing leave balances, pending applications, and recent activity
 */

$pageTitle = 'Dashboard';

// Require student role
require_once __DIR__ . '/../includes/header.php';
requireRole('student');

// Initialize classes
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Holiday.php';
require_once __DIR__ . '/../includes/attendance_today.php';

$userClass = new User();
$leaveClass = new Leave();
$holidayClass = new Holiday();

// Get current user ID
$userId = getCurrentUserId();

// Get student details with leave balances
$studentDetails = $userClass->getStudentDetails($userId);

if (!$studentDetails) {
    setFlashMessage('error', 'Student details not found. Please contact administrator.');
    header('Location: ' . BASE_URL . '/logout.php');
    exit();
}

$studentId = $studentDetails['student_id'];

// Get leave balances
$balance = $leaveClass->getBalance($studentId);

// Get pending leave days
$pendingPersonal = $leaveClass->getPendingLeaveDays($studentId, 'personal');
$pendingMedical = $leaveClass->getPendingLeaveDays($studentId, 'medical');
$pendingAcademic = $leaveClass->getPendingLeaveDays($studentId, 'academic');

// Calculate available balances
$availablePersonal = ($balance['personal_leaves'] ?? 0) - $pendingPersonal;
$availableMedical = ($balance['medical_leaves'] ?? 0) - $pendingMedical;
$availableAcademic = ($balance['academic_leaves'] ?? 0) - $pendingAcademic;

// All of the student's applications, newest first
$allMyLeaves = $leaveClass->getByStudent($studentId, []);

// Pending applications count, over ALL applications: it used to be counted
// after trimming to the five most recent, so older pending ones were missed.
$pendingCount = count(array_filter($allMyLeaves, function($leave) {
    return in_array($leave['status'], Leave::PENDING_STATUSES, true);
}));

// Recent leave applications (last 5)
$recentLeaves = array_slice($allMyLeaves, 0, 5);

// Get upcoming holidays
$upcomingHolidays = $holidayClass->getUpcoming(5);

// Check if eligible for academic leave
$isEligibleForAcademic = true; // Will be checked in the Leave class when applying
$joiningDate = $studentDetails['joining_date'];
$monthsSinceJoining = (time() - strtotime($joiningDate)) / (30 * 24 * 60 * 60);
$isEligibleForAcademic = $monthsSinceJoining >= ACADEMIC_LEAVE_ELIGIBILITY_MONTHS;

// Check if student has required guide and TA assignments
$hasGuide = !empty($studentDetails['faculty_guide_id']);
$hasTA = !empty($studentDetails['ta_coordinator_id']);
$hasRequiredAssignments = $hasGuide && $hasTA;

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

// One entry per balance card.
$balanceCards = [
    [
        'label' => 'Personal leave', 'icon' => 'fa-user-clock', 'chip' => 'chip-violet', 'bar' => 'bg-violet-500',
        'available' => $availablePersonal, 'pending' => $pendingPersonal,
        'allocation' => PERSONAL_LEAVE_ALLOCATION, 'locked' => false,
    ],
    [
        'label' => 'Medical leave', 'icon' => 'fa-heartbeat', 'chip' => 'chip-rose', 'bar' => 'bg-rose-500',
        'available' => $availableMedical, 'pending' => $pendingMedical,
        'allocation' => MEDICAL_LEAVE_ALLOCATION, 'locked' => false,
    ],
    [
        'label' => 'Academic leave', 'icon' => 'fa-graduation-cap', 'chip' => 'chip-sky', 'bar' => 'bg-sky-500',
        'available' => $availableAcademic, 'pending' => $pendingAcademic,
        'allocation' => ACADEMIC_LEAVE_ALLOCATION, 'locked' => !$isEligibleForAcademic,
    ],
];

// Icon chip per leave type, for the recent-applications list.
$typeChips = [
    'personal' => ['chip-violet', 'fa-user-clock'],
    'medical'  => ['chip-rose', 'fa-heartbeat'],
    'academic' => ['chip-sky', 'fa-graduation-cap'],
];

$coordinators = [
    ['role' => 'Faculty guide',  'name' => $studentDetails['guide_name'] ?? null,          'email' => $studentDetails['guide_email'] ?? null],
    ['role' => 'TA coordinator', 'name' => $studentDetails['ta_coordinator_name'] ?? null, 'email' => $studentDetails['ta_coordinator_email'] ?? null],
];
?>

<!-- Dashboard Header -->
<div class="page-header">
    <div>
        <p class="page-eyebrow">Student</p>
        <h1 class="page-title"><?php echo $greeting; ?>, <?php echo sanitize(explode(' ', trim($studentDetails['name']))[0]); ?></h1>
        <p class="page-subtitle">
            <?php echo date('l, j F Y'); ?>
            <span class="mx-1.5 text-gray-300">&middot;</span>
            <span class="whitespace-nowrap">Roll no. <?php echo sanitize($studentDetails['roll_number']); ?></span>
        </p>
    </div>
    <div class="flex gap-2">
        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="btn btn-primary">
            <i class="fas fa-paper-plane"></i> Apply for leave
        </a>
    </div>
</div>

<?php
// Today's attendance comes first: it is the one thing with a daily deadline.
$attToday = attendanceToday($studentDetails['student_id'] ?? getCurrentStudentId());
$attUrl = BASE_URL . '/student/attendance.php';
$guideUrl = BASE_URL . '/attendance-guide.php';
?>
<?php if ($attToday !== null && !$attToday['enrolled']): ?>
    <!-- Not registered for face attendance yet -->
    <div class="mb-8 overflow-hidden rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white shadow-lg">
        <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 text-xl ring-1 ring-inset ring-white/30">
                <i class="fas fa-id-badge"></i>
            </span>
            <div class="flex-1">
                <p class="text-xs font-semibold uppercase tracking-wider text-amber-100">Action needed · Attendance</p>
                <p class="mt-1 text-lg font-semibold">Register your face for attendance</p>
                <p class="mt-1 text-sm text-amber-50">
                    A one-time, five-minute step at the SOM office, on your own phone and the office WiFi.
                    Until you do, you can't mark attendance.
                </p>
            </div>
            <div class="flex flex-col gap-2 sm:items-end">
                <a href="<?php echo $guideUrl; ?>" class="btn bg-white text-orange-700 shadow-sm hover:bg-amber-50">
                    <i class="fas fa-book-open"></i> How to register
                </a>
                <a href="<?php echo $attUrl; ?>" class="text-sm font-medium text-white/90 underline-offset-2 hover:underline">
                    Go to Attendance <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
        </div>
    </div>
<?php elseif ($attToday !== null && $attToday['due']): ?>
    <!-- Attendance due today -->
    <a href="<?php echo $attUrl; ?>" class="group mb-8 block overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 text-white shadow-lg transition hover:shadow-xl">
        <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:p-6">
            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/20 text-xl ring-1 ring-inset ring-white/30">
                <i class="fas fa-camera"></i>
            </span>
            <div class="flex-1">
                <p class="text-xs font-semibold uppercase tracking-wider text-blue-100">Today &middot; <?php echo date('j M'); ?></p>
                <p class="mt-1 text-lg font-semibold">You haven't marked your attendance yet</p>
                <p class="mt-1 text-sm text-blue-50">
                    <?php if ($attToday['open_now']): ?>
                        Marking is open until <?php echo $attToday['end']; ?> &mdash; on the SOM office WiFi, from your enrolled phone.
                    <?php else: ?>
                        Marking opens at <?php echo $attToday['start']; ?> and closes at <?php echo $attToday['end']; ?>.
                    <?php endif; ?>
                </p>
            </div>
            <span class="btn bg-white text-blue-700 shadow-sm group-hover:bg-blue-50">
                <i class="fas fa-user-check"></i> Mark attendance
            </span>
        </div>
    </a>
<?php elseif ($attToday !== null && $attToday['marked']): ?>
    <div class="alert alert-success mb-8" role="status">
        <i class="fas fa-check-circle mt-0.5"></i>
        <p class="flex-1">Attendance marked for today<?php echo $attToday['marked_at'] ? ' at ' . sanitize($attToday['marked_at']) : ''; ?>.</p>
        <a href="<?php echo $attUrl; ?>" class="text-sm font-semibold hover:underline">View</a>
    </div>
<?php endif; ?>

<!-- Missing Guide/TA Warning -->
<?php if (!$hasRequiredAssignments): ?>
    <div class="alert alert-warning mb-8" role="alert">
        <i class="fas fa-exclamation-triangle mt-0.5 text-amber-500"></i>
        <div class="flex-1">
            <p class="font-semibold text-amber-900">Your profile setup is incomplete</p>
            <p class="mt-1 text-amber-800">Before you can apply for leave, you need the following assignments:</p>
            <ul class="mt-2 space-y-1">
                <?php if (!$hasGuide): ?>
                    <li class="flex items-center gap-2"><i class="fas fa-user-tie w-4 text-center text-xs text-amber-500"></i>Faculty Guide</li>
                <?php endif; ?>
                <?php if (!$hasTA): ?>
                    <li class="flex items-center gap-2"><i class="fas fa-chalkboard-teacher w-4 text-center text-xs text-amber-500"></i>TA Coordinator</li>
                <?php endif; ?>
            </ul>
            <p class="mt-2 text-amber-800">Please contact the staff PhD coordinator to complete these assignments.</p>
        </div>
    </div>
<?php endif; ?>

<!-- Leave Balance Cards -->
<section class="mb-8">
    <h2 class="sr-only">Leave balances</h2>
    <div class="grid gap-4 sm:grid-cols-3">
        <?php foreach ($balanceCards as $card): ?>
            <?php
            $isNegative = $card['available'] < 0;
            $meterPct = $isNegative
                ? min(100, abs($card['available']) / $card['allocation'] * 100)
                : min(100, max(0, $card['available'] / $card['allocation'] * 100));
            ?>
            <div class="card p-5">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="icon-chip-sm <?php echo $card['chip']; ?>"><i class="fas <?php echo $card['icon']; ?>"></i></span>
                        <p class="text-sm font-medium text-gray-700"><?php echo $card['label']; ?></p>
                    </div>
                    <?php if (!$card['locked'] && $card['pending'] > 0): ?>
                        <span class="badge badge-pending" title="Days in applications still awaiting approval">
                            <i class="fas fa-clock text-[10px]"></i><?php echo number_format($card['pending'], 1); ?> pending
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mt-4 flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tracking-tight <?php echo $isNegative ? 'text-rose-600' : 'text-gray-900'; ?>">
                        <?php echo number_format($card['available'], 1); ?>
                    </span>
                    <span class="text-sm text-gray-500">days available</span>
                </div>

                <div class="meter mt-4<?php echo $isNegative ? ' bg-rose-100' : ''; ?>">
                    <span class="<?php echo $isNegative ? 'bg-rose-500' : $card['bar']; ?>" style="width: <?php echo round($meterPct, 1); ?>%"></span>
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-gray-500">
                    <?php if ($isNegative): ?>
                        <span class="font-medium text-rose-600">Overdrawn by <?php echo number_format(abs($card['available']), 1); ?> days</span>
                    <?php else: ?>
                        <span>of <?php echo $card['allocation']; ?> days allotted</span>
                    <?php endif; ?>
                    <?php if ($card['locked']): ?>
                        <span class="inline-flex items-center gap-1 text-gray-400"><i class="fas fa-lock text-[10px]"></i> Available after 1 year</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Apply CTA & Stats Row -->
<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <a href="<?php echo BASE_URL; ?>/student/apply_leave.php"
       class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 p-6 text-white shadow-sm transition hover:shadow-lg col-span-2">
        <span class="pointer-events-none absolute -right-12 -top-16 h-48 w-48 rounded-full bg-white/10"></span>
        <span class="pointer-events-none absolute -bottom-20 right-24 h-40 w-40 rounded-full bg-white/5"></span>
        <div class="relative flex h-full flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-white/70">Planning time off?</p>
                <h2 class="mt-1 text-xl font-semibold">Apply for leave</h2>
                <p class="mt-1 text-sm text-white/80">Submit a new application and track it through each approval.</p>
            </div>
            <span class="btn shrink-0 self-start bg-white text-brand-700 shadow-sm transition group-hover:bg-blue-50 sm:self-auto">
                Apply now <i class="fas fa-arrow-right transition-transform group-hover:translate-x-0.5"></i>
            </span>
        </div>
    </a>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-amber"><i class="fas fa-clock"></i></span>
        <div>
            <p class="stat-label">Pending applications</p>
            <p class="stat-value"><?php echo $pendingCount; ?></p>
            <p class="stat-meta">Awaiting approval</p>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-brand"><i class="fas fa-file-alt"></i></span>
        <div>
            <p class="stat-label">Total applications</p>
            <p class="stat-value"><?php echo count(array_filter($allMyLeaves, function ($l) { return $l['status'] !== 'combined'; })); ?></p>
        </div>
    </div>
</div>

<!-- Recent Leave Applications & Sidebar -->
<div class="grid gap-6 lg:grid-cols-3">
    <!-- Recent Leave Applications -->
    <div class="lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Recent applications</h2>
                    <p class="card-subtitle">Your five most recent leave requests</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                    View all <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <?php if (empty($recentLeaves)): ?>
                <div class="p-5 sm:p-6">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-inbox"></i></div>
                        <p class="empty-state-title">No leave applications yet</p>
                        <p class="empty-state-description">Get started by submitting your first leave application.</p>
                        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="btn btn-secondary btn-sm mt-4">
                            <i class="fas fa-paper-plane"></i> Apply for your first leave
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($recentLeaves as $leave): ?>
                        <?php [$chipClass, $chipIcon] = $typeChips[$leave['leave_type']] ?? ['chip-slate', 'fa-file-alt']; ?>
                        <li class="flex items-start gap-4 px-5 py-4 transition-colors hover:bg-gray-50/70 sm:items-center sm:px-6">
                            <span class="icon-chip-sm <?php echo $chipClass; ?>"><i class="fas <?php echo $chipIcon; ?>"></i></span>
                            <div class="min-w-0 flex-1 sm:flex sm:items-center sm:justify-between sm:gap-4">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900">
                                        <?php echo getLeaveTypeLabel($leave['leave_type']); ?>
                                        <span class="font-normal text-gray-400">&middot;</span>
                                        <span class="font-normal text-gray-600"><?php echo $leave['total_days']; ?> days</span>
                                    </p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        <?php echo formatDate($leave['start_date']); ?> <span class="text-gray-300">&ndash;</span> <?php echo formatDate($leave['end_date']); ?>
                                        <span class="mx-1 text-gray-300">&middot;</span>
                                        <span class="whitespace-nowrap">Applied <?php echo timeAgo($leave['created_at']); ?></span>
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

    <div class="space-y-6">
        <!-- Upcoming Holidays -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Upcoming holidays</h2>
                <a href="<?php echo BASE_URL; ?>/student/holidays.php" class="inline-flex shrink-0 items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700">
                    View all <i class="fas fa-arrow-right text-xs"></i>
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($upcomingHolidays)): ?>
                    <div class="empty-state py-8">
                        <div class="empty-state-icon"><i class="fas fa-calendar-times"></i></div>
                        <p class="empty-state-title">No upcoming holidays</p>
                        <p class="empty-state-description">Institute holidays will show up here.</p>
                    </div>
                <?php else: ?>
                    <ul class="space-y-3">
                        <?php foreach ($upcomingHolidays as $holiday): ?>
                            <?php
                            $holidayTs = strtotime($holiday['holiday_date']);
                            $daysAway = (int) round(($holidayTs - strtotime('today')) / 86400);
                            $whenLabel = $daysAway <= 0 ? 'Today' : ($daysAway === 1 ? 'Tomorrow' : 'In ' . $daysAway . ' days');
                            ?>
                            <li class="flex items-center gap-3">
                                <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 ring-1 ring-inset ring-brand-100">
                                    <span class="text-[10px] font-semibold uppercase leading-none tracking-wide text-brand-600"><?php echo date('M', $holidayTs); ?></span>
                                    <span class="mt-1 text-lg font-semibold leading-none text-gray-900"><?php echo date('j', $holidayTs); ?></span>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-900"><?php echo sanitize($holiday['holiday_name']); ?></p>
                                    <p class="mt-0.5 text-xs text-gray-500"><?php echo date('l', $holidayTs); ?> &middot; <?php echo $whenLabel; ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Guide & TA Coordinator Info -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Your coordinators</h2>
            </div>
            <ul class="divide-y divide-gray-100">
                <?php foreach ($coordinators as $person): ?>
                    <li class="flex items-center gap-3 px-5 py-4 sm:px-6">
                        <?php if (!empty($person['name'])): ?>
                            <span class="avatar h-10 w-10 bg-none bg-brand-50 text-sm text-brand-700 ring-1 ring-inset ring-brand-100"><?php echo sanitize($initialsOf($person['name'])); ?></span>
                        <?php else: ?>
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm text-gray-400 ring-1 ring-inset ring-gray-200"><i class="fas fa-user-slash"></i></span>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400"><?php echo $person['role']; ?></p>
                            <p class="truncate text-sm font-semibold <?php echo !empty($person['name']) ? 'text-gray-900' : 'text-gray-500'; ?>">
                                <?php echo sanitize($person['name'] ?? 'Not Assigned'); ?>
                            </p>
                            <?php if (!empty($person['email'])): ?>
                                <a href="mailto:<?php echo sanitize($person['email']); ?>" class="block truncate text-xs text-brand-600 hover:text-brand-700 hover:underline">
                                    <?php echo sanitize($person['email']); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($person['email'])): ?>
                            <a href="mailto:<?php echo sanitize($person['email']); ?>" class="btn-icon hover:text-brand-600" title="Email <?php echo sanitize($person['name'] ?? ''); ?>" aria-label="Email <?php echo sanitize($person['name'] ?? ''); ?>">
                                <i class="fas fa-envelope"></i>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
