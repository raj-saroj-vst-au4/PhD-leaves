/**
 * FaceGuide — Apple-Face-ID-style live face capture for the attendance flow.
 *
 * The capture decision has always been the Python backend's job; this module
 * only makes the *client* interactive, so the student sees a ring fill as they
 * move — instead of a blind 1-2-3 countdown — and frames are grabbed at the
 * moment the pose is actually hit. What we upload is unchanged: the same JPEG
 * burst the backend re-validates (quality, pose sequence, liveness, identity,
 * cross-match). The client is never trusted; it only improves guidance.
 *
 * Alignment with the server (attendance/backend/app/faces.py):
 *   yaw is computed here with the SAME geometry the server's landmark fallback
 *   uses — horizontal nose offset from the eye midpoint, scaled by eye span,
 *   ×60 → degrees. So a turn the ring accepts (|yaw| ≳ 18°) comfortably clears
 *   the server's 15° threshold, and 'centre' (|yaw| ≤ 10°) clears its 12°.
 *
 * Graceful degradation: ensureLoaded() returns false (old browser, WebGL/WASM
 * unavailable, asset fetch blocked) and runMark/runEnroll throw
 * 'FACE_GUIDE_UNAVAILABLE' if no face is ever found — the caller then falls
 * back to the original blind guidedCapture(). Marking never depends on this.
 *
 * face-api.js (vendored, tfjs 4.x fork) is loaded lazily on first capture, so
 * the ~1.3 MB library never touches the initial page load.
 */
(function () {
  'use strict';

  // Degrees. Kept a little stricter than the server so a client-accepted pose
  // is very unlikely to be rejected server-side.
  const YAW_CENTRE_MAX = 10;   // server: 12
  const YAW_TURN_MIN   = 18;   // server: 15
  const MAX_DEG        = 30;   // ring edge maps to ~30° of head rotation
  const MIN_FACE_RATIO = 0.20; // a touch above the server's 0.18 "too small"

  let loadPromise = null;      // ensureLoaded() memoised
  let loaded = false;
  // Why the guide is not running, for the caller to show and for the console.
  // A silent fallback is indistinguishable from "the feature was never wired
  // up", which is exactly how the first attempt at this shipped looking broken.
  let lastError = null;
  function note(where, e) {
    lastError = where + (e && e.message ? ': ' + e.message : '');
    // eslint-disable-next-line no-console
    console.error('[FaceGuide] ' + lastError, e || '');
    return false;
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = () => reject(new Error('script load failed: ' + src));
      document.head.appendChild(s);
    });
  }

  /**
   * Load face-api.js + the two tiny models once. `assetBase` is BASE_URL
   * (e.g. "/phdtimeoff"); assets live under public/vendor/faceapi/.
   * Resolves true on success, false if anything is unavailable.
   */
  async function ensureLoaded(assetBase) {
    if (loaded) return true;
    if (loadPromise) return loadPromise;
    loadPromise = (async () => {
      try {
        if (typeof faceapi === 'undefined') {
          await loadScript(assetBase + '/public/vendor/faceapi/face-api.min.js');
        }
        if (typeof faceapi === 'undefined') {
          return note('library loaded but window.faceapi is undefined');
        }
        // Pick a tf backend explicitly. Without this the first inference can
        // throw "No backend found in registry" on browsers where WebGL is
        // blocked (some in-app browsers, hardware acceleration off).
        const tf = faceapi.tf;
        if (tf && tf.setBackend) {
          let ok = false;
          for (const b of ['webgl', 'cpu']) {
            try { ok = await tf.setBackend(b); if (ok) break; } catch (e) { /* try next */ }
          }
          if (tf.ready) await tf.ready();
          if (!ok && tf.getBackend && !tf.getBackend()) {
            return note('no usable tfjs backend (webgl and cpu both failed)');
          }
        }
        const modelBase = assetBase + '/public/vendor/faceapi/model';
        await faceapi.nets.tinyFaceDetector.loadFromUri(modelBase);
        await faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelBase);

        // Warm up: the FIRST inference compiles WebGL shaders and can take
        // several seconds. Paying that here — while the student is still
        // reading the screen — keeps it out of the live loop, where it used to
        // exhaust the no-face budget before a face was ever seen.
        try {
          const warm = document.createElement('canvas');
          warm.width = 160; warm.height = 160;
          const wctx = warm.getContext('2d');
          wctx.fillStyle = '#808080';
          wctx.fillRect(0, 0, 160, 160);
          await faceapi.detectSingleFace(warm, DET_OPTS()).withFaceLandmarks(true);
        } catch (e) { /* a warm-up miss is fine; it still compiled the graph */ }

        loaded = true;
        return true;
      } catch (e) {
        return note('load failed', e);
      }
    })();
    return loadPromise;
  }

  /** The video must actually be producing pixels before detection, or every
   *  read comes back empty and the guide looks dead. */
  async function waitForVideo(video, timeoutMs) {
    const deadline = Date.now() + (timeoutMs || 5000);
    try { if (video.paused) await video.play(); } catch (e) { /* autoplay attr covers it */ }
    while (Date.now() < deadline) {
      if (video.readyState >= 2 && video.videoWidth > 0) return true;
      await new Promise((r) => setTimeout(r, 100));
    }
    return false;
  }

  const DET_OPTS = () => new faceapi.TinyFaceDetectorOptions({ inputSize: 192, scoreThreshold: 0.4 });

  function centroid(pts) {
    let x = 0, y = 0;
    for (const p of pts) { x += p.x; y += p.y; }
    return { x: x / pts.length, y: y / pts.length };
  }
  function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

  /** One detection → head pose, or null if no usable single face. */
  async function detectPose(video) {
    const res = await faceapi
      .detectSingleFace(video, DET_OPTS())
      .withFaceLandmarks(true);          // true → the tiny landmark model
    if (!res) return null;

    const lm = res.landmarks;
    const eyeL = centroid(lm.getLeftEye());
    const eyeR = centroid(lm.getRightEye());
    // getNose() is landmarks 27..35: 27-30 is the bridge (30 = tip), 31-35 the
    // nostrils. Index 3 is the TIP; the last element is a nostril edge, which is
    // laterally offset and would bias yaw on its own.
    const nosePts = lm.getNose();
    const noseTip = nosePts[3] || nosePts[nosePts.length - 1];
    const eyeMid = { x: (eyeL.x + eyeR.x) / 2, y: (eyeL.y + eyeR.y) / 2 };
    const eyeSpan = Math.max(dist(eyeL, eyeR), 1e-3);

    const yaw = ((noseTip.x - eyeMid.x) / eyeSpan) * 60;   // same scale as the server
    const vy  = (noseTip.y - eyeMid.y) / eyeSpan;          // raw vertical ratio (calibrated → pitch)
    const faceRatio = res.detection.box.width / (video.videoWidth || res.detection.imageWidth || 1);

    return { yaw, vy, faceRatio };
  }

  // --- ring overlay ---------------------------------------------------------

  function makeRing(video) {
    const parent = video.parentElement;
    const canvas = document.createElement('canvas');
    canvas.className = 'att-ring';
    parent.appendChild(canvas);
    const rect = video.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round((rect.width || 280) * dpr);
    canvas.height = Math.round((rect.height || 373) * dpr);
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    return { canvas, ctx, w: rect.width || 280, h: rect.height || 373 };
  }

  function ringGeom(r) {
    return { cx: r.w / 2, cy: r.h / 2, R: Math.min(r.w, r.h) * 0.42 };
  }

  /** Map a (yaw, pitchDelta) pose to a screen point inside the ring.
   *
   * The preview is mirrored in CSS (#att-video { transform: scaleX(-1) }), so
   * the on-screen x must be flipped too — otherwise the dot moves away from the
   * side the student sees their head turn towards. Yaw itself is NOT flipped:
   * it is measured from the true sensor frame and must keep matching the
   * server's convention. */
  const MIRRORED = true;
  function poseToXY(g, yaw, pitch) {
    const nx = Math.max(-1, Math.min(1, yaw / MAX_DEG)) * (MIRRORED ? -1 : 1);
    const ny = Math.max(-1, Math.min(1, pitch / MAX_DEG));
    return { x: g.cx + nx * g.R * 0.82, y: g.cy + ny * g.R * 0.82 };
  }

  function clearRing(r) { r.ctx.clearRect(0, 0, r.w, r.h); }

  function drawSegments(r, g, count, litSet, activeIdx) {
    const { ctx } = r;
    const gap = 0.12;
    const seg = (Math.PI * 2) / count;
    for (let i = 0; i < count; i++) {
      const a0 = -Math.PI / 2 + i * seg + gap / 2;
      const a1 = a0 + seg - gap;
      ctx.beginPath();
      ctx.arc(g.cx, g.cy, g.R, a0, a1);
      ctx.lineWidth = 6;
      ctx.strokeStyle = litSet.has(i) ? '#22c55e' : (i === activeIdx ? '#facc15' : 'rgba(255,255,255,0.35)');
      ctx.stroke();
    }
  }

  /** Where the head should get to. For a turn, either side counts, so both are
   *  drawn — the student picks whichever is comfortable. */
  function drawTargets(r, g, isTurn) {
    const { ctx } = r;
    const spots = isTurn
      ? [poseToXY(g, -(YAW_TURN_MIN + 6), 0), poseToXY(g, YAW_TURN_MIN + 6, 0)]
      : [poseToXY(g, 0, 0)];
    ctx.lineWidth = 4;
    ctx.setLineDash([6, 5]);
    ctx.strokeStyle = '#facc15';
    for (const t of spots) {
      ctx.beginPath();
      ctx.arc(t.x, t.y, 26, 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.setLineDash([]);
  }

  function drawPointer(r, g, x, y, ok) {
    const { ctx } = r;
    ctx.beginPath();
    ctx.arc(x, y, 12, 0, Math.PI * 2);
    ctx.fillStyle = ok ? '#22c55e' : '#ffffff';
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(0,0,0,0.35)';
    ctx.stroke();
  }

  function wait(ms) { return new Promise((res) => setTimeout(res, ms)); }

  // --- marking: centre + the challenged turn(s), captured live -------------

  async function runMark(opts) {
    const { video, grab, challenge, prompts, stepEl, msgEl, isCancelled } = opts;
    if (!(await waitForVideo(video))) throw new Error('FACE_GUIDE_UNAVAILABLE');
    const r = makeRing(video);
    const g = ringGeom(r);
    const frames = [];
    let sawFaceEver = false;
    let noFaceReads = 0;
    // Give up on READ COUNT, not wall-clock: one inference can take seconds on a
    // slow phone, so a short time budget bailed out after barely one attempt.
    const MAX_NO_FACE_READS = 25;

    // Paint the ring before the first (slow) inference, so the guide is visible
    // the instant the camera opens rather than after a multi-second wait.
    drawTargets(r, ringGeom(r), false);
    if (msgEl) msgEl.textContent = 'Looking for your face…';

    try {
      for (const pose of challenge) {
        const isTurn = pose !== 'centre';
        if (stepEl) stepEl.textContent = prompts[pose] || pose;
        let held = 0;
        // Never loop forever: if the pose is not reached in time, hand back to
        // the blind fallback capture rather than trapping the student.
        const stepDeadline = Date.now() + 30000;

        for (;;) {
          if (isCancelled && isCancelled()) throw new Error('cancelled');
          if (Date.now() > stepDeadline) throw new Error('FACE_GUIDE_UNAVAILABLE');
          const p = await detectPose(video);
          clearRing(r);

          if (!p) {
            noFaceReads++;
            if (!sawFaceEver && noFaceReads >= MAX_NO_FACE_READS) {
              throw new Error('FACE_GUIDE_UNAVAILABLE');
            }
            drawTargets(r, g, isTurn);
            if (msgEl) msgEl.textContent = 'Center your face in the circle';
            await wait(90);
            continue;
          }
          sawFaceEver = true;

          const xy = poseToXY(g, p.yaw, 0);
          // Side-agnostic: a turn either way satisfies the server's 'turn' step.
          const matched = isTurn
            ? Math.abs(p.yaw) >= YAW_TURN_MIN
            : Math.abs(p.yaw) <= YAW_CENTRE_MAX;
          const tooSmall = p.faceRatio < MIN_FACE_RATIO;

          drawTargets(r, g, isTurn);
          drawPointer(r, g, xy.x, xy.y, matched && !tooSmall);

          if (msgEl) {
            msgEl.textContent = tooSmall ? 'Move a little closer'
              : matched ? 'Hold still…'
              : isTurn ? 'Turn until the dot reaches a circle'
              : 'Look straight ahead';
          }

          if (matched && !tooSmall) {
            held++;
            if (held >= 2) {                 // stable for two reads → capture
              frames.push(await grab());
              await wait(120);
              frames.push(await grab());     // a second, a beat later (server likes ≥1 clean)
              break;
            }
          } else {
            held = 0;
          }
          await wait(90);
        }
      }
      return frames;
    } finally {
      r.canvas.remove();
    }
  }

  // --- enrollment: sweep the head around to light every segment ------------

  async function runEnroll(opts) {
    const { video, grab, stepEl, msgEl, isCancelled } = opts;
    if (!(await waitForVideo(video))) throw new Error('FACE_GUIDE_UNAVAILABLE');
    const r = makeRing(video);
    const g = ringGeom(r);
    const SEG = 8;
    const lit = new Set();
    const frames = [];
    let neutralVy = null;
    let centreDone = false;
    let sawFaceEver = false;
    let noFaceReads = 0;
    const MAX_NO_FACE_READS = 25;
    const hardStop = Date.now() + 60000;   // enrollment may take its time

    if (stepEl) stepEl.textContent = 'Slowly move your head in a circle';
    // Show the segmented ring straight away, before the first inference.
    drawSegments(r, g, SEG, lit, -1);
    if (msgEl) msgEl.textContent = 'Looking for your face…';

    try {
      for (;;) {
        if (isCancelled && isCancelled()) throw new Error('cancelled');
        if (centreDone && lit.size >= 6) break;                 // enough coverage
        if (Date.now() > hardStop) break;                       // accept what we have (server re-checks count)

        const p = await detectPose(video);
        clearRing(r);

        if (!p) {
          noFaceReads++;
          if (!sawFaceEver && noFaceReads >= MAX_NO_FACE_READS) {
            throw new Error('FACE_GUIDE_UNAVAILABLE');
          }
          drawSegments(r, g, SEG, lit, -1);
          if (msgEl) msgEl.textContent = 'Center your face in the circle';
          await wait(90);
          continue;
        }
        sawFaceEver = true;
        if (neutralVy == null && Math.abs(p.yaw) <= YAW_CENTRE_MAX) neutralVy = p.vy;

        const pitch = neutralVy == null ? 0 : (p.vy - neutralVy) * 90; // rough degrees
        const xy = poseToXY(g, p.yaw, pitch);
        const rad = Math.hypot(p.yaw / MAX_DEG, pitch / MAX_DEG);
        const tooSmall = p.faceRatio < MIN_FACE_RATIO;

        // Which segment is the head pointing at? (only when meaningfully turned)
        let activeIdx = -1;
        if (rad >= 0.35 && rad <= 1.2) {
          const ang = Math.atan2(pitch, p.yaw);              // 0 = right, grows CW
          activeIdx = ((Math.round(ang / (Math.PI * 2 / SEG)) % SEG) + SEG) % SEG;
        }

        drawSegments(r, g, SEG, lit, tooSmall ? -1 : activeIdx);
        drawPointer(r, g, xy.x, xy.y, !tooSmall);

        if (!tooSmall) {
          if (!centreDone && rad < 0.2) {
            frames.push(await grab());                       // straight-on
            frames.push(await grab());
            centreDone = true;
          } else if (activeIdx >= 0 && !lit.has(activeIdx)) {
            frames.push(await grab());                       // one per fresh sector
            lit.add(activeIdx);
          }
        }

        if (msgEl) {
          msgEl.textContent = tooSmall ? 'Move a little closer'
            : !centreDone ? 'Look straight ahead to begin'
            : `Keep going — ${lit.size}/6 captured`;
        }
        await wait(70);
      }

      if (!centreDone) throw new Error('FACE_GUIDE_UNAVAILABLE');  // never got going
      return frames;
    } finally {
      r.canvas.remove();
    }
  }

  window.FaceGuide = {
    ensureLoaded, runMark, runEnroll, waitForVideo, detectPose,
    lastError: () => lastError,
  };
})();
