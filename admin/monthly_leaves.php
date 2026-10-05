<?php
/**
 * Admin - Monthly Leaves
 * Calendar-month view of who is on leave: student, roll number, dates, days
 * and reason. Linked from the Leave Management page.
 */

$pageTitle = 'Monthly Leaves';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/Leave.php';

$leaveClass = new Leave();

// Selected month (?month=YYYY-MM), defaulting to the current month
$monthParam = sanitize($_GET['month'] ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthStart = new DateTime($monthParam . '-01');
} else {
    $monthStart = new DateTime('first day of this month');
}
$monthEnd   = (clone $monthStart)->modify('last day of this month');
$monthKey   = $monthStart->format('Y-m');
$monthLabel = $monthStart->format('F Y');
$prevMonth  = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $monthStart)->modify('+1 month')->format('Y-m');

// Status group (?show=approved|pending|all)
$filterGroup = sanitize($_GET['show'] ?? 'approved');
if (!in_array($filterGroup, ['approved', 'pending', 'all'], true)) {
    $filterGroup = 'approved';
}

// Every application overlapping the month; group filtering happens below so
// the pill counts stay accurate for all groups at once.
$allLeaves = $leaveClass->getAll([
    'overlaps_from' => $monthStart->format('Y-m-d'),
    'overlaps_to'   => $monthEnd->format('Y-m-d'),
]);

// Combined-status rows are superseded duplicates: combineLeaves() inserts a new
// application spanning the same dates, so counting both would double the totals.
$allLeaves = array_filter($allLeaves, fn($l) => $l['status'] !== 'combined');

$pendingStatuses = Leave::PENDING_STATUSES;
$approvedCount = count(array_filter($allLeaves, fn($l) => $l['status'] === 'approved'));
$pendingCount  = count(array_filter($allLeaves, fn($l) => in_array($l['status'], $pendingStatuses, true)));

switch ($filterGroup) {
    case 'pending':
        $leaves = array_filter($allLeaves, fn($l) => in_array($l['status'], $pendingStatuses, true));
        break;
    case 'all':
        $leaves = $allLeaves;
        break;
    default:
        $leaves = array_filter($allLeaves, fn($l) => $l['status'] === 'approved');
}

// Precompute display rows: chronological, with the day-count clipped to the
// selected month (a leave spanning months only contributes its days inside it).
$rows = [];
$totalDaysInMonth = 0;
$studentIds = [];
foreach ($leaves as $leave) {
    $clipStart = max($leave['start_date'], $monthStart->format('Y-m-d'));
    $clipEnd   = min($leave['end_date'], $monthEnd->format('Y-m-d'));
    $daysInMonth = (new DateTime($clipStart))->diff(new DateTime($clipEnd))->days + 1;

    $totalDaysInMonth += $daysInMonth;
    $studentIds[$leave['student_id']] = true;

    $rows[] = ['leave' => $leave, 'daysInMonth' => $daysInMonth];
}
usort($rows, fn($a, $b) =>
    [$a['leave']['start_date'], $a['leave']['student_name']]
    <=> [$b['leave']['start_date'], $b['leave']['student_name']]);
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Monthly Leaves</h1>
        <p class="page-subtitle">Who is on leave in <?php echo $monthLabel; ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <!-- Month navigator -->
        <div class="inline-flex items-center gap-1 rounded-xl bg-gray-100 p-1">
            <a href="?month=<?php echo $prevMonth; ?>&show=<?php echo $filterGroup; ?>"
               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-white hover:text-gray-900 hover:shadow-sm" title="Previous month" aria-label="Previous month">
                <i class="fas fa-chevron-left text-xs"></i>
            </a>
            <form method="get">
                <input type="month" name="month" value="<?php echo $monthKey; ?>" onchange="this.form.submit()"
                       class="h-9 rounded-lg border-0 bg-white px-3 text-sm font-medium text-gray-900 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500/30" aria-label="Month">
                <input type="hidden" name="show" value="<?php echo $filterGroup; ?>">
            </form>
            <a href="?month=<?php echo $nextMonth; ?>&show=<?php echo $filterGroup; ?>"
               class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-white hover:text-gray-900 hover:shadow-sm" title="Next month" aria-label="Next month">
                <i class="fas fa-chevron-right text-xs"></i>
            </a>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="btn btn-secondary">
            <i class="fas fa-file-alt"></i>All Leaves
        </a>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php
// Card wording must match the active group — under "pending" these are requests,
// not leave actually taken, and "all" mixes statuses.
$cardLabels = [
    'approved' => ['Approved Applications', 'Students on Leave', 'Approved Leave Days'],
    'pending'  => ['Pending Applications', 'Students Awaiting Approval', 'Requested Leave Days'],
    'all'      => ['Applications (all statuses)', 'Students', 'Leave Days (all statuses)'],
][$filterGroup];
?>
<!-- Quick Stats -->
<div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
    <div class="stat-tile">
        <span class="icon-chip chip-brand"><i class="fas fa-file-alt"></i></span>
        <div class="min-w-0">
            <p class="stat-label"><?php echo $cardLabels[0]; ?></p>
            <p class="stat-value"><?php echo count($rows); ?></p>
            <p class="stat-meta">in <?php echo $monthLabel; ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-violet"><i class="fas fa-user-graduate"></i></span>
        <div class="min-w-0">
            <p class="stat-label"><?php echo $cardLabels[1]; ?></p>
            <p class="stat-value"><?php echo count($studentIds); ?></p>
            <p class="stat-meta">Distinct students</p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-amber"><i class="fas fa-calendar-day"></i></span>
        <div class="min-w-0">
            <p class="stat-label"><?php echo $cardLabels[2]; ?></p>
            <p class="stat-value"><?php echo $totalDaysInMonth; ?></p>
            <p class="stat-meta">Days falling in <?php echo $monthLabel; ?></p>
        </div>
    </div>
</div>

<?php
$segBase  = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-sm font-medium transition sm:gap-2 sm:px-3';
$segOn    = $segBase . ' bg-white text-gray-900 shadow-sm';
$segOff   = $segBase . ' text-gray-600 hover:text-gray-900';
$segCount = 'rounded-md bg-gray-200/80 px-1.5 text-xs font-semibold tabular-nums text-gray-700';
?>
<!-- Leaves Table -->
<div class="card overflow-hidden">
    <div class="card-header flex-col items-start sm:flex-row sm:items-center">
        <div>
            <h2 class="card-title"><?php echo $monthLabel; ?></h2>
            <p class="card-subtitle">Applications overlapping the month, earliest first</p>
        </div>
        <!-- Status Group Filter -->
        <nav class="inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" aria-label="Filter by status">
            <a href="?month=<?php echo $monthKey; ?>&show=approved" class="<?php echo $filterGroup === 'approved' ? $segOn : $segOff; ?>">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Approved <span class="<?php echo $segCount; ?>"><?php echo $approvedCount; ?></span>
            </a>
            <a href="?month=<?php echo $monthKey; ?>&show=pending" class="<?php echo $filterGroup === 'pending' ? $segOn : $segOff; ?>">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>Pending <span class="<?php echo $segCount; ?>"><?php echo $pendingCount; ?></span>
            </a>
            <a href="?month=<?php echo $monthKey; ?>&show=all" class="<?php echo $filterGroup === 'all' ? $segOn : $segOff; ?>">
                All <span class="<?php echo $segCount; ?>"><?php echo count($allLeaves); ?></span>
            </a>
        </nav>
    </div>

    <?php if (empty($rows)): ?>
        <div class="p-5 sm:p-6">
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-umbrella-beach"></i></div>
                <p class="empty-state-title">No <?php echo $filterGroup === 'all' ? '' : $filterGroup . ' '; ?>leaves in <?php echo $monthLabel; ?></p>
                <p class="empty-state-description">Try another month or status group.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="monthlyLeavesTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Roll Number</th>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Days in <?php echo $monthStart->format('M'); ?></th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): $leave = $row['leave']; ?>
                        <tr>
                            <td class="whitespace-nowrap font-medium text-gray-900">
                                <?php echo sanitize($leave['student_name']); ?>
                            </td>
                            <td class="whitespace-nowrap tabular-nums text-gray-500">
                                <?php echo sanitize($leave['roll_number']); ?>
                            </td>
                            <td class="whitespace-nowrap"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="whitespace-nowrap text-gray-700" data-order="<?php echo $leave['start_date']; ?>">
                                <?php echo formatDate($leave['start_date']); ?> <span class="text-gray-400">&ndash;</span> <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="whitespace-nowrap" data-order="<?php echo $row['daysInMonth']; ?>">
                                <span class="font-semibold tabular-nums text-gray-900"><?php echo $row['daysInMonth']; ?></span>
                                <?php if ((int)$leave['total_days'] !== $row['daysInMonth']): ?>
                                    <span class="text-xs text-gray-500">of <?php echo $leave['total_days']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-gray-600">
                                <span class="block truncate" style="max-width: 280px;" title="<?php echo sanitize($leave['reason']); ?>">
                                    <?php echo sanitize($leave['reason']); ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap"><?php echo getStatusBadge($leave['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    $('#monthlyLeavesTable').DataTable({
        responsive: true,
        order: [],          // keep the server's chronological order until the user sorts
        pageLength: 25,
        language: { emptyTable: "No leaves found" }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
