<?php
/**
 * Admin - Adjacent Leaves Management
 * View and combine adjacent leave applications
 */

$pageTitle = 'Adjacent Leaves';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

require_once __DIR__ . '/../classes/Leave.php';

$leaveClass = new Leave();

// Handle combine action
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'combine') {
    try {
        checkCSRF();

        $leaveIds = $_POST['leave_ids'] ?? [];
        $remarks = sanitize($_POST['remarks'] ?? '');

        if (count($leaveIds) < 2) {
            throw new Exception("Please select at least 2 leaves to combine");
        }

        $result = $leaveClass->combineLeaves($leaveIds, getCurrentUserId(), $remarks);

        if ($result['success']) {
            $message = "Successfully combined " . count($leaveIds) . " leaves into Leave #{$result['new_leave_id']} " .
                       "({$result['start_date']} to {$result['end_date']}, {$result['total_days']} days)";
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get adjacent leave groups
$adjacentGroups = $leaveClass->getAdjacentLeaveGroups();
$adjacentLeaves = $leaveClass->getLeavesWithAdjacentFlags();
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Adjacent Leaves</h1>
            <p class="text-gray-600 mt-2">Review and combine leave applications that are adjacent (separated only by weekends/holidays)</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="text-blue-600 hover:text-blue-700">
            <i class="fas fa-arrow-left mr-2"></i>Back to Leave Management
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'; ?>">
        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php displayFlashMessage(); ?>

<!-- Info Box -->
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
    <div class="flex items-start">
        <i class="fas fa-info-circle text-yellow-600 mt-1 mr-3"></i>
        <div>
            <h3 class="font-semibold text-yellow-800">Why Adjacent Leaves Matter</h3>
            <p class="text-sm text-yellow-700 mt-1">
                Students might try to apply for separate leaves (e.g., Friday and Monday) to get 4 days off while only using 2 leave days.
                With the new policy counting all calendar days, these should be combined into a single leave application.
            </p>
        </div>
    </div>
</div>

<?php if (empty($adjacentGroups)): ?>
    <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
        <h2 class="text-xl font-semibold text-gray-900 mb-2">No Adjacent Leaves Found</h2>
        <p class="text-gray-600">There are no leave applications that need to be combined.</p>
    </div>
<?php else: ?>
    <!-- Adjacent Leave Groups -->
    <?php foreach ($adjacentGroups as $index => $group): ?>
        <div class="bg-white rounded-lg shadow-md mb-6 overflow-hidden">
            <div class="bg-orange-50 border-b border-orange-200 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-orange-800">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Adjacent Leave Group #<?php echo $index + 1; ?>
                        </h3>
                        <p class="text-sm text-orange-600">
                            Student: <?php echo sanitize($group['student_name']); ?> (<?php echo sanitize($group['roll_number']); ?>)
                        </p>
                    </div>
                    <span class="px-3 py-1 bg-orange-200 text-orange-800 rounded-full text-sm font-medium">
                        <?php echo count($group['leaves']); ?> leaves
                    </span>
                </div>
            </div>

            <form method="POST" class="p-6">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="combine">

                <div class="overflow-x-auto mb-4">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Select</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Leave ID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dates</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $leaves = array_values($group['leaves']);
                            usort($leaves, function($a, $b) {
                                return strtotime($a['start_date']) - strtotime($b['start_date']);
                            });

                            $totalDays = 0;
                            $minDate = null;
                            $maxDate = null;

                            foreach ($leaves as $leave):
                                $totalDays += $leave['total_days'];
                                if ($minDate === null || strtotime($leave['start_date']) < strtotime($minDate)) {
                                    $minDate = $leave['start_date'];
                                }
                                if ($maxDate === null || strtotime($leave['end_date']) > strtotime($maxDate)) {
                                    $maxDate = $leave['end_date'];
                                }
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <input type="checkbox"
                                               name="leave_ids[]"
                                               value="<?php echo $leave['id']; ?>"
                                               checked
                                               class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">#<?php echo $leave['id']; ?></td>
                                    <td class="px-4 py-3"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900">
                                        <?php echo formatDate($leave['start_date']); ?> - <?php echo formatDate($leave['end_date']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900"><?php echo $leave['total_days']; ?></td>
                                    <td class="px-4 py-3"><?php echo getStatusBadge($leave['status']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="<?php echo sanitize($leave['reason']); ?>">
                                        <?php echo sanitize(substr($leave['reason'], 0, 50)) . (strlen($leave['reason']) > 50 ? '...' : ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Combination Preview -->
                <?php
                $leaveClass2 = new Leave();
                $combinedDays = $leaveClass2->calculateWorkingDays($minDate, $maxDate);
                ?>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <h4 class="font-semibold text-blue-800 mb-2">
                        <i class="fas fa-compress-arrows-alt mr-2"></i>If Combined:
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-blue-600">Date Range:</span>
                            <span class="font-medium text-blue-900">
                                <?php echo formatDate($minDate); ?> - <?php echo formatDate($maxDate); ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-blue-600">Separate Total:</span>
                            <span class="font-medium text-blue-900"><?php echo $totalDays; ?> days</span>
                        </div>
                        <div>
                            <span class="text-blue-600">Combined Total:</span>
                            <span class="font-medium text-blue-900"><?php echo $combinedDays; ?> days</span>
                            <?php if ($combinedDays > $totalDays): ?>
                                <span class="text-red-600 ml-1">(+<?php echo $combinedDays - $totalDays; ?> days)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Remarks -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Admin Remarks (optional)
                    </label>
                    <textarea name="remarks"
                              rows="2"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Add any remarks about why these leaves are being combined..."></textarea>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-3">
                    <button type="submit"
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        <i class="fas fa-compress-arrows-alt mr-2"></i>Combine Selected Leaves
                    </button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Warn if unchecking leaves
document.querySelectorAll('input[name="leave_ids[]"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const form = this.closest('form');
        const checked = form.querySelectorAll('input[name="leave_ids[]"]:checked').length;
        if (checked < 2) {
            alert('At least 2 leaves must be selected to combine.');
            this.checked = true;
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
