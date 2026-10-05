<?php
/**
 * Faculty - My Students
 * View all students assigned to this faculty as guide or TA coordinator
 */

$pageTitle = 'My Students';
require_once __DIR__ . '/../includes/header.php';
requireRole('faculty');

require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Leave.php';

$userId = getCurrentUserId();
$userClass = new User();
$leaveClass = new Leave();

// Determine active tab
$activeRole = sanitize($_GET['role'] ?? 'guide');
if (!in_array($activeRole, ['guide', 'ta'])) {
    $activeRole = 'guide';
}

// Get students for each role
$studentsAsGuide = $userClass->getStudentsForFaculty($userId, 'guide');
$studentsAsTA = $userClass->getStudentsForFaculty($userId, 'ta_coordinator');
$activeStudents = ($activeRole === 'guide') ? $studentsAsGuide : $studentsAsTA;
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Faculty</p>
        <h1 class="page-title">My Students</h1>
        <p class="page-subtitle">Students currently assigned to you</p>
    </div>
</div>

<?php displayFlashMessage(); ?>

<?php
$segBase = 'inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition';
$segOn   = $segBase . ' bg-white text-gray-900 shadow-sm';
$segOff  = $segBase . ' text-gray-600 hover:text-gray-900';

// Balance meters: each leave type in its badge colour, scaled to the annual
// allocation (a carried-forward balance above it shows a full bar).
$balanceTypes = [
    'personal_leaves' => ['label' => 'Personal', 'short' => 'P', 'bar' => 'bg-violet-500', 'alloc' => PERSONAL_LEAVE_ALLOCATION],
    'medical_leaves'  => ['label' => 'Medical',  'short' => 'M', 'bar' => 'bg-rose-500',   'alloc' => MEDICAL_LEAVE_ALLOCATION],
    'academic_leaves' => ['label' => 'Academic', 'short' => 'A', 'bar' => 'bg-sky-500',    'alloc' => ACADEMIC_LEAVE_ALLOCATION],
];
$balancePct = function ($value, $alloc) {
    $value = (float) ($value ?? 0);
    return $alloc > 0 ? max(0, min(100, round($value / $alloc * 100))) : 0;
};
$initialsOf = function ($name) {
    $words = preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    foreach (array_slice($words, 0, 2) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return htmlspecialchars($out !== '' ? $out : '?', ENT_QUOTES, 'UTF-8');
};
?>

<!-- Role Tabs -->
<div class="mb-6">
    <nav class="inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1" aria-label="Students by role">
        <a href="?role=guide" class="<?php echo $activeRole === 'guide' ? $segOn : $segOff; ?>">
            <i class="fas fa-user-tie text-orange-500"></i>
            <span><span class="hidden sm:inline">As </span>Faculty Guide</span>
            <span class="rounded-md bg-orange-100 px-1.5 text-xs font-semibold tabular-nums text-orange-800">
                <?php echo count($studentsAsGuide); ?>
            </span>
        </a>
        <a href="?role=ta" class="<?php echo $activeRole === 'ta' ? $segOn : $segOff; ?>">
            <i class="fas fa-chalkboard-teacher text-brand-500"></i>
            <span><span class="hidden sm:inline">As </span>TA Coordinator</span>
            <span class="rounded-md bg-brand-100 px-1.5 text-xs font-semibold tabular-nums text-brand-800">
                <?php echo count($studentsAsTA); ?>
            </span>
        </a>
    </nav>
</div>

<?php if (empty($activeStudents)): ?>
    <div class="card p-5 sm:p-6">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-user-graduate"></i></div>
            <h3 class="empty-state-title">No Students Assigned</h3>
            <p class="empty-state-description">
                You don't have any students assigned as <?php echo $activeRole === 'guide' ? 'Faculty Guide' : 'TA Coordinator'; ?>.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="card overflow-hidden">
        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden">
            <div class="border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-medium text-gray-500"><?php echo count($activeStudents); ?> student(s)</p>
            </div>
            <div class="divide-y divide-gray-100">
                <?php foreach ($activeStudents as $student): ?>
                    <div class="px-4 py-4">
                        <div class="flex items-center gap-3">
                            <span class="icon-chip-sm chip-brand rounded-full text-xs font-semibold" aria-hidden="true"><?php echo $initialsOf($student['name']); ?></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-900"><?php echo sanitize($student['name']); ?></p>
                                <p class="truncate text-xs text-gray-500"><?php echo sanitize($student['roll_number']); ?> &middot; <?php echo sanitize($student['email']); ?></p>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div>
                                <span class="text-gray-500">Joined</span>
                                <span class="ml-1 text-gray-700"><?php echo formatDate($student['joining_date']); ?></span>
                            </div>
                            <div class="truncate">
                                <span class="text-gray-500"><?php echo $activeRole === 'guide' ? 'TA Coord.' : 'Guide'; ?></span>
                                <?php if ($activeRole === 'guide'): ?>
                                    <span class="ml-1 text-gray-700"><?php echo $student['ta_coordinator_name'] ? sanitize($student['ta_coordinator_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?></span>
                                <?php else: ?>
                                    <span class="ml-1 text-gray-700"><?php echo $student['guide_name'] ? sanitize($student['guide_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-3 rounded-xl bg-gray-50 px-3 py-2.5">
                            <?php foreach ($balanceTypes as $key => $bt): $bal = $student[$key] ?? '0'; ?>
                                <div>
                                    <div class="flex items-baseline justify-between gap-1">
                                        <span class="text-xs text-gray-500"><?php echo $bt['label']; ?></span>
                                        <span class="text-sm font-semibold tabular-nums <?php echo (float) $bal > 0 ? 'text-gray-900' : 'text-rose-600'; ?>"><?php echo $bal; ?></span>
                                    </div>
                                    <div class="meter mt-1.5"><span class="<?php echo $bt['bar']; ?>" style="width: <?php echo $balancePct($bal, $bt['alloc']); ?>%"></span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden overflow-x-auto md:block">
            <table id="studentsTable" class="w-full text-sm">
                <thead>
                    <tr>
                        <th>Roll No.</th>
                        <th>Name</th>
                        <th class="hidden lg:table-cell">Email</th>
                        <th class="hidden xl:table-cell">Joined</th>
                        <?php if ($activeRole === 'guide'): ?>
                            <th class="hidden xl:table-cell">TA Coord.</th>
                        <?php else: ?>
                            <th class="hidden xl:table-cell">Guide</th>
                        <?php endif; ?>
                        <th>Personal</th>
                        <th>Medical</th>
                        <th>Academic</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeStudents as $student): ?>
                        <tr>
                            <td class="whitespace-nowrap font-medium tabular-nums text-gray-900">
                                <?php echo sanitize($student['roll_number']); ?>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="icon-chip-sm chip-brand h-8 w-8 rounded-full text-xs font-semibold" aria-hidden="true"><?php echo $initialsOf($student['name']); ?></span>
                                    <span class="font-medium text-gray-900"><?php echo sanitize($student['name']); ?></span>
                                </div>
                            </td>
                            <td class="hidden text-gray-600 lg:table-cell">
                                <?php echo sanitize($student['email']); ?>
                            </td>
                            <td class="hidden whitespace-nowrap text-gray-600 xl:table-cell" data-order="<?php echo htmlspecialchars((string) $student['joining_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo formatDate($student['joining_date']); ?>
                            </td>
                            <td class="hidden text-gray-700 xl:table-cell">
                                <?php if ($activeRole === 'guide'): ?>
                                    <?php echo $student['ta_coordinator_name'] ? sanitize($student['ta_coordinator_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?>
                                <?php else: ?>
                                    <?php echo $student['guide_name'] ? sanitize($student['guide_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($balanceTypes as $key => $bt): $bal = $student[$key] ?? '0'; ?>
                                <td data-order="<?php echo (float) $bal; ?>">
                                    <div class="w-20" title="<?php echo $bt['label']; ?>: <?php echo $bal; ?> day(s) left (annual allocation <?php echo $bt['alloc']; ?>)">
                                        <span class="text-sm font-semibold tabular-nums <?php echo (float) $bal > 0 ? 'text-gray-900' : 'text-rose-600'; ?>"><?php echo $bal; ?></span>
                                        <div class="meter mt-1"><span class="<?php echo $bt['bar']; ?>" style="width: <?php echo $balancePct($bal, $bt['alloc']); ?>%"></span></div>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
$(document).ready(function() {
    if ($('#studentsTable').length && $('#studentsTable').is(':visible')) {
        $('#studentsTable').DataTable({
            responsive: true,
            autoWidth: false,
            order: [[0, 'asc']],
            pageLength: 25,
            language: {
                emptyTable: "No students found",
                search: "Search:"
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
