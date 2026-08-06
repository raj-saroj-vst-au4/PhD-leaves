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
requireRole(['admin', 'hod']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Code - <?php echo SITE_NAME; ?></title>
    <style>
        body { margin:0; background:#0b1220; color:#fff; font-family:system-ui,sans-serif;
               min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        #code { font-size:18vw; font-weight:800; letter-spacing:0.08em; font-variant-numeric:tabular-nums; }
        #bar-wrap { width:60vw; height:10px; background:#1e293b; border-radius:5px; margin-top:2rem; overflow:hidden; }
        #bar { height:100%; background:#38bdf8; width:100%; transition:width 1s linear; }
        #label { color:#94a3b8; margin-top:1.5rem; font-size:1.2rem; }
        #off { font-size:2rem; color:#94a3b8; display:none; text-align:center; padding:2rem; }
    </style>
</head>
<body data-api-base="<?php echo ATT_API_BASE; ?>"
      data-ticket="<?php echo sanitize(att_ticket()); ?>"
      data-ticket-url="<?php echo BASE_URL; ?>/api/attendance_ticket.php">
    <div id="off">Strict mode is currently <b>off</b>.<br>Enable it in Attendance &rarr; Configuration.</div>
    <div id="on">
        <div id="code">······</div>
        <div id="bar-wrap"><div id="bar"></div></div>
        <div id="label">Enter this code in the attendance app &middot; changes every 30 seconds</div>
    </div>
<script>
(function () {
  const API = document.body.dataset.apiBase;
  let ticket = document.body.dataset.ticket;
  const ticketUrl = document.body.dataset.ticketUrl;

  async function refreshTicket() {
    try { const r = await fetch(ticketUrl, {credentials:'same-origin'});
          const d = await r.json(); if (d.ok) ticket = d.ticket; } catch (e) {}
  }
  setInterval(refreshTicket, 8 * 60 * 1000);

  async function poll() {
    try {
      const r = await fetch(API + encodeURIComponent('/admin/strict-code'), {
        credentials: 'same-origin',
        headers: { Authorization: 'Bearer ' + ticket },
      });
      const d = await r.json();
      const on = document.getElementById('on'), off = document.getElementById('off');
      if (!d.enabled) { on.style.display = 'none'; off.style.display = 'block'; return; }
      on.style.display = 'block'; off.style.display = 'none';
      document.getElementById('code').textContent = d.code;
      document.getElementById('bar').style.width = (d.seconds_left / 30 * 100) + '%';
    } catch (e) { /* transient */ }
  }
  poll();
  setInterval(poll, 3000);
})();
</script>
</body>
</html>
