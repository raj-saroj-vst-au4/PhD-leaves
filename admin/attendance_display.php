<?php
/**
 * Full-screen rotating code for the office screen (strict mode).
 * When strict mode is off this page says so and shows nothing else.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/attendance.php';
require_once __DIR__ . '/../includes/att_display.php';
requireRole(['admin', 'hod']);

// The screen runs on its own display token, good only for reading the code
// (see includes/att_display.php). Its polls never touch this browser's portal
// session, which therefore still times out after an hour without real use, so
// the office TV does not sit on a live admin session all day.
$displayToken = attDisplayTokenMint((int) getCurrentUserId(), (string) ($_SESSION['pw_fp'] ?? ''));
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Code - <?php echo SITE_NAME; ?></title>
    <?php /* Same design system as the portal (Inter, brand palette, icons); the
             TV-specific sizing and backdrop live in attendance.css (.att-display*). */ ?>
    <link rel="stylesheet" href="<?php echo asset_url('public/vendor/tailwind/tailwind.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('public/css/attendance.css'); ?>">
</head>
<body class="att-display min-h-screen overflow-hidden bg-gray-950 text-white"
      data-api-base="<?php echo ATT_API_BASE; ?>"
      data-display-token="<?php echo sanitize($displayToken); ?>">
    <div class="flex min-h-screen flex-col px-[4vw] py-[4vh]">
        <header class="flex items-center justify-between gap-6">
            <div class="flex items-center gap-3 lg:gap-[1vw]">
                <span class="inline-flex rounded-2xl bg-white px-[1vw] py-[0.6vw] shadow-lg shadow-black/40">
                    <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay" width="463" height="200" class="h-[6vh] min-h-[2.5rem] w-auto">
                </span>
                <div class="leading-tight">
                    <p class="att-display-brand font-semibold tracking-tight">PhD Time-Off</p>
                    <p class="att-display-sub text-gray-400">SJM School of Management</p>
                </div>
            </div>
            <div class="att-display-sub inline-flex items-center gap-3 rounded-full bg-white/5 px-[1.2vw] py-[0.6vw] font-medium text-gray-300 ring-1 ring-inset ring-white/10">
                <span class="relative flex h-3 w-3">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-400"></span>
                </span>
                Live
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center">
            <?php /* #off / #on are shown and hidden by the poller below via style.display. */ ?>
            <div id="off" class="hidden text-center">
                <span class="mx-auto mb-[3vh] flex h-[7vw] w-[7vw] items-center justify-center rounded-3xl bg-white/5 text-[3vw] text-gray-400 ring-1 ring-inset ring-white/10">
                    <i class="fas fa-lock-open"></i>
                </span>
                <p class="att-display-off leading-snug text-gray-300">Strict mode is currently <b class="font-semibold text-white">off</b>.<br>Enable it in Attendance &rarr; Configuration.</p>
            </div>
            <div id="on" class="w-full text-center">
                <p class="att-display-eyebrow font-semibold uppercase text-blue-300">Attendance code</p>
                <div id="code" class="att-code font-extrabold text-white">······</div>
                <div id="bar-wrap" class="mx-auto mt-[4vh] h-[1.4vh] min-h-[8px] w-[60vw] overflow-hidden rounded-full bg-white/10">
                    <div id="bar" class="h-full rounded-full bg-gradient-to-r from-sky-400 to-blue-500 transition-[width] duration-1000 ease-linear" style="width:100%"></div>
                </div>
                <div id="label" class="att-display-sub mt-[3vh] text-gray-400">Enter this code in the attendance app &middot; changes every 30 seconds</div>
            </div>
        </main>

        <footer class="att-display-sub flex items-center justify-center gap-3 text-gray-500">
            <i class="fas fa-mobile-alt"></i>Portal &rarr; Attendance &rarr; Mark Attendance, then type the code when asked
        </footer>
    </div>
<script>
(function () {
  const API = document.body.dataset.apiBase;
  const displayToken = document.body.dataset.displayToken;
  let timer = null;

  async function poll() {
    try {
      // The display token, not the portal session, authorises this read. (The
      // cookie still goes along so each poll reuses one session file on the
      // server instead of opening a new one every 3 s.)
      const r = await fetch(API + encodeURIComponent('/admin/strict-code'), {
        credentials: 'same-origin',
        headers: { 'X-Display-Token': displayToken },
      });
      const d = await r.json();
      const on = document.getElementById('on'), off = document.getElementById('off');
      if (r.status === 401 || r.status === 403) {
        // Token expired (after a working day) or the account changed: stop and
        // say so, rather than showing "strict mode is off".
        clearInterval(timer);
        off.querySelector('p').textContent = 'This screen has expired. Reload the page and sign in to start it again.';
        on.style.display = 'none'; off.style.display = 'block';
        return;
      }
      if (!d.enabled) { on.style.display = 'none'; off.style.display = 'block'; return; }
      on.style.display = 'block'; off.style.display = 'none';
      document.getElementById('code').textContent = d.code;
      document.getElementById('bar').style.width = (d.seconds_left / 30 * 100) + '%';
    } catch (e) { /* transient */ }
  }
  poll();
  timer = setInterval(poll, 3000);
})();
</script>
</body>
</html>
