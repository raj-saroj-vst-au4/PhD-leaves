MediaPipe Face Landmarker — vendored for liveness v2 (public/js/liveness.js)
==========================================================================

Served from here at runtime; no CDN, no network access to Google.

Library: @mediapipe/tasks-vision 0.10.35 (npm), Apache License 2.0 (LICENSE)
  tarball   https://registry.npmjs.org/@mediapipe/tasks-vision/-/tasks-vision-0.10.35.tgz
  integrity sha512-HOvadwVRE6JC+45nyYhmnywnr5h/J8KZvOeUNVOG9q/0875pZgItznFB9bRTvLc264YSJqiZ1NsIpCStJw/egg==
            (checked against the registry before copying)
  copied unmodified, except one rename:
    vision_bundle.js   <- package/vision_bundle.mjs   (ES module)
        Renamed so every web server sends a JavaScript MIME type: nginx's
        stock mime.types may not know .mjs, and browsers refuse to run a
        module served as application/octet-stream. liveness.js loads it with
        import(), which treats it as a module whatever the extension.
    wasm/vision_wasm_internal.{js,wasm}         SIMD build (all current phones)
    wasm/vision_wasm_nosimd_internal.{js,wasm}  for browsers without WASM SIMD
  Not copied: the .cjs build, source maps, type definitions, and the
  vision_wasm_module_internal.* variant (ES-module workers; unused).

Model: face_landmarker.task (float16, version 1), Apache License 2.0
  https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task
  md5 (GCS x-goog-hash) sOcnSQehZEQE/vZrKN1thQ==, last-modified 2023-05-03

SHA-256 of the files here:
  55d7ab624fbb70dcc5adc4ae6d7ea9cfcb569139d3dbfbf2b1deafcb966bc0fe  vision_bundle.js
  e7fd9858e8e8f221d9b96eddc11f8e077f263e0b7bbd79d3cbe882b134274f8c  wasm/vision_wasm_internal.js
  6a5c64584c2ab61c763b6e204afbdbc7ce1caf7f5216187322bca8df94f646bc  wasm/vision_wasm_internal.wasm
  438d1fe8ff7f4d946025bc211c291543c037d8a3785ed4eee60f1f521b236296  wasm/vision_wasm_nosimd_internal.js
  8a3092d34c79d3f57e6ba8592105e8a90f6b07c27891ffecd14cca428bfd3e31  wasm/vision_wasm_nosimd_internal.wasm
  64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff  face_landmarker.task

Why 0.10.35 and not the newer 1.x: from 1.0.0 (July 2026) the bundle sends
usage telemetry (task type, timings) to https://odml.pa.googleapis.com/v1/log
every minute, with an API key built into the WASM. Production's CSP
(default-src https:) would let that through, from a page that handles
biometric data. 0.10.35 (April 2026) is the last release without it. Before
upgrading, grep the new bundle for "odml.pa.googleapis.com".

Runs under production's CSP (default-src https: data: 'unsafe-inline'
'unsafe-eval'): verified in headless Chromium with that exact header, no
violations. It needs 'unsafe-eval' (or 'wasm-unsafe-eval') to compile WASM,
and creates no blob: URLs or workers. Serving .wasm as application/wasm saves a
second download (without it the loader logs "wasm streaming compile failed"
and falls back to fetching the file again as a plain buffer — still works).
