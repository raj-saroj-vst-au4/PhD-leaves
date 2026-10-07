/**
 * Student attendance flow: status card, guided face capture, enrollment,
 * history. Talks to the Python backend through the Apache proxy; the ticket
 * from the PHP session authenticates every call.
 */
(function () {
  'use strict';

  const root = document.getElementById('att-root');
  if (!root) return;

  const API = root.dataset.apiBase;
  // Where the vendored face-tracking assets live (loaded on demand): face-api
  // for the original flow (faceguide.js), MediaPipe for liveness v2
  // (liveness.js — its asset URLs come from the page, each mtime-busted).
  const BASE = root.dataset.base || '';
  const MP_ASSETS = root.dataset.mpAssets || '';
  /** liveness.js's tracker, or false — also when the script itself is missing
   *  (a stale cached page, a blocked file): v2 then uses its timed prompts. */
  async function loadLiveness() {
    return window.Liveness ? Liveness.ensureLoaded(MP_ASSETS) : false;
  }
  let ticket = root.dataset.ticket;
  const ticketUrl = root.dataset.ticketUrl;

  const $ = (id) => document.getElementById(id);
  const statusCard = $('att-status');
  const markBtn = $('att-mark-btn');
  const markHint = $('att-mark-hint');

  // ------------------------------------------------------------------ utils

  async function refreshTicket() {
    try {
      const res = await fetch(ticketUrl, { credentials: 'same-origin' });
      const data = await res.json();
      if (data.ok) ticket = data.ticket;
    } catch (e) { /* keep the old ticket; the API will 401 if it truly died */ }
  }
  setInterval(refreshTicket, 8 * 60 * 1000);

  async function api(path, opts = {}) {
    // The forwarder routes on ?p=<encoded path>, so the whole path (including
    // any query string) is encoded into that single parameter.
    const res = await fetch(API + encodeURIComponent(path), {
      credentials: 'same-origin',
      ...opts,
      headers: { Authorization: 'Bearer ' + ticket, ...(opts.headers || {}) },
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-JSON error */ }
    if (res.status === 401 && (data.code === 'TICKET_EXPIRED' || data.code === 'TICKET_INVALID')) {
      await refreshTicket();
    }
    return { status: res.status, data };
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    // innerHTML escapes < > & but not quotes, and these values also land inside
    // HTML attributes (e.g. href="…"), so escape both quote styles too.
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // --------------------------------------------------------- camera support

  /** Why the camera cannot be used here, or null if it can.
   *
   * Browsers only expose navigator.mediaDevices in a secure context. Opening
   * the portal over plain http:// (e.g. http://10.127.1.1/phdtimeoff/) leaves
   * it undefined, so capture fails before any request is made — which looks
   * exactly like "the button does nothing". Detect it and say so plainly.
   */
  function cameraBlockedReason() {
    if (!window.isSecureContext) {
      return 'The camera only works on a secure (https) address, and this page was opened over plain http.';
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return 'This browser will not give the page access to a camera.';
    }
    return null;
  }

  function secureUrl() {
    const base = root.dataset.secureBase || '';
    return base ? base + '/student/attendance.php' : location.href.replace(/^http:/, 'https:');
  }

  /** Rough platform bucket — only used to pick the right recovery wording. */
  function platform() {
    const ua = navigator.userAgent || '';
    // iPadOS 13+ reports itself as a Mac, so touch points disambiguate.
    if (/iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) {
      return 'ios';
    }
    return /Android/.test(ua) ? 'android' : 'desktop';
  }

  /** How to un-block a camera the browser has already refused.
   *
   * This is the crux of the "permission required" dead end: the browser asks
   * automatically only while the decision is still unmade. Once a site has been
   * denied, getUserMedia rejects instantly and NO script can raise the prompt
   * again — the permission must be reset by hand. So the message has to say
   * where that switch lives, per platform, instead of "allow and try again".
   */
  function unblockSteps() {
    switch (platform()) {
      case 'ios':
        return ['Tap the <b>aA</b> (or ⓘ) icon at the left of Safari’s address bar',
                'Choose <b>Website Settings</b>',
                'Set <b>Camera</b> to <b>Allow</b>, then reload this page',
                'Still stuck? iPhone <b>Settings → Safari → Camera → Allow</b>'];
      case 'android':
        return ['Tap the <b>lock</b> (or sliders) icon at the left of the address bar',
                'Tap <b>Permissions</b> → <b>Camera</b>',
                'Choose <b>Allow</b>, then reload this page'];
      default:
        return ['Click the <b>camera</b> or <b>lock</b> icon in the address bar',
                'Set <b>Camera</b> to <b>Allow</b>',
                'Reload this page'];
    }
  }

  /** Turn a getUserMedia rejection into something a student can act on. */
  function describeCameraError(e) {
    const name = (e && e.name) || '';
    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
      return {
        title: 'Camera access is blocked for this site.',
        detail: 'The browser refused the camera without asking, which means access was declined here earlier. '
              + 'It has to be switched back on by hand — a page cannot re-ask on its own.',
        steps: unblockSteps(),
      };
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      return { title: 'No camera found on this device.',
               detail: 'Use a phone or a laptop that has a front camera.', steps: [] };
    }
    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
      return { title: 'The camera is already in use.',
               detail: 'Another app or browser tab has it open. Close that (video call, camera app) and try again.',
               steps: [] };
    }
    return { title: 'The camera could not be started.',
             detail: (e && (e.message || e.name)) || 'Unknown error.', steps: [] };
  }

  /** Inline, persistent help with a retry button — an alert() is dismissed and
   *  forgotten, which is no use for instructions you must follow in a menu. */
  function showCameraHelp(info, onRetry) {
    const old = document.getElementById('att-cam-help');
    if (old) old.remove();
    const el = document.createElement('div');
    el.id = 'att-cam-help';
    el.className = 'alert alert-error';
    // info.title/detail are escaped (detail can carry a browser message);
    // the step strings are our own static markup and intentionally keep <b>.
    el.innerHTML =
      '<i class="fas fa-video-slash mt-0.5"></i><div class="min-w-0 flex-1">'
      + '<p class="font-semibold">' + esc(info.title) + '</p>'
      + '<p class="mt-1">' + esc(info.detail) + '</p>'
      + (info.steps.length
          ? '<ol class="mt-2 ml-5 list-decimal space-y-1">'
            + info.steps.map((s) => '<li>' + s + '</li>').join('') + '</ol>'
          : '')
      + (onRetry
          ? '<button type="button" id="att-cam-retry" class="btn btn-danger btn-sm mt-3">'
            + '<i class="fas fa-redo"></i>Try camera again</button>'
          : '')
      + '</div>';
    root.insertBefore(el, root.firstChild);
    try { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (x) { /* older browsers */ }
    const retryBtn = document.getElementById('att-cam-retry');
    if (retryBtn && onRetry) {
      retryBtn.addEventListener('click', () => { el.remove(); onRetry(); });
    }
  }

  /** Current camera permission: 'granted' | 'prompt' | 'denied' | 'unknown'.
   *
   * The Permissions API is authoritative but Safari does not implement the
   * 'camera' name, so fall back to a device-label probe: browsers hide device
   * labels until camera access has been granted, so a video input with a real
   * label means granted. An empty label cannot distinguish prompt from denied —
   * hence 'unknown', where offering the prompt is still the right move.
   */
  async function cameraPermissionState() {
    try {
      if (navigator.permissions && navigator.permissions.query) {
        const st = await navigator.permissions.query({ name: 'camera' });
        if (st.state) return st.state;
      }
    } catch (e) { /* unsupported name (Safari) — fall through to the probe */ }
    try {
      const devices = await navigator.mediaDevices.enumerateDevices();
      const cams = devices.filter((d) => d.kind === 'videoinput');
      if (!cams.length) return 'unknown';
      return cams.some((d) => d.label) ? 'granted' : 'unknown';
    } catch (e) { return 'unknown'; }
  }

  /** Ask for the camera now, on this click.
   *
   * ALWAYS attempt getUserMedia, even when we believe the site is already
   * blocked. Skipping the call looks tidier but is actively harmful: a browser
   * only exposes its own Camera control (the address-bar camera icon, and
   * Camera under the lock/settings menu → Permissions) once the page has really
   * requested the camera. No request, no control for the student to flip — so
   * the attempt is what makes recovery discoverable, which is exactly how the
   * webcam-test sites behave. It also self-corrects if our state detection was
   * wrong (Safari has no 'camera' permission name).
   *
   * On a still-undecided site this raises the prompt; on a blocked one it
   * rejects instantly with no dialog (nwjs/nw.js#7832) and we show the steps.
   * Either way the track is stopped at once so no camera light lingers.
   */
  async function requestCameraNow(onDone) {
    try {
      const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      s.getTracks().forEach((t) => t.stop());
      const panel = document.getElementById('att-cam-enable');
      if (panel) {
        panel.className = 'alert alert-success';
        panel.innerHTML = '<i class="fas fa-check-circle mt-0.5"></i><div><b class="font-semibold">Camera enabled.</b> '
          + 'You can mark attendance or enroll now.</div>';
        setTimeout(() => { if (panel.parentNode) panel.remove(); }, 4000);
      }
      if (onDone) onDone(true);
    } catch (e) {
      const panel = document.getElementById('att-cam-enable');
      if (panel) panel.remove();
      showCameraHelp(describeCameraError(e), () => requestCameraNow(onDone));
      if (onDone) onDone(false);
    }
  }

  /** Offer a one-tap "Allow camera" before the student is mid-capture.
   *  `blocked` = we already know the site was refused, so set expectations and
   *  include the recovery steps — but STILL offer the request, because that is
   *  what makes the browser show its own Camera control. */
  function showEnableCameraPanel(blocked) {
    const existing = document.getElementById('att-cam-enable');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.id = 'att-cam-enable';
    el.className = blocked
      ? 'alert alert-error'
      : 'alert alert-info';
    el.innerHTML = blocked
      ? '<i class="fas fa-video-slash mt-0.5"></i><div class="min-w-0 flex-1">'
        + '<p class="font-semibold">Camera is blocked for this site.</p>'
        + '<p class="mt-1">You cannot enrol or mark attendance without it. Tap below — your browser '
        + 'will then show a camera icon in the address bar you can use to allow it.</p>'
        + '<button type="button" id="att-cam-enable-btn" class="btn btn-danger btn-sm mt-3">'
        + '<i class="fas fa-video"></i>Request camera access</button>'
        + '<ol class="mt-3 ml-5 list-decimal space-y-1">'
        + unblockSteps().map((s) => '<li>' + s + '</li>').join('') + '</ol>'
        + '<p class="mt-2 text-xs opacity-80">This page notices the moment you allow it — no need to reload.</p></div>'
      : '<i class="fas fa-camera mt-0.5"></i><div class="min-w-0 flex-1">'
        + '<p class="font-semibold">Camera access needed.</p>'
        + '<p class="mt-1">Attendance uses a live photo. Tap below and choose <b>Allow</b> when your '
        + 'browser asks — doing it now means the camera is ready when you mark attendance.</p>'
        + '<button type="button" id="att-cam-enable-btn" class="btn btn-primary btn-sm mt-3">'
        + '<i class="fas fa-video"></i>Allow camera</button></div>';
    root.insertBefore(el, root.firstChild);
    document.getElementById('att-cam-enable-btn').addEventListener('click', () => requestCameraNow(null));
    watchCameraPermission();
  }

  /** Notice the moment the student flips the permission in browser settings.
   *
   * permissions.onchange covers Chrome/Firefox, but Safari does not implement
   * the 'camera' permission name at all, so nothing would ever fire there. A
   * light poll (cheap: no getUserMedia, just a state query) means the page picks
   * the change up on every browser and the student is not left staring at a
   * stale warning wondering whether it worked. Stops as soon as it is granted.
   */
  let permWatch = null;
  function watchCameraPermission() {
    if (permWatch) return;
    let ticks = 0;
    permWatch = setInterval(async () => {
      if (document.hidden) return;                 // don't poll a backgrounded tab
      ticks++;
      if (ticks > 200) { clearInterval(permWatch); permWatch = null; return; }  // ~5 min cap
      const state = await cameraPermissionState();
      if (state === 'granted') {
        clearInterval(permWatch); permWatch = null;
        ['att-cam-help', 'att-cam-enable'].forEach((id) => {
          const el = document.getElementById(id);
          if (el) el.remove();
        });
        const ok = document.createElement('div');
        ok.className = 'alert alert-success';
        ok.innerHTML = '<i class="fas fa-check-circle mt-0.5"></i><div><b class="font-semibold">Camera allowed.</b> '
          + 'You can enrol or mark attendance now.</div>';
        root.insertBefore(ok, root.firstChild);
        setTimeout(() => { if (ok.parentNode) ok.remove(); }, 6000);
      }
    }, 1500);
  }

  /** Route on the real permission state: prompt for anyone who has not decided,
   *  give recovery steps to anyone already blocked, and stay silent otherwise. */
  async function preflightCamera() {
    if (cameraBlockedReason()) return;                     // insecure context: warned separately
    const state = await cameraPermissionState();
    if (state === 'denied') {
      // Still offer the request: it is what makes the browser reveal its own
      // Camera control, and it is the student's route back.
      showEnableCameraPanel(true);
    } else if (state === 'prompt' || state === 'unknown') {
      // Not allowed yet — this is the case where the popup CAN still be raised.
      showEnableCameraPanel(false);
    }
    // 'granted' needs no UI — the capture flow just works.
    // Keep in step if the student changes it in browser settings mid-visit.
    try {
      if (navigator.permissions && navigator.permissions.query) {
        const st = await navigator.permissions.query({ name: 'camera' });
        st.onchange = () => {
          ['att-cam-help', 'att-cam-enable'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.remove();
          });
          if (st.state === 'denied') showEnableCameraPanel(true);
          else if (st.state === 'prompt') showEnableCameraPanel(false);
        };
      }
    } catch (e) { /* no Permissions API for camera here */ }
  }

  /** Warn on load rather than letting the student discover it mid-enrollment. */
  function warnIfCameraBlocked() {
    const reason = cameraBlockedReason();
    if (!reason) return false;
    if (document.getElementById('att-insecure-warning')) return true;
    const el = document.createElement('div');
    el.id = 'att-insecure-warning';
    el.className = 'alert alert-error';
    el.innerHTML = '<i class="fas fa-exclamation-triangle mt-0.5"></i><div><b class="font-semibold">Camera unavailable.</b> ' +
      esc(reason) + ' Attendance and enrollment need the camera, so please use ' +
      '<a class="underline font-semibold" href="' + esc(secureUrl()) + '">the secure link</a>.</div>';
    root.insertBefore(el, root.firstChild);
    return true;
  }

  // ----------------------------------------------------------------- status

  let net = null;

  function pill(color, icon, text) {
    return `<span class="inline-flex max-w-full items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium ring-1 ring-inset bg-${color}-50 text-${color}-700 ring-${color}-200">
              <i class="fas fa-${icon} text-xs opacity-80"></i>${text}</span>`;
  }

  function renderStatus() {
    if (!net) return;
    const rows = [];

    if (net.today && net.today.kind !== 'void') {
      const label = { self: 'Present', manual: 'Present (office)', excused: 'Excused' }[net.today.kind] || net.today.kind;
      rows.push(pill('green', 'check-circle', `${label}${net.today.marked_at_ist ? ' at ' + net.today.marked_at_ist : ''}`));
    } else if (net.pending_review) {
      rows.push(pill('amber', 'hourglass-half', 'Under office review — you will not be marked absent'));
    } else if (!net.working_day) {
      rows.push(pill('gray', 'mug-hot', (net.day_label ? esc(net.day_label) + ' — ' : '') + 'No attendance today'));
    } else {
      rows.push(pill(net.window.open_now ? 'blue' : 'gray', 'clock',
        `Window ${net.window.start}–${net.window.end}` +
        (net.window.extended_minutes ? ` (extended +${net.window.extended_minutes}m)` : '')));
      rows.push(net.ip_unavailable
        ? pill('purple', 'exclamation-triangle', 'Network check unavailable — contact the office')
        : net.ip_allowed
          ? pill('green', 'wifi', 'On SOM office WiFi')
          : pill('red', 'wifi', 'Not on SOM office WiFi'));
    }

    if (!net.face_enrolled) rows.push(pill('amber', 'id-badge', 'Enrollment pending'));
    else if (!net.device_enrolled) rows.push(pill('red', 'mobile-alt', 'This phone is not your enrolled device'));

    statusCard.innerHTML = `<div class="flex items-start gap-4">
        <span class="icon-chip chip-brand hidden sm:inline-flex"><i class="fas fa-calendar-day"></i></span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-gray-900">Today's attendance</p>
          <div class="mt-3 flex flex-wrap gap-2">${rows.join('')}</div>
        </div>
      </div>`;

    const canMark = net.enabled && net.working_day && net.window.open_now && net.ip_allowed &&
      net.face_enrolled && net.device_enrolled &&
      !(net.today && net.today.kind !== 'void') && !net.pending_review;

    markBtn.classList.toggle('hidden', !canMark);
    markHint.textContent = !net.enabled ? 'Attendance is temporarily disabled by the office.'
      : (net.today && net.today.kind !== 'void') ? ''
      : net.pending_review ? ''
      : !net.working_day ? ''
      : !net.window.open_now ? `Marking opens ${net.window.start}–${net.window.end} (server time).`
      : net.ip_unavailable ? 'The server cannot see which network you are on. Please inform the SOM office (proxy configuration).'
      : !net.ip_allowed ? 'Connect to the SOM office WiFi, then pull down to refresh.'
      : !net.face_enrolled ? 'Complete one-time enrollment at the SOM office first.'
      : !net.device_enrolled ? 'Attendance can only be marked from your enrolled phone.'
      : '';

    $('att-enroll-card').classList.toggle('hidden', net.face_enrolled === true);
    $('att-clock').textContent = net.server_time_ist ? net.server_time_ist.slice(11, 16) + ' IST' : '';
  }

  async function loadStatus() {
    const { status, data } = await api('/netcheck');
    if (status === 200 && data.ok) { net = data; renderStatus(); }
    else statusCard.innerHTML = '<div class="flex items-start gap-3 text-sm text-rose-700"><i class="fas fa-exclamation-circle mt-0.5"></i><span>Could not reach the attendance service. Pull down to retry.</span></div>';
  }

  // ---------------------------------------------------------------- history

  async function loadHistory() {
    const { data } = await api('/my/history?months=3');
    const box = $('att-history');
    if (!data.ok) { box.innerHTML = '<div class="px-5 py-8 text-center text-sm text-gray-500 sm:px-6">No data.</div>'; return; }
    if (!data.records.length) {
      box.innerHTML = '<div class="px-5 py-10 text-center sm:px-6"><div class="empty-state-icon"><i class="far fa-calendar"></i></div>'
        + '<p class="empty-state-title">No attendance records yet.</p></div>'; return;
    }
    const icon = { self: ['check', 'green'], manual: ['user-edit', 'blue'], excused: ['umbrella-beach', 'amber'] };
    box.innerHTML = data.records.map((r) => {
      const [ic, color] = icon[r.kind] || ['question', 'gray'];
      return `<div class="flex items-center gap-3 px-5 py-3 text-sm transition-colors hover:bg-gray-50/70 sm:px-6">
        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs ring-1 ring-inset bg-${color}-50 text-${color}-600 ring-${color}-100"><i class="fas fa-${ic}"></i></span>
        <span class="min-w-0 flex-1 font-medium tabular-nums text-gray-900">${esc(r.date)}</span>
        <span class="capitalize tabular-nums text-${color}-700">${esc(r.kind)}${r.marked_at_ist ? ' · ' + r.marked_at_ist : ''}</span>
      </div>`;
    }).join('');
    $('att-history-summary').textContent = `${data.records.length} day(s) recorded`;
  }

  // ------------------------------------------------------------ camera flow

  const overlay = $('att-capture');
  const video = $('att-video');
  const stepLabel = $('att-capture-step');
  const countdownEl = $('att-countdown');
  const captureMsg = $('att-capture-msg');
  let stream = null;
  let cancelled = false;

  async function openCamera() {
    // Front camera at a portrait-ish size, but never fail over a size hint: some
    // laptop webcams and Android WebViews reject resolution/facingMode combos,
    // and a working camera beats an exact resolution. Errors are tagged so the
    // callers can show camera-specific help instead of a generic failure.
    const attempts = [];
    attempts.push({ video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } }, audio: false });
    attempts.push({ video: { facingMode: 'user' }, audio: false });
    attempts.push({ video: true, audio: false });

    let lastErr = null;
    for (let i = 0; i < attempts.length; i++) {
      try {
        stream = await navigator.mediaDevices.getUserMedia(attempts[i]);
        break;
      } catch (e) {
        lastErr = e;
        // A refusal never improves with looser hints.
        if (['NotAllowedError', 'SecurityError', 'PermissionDeniedError'].includes(e.name)) break;
        if (['NotFoundError', 'DevicesNotFoundError'].includes(e.name)) break;
      }
    }
    if (!stream) {
      const err = lastErr || new Error('camera unavailable');
      err.attCamera = true;
      throw err;
    }
    video.srcObject = stream;
    // Don't wait on an event that may already have fired, and don't wait forever
    // if it never does — the tracker re-checks readiness anyway.
    if (video.readyState < 1) {
      await new Promise((resolve) => {
        let done = false;
        const finish = () => { if (!done) { done = true; resolve(); } };
        video.onloadedmetadata = finish;
        setTimeout(finish, 3000);
      });
    }
    try { if (video.paused) await video.play(); } catch (e) { /* autoplay attrs cover it */ }
  }

  function stopStream() {
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
  }

  const cancelBtn = $('att-capture-cancel');
  function closeCamera() {
    stopStream();
    overlay.classList.add('hidden');
    setProgress(0, 0);
    if (window.Liveness) Liveness.reset();
    stepLabel.textContent = '';
    captureMsg.textContent = '';
    cancelBtn.textContent = 'Cancel';
  }

  /** Liveness v2 ends inside the overlay — a tick, an hourglass or a cross on
   *  the frame and the server's words under it — instead of an alert(). A mark
   *  closes itself after a moment; anything to read waits for "Done". */
  async function showResult(kind, title, message, autoCloseMs) {
    stopStream();
    if (window.Liveness) Liveness.finish(kind);
    setProgress(0, 0);
    stepLabel.textContent = title;
    captureMsg.textContent = message || '';
    cancelBtn.textContent = kind === 'fail' ? 'Close' : 'Done';
    if (kind === 'ok') { try { if (navigator.vibrate) navigator.vibrate([30, 60, 30]); } catch (e) { /* unsupported */ } }
    if (autoCloseMs) {
      await wait(autoCloseMs);
      if (!overlay.classList.contains('hidden') && cancelBtn.textContent === 'Done') closeCamera();
    }
  }

  /** "Step n of N" in the overlay's eyebrow while marking; its default label otherwise. */
  const progressEl = $('att-capture-progress');
  const progressDefault = progressEl ? progressEl.textContent : '';
  function setProgress(n, total) {
    if (progressEl) progressEl.textContent = total ? `Step ${n} of ${total}` : progressDefault;
  }


  function grabFrame() {
    const canvas = document.createElement('canvas');
    const w = Math.min(video.videoWidth || 720, 720);
    const h = Math.round(w * (video.videoHeight / video.videoWidth || 4 / 3));
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
  }

  function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }

  async function countdown(seconds) {
    for (let i = seconds; i > 0; i--) {
      countdownEl.textContent = i;
      await wait(1000);
      if (cancelled) throw new Error('cancelled');
    }
    countdownEl.textContent = '';
  }

  /** Run a guided capture: for each prompt, give the student a beat to move,
   *  then take `perStep` frames a moment apart (better odds one is clean).
   *  `pace` is the per-step countdown in seconds. Marking uses 2s: the server
   *  judges each step on that step's own frames, so the student must already be
   *  in the new pose when the first one is taken. Enrollment also uses 2s. */
  async function guidedCapture(prompts, pace, perStep = 2, onStep = null) {
    const frames = [];
    for (const [i, prompt] of prompts.entries()) {
      if (onStep) onStep(i + 1, prompts.length);
      stepLabel.textContent = prompt;
      await countdown(pace);
      for (let k = 0; k < perStep; k++) {
        if (k) await wait(350);
        frames.push(await grabFrame());
      }
      if (cancelled) throw new Error('cancelled');
    }
    return frames;
  }

  $('att-capture-cancel').addEventListener('click', () => { cancelled = true; closeCamera(); });

  // ---------------------------------------------------------------- marking

  markBtn.addEventListener('click', async () => {
    const blocked = cameraBlockedReason();
    if (blocked) {
      warnIfCameraBlocked();
      alert(blocked + '\n\nOpen this instead:\n' + secureUrl());
      return;
    }
    markBtn.disabled = true;
    cancelled = false;
    captureMsg.textContent = '';
    try {
      // Camera and face tracker first, challenge second: the challenge lives
      // for two minutes from issue, and the tracker's first load (download +
      // shader warm-up) can take a good part of that on a slow phone. Which
      // tracker: the one the server's flag points this student at (netcheck);
      // the challenge below has the final say.
      stepLabel.textContent = 'Starting camera…';
      overlay.classList.remove('hidden');
      await openCamera();
      const wantV2 = !!(net && net.liveness === 2);
      if (wantV2) stepLabel.textContent = 'Getting the face guide ready…';
      let guideReady = wantV2 ? await loadLiveness() : await FaceGuide.ensureLoaded(BASE);
      if (cancelled) throw new Error('cancelled');

      const { status, data } = await api('/mark/challenge', { method: 'POST' });
      if (status !== 200) {
        closeCamera();
        alert(data.message || 'Cannot mark right now.');
        return;
      }
      const issuedAt = Date.now();
      const v2 = data.protocol === 2;
      if (v2 !== wantV2) {
        // The flag changed since the page loaded: get the other tracker.
        guideReady = v2 ? await loadLiveness() : await FaceGuide.ensureLoaded(BASE);
        if (cancelled) throw new Error('cancelled');
      }

      const strictNeeded = data.strict_mode;
      $('att-strict-box').classList.toggle('hidden', !strictNeeded);

      // Interactive capture when the face tracker is available: frames are taken
      // the moment the pose is actually hit, which is both quicker and far more
      // reliable than grabbing blindly after a countdown. Falls back to the
      // timed capture whenever the tracker cannot run — the backend judges the
      // burst either way, so nothing about marking depends on the tracker.
      // The server's random sequence (e.g. turn → centre → other side) and how
      // many frames it expects per step; each step is checked in its own frames.
      const perStep = data.frames_per_step || 2;
      let frames = null;
      if (v2) {
        // Liveness v2: named turns, a blink and the colour flash, each step
        // captured only once it is actually performed (liveness.js).
        if (!window.Liveness) {
          closeCamera();
          alert('The attendance page did not load completely. Please reload it and try again.');
          return;
        }
        if (guideReady) {
          try {
            frames = await Liveness.runMark({
              video, data, isCancelled: () => cancelled, onStep: setProgress,
            });
          } catch (e) {
            if (String(e.message) === 'cancelled') throw e;
            console.warn('[attendance] live guide gave up:', e && e.message, Liveness.lastError());
            frames = null;
          }
        }
        if (!frames) {
          captureMsg.textContent = guideReady
            ? 'Live guide could not track your face — using timed prompts.'
            : 'Live guide unavailable on this device — using timed prompts.';
          if (!guideReady) console.warn('[attendance] liveness:', Liveness.lastError());
          frames = await Liveness.runTimed({
            data, grab: grabFrame, countdown, isCancelled: () => cancelled, onStep: setProgress,
          });
        }
      } else if (guideReady) {
        try {
          frames = await FaceGuide.runMark({
            video, grab: grabFrame, challenge: data.challenge, prompts: data.prompts, perStep,
            stepEl: stepLabel, msgEl: captureMsg, isCancelled: () => cancelled,
            onStep: setProgress,
          });
        } catch (e) {
          if (String(e.message) === 'cancelled') throw e;
          frames = null;                       // tracker gave up → timed capture
        }
      }
      if (!frames && !v2) {
        // Say so rather than silently degrading: an invisible fallback is
        // indistinguishable from a broken feature.
        captureMsg.textContent = guideReady
          ? 'Live guide could not track your face — using timed capture.'
          : 'Live guide unavailable on this device — using timed capture.';
        if (!guideReady) console.warn('[attendance] face guide:', FaceGuide.lastError());
        const prompts = data.challenge.map((p) => data.prompts[p] || p);
        frames = await guidedCapture(prompts, 2, perStep, setProgress);
      }
      setProgress(0, 0);

      let strictCode = '';
      if (strictNeeded) {
        stepLabel.textContent = 'Enter the code from the office screen';
        const input = $('att-strict-code');
        input.value = '';
        input.focus();
        strictCode = await new Promise((resolve) => {
          const check = () => {
            if (cancelled) resolve('');
            else if (input.value.trim().length === 6) resolve(input.value.trim());
            else setTimeout(check, 300);
          };
          check();
        });
        if (cancelled) throw new Error('cancelled');
      }

      stepLabel.textContent = 'Verifying…';
      const form = new FormData();
      form.append('token', data.token);
      if (strictCode) form.append('strict_code', strictCode);
      // frames[] so PHP keeps every part; the forwarder re-emits them as
      // repeated "frames" fields for the API.
      frames.forEach((blob, i) => form.append('frames[]', blob, `f${i}.jpg`));

      let result = await api('/mark', { method: 'POST', body: form });
      // SERVER_BUSY means nothing was judged and the challenge is still unused,
      // so the very same frames can be sent again — but only while the
      // challenge has comfortably longer to live than the server's queue wait.
      const resendBefore = issuedAt + ((data.expires_in || 120) - 35) * 1000;
      for (let tries = 0; tries < 3 && !cancelled && result.status === 503
           && result.data.code === 'SERVER_BUSY' && Date.now() + 4000 < resendBefore; tries++) {
        stepLabel.textContent = 'Server busy — trying again…';
        await wait(4000);
        result = await api('/mark', { method: 'POST', body: form });
      }
      if (cancelled) throw new Error('cancelled');

      if (v2) {
        if (result.status === 200 && result.data.ok && result.data.status === 'marked') {
          showResult('ok', 'You’re marked present', result.data.message, 2600);
        } else if (result.status === 200 && result.data.ok) {
          showResult('wait', 'Sent to the office for review', result.data.message);
        } else {
          showResult('fail', 'Not verified', result.data.message || 'Verification failed. Please try again.');
        }
        return;
      }
      closeCamera();

      if (result.status === 200 && result.data.ok) {
        alert(result.data.message);
      } else {
        alert(result.data.message || 'Verification failed. Please try again.');
      }
    } catch (e) {
      closeCamera();
      if (String(e.message) !== 'cancelled') {
        if (e.attCamera) {
          showCameraHelp(describeCameraError(e), () => markBtn.click());
        } else {
          alert('Something went wrong. Please try again.');
        }
      }
    } finally {
      markBtn.disabled = false;
      loadStatus();
      loadHistory();
    }
  });

  // ------------------------------------------------------------- enrollment

  const ENROLL_PROMPTS = [
    'Look straight at the camera',
    'Turn your head to your LEFT',
    'Turn your head to your RIGHT',
    'Tilt your head slightly UP',
    'Tilt your head slightly DOWN',
    'Look straight again',
  ];

  async function enroll(code) {
    const msg = $('att-enroll-msg');
    msg.textContent = '';
    // Enrollment is WiFi-gated too: the OTP alone can't enroll a face from off
    // campus. Pre-check for a friendly message; the backend enforces it anyway.
    if (net && !net.ip_allowed) {
      msg.textContent = 'Connect to the SOM office WiFi to enroll (you must be in the office).';
      return;
    }
    const blocked = cameraBlockedReason();
    if (blocked) {
      warnIfCameraBlocked();
      msg.textContent = blocked;
      alert(blocked + '\n\nOpen this instead:\n' + secureUrl());
      return;
    }
    cancelled = false;
    try {
      stepLabel.textContent = 'Starting camera…';
      overlay.classList.remove('hidden');
      $('att-strict-box').classList.add('hidden');
      await openCamera();
      // Enrollment is the one-off, thorough capture: the template needs several
      // angles. Liveness v2 guides the student through them one held pose at a
      // time (straight, left, right, up, down); the original flow sweeps the
      // head around faceguide.js's ring. Same fallback contract either way.
      let frames = null;
      const v2 = !!(net && net.liveness === 2);
      const guideReady = v2 ? await loadLiveness() : await FaceGuide.ensureLoaded(BASE);
      if (v2 && guideReady) {
        try {
          frames = await Liveness.runEnroll({ video, isCancelled: () => cancelled, onStep: setProgress });
        } catch (e) {
          if (String(e.message) === 'cancelled') throw e;
          frames = null;
          if (window.Liveness) Liveness.reset();
        }
      } else if (guideReady) {
        try {
          frames = await FaceGuide.runEnroll({
            video, grab: grabFrame, stepEl: stepLabel, msgEl: captureMsg,
            isCancelled: () => cancelled,
          });
        } catch (e) {
          if (String(e.message) === 'cancelled') throw e;
          frames = null;
        }
      }
      if (!frames) {
        captureMsg.textContent = guideReady
          ? 'Live guide could not track your face — using timed capture.'
          : 'Live guide unavailable on this device — using timed capture.';
        if (!guideReady) console.warn('[attendance] face guide:', v2 && window.Liveness ? Liveness.lastError() : FaceGuide.lastError());
        frames = await guidedCapture(ENROLL_PROMPTS, 2);
      }

      setProgress(0, 0);
      stepLabel.textContent = 'Building your face profile…';
      const form = new FormData();
      form.append('token', code);
      frames.forEach((blob, i) => form.append('frames[]', blob, `e${i}.jpg`));

      const { status, data } = await api('/enroll/capture', { method: 'POST', body: form });
      if (v2 && status === 200 && data.ok) {
        await showResult('ok', 'You’re enrolled', 'This phone is now your attendance device.', 2600);
        return;
      }
      closeCamera();
      if (status === 200 && data.ok) {
        alert('Enrollment complete. This phone is now your attendance device.');
      } else {
        msg.textContent = data.message || 'Enrollment failed. Please retry with the office.';
      }
    } catch (e) {
      closeCamera();
      if (String(e.message) !== 'cancelled') {
        if (e.attCamera) {
          // Camera trouble gets the inline panel with platform-specific steps
          // and a retry, because "allow access" alone is not actionable once
          // the browser has stopped asking.
          msg.textContent = 'The camera could not be opened — see the note at the top of this page.';
          showCameraHelp(describeCameraError(e), () => enroll(code));
        } else {
          const text = 'Enrollment failed: ' + (e.message || e.name || 'unknown error');
          msg.textContent = text;
          alert(text);        // the inline note is easy to miss on a phone
        }
      }
    } finally {
      loadStatus();
    }
  }

  function startEnrollment() {
    const code = $('att-enroll-code').value.replace(/\D/g, '');
    if (code.length !== 6) {
      $('att-enroll-msg').textContent = 'Enter the 6-digit code given by the office.';
      return;
    }
    enroll(code);
  }

  $('att-enroll-btn').addEventListener('click', startEnrollment);
  // Phone keyboards show a "go" key for this field — make it work.
  $('att-enroll-code').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); startEnrollment(); }
  });
  // Digits only. Deliberately does NOT auto-submit on the sixth digit: starting
  // enrollment opens the camera, and a mistyped code would burn one of the ten
  // throttled attempts.
  $('att-enroll-code').addEventListener('input', (e) => {
    const digits = e.target.value.replace(/\D/g, '').slice(0, 6);
    if (digits !== e.target.value) e.target.value = digits;
  });

  if (root.dataset.enrollCode) {
    $('att-enroll-code').value = root.dataset.enrollCode;
  }

  // ------------------------------------------------------------------- init

  warnIfCameraBlocked();
  preflightCamera();          // flag an already-blocked camera before they start
  loadStatus();
  loadHistory();
  setInterval(loadStatus, 60 * 1000);
})();
