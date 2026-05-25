<?php
/**
 * Faculty Pending Approvals
 * Unified view: shows pending items from BOTH roles (Faculty Guide + TA Coordinator)
 * in a single prioritized feed so nothing gets missed behind a tab.
 */

$pageTitle = 'Pending Approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole('faculty');

require_once __DIR__ . '/../classes/Leave.php';
require_once __DIR__ . '/../classes/Approval.php';

$userId = getCurrentUserId();
$leaveClass = new Leave();
$approvalClass = new Approval();

// Pending applications for both roles
$pendingAsGuide = $leaveClass->getPendingForFaculty($userId, 'guide');
$pendingAsTA    = $leaveClass->getPendingForFaculty($userId, 'ta_coordinator');

foreach ($pendingAsGuide as &$_l) { $_l['_role'] = 'guide'; } unset($_l);
foreach ($pendingAsTA    as &$_l) { $_l['_role'] = 'ta';    } unset($_l);

// Merge & sort newest-first
$pendingAll = array_merge($pendingAsGuide, $pendingAsTA);
usort($pendingAll, function ($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// History
$historyAsGuide = $leaveClass->getHistoryForFaculty($userId, 'guide');
$historyAsTA    = $leaveClass->getHistoryForFaculty($userId, 'ta_coordinator');

foreach ($historyAsGuide as &$_l) { $_l['_role'] = 'guide'; } unset($_l);
foreach ($historyAsTA    as &$_l) { $_l['_role'] = 'ta';    } unset($_l);

$historyAll = array_merge($historyAsGuide, $historyAsTA);
usort($historyAll, function ($a, $b) {
    $aTime = $a['_role'] === 'guide' ? ($a['guide_approved_at'] ?? '') : ($a['ta_approved_at'] ?? '');
    $bTime = $b['_role'] === 'guide' ? ($b['guide_approved_at'] ?? '') : ($b['ta_approved_at'] ?? '');
    return strtotime($bTime) - strtotime($aTime);
});

$guideCount = count($pendingAsGuide);
$taCount    = count($pendingAsTA);
$totalCount = $guideCount + $taCount;
?>

<!-- Page Header -->
<div class="mb-4 sm:mb-6 flex items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Pending Approvals</h1>
        <p class="text-gray-600 mt-1 sm:mt-2 text-sm sm:text-base">
            Leave applications waiting on your decision &mdash; across <strong>both</strong> of your roles.
        </p>
    </div>
</div>

<?php displayFlashMessage(); ?>

<!-- Summary Strip -->
<?php if ($totalCount > 0): ?>
<div class="grid grid-cols-3 gap-2 sm:gap-4 mb-4 sm:mb-6">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
        <p class="text-xs sm:text-sm text-gray-500 mb-0.5 sm:mb-1">Total Pending</p>
        <p class="text-2xl sm:text-3xl font-bold text-gray-900"><?php echo $totalCount; ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-orange-500 p-3 sm:p-4">
        <p class="text-xs sm:text-sm text-gray-500 mb-0.5 sm:mb-1">
            <i class="fas fa-user-tie text-orange-500 mr-1"></i>As Faculty Guide
        </p>
        <p class="text-2xl sm:text-3xl font-bold text-orange-600"><?php echo $guideCount; ?></p>
    </div>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 border-l-4 border-l-blue-500 p-3 sm:p-4">
        <p class="text-xs sm:text-sm text-gray-500 mb-0.5 sm:mb-1">
            <i class="fas fa-chalkboard-teacher text-blue-500 mr-1"></i>As TA Coordinator
        </p>
        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?php echo $taCount; ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Filter Pills (client-side, defaults to All) -->
<?php if ($totalCount > 0): ?>
<div class="flex flex-wrap items-center gap-2 mb-4 sm:mb-6">
    <span class="text-xs text-gray-500 mr-1 hidden sm:inline">Filter:</span>
    <button type="button"
            data-filter="all"
            class="filter-pill active inline-flex items-center gap-3 px-4 py-1.5 rounded-full text-sm font-medium border bg-gray-900 text-white border-gray-900">
        <span>Show All</span>
        <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-white bg-opacity-20"><?php echo $totalCount; ?></span>
    </button>
    <button type="button"
            data-filter="guide"
            class="filter-pill inline-flex items-center gap-3 px-4 py-1.5 rounded-full text-sm font-medium border bg-white text-gray-700 border-gray-300 hover:bg-orange-50 hover:border-orange-300 transition-colors">
        <i class="fas fa-user-tie text-orange-500"></i>
        <span>Faculty Guide</span>
        <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-orange-100 text-orange-800"><?php echo $guideCount; ?></span>
    </button>
    <button type="button"
            data-filter="ta"
            class="filter-pill inline-flex items-center gap-3 px-4 py-1.5 rounded-full text-sm font-medium border bg-white text-gray-700 border-gray-300 hover:bg-blue-50 hover:border-blue-300 transition-colors">
        <i class="fas fa-chalkboard-teacher text-blue-500"></i>
        <span>TA Coordinator</span>
        <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800"><?php echo $taCount; ?></span>
    </button>
</div>
<?php endif; ?>

<!-- Applications List -->
<?php if (empty($pendingAll)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-12 text-center">
        <i class="fas fa-check-circle text-5xl sm:text-6xl text-green-500 mb-3 sm:mb-4"></i>
        <h3 class="text-lg sm:text-xl font-semibold text-gray-900 mb-2">All Caught Up!</h3>
        <p class="text-gray-600 text-sm sm:text-base">No pending applications in either of your roles.</p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 gap-4 sm:gap-6" id="pendingList">
        <?php foreach ($pendingAll as $leave): ?>
            <?php
                $isGuide     = $leave['_role'] === 'guide';
                $borderClass = $isGuide ? 'border-l-orange-500' : 'border-l-blue-500';
                $bannerBg    = $isGuide ? 'bg-orange-50' : 'bg-blue-50';
                $roleBadge   = $isGuide
                    ? '<span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-orange-100 text-orange-800"><i class="fas fa-user-tie mr-1.5"></i>Faculty Guide</span>'
                    : '<span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-blue-100 text-blue-800"><i class="fas fa-chalkboard-teacher mr-1.5"></i>TA Coordinator</span>';
                $roleLong    = $isGuide ? 'Faculty Guide' : 'TA Coordinator';
            ?>
            <div class="pending-card bg-white rounded-lg shadow-md overflow-hidden border-l-4 <?php echo $borderClass; ?>"
                 data-role="<?php echo $leave['_role']; ?>">
                <!-- Role banner — makes the active role unmissable -->
                <div class="<?php echo $bannerBg; ?> px-3 sm:px-6 py-2 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <?php echo $roleBadge; ?>
                        <span class="text-xs text-gray-700 hidden sm:inline truncate">
                            Action needed from you as <strong><?php echo $roleLong; ?></strong>
                        </span>
                    </div>
                    <span class="text-xs text-gray-500 flex-shrink-0">
                        <i class="far fa-clock mr-1"></i><?php echo timeAgo($leave['created_at']); ?>
                    </span>
                </div>

                <div class="p-3 sm:p-4 md:p-6">
                    <!-- Student header -->
                    <div class="flex items-start justify-between mb-3 sm:mb-4 gap-2">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-1">
                                <?php echo sanitize($leave['student_name']); ?>
                                <span class="block sm:inline text-sm font-normal text-gray-500 sm:ml-2">
                                    (<?php echo sanitize($leave['roll_number']); ?>)
                                </span>
                            </h3>
                            <p class="text-xs sm:text-sm text-gray-600 truncate">
                                <?php echo sanitize($leave['student_email']); ?>
                            </p>
                        </div>
                        <div class="flex-shrink-0">
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                        </div>
                    </div>

                    <!-- Leave details grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4 mb-3 sm:mb-4">
                        <div class="col-span-2 sm:col-span-1">
                            <p class="text-xs text-gray-500">Duration</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base">
                                <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Total Days</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base">
                                <?php echo $leave['total_days']; ?> days
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Applied On</p>
                            <p class="font-semibold text-gray-900 text-sm sm:text-base">
                                <?php echo formatDate($leave['created_at']); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Reason -->
                    <div class="mb-3 sm:mb-4">
                        <p class="text-xs text-gray-500 mb-1">Reason</p>
                        <p class="text-sm text-gray-700 bg-gray-50 p-2 sm:p-3 rounded whitespace-pre-line">
                            <?php echo sanitize($leave['reason']); ?>
                        </p>
                    </div>

                    <!-- Medical Certificate -->
                    <?php if ($leave['leave_type'] === 'medical' && !empty($leave['supporting_document'])): ?>
                        <div class="mb-3 sm:mb-4 p-2 sm:p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <div class="flex items-center">
                                    <i class="fas fa-file-medical text-blue-600 text-lg mr-2"></i>
                                    <span class="text-xs sm:text-sm font-medium text-blue-800">Medical Certificate Attached</span>
                                </div>
                                <button type="button"
                                        onclick="openPdfViewer('<?php echo BASE_URL; ?>/public/<?php echo sanitize($leave['supporting_document']); ?>')"
                                        class="px-3 py-1.5 bg-blue-600 text-white text-xs sm:text-sm rounded-lg hover:bg-blue-700 transition-colors w-full sm:w-auto text-center">
                                    <i class="fas fa-eye mr-1"></i>View Document
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-2 sm:gap-3 pt-3 border-t border-gray-100">
                        <button type="button"
                                onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'reject', '<?php echo $leave['_role']; ?>')"
                                class="flex-1 sm:flex-none px-3 sm:px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors text-sm font-medium">
                            <i class="fas fa-times mr-1 sm:mr-2"></i>Reject
                        </button>
                        <button type="button"
                                onclick="showApprovalModal(<?php echo $leave['id']; ?>, 'approve', '<?php echo $leave['_role']; ?>')"
                                class="flex-1 sm:flex-none px-3 sm:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                            <i class="fas fa-check mr-1 sm:mr-2"></i>Approve as <?php echo $isGuide ? 'Guide' : 'TA Coord.'; ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Empty filter state (shown when current filter matches nothing) -->
    <div id="emptyFilterState" class="hidden bg-white rounded-lg shadow-md p-6 sm:p-8 text-center">
        <i class="fas fa-filter text-3xl sm:text-4xl text-gray-300 mb-3"></i>
        <h3 class="text-base sm:text-lg font-semibold text-gray-700 mb-1">Nothing in this filter</h3>
        <p class="text-gray-500 text-xs sm:text-sm">Try the <strong>Show All</strong> filter to see all pending items.</p>
    </div>
<?php endif; ?>

<!-- Approval History -->
<div class="pt-8 sm:pt-14 mb-4 sm:mb-6">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">Approval History</h2>
    <p class="text-gray-600 text-xs sm:text-sm">Your past decisions across both roles</p>
</div>

<?php if (empty($historyAll)): ?>
    <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 text-center">
        <i class="fas fa-history text-3xl sm:text-4xl text-gray-300 mb-3"></i>
        <h3 class="text-base sm:text-lg font-semibold text-gray-700 mb-1">No History Yet</h3>
        <p class="text-gray-500 text-xs sm:text-sm">You haven't approved or rejected any applications yet.</p>
    </div>
<?php else: ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <?php
        // Pre-compute display data for each history row
        $historyData = [];
        foreach ($historyAll as $leave) {
            $isGuide = $leave['_role'] === 'guide';
            if ($isGuide) {
                $decision = $leave['guide_status'];
                $remarks  = $leave['guide_remarks'];
                $actedOn  = $leave['guide_approved_at'];
            } else {
                $decision = $leave['ta_status'];
                $remarks  = $leave['ta_remarks'];
                $actedOn  = $leave['ta_approved_at'];
            }
            $decisionBadge = $decision === 'approved'
                ? '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i class="fas fa-check mr-1"></i>Approved</span>'
                : '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><i class="fas fa-times mr-1"></i>Rejected</span>';
            $roleChip = $isGuide
                ? '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800"><i class="fas fa-user-tie mr-1"></i>Guide</span>'
                : '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"><i class="fas fa-chalkboard-teacher mr-1"></i>TA Coord.</span>';
            $historyData[] = [
                'leave'         => $leave,
                'decision'      => $decision,
                'remarks'       => $remarks,
                'actedOn'       => $actedOn,
                'decisionBadge' => $decisionBadge,
                'roleChip'      => $roleChip,
                'role'          => $leave['_role'],
            ];
        }
        ?>

        <!-- Mobile Card View (< md) -->
        <div class="block md:hidden divide-y divide-gray-200">
            <?php foreach ($historyData as $item):
                $leave = $item['leave'];
            ?>
                <div class="p-3 sm:p-4">
                    <div class="flex items-start justify-between mb-2 gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 text-sm sm:text-base truncate">
                                <?php echo sanitize($leave['student_name']); ?>
                            </p>
                            <p class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></p>
                        </div>
                        <div class="flex flex-col items-end gap-1 flex-shrink-0">
                            <?php echo $item['decisionBadge']; ?>
                            <?php echo $item['roleChip']; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:text-sm mt-2">
                        <div>
                            <span class="text-gray-500">Type:</span>
                            <?php echo getLeaveTypeBadge($leave['leave_type']); ?>
                        </div>
                        <div>
                            <span class="text-gray-500">Days:</span>
                            <span class="font-semibold text-gray-900"><?php echo $leave['total_days']; ?></span>
                        </div>
                        <div class="col-span-2 mt-1">
                            <span class="text-gray-500">Duration:</span>
                            <span class="text-gray-900">
                                <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                            </span>
                        </div>
                        <div class="col-span-2 mt-1">
                            <span class="text-gray-500">Overall:</span>
                            <?php echo getStatusBadge($leave['status']); ?>
                        </div>
                        <?php if (!empty($item['remarks'])): ?>
                            <div class="col-span-2 mt-1">
                                <span class="text-gray-500">Remarks:</span>
                                <span class="text-gray-700"><?php echo sanitize($item['remarks']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 pt-2 border-t border-gray-100">
                        <span class="text-xs text-gray-400">
                            <?php echo $item['actedOn'] ? formatDate($item['actedOn']) : '-'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Desktop Table View (>= md) -->
        <div class="hidden md:block overflow-x-auto">
            <table id="historyTable" class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Duration</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Days</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Decision</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden xl:table-cell">Remarks</th>
                        <th class="px-3 lg:px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden lg:table-cell">Acted On</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($historyData as $item):
                        $leave = $item['leave'];
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 lg:px-4 py-3">
                                <div class="font-medium text-gray-900"><?php echo sanitize($leave['student_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo sanitize($leave['roll_number']); ?></div>
                            </td>
                            <td class="px-3 lg:px-4 py-3"><?php echo $item['roleChip']; ?></td>
                            <td class="px-3 lg:px-4 py-3"><?php echo getLeaveTypeBadge($leave['leave_type']); ?></td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700 hidden lg:table-cell">
                                <?php echo formatDate($leave['start_date']); ?> &ndash; <?php echo formatDate($leave['end_date']); ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-700"><?php echo $leave['total_days']; ?></td>
                            <td class="px-3 lg:px-4 py-3"><?php echo $item['decisionBadge']; ?></td>
                            <td class="px-3 lg:px-4 py-3"><?php echo getStatusBadge($leave['status']); ?></td>
                            <td class="px-3 lg:px-4 py-3 text-gray-600 max-w-xs hidden xl:table-cell">
                                <?php if (!empty($item['remarks'])): ?>
                                    <span class="truncate block max-w-[200px]" title="<?php echo sanitize($item['remarks']); ?>">
                                        <?php echo sanitize($item['remarks']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Not Required</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 lg:px-4 py-3 text-gray-500 text-xs whitespace-nowrap hidden lg:table-cell">
                                <?php echo $item['actedOn'] ? formatDate($item['actedOn']) : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Approval Modal -->
<div id="approvalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end sm:items-center justify-center sm:p-4">
    <div class="bg-white rounded-t-lg sm:rounded-lg shadow-xl w-full sm:max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 flex justify-between items-center sticky top-0 bg-white z-10">
            <h3 class="text-lg sm:text-xl font-semibold text-gray-900" id="modalTitle">Approve Leave</h3>
            <button type="button" onclick="closeApprovalModal()" class="text-gray-400 hover:text-gray-600 p-1">
                <i class="fas fa-times text-lg sm:text-xl"></i>
            </button>
        </div>
        <form id="approvalForm" class="p-4 sm:p-6">
            <input type="hidden" id="leaveId" name="leave_id">
            <input type="hidden" id="actionType" name="action">
            <input type="hidden" id="roleType" name="role">
            <?php echo getCSRFField(); ?>

            <!-- Acting-as banner inside modal so the role is clear at decision time -->
            <div id="actingAsBanner" class="mb-4 px-3 py-2 rounded-lg text-sm flex items-center"></div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">
                    Remarks <span class="text-red-500">*</span>
                </label>
                <textarea
                    id="remarks"
                    name="remarks"
                    rows="3"
                    class="w-full px-3 sm:px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm sm:text-base"
                    placeholder="Please provide your remarks..."
                    required
                ></textarea>
            </div>

            <div id="taDutySection" class="hidden mb-4">
                <label class="flex items-start sm:items-center">
                    <input type="checkbox" id="noDutyConfirm" name="no_duty_confirmed" value="1"
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mt-0.5 sm:mt-0">
                    <span class="ml-2 text-xs sm:text-sm text-gray-700">
                        I confirm that the student has no TA duties during this period
                    </span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-2 sm:gap-3">
                <button type="button" onclick="closeApprovalModal()"
                        class="px-3 sm:px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm">
                    Cancel
                </button>
                <button type="submit" id="submitBtn"
                        class="px-3 sm:px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                    Submit
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ---------- Filter pills ----------
(function () {
    const pills    = document.querySelectorAll('.filter-pill');
    const cards    = document.querySelectorAll('.pending-card');
    const emptyMsg = document.getElementById('emptyFilterState');

    function setActive(btn) {
        pills.forEach(p => {
            p.classList.remove('active', 'bg-gray-900', 'text-white', 'border-gray-900');
            p.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
        });
        btn.classList.add('active', 'bg-gray-900', 'text-white', 'border-gray-900');
        btn.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
    }

    function applyFilter(filter) {
        let visible = 0;
        cards.forEach(card => {
            const show = (filter === 'all') || (card.dataset.role === filter);
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (emptyMsg) {
            emptyMsg.classList.toggle('hidden', visible !== 0);
        }
    }

    pills.forEach(btn => {
        btn.addEventListener('click', () => {
            setActive(btn);
            applyFilter(btn.dataset.filter);
        });
    });
})();

// ---------- Approval Modal ----------
function showApprovalModal(leaveId, action, role) {
    document.getElementById('leaveId').value   = leaveId;
    document.getElementById('actionType').value = action;
    document.getElementById('roleType').value   = role;
    document.getElementById('remarks').value    = '';
    document.getElementById('noDutyConfirm').checked = false;

    const modal     = document.getElementById('approvalModal');
    const title     = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const taSect    = document.getElementById('taDutySection');
    const banner    = document.getElementById('actingAsBanner');

    const roleLong = role === 'guide' ? 'Faculty Guide' : 'TA Coordinator';
    const roleIcon = role === 'guide' ? 'fa-user-tie' : 'fa-chalkboard-teacher';
    const bannerCls = role === 'guide'
        ? 'bg-orange-50 text-orange-800 border border-orange-200'
        : 'bg-blue-50 text-blue-800 border border-blue-200';

    banner.className = 'mb-4 px-3 py-2 rounded-lg text-sm flex items-center ' + bannerCls;
    banner.innerHTML = '<i class="fas ' + roleIcon + ' mr-2"></i>You are acting as <strong class="mx-1">' + roleLong + '</strong>';

    if (action === 'approve') {
        title.textContent = 'Approve Leave (' + roleLong + ')';
        submitBtn.className = 'px-3 sm:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm';
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Approve';
        taSect.classList.toggle('hidden', role !== 'ta');
    } else {
        title.textContent = 'Reject Leave (' + roleLong + ')';
        submitBtn.className = 'px-3 sm:px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm';
        submitBtn.innerHTML = '<i class="fas fa-times mr-2"></i>Reject';
        taSect.classList.add('hidden');
    }

    modal.classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}

document.getElementById('approvalForm').addEventListener('submit', async function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    const action   = formData.get('action');
    const role     = formData.get('role');

    if (action === 'approve' && role === 'ta') {
        if (!document.getElementById('noDutyConfirm').checked) {
            alert('Please confirm that the student has no TA duties during this period');
            return;
        }
    }

    const submitBtn = document.getElementById('submitBtn');
    const original  = submitBtn.innerHTML;
    submitBtn.disabled  = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';

    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/approvals/process.php', {
            method: 'POST',
            body:   formData
        });
        const result = await response.json();

        if (result.success) {
            window.location.href = '<?php echo BASE_URL; ?>/faculty/pending_approvals.php?success=' + encodeURIComponent(result.message);
        } else {
            alert('Error: ' + result.message);
            submitBtn.disabled  = false;
            submitBtn.innerHTML = original;
        }
    } catch (err) {
        alert('An error occurred. Please try again.');
        submitBtn.disabled  = false;
        submitBtn.innerHTML = original;
    }
});

// Close modal on outside click
document.getElementById('approvalModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeApprovalModal();
});

// Show success message from URL parameter
<?php if (isset($_GET['success'])): ?>
    setTimeout(() => { alert('<?php echo sanitize($_GET['success']); ?>'); }, 100);
<?php endif; ?>

// ---------- PDF Viewer ----------
function openPdfViewer(pdfUrl) {
    document.getElementById('pdfFrame').src = pdfUrl;
    document.getElementById('pdfModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closePdfViewer() {
    document.getElementById('pdfModal').classList.add('hidden');
    document.getElementById('pdfFrame').src = '';
    document.body.style.overflow = 'auto';
}
document.getElementById('pdfModal')?.addEventListener('click', function (e) {
    if (e.target === this) closePdfViewer();
});

// ---------- History DataTable ----------
$(document).ready(function () {
    if ($('#historyTable').length && $('#historyTable').is(':visible')) {
        $('#historyTable').DataTable({
            order: [[8, 'desc']],
            pageLength: 10,
            language: {
                emptyTable: "No history found",
                search: "Search:"
            }
        });
    }
});
</script>

<!-- PDF Viewer Modal (Full Screen) -->
<div id="pdfModal" class="hidden fixed inset-0 bg-gray-900 z-50 flex flex-col">
    <div class="px-4 py-3 bg-gray-800 flex justify-between items-center flex-shrink-0">
        <h3 class="text-lg font-semibold text-white">
            <i class="fas fa-file-medical text-blue-400 mr-2"></i>Medical Certificate
        </h3>
        <button type="button" onclick="closePdfViewer()" class="text-gray-300 hover:text-white p-2 hover:bg-gray-700 rounded-full transition-colors">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    <div class="flex-1 min-h-0">
        <iframe id="pdfFrame" src="" class="w-full h-full border-0"></iframe>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>