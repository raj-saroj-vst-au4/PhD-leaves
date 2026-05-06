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

<div class="mb-4 sm:mb-6">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">My Students</h1>
    <p class="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">Students currently assigned to you</p>
</div>

<?php displayFlashMessage(); ?>

<!-- Role Tabs -->
<div class="mb-4 sm:mb-6">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-4 sm:space-x-8 overflow-x-auto">
            <a href="?role=guide" class="<?php echo $activeRole === 'guide' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-3 sm:py-4 px-1 border-b-2 font-medium text-sm sm:text-base">
                <i class="fas fa-user-tie mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">As </span>Faculty Guide
                <span class="ml-1 sm:ml-2 bg-orange-100 text-orange-800 py-0.5 px-2 rounded-full text-xs font-medium">
                    <?php echo count($studentsAsGuide); ?>
                </span>
            </a>
            <a href="?role=ta" class="<?php echo $activeRole === 'ta' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> whitespace-nowrap py-3 sm:py-4 px-1 border-b-2 font-medium text-sm sm:text-base">
                <i class="fas fa-chalkboard-teacher mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">As </span>TA Coordinator
                <span class="ml-1 sm:ml-2 bg-blue-100 text-blue-800 py-0.5 px-2 rounded-full text-xs font-medium">
                    <?php echo count($studentsAsTA); ?>
                </span>
            </a>
        </nav>
    </div>
</div>

<?php if (empty($activeStudents)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
        <i class="fas fa-user-graduate text-5xl sm:text-6xl text-gray-300 mb-3 sm:mb-4"></i>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-700 mb-2">No Students Assigned</h3>
        <p class="text-gray-500 text-sm sm:text-base">
            You don't have any students assigned as <?php echo $activeRole === 'guide' ? 'Faculty Guide' : 'TA Coordinator'; ?>.
        </p>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden">
            <div class="p-3 sm:p-4 bg-gray-50 border-b border-gray-200">
                <p class="text-sm text-gray-600 font-medium"><?php echo count($activeStudents); ?> student(s)</p>
            </div>
            <div class="divide-y divide-gray-200">
                <?php foreach ($activeStudents as $student): ?>
                    <div class="p-3 sm:p-4">
                        <div class="flex items-start justify-between mb-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900 text-sm sm:text-base"><?php echo sanitize($student['name']); ?></p>
                                <p class="text-xs sm:text-sm text-gray-500"><?php echo sanitize($student['roll_number']); ?></p>
                            </div>
                        </div>
                        <div class="space-y-1 text-xs sm:text-sm mt-2">
                            <div class="truncate">
                                <span class="text-gray-500">Email:</span>
                                <span class="text-gray-700"><?php echo sanitize($student['email']); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500">Joined:</span>
                                <span class="text-gray-700"><?php echo formatDate($student['joining_date']); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500"><?php echo $activeRole === 'guide' ? 'TA Coord:' : 'Guide:'; ?></span>
                                <?php if ($activeRole === 'guide'): ?>
                                    <span class="text-gray-700"><?php echo $student['ta_coordinator_name'] ? sanitize($student['ta_coordinator_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?></span>
                                <?php else: ?>
                                    <span class="text-gray-700"><?php echo $student['guide_name'] ? sanitize($student['guide_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mt-3 pt-2 border-t border-gray-100">
                            <span class="text-xs text-gray-500">Balance:</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo ($student['personal_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                P: <?php echo $student['personal_leaves'] ?? '0'; ?>
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo ($student['medical_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                M: <?php echo $student['medical_leaves'] ?? '0'; ?>
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo ($student['academic_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                A: <?php echo $student['academic_leaves'] ?? '0'; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto">
            <table id="studentsTable" class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No.</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Email</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">Joined</th>
                        <?php if ($activeRole === 'guide'): ?>
                            <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">TA Coord.</th>
                        <?php else: ?>
                            <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">Guide</th>
                        <?php endif; ?>
                        <th class="px-3 lg:px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Personal</th>
                        <th class="px-3 lg:px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Medical</th>
                        <th class="px-3 lg:px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Academic</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($activeStudents as $student): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 lg:px-4 py-3 font-medium text-gray-900">
                                <?php echo sanitize($student['roll_number']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-900">
                                <?php echo sanitize($student['name']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-600 hidden lg:table-cell">
                                <?php echo sanitize($student['email']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700 whitespace-nowrap hidden xl:table-cell">
                                <?php echo formatDate($student['joining_date']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700 hidden xl:table-cell">
                                <?php if ($activeRole === 'guide'): ?>
                                    <?php echo $student['ta_coordinator_name'] ? sanitize($student['ta_coordinator_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?>
                                <?php else: ?>
                                    <?php echo $student['guide_name'] ? sanitize($student['guide_name']) : '<span class="text-gray-400 italic">Not assigned</span>'; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo ($student['personal_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $student['personal_leaves'] ?? '0'; ?>
                                </span>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo ($student['medical_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $student['medical_leaves'] ?? '0'; ?>
                                </span>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo ($student['academic_leaves'] ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $student['academic_leaves'] ?? '0'; ?>
                                </span>
                            </td>
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
