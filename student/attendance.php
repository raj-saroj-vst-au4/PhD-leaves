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
// Styles Tailwind utilities cannot express: the mirrored camera preview, the
// capture frame and pose-guide canvas, and hide-when-empty rules. See
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

<?php /* header.php already opens <main class="max-w-7xl mx-auto px-4 …">; this
         wrapper only narrows the column to a single phone-first flow. The page
         header sits OUTSIDE #att-root on purpose: attendance.js inserts its camera
         notices at root.firstChild, so they land just under the title. */ ?>
<div class="max-w-3xl mx-auto">

    <div class="page-header">
        <div>
            <p class="page-eyebrow">Student</p>
            <h1 class="page-title">Attendance</h1>
            <p class="page-subtitle">Mark today's attendance with a quick live face check.</p>
        </div>
        <?php /* #att-clock is filled by attendance.js; the chip hides itself while it
                 is empty (attendance.css), e.g. before the first status load. */ ?>
        <span class="att-clock-chip inline-flex items-center gap-2 self-start rounded-full bg-white px-3 py-1.5 text-sm font-medium text-gray-600 shadow-sm ring-1 ring-inset ring-gray-200 sm:self-auto">
            <i class="far fa-clock text-gray-400"></i><span id="att-clock" class="tabular-nums"></span>
        </span>
    </div>

<?php /* Children are spaced by the flex gap, not margins: gap skips display:none
         children, so hidden cards and JS notices never leave a double gap. */ ?>
<div class="flex flex-col gap-5"
     id="att-root"
     data-api-base="<?php echo ATT_API_BASE; ?>"
     data-ticket="<?php echo sanitize(att_ticket()); ?>"
     data-ticket-url="<?php echo BASE_URL; ?>/api/attendance_ticket.php"
     data-base="<?php echo BASE_URL; ?>"
     data-secure-base="<?php echo SITE_DOMAIN . BASE_URL; ?>"
     data-enroll-code="<?php echo sanitize($enrollCode); ?>">

    <!-- Today: status + the one primary action -->
    <section class="card">
        <div id="att-status" class="p-5 sm:p-6">
            <div class="flex items-center gap-3 text-sm text-gray-500">
                <i class="fas fa-circle-notch fa-spin text-blue-500"></i>Checking status…
            </div>
        </div>
        <?php /* .att-cta collapses when the button is hidden AND the hint is empty
                 (attendance.css), so a marked day ends the card at the status. */ ?>
        <div class="att-cta flex flex-col gap-3 px-5 pb-5 sm:px-6 sm:pb-6">
            <button id="att-mark-btn"
                    class="hidden btn btn-lg btn-primary w-full rounded-xl py-4 text-lg bg-gradient-to-br from-blue-600 to-violet-600 shadow-lg shadow-blue-600/25 hover:from-blue-700 hover:to-violet-700">
                <i class="fas fa-camera"></i>Mark Attendance
            </button>
            <p id="att-mark-hint" class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 ring-1 ring-inset ring-gray-100 empty:hidden"></p>
        </div>
    </section>

    <!-- Capture overlay -->
    <div id="att-capture" class="hidden fixed inset-0 z-50 flex flex-col items-center justify-center overflow-y-auto bg-gray-950/95 p-4 backdrop-blur-sm">
        <div class="w-full max-w-sm text-center animate-fade-in">
            <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-blue-200 ring-1 ring-inset ring-white/10">
                <i class="fas fa-shield-alt"></i><span id="att-capture-progress">Live face check</span>
            </p>
            <p id="att-capture-step" class="mt-3 flex min-h-[3.5rem] items-center justify-center text-xl font-semibold leading-snug text-white sm:text-2xl">Starting camera…</p>
            <?php /* .att-frame sets the 3:4 size; faceguide.js appends its pose-guide
                     canvas (.att-ring) inside this wrapper, over the video. */ ?>
            <div class="att-frame relative mx-auto mt-4 overflow-hidden rounded-[1.75rem] bg-black shadow-2xl ring-1 ring-white/15">
                <video id="att-video" autoplay playsinline muted
                       class="h-full w-full object-cover"></video>
                <div class="att-vignette pointer-events-none absolute inset-0"></div>
                <div id="att-countdown"
                     class="absolute bottom-4 left-1/2 flex h-14 w-14 -translate-x-1/2 items-center justify-center rounded-full bg-black/50 text-3xl font-semibold tabular-nums text-white ring-1 ring-white/20 backdrop-blur empty:hidden"></div>
            </div>
            <div id="att-strict-box" class="hidden mt-5 rounded-2xl bg-white/5 p-4 ring-1 ring-inset ring-white/10">
                <label for="att-strict-code" class="block text-sm font-medium text-gray-300">Code on the office screen</label>
                <input id="att-strict-code" inputmode="numeric" maxlength="6"
                       class="mx-auto mt-2 block w-48 rounded-xl border-0 bg-white py-2.5 text-center font-mono text-2xl font-semibold tracking-[0.35em] text-gray-900 focus:outline-none focus:ring-4 focus:ring-blue-400/40" placeholder="······">
            </div>
            <p id="att-capture-msg" class="mt-4 min-h-[1.25rem] text-sm text-gray-300"></p>
            <button id="att-capture-cancel" class="btn mt-5 w-full bg-white/10 text-white ring-1 ring-inset ring-white/20 hover:bg-white/20 sm:w-auto sm:px-8">
                Cancel
            </button>
        </div>
    </div>

    <!-- Enrollment card (shown when not enrolled) -->
    <section id="att-enroll-card" class="hidden card">
        <div class="card-header justify-start">
            <span class="icon-chip chip-amber"><i class="fas fa-id-badge"></i></span>
            <div>
                <h2 class="card-title">One-time enrollment</h2>
                <p class="card-subtitle">Set up your face profile once, at the SOM office.</p>
            </div>
        </div>
        <div class="card-body space-y-4">
            <p class="text-sm leading-relaxed text-gray-600">
                Visit the SOM office with this phone <strong class="font-semibold text-gray-900">and connect to the office WiFi</strong>.
                A staff member will give you a short one-time code — enter it below and follow the camera
                steps. Enrollment only works on the office WiFi, so no one can enroll your face remotely.
            </p>
            <a href="<?php echo BASE_URL; ?>/attendance-guide.php" target="_blank" rel="noopener"
               class="flex items-center gap-3 rounded-xl bg-amber-50 p-3.5 text-sm font-semibold text-amber-900 ring-1 ring-inset ring-amber-200 transition hover:bg-amber-100">
                <span class="icon-chip-sm chip-amber"><i class="fas fa-book-open"></i></span>
                <span class="flex-1">First time? Read the step-by-step guide with screenshots</span>
                <i class="fas fa-arrow-right text-xs"></i>
            </a>
            <div class="flex items-start gap-3 rounded-xl bg-gray-50 p-3.5 text-xs leading-relaxed text-gray-600 ring-1 ring-inset ring-gray-100">
                <i class="fas fa-lock mt-0.5 text-gray-400"></i>
                <p>
                    A live photo is captured every time you mark attendance and kept for 60 days; your face
                    profile is erased if your enrollment is revoked or your portal account is deactivated.
                    Entering the code records your consent.
                </p>
            </div>
            <div>
                <label for="att-enroll-code" class="form-label">Enrollment code</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input id="att-enroll-code" type="text" inputmode="numeric" pattern="[0-9]*"
                           maxlength="6" autocomplete="one-time-code" enterkeyhint="go"
                           class="form-input min-w-0 py-3 text-center font-mono text-2xl tracking-[0.4em] sm:flex-1"
                           placeholder="000000" value="">
                    <button id="att-enroll-btn" type="button"
                            class="btn btn-lg btn-primary w-full shrink-0 sm:w-auto">
                        <i class="fas fa-camera"></i>Start enrollment
                    </button>
                </div>
                <p id="att-enroll-msg" class="mt-3 text-sm font-medium text-amber-800 empty:hidden"></p>
            </div>
        </div>
    </section>

    <!-- History -->
    <section class="card overflow-hidden">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="far fa-calendar-check"></i></span>
                <div>
                    <h2 class="card-title">My attendance</h2>
                    <p class="text-xs text-gray-500">Last 3 months</p>
                </div>
            </div>
            <span id="att-history-summary" class="text-xs font-medium text-gray-500"></span>
        </div>
        <div id="att-history" class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto">
            <div class="px-5 py-4 text-sm text-gray-500 sm:px-6">Loading…</div>
        </div>
    </section>
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
