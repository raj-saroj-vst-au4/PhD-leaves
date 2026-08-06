<?php
/**
 * Admin — monthly attendance sign-off status.
 *
 * Read-only oversight: where every student's month has got to, and which faculty
 * member is holding it up. Admins deliberately cannot approve on a faculty
 * member's behalf — the sign-off is their attestation, and a proxy approval
 * would make the audit trail a fiction. To correct the underlying attendance an
 * admin uses Attendance → override, which supersedes the record properly.
 */

$pageTitle = 'Attendance Sign-off Status';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'hod']);

require_once __DIR__ . '/../classes/AttendanceApproval.php';

$approval = new AttendanceApproval();
$periods = $approval->availablePeriods();

// Selected period: ?period=YYYY-MM, defaulting to the newest that exists.
$sel = sanitize($_GET['period'] ?? '');
$year = $month = null;
if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $sel, $m)) {
    $year = (int) $m[1];
    $month = (int) $m[2];
} elseif ($periods) {
    $year = (int) $periods[0]['period_year'];
    $month = (int) $periods[0]['period_month'];
}

$rows = $summary = $outstanding = [];
$periodLabel = '—';
if ($year && $month) {
    $rows = $approval->getMonthOverview($year, $month);
    $summary = $approval->monthSummary($year, $month);
    $outstanding = $approval->outstandingByFaculty($year, $month);
    $periodLabel = DateTime::createFromFormat('!Y-n-j', "$year-$month-1")->format('F Y');
}

/** Badge for the overall state of one student-month. */
function signoffBadge($status)
{
    $map = [
        'pending_ta'    => ['bg-yellow-100 text-yellow-800', 'Awaiting TA'],
        'pending_guide' => ['bg-orange-100 text-orange-800', 'Awaiting Guide'],
        'approved'      => ['bg-green-100 text-green-800', 'Approved'],
        'rejected'      => ['bg-red-100 text-red-800', 'Disputed'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-gray-100 text-gray-700', $status];
    return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . $cls . '">'
        . $label . '</span>';
}

/** Per-stage cell: decision, who made it, and when. */
function stageCell($status, $actorName, $actedAt, $remarks)
{
    if ($status === 'skipped') {
        return '<span class="text-xs text-gray-400">not applicable</span>';
    }
    if ($status === 'pending') {
        return '<span class="text-xs text-yellow-700">pending</span>';
    }
    $icon = $status === 'approved'
        ? '<i class="fas fa-check text-green-600 mr-1"></i>'
        : '<i class="fas fa-flag text-red-600 mr-1"></i>';
    $out = '<div class="text-xs">' . $icon . ($status === 'approved' ? 'approved' : 'disputed');
    if ($actorName) {
        $out .= ' by ' . sanitize($actorName);
    }
    if ($actedAt) {
        $out .= '<div class="text-gray-400">' . formatDate($actedAt) . '</div>';
    }
    $out .= '</div>';
    if ($remarks) {
        $out .= '<div class="text-xs text-gray-600 mt-1 italic">&ldquo;' . sanitize($remarks) . '&rdquo;</div>';
    }
    return $out;
}
?>

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Attendance Sign-off Status</h1>
        <p class="text-gray-600 mt-2 text-sm sm:text-base">
            Monthly attendance approvals: TA coordinator first, then faculty guide.
        </p>
    </div>
    <?php if ($periods): ?>
    <form method="get" class="flex items-center gap-2">
        <label class="text-sm text-gray-500">Period</label>
        <select name="period" onchange="this.form.submit()"
                class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($periods as $p):
                $key = $p['period_year'] . '-' . sprintf('%02d', $p['period_month']);
                $lbl = DateTime::createFromFormat('!Y-n-j',
                    $p['period_year'] . '-' . $p['period_month'] . '-1')->format('F Y');
            ?>
                <option value="<?php echo $key; ?>"
                    <?php echo ($year . '-' . sprintf('%02d', $month)) === $key ? 'selected' : ''; ?>>
                    <?php echo $lbl; ?> (<?php echo (int) $p['total']; ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<?php displayFlashMessage(); ?>

<?php if (!$periods): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
        <i class="far fa-calendar-check text-5xl text-gray-300 mb-4"></i>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">No months opened yet</h3>
        <p class="text-gray-600 text-sm">
            Attendance is opened for sign-off automatically on the 1st of each month.
            Until the first run there is nothing to show here.
        </p>
    </div>
<?php else: ?>

    <!-- Summary -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">Students</p>
            <p class="text-2xl sm:text-3xl font-bold text-gray-900"><?php echo (int) $summary['total']; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">Awaiting TA</p>
            <p class="text-2xl sm:text-3xl font-bold text-yellow-600"><?php echo (int) $summary['pending_ta']; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">Awaiting Guide</p>
            <p class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo (int) $summary['pending_guide']; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">Approved</p>
            <p class="text-2xl sm:text-3xl font-bold text-green-600"><?php echo (int) $summary['approved']; ?></p>
        </div>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <p class="text-xs sm:text-sm text-gray-500 mb-1">Disputed</p>
            <p class="text-2xl sm:text-3xl font-bold text-red-600"><?php echo (int) $summary['rejected']; ?></p>
        </div>
    </div>

    <?php if (!empty($outstanding)): ?>
    <!-- Who to chase -->
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
        <h3 class="font-semibold text-yellow-800 text-sm mb-2">
            <i class="fas fa-hourglass-half mr-2"></i>Still waiting on
        </h3>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($outstanding as $o): ?>
                <span class="inline-flex items-center gap-2 bg-white border border-yellow-200 rounded-lg px-3 py-1.5 text-xs">
                    <span class="font-medium text-gray-900"><?php echo sanitize($o['name']); ?></span>
                    <span class="text-gray-500"><?php echo $o['role'] === 'ta' ? 'TA Coordinator' : 'Faculty Guide'; ?></span>
                    <span class="px-1.5 py-0.5 rounded-full bg-yellow-100 text-yellow-800 font-semibold">
                        <?php echo (int) $o['pending_count']; ?>
                    </span>
                </span>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-yellow-800 mt-2">
            Faculty are emailed when their stage opens. Sign-off is theirs to give &mdash;
            to correct the underlying attendance instead, use
            <a href="<?php echo BASE_URL; ?>/admin/attendance.php" class="underline font-medium">Attendance &rarr; override</a>.
        </p>
    </div>
    <?php endif; ?>

    <!-- Detail -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="p-12 text-center text-gray-500">
                <i class="fas fa-inbox text-5xl mb-4"></i>
                <p>Nothing recorded for <?php echo $periodLabel; ?></p>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="signoffTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No.</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Excused</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Absent</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">TA stage</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Guide stage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($rows as $r): ?>
                    <tr class="<?php echo $r['status'] === 'rejected' ? 'bg-red-50' : ''; ?>">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900"><?php echo sanitize($r['student_name']); ?></div>
                            <div class="text-xs text-gray-500">
                                <?php echo (int) $r['working_days']; ?> working days
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?php echo sanitize($r['roll_number']); ?></td>
                        <td class="px-4 py-3 text-center text-green-700 font-medium"><?php echo (int) $r['present_days']; ?></td>
                        <td class="px-4 py-3 text-center text-amber-700"><?php echo (int) $r['excused_days']; ?></td>
                        <td class="px-4 py-3 text-center <?php echo (int) $r['absent_days'] > 0 ? 'text-red-700 font-bold' : 'text-gray-400'; ?>">
                            <?php echo (int) $r['absent_days']; ?>
                        </td>
                        <td class="px-4 py-3"><?php echo signoffBadge($r['status']); ?></td>
                        <td class="px-4 py-3">
                            <?php if (empty($r['ta_coordinator_id']) && $r['ta_status'] === 'pending'): ?>
                                <span class="text-xs text-red-600">no TA assigned</span>
                            <?php else: ?>
                                <div class="text-xs text-gray-500 mb-0.5"><?php echo sanitize($r['ta_name'] ?: '—'); ?></div>
                                <?php echo stageCell($r['ta_status'], $r['ta_acted_by_name'], $r['ta_acted_at'], $r['ta_remarks']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (empty($r['faculty_guide_id']) && $r['guide_status'] !== 'skipped'): ?>
                                <span class="text-xs text-red-600">no guide assigned</span>
                            <?php else: ?>
                                <div class="text-xs text-gray-500 mb-0.5"><?php echo sanitize($r['guide_name'] ?: '—'); ?></div>
                                <?php echo stageCell($r['guide_status'], $r['guide_acted_by_name'], $r['guide_acted_at'], $r['guide_remarks']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if ($('#signoffTable').length) {
        $('#signoffTable').DataTable({
            pageLength: 25,
            order: [],                       // keep the server's "needs attention first" order
            columnDefs: [{ orderable: false, targets: [6, 7] }],
            language: { emptyTable: 'Nothing recorded for this period' }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
