<?php
/**
 * Office attendance dashboard: live day view, pending face reviews, failed
 * attempts, enrollment codes, overrides, calendar/window config, allowlist,
 * reports and audit — all backed by the Python service.
 */
$pageTitle = 'Attendance — Office';

// public/css/attendance.css holds the tab-strip states (.atta-tab.is-active,
// toggled by attendance-admin.js); everything else is Tailwind utilities.
require_once __DIR__ . '/../config/constants.php';
// asset_url() is used for $pageStyles, before header.php loads functions.php.
require_once __DIR__ . '/../includes/functions.php';
$pageStyles = '<link rel="stylesheet" href="' . asset_url('public/css/attendance.css') . '">';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/attendance.php';
requireRole(['admin', 'hod']);
?>

<?php /* Layout (max-width, padding) comes from <main> in header.php — repeating it
         here would double the page padding. */ ?>
<div id="atta-root" class="space-y-6"
     data-api-base="<?php echo ATT_API_BASE; ?>"
     data-ticket="<?php echo sanitize(att_ticket()); ?>"
     data-ticket-url="<?php echo BASE_URL; ?>/api/attendance_ticket.php">

    <div class="page-header">
        <div>
            <p class="page-eyebrow">Office</p>
            <h1 class="page-title">Attendance</h1>
            <p class="page-subtitle">Face-verified daily attendance — the live day, reviews, configuration and reports.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <label for="atta-date" class="sr-only">Day</label>
            <input type="date" id="atta-date" class="form-input w-auto py-2">
            <a href="<?php echo BASE_URL; ?>/admin/attendance_display.php" target="_blank"
               class="btn btn-secondary py-2">
                <i class="fas fa-tv"></i>Code screen
            </a>
        </div>
    </div>

    <!-- Counters (stat tiles rendered by attendance-admin.js) -->
    <div id="atta-counters" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6 empty:hidden"></div>

    <!-- Tabs -->
    <?php /* Underline tabs. States live in .atta-tab / .atta-tab.is-active
             (attendance.css); the JS only toggles is-active. The strip scrolls
             sideways on a phone instead of wrapping. */ ?>
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="flex min-w-max gap-6 border-b border-gray-200" id="atta-tabs">
            <button data-tab="today"    class="atta-tab is-active"><i class="fas fa-calendar-day"></i>Today</button>
            <button data-tab="review"   class="atta-tab"><i class="fas fa-user-clock"></i>Reviews <span id="atta-review-badge" class="hidden rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"></span></button>
            <button data-tab="attempts" class="atta-tab"><i class="fas fa-exclamation-triangle"></i>Failed attempts</button>
            <button data-tab="config"   class="atta-tab"><i class="fas fa-sliders-h"></i>Configuration</button>
            <button data-tab="reports"  class="atta-tab"><i class="fas fa-file-excel"></i>Reports</button>
            <button data-tab="audit"    class="atta-tab"><i class="fas fa-clipboard-list"></i>Audit</button>
        </div>
    </div>

    <div id="atta-panel-today" class="atta-panel space-y-4"></div>
    <div id="atta-panel-review" class="atta-panel hidden space-y-4"></div>
    <div id="atta-panel-attempts" class="atta-panel hidden space-y-4"></div>
    <div id="atta-panel-config" class="atta-panel hidden space-y-4"></div>
    <div id="atta-panel-reports" class="atta-panel hidden space-y-4"></div>
    <div id="atta-panel-audit" class="atta-panel hidden space-y-4"></div>
</div>

<!-- Frame viewer modal -->
<div id="atta-frames-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-sm">
    <div class="modal-panel">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-4">
            <h3 class="text-base font-semibold text-gray-900" id="atta-frames-title">Evidence frames</h3>
            <button onclick="document.getElementById('atta-frames-modal').classList.add('hidden')"
                    class="btn-icon" title="Close" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div id="atta-frames-body" class="grid max-h-[75vh] grid-cols-3 gap-2 overflow-y-auto p-5"></div>
    </div>
</div>

<script src="<?php echo asset_url('public/js/attendance-admin.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
