<?php
/**
 * Student attendance page: today's status, the Mark Attendance capture flow,
 * enrollment (with an office-issued code), and personal history.
 *
 * All decisions happen in the Python backend; this page only renders state
 * from /attendance/api and drives the camera.
 */
/*
 * Re-allow the camera for THIS page, before any output.
 *
 * The upstream proxy (www.som.iitb.ac.in) adds a site-wide
 *     Permissions-Policy: geolocation=(), microphone=(), camera=()
 * and `camera=()` means "no origin at all", which disables the camera for the
 * whole document. getUserMedia then fails with NotAllowedError even after the
 * student grants permission, no prompt is ever shown, and the browser offers no
 * Camera entry in its site settings — because the camera is not merely blocked,
 * it is disallowed. Chrome applies the LAST matching header, so re-declaring it
 * here restores the camera without touching the proxy (which needs root).
 * Verified on the live site: policy allowsFeature('camera') false -> true.
 *
 * Scoped to `self` and to this page only — the sole place the camera is used.
 * Microphone and geolocation stay denied; attendance never needs them.
 */
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

$pageTitle = 'Attendance';

// BASE_URL comes from constants.php, which header.php also loads; require it
// first so $pageStyles can be built before the header renders <head>.
require_once __DIR__ . '/../config/constants.php';
// functions.php too, because asset_url() is needed for $pageStyles below —
// header.php would otherwise be the first thing to load it, too late for this.
require_once __DIR__ . '/../includes/functions.php';
// The vendored tailwind.min.css is a purged build and lacks several utilities
// this page uses (the whole amber palette, the overlay transforms). See
// public/css/attendance.css.
$pageStyles = '<link rel="stylesheet" href="' . asset_url('public/css/attendance.css') . '">';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../config/attendance.php';
requireRole('student');

// Enrollment codes are six digits (see attendance/backend/app/main.py).
$enrollCode = isset($_GET['enroll'])
    ? substr(preg_replace('/\D/', '', (string) $_GET['enroll']), 0, 6)
    : '';
?>

<!-- header.php already opens <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">,
     so this wrapper only narrows the column. Repeating the padding utilities here
     would double the page padding. -->
<div class="max-w-3xl mx-auto"
     id="att-root"
     data-api-base="<?php echo ATT_API_BASE; ?>"
     data-ticket="<?php echo sanitize(att_ticket()); ?>"
     data-ticket-url="<?php echo BASE_URL; ?>/api/attendance_ticket.php"
     data-base="<?php echo BASE_URL; ?>"
     data-secure-base="<?php echo SITE_DOMAIN . BASE_URL; ?>"
     data-enroll-code="<?php echo sanitize($enrollCode); ?>">

    <div class="flex items-center justify-between mb-8">
        <h1 class="text-2xl font-bold text-gray-900">
            <i class="fas fa-user-check text-blue-600 mr-2"></i>Attendance
        </h1>
        <span id="att-clock" class="text-sm text-gray-500 font-mono"></span>
    </div>

    <!-- Status card -->
    <div id="att-status" class="bg-white rounded-lg shadow p-6 mb-8">
        <div class="text-gray-500 text-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Checking status…</div>
    </div>

    <!-- Mark button -->
    <div class="text-center mb-8">
        <button id="att-mark-btn"
                class="hidden w-full sm:w-auto px-10 py-4 rounded-lg text-lg font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-lg transition-colors">
            <i class="fas fa-camera mr-2"></i>Mark Attendance
        </button>
        <p id="att-mark-hint" class="text-sm text-gray-500 mt-3"></p>
    </div>

    <!-- Capture overlay -->
    <div id="att-capture" class="hidden fixed inset-0 bg-black bg-opacity-95 z-50 flex flex-col items-center justify-center p-4">
        <div class="w-full max-w-sm text-center">
            <p id="att-capture-step" class="text-white text-xl font-semibold mb-4">Starting camera…</p>
            <div class="relative mx-auto" style="width:280px;height:373px;">
                <video id="att-video" autoplay playsinline muted
                       class="w-full h-full object-cover rounded-2xl border-4 border-white"></video>
                <div class="absolute inset-0 rounded-2xl pointer-events-none"
                     style="box-shadow: inset 0 0 0 9999px rgba(0,0,0,0.15); border-radius:1rem;"></div>
                <div id="att-countdown"
                     class="absolute bottom-3 left-1/2 -translate-x-1/2 text-white text-3xl font-bold drop-shadow"></div>
            </div>
            <div id="att-strict-box" class="hidden mt-4">
                <label class="block text-white text-sm mb-1">Code on the office screen</label>
                <input id="att-strict-code" inputmode="numeric" maxlength="6"
                       class="w-40 text-center text-2xl tracking-widest rounded-lg py-2" placeholder="······">
            </div>
            <p id="att-capture-msg" class="text-gray-300 text-sm mt-4"></p>
            <button id="att-capture-cancel" class="mt-4 px-6 py-2 rounded-lg text-white bg-gray-700 hover:bg-gray-600">
                Cancel
            </button>
        </div>
    </div>

    <!-- Enrollment card (shown when not enrolled) -->
    <div id="att-enroll-card" class="hidden bg-amber-50 border border-amber-200 rounded-lg p-6 mb-8">
        <h2 class="font-semibold text-amber-900 mb-2"><i class="fas fa-id-badge mr-2"></i>One-time enrollment</h2>
        <p class="text-sm text-amber-800 mb-4">
            Visit the SOM office with this phone <strong>and connect to the office WiFi</strong>.
            A staff member will give you a short one-time code — enter it below and follow the camera
            steps. Enrollment only works on the office WiFi, so no one can enroll your face remotely.
            A live photo is captured every time you mark attendance and kept for 60 days; entering the
            code records your consent.
        </p>
        <div class="att-enroll-row">
            <input id="att-enroll-code" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" enterkeyhint="go"
                   class="border rounded-lg px-3 py-3 font-mono tracking-widest text-center text-2xl"
                   placeholder="000000" value="">
            <button id="att-enroll-btn" type="button"
                    class="px-6 py-3 rounded-lg font-semibold text-white bg-amber-600 hover:bg-amber-700">
                <i class="fas fa-camera mr-2"></i>Start enrollment
            </button>
        </div>
        <p id="att-enroll-msg" class="text-sm text-amber-800 mt-3"></p>
    </div>

    <!-- History -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-semibold text-gray-900"><i class="far fa-calendar-check mr-2"></i>My attendance</h2>
            <span id="att-history-summary" class="text-sm text-gray-500"></span>
        </div>
        <div id="att-history" class="divide-y max-h-96 overflow-y-auto">
            <div class="px-6 py-4 text-sm text-gray-500">Loading…</div>
        </div>
    </div>
</div>

<?php /* webrtc-adapter (the WebRTC project's own shim) normalises getUserMedia and
         constraint handling across browsers — it no-ops on modern ones and
         smooths over older Android WebViews and Safari quirks. Loading it is
         enough; the shims apply as a side effect. It cannot affect a permission
         the user has already denied — no script can re-open that prompt. */ ?>
<script src="<?php echo asset_url('public/vendor/webrtc-adapter/adapter.js'); ?>"></script>
<?php /* faceguide.js only defines window.FaceGuide; the ~1.3 MB tracker library and
         its models are fetched lazily on the first capture, so they never touch
         this page's load. */ ?>
<script src="<?php echo asset_url('public/js/faceguide.js'); ?>"></script>
<script src="<?php echo asset_url('public/js/attendance.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
