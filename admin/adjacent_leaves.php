<?php
/**
 * Admin - Adjacent Leaves Management
 * View and combine adjacent leave applications
 */

// NOTE: POST handler must come BEFORE header.php include (it redirects).
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../includes/EmailService.php';

requireRole('admin');

$leaveClass = new Leave();

// Handle combine action
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'combine') {
    checkCSRF();
    try {
        $leaveIds = (array) ($_POST['leave_ids'] ?? []);
        $remarks = sanitize($_POST['remarks'] ?? '');

        if (count($leaveIds) < 2) {
            throw new Exception("Please select at least 2 leaves to combine");
        }

        $result = $leaveClass->combineLeaves($leaveIds, getCurrentUserId(), $remarks);

        // Tell whoever acts next. A leave held at pending_routing has no
        // approver until the office decides the guide/TA change it is queued on.
        try {
            $combined = $leaveClass->getById($result['new_leave_id']);
            $emailService = new EmailService();
            if ($result['status'] === 'approved') {
                $emailService->sendHODApproval($combined);
            } elseif ($result['status'] === 'pending_admin') {
                $emailService->sendAdminNotification($combined);
            } elseif ($result['status'] === 'pending_guide' && !empty($combined['guide_email'])) {
                $emailService->sendLeaveApplication($combined, 'guide');
            }
        } catch (Exception $emailEx) {
            logError("Failed to send combined leave notification email: " . $emailEx->getMessage());
        }

        $outcome = [
            'approved' => 'It stays approved',
            'pending_admin' => 'It is academic leave over ' . EXTENDED_LEAVE_THRESHOLD . ' days, so it now needs Admin (Dean AP) approval',
            'pending_guide' => 'It has been sent to the Faculty Guide for approval as one application',
            'pending_routing' => 'It is held until the student\'s pending guide/TA change is decided',
        ][$result['status']] ?? "Status: {$result['status']}";
        $balanceNote = ($result['refunded_days'] ? " {$result['refunded_days']} day(s) already deducted were refunded" : '')
            . ($result['deducted_days'] ? ($result['refunded_days'] ? ' and' : '') . " {$result['deducted_days']} day(s) deducted for the combined leave" : '')
            . (($result['refunded_days'] || $result['deducted_days']) ? '.' : '');

        // Post/Redirect/Get, so a reload cannot post the combination again.
        redirectWithMessage(BASE_URL . '/admin/adjacent_leaves.php', 'success',
            "Combined " . count($result['combined_leave_ids']) . " leaves into Leave #{$result['new_leave_id']} "
            . "({$result['start_date']} to {$result['end_date']}, {$result['total_days']} days). $outcome." . $balanceNote);
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$pageTitle = 'Adjacent Leaves';
require_once __DIR__ . '/../includes/header.php';

// Get adjacent leave groups
$adjacentGroups = $leaveClass->getAdjacentLeaveGroups();
$adjacentLeaves = $leaveClass->getLeavesWithAdjacentFlags();
?>

<?php
// Presentation only: avatar initials, the colour of each leave type on the
// mini timeline, and the summary tiles.
$personInitials = static function ($name) {
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials !== '' ? $initials : '?';
};
$typeColours = ['personal' => 'bg-violet-500', 'medical' => 'bg-rose-500', 'academic' => 'bg-sky-500'];
$groupStudents = count(array_unique(array_column($adjacentGroups, 'student_id')));
?>

<a href="<?php echo BASE_URL; ?>/admin/leave_management.php" class="back-link">
    <i class="fas fa-arrow-left"></i> Back to Leave Management
</a>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Adjacent Leaves</h1>
        <p class="page-subtitle">Review and combine leave applications that are adjacent (separated only by weekends/holidays)</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert <?php echo $messageType === 'success' ? 'alert-success' : 'alert-error'; ?> mb-6" role="alert">
        <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mt-0.5"></i>
        <p class="flex-1"><?php echo sanitize($message); ?></p>
    </div>
<?php endif; ?>

<?php displayFlashMessage(); ?>

<?php if (!empty($adjacentGroups)): ?>
<!-- Summary -->
<div class="mb-6 grid gap-4 sm:grid-cols-3">
    <div class="stat-tile">
        <span class="icon-chip chip-amber"><i class="fas fa-layer-group"></i></span>
        <div>
            <p class="stat-label">Groups to review</p>
            <p class="stat-value"><?php echo count($adjacentGroups); ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-violet"><i class="fas fa-file-alt"></i></span>
        <div>
            <p class="stat-label">Leaves flagged</p>
            <p class="stat-value"><?php echo count($adjacentLeaves); ?></p>
        </div>
    </div>
    <div class="stat-tile">
        <span class="icon-chip chip-sky"><i class="fas fa-user-graduate"></i></span>
        <div>
            <p class="stat-label">Students</p>
            <p class="stat-value"><?php echo $groupStudents; ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Info Box -->
<div class="alert alert-info mb-6">
    <i class="fas fa-info-circle mt-0.5"></i>
    <div>
        <h3 class="font-semibold">Why Adjacent Leaves Matter</h3>
        <p class="mt-1">
            Students might try to apply for separate leaves (e.g., Friday and Monday) to get 4 days off while only using 2 leave days.
            With the new policy counting all calendar days, these should be combined into a single leave application.
        </p>
        <p class="mt-2">
            Only leaves of the same type that are all approved, or all still pending, can be combined. Approved
            leaves are refunded and the combined leave is charged once, and stays approved (academic leave that
            now exceeds <?php echo EXTENDED_LEAVE_THRESHOLD; ?> days goes to the Admin; if that is rejected, the
            original leaves stand again). Pending leaves go back to the Faculty Guide as one application.
        </p>
    </div>
</div>

<?php if (empty($adjacentGroups)): ?>
    <div class="card p-5 sm:p-6">
        <div class="empty-state">
            <div class="empty-state-icon text-emerald-500"><i class="fas fa-check-circle"></i></div>
            <h2 class="empty-state-title">No Adjacent Leaves Found</h2>
            <p class="empty-state-description">There are no leave applications that need to be combined.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Adjacent Leave Groups -->
    <div class="space-y-6">
    <?php foreach ($adjacentGroups as $index => $group): ?>
        <div class="card overflow-hidden">
            <div class="card-header">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="avatar h-10 w-10 text-sm" aria-hidden="true"><?php echo sanitize($personInitials($group['student_name'])); ?></span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider text-amber-600">
                            Adjacent Leave Group #<?php echo $index + 1; ?>
                        </p>
                        <h3 class="truncate text-base font-semibold text-gray-900">
                            <?php echo sanitize($group['student_name']); ?>
                            <span class="font-normal text-gray-500">(<?php echo sanitize($group['roll_number']); ?>)</span>
                        </h3>
                    </div>
                </div>
                <span class="badge badge-pending shrink-0">
                    <?php echo count($group['leaves']); ?> leaves
                </span>
            </div>

            <form method="POST" class="grid grid-cols-1 lg:grid-cols-5">
                <?php echo getCSRFField(); ?>
                <input type="hidden" name="action" value="combine">

                <!-- The leaves, in date order, with the gap between each pair -->
                <div class="p-5 sm:p-6 lg:col-span-3">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Select the leaves to combine</p>
                    <div class="relative">
                    <span class="absolute bottom-6 left-[7px] top-6 w-px bg-gray-200" aria-hidden="true"></span>
                    <ol class="space-y-2">
                        <?php
                        $leaves = array_values($group['leaves']);
                        usort($leaves, function($a, $b) {
                            return strtotime($a['start_date']) - strtotime($b['start_date']);
                        });

                        $totalDays = 0;
                        $minDate = null;
                        $maxDate = null;
                        $mixedTypes = count(array_unique(array_column($leaves, 'leave_type'))) > 1;
                        $approvedCount = count(array_filter($leaves, fn($l) => $l['status'] === 'approved'));
                        $mixedStatus = $approvedCount > 0 && $approvedCount < count($leaves);
                        $prevEnd = null;

                        foreach ($leaves as $leave):
                            $totalDays += $leave['total_days'];
                            if ($minDate === null || strtotime($leave['start_date']) < strtotime($minDate)) {
                                $minDate = $leave['start_date'];
                            }
                            if ($maxDate === null || strtotime($leave['end_date']) > strtotime($maxDate)) {
                                $maxDate = $leave['end_date'];
                            }
                            $gapDays = $prevEnd === null ? null
                                : (int) round((strtotime($leave['start_date']) - strtotime($prevEnd)) / 86400) - 1;
                            $prevEnd = $leave['end_date'];
                        ?>
                            <?php if ($gapDays !== null && $gapDays >= 0): ?>
                                <li class="pl-7 text-xs text-gray-500">
                                    <?php echo $gapDays === 0 ? 'Back to back' : $gapDays . '-day gap (weekend/holiday)'; ?>
                                </li>
                            <?php endif; ?>
                            <li class="relative pl-7">
                                <span class="absolute left-0 top-4 h-[15px] w-[15px] rounded-full border-[3px] border-white <?php echo $typeColours[$leave['leave_type']] ?? 'bg-gray-400'; ?> shadow ring-1 ring-gray-200" aria-hidden="true"></span>
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 bg-white p-3.5 transition hover:border-gray-300 has-[:checked]:border-brand-300 has-[:checked]:bg-brand-50/40">
                                    <input type="checkbox"
                                           name="leave_ids[]"
                                           value="<?php echo $leave['id']; ?>"
                                           checked
                                           class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="text-sm font-semibold text-gray-900">
                                                <?php echo formatDate($leave['start_date']); ?> <span class="font-normal text-gray-400">&ndash;</span> <?php echo formatDate($leave['end_date']); ?>
                                            </span>
                                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                                            <?php echo getStatusBadge($leave['status']); ?>
                                        </span>
                                        <span class="mt-1 block truncate text-xs text-gray-500" title="<?php echo sanitize($leave['reason']); ?>">
                                            <span class="font-medium text-gray-700">#<?php echo $leave['id']; ?></span>
                                            &middot; <span class="font-medium text-gray-700"><?php echo $leave['total_days']; ?> <?php echo (float) $leave['total_days'] == 1 ? 'day' : 'days'; ?></span>
                                            &middot; <?php echo sanitize(substr($leave['reason'], 0, 50)) . (strlen($leave['reason']) > 50 ? '...' : ''); ?>
                                        </span>
                                    </span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    </div>

                    <?php if ($mixedTypes): ?>
                        <div class="alert alert-error mt-4">
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <p>These leaves are of different types. Only leaves of the same type can be combined; select those only.</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($mixedStatus): ?>
                        <div class="alert alert-error mt-4">
                            <i class="fas fa-exclamation-circle mt-0.5"></i>
                            <p>Some of these leaves are approved and some are still pending. Decide the pending application(s) first, then combine.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Combination Preview -->
                <?php
                $leaveClass2 = new Leave();
                $combinedDays = $leaveClass2->calculateWorkingDays($minDate, $maxDate);
                $spanDays = max(1, (int) round((strtotime($maxDate) - strtotime($minDate)) / 86400) + 1);
                ?>
                <div class="flex flex-col gap-5 border-t border-gray-100 bg-gray-50/70 p-5 sm:p-6 lg:col-span-2 lg:border-l lg:border-t-0">
                    <div>
                        <h4 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                            <i class="fas fa-compress-arrows-alt text-brand-600"></i>If Combined:
                        </h4>

                        <!-- Mini timeline: each leave on the span it would become -->
                        <div class="mt-4">
                            <p class="mb-2 text-xs text-gray-500">Date Range</p>
                            <div class="relative h-3 overflow-hidden rounded-full bg-gray-200/80" aria-hidden="true">
                                <?php foreach ($leaves as $leave):
                                    $left = (strtotime($leave['start_date']) - strtotime($minDate)) / 86400 / $spanDays * 100;
                                    $width = (round((strtotime($leave['end_date']) - strtotime($leave['start_date'])) / 86400) + 1) / $spanDays * 100;
                                ?>
                                    <span class="absolute inset-y-0 rounded-full <?php echo $typeColours[$leave['leave_type']] ?? 'bg-gray-400'; ?>"
                                          style="left: <?php echo round($left, 2); ?>%; width: <?php echo round($width, 2); ?>%"
                                          title="#<?php echo (int) $leave['id']; ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-1.5 flex justify-between text-sm font-medium text-gray-900">
                                <span><?php echo formatDate($minDate); ?></span>
                                <span><?php echo formatDate($maxDate); ?></span>
                            </div>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-gray-200">
                                <dt class="text-xs text-gray-500">Separate Total</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900"><?php echo $totalDays; ?> days</dd>
                            </div>
                            <div class="rounded-xl bg-white p-3 ring-1 ring-inset ring-brand-200">
                                <dt class="text-xs text-brand-700">Combined Total</dt>
                                <dd class="mt-1 text-sm font-semibold text-gray-900">
                                    <?php echo $combinedDays; ?> days
                                    <?php if ($combinedDays > $totalDays): ?>
                                        <span class="block text-xs font-medium text-rose-600">(+<?php echo $combinedDays - $totalDays; ?> days)</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Remarks -->
                    <div>
                        <label for="remarks_<?php echo (int) $index; ?>" class="form-label">
                            Admin Remarks (optional)
                        </label>
                        <textarea name="remarks"
                                  id="remarks_<?php echo (int) $index; ?>"
                                  rows="2"
                                  class="form-textarea"
                                  placeholder="Add any remarks about why these leaves are being combined..."></textarea>
                    </div>

                    <!-- Actions -->
                    <div class="mt-auto flex justify-end">
                        <button type="submit" class="btn btn-primary w-full sm:w-auto">
                            <i class="fas fa-compress-arrows-alt"></i>Combine Selected Leaves
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
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
