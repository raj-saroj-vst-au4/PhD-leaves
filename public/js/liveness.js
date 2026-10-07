/**
 * Liveness — the liveness v2 face capture (att_settings liveness_v2, or the
 * liveness_v2_students pilot list). The original flow (faceguide.js) runs for
 * everyone else; the server says which in netcheck and in each challenge.
 *
 * What the student does: look straight, then — in the server's random order —
 * turn their head to THEIR left OR right (the server names one) and blink.
 * (A colour-flash step exists but is off unless att_settings flash_enabled=1.)
 * Each step is captured automatically, only once the pose is actually held,
 * which is what the first production failures lacked: every
 * POSE_CHALLENGE_FAILED had a frame taken while the head was still moving.
 *
 * Tracker: Google MediaPipe Face Landmarker (@mediapipe/tasks-vision 0.10.35,
 * Apache-2.0), vendored under public/vendor/mediapipe/ — no CDN at runtime, and
 * no telemetry (the 1.x releases post usage logs to a Google endpoint; see the
 * README there). It runs under the production CSP: the bundle is an ES module
 * loaded with import(), the WASM loader is a same-origin <script>, WASM
 * compiles under 'unsafe-eval', and nothing uses blob: URLs or workers.
 *
 * Geometry — the one thing that must match the server exactly. MediaPipe's
 * landmarks are in the RAW camera image (what the server receives; only the
 * preview is mirrored, by CSS). turn = the nose tip's offset from the eye
 * midpoint along the image-left -> image-right eye line, in eye distances:
 * POSITIVE = the student turned to THEIR LEFT. faces.turn_ratio computes the
 * same thing from the detector's keypoints. MediaPipe's reading runs 1.15-1.6x
 * the server's (measured on the same faces, same sign on all twelve), so the
 * thresholds below are the server's scaled up, plus margin: a pose the phone
 * accepts is one the server accepts.
 *
 * The client is never trusted — it only decides WHEN to take the frames the
 * server then judges. If the tracker cannot load or never finds a face,
 * runMark/runEnroll throw 'LIVENESS_UNAVAILABLE' and attendance.js falls back
 * to timed prompts (runTimed here for v2).
 */
(function () {
  'use strict';

  // --- thresholds (MediaPipe units; server equivalents in faces.py) --------
  const TURN_MIN = 0.24;          // server needs 0.11; 0.24 / 1.6 = 0.15
  const TURN_MIN_RELAXED = 0.20;  // after RELAX_AFTER_MS stuck on one turn: 0.20 / 1.6 = 0.125
  const RELAX_AFTER_MS = 8000;
  const TURN_MAX = 0.75;          // further, and identity scores start to sag
  const ENROLL_TURN_MAX = 0.6;    // enrollment angles stay moderate: they are what marks match
  const CENTRE_MAX = 0.06;        // server allows 0.085; MediaPipe is ±0.04 off near zero
  const VERT_MIN = 0.07, VERT_MAX = 0.35;   // enrollment up/down, change in nose drop
  const HOLD_MS = 400;            // pose held this long before the first frame
  const PAIR_GAP_MS = 180;        // ...and the step's second frame this much later
  const STILL_TURN = 0.06;        // max spread of `turn` over the hold
  const STILL_MOVE = 0.12;        // max nose travel over the hold, eye distances
  const MIN_FACE = 0.26, MAX_FACE = 0.85;   // face width / frame width (server refuses < 0.18)
  const OFF_CENTRE = 0.2;         // face centre this far from the frame centre
  const DARK_LUMA = 55;           // mean luma; the server refuses below 40
  const SHARP_MIN = 35;           // whole-frame Laplacian variance; the server refuses below 25
  // Blink, on MediaPipe's eyeBlink blendshapes (0 open .. 1 shut). The server
  // re-scores the two uploaded frames with the same model and wants: shut >=
  // 0.45, open <= 0.40, and 0.25 apart (faces.py "blink"). The phone asks for
  // a little more, per eye too, and relative to this student's own open eyes.
  // Looking down at the screen also lifts the scores (pilot attempt #41 took
  // that for a blink): it is told apart by time — a blink, even a slow one, is
  // over within about a second; reading the screen is not.
  const BLINK_SHUT = 0.50;        // mean of both eyes, and...
  const BLINK_SHUT_EYE = 0.40;    // ...each eye at least this
  const BLINK_RISE = 0.30;        // ...and this far above the student's own open eyes
  const BLINK_MAX_MS = 1200;      // shut for longer: not a blink — start again
  const BLINK_OPEN = 0.34;        // the frame after: open again (server allows 0.40)
  const BLINK_DROP = 0.28;        // ...and this far below the shut frame (server: 0.25)
  const BLINK_BASE_MAX = 0.45;    // samples above this are not "open" for the baseline
  const NO_FACE_GIVEUP_MS = 15000;
  const STEP_GIVEUP_MS = 30000;

  const PROMPTS = {
    centre: 'Look straight at the camera',
    left: 'Turn your head to your LEFT',
    right: 'Turn your head to your RIGHT',
    blink: 'Look at the camera and blink slowly',
    flash: 'Hold still while the screen changes colour',
    up: 'Tilt your chin UP a little',
    down: 'Tilt your chin DOWN a little',
  };
  const ICONS = {
    centre: 'fa-circle-dot', left: 'fa-arrow-left', right: 'fa-arrow-right', blink: 'fa-eye',
    flash: 'fa-palette', up: 'fa-arrow-up', down: 'fa-arrow-down',
  };

  const $ = (id) => document.getElementById(id);
  const reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  // Diagnostics for a phone test: ?lvdebug=1 shows the live measurements in the
  // frame; ?lvcpu=1 skips the GPU delegate (to compare speed, or rule it out).
  const debug = /[?&]lvdebug=1\b/.test(location.search);
  const cpuOnly = /[?&]lvcpu=1\b/.test(location.search);
  function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }
  function median(xs) {
    const s = xs.slice().sort((a, b) => a - b);
    return s.length ? s[Math.floor(s.length / 2)] : 0;
  }
  function buzz(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) { /* unsupported */ } }

  // ======================================================================
  // Loading MediaPipe
  // ======================================================================

  let landmarker = null;
  let delegateUsed = null;
  let vision = null;
  let fileset = null;
  let assets = null;
  let loadPromise = null;
  let lastError = null;
  let lastTs = 0;

  function note(where, e) {
    lastError = where + (e && e.message ? ': ' + e.message : '');
    // eslint-disable-next-line no-console
    console.error('[Liveness] ' + lastError, e || '');
    return false;
  }

  /** VIDEO mode needs strictly increasing timestamps, across every call. */
  function nextTs() {
    lastTs = Math.max(lastTs + 1, performance.now());
    return lastTs;
  }

  async function createLandmarker(delegate) {
    const lm = await vision.FaceLandmarker.createFromOptions(fileset, {
      baseOptions: { modelAssetPath: assets.model, delegate },
      runningMode: 'VIDEO',
      numFaces: 2,                    // to notice a second person, which the server refuses
      outputFaceBlendshapes: true,    // eyeBlinkLeft/Right time the blink frame
      outputFacialTransformationMatrixes: true,
      minFaceDetectionConfidence: 0.5,
      minFacePresenceConfidence: 0.5,
      minTrackingConfidence: 0.5,
    });
    // The first inference compiles GPU shaders and can take seconds; pay it
    // now, while the student is still reading, not inside the live loop. A
    // delegate that cannot run at all throws here and the next one is tried.
    const warm = document.createElement('canvas');
    warm.width = 96; warm.height = 128;
    const wctx = warm.getContext('2d');
    wctx.fillStyle = '#808080';
    wctx.fillRect(0, 0, 96, 128);
    lm.detectForVideo(warm, nextTs());
    return lm;
  }

  /**
   * Load the tracker once (memoised; a failure may be retried on the next
   * capture). `assetList` is #att-root's data-mp-assets. Resolves true/false.
   */
  async function ensureLoaded(assetList) {
    if (landmarker) return true;
    if (loadPromise) return loadPromise;
    loadPromise = (async () => {
      try {
        assets = typeof assetList === 'string' ? JSON.parse(assetList) : assetList;
        if (!assets || !assets.bundle) return note('no MediaPipe asset list on the page');
        if (typeof WebAssembly !== 'object') return note('WebAssembly unavailable');
        vision = await import(assets.bundle);
        const simd = await vision.FilesetResolver.isSimdSupported();
        fileset = simd
          ? { wasmLoaderPath: assets.simdJs, wasmBinaryPath: assets.simdWasm }
          : { wasmLoaderPath: assets.nosimdJs, wasmBinaryPath: assets.nosimdWasm };
        for (const delegate of (cpuOnly ? ['CPU'] : ['GPU', 'CPU'])) {
          try {
            landmarker = await createLandmarker(delegate);
            delegateUsed = delegate;
            return true;
          } catch (e) {
            note('could not start the ' + delegate + ' delegate', e);
          }
        }
        return false;
      } catch (e) {
        return note('load failed', e);
      }
    })();
    const ok = await loadPromise;
    if (!ok) loadPromise = null;
    return ok;
  }

  /** Some phones create a GPU landmarker that then fails per frame. */
  async function dropToCpu() {
    if (delegateUsed !== 'GPU') return false;
    try { landmarker.close(); } catch (e) { /* already gone */ }
    landmarker = null;
    try {
      landmarker = await createLandmarker('CPU');
      delegateUsed = 'CPU';
      return true;
    } catch (e) {
      return note('CPU fallback failed', e);
    }
  }

  // ======================================================================
  // Measuring one frame
  // ======================================================================

  /** What the steps need from one landmarker result (or {face:false}). */
  function measure(res, w, h) {
    const n = res && res.faceLandmarks ? res.faceLandmarks.length : 0;
    if (!n) return { face: false };
    if (n > 1) return { face: true, multiple: true };
    const L = res.faceLandmarks[0];
    const P = (i) => ({ x: L[i].x * w, y: L[i].y * h });     // pixels: distances must not be warped by w != h
    const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
    // Eye centres from the corners (33/133 and 362/263): the corners do not
    // move with the gaze, which the irises do. Ordered by IMAGE position.
    let a = mid(P(33), P(133));
    let b = mid(P(362), P(263));
    if (a.x > b.x) { const t = a; a = b; b = t; }
    const span = Math.hypot(b.x - a.x, b.y - a.y) || 1;
    const axis = { x: (b.x - a.x) / span, y: (b.y - a.y) / span };
    let down = { x: -axis.y, y: axis.x };
    const eyes = mid(a, b);
    const nose = P(1);                                         // nose tip
    const off = { x: nose.x - eyes.x, y: nose.y - eyes.y };
    if (off.x * down.x + off.y * down.y < 0) down = { x: -down.x, y: -down.y };
    let minX = 1, maxX = 0, minY = 1, maxY = 0;
    for (const p of L) {
      if (p.x < minX) minX = p.x;
      if (p.x > maxX) maxX = p.x;
      if (p.y < minY) minY = p.y;
      if (p.y > maxY) maxY = p.y;
    }
    const cats = res.faceBlendshapes && res.faceBlendshapes[0] ? res.faceBlendshapes[0].categories : [];
    let blinkL = 0, blinkR = 0;
    for (const c of cats) {
      if (c.categoryName === 'eyeBlinkLeft') blinkL = c.score;
      else if (c.categoryName === 'eyeBlinkRight') blinkR = c.score;
    }
    const blink = (blinkL + blinkR) / 2;
    return {
      face: true,
      turn: (off.x * axis.x + off.y * axis.y) / span,   // + = the student's LEFT
      vert: (off.x * down.x + off.y * down.y) / span,   // nose drop below the eye line
      nose: { x: nose.x / span, y: nose.y / span },     // for "is the head still"
      size: maxX - minX,
      cx: (minX + maxX) / 2,
      cy: (minY + maxY) / 2,
      blink,
      blinkMin: Math.min(blinkL, blinkR),
    };
  }

  // ======================================================================
  // The overlay (student/attendance.php #att-capture)
  // ======================================================================

  const ui = {
    get guide() { return $('att-guide'); },
    show() {
      const g = this.guide;
      if (g) { g.classList.remove('hidden'); g.dataset.state = 'search'; }
      this.hold(0);
    },
    reset() {
      const g = this.guide;
      if (g) g.classList.add('hidden');
      const steps = $('att-steps');
      if (steps) steps.innerHTML = '';
      this.cue(null);
      this.flash(null);
      const done = $('att-done');
      if (done) done.classList.add('hidden');
      const dbg = $('att-lv-debug');
      if (dbg) dbg.remove();
    },
    state(s) {
      const g = this.guide;
      if (g && g.dataset.state !== s) g.dataset.state = s;
    },
    hold(p) {
      const g = this.guide;
      const fill = g && g.querySelector('.att-guide-fill');
      if (fill) fill.style.strokeDashoffset = String(100 - 100 * Math.max(0, Math.min(1, p)));
    },
    setSteps(list) {
      const ol = $('att-steps');
      if (!ol) return;
      ol.innerHTML = list.map((s, i) =>
        '<li data-step="' + i + '" title="' + (PROMPTS[s] || s) + '"><i class="fas ' + (ICONS[s] || 'fa-circle') + '"></i></li>').join('');
    },
    markStep(i, state) {
      const li = document.querySelector('#att-steps > li[data-step="' + i + '"]');
      if (!li) return;
      li.dataset.state = state;
      if (state === 'done') li.innerHTML = '<i class="fas fa-check"></i>';
    },
    say(text) { const el = $('att-capture-step'); if (el) el.textContent = text; },
    hint(text) { const el = $('att-capture-msg'); if (el && el.textContent !== text) el.textContent = text || ''; },
    /** Direction cue. 'left' is drawn on the screen's left — see attendance.css. */
    cue(kind) {
      const el = $('att-cue');
      if (!el) return;
      if (!kind || !['left', 'right', 'up', 'down', 'blink'].includes(kind)) {
        el.classList.add('hidden');
        el.removeAttribute('data-cue');
        el.innerHTML = '';
        return;
      }
      if (el.dataset.cue === kind) return;
      el.dataset.cue = kind;
      const icon = { left: 'fa-chevron-left', right: 'fa-chevron-right', up: 'fa-chevron-up', down: 'fa-chevron-down' }[kind];
      el.innerHTML = kind === 'blink'
        ? '<span><i class="fas fa-eye"></i></span>'
        : '<span><i class="fas ' + icon + '"></i><i class="fas ' + icon + '"></i></span>';
      el.classList.remove('hidden');
    },
    flash(color) {
      const ov = $('att-capture');
      if (!ov) return;
      if (color) {
        ov.style.backgroundColor = color;
        ov.classList.add('att-flashing');
      } else {
        ov.style.backgroundColor = '';
        ov.classList.remove('att-flashing');
      }
    },
    /** The finishing state: 'ok' (tick), 'wait' (sent for review) or 'fail'. */
    done(kind) {
      const el = $('att-done');
      if (!el) return;
      el.dataset.kind = kind;
      // Restart the draw animation each time.
      el.classList.add('hidden');
      void el.offsetWidth; // eslint-disable-line no-void
      el.classList.remove('hidden');
      this.cue(null);
      const g = this.guide;
      if (g) g.classList.add('hidden');
    },
    debug(m) {
      if (!debug) return;
      let el = $('att-lv-debug');
      if (!el) {
        el = document.createElement('div');
        el.id = 'att-lv-debug';
        el.style.cssText = 'position:absolute;left:6px;bottom:6px;z-index:5;font:11px/1.3 ui-monospace,monospace;'
          + 'color:#fff;background:rgb(0 0 0/.6);padding:3px 6px;border-radius:6px;text-align:left;pointer-events:none';
        const frame = document.querySelector('.att-frame');
        if (frame) frame.appendChild(el);
      }
      el.textContent = !m.face ? 'no face · ' + delegateUsed
        : m.multiple ? 'faces: 2+'
          : 'turn ' + m.turn.toFixed(3) + ' vert ' + m.vert.toFixed(2) + ' blink ' + m.blink.toFixed(2)
            + ' size ' + m.size.toFixed(2) + ' luma ' + (m.luma == null ? '-' : Math.round(m.luma)) + ' · ' + delegateUsed;
    },
  };

  // ======================================================================
  // A capture run: samples frames, keeps the analysed snapshot
  // ======================================================================

  async function waitForVideo(video, timeoutMs) {
    const deadline = Date.now() + (timeoutMs || 5000);
    try { if (video.paused) await video.play(); } catch (e) { /* autoplay attr covers it */ }
    while (Date.now() < deadline) {
      if (video.readyState >= 2 && video.videoWidth > 0) return true;
      await wait(100);
    }
    return false;
  }

  function nextVideoFrame(video) {
    return new Promise((resolve) => {
      let done = false;
      const finish = () => { if (!done) { done = true; resolve(); } };
      if (video.requestVideoFrameCallback) video.requestVideoFrameCallback(finish);
      else requestAnimationFrame(finish);
      setTimeout(finish, 250);           // a stalled stream must not hang the loop
    });
  }

  function nextPaint() {
    return new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  }

  /**
   * One run over the live video. Every sample draws the current video frame
   * into `snap` (the same size grabFrame uses) and analyses THAT canvas, so a
   * frame we decide to keep is exactly the picture that was judged in position
   * — not whatever the camera shows a moment later.
   */
  function startRun(video, isCancelled) {
    const w = Math.min(video.videoWidth || 720, 720);
    const h = Math.round(w * ((video.videoHeight / video.videoWidth) || 4 / 3));
    const snap = document.createElement('canvas');
    snap.width = w; snap.height = h;
    const sctx = snap.getContext('2d');
    const tiny = document.createElement('canvas');
    tiny.width = 24; tiny.height = 32;
    const tctx = tiny.getContext('2d', { willReadFrequently: true });
    const started = performance.now();
    let sawFace = false;
    let failures = 0;
    let luma = null;
    let lumaAt = 0;

    async function sample() {
      await nextVideoFrame(video);
      if (isCancelled && isCancelled()) throw new Error('cancelled');
      sctx.drawImage(video, 0, 0, w, h);
      let res = null;
      try {
        res = landmarker.detectForVideo(snap, nextTs());
        failures = 0;
      } catch (e) {
        failures++;
        if (failures === 3 && await dropToCpu()) failures = 0;
        if (failures > 6 || !landmarker) throw new Error('LIVENESS_UNAVAILABLE');
      }
      const m = measure(res, w, h);
      m.t = performance.now();
      if (m.t - lumaAt > 300) {
        tctx.drawImage(snap, 0, 0, 24, 32);
        const d = tctx.getImageData(0, 0, 24, 32).data;
        let sum = 0;
        for (let i = 0; i < d.length; i += 4) sum += 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
        luma = sum / (d.length / 4);
        lumaAt = m.t;
      }
      m.luma = luma;
      if (m.face) sawFace = true;
      else if (!sawFace && m.t - started > NO_FACE_GIVEUP_MS) throw new Error('LIVENESS_UNAVAILABLE');
      ui.debug(m);
      return m;
    }

    /** The server's blur gate, computed the same way on the same picture:
     *  variance of the Laplacian over the whole grey frame (faces.analyse_frame
     *  refuses < 25 as TOO_BLURRY). Only run when a frame is about to be kept. */
    function sharpness() {
      const d = sctx.getImageData(0, 0, w, h).data;
      const g = new Float32Array(w * h);
      for (let i = 0; i < g.length; i++) g[i] = 0.299 * d[i * 4] + 0.587 * d[i * 4 + 1] + 0.114 * d[i * 4 + 2];
      let s = 0, s2 = 0, n = 0;
      for (let y = 1; y < h - 1; y++) {
        for (let x = 1, i = y * w + 1; x < w - 1; x++, i++) {
          const l = g[i - 1] + g[i + 1] + g[i - w] + g[i + w] - 4 * g[i];
          s += l; s2 += l * l; n++;
        }
      }
      return n ? s2 / n - (s / n) * (s / n) : 0;
    }

    function keep() {
      // Copy, then encode the copy: `snap` is redrawn on the next sample,
      // while toBlob works asynchronously.
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(snap, 0, 0);
      return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.85));
    }

    function copy(into) {
      into.width = w; into.height = h;
      into.getContext('2d').drawImage(snap, 0, 0);
      return into;
    }

    return { sample, sharpness, keep, copy, w, h, vert0: null };
  }

  function encodeCanvas(c) {
    return new Promise((resolve) => c.toBlob(resolve, 'image/jpeg', 0.85));
  }

  // ======================================================================
  // Steps
  // ======================================================================

  /** Generic framing problems, most fundamental first; null when fine. */
  function framing(m) {
    if (!m.face) return { state: 'search', msg: 'Bring your face into the oval' };
    if (m.multiple) return { state: 'search', msg: 'Only you in the picture, please' };
    if (m.luma != null && m.luma < DARK_LUMA) return { state: 'adjust', msg: 'Too dark — face a light or a window' };
    if (m.size < MIN_FACE) return { state: 'adjust', msg: 'Move closer' };
    if (m.size > MAX_FACE) return { state: 'adjust', msg: 'Move back a little' };
    if (Math.abs(m.cx - 0.5) > OFF_CENTRE || Math.abs(m.cy - 0.48) > OFF_CENTRE + 0.05) {
      return { state: 'adjust', msg: 'Centre your face in the oval' };
    }
    return null;
  }

  /** Is the face where `target` wants it? {ok, state, msg}. */
  function judgePose(m, target, run, elapsed, opts) {
    const f = framing(m);
    if (f) return f;
    const turnMin = elapsed > RELAX_AFTER_MS ? TURN_MIN_RELAXED : TURN_MIN;
    const turnMax = (opts && opts.turnMax) || TURN_MAX;
    const side = target === 'left' ? 1 : target === 'right' ? -1 : 0;
    if (target === 'centre') {
      return Math.abs(m.turn) <= CENTRE_MAX
        ? { ok: true, state: 'ok', msg: 'Hold still…' }
        : { state: 'adjust', msg: 'Look straight at the camera' };
    }
    if (side) {
      const t = m.turn * side;            // > 0 when turned the asked-for way
      const name = target === 'left' ? 'LEFT' : 'RIGHT';
      if (t >= turnMin && t <= turnMax) return { ok: true, state: 'ok', msg: 'Hold it there…' };
      if (t > turnMax) return { state: 'adjust', msg: 'Not so far — turn back a little' };
      if (t <= -0.6 * turnMin) return { state: 'adjust', msg: 'Other way — turn to your ' + name };
      return { state: 'adjust', msg: t > 0.5 * turnMin ? 'A little more to your ' + name : 'Turn your head to your ' + name };
    }
    // enrollment up/down, relative to the straight-on nose drop
    if (Math.abs(m.turn) > 0.12) return { state: 'adjust', msg: 'Face the camera, then tilt' };
    const dv = (m.vert - (run.vert0 == null ? m.vert : run.vert0)) * (target === 'up' ? -1 : 1);
    if (dv >= VERT_MIN && dv <= VERT_MAX) return { ok: true, state: 'ok', msg: 'Hold it there…' };
    if (dv > VERT_MAX) return { state: 'adjust', msg: 'Not so far' };
    return { state: 'adjust', msg: target === 'up' ? 'Tilt your chin UP a little' : 'Tilt your chin DOWN a little' };
  }

  function still(win) {
    let tMin = Infinity, tMax = -Infinity, xMin = Infinity, xMax = -Infinity, yMin = Infinity, yMax = -Infinity;
    for (const m of win) {
      tMin = Math.min(tMin, m.turn); tMax = Math.max(tMax, m.turn);
      xMin = Math.min(xMin, m.nose.x); xMax = Math.max(xMax, m.nose.x);
      yMin = Math.min(yMin, m.nose.y); yMax = Math.max(yMax, m.nose.y);
    }
    return tMax - tMin <= STILL_TURN && Math.max(xMax - xMin, yMax - yMin) <= STILL_MOVE;
  }

  /**
   * Hold `target` for HOLD_MS, still, then keep `count` frames a beat apart,
   * every one of them itself in position. Any slip restarts the hold, so no
   * frame of a moving head is ever sent.
   */
  async function poseStep(run, target, count, opts) {
    const started = performance.now();
    let holdStart = null;
    let win = [];
    let kept = [];
    let blurry = 0;
    const verts = [];
    for (;;) {
      const m = await run.sample();
      const elapsed = m.t - started;
      if (elapsed > STEP_GIVEUP_MS) throw new Error('LIVENESS_UNAVAILABLE');
      const v = judgePose(m, target, run, elapsed, opts);
      ui.state(v.state);
      if (!v.ok) {
        holdStart = null; win = []; kept = [];
        ui.hold(0);
        ui.hint(v.msg);
        continue;
      }
      if (holdStart === null) holdStart = m.t;
      win.push(m);
      win = win.filter((s) => m.t - s.t <= HOLD_MS + PAIR_GAP_MS * count + 100);
      if (!still(win)) {
        holdStart = m.t; win = [m]; kept = [];
        ui.hold(0);
        ui.hint('Hold still');
        continue;
      }
      if (target === 'centre') verts.push(m.vert);
      const held = m.t - holdStart;
      ui.hold(Math.min(1, held / HOLD_MS));
      ui.hint(v.msg);
      const due = kept.length === 0 ? held >= HOLD_MS : m.t - kept[kept.length - 1].t >= PAIR_GAP_MS;
      if (due) {
        // A blurry frame is skipped, not sent; after a few in a row (a soft
        // camera in poor light) it is sent anyway and the server decides.
        if (blurry < 6 && run.sharpness() < SHARP_MIN) {
          blurry++;
          ui.hint('Hold still — the picture is blurred');
          continue;
        }
        blurry = 0;
        kept.push({ t: m.t, blob: run.keep() });
        if (kept.length >= count) {
          if (target === 'centre' && verts.length) run.vert0 = median(verts);
          return kept.map((k) => k.blob);
        }
      }
    }
  }

  /**
   * A blink, slowly: the shut frame is the very picture the tracker scored
   * shut — `snap`, the canvas detectForVideo just ran on, copied at the peak
   * of the closure, never a later grab — then a frame once the eyes are open
   * again. Both are re-scored on the server by the same model.
   */
  async function blinkStep(run) {
    const started = performance.now();
    const peakCanvas = document.createElement('canvas');
    let base = [];
    let phase = 'ready';
    let peak = 0;
    let peakT = 0;
    let closingT = 0;
    let closed = null;
    for (;;) {
      const m = await run.sample();
      if (m.t - started > STEP_GIVEUP_MS) throw new Error('LIVENESS_UNAVAILABLE');
      const f = framing(m) || (Math.abs(m.turn) > CENTRE_MAX * 1.5 ? { state: 'adjust', msg: 'Look straight at the camera' } : null);
      if (f) {
        ui.state(f.state);
        ui.hint(f.msg);
        if (phase !== 'ready') { phase = 'ready'; closed = null; ui.hold(0); }
        continue;
      }
      ui.state('ok');
      const b0 = base.length ? median(base) : m.blink;
      const shut = m.blink >= Math.max(BLINK_SHUT, b0 + BLINK_RISE) && m.blinkMin >= BLINK_SHUT_EYE;
      if (phase === 'ready') {
        if (m.blink < BLINK_BASE_MAX) {
          base.push(m.blink);
          if (base.length > 12) base = base.slice(-12);
        }
        if (base.length >= 3 && shut) {
          phase = 'closing';
          peak = m.blink; peakT = m.t; closingT = m.t;
          run.copy(peakCanvas);
          ui.hold(0.4);
        } else {
          ui.hint(m.t - started > 5000 ? 'Close your eyes fully for a moment, then open them' : 'Blink slowly');
        }
      } else if (phase === 'closing') {
        // Keep the most-shut picture of this closure.
        if (shut && m.blink > peak) {
          peak = m.blink; peakT = m.t;
          run.copy(peakCanvas);
        }
        if (shut && m.t - closingT > BLINK_MAX_MS) {
          // Too long to be a blink: eyes kept down (reading the screen?).
          phase = 'ready'; ui.hold(0);
          ui.hint('Look at the camera, then blink');
        } else if (!shut) {
          closed = encodeCanvas(peakCanvas);
          phase = 'opening';
          ui.hold(0.7);
          ui.hint('Now open your eyes');
        }
      } else if (phase === 'opening') {
        const reopened = m.blink <= peak - BLINK_DROP
          && (m.blink <= BLINK_OPEN
              // Some open eyes never score that low (looking down at the
              // phone): after a moment, back to their own normal will do and
              // the server decides.
              || (m.t - peakT > 1200 && m.blink <= b0 + 0.08));
        if (reopened) {
          ui.hold(1);
          return [closed, run.keep()];
        }
        if (m.t - peakT > 4000) { phase = 'ready'; closed = null; ui.hold(0); }
      }
    }
  }

  /**
   * The colour flash: paint each server colour over the whole overlay, wait
   * for it to reach the screen AND the camera (~1 frame of paint, ~100-150 ms
   * of camera pipeline), then keep the frame — once per colour. The face must
   * stay put throughout, or the colours of different frames are not of the
   * same skin; a slip starts the sequence over (same colours).
   */
  async function flashStep(run, flash, opts) {
    const colors = (flash && flash.colors) || [];
    const durations = (flash && flash.durations_ms) || [];
    const validate = !(opts && opts.unvalidated);
    // Settle: straight on, still, for a moment.
    if (validate) {
      ui.hint('Hold still — the screen will change colour');
      let calm = null;
      for (;;) {
        const m = await run.sample();
        const f = framing(m) || (Math.abs(m.turn) > CENTRE_MAX * 1.5 ? { state: 'adjust', msg: 'Look straight at the camera' } : null);
        if (f) { ui.state(f.state); ui.hint(f.msg); calm = null; continue; }
        ui.state('ok');
        calm = calm || m.t;
        ui.hold(Math.min(1, (m.t - calm) / 600));
        if (m.t - calm >= 600) break;
      }
    }
    for (let attempt = 0; attempt < 4; attempt++) {
      const last = attempt === 3 || !validate;      // the last try keeps whatever it gets: the server judges
      const blobs = [];
      let ref = null;
      let broken = false;
      ui.hint('Keep still…');
      for (let i = 0; i < colors.length; i++) {
        const dur = (durations[i] || 400) * (reducedMotion ? 1.4 : 1);
        const t0 = performance.now();
        ui.flash(colors[i]);
        await nextPaint();
        const grabAt = Math.max(reducedMotion ? 320 : 240, dur - 90);
        let m;
        do { m = await (opts && opts.sample ? opts.sample() : run.sample()); } while (performance.now() - t0 < grabAt);
        if (!last && m.face !== undefined) {
          const ok = m.face && !m.multiple && Math.abs(m.turn) <= CENTRE_MAX * 2
            && (!ref || (Math.abs(m.nose.x - ref.nose.x) < 0.25 && Math.abs(m.nose.y - ref.nose.y) < 0.25));
          if (!ok) { broken = true; break; }
          ref = ref || m;
        }
        blobs.push(opts && opts.grab ? opts.grab() : run.keep());
        ui.hold((i + 1) / colors.length);
        const rest = dur - (performance.now() - t0);
        if (rest > 0) await wait(rest);
      }
      ui.flash(null);
      if (!broken) { ui.hint(''); return blobs; }
      ui.hold(0);
      ui.hint('You moved — once more, keep still');
      await wait(700);
    }
    throw new Error('LIVENESS_UNAVAILABLE');
  }

  // ======================================================================
  // Marking and enrollment
  // ======================================================================

  function countsFor(data) {
    return data.frame_counts || data.challenge.map(() => data.frames_per_step || 2);
  }

  /**
   * The v2 challenge, live. `data` is the /mark/challenge response. Resolves
   * to the frames (Blobs) in step order, exactly frame_counts[i] per step.
   */
  async function runMark(opts) {
    const { video, data, isCancelled, onStep } = opts;
    if (!landmarker) throw new Error('LIVENESS_UNAVAILABLE');
    if (!(await waitForVideo(video))) throw new Error('LIVENESS_UNAVAILABLE');
    const run = startRun(video, isCancelled);
    const counts = countsFor(data);
    ui.show();
    ui.setSteps(data.challenge);
    const frames = [];
    try {
      for (const [i, step] of data.challenge.entries()) {
        if (onStep) onStep(i + 1, data.challenge.length);
        ui.markStep(i, 'now');
        ui.say((data.prompts && data.prompts[step]) || PROMPTS[step] || step);
        ui.cue(step);
        ui.hold(0);
        let got;
        if (step === 'blink') got = await blinkStep(run);
        else if (step === 'flash') got = await flashStep(run, data.flash);
        else got = await poseStep(run, step, counts[i]);
        if (got.length !== counts[i]) throw new Error('LIVENESS_UNAVAILABLE');
        frames.push(...got);
        ui.markStep(i, 'done');
        ui.cue(null);
        buzz(35);
        ui.hint('');
        await wait(120);
      }
      return await Promise.all(frames);
    } finally {
      ui.flash(null);
      ui.cue(null);
    }
  }

  const ENROLL_STEPS = [['centre', 2], ['left', 2], ['right', 2], ['up', 1], ['down', 1]];

  /** Guided enrollment: the angles the template needs, each held and auto-captured. */
  async function runEnroll(opts) {
    const { video, isCancelled, onStep } = opts;
    if (!landmarker) throw new Error('LIVENESS_UNAVAILABLE');
    if (!(await waitForVideo(video))) throw new Error('LIVENESS_UNAVAILABLE');
    const run = startRun(video, isCancelled);
    ui.show();
    ui.setSteps(ENROLL_STEPS.map((s) => s[0]));
    const frames = [];
    try {
      for (const [i, [step, n]] of ENROLL_STEPS.entries()) {
        if (onStep) onStep(i + 1, ENROLL_STEPS.length);
        ui.markStep(i, 'now');
        ui.say(PROMPTS[step]);
        ui.cue(step);
        ui.hold(0);
        frames.push(...await poseStep(run, step, n, { turnMax: ENROLL_TURN_MAX }));
        ui.markStep(i, 'done');
        ui.cue(null);
        buzz(35);
        ui.hint('');
        await wait(200);
      }
      return await Promise.all(frames);
    } finally {
      ui.cue(null);
    }
  }

  /**
   * v2 without a tracker: timed prompts, the same frames per step. The blink
   * becomes "close your eyes, count to two, open them" — one frame while they
   * are shut, one after — and the flash runs on the clock alone.
   */
  async function runTimed(opts) {
    const { data, grab, countdown, isCancelled, onStep } = opts;
    const counts = countsFor(data);
    ui.setSteps(data.challenge);
    const frames = [];
    const check = () => { if (isCancelled && isCancelled()) throw new Error('cancelled'); };
    try {
      for (const [i, step] of data.challenge.entries()) {
        if (onStep) onStep(i + 1, data.challenge.length);
        ui.markStep(i, 'now');
        ui.cue(step);
        if (step === 'blink') {
          ui.say('When the count ends, close your eyes, count to two, then open them');
          await countdown(3);
          ui.say('Eyes closed…');
          await wait(1000); check();
          frames.push(grab());
          ui.say('Open your eyes');
          await wait(2000); check();
          frames.push(grab());
        } else if (step === 'flash') {
          ui.say(PROMPTS.flash);
          await countdown(2);
          frames.push(...await flashStep(null, data.flash, { unvalidated: true, grab, sample: async () => { check(); await wait(40); return {}; } }));
        } else {
          ui.say((data.prompts && data.prompts[step]) || PROMPTS[step] || step);
          await countdown(2);
          for (let k = 0; k < counts[i]; k++) {
            if (k) await wait(350);
            check();
            frames.push(grab());
          }
        }
        ui.markStep(i, 'done');
        ui.cue(null);
      }
      return await Promise.all(frames);
    } finally {
      ui.flash(null);
      ui.cue(null);
    }
  }

  window.Liveness = {
    ensureLoaded, runMark, runEnroll, runTimed,
    /** Show the finishing tick / review / failure state in the frame. */
    finish: (kind) => ui.done(kind),
    reset: () => ui.reset(),
    lastError: () => lastError,
    delegate: () => delegateUsed,
    // Exposed for the browser checks in the test harness, not used by the page.
    _measure: measure,
  };
})();
