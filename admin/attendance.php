<?php
/**
 * Office attendance dashboard: live day view, pending face reviews, failed
 * attempts, enrollment codes, overrides, calendar/window config, allowlist,
 * reports and audit — all backed by the Python service.
 */
$pageTitle = 'Attendance — Office';

// See public/css/attendance.css — the vendored Tailwind build is purged and
// lacks the amber palette and several layout utilities used here.
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
<div id="atta-root"
     data-api-base="<?php echo ATT_API_BASE; ?>"
     data-ticket="<?php echo sanitize(att_ticket()); ?>"
     data-ticket-url="<?php echo BASE_URL; ?>/api/attendance_ticket.php">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-8">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-user-check text-blue-600 mr-2"></i>Attendance — Office
        </h1>
        <?php /* .atta-input / .atta-btn so these match the controls inside the
                 panels below — they used to be 4px taller and a type-step larger. */ ?>
        <div class="flex items-center gap-2">
            <input type="date" id="atta-date" class="atta-input">
            <a href="<?php echo BASE_URL; ?>/admin/attendance_display.php" target="_blank"
               class="atta-btn" style="background:#1f2937">
                <i class="fas fa-tv"></i>Code screen
            </a>
        </div>
    </div>

    <!-- Counters -->
    <div id="atta-counters" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8"></div>

    <!-- Tabs -->
    <div class="border-b mb-8 flex flex-wrap gap-1" id="atta-tabs">
        <?php /* States live in .atta-tab / .atta-tab.is-active (attendance.css); the
                 JS only toggles is-active. Hover utilities here used to outrank the
                 active colour, greying out the tab you had just selected. */ ?>
        <button data-tab="today"    class="atta-tab is-active">Today</button>
        <button data-tab="review"   class="atta-tab">Reviews <span id="atta-review-badge" class="hidden ml-1 px-2 rounded-full bg-amber-500 text-white text-xs"></span></button>
        <button data-tab="attempts" class="atta-tab">Failed attempts</button>
        <button data-tab="config"   class="atta-tab">Configuration</button>
        <button data-tab="reports"  class="atta-tab">Reports</button>
        <button data-tab="audit"    class="atta-tab">Audit</button>
    </div>

    <div id="atta-panel-today" class="atta-panel"></div>
    <div id="atta-panel-review" class="atta-panel hidden"></div>
    <div id="atta-panel-attempts" class="atta-panel hidden"></div>
    <div id="atta-panel-config" class="atta-panel hidden"></div>
    <div id="atta-panel-reports" class="atta-panel hidden"></div>
    <div id="atta-panel-audit" class="atta-panel hidden"></div>
</div>

<!-- Frame viewer modal -->
<div id="atta-frames-modal" class="hidden fixed inset-0 bg-black bg-opacity-80 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg max-w-lg w-full p-4">
        <div class="flex justify-between items-center mb-3">
            <h3 class="font-semibold" id="atta-frames-title">Evidence frames</h3>
            <button onclick="document.getElementById('atta-frames-modal').classList.add('hidden')"
                    class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
        </div>
        <div id="atta-frames-body" class="grid grid-cols-3 gap-2"></div>
    </div>
</div>

<script src="<?php echo asset_url('public/js/attendance-admin.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
