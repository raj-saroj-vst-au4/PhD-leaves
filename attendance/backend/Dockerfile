# Attendance backend: FastAPI + InsightFace (CPU), talks to host MariaDB/MySQL.
FROM python:3.12-slim

# opencv-headless needs libgl-less stack; insightface builds a small C extension.
#
# mediapipe (the liveness v2 blink, below) links libEGL.so.1 and libGLESv2.so.2
# even though it only ever runs on the CPU here. Those come from libglvnd: a
# few hundred KB of dispatch stubs. Debian's libegl1 also Depends on Mesa's EGL
# driver, which drags in Mesa and LLVM (+250 MB) that nothing here would use,
# so libegl1 is installed with that one dependency ignored. Leave this as the
# LAST apt step: a later `apt-get install` would see the ignored dependency
# and refuse to run until it is "fixed".
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential g++ libglib2.0-0 curl libglvnd0 libgles2 \
    && cd /tmp && apt-get download libegl1 \
    && dpkg -i --ignore-depends=libegl-mesa0 libegl1_*.deb && rm -f /tmp/libegl1_*.deb \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
# MediaPipe for the liveness v2 blink (faces.get_blink_model) — the same 0.10.35
# the phone runs (public/vendor/mediapipe). Installed WITHOUT its declared
# dependencies: they include opencv-contrib-python, a second copy of cv2 that
# would fight opencv-python-headless above, and sounddevice for audio tasks we
# never load. What it really needs is in requirements.txt (absl-py,
# flatbuffers, numpy; matplotlib arrives with insightface). A py3-none
# manylinux_2_28 x86_64 wheel, 12 MB.
RUN pip install --no-cache-dir --no-deps mediapipe==0.10.35

# Bake the face model into the image so first startup needs no internet.
# (~280 MB; downloads from the insightface release bucket at build time.)
RUN python -c "from insightface.app import FaceAnalysis; \
    FaceAnalysis(name='buffalo_l', root='/models', providers=['CPUExecutionProvider'])" \
    || echo "WARNING: model download failed at build; mount /models at runtime instead"

# Passive anti-spoof model for liveness v2 (a review flag only — faces.py
# "Passive anti-spoof"): MiniFASNetV2 from minivision-ai/Silent-Face-Anti-
# Spoofing (Apache-2.0), ONNX export by yakhyo/face-anti-spoofing (Apache-2.0),
# 1.7 MB. Pinned by SHA-256: a file that does not match is deleted, never
# used. Behind the IITB proxy pass the build args (DEPLOY-NOTES.md); without the
# file the backend runs with the signal off and says so in its log.
ARG ANTISPOOF_URL=https://github.com/yakhyo/face-anti-spoofing/releases/download/weights/MiniFASNetV2.onnx
ARG ANTISPOOF_SHA256=b32929adc2d9c34b9486f8c4c7bc97c1b69bc0ea9befefc380e4faae4e463907
# The Face Landmarker model for the blink, byte-identical to
# public/vendor/mediapipe/face_landmarker.task (same SHA-256). Without it the
# blink falls back to cruder landmark measures and says so in the log.
ARG BLINK_MODEL_URL=https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task
ARG BLINK_MODEL_SHA256=64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff
RUN mkdir -p /models/mediapipe \
    && ( curl -fsSL --retry 3 -o /models/mediapipe/face_landmarker.task "$BLINK_MODEL_URL" \
         && echo "$BLINK_MODEL_SHA256  /models/mediapipe/face_landmarker.task" | sha256sum -c - ) \
    || ( rm -f /models/mediapipe/face_landmarker.task; \
         echo "WARNING: blink model download or checksum failed; the v2 blink uses its fallback" )

RUN mkdir -p /models/antispoof \
    && ( curl -fsSL --retry 3 -o /models/antispoof/MiniFASNetV2.onnx "$ANTISPOOF_URL" \
         && echo "$ANTISPOOF_SHA256  /models/antispoof/MiniFASNetV2.onnx" | sha256sum -c - ) \
    || ( rm -f /models/antispoof/MiniFASNetV2.onnx; \
         echo "WARNING: anti-spoof model download or checksum failed; liveness v2 runs without it" )

COPY app ./app

# Give the service user a real HOME so InsightFace's matplotlib import can write
# its cache (no MPLCONFIGDIR juggling, no cold-start warning).
RUN useradd -r -u 1001 -m -d /home/attendance attendance \
    && mkdir -p /data/frames /data/reports \
    && chown -R attendance:attendance /data /models /home/attendance 2>/dev/null || true
USER attendance

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8000/api/healthz || exit 1

CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2"]
