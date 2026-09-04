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

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Monthly Leaves</h1>
        <p class="text-gray-600 mt-2">Who is on leave in <?php echo $monthLabel; ?></p>
    </div>
    <div class="flex items-center gap-2">
        <a href="?month=<?php echo $prevMonth; ?>&show=<?php echo $filterGroup; ?>"
           class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="Previous month">
            <i class="fas fa-chevron-left"></i>
        </a>
        <form method="get">
            <input type="month" name="month" value="<?php echo $monthKey; ?>" onchange="this.form.submit()"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <input type="hidden" name="show" value="<?php echo $filterGroup; ?>">
        </form>
        <a href="?month=<?php echo $nextMonth; ?>&show=<?php echo $filterGroup; ?>"
           class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="Next month">
            <i class="fas fa-chevron-right"></i>
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/leave_management.php"
           class="ml-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 whitespace-nowrap">
            <i class="fas fa-file-alt mr-2"></i>All Leaves
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
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600"><?php echo $cardLabels[0]; ?> in <?php echo $monthLabel; ?></p>
        <p class="text-3xl font-bold text-gray-900"><?php echo count($rows); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600"><?php echo $cardLabels[1]; ?></p>
        <p class="text-3xl font-bold text-blue-600"><?php echo count($studentIds); ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-md p-6">
        <p class="text-sm text-gray-600"><?php echo $cardLabels[2]; ?> in <?php echo $monthLabel; ?></p>
        <p class="text-3xl font-bold text-orange-600"><?php echo $totalDaysInMonth; ?></p>
    </div>
</div>

<!-- Status Group Filter -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap gap-2">
        <a href="?month=<?php echo $monthKey; ?>&show=approved"
           class="px-4 py-2 <?php echo $filterGroup === 'approved' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">
            Approved <span class="ml-1 px-2 py-0.5 <?php echo $filterGroup === 'approved' ? 'bg-white bg-opacity-30' : 'bg-gray-200'; ?> rounded-full"><?php echo $approvedCount; ?></span>
        </a>
        <a href="?month=<?php echo $monthKey; ?>&show=pending"
           class="px-4 py-2 <?php echo $filterGroup === 'pending' ? 'bg-yellow-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">
            Pending <span class="ml-1 px-2 py-0.5 <?php echo $filterGroup === 'pending' ? 'bg-white bg-opacity-30' : 'bg-gray-200'; ?> rounded-full"><?php echo $pendingCount; ?></span>
        </a>
        <a href="?month=<?php echo $monthKey; ?>&show=all"
           class="px-4 py-2 <?php echo $filterGroup === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'; ?> rounded-lg text-sm">
            All <span class="ml-1 px-2 py-0.5 <?php echo $filterGroup === 'all' ? 'bg-white bg-opacity-30' : 'bg-gray-200'; ?> rounded-full"><?php echo count($allLeaves); ?></span>
        </a>
    </div>
</div>

<!-- Leaves Table -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($rows)): ?>
        <div class="p-12 text-center text-gray-500">
            <i class="fas fa-umbrella-beach text-6xl mb-4"></i>
            <p>No <?php echo $filterGroup === 'all' ? '' : $filterGroup . ' '; ?>leaves in <?php echo $monthLabel; ?></p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="monthlyLeavesTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days in <?php echo $monthStart->format('M'); ?></th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($rows as $row): $leave = $row['leave']; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                <?php echo sanitize($leave['student_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo sanitize($leave['roll_number']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900" data-order="<?php echo $leave['start_date']; ?>">
                                <?php echo formatDate($leave['start_date']); ?> - <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900" data-order="<?php echo $row['daysInMonth']; ?>">
                                <?php echo $row['daysInMonth']; ?>
                                <?php if ((int)$leave['total_days'] !== $row['daysInMonth']): ?>
                                    <span class="text-xs font-normal text-gray-500">of <?php echo $leave['total_days']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <span class="truncate block" style="max-width: 280px;" title="<?php echo sanitize($leave['reason']); ?>">
                                    <?php echo sanitize($leave['reason']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap"><?php echo getStatusBadge($leave['status']); ?></td>
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
